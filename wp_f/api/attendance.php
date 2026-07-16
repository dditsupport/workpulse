<?php
// =========================================================
// LOG ATTENDANCE
// POST /api/attendance.php
// Body (JSON):
//   employee_code  string
//   device_serial  string
//   device_type    MFS500 | FM220
//   location_id    int
//   punch_type     IN | OUT
//   punch_method   fingerprint | otp
//   match_score    int  (normalized 0–100)
//
// Server-side rules (authoritative — C# app logic is UI only):
//   1. device_serial must exist in devices table (is_active=1)
//      EXCEPTION: if punch_method=otp AND employee.otp_device_bypass=1,
//                 device check is skipped; location_id from app is
//                 validated against active locations.
//   2. location_id must match device's registered location (or, for
//      bypass, must be a real active location).
//   3. Employee must exist and be active.
//   4. Minimum punch interval enforced (MinPunchIntervalMinutes setting).
//   5. Punch sequence enforced — cannot IN twice or OUT before IN.
//   6. Shift day calculated entirely in MySQL. Cutoff hour is read from
//      system_settings (key ShiftCutoffHour, default 4).
//   7. Double-punch race protected by UNIQUE KEY (employee_code, punch_time).
//   8. All failures logged to failed_punch_logs.
// =========================================================

require_once __DIR__ . '/_bootstrap.php';
api_requireApiKey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_jsonFail('Method not allowed', 405);

$body = api_jsonBody();

$employeeCode = trim($body['employee_code'] ?? '');
$deviceSerial = trim($body['device_serial'] ?? '');
$deviceType   = mb_strtoupper(trim($body['device_type'] ?? ''));
$locationId   = (int)($body['location_id']  ?? 0);
$punchType    = mb_strtoupper(trim($body['punch_type'] ?? ''));
$punchMethod  = mb_strtolower(trim($body['punch_method'] ?? 'fingerprint'));
$matchScore   = (int)($body['match_score']  ?? 0);

if ($employeeCode === '') api_jsonFail('employee_code is required');
if (!in_array($deviceType,  ['MFS500','FM220'],   true)) api_jsonFail('Invalid device_type');
if ($locationId <= 0)                                     api_jsonFail('Invalid location_id');
if (!in_array($punchType,   ['IN','OUT'],          true)) api_jsonFail('Invalid punch_type');
if (!in_array($punchMethod, ['fingerprint','otp'], true)) api_jsonFail('Invalid punch_method');

if ($punchMethod === 'fingerprint' && $deviceSerial === '') {
    api_jsonFail('device_serial is required');
}

// ── Cutoff hour from settings ─────────────────────────────
// Default 6 — one-hour buffer before the 7AM shift start, so a
// 06:30 arrival still counts as today's shift day.
$cutoff = (int)api_getSetting('ShiftCutoffHour', '6');
if ($cutoff < 0 || $cutoff > 23) $cutoff = 6;

try {
    $db = api_getDb();

    // ── 3. Verify employee ───────────────────────────────
    $empSt = $db->prepare(
        'SELECT id, otp_device_bypass FROM employees
          WHERE employee_code = ? AND is_active = 1');
    $empSt->execute([$employeeCode]);
    $employee = $empSt->fetch();

    if (!$employee) {
        logFail($db, $employeeCode, $deviceSerial, $deviceType, $locationId,
                $punchType, $punchMethod, $matchScore, null, 'employee_not_found');
        api_jsonFail('Employee not found or inactive', 404);
    }

    $bypassDevice = ($punchMethod === 'otp') && (bool)$employee['otp_device_bypass'];

    // ── 1 & 2. Device + location ─────────────────────────
    if (!$bypassDevice) {
        if ($deviceSerial === '') api_jsonFail('device_serial is required');

        $devSt = $db->prepare(
            'SELECT device_id, device_type, location_id FROM devices
              WHERE device_serial = ? AND is_active = 1 LIMIT 1');
        $devSt->execute([$deviceSerial]);
        $device = $devSt->fetch();

        if (!$device) {
            logFail($db, $employeeCode, $deviceSerial, $deviceType, $locationId,
                    $punchType, $punchMethod, $matchScore, null, 'unregistered_device');
            api_jsonFail('Device not registered or inactive', 401);
        }

        $registeredLocationId = (int)$device['location_id'];

        if ($locationId !== $registeredLocationId) {
            logFail($db, $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
                    $punchType, $punchMethod, $matchScore, null, 'location_id_mismatch');
            api_jsonFail(
                "Location mismatch — device {$deviceSerial} is registered at location " .
                "{$registeredLocationId}, app sent {$locationId}. Update App.config.",
                409
            );
        }

    } else {
        // Bypass — but the app-supplied location must still be a real active one.
        $locStmt = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? AND is_active = 1');
        $locStmt->execute([$locationId]);
        if (!$locStmt->fetchColumn()) {
            logFail($db, $employeeCode, $deviceSerial, $deviceType, $locationId,
                    $punchType, $punchMethod, $matchScore, null, 'invalid_bypass_location');
            api_jsonFail('Invalid location for bypass employee', 400);
        }
        $registeredLocationId = $locationId;
    }

    // ── 4. Get last punch + shift day (all in MySQL clock) ──
    // Include device_serial / device_type / location_id so the auto-close
    // step below can synthesise a matching OUT for a stale IN — see §4b.
    $lastSql = "
        SELECT punch_type,
               punch_time,
               device_serial,
               device_type,
               location_id,
               DATE(punch_time - INTERVAL {$cutoff} HOUR) AS shift_day
        FROM   attendance_logs
        WHERE  employee_code = ?
        ORDER  BY punch_time DESC
        LIMIT  1";
    $lastSt = $db->prepare($lastSql);
    $lastSt->execute([$employeeCode]);
    $lastPunch = $lastSt->fetch();

    $nowRow = $db->query("
        SELECT DATE(NOW() - INTERVAL {$cutoff} HOUR) AS shift_day,
               NOW()                                  AS now_time")
        ->fetch();
    $nowShiftDay = $nowRow['shift_day'];

    // ── 4b. Auto-close stale IN ─────────────────────────
    // If the most recent punch is an IN from a PREVIOUS shift day, the
    // employee never punched OUT before going home. Synthesise an OUT
    // for that shift day so today's punch validates cleanly and the
    // attendance report shows a proper IN→OUT pair.
    //
    // Auto-OUT timestamp = last second of the IN's shift day, i.e. the
    // next calendar day at the cutoff hour minus one second. For
    // cutoff=6 and shift_day=2026-05-09 → 2026-05-10 05:59:59 (still
    // belongs to shift day 2026-05-09 by DATE(time - INTERVAL 6 HOUR)).
    //
    // Alternation is enforced within a shift day, so there can only
    // ever be one open IN at a time — a single insert closes it.
    if ($lastPunch
        && $lastPunch['punch_type'] === 'IN'
        && $lastPunch['shift_day'] !== $nowShiftDay)
    {
        try {
            $autoOutSt = $db->prepare("
                INSERT INTO attendance_logs
                    (employee_code, device_serial, device_type, location_id,
                     punch_type, punch_method, match_score, punch_time)
                VALUES (?, ?, ?, ?, 'OUT', 'auto_close', 0,
                        DATE_ADD(DATE_ADD(?, INTERVAL ? HOUR), INTERVAL -1 SECOND))
            ");
            $autoOutSt->execute([
                $employeeCode,
                $lastPunch['device_serial'],
                $lastPunch['device_type'],
                (int)$lastPunch['location_id'],
                $lastPunch['shift_day'],   // e.g. '2026-05-09'
                $cutoff + 24,               // next day at cutoff hour
            ]);
        } catch (PDOException $pe) {
            // 1062 = duplicate (employee_code, punch_time) — a concurrent
            // request already auto-closed. Safe to ignore; we just need
            // an OUT to exist there before the sequence check runs.
            if (($pe->errorInfo[1] ?? null) !== 1062) {
                throw $pe;
            }
        }

        // Re-read so the sequence check below sees the synthesised OUT
        // as the new "last punch". A duplicate-key skip still leaves
        // an OUT row in place — same end state.
        $lastSt = $db->prepare($lastSql);
        $lastSt->execute([$employeeCode]);
        $lastPunch = $lastSt->fetch();
    }

    // ── 5. Min interval ─────────────────────────────────
    $minInterval = (int)api_getSetting('MinPunchIntervalMinutes', '1');
    if ($minInterval <= 0) $minInterval = 1;

    if ($lastPunch) {
        $diffSt = $db->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS diff_seconds');
        $diffSt->execute([$lastPunch['punch_time']]);
        $diffSeconds = (int)$diffSt->fetch()['diff_seconds'];
        $diffMinutes = $diffSeconds / 60;

        if ($diffMinutes < $minInterval) {
            $waitSecs = max(1, ($minInterval * 60) - $diffSeconds);
            logFail($db, $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
                    $punchType, $punchMethod, $matchScore, null, 'too_soon');
            api_jsonFail(
                "Too soon — please wait {$minInterval} minute(s) between punches. " .
                "Try again in {$waitSecs} second(s).",
                429
            );
        }
    }

    // ── 6. Sequence check ───────────────────────────────
    if ($lastPunch) {
        $lastType      = $lastPunch['punch_type'];
        $lastShiftDay  = $lastPunch['shift_day'];
        $isNewShiftDay = ($lastShiftDay !== $nowShiftDay);

        if ($isNewShiftDay) {
            if ($punchType === 'OUT') {
                logFail($db, $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
                        $punchType, $punchMethod, $matchScore, null, 'sequence_error');
                api_jsonFail('New shift day — please punch IN first', 409);
            }
        } else {
            if ($punchType === $lastType) {
                $expected = $lastType === 'IN' ? 'OUT' : 'IN';
                logFail($db, $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
                        $punchType, $punchMethod, $matchScore, null, 'sequence_error');
                api_jsonFail(
                    "Sequence error — last punch was {$lastType}, expected {$expected}",
                    409
                );
            }
        }
    } else if ($punchType === 'OUT') {
        logFail($db, $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
                $punchType, $punchMethod, $matchScore, null, 'sequence_error');
        api_jsonFail('No punch history — please punch IN first', 409);
    }

    // ── 7. Insert (unique key on (employee_code, punch_time) deduplicates concurrent calls) ──
    try {
        $db->prepare('
            INSERT INTO attendance_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, match_score, punch_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ')->execute([
            $employeeCode, $deviceSerial, $deviceType, $registeredLocationId,
            $punchType, $punchMethod, $matchScore,
        ]);
    } catch (PDOException $pe) {
        // 1062 = duplicate entry — concurrent request beat us. Treat as success-ish.
        if ($pe->errorInfo[1] ?? null === 1062) {
            api_jsonFail('Duplicate punch (already recorded)', 409);
        }
        throw $pe;
    }

    api_jsonOk([
        'punch_time'  => $nowRow['now_time'],
        'punch_type'  => $punchType,
        'location_id' => $registeredLocationId,
        'shift_day'   => $nowShiftDay,
    ], 'Attendance logged');

} catch (Throwable $e) {
    api_dbFail($e);
}

// ── Log failed punch attempt ──────────────────────────────
function logFail(
    PDO    $db,
    string $employeeCode,
    string $deviceSerial,
    string $deviceType,
    int    $locationId,
    string $punchType,
    string $punchMethod,
    int    $matchScore,
    ?int   $thresholdUsed,
    string $failReason
): void {
    try {
        $db->prepare('
            INSERT INTO failed_punch_logs
                (employee_code, device_serial, device_type, location_id,
                 punch_type, punch_method, match_score, threshold_used,
                 fail_reason, attempted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ')->execute([
            $employeeCode ?: null,
            $deviceSerial,
            $deviceType,
            $locationId   ?: null,
            $punchType    ?: null,
            $punchMethod,
            $matchScore,
            $thresholdUsed,
            $failReason,
        ]);
    } catch (Throwable $ignored) {
        // best effort
    }
}
