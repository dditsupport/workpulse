<?php
// =========================================================
// Store Performance — monthly MIS upload, review and remarks
//
// Replaces the "Target vs Achievement" pivot workbook Operations kept
// outside the app. Three roles meet on one screen:
//
//   Operations Manager (txn_perf_admin)
//     · uploads one CSV per month for every outlet
//     · reads any outlet's history
//     · writes the closing conclusion for a month
//
//   Store Manager (employees.location_id — no txn flag at all)
//     · sees ONLY their own outlet
//     · writes one remark per parameter while the month is reviewed
//
//   Management / HO (txn_perf_view)
//     · read-only across every outlet
//
// The 18 parameters live in `perf_parameters`, keyed by the numeric
// prefix the workbook already uses (01Target … 18Negative Feedback).
// The prefix is the sort order AND the stable code the CSV matches on,
// so renaming a label never orphans history or a remark.
//
// Schema: migrations/2026-09-02_store_performance.sql
// History: migrations/2026-09-02_store_performance_history.sql
// =========================================================

define('PERF_CSV_MAX_BYTES', 8 * 1024 * 1024);   // 8 MB — 28 months x 48 outlets is ~1 MB
const PERF_DEFAULT_WINDOW = 12;                   // months shown side by side
const PERF_WINDOW_CHOICES = [6, 12, 24, 36];

// ── Schema probe ────────────────────────────────────────
// Every entry point checks this so an un-migrated database shows a
// "run the migration" notice instead of a 500. Probed once per request.
function perfSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM perf_values LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM perf_reviews LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM perf_remarks LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM perf_parameters LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function perfSchemaNotice(): string {
    return 'Store Performance is not set up on this database yet — run '
         . 'migrations/2026-09-02_store_performance.sql, then '
         . 'migrations/2026-09-02_store_performance_history.sql for the '
         . 'historical months.';
}

// ── Permissions ─────────────────────────────────────────
// Operations Manager: uploads, sees everything, concludes a month.
function perfCanAdmin(): bool {
    return isSuperadmin() || hasTxn('perf_admin');
}

// Anyone who may look at outlets other than their own.
function perfCanViewAll(): bool {
    return perfCanAdmin() || hasTxn('perf_view');
}

// The Store Manager side of the gate, and the whole of it: an employee
// reaches Store Performance only through employees.location_id (mirrored
// into the session as bio_location_id at login and on a location
// transfer). No location claimed and no txn flag = no access.
function perfMyLocation(): int {
    return myLocationId();
}

function perfCanUsePage(): bool {
    return perfCanViewAll() || perfMyLocation() > 0;
}

// Which outlet's data this user may open. View-all roles: any. Everyone
// else: exactly the outlet on their own employee record.
function perfCanViewLocation(int $locationId): bool {
    if ($locationId <= 0) return false;
    if (perfCanViewAll()) return true;
    return $locationId === perfMyLocation();
}

// Who may write the per-parameter remarks: the Store Manager who owns
// the outlet. Superadmin is included so support can correct an entry —
// every other role, Operations included, reads them.
function perfCanRemark(int $locationId): bool {
    if ($locationId <= 0) return false;
    if (isSuperadmin()) return true;
    return $locationId === perfMyLocation();
}

// Who may write the closing conclusion: Operations only.
function perfCanConclude(int $locationId): bool {
    return $locationId > 0 && perfCanAdmin();
}

// ── Parameter master ────────────────────────────────────
function perfParameters(): array {
    static $params = null;
    if ($params !== null) return $params;
    try {
        $params = getDb()->query(
            'SELECT param_code, param_name, value_type, better
             FROM perf_parameters WHERE is_active = 1
             ORDER BY sort_order, param_code'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $params = [];
    }
    return $params;
}

// The label as the workbook writes it — "01Target", "18Negative Feedback".
// Used for the CSV template and the export so a round trip re-imports.
function perfParamLabel(array $param): string {
    return $param['param_code'] . $param['param_name'];
}

// Header/cell matching key: lowercase, letters and digits only. Collapses
// every spelling difference that has actually turned up between the
// workbook and this table — "02Achivement" vs "Achievement",
// "18Negative FeedBack" vs "Negative Feedback", "Target %" vs "Target%".
//
// A percent sign becomes "pct" rather than being stripped, because four
// pairs of parameters differ by nothing else: Target / Target %,
// Valid phone / Valid phone %, Online Sales / Online Sales %,
// Invalid phone / Invalid phone %. Dropping it would silently file a
// percentage against the count beside it.
function perfNormalizeKey(string $s): string {
    $s = str_replace('%', ' pct ', $s);
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? '');
}

// Resolve a CSV cell or column header to a param_code. Accepts the
// prefixed label ("01Target"), the bare label ("Target"), or the code on
// its own ("01" / "1").
function perfParamCode(string $raw): ?string {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (perfParameters() as $p) {
            $code = (string)$p['param_code'];
            $map[perfNormalizeKey(perfParamLabel($p))] = $code;
            $map[perfNormalizeKey((string)$p['param_name'])] = $code;
            $map[perfNormalizeKey($code)] = $code;
            $map[perfNormalizeKey((string)(int)$code)] = $code;
        }
    }
    $key = perfNormalizeKey($raw);
    return $key === '' ? null : ($map[$key] ?? null);
}

// Parameters judged against another parameter in the same month, rather
// than only against the month before: [param_code => benchmark_code].
// Achievement is the one that matters today — it reads green once it
// reaches that month's Target and red while it is short. Add a pair here
// to give another parameter the same treatment.
function perfBenchmarks(): array {
    return ['02' => '01'];      // Achievement vs Target
}

// ── Month handling ──────────────────────────────────────
// Everything is stored on the 1st. Accepts what the workbook and Excel
// actually emit: 2026/07, 2026-07, 07/2026, 2026-07-01, Jul 2026.
function perfNormalizeMonth(string $raw): ?string {
    $s = trim($raw);
    if ($s === '') return null;
    if (preg_match('~^(\d{4})[/\-.](\d{1,2})(?:[/\-.]\d{1,2})?$~', $s, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2];
    } elseif (preg_match('~^(\d{1,2})[/\-.](\d{4})$~', $s, $m)) {
        $mo = (int)$m[1]; $y = (int)$m[2];
    } else {
        $t = strtotime($s . ' 01');
        if ($t === false) $t = strtotime($s);
        if ($t === false) return null;
        $y = (int)date('Y', $t); $mo = (int)date('n', $t);
    }
    if ($mo < 1 || $mo > 12 || $y < 2000 || $y > 2100) return null;
    return sprintf('%04d-%02d-01', $y, $mo);
}

function perfMonthLabel(string $ymd): string {
    $t = strtotime($ymd);
    return $t === false ? $ymd : date('M Y', $t);
}

function perfMonthInput(string $ymd): string {   // for <input type="month">
    return substr($ymd, 0, 7);
}

// ── Value parsing & display ─────────────────────────────
// A cell is a number, a note, or nothing. Strips the decoration the
// workbook carries (thousands separators, a rupee sign, a trailing %,
// parenthesised negatives) before deciding. Anything left that is not a
// number is kept verbatim as a note — "No Audit" against Audit Score is
// a real statement about the month, not a parse failure.
//
// Returns [float|null $number, string|null $text, bool $hadPercentSign].
// The third element matters for percentage parameters: "3%" states its
// own scale and is 3, while a bare "0.03" is a fraction the upload has
// to multiply. See perfScalePercent().
function perfParseValue(string $raw): array {
    $s = trim($raw);
    if ($s === '') return [null, null, false];

    $hadPct = str_contains($s, '%');
    $clean  = str_replace(["\xE2\x82\xB9", 'Rs.', 'Rs', ',', ' ', '%'], '', $s);
    $neg    = false;
    if (preg_match('/^\((.*)\)$/', $clean, $m)) { $neg = true; $clean = $m[1]; }
    if (is_numeric($clean)) {
        $n = (float)$clean;
        return [$neg ? -$n : $n, null, $hadPct];
    }
    // Excel error cells are a failed formula, not a measurement.
    if ($s[0] === '#') return [null, null, false];
    return [null, mb_substr($s, 0, 60), false];
}

// Percentages are stored the way the source workbook holds them and the
// way everyone reads them: 3% is 3, not 0.03. Spreadsheets export a
// percent-formatted cell either way, so the upload settles the scale:
//
//   · a cell that carries its own "%" is already a percentage — as-is;
//   · otherwise the file's declared scale decides. 'fraction' (the
//     default, and what Operations exports today) multiplies by 100, so
//     0.03 becomes 3; 'whole' takes the number as written, which is what
//     the historical Target vs Achievement sheet holds.
//
// Only parameters typed 'percent' are touched; a count or an amount is
// never rescaled.
function perfScalePercent(?float $num, string $valueType, bool $hadPercentSign, string $scale): ?float {
    if ($num === null || $valueType !== 'percent') return $num;
    if ($hadPercentSign || $scale !== 'fraction') return $num;
    // Rounded to the column's own scale: 1.4727 * 100 is 147.26999999999998
    // in binary float, and storing that would make the grid disagree with
    // the number the manager typed.
    return round($num * 100, 4);
}

// Indian digit grouping — 2,20,000 rather than 220,000, matching the
// workbook every one of these managers reads today.
function perfInr(float $n): string {
    $neg = $n < 0;
    $n   = abs($n);
    $int = (string)(int)round($n);
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $int;
}

// One stored cell → what the grid shows.
function perfDisplayValue(?array $cell, array $param): string {
    if ($cell === null) return '';
    if ($cell['value_num'] === null) {
        return (string)($cell['value_text'] ?? '');
    }
    $v = (float)$cell['value_num'];
    return match ($param['value_type']) {
        'amount'  => perfInr($v),
        'percent' => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '%',
        default   => perfInr($v),
    };
}

// Plain number for the CSV export — no grouping. A percentage keeps its
// "%" so re-importing the export cannot be read as a fraction and
// multiplied a second time.
function perfRawValue(?array $cell, ?array $param = null): string {
    if ($cell === null) return '';
    if ($cell['value_num'] === null) return (string)($cell['value_text'] ?? '');
    $n = rtrim(rtrim(number_format((float)$cell['value_num'], 4, '.', ''), '0'), '.');
    return ($param !== null && $param['value_type'] === 'percent') ? $n . '%' : $n;
}

// ── Data access ─────────────────────────────────────────

// Outlet name → location_id, normalised the same way parameter labels
// are, so "AHD - Haridarshan", "ahd-haridarshan" and a stray double
// space all land on the same outlet. Inactive outlets are included on
// purpose: a store that closed last year still has history to show.
function perfLocationsByName(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        $rows = getDb()->query('SELECT location_id, location_name FROM locations')
                       ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $map[perfNormalizeKey((string)$r['location_name'])] = (int)$r['location_id'];
        }
    } catch (Exception $e) {
        $map = [];
    }
    return $map;
}

function perfLocationName(int $locationId): string {
    try {
        $st = getDb()->prepare('SELECT location_name FROM locations WHERE location_id = ?');
        $st->execute([$locationId]);
        return (string)($st->fetchColumn() ?: ('#' . $locationId));
    } catch (Exception $e) { return '#' . $locationId; }
}

// Months that have data, newest first. Scoped to one outlet when asked —
// a Store Manager's month list should not leak that another outlet was
// uploaded and theirs was not.
function perfMonths(int $locationId = 0): array {
    try {
        if ($locationId > 0) {
            $st = getDb()->prepare(
                'SELECT DISTINCT period_month FROM perf_values
                 WHERE location_id = ? ORDER BY period_month DESC');
            $st->execute([$locationId]);
        } else {
            $st = getDb()->query(
                'SELECT DISTINCT period_month FROM perf_values ORDER BY period_month DESC');
        }
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return []; }
}

// The grid itself: [param_code][period_month] => ['value_num'=>…, 'value_text'=>…]
function perfValueGrid(int $locationId, array $months): array {
    if (!$months) return [];
    $ph = implode(',', array_fill(0, count($months), '?'));
    $st = getDb()->prepare(
        "SELECT param_code, period_month, value_num, value_text
         FROM perf_values
         WHERE location_id = ? AND period_month IN ($ph)");
    $st->execute(array_merge([$locationId], $months));
    $grid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grid[(string)$r['param_code']][(string)$r['period_month']] = [
            'value_num'  => $r['value_num'] === null ? null : (float)$r['value_num'],
            'value_text' => $r['value_text'],
        ];
    }
    return $grid;
}

// Review headers for the window: [period_month] => row
function perfReviewHeaders(int $locationId, array $months): array {
    if (!$months) return [];
    $ph = implode(',', array_fill(0, count($months), '?'));
    $st = getDb()->prepare(
        "SELECT r.*, sm.full_name AS remarked_name, om.full_name AS concluded_name
         FROM perf_reviews r
         LEFT JOIN employees sm ON sm.employee_code = r.remarked_by
         LEFT JOIN employees om ON om.employee_code = r.concluded_by
         WHERE r.location_id = ? AND r.period_month IN ($ph)");
    $st->execute(array_merge([$locationId], $months));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['period_month']] = $r;
    return $out;
}

// Per-parameter remarks across the whole window, so past months render
// their remarks inline next to the number they explain:
//   [period_month][param_code] => ['remark'=>…, 'updated_by'=>…, 'name'=>…]
function perfRemarkGrid(array $reviews): array {
    $ids = [];
    foreach ($reviews as $r) $ids[] = (int)$r['id'];
    if (!$ids) return [];
    $byId = [];
    foreach ($reviews as $month => $r) $byId[(int)$r['id']] = $month;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = getDb()->prepare(
        "SELECT m.review_id, m.param_code, m.remark, m.updated_by, m.updated_at,
                e.full_name
         FROM perf_remarks m
         LEFT JOIN employees e ON e.employee_code = m.updated_by
         WHERE m.review_id IN ($ph)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $month = $byId[(int)$r['review_id']] ?? null;
        if ($month === null) continue;
        $out[$month][(string)$r['param_code']] = $r;
    }
    return $out;
}

// Get-or-create the review header for one (outlet, month). Every write
// path goes through this, so a remark can never be orphaned and an
// upload always leaves the month reviewable.
function perfEnsureReview(int $locationId, string $month): int {
    $db = getDb();
    $st = $db->prepare('SELECT id FROM perf_reviews WHERE location_id = ? AND period_month = ?');
    $st->execute([$locationId, $month]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    $ins = $db->prepare(
        'INSERT INTO perf_reviews (location_id, period_month, status)
         VALUES (?, ?, \'pending\')
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $ins->execute([$locationId, $month]);
    return (int)$db->lastInsertId();
}

function perfStatusBadge(?string $status): string {
    return match ($status) {
        'concluded' => '<span class="badge badge-green">Concluded</span>',
        'remarked'  => '<span class="badge badge-blue">Remarked</span>',
        'pending'   => '<span class="badge badge-yellow">Pending</span>',
        default     => '<span class="badge badge-grey">No review</span>',
    };
}

// ── Upload ──────────────────────────────────────────────
// Two CSV shapes are accepted, because Operations already keeps this
// data in two shapes:
//
//   Long  — Month, Outlet, Parameter, Value   (one row per number)
//           This is exactly the workbook's "Data" sheet, so an export
//           of it imports untouched, and one file may carry many months.
//
//   Wide  — Outlet, 01Target, 02Achivement, … (one row per outlet)
//           No Month column, so the month comes from the form.
//
// The shape is decided by whether a Parameter column is present. Column
// order never matters; headers match on the same normalisation as the
// parameter labels, so "outlet", "Outlet Name" and "location" are one.
function perfCsvOutletAliases(): array {
    return ['outlet', 'outletname', 'location', 'locationname', 'store', 'storename', 'branch'];
}
function perfCsvMonthAliases(): array {
    return ['month', 'period', 'periodmonth', 'monthyear', 'yearmonth'];
}
function perfCsvParamAliases(): array {
    return ['parameter', 'param', 'parametername', 'kpi', 'metric'];
}
function perfCsvValueAliases(): array {
    return ['value', 'val', 'amount', 'number', 'sumofvalue'];
}

function doPerfUpload(): void {
    $back = 'index.php?page=perf_upload';
    if (!perfCanAdmin())   { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }
    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: ' . $back); exit; }

    if (empty($_FILES['csv']['name']) || ($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('error', 'No file uploaded, or the upload failed. Pick a .csv file and try again.');
        header('Location: ' . $back); exit;
    }
    $file = $_FILES['csv'];
    if ((int)$file['size'] > PERF_CSV_MAX_BYTES) {
        flash('error', 'File too large (max ' . (int)(PERF_CSV_MAX_BYTES / 1024 / 1024) . ' MB).');
        header('Location: ' . $back); exit;
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        flash('error', 'Only .csv files are accepted. Save the sheet as CSV first.');
        header('Location: ' . $back); exit;
    }

    // The month the form picked. Required for a wide file, and used as
    // the fallback for a long file whose Month column is blank on a row.
    $formMonth = perfNormalizeMonth((string)($_POST['period_month'] ?? ''));

    // How this file writes percentages. Defaults to 'fraction' — 0.03 for
    // 3% — which is what a spreadsheet's own percent formatting produces
    // and what Operations uploads. 'whole' is for a file that already
    // carries 3, such as an export of the old Target vs Achievement sheet.
    $pctScale = (string)($_POST['percent_scale'] ?? 'fraction');
    if (!in_array($pctScale, ['fraction', 'whole'], true)) $pctScale = 'fraction';

    $fh = @fopen($file['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read the uploaded file.'); header('Location: ' . $back); exit; }

    // Excel writes a BOM; without stripping it the first header never matches.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) {
        fclose($fh);
        flash('error', 'The file has no header row.');
        header('Location: ' . $back); exit;
    }

    // Map every header cell once: the four structural columns, plus any
    // column that names a parameter (the wide shape).
    $colOutlet = $colMonth = $colParam = $colValue = null;
    $paramCols = [];                       // column index => param_code
    foreach ($header as $i => $cell) {
        $key = perfNormalizeKey((string)$cell);
        if ($key === '') continue;
        if ($colOutlet === null && in_array($key, perfCsvOutletAliases(), true)) { $colOutlet = $i; continue; }
        if ($colMonth  === null && in_array($key, perfCsvMonthAliases(),  true)) { $colMonth  = $i; continue; }
        if ($colParam  === null && in_array($key, perfCsvParamAliases(),  true)) { $colParam  = $i; continue; }
        if ($colValue  === null && in_array($key, perfCsvValueAliases(),  true)) { $colValue  = $i; continue; }
        $code = perfParamCode((string)$cell);
        if ($code !== null) $paramCols[$i] = $code;
    }

    $isLong = $colParam !== null;
    if ($colOutlet === null) {
        fclose($fh);
        flash('error', 'The file needs an "Outlet" column. Download the template to see the expected layout.');
        header('Location: ' . $back); exit;
    }
    if ($isLong && $colValue === null) {
        fclose($fh);
        flash('error', 'A file with a "Parameter" column also needs a "Value" column.');
        header('Location: ' . $back); exit;
    }
    if (!$isLong && !$paramCols) {
        fclose($fh);
        flash('error', 'No parameter columns recognised. Use either Month/Outlet/Parameter/Value, '
                     . 'or one column per parameter named like "01Target". Download the template.');
        header('Location: ' . $back); exit;
    }
    if (!$isLong && $formMonth === null) {
        fclose($fh);
        flash('error', 'This file has no Month column, so pick the month on the form before uploading.');
        header('Location: ' . $back); exit;
    }

    // ── Parse everything before touching the database, so a file with a
    // typo in it is rejected whole rather than half-applied.
    $locMap  = perfLocationsByName();
    $types   = [];                       // param_code => value_type
    foreach (perfParameters() as $p) $types[(string)$p['param_code']] = (string)$p['value_type'];
    $parsed  = [];                       // [locId][month][code] => [num, text]
    $rescaled = 0;                       // percentages converted from a fraction
    $unknownOutlets = [];                // normalised name => original spelling
    $unknownParams  = [];
    $badMonths      = 0;
    $blankCells     = 0;
    $line           = 1;
    $tooMany        = false;

    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($r) === 1 && trim((string)$r[0]) === '') continue;   // blank line
        if ($line > 80000) { $tooMany = true; break; }

        $outletRaw = trim((string)($r[$colOutlet] ?? ''));
        if ($outletRaw === '') continue;
        $locId = $locMap[perfNormalizeKey($outletRaw)] ?? null;
        if ($locId === null) { $unknownOutlets[perfNormalizeKey($outletRaw)] = $outletRaw; continue; }

        $month = $formMonth;
        if ($colMonth !== null) {
            $raw = trim((string)($r[$colMonth] ?? ''));
            if ($raw !== '') {
                $month = perfNormalizeMonth($raw);
                if ($month === null) { $badMonths++; continue; }
            }
        }
        if ($month === null) { $badMonths++; continue; }

        if ($isLong) {
            $paramRaw = trim((string)($r[$colParam] ?? ''));
            $code     = $paramRaw === '' ? null : perfParamCode($paramRaw);
            if ($code === null) {
                if ($paramRaw !== '') $unknownParams[perfNormalizeKey($paramRaw)] = $paramRaw;
                continue;
            }
            [$num, $txt, $hadPct] = perfParseValue((string)($r[$colValue] ?? ''));
            if ($num === null && $txt === null) { $blankCells++; continue; }
            $scaled = perfScalePercent($num, $types[$code] ?? 'number', $hadPct, $pctScale);
            if ($scaled !== $num) $rescaled++;
            $parsed[$locId][$month][$code] = [$scaled, $txt];
        } else {
            foreach ($paramCols as $i => $code) {
                [$num, $txt, $hadPct] = perfParseValue((string)($r[$i] ?? ''));
                if ($num === null && $txt === null) { $blankCells++; continue; }
                $scaled = perfScalePercent($num, $types[$code] ?? 'number', $hadPct, $pctScale);
                if ($scaled !== $num) $rescaled++;
                $parsed[$locId][$month][$code] = [$scaled, $txt];
            }
        }
    }
    fclose($fh);

    if ($tooMany) {
        flash('error', 'That file has more than 80,000 rows. Split it by year and upload again.');
        header('Location: ' . $back); exit;
    }
    if (!$parsed) {
        $why = $unknownOutlets
            ? 'None of the outlet names matched a location. Unmatched: ' . implode(', ', array_slice($unknownOutlets, 0, 8))
            : 'No usable rows found.';
        flash('error', 'Nothing was imported. ' . $why);
        header('Location: ' . $back); exit;
    }

    // ── Write ───────────────────────────────────────────
    $db   = getDb();
    $me   = myCode();
    $cells = 0; $months = []; $outlets = 0;
    try {
        $db->beginTransaction();
        $up = $db->prepare(
            'INSERT INTO perf_values
               (location_id, period_month, param_code, value_num, value_text, uploaded_by, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               value_num   = VALUES(value_num),
               value_text  = VALUES(value_text),
               uploaded_by = VALUES(uploaded_by),
               uploaded_at = VALUES(uploaded_at)');

        foreach ($parsed as $locId => $byMonth) {
            $outlets++;
            foreach ($byMonth as $month => $byCode) {
                $months[$month] = true;
                foreach ($byCode as $code => [$num, $txt]) {
                    $up->execute([(int)$locId, $month, $code, $num, $txt, $me]);
                    $cells++;
                }
                // Leaving the month reviewable is part of the upload, not
                // a later step — the Store Manager should find it waiting.
                perfEnsureReview((int)$locId, (string)$month);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Import failed, nothing was saved: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    $msg = 'Imported ' . number_format($cells) . ' value' . ($cells === 1 ? '' : 's')
         . ' for ' . $outlets . ' outlet' . ($outlets === 1 ? '' : 's')
         . ' across ' . count($months) . ' month' . (count($months) === 1 ? '' : 's')
         . ' (' . implode(', ', array_map('perfMonthLabel', array_keys($months))) . ').';
    if ($rescaled)       $msg .= ' ' . number_format($rescaled) . ' percentage(s) read as fractions and multiplied by 100 (0.03 → 3%).';
    if ($blankCells)     $msg .= ' ' . number_format($blankCells) . ' blank cell(s) skipped.';
    if ($badMonths)      $msg .= ' ' . number_format($badMonths) . ' row(s) skipped for an unreadable month.';
    if ($unknownParams)  $msg .= ' Unknown parameter(s) ignored: ' . implode(', ', array_slice($unknownParams, 0, 6)) . '.';
    if ($unknownOutlets) $msg .= ' Outlet(s) with no matching location: ' . implode(', ', array_slice($unknownOutlets, 0, 8)) . '.';

    flash($unknownOutlets || $unknownParams || $badMonths ? 'error' : 'success', $msg);
    header('Location: ' . $back); exit;
}

// ── Review: the Store Manager's per-parameter remarks ───
function doPerfSaveRemarks(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $month = perfNormalizeMonth((string)($_POST['period_month'] ?? '')) ?? '';
    $back  = 'index.php?page=perf_review&loc=' . $locId . '&month=' . urlencode(perfMonthInput($month));

    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: index.php'); exit; }
    if ($month === '' || !perfCanViewLocation($locId) || !perfCanRemark($locId)) {
        flash('error', 'Access denied — you can only remark on your own outlet.');
        header('Location: index.php?page=perf_review'); exit;
    }

    $db = getDb();
    $reviewId = perfEnsureReview($locId, $month);

    // A concluded month is closed. Operations reopens it if the Store
    // Manager needs to change something after the fact.
    $st = $db->prepare('SELECT status FROM perf_reviews WHERE id = ?');
    $st->execute([$reviewId]);
    if ((string)$st->fetchColumn() === 'concluded' && !isSuperadmin()) {
        flash('error', 'This month is already concluded. Ask Operations to reopen it before editing remarks.');
        header('Location: ' . $back); exit;
    }

    $posted = $_POST['remark'] ?? [];
    if (!is_array($posted)) $posted = [];
    $me = myCode();

    try {
        $db->beginTransaction();
        $up = $db->prepare(
            'INSERT INTO perf_remarks (review_id, param_code, remark, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE remark = VALUES(remark), updated_by = VALUES(updated_by)');
        $del = $db->prepare('DELETE FROM perf_remarks WHERE review_id = ? AND param_code = ?');

        $filled = 0;
        foreach (perfParameters() as $p) {
            $code = (string)$p['param_code'];
            $text = trim((string)($posted[$code] ?? ''));
            // Clearing the box removes the remark rather than storing an
            // empty one, so "has a remark" stays a truthful test.
            if ($text === '') { $del->execute([$reviewId, $code]); continue; }
            $up->execute([$reviewId, $code, mb_substr($text, 0, 4000), $me]);
            $filled++;
        }

        // "Submit" marks the month reviewed; a plain save leaves it open
        // so a manager can come back to it later in the day.
        if (!empty($_POST['submit_review']) && $filled > 0) {
            $db->prepare(
                'UPDATE perf_reviews
                 SET status = \'remarked\', remarked_by = ?, remarked_at = NOW()
                 WHERE id = ? AND status <> \'concluded\''
            )->execute([$me, $reviewId]);
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Could not save remarks: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    flash('success', !empty($_POST['submit_review'])
        ? 'Remarks submitted for ' . perfMonthLabel($month) . '.'
        : 'Remarks saved for ' . perfMonthLabel($month) . '.');
    header('Location: ' . $back); exit;
}

// ── Review: the Operations Manager's conclusion ─────────
function doPerfSaveConclusion(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $month = perfNormalizeMonth((string)($_POST['period_month'] ?? '')) ?? '';
    $back  = 'index.php?page=perf_review&loc=' . $locId . '&month=' . urlencode(perfMonthInput($month));

    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: index.php'); exit; }
    if ($month === '' || !perfCanConclude($locId)) {
        flash('error', 'Access denied — only Operations can write the conclusion.');
        header('Location: index.php?page=perf_review'); exit;
    }

    $text     = trim((string)($_POST['conclusion'] ?? ''));
    $finalise = !empty($_POST['conclude']);
    if ($finalise && $text === '') {
        flash('error', 'Write the conclusion before closing the month.');
        header('Location: ' . $back); exit;
    }

    $db       = getDb();
    $reviewId = perfEnsureReview($locId, $month);
    $me       = myCode();
    try {
        if ($finalise) {
            $db->prepare(
                'UPDATE perf_reviews
                 SET conclusion = ?, concluded_by = ?, concluded_at = NOW(), status = \'concluded\'
                 WHERE id = ?'
            )->execute([mb_substr($text, 0, 8000), $me, $reviewId]);
        } else {
            // Saving without closing keeps the status where it is, so a
            // half-written conclusion does not lock the Store Manager out.
            $db->prepare('UPDATE perf_reviews SET conclusion = ? WHERE id = ?')
               ->execute([$text === '' ? null : mb_substr($text, 0, 8000), $reviewId]);
        }
    } catch (Exception $e) {
        flash('error', 'Could not save the conclusion: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    flash('success', $finalise
        ? perfMonthLabel($month) . ' concluded.'
        : 'Conclusion saved as a draft — the month is still open.');
    header('Location: ' . $back); exit;
}

// Reopen a concluded month so remarks can be corrected. The conclusion
// text is kept: reopening is an amendment, not a reset.
function doPerfReopenReview(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $month = perfNormalizeMonth((string)($_POST['period_month'] ?? '')) ?? '';
    $back  = 'index.php?page=perf_review&loc=' . $locId . '&month=' . urlencode(perfMonthInput($month));

    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: index.php'); exit; }
    if ($month === '' || !perfCanConclude($locId)) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=perf_review'); exit;
    }

    $db = getDb();
    try {
        $st = $db->prepare(
            'SELECT r.id, (SELECT COUNT(*) FROM perf_remarks m WHERE m.review_id = r.id) AS remarks
             FROM perf_reviews r WHERE r.location_id = ? AND r.period_month = ?');
        $st->execute([$locId, $month]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { flash('error', 'Nothing to reopen for that month.'); header('Location: ' . $back); exit; }

        $db->prepare(
            'UPDATE perf_reviews
             SET status = ?, concluded_by = NULL, concluded_at = NULL
             WHERE id = ?'
        )->execute([(int)$row['remarks'] > 0 ? 'remarked' : 'pending', (int)$row['id']]);
    } catch (Exception $e) {
        flash('error', 'Could not reopen: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    flash('success', perfMonthLabel($month) . ' reopened for editing.');
    header('Location: ' . $back); exit;
}

// ── Page: Upload (Operations) ───────────────────────────
function pagePerfUpload(): void {
    if (!perfCanAdmin()) {
        echo '<div class="page-header"><h2>Performance Upload</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to upload monthly performance data.</div>';
        return;
    }
    if (!perfSchemaReady()) {
        echo '<div class="page-header"><h2>Performance Upload</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    $db = getDb();
    // What has been uploaded so far, newest month first, with how far the
    // review of each month has got.
    $coverage = $db->query(
        'SELECT v.period_month,
                COUNT(DISTINCT v.location_id) AS outlets,
                COUNT(*)                      AS cells,
                MAX(v.uploaded_at)            AS last_upload
         FROM perf_values v
         GROUP BY v.period_month
         ORDER BY v.period_month DESC
         LIMIT 18')->fetchAll(PDO::FETCH_ASSOC);

    $statusRows = $db->query(
        'SELECT period_month, status, COUNT(*) AS n
         FROM perf_reviews GROUP BY period_month, status')->fetchAll(PDO::FETCH_ASSOC);
    $byMonth = [];
    foreach ($statusRows as $r) $byMonth[(string)$r['period_month']][(string)$r['status']] = (int)$r['n'];

    $defaultMonth = date('Y-m', strtotime('first day of last month'));
?>
<div class="page-header">
    <h2>📈 Performance Upload</h2>
    <a class="btn btn-ghost" href="index.php?page=perf_reviews">Go to Reviews</a>
</div>

<form method="POST" enctype="multipart/form-data" class="form-card" style="max-width:none;margin-bottom:18px">
    <input type="hidden" name="action" value="perf_upload">
    <div class="form-section-title">Upload a month</div>
    <div class="form-grid" style="grid-template-columns:repeat(2,1fr);max-width:840px">
        <div class="form-group">
            <label>Month</label>
            <input type="month" name="period_month" class="form-control" value="<?= h($defaultMonth) ?>">
            <small class="text-muted">Used only when the file has no Month column. A file that carries
                its own Month column may cover many months at once.</small>
        </div>
        <div class="form-group">
            <label>CSV file <span class="required">*</span></label>
            <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
            <small class="text-muted">Max <?= (int)(PERF_CSV_MAX_BYTES / 1024 / 1024) ?> MB. Templates,
                every active outlet already listed:
                <a href="index.php?page=perf_sample_csv&amp;layout=wide" style="color:var(--accent)">wide</a>
                (one row per outlet — quickest to fill) or
                <a href="index.php?page=perf_sample_csv" style="color:var(--accent)">long</a>
                (one row per number).</small>
        </div>
        <div class="form-group" style="grid-column:1 / -1">
            <label>Percentages in this file are written as</label>
            <select name="percent_scale" class="form-control" style="max-width:400px">
                <option value="fraction" selected>Fractions — 0.03 means 3%</option>
                <option value="whole">Whole numbers — 3 means 3%</option>
            </select>
            <small class="text-muted">Applies only to the eight % parameters. A cell that already
                carries a "%" (like <code>3%</code>) is taken as written whichever option is picked.
                The old Target vs Achievement sheet holds whole numbers.</small>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Import</button></div>
</form>

<div class="report-header-box" style="margin-bottom:18px">
    <strong>Two layouts are accepted.</strong><br>
    <b>Long</b> — <code>Month, Outlet, Parameter, Value</code>, one row per number. This is the
    "Data" sheet of the Target vs Achievement workbook, so an export of it imports as it stands.<br>
    <b>Wide</b> — <code>Outlet</code> plus one column per parameter (<code>01Target</code>,
    <code>02Achivement</code>, …), one row per outlet, month taken from the form above.<br>
    Column order never matters. Outlet names are matched to
    <a href="index.php?page=locations" style="color:var(--accent)">Locations</a> ignoring case, spacing and punctuation; any that
    don't match are listed back to you and nothing else in the file is held up. Re-uploading a month
    overwrites that month's numbers and leaves remarks and conclusions untouched.<br>
    Percentages are stored the way you read them — 3% is 3 — so a file written as fractions is
    multiplied by 100 on the way in, and the import result tells you how many cells that touched.
</div>

<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th>Month</th><th>Outlets</th><th>Values</th><th>Last upload</th>
            <th>Pending</th><th>Remarked</th><th>Concluded</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$coverage): ?>
            <tr><td colspan="8" class="empty-row">Nothing uploaded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($coverage as $c):
            $m = (string)$c['period_month'];
            $s = $byMonth[$m] ?? []; ?>
            <tr>
                <td data-label="Month"><strong><?= h(perfMonthLabel($m)) ?></strong></td>
                <td data-label="Outlets"><?= (int)$c['outlets'] ?></td>
                <td data-label="Values"><?= number_format((int)$c['cells']) ?></td>
                <td data-label="Last upload" class="text-muted"><?= h((string)$c['last_upload']) ?></td>
                <td data-label="Pending"><?= (int)($s['pending'] ?? 0) ?></td>
                <td data-label="Remarked"><?= (int)($s['remarked'] ?? 0) ?></td>
                <td data-label="Concluded"><?= (int)($s['concluded'] ?? 0) ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost"
                       href="index.php?page=perf_reviews&month=<?= h(perfMonthInput($m)) ?>">Reviews</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}

// CSV template — the long layout, pre-filled with the 18 parameter
// labels against the first outlet so the expected spelling is obvious.
function perfSampleCsv(): void {
    if (!perfCanAdmin()) { echo 'Access denied.'; exit; }

    // Every active outlet, so the file is the month's whole grid ready to
    // fill in rather than an example of one store. Inactive outlets are
    // left out: you would not be reporting a month for a closed store.
    $locs  = getActiveLocations();
    if (!$locs) $locs = [['location_id' => 0, 'location_name' => 'AHD - Example']];
    $params = perfParameters();
    $month  = date('Y/m', strtotime('first day of last month'));
    $wide   = ($_GET['layout'] ?? '') === 'wide';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="performance_template_'
         . ($wide ? 'wide' : 'long') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($wide) {
        // One row per outlet, one column per parameter — the quickest
        // shape to type a month into. No note row: every row here is read
        // as an outlet, and a note would come back as an unmatched name.
        $head = ['Outlet'];
        foreach ($params as $p) $head[] = perfParamLabel($p);
        fputcsv($out, $head, escape: '');
        foreach ($locs as $l) {
            fputcsv($out, array_merge([(string)$l['location_name']],
                                      array_fill(0, count($params), '')), escape: '');
        }
        fclose($out);
        exit;
    }

    // Long layout, outlet by outlet so a store is filled in one block.
    // The trailing note column is documentation, not data: the importer
    // only reads columns it recognises, so it can be left in place or
    // deleted. Values are left blank so nothing here uploads by accident.
    fputcsv($out, ['Month', 'Outlet', 'Parameter', 'Value', 'How to write it'], escape: '');
    foreach ($locs as $l) {
        foreach ($params as $p) {
            $hint = match ($p['value_type']) {
                'percent' => 'fraction — 0.03 for 3% (or write 3%)',
                'amount'  => 'rupees — 445000',
                default   => 'count — 1036',
            };
            fputcsv($out, [$month, (string)$l['location_name'], perfParamLabel($p), '', $hint], escape: '');
        }
    }
    fclose($out);
    exit;
}

// ── Page: Reviews list (Operations / HO) ────────────────
function pagePerfReviews(): void {
    if (!perfCanViewAll()) {
        // A Store Manager has exactly one outlet, so the list would be a
        // list of one — show that outlet's review instead. Rendering it
        // rather than redirecting because renderShell() has already sent
        // the page shell by the time a page function runs.
        if (perfMyLocation() > 0) { pagePerfReview(); return; }
        echo '<div class="page-header"><h2>Performance Reviews</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to store performance reviews.</div>';
        return;
    }
    if (!perfSchemaReady()) {
        echo '<div class="page-header"><h2>Performance Reviews</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    $db     = getDb();
    $months = perfMonths();
    if (!$months) {
        echo '<div class="page-header"><h2>Performance Reviews</h2></div>';
        echo '<div class="rpt-prompt">No performance data has been uploaded yet.'
           . (perfCanAdmin() ? ' <a href="index.php?page=perf_upload" style="color:var(--accent)">Upload a month</a>.' : '')
           . '</div>';
        return;
    }
    $month = perfNormalizeMonth((string)($_GET['month'] ?? ''));
    if ($month === null || !in_array($month, $months, true)) $month = $months[0];

    $st = $db->prepare(
        'SELECT l.location_id, l.location_name, COUNT(*) AS cells
         FROM perf_values v
         JOIN locations l ON l.location_id = v.location_id
         WHERE v.period_month = ?
         GROUP BY l.location_id, l.location_name
         ORDER BY l.location_name');
    $st->execute([$month]);
    $outlets = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare(
        'SELECT r.location_id, r.status, r.remarked_at, r.concluded_at, r.conclusion,
                sm.full_name AS remarked_name, om.full_name AS concluded_name,
                (SELECT COUNT(*) FROM perf_remarks m WHERE m.review_id = r.id) AS remarks
         FROM perf_reviews r
         LEFT JOIN employees sm ON sm.employee_code = r.remarked_by
         LEFT JOIN employees om ON om.employee_code = r.concluded_by
         WHERE r.period_month = ?');
    $st->execute([$month]);
    $reviews = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $reviews[(int)$r['location_id']] = $r;

    $paramCount = count(perfParameters());
?>
<div class="page-header">
    <h2>📈 Performance Reviews · <?= h(perfMonthLabel($month)) ?></h2>
    <?php if (perfCanAdmin()): ?>
        <!-- The only route to the upload page: it is deliberately not a
             sidebar entry, so this button has to read as an action. -->
        <a class="btn btn-primary" href="index.php?page=perf_upload">Upload month's data</a>
    <?php endif; ?>
</div>

<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="perf_reviews">
    <label class="text-muted">Month</label>
    <select name="month" class="form-control" style="width:160px" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
            <option value="<?= h(perfMonthInput($m)) ?>" <?= $m === $month ? 'selected' : '' ?>>
                <?= h(perfMonthLabel($m)) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-secondary btn-sm" type="submit">Go</button></noscript>
</form>

<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th>Outlet</th><th>Status</th><th>Remarks</th>
            <th>Store Manager</th><th>Conclusion</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$outlets): ?>
            <tr><td colspan="6" class="empty-row">No outlets have data for this month.</td></tr>
        <?php endif; ?>
        <?php foreach ($outlets as $o):
            $r = $reviews[(int)$o['location_id']] ?? null; ?>
            <tr>
                <td data-label="Outlet"><strong><?= h($o['location_name']) ?></strong></td>
                <td data-label="Status"><?= perfStatusBadge($r['status'] ?? null) ?></td>
                <td data-label="Remarks">
                    <?= (int)($r['remarks'] ?? 0) ?> / <?= $paramCount ?>
                </td>
                <td data-label="Store Manager" class="text-muted">
                    <?= $r && $r['remarked_at'] ? h((string)$r['remarked_name']) . ' · ' . h(substr((string)$r['remarked_at'], 0, 10)) : '—' ?>
                </td>
                <td data-label="Conclusion" class="text-muted">
                    <?= $r && trim((string)($r['conclusion'] ?? '')) !== ''
                        ? h(mb_strimwidth(trim((string)$r['conclusion']), 0, 70, '…')) : '—' ?>
                </td>
                <td class="actions">
                    <a class="btn btn-sm btn-primary"
                       href="index.php?page=perf_review&loc=<?= (int)$o['location_id'] ?>&month=<?= h(perfMonthInput($month)) ?>">Open</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="table-count"><?= count($outlets) ?> outlet<?= count($outlets) === 1 ? '' : 's' ?></div>
<?php
}

// ── Review context ──────────────────────────────────────
// Shared by the review screen and its CSV export so both answer
// "which outlet, which month, which window" identically.
// Returns null when the user may not see the outlet they asked for.
function perfReviewContext(): ?array {
    $mine  = perfMyLocation();
    $locId = (int)($_GET['loc'] ?? 0);

    // A Store Manager never picks an outlet — theirs is the only one, and
    // an id in the query string must not talk them past that.
    if (!perfCanViewAll()) $locId = $mine;
    if ($locId <= 0 && perfCanViewAll()) {
        // Operations landing with no outlet chosen: first one with data.
        try {
            $locId = (int)(getDb()->query(
                'SELECT v.location_id FROM perf_values v
                 JOIN locations l ON l.location_id = v.location_id
                 GROUP BY v.location_id, l.location_name
                 ORDER BY l.location_name LIMIT 1')->fetchColumn() ?: 0);
        } catch (Exception $e) { $locId = 0; }
    }
    if ($locId <= 0 || !perfCanViewLocation($locId)) return null;

    $allMonths = perfMonths($locId);                       // newest first
    $month     = perfNormalizeMonth((string)($_GET['month'] ?? ''));
    if ($month === null || !in_array($month, $allMonths, true)) {
        $month = $allMonths[0] ?? date('Y-m-01');
    }

    $window = (int)($_GET['n'] ?? PERF_DEFAULT_WINDOW);
    if (!in_array($window, PERF_WINDOW_CHOICES, true)) $window = PERF_DEFAULT_WINDOW;

    // The window is the review month and the months before it, oldest
    // first — reading left to right is reading forward in time, the way
    // the workbook's pivot already reads.
    $upTo = array_values(array_filter($allMonths, fn($m) => $m <= $month));
    sort($upTo);
    $months = array_slice($upTo, -$window);
    if (!in_array($month, $months, true)) $months[] = $month;

    return [
        'location_id' => $locId,
        'month'       => $month,
        'months'      => $months,
        'all_months'  => $allMonths,
        'window'      => $window,
    ];
}

// ── Page: the review grid ───────────────────────────────
function pagePerfReview(): void {
    if (!perfCanUsePage()) {
        echo '<div class="page-header"><h2>Store Performance</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to store performance. '
           . 'Store Managers reach it once their outlet is set on their employee record.</div>';
        return;
    }
    if (!perfSchemaReady()) {
        echo '<div class="page-header"><h2>Store Performance</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    $ctx = perfReviewContext();
    if ($ctx === null) {
        // Two different reasons land here: an outlet the viewer may not
        // open, and a database with nothing uploaded yet (so there was no
        // outlet to default to). Saying which one saves a support call.
        echo '<div class="page-header"><h2>Store Performance</h2></div>';
        if (perfMonths()) {
            echo '<div class="rpt-prompt">That outlet is not yours to view.</div>';
        } else {
            echo '<div class="rpt-prompt">No performance data has been uploaded yet.'
               . (perfCanAdmin() ? ' <a href="index.php?page=perf_upload" style="color:var(--accent)">Upload a month</a>.' : '')
               . '</div>';
        }
        return;
    }

    $locId     = $ctx['location_id'];
    $month     = $ctx['month'];
    $months    = $ctx['months'];
    $allMonths = $ctx['all_months'];
    $window    = $ctx['window'];
    $locName   = perfLocationName($locId);
    $params    = perfParameters();

    $showRemarks = !isset($_GET['remarks']) || $_GET['remarks'] !== '0';
    $paramByCode = [];
    foreach ($params as $p) $paramByCode[(string)$p['param_code']] = $p;
    $benchmarks  = perfBenchmarks();

    $grid     = perfValueGrid($locId, $months);
    $reviews  = perfReviewHeaders($locId, $months);
    $remarks  = perfRemarkGrid($reviews);
    $review   = $reviews[$month] ?? null;
    $status   = (string)($review['status'] ?? 'pending');

    $isConcluded  = $status === 'concluded';
    $canRemark    = perfCanRemark($locId) && (!$isConcluded || isSuperadmin());
    $canConclude  = perfCanConclude($locId);
    $myRemarks    = $remarks[$month] ?? [];

    // Outlet picker — only outlets that actually carry data, so the list
    // is short and every entry leads somewhere.
    $pickable = [];
    if (perfCanViewAll()) {
        try {
            $pickable = getDb()->query(
                'SELECT l.location_id, l.location_name
                 FROM perf_values v JOIN locations l ON l.location_id = v.location_id
                 GROUP BY l.location_id, l.location_name
                 ORDER BY l.location_name')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $pickable = []; }
    }

    $qs = fn(array $over = []) => 'index.php?' . http_build_query(array_merge([
        'page' => 'perf_review', 'loc' => $locId,
        'month' => perfMonthInput($month), 'n' => $window,
        'remarks' => $showRemarks ? '1' : '0',
    ], $over));
?>
<style>
/* The grid is wide by design — one column per month, up to 36 of them, so
   the wrapper scrolls sideways and the parameter column pins to the left
   to keep each row identifiable while it does.
   table-layout:fixed with an explicit width per column is what stops one
   long remark from stealing the table's spare width and squashing every
   other month; --perf-col widens the months when remarks are on show. */
.perf-grid{font-size:12.5px;border-collapse:separate;border-spacing:0;
    table-layout:fixed;width:auto;min-width:100%}
.perf-grid th,.perf-grid td{vertical-align:top}
.perf-grid .perf-param{position:sticky;left:0;z-index:2;background:var(--surface);
    text-align:left;white-space:normal;width:200px;
    border-right:1px solid var(--border);font-weight:600;font-size:12px;text-transform:none;color:var(--text)}
.perf-grid thead .perf-param{z-index:3}
.perf-grid tbody tr:hover .perf-param{background:var(--surface)}
.perf-grid .perf-code{color:var(--muted);font-weight:400;font-size:11px;margin-right:4px}
.perf-grid th.perf-month{text-align:right;width:var(--perf-col)}
.perf-grid td.perf-cell{text-align:right;font-family:Consolas,monospace;padding:8px 12px}
.perf-grid .perf-num{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.perf-col-review,.perf-grid th.perf-col-review{background:rgba(26,143,227,.08)}
.perf-grid th.perf-col-review{width:250px}
.perf-delta{font-size:10px;margin-left:5px;font-family:inherit}
.perf-up{color:var(--green)}.perf-down{color:var(--red)}
/* Met / missed the month's own benchmark — Achievement against Target.
   Independent of the delta arrow beside it, which is month-on-month: a
   figure can be down on last month and still ahead of target. */
.perf-hit{color:var(--green);font-weight:700}
.perf-miss{color:var(--red);font-weight:700}
.perf-note{color:var(--yellow);font-family:inherit;font-style:italic;font-size:11.5px}
.perf-cell-remark{margin-top:5px;padding-top:5px;border-top:1px dashed rgba(255,255,255,.12);
    font-family:inherit;font-size:11px;font-style:italic;color:var(--muted);
    text-align:left;white-space:normal;line-height:1.45}
.perf-grid textarea.form-control{display:block;width:100%;margin-top:6px;
    font-size:11.5px;padding:5px 7px;min-height:56px;
    white-space:normal;font-family:inherit;resize:vertical}
.perf-meta{display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-bottom:14px;
    background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:11px 16px;font-size:12.5px}
.perf-meta b{font-weight:600}
.perf-concl{background:var(--surface);border:1px solid var(--border);border-radius:8px;
    padding:18px;margin-top:18px}
.perf-concl-past{border-left:3px solid var(--border);padding:8px 12px;margin-top:10px;
    font-size:12.5px;line-height:1.6}
.perf-concl-past .m{font-weight:600;color:var(--accent)}
</style>

<div class="page-header">
    <h2>📈 <?= h($locName) ?> · <?= h(perfMonthLabel($month)) ?></h2>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="<?= h($qs(['page' => 'export_perf_review'])) ?>">Export CSV</a>
        <?php if (perfCanViewAll()): ?>
            <a class="btn btn-ghost btn-sm" href="index.php?page=perf_reviews&month=<?= h(perfMonthInput($month)) ?>">All outlets</a>
        <?php endif; ?>
    </div>
</div>

<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="perf_review">
    <?php if (perfCanViewAll() && $pickable): ?>
        <label class="text-muted">Outlet</label>
        <select name="loc" class="form-control" style="width:230px" onchange="this.form.submit()">
            <?php foreach ($pickable as $p): ?>
                <option value="<?= (int)$p['location_id'] ?>" <?= (int)$p['location_id'] === $locId ? 'selected' : '' ?>>
                    <?= h($p['location_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="hidden" name="loc" value="<?= $locId ?>">
    <?php endif; ?>

    <label class="text-muted">Review month</label>
    <select name="month" class="form-control" style="width:140px" onchange="this.form.submit()">
        <?php foreach ($allMonths as $m): ?>
            <option value="<?= h(perfMonthInput($m)) ?>" <?= $m === $month ? 'selected' : '' ?>>
                <?= h(perfMonthLabel($m)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label class="text-muted">History</label>
    <select name="n" class="form-control" style="width:110px" onchange="this.form.submit()">
        <?php foreach (PERF_WINDOW_CHOICES as $w): ?>
            <option value="<?= $w ?>" <?= $w === $window ? 'selected' : '' ?>><?= $w ?> months</option>
        <?php endforeach; ?>
    </select>

    <!-- Hidden 0 first so an unchecked box still submits a value; PHP keeps
         the last occurrence, so ticked wins and unticked means hide. -->
    <input type="hidden" name="remarks" value="0">
    <label class="rpt-filter-chk">
        <input type="checkbox" name="remarks" value="1" <?= $showRemarks ? 'checked' : '' ?>
               onchange="this.form.submit()"> Show remarks
    </label>
    <noscript><button class="btn btn-secondary btn-sm" type="submit">Apply</button></noscript>
</form>

<div class="perf-meta">
    <span><b>Status</b> <?= perfStatusBadge($review['status'] ?? null) ?></span>
    <span class="text-muted"><b>Store Manager remarks</b>
        <?= $review && $review['remarked_at']
            ? h((string)$review['remarked_name']) . ' · ' . h((string)$review['remarked_at'])
            : 'not submitted' ?></span>
    <span class="text-muted"><b>Conclusion</b>
        <?= $review && $review['concluded_at']
            ? h((string)$review['concluded_name']) . ' · ' . h((string)$review['concluded_at'])
            : 'open' ?></span>
</div>

<?php if (!$grid): ?>
    <div class="alert alert-error">No data has been uploaded for this outlet yet, so there is nothing to
        review. <?= perfCanAdmin() ? '<a href="index.php?page=perf_upload" style="color:var(--accent)">Upload a month</a>.' : '' ?></div>
<?php endif; ?>

<?php if ($isConcluded && perfCanRemark($locId) && !isSuperadmin()): ?>
    <div class="alert alert-success">This month is concluded. Remarks are locked — ask Operations to reopen it if something needs changing.</div>
<?php endif; ?>

<form method="POST" id="perfRemarkForm">
    <input type="hidden" name="action" value="perf_save_remarks">
    <input type="hidden" name="location_id" value="<?= $locId ?>">
    <input type="hidden" name="period_month" value="<?= h($month) ?>">

    <div class="table-wrap">
    <table class="table perf-grid" style="--perf-col:<?= $showRemarks ? '180px' : '112px' ?>">
        <thead>
            <tr>
                <th class="perf-param">Parameter</th>
                <?php foreach ($months as $m):
                    $isReview = $m === $month; ?>
                    <th class="perf-month <?= $isReview ? 'perf-col-review' : '' ?>">
                        <?= h(perfMonthLabel($m)) ?>
                        <?php if ($isReview): ?><br><span style="font-size:10px;color:var(--accent)">under review</span><?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$params): ?>
            <tr><td colspan="<?= count($months) + 1 ?>" class="empty-row">No parameters configured.</td></tr>
        <?php endif; ?>
        <?php foreach ($params as $p):
            $code = (string)$p['param_code']; ?>
            <tr>
                <th class="perf-param">
                    <span class="perf-code"><?= h($code) ?></span><?= h($p['param_name']) ?>
                </th>
                <?php foreach ($months as $i => $m):
                    $isReview = $m === $month;
                    $cell     = $grid[$code][$m] ?? null;
                    $prevCell = $i > 0 ? ($grid[$code][$months[$i - 1]] ?? null) : null;

                    // Month-on-month movement, coloured by whether the
                    // movement is the good direction for this parameter.
                    $delta = '';
                    if ($cell && $prevCell && $cell['value_num'] !== null && $prevCell['value_num'] !== null
                        && $p['better'] !== 'none') {
                        $d = (float)$cell['value_num'] - (float)$prevCell['value_num'];
                        if (abs($d) > 0.0001) {
                            $good  = $p['better'] === 'up' ? $d > 0 : $d < 0;
                            $delta = '<span class="perf-delta ' . ($good ? 'perf-up' : 'perf-down') . '">'
                                   . ($d > 0 ? '&#9650;' : '&#9660;') . '</span>';
                        }
                    }
                    // Did this month's figure reach the month's own
                    // benchmark? Achievement is green once it matches or
                    // beats Target, red while it is short. A zero or
                    // missing target is no benchmark at all — everything
                    // clears zero, so colouring it would say nothing.
                    $hitClass = ''; $hitTitle = '';
                    $benchCode = $benchmarks[$code] ?? null;
                    if ($benchCode !== null && $cell && $cell['value_num'] !== null) {
                        $bench = $grid[$benchCode][$m] ?? null;
                        if ($bench && $bench['value_num'] !== null && (float)$bench['value_num'] > 0) {
                            $met       = (float)$cell['value_num'] >= (float)$bench['value_num'];
                            $hitClass  = $met ? 'perf-hit' : 'perf-miss';
                            $benchName = $paramByCode[$benchCode]['param_name'] ?? $benchCode;
                            $hitTitle  = ($met ? 'Met ' : 'Below ') . strtolower((string)$benchName)
                                       . ' (' . perfDisplayValue($bench, $paramByCode[$benchCode] ?? $p) . ')';
                        }
                    }

                    $shown = perfDisplayValue($cell, $p);
                    $isNote = $cell && $cell['value_num'] === null && $cell['value_text'] !== null;
                    $pastRemark = $remarks[$m][$code]['remark'] ?? '';
                ?>
                    <td class="perf-cell <?= $isReview ? 'perf-col-review' : '' ?>">
                        <div class="perf-num" title="<?= h($hitTitle !== '' ? $hitTitle : $shown) ?>">
                        <?php if ($shown === ''): ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($isNote): ?>
                            <span class="perf-note"><?= h($shown) ?></span>
                        <?php else: ?>
                            <span class="<?= $hitClass ?>"><?= h($shown) ?></span><?= $delta ?>
                        <?php endif; ?>
                        </div>

                        <?php if ($isReview && $canRemark): ?>
                            <textarea class="form-control" name="remark[<?= h($code) ?>]" rows="2"
                                      maxlength="4000"
                                      placeholder="Remark…"><?= h((string)($myRemarks[$code]['remark'] ?? '')) ?></textarea>
                        <?php elseif ($showRemarks && $pastRemark !== ''): ?>
                            <div class="perf-cell-remark" title="<?= h((string)($remarks[$m][$code]['full_name'] ?? '')) ?>">
                                <?= nl2br(h($pastRemark)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($canRemark): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">Save remarks</button>
            <button type="submit" name="submit_review" value="1" class="btn btn-primary">Submit for review</button>
        </div>
        <div class="text-muted" style="margin-top:6px">
            Save keeps the month open so you can come back to it. Submit tells Operations the
            remarks are complete; you can still edit until the month is concluded.
        </div>
    <?php endif; ?>
</form>

<div class="perf-concl">
    <div class="form-section-title" style="margin-top:0">Operations conclusion · <?= h(perfMonthLabel($month)) ?></div>
    <?php if ($canConclude): ?>
        <form method="POST">
            <input type="hidden" name="action" value="perf_save_conclusion">
            <input type="hidden" name="location_id" value="<?= $locId ?>">
            <input type="hidden" name="period_month" value="<?= h($month) ?>">
            <textarea name="conclusion" class="form-control" rows="4" maxlength="8000"
                      placeholder="Closing remarks for the month — what went well, what has to change, what is agreed with the Store Manager."><?= h((string)($review['conclusion'] ?? '')) ?></textarea>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">Save draft</button>
                <?php if (!$isConcluded): ?>
                    <button type="submit" name="conclude" value="1" class="btn btn-primary">Conclude month</button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($isConcluded): ?>
            <form method="POST" style="margin-top:10px"
                  onsubmit="return confirm('Reopen <?= h(perfMonthLabel($month)) ?> so remarks can be edited?')">
                <input type="hidden" name="action" value="perf_reopen_review">
                <input type="hidden" name="location_id" value="<?= $locId ?>">
                <input type="hidden" name="period_month" value="<?= h($month) ?>">
                <button type="submit" class="btn btn-danger btn-sm">Reopen month</button>
            </form>
        <?php endif; ?>
    <?php elseif (trim((string)($review['conclusion'] ?? '')) !== ''): ?>
        <div style="font-size:13px;line-height:1.7"><?= nl2br(h((string)$review['conclusion'])) ?></div>
    <?php else: ?>
        <div class="text-muted">Operations has not written a conclusion for this month yet.</div>
    <?php endif; ?>

    <?php
    // Earlier conclusions in the window, newest first — the running
    // commentary Operations wants next to the numbers.
    $past = [];
    foreach (array_reverse($months) as $m) {
        if ($m === $month) continue;
        $c = trim((string)($reviews[$m]['conclusion'] ?? ''));
        if ($c !== '') $past[$m] = $c;
    }
    ?>
    <?php if ($past): ?>
        <div class="form-section-title">Earlier conclusions</div>
        <?php foreach ($past as $m => $c): ?>
            <div class="perf-concl-past">
                <span class="m"><?= h(perfMonthLabel($m)) ?></span>
                <span class="text-muted"><?= h((string)($reviews[$m]['concluded_name'] ?? '')) ?></span><br>
                <?= nl2br(h($c)) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
}

// ── CSV export of the review grid ───────────────────────
// Same shape as the screen: parameters down, months across, then the
// remarks block and the conclusions, so a month can be mailed on or
// pasted back into the workbook.
function exportPerfReview(): void {
    if (!perfCanUsePage() || !perfSchemaReady()) { echo 'Access denied.'; exit; }
    $ctx = perfReviewContext();
    if ($ctx === null) { echo 'Access denied.'; exit; }

    $locId   = $ctx['location_id'];
    $month   = $ctx['month'];
    $months  = $ctx['months'];
    $locName = perfLocationName($locId);
    $params  = perfParameters();
    $grid    = perfValueGrid($locId, $months);
    $reviews = perfReviewHeaders($locId, $months);
    $remarks = perfRemarkGrid($reviews);

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $locName) ?: 'outlet';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="performance_' . $slug . '_' . perfMonthInput($month) . '.csv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['Store Performance'], escape: '');
    fputcsv($out, ['Outlet', $locName], escape: '');
    fputcsv($out, ['Review month', perfMonthLabel($month)], escape: '');
    fputcsv($out, ['Status', (string)($reviews[$month]['status'] ?? 'pending')], escape: '');
    fputcsv($out, [], escape: '');

    $head = ['Parameter'];
    foreach ($months as $m) $head[] = perfMonthLabel($m);
    fputcsv($out, $head, escape: '');
    foreach ($params as $p) {
        $row = [perfParamLabel($p)];
        foreach ($months as $m) $row[] = perfRawValue($grid[(string)$p['param_code']][$m] ?? null, $p);
        fputcsv($out, $row, escape: '');
    }

    fputcsv($out, [], escape: '');
    fputcsv($out, ['Store Manager remarks'], escape: '');
    fputcsv($out, $head, escape: '');
    foreach ($params as $p) {
        $row = [perfParamLabel($p)];
        foreach ($months as $m) $row[] = (string)($remarks[$m][(string)$p['param_code']]['remark'] ?? '');
        fputcsv($out, $row, escape: '');
    }

    fputcsv($out, [], escape: '');
    fputcsv($out, ['Operations conclusion'], escape: '');
    fputcsv($out, ['Month', 'By', 'On', 'Conclusion'], escape: '');
    foreach (array_reverse($months) as $m) {
        $c = trim((string)($reviews[$m]['conclusion'] ?? ''));
        if ($c === '') continue;
        fputcsv($out, [
            perfMonthLabel($m),
            (string)($reviews[$m]['concluded_name'] ?? ''),
            (string)($reviews[$m]['concluded_at'] ?? ''),
            $c,
        ], escape: '');
    }

    fclose($out);
    exit;
}
