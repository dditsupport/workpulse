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
//     · flags parameters that need explaining, with a note saying what —
//       the store cannot submit the month until each is answered
//     · writes the closing conclusion for a month, which locks it.
//       Reopening a concluded month is superadmin only (perfCanReopen):
//       concluding is meant to be the end of it, so undoing it sits a
//       level above the people doing the reviewing.
//
//   Store Manager (employees.location_id — no txn flag at all)
//     · sees ONLY their own outlet
//     · answers every flagged parameter, and may justify any other
//     · is the ONLY role that can answer: the Save / Submit controls
//       exist for whoever owns the outlet and for nobody else
//
// The two halves live on one perf_remarks row and stay separate on
// purpose: the question is a working document for the month under review,
// the answer is the record. History shows the answer only — a figure that
// was questioned is highlighted, and the question itself is not re-aired.
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
// History is read in financial years, not in rolling months: Operations
// compares Aug against last Aug and reads a year Apr → Mar, so the window
// is "how many financial years back", never "how many months back".
// 0 means every year on file (data starts Apr 2024).
const PERF_FY_START_MONTH  = 4;                   // April opens the year
const PERF_DEFAULT_FY_SPAN = 2;                   // this FY and the one before it
const PERF_FY_CHOICES      = [1, 2, 3, 0];

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

// Who answers: the Store Manager whose employee record carries this
// outlet, and nobody else — not Operations, not superadmin. The point of a
// request is that the store explains itself, so a justification any
// onlooker could type would not be a justification, and a submit gate they
// could satisfy would gate nothing.
//
// Deliberately no superadmin escape hatch: the auditable way to fix a bad
// entry is Operations reopening the month, which is one button and leaves
// a trace, rather than an admin quietly editing the store's own words.
function perfCanRemark(int $locationId): bool {
    if ($locationId <= 0) return false;
    return $locationId === perfMyLocation();
}

// Who asks: Operations flags any parameter on any outlet as needing a
// justification, with a note saying what to explain. A Store Manager
// cannot flag — they answer.
function perfCanFlag(int $locationId): bool {
    return $locationId > 0 && perfCanAdmin();
}

// The outlet's Store Manager, for the "on behalf of" banner. Read from
// the same mapping the audit workflow uses (Store Operations > Manager
// Mapping). Empty when the outlet has no mapping yet — the banner then
// just says "the Store Manager", which is still true.
function perfStoreManagerName(int $locationId): string {
    try {
        $st = getDb()->prepare(
            'SELECT e.full_name
             FROM location_managers lm
             JOIN employees e ON e.employee_code = lm.store_manager_code
             WHERE lm.location_id = ?');
        $st->execute([$locationId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Exception $e) { return ''; }
}

// Who may reopen a concluded month: superadmin only. Concluding is meant
// to be the end of the month, so undoing it sits one level above the
// people who do the reviewing — Operations concludes, an administrator
// reverses it.
function perfCanReopen(int $locationId): bool {
    return $locationId > 0 && isSuperadmin();
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
            'SELECT param_code, param_name, value_type, better, default_target
             FROM perf_parameters WHERE is_active = 1
             ORDER BY sort_order, param_code'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // default_target arrives with the goals migration. Without it the
        // whole module would otherwise render no parameters at all, so
        // fall back to the columns that have always been there and treat
        // every goal as unset.
        try {
            $params = getDb()->query(
                'SELECT param_code, param_name, value_type, better
                 FROM perf_parameters WHERE is_active = 1
                 ORDER BY sort_order, param_code'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($params as &$p) $p['default_target'] = null;
            unset($p);
        } catch (Exception $e2) {
            $params = [];
        }
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

// ── Goals ───────────────────────────────────────────────
// The number a parameter is held to at one outlet: the outlet's own row
// if it has one, otherwise the company-wide default. A row whose
// target_value is NULL is a deliberate "not judged here" and beats the
// default; no row at all falls through to it.
//   [param_code => float|null]
function perfGoals(int $locationId): array {
    static $cache = [];
    if (isset($cache[$locationId])) return $cache[$locationId];
    $goals = [];
    foreach (perfParameters() as $p) {
        $goals[(string)$p['param_code']] =
            $p['default_target'] === null ? null : (float)$p['default_target'];
    }
    try {
        $st = getDb()->prepare('SELECT param_code, target_value FROM perf_targets WHERE location_id = ?');
        $st->execute([$locationId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $goals[(string)$r['param_code']] =
                $r['target_value'] === null ? null : (float)$r['target_value'];
        }
    } catch (Exception $e) { /* pre-migration: defaults only */ }
    return $cache[$locationId] = $goals;
}

// Did this figure meet its goal? null when there is nothing to judge —
// no goal, no number, or a parameter with no good direction.
function perfMeetsGoal(?float $value, ?float $goal, string $better): ?bool {
    if ($value === null || $goal === null) return null;
    if ($better === 'up')   return $value >= $goal;
    if ($better === 'down') return $value <= $goal;
    return null;
}

// "at most 2%" / "at least 95%" — how a goal reads for a parameter.
function perfGoalLabel(?float $goal, array $param): string {
    if ($goal === null || $param['better'] === 'none') return '';
    $shown = perfDisplayValue(['value_num' => $goal, 'value_text' => null], $param);
    return ($param['better'] === 'down' ? '≤ ' : '≥ ') . $shown;
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

// ── Financial year (Apr–Mar) ────────────────────────────
// Apr 2026 … Mar 2027 is all one year, labelled "FY 2026-27". Everything
// that groups or orders months on this screen goes through these, so the
// year boundary is stated once.
function perfFyStartYear(string $ymd): int {
    $y = (int)substr($ymd, 0, 4);
    $m = (int)substr($ymd, 5, 2);
    return $m < PERF_FY_START_MONTH ? $y - 1 : $y;
}

// The Apr-1 date of the financial year a month belongs to — the key the
// FY column groups hang off.
function perfFyStart(string $ymd): string {
    return sprintf('%04d-%02d-01', perfFyStartYear($ymd), PERF_FY_START_MONTH);
}

function perfFyLabel(string $ymd): string {
    $y = perfFyStartYear($ymd);
    return sprintf('FY %04d-%02d', $y, ($y + 1) % 100);
}

// Position of a month inside its own financial year: Apr = 1 … Mar = 12.
function perfFyMonthNo(string $ymd): int {
    $m = (int)substr($ymd, 5, 2);
    return (($m - PERF_FY_START_MONTH + 12) % 12) + 1;
}

// Months (already in order) split into their financial years, keeping that
// order: [fy_start => [month, …]]. Used for the column-group header, so a
// year reads as a block instead of twelve unrelated columns.
function perfMonthsByFy(array $months): array {
    $out = [];
    foreach ($months as $m) $out[perfFyStart($m)][] = $m;
    return $out;
}

function perfFySpanLabel(int $span): string {
    if ($span <= 0) return 'All years';
    return $span === 1 ? 'This FY' : $span . ' financial years';
}

// The reverse of perfFyMonthNo(): which calendar month sits at position
// $pos of the financial year that opened on $fyStart. Apr 2026 + 11 is
// Mar 2027, so the year rolls over inside the row.
function perfFyMonthKey(string $fyStart, int $pos): string {
    $y = (int)substr($fyStart, 0, 4);
    $m = PERF_FY_START_MONTH + $pos - 1;
    if ($m > 12) { $m -= 12; $y++; }
    return sprintf('%04d-%02d-01', $y, $m);
}

// Column heading for position 1…12 — the month with no year on it, because
// the year is the row.
function perfFyPosLabel(int $pos): string {
    static $names = ['Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];
    return $names[$pos - 1] ?? '';
}

// The calendar month before this one. The month-on-month arrow follows the
// calendar, not the row: April's previous month is the March sitting one
// row up, in the financial year before it.
function perfPrevMonth(string $ymd): string {
    $y = (int)substr($ymd, 0, 4);
    $m = (int)substr($ymd, 5, 2) - 1;
    if ($m < 1) { $m = 12; $y--; }
    return sprintf('%04d-%02d-01', $y, $m);
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
        // Always two places, zeros kept: a column of 75.10% / 94.00% /
        // 100.00% lines up on the decimal point, and 0.30% does not read
        // as 0.3 of a point when scanned quickly next to 0.03%.
        'percent' => number_format($v, 2, '.', '') . '%',
        // Audit Score is a graded figure, not a count: 88.75 is a different
        // result from 88, and rounding it to a whole number threw away
        // precision the audit module had already earned. Trailing zeros are
        // trimmed, so 88 stays 88 and 99.10 reads 99.1.
        'decimal' => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'),
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

// Per-parameter rows across the whole window, so past months render their
// justifications inline next to the number they explain:
//   [period_month][param_code] => ['remark'=>…, 'flagged'=>…, 'flag_note'=>…, …]
// Both halves are read here; which half a month may show is decided at
// render time — history shows the answer only, never the question.
function perfRemarkGrid(array $reviews): array {
    $ids = [];
    foreach ($reviews as $r) $ids[] = (int)$r['id'];
    if (!$ids) return [];
    $byId = [];
    foreach ($reviews as $month => $r) $byId[(int)$r['id']] = $month;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = getDb()->prepare(
        "SELECT m.review_id, m.param_code, m.remark, m.updated_by, m.updated_at,
                m.flagged, m.flag_note, m.flagged_by, m.flagged_at,
                e.full_name, f.full_name AS flagged_name
         FROM perf_remarks m
         LEFT JOIN employees e ON e.employee_code = m.updated_by
         LEFT JOIN employees f ON f.employee_code = m.flagged_by
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

// Open justification requests on a review: flagged parameters with no
// answer yet. This is the list that blocks the Store Manager's submit,
// and the count Operations reads on the outlet list.
// $rows is one month's slice of perfRemarkGrid(): [param_code => row].
function perfOpenRequests(array $rows): array {
    $open = [];
    foreach ($rows as $code => $r) {
        if ((int)($r['flagged'] ?? 0) === 1 && trim((string)($r['remark'] ?? '')) === '') {
            $open[] = (string)$code;
        }
    }
    return $open;
}

// ── Admin: the parameter master ─────────────────────────
// Operations owns the list of 18 (or however many it grows to). Adding
// one, renaming it, changing how it reads or what it is held to, and
// retiring one that no longer matters.
//
// Nothing is ever deleted: a retired parameter goes is_active = 0, which
// drops it out of the grid, the CSV template and the import, while every
// value and justification already recorded against it stays put and comes
// back untouched if it is reactivated.
function doPerfSaveParameter(): void {
    $back = 'index.php?page=perf_params';
    if (!perfCanAdmin())    { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }
    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: ' . $back); exit; }

    $orig = trim((string)($_POST['orig_code'] ?? ''));      // '' = adding
    $code = trim((string)($_POST['param_code'] ?? ''));
    $name = trim((string)($_POST['param_name'] ?? ''));
    $type = (string)($_POST['value_type'] ?? 'number');
    $bett = (string)($_POST['better'] ?? 'none');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $dflt = trim((string)($_POST['default_target'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9]{1,4}$/', $code)) {
        flash('error', 'The code must be 1–4 letters or digits — it is the sort key and the name the CSV matches on.');
        header('Location: ' . $back); exit;
    }
    if ($name === '') { flash('error', 'Give the parameter a name.'); header('Location: ' . $back); exit; }
    if (!in_array($type, ['amount', 'number', 'percent', 'decimal'], true)) $type = 'number';
    if (!in_array($bett, ['up', 'down', 'none'], true)) $bett = 'none';

    [$target, ] = perfParseValue($dflt);
    if ($dflt !== '' && $target === null) {
        flash('error', 'The default goal must be a number, or left blank for "not judged".');
        header('Location: ' . $back); exit;
    }
    // A percentage goal is typed the way the grid shows it — 2 for 2% —
    // rather than as the fraction an upload carries.
    if ($sort <= 0) $sort = (int)$code ?: 99;

    $db = getDb();
    try {
        if ($orig === '') {
            // Checked here rather than left to the unique index, so the
            // answer is "that code is taken" and not a driver message —
            // and so a retired parameter is found, since it is still there.
            $dup = $db->prepare('SELECT param_name, is_active FROM perf_parameters WHERE param_code = ?');
            $dup->execute([$code]);
            $existing = $dup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                flash('error', 'Code "' . $code . '" is already used by ' . $code . (string)$existing['param_name']
                    . ((int)$existing['is_active'] === 0
                        ? ', which is retired — reactivate it rather than adding a second one.'
                        : '. Pick another code.'));
                header('Location: ' . $back); exit;
            }
            $db->prepare(
                'INSERT INTO perf_parameters (param_code, param_name, value_type, better, default_target, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([$code, $name, $type, $bett, $target, $sort]);
            flash('success', 'Added ' . $code . $name . '.');
        } else {
            // The code is the identity every value and justification hangs
            // off, so it is not editable — rename freely, recode never.
            $db->prepare(
                'UPDATE perf_parameters
                 SET param_name = ?, value_type = ?, better = ?, default_target = ?, sort_order = ?
                 WHERE param_code = ?'
            )->execute([$name, $type, $bett, $target, $sort, $orig]);
            flash('success', 'Updated ' . $orig . $name . '.');
        }
    } catch (Exception $e) {
        flash('error', str_contains($e->getMessage(), 'uq_perf_param_code') || str_contains($e->getMessage(), 'Duplicate')
            ? 'A parameter with code "' . h($code) . '" already exists.'
            : 'Could not save the parameter: ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

function doPerfToggleParameter(): void {
    $back = 'index.php?page=perf_params';
    if (!perfCanAdmin())    { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }
    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: ' . $back); exit; }

    $code = trim((string)($_POST['param_code'] ?? ''));
    try {
        getDb()->prepare('UPDATE perf_parameters SET is_active = 1 - is_active WHERE param_code = ?')
               ->execute([$code]);
        $st = getDb()->prepare('SELECT param_name, is_active FROM perf_parameters WHERE param_code = ?');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        flash('success', $code . (string)($row['param_name'] ?? '')
            . ((int)($row['is_active'] ?? 0) === 1
                ? ' is active again. Its history comes back with it.'
                : ' retired. Its recorded values and justifications are kept.'));
    } catch (Exception $e) {
        flash('error', 'Could not change the parameter: ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

// ── Admin: one outlet's goals ───────────────────────────
// A blank box means "no goal of its own" and falls back to the
// company-wide default; there is no way to say "no goal at all" without
// clearing the default too, which is the honest simplification — a goal
// that applies everywhere except here is a default with an override.
function doPerfSaveTargets(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $back  = 'index.php?page=perf_targets&loc=' . $locId;
    if (!perfCanAdmin())    { flash('error', 'Access denied.'); header('Location: index.php?page=perf_targets'); exit; }
    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: ' . $back); exit; }
    if ($locId <= 0)        { flash('error', 'Pick an outlet first.'); header('Location: index.php?page=perf_targets'); exit; }

    $posted = $_POST['target'] ?? [];
    if (!is_array($posted)) $posted = [];

    $db  = getDb();
    $me  = myCode();
    $set = 0; $cleared = 0; $bad = [];
    try {
        $db->beginTransaction();
        $up = $db->prepare(
            'INSERT INTO perf_targets (location_id, param_code, target_value, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE target_value = VALUES(target_value), updated_by = VALUES(updated_by)');
        $del = $db->prepare('DELETE FROM perf_targets WHERE location_id = ? AND param_code = ?');

        foreach (perfParameters() as $p) {
            $code = (string)$p['param_code'];
            $raw  = trim((string)($posted[$code] ?? ''));
            if ($raw === '') { $del->execute([$locId, $code]); $cleared++; continue; }
            [$num, ] = perfParseValue($raw);
            if ($num === null) { $bad[] = perfParamLabel($p); continue; }
            $up->execute([$locId, $code, $num, $me]);
            $set++;
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Could not save the goals: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    $msg = $set . ' goal' . ($set === 1 ? '' : 's') . ' set for ' . perfLocationName($locId)
         . ($cleared ? ', ' . $cleared . ' left to the company default' : '') . '.';
    if ($bad) $msg .= ' Not a number, so skipped: ' . implode(', ', $bad) . '.';
    flash($bad ? 'error' : 'success', $msg);
    header('Location: ' . $back); exit;
}

// ── Review: Operations asks for a justification ─────────
// Ticking a parameter creates the request; the note says what needs
// explaining. Unticking withdraws it, and removes the row entirely when
// the store had not answered yet — a withdrawn question should leave no
// trace, but an answer already given is the store's and is kept.
function doPerfSaveFlags(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $month = perfNormalizeMonth((string)($_POST['period_month'] ?? '')) ?? '';
    $back  = 'index.php?page=perf_review&loc=' . $locId
           . '&month=' . urlencode(perfMonthInput($month)) . '&justify=1';

    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: index.php'); exit; }
    if ($month === '' || !perfCanViewLocation($locId) || !perfCanFlag($locId)) {
        flash('error', 'Access denied — only Operations can ask for a justification.');
        header('Location: index.php?page=perf_review'); exit;
    }

    $db       = getDb();
    $reviewId = perfEnsureReview($locId, $month);
    $me       = myCode();
    $wanted   = $_POST['flag']      ?? [];
    $notes    = $_POST['flag_note'] ?? [];
    if (!is_array($wanted)) $wanted = [];
    if (!is_array($notes))  $notes  = [];

    $asked = 0;
    try {
        $db->beginTransaction();
        $set = $db->prepare(
            'INSERT INTO perf_remarks (review_id, param_code, flagged, flag_note, flagged_by, flagged_at)
             VALUES (?, ?, 1, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               flagged    = 1,
               flag_note  = VALUES(flag_note),
               flagged_by = VALUES(flagged_by),
               flagged_at = VALUES(flagged_at)');
        // Withdrawing keeps an answer that already exists; it only drops
        // the question.
        $clear = $db->prepare(
            'UPDATE perf_remarks
             SET flagged = 0, flag_note = NULL, flagged_by = NULL, flagged_at = NULL
             WHERE review_id = ? AND param_code = ?');
        $drop = $db->prepare(
            'DELETE FROM perf_remarks
             WHERE review_id = ? AND param_code = ? AND COALESCE(remark, \'\') = \'\'');

        foreach (perfParameters() as $p) {
            $code = (string)$p['param_code'];
            if (!empty($wanted[$code])) {
                $set->execute([$reviewId, $code, mb_substr(trim((string)($notes[$code] ?? '')), 0, 1000) ?: null, $me]);
                $asked++;
            } else {
                $clear->execute([$reviewId, $code]);
                $drop->execute([$reviewId, $code]);
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Could not save the justification requests: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }

    flash('success', $asked === 0
        ? 'No parameters are marked for justification for ' . perfMonthLabel($month) . '.'
        : $asked . ' parameter' . ($asked === 1 ? '' : 's') . ' marked for justification for '
          . perfMonthLabel($month) . '. The Store Manager cannot submit until each one is answered.');
    header('Location: ' . $back); exit;
}

// ── Review: the Store Manager's per-parameter justifications ───
function doPerfSaveRemarks(): void {
    $locId = (int)($_POST['location_id'] ?? 0);
    $month = perfNormalizeMonth((string)($_POST['period_month'] ?? '')) ?? '';
    // Coming back in the same mode: an Operations user typing on a
    // manager's behalf should land back on the open boxes, not a read-only
    // page. The flag is presentation only — perfCanRemark() below is the
    // gate, and it does not consult it.
    $back  = 'index.php?page=perf_review&loc=' . $locId . '&month=' . urlencode(perfMonthInput($month))
           . (($_POST['justify'] ?? '') === '1' ? '&justify=1' : '');

    if (!perfSchemaReady()) { flash('error', perfSchemaNotice()); header('Location: index.php'); exit; }
    if ($month === '' || !perfCanViewLocation($locId) || !perfCanRemark($locId)) {
        flash('error', 'Access denied — you cannot write remarks for that outlet.');
        header('Location: index.php?page=perf_review'); exit;
    }

    $db = getDb();
    $reviewId = perfEnsureReview($locId, $month);

    // A concluded month is closed. Operations reopens it if the Store
    // Manager needs to change something after the fact.
    $st = $db->prepare('SELECT status FROM perf_reviews WHERE id = ?');
    $st->execute([$reviewId]);
    if ((string)$st->fetchColumn() === 'concluded') {
        flash('error', perfCanReopen($locId)
            ? 'This month is already concluded. Reopen it before editing justifications.'
            : 'This month is already concluded. Ask an administrator to reopen it before editing justifications.');
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
        // A flagged row survives an emptied box, but the stale answer must
        // not: blanking it reopens the request, which is what the manager
        // just asked for by clearing the text.
        $blank = $db->prepare(
            'UPDATE perf_remarks SET remark = NULL, updated_by = ?
             WHERE review_id = ? AND param_code = ? AND flagged = 1');
        $del = $db->prepare('DELETE FROM perf_remarks WHERE review_id = ? AND param_code = ? AND flagged = 0');

        $filled = 0;
        foreach (perfParameters() as $p) {
            $code = (string)$p['param_code'];
            $text = trim((string)($posted[$code] ?? ''));
            if ($text === '') {
                // Clearing the box drops the row — unless Operations asked
                // for this one, where the row IS the open request and
                // deleting it would quietly withdraw their question.
                $del->execute([$reviewId, $code]);
                $blank->execute([$me, $reviewId, $code]);
                continue;
            }
            $up->execute([$reviewId, $code, mb_substr($text, 0, 4000), $me]);
            $filled++;
        }

        // "Submit" marks the month reviewed; a plain save leaves it open so
        // a manager can come back to it later in the day. Submitting with a
        // requested justification still unanswered is refused: that request
        // is the reason the month is open.
        // Refusing the submit must not cost the manager the answers they did
        // type: everything above is committed either way, and only the
        // status change is withheld. Losing sixteen answers because the
        // seventeenth was missed would teach people to avoid the button.
        $blocked = [];
        if (!empty($_POST['submit_review'])) {
            $st = $db->prepare(
                'SELECT m.param_code
                 FROM perf_remarks m
                 WHERE m.review_id = ? AND m.flagged = 1 AND COALESCE(m.remark, \'\') = \'\'');
            $st->execute([$reviewId]);
            $blocked = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$blocked) {
                $db->prepare(
                    'UPDATE perf_reviews
                     SET status = \'remarked\', remarked_by = ?, remarked_at = NOW()
                     WHERE id = ? AND status <> \'concluded\''
                )->execute([$me, $reviewId]);
            }
        }
        $db->commit();

        if ($blocked) {
            $names = [];
            foreach (perfParameters() as $p) {
                if (in_array((string)$p['param_code'], $blocked, true)) $names[] = perfParamLabel($p);
            }
            flash('error', 'Saved, but not submitted — ' . count($blocked) . ' requested justification'
                . (count($blocked) === 1 ? '' : 's') . ' still unanswered: ' . implode(', ', $names)
                . '. Everything else you typed is saved; answer these and submit again.');
            header('Location: ' . $back); exit;
        }
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

    // Concluding ends the month for the conclusion too, not only for the
    // store's justifications. A draft saved afterwards would rewrite what
    // was signed off without anything recording that it changed.
    $st = $db->prepare('SELECT status FROM perf_reviews WHERE id = ?');
    $st->execute([$reviewId]);
    if ((string)$st->fetchColumn() === 'concluded') {
        flash('error', perfCanReopen($locId)
            ? 'This month is already concluded. Reopen it before editing the conclusion.'
            : 'This month is already concluded. Ask an administrator to reopen it before editing the conclusion.');
        header('Location: ' . $back); exit;
    }

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
    if ($month === '' || !perfCanReopen($locId)) {
        flash('error', 'Access denied — only an administrator can reopen a concluded month.');
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
                'decimal' => 'score, 2 decimals — 88.75',
                default   => 'count — 1036',
            };
            fputcsv($out, [$month, (string)$l['location_name'], perfParamLabel($p), '', $hint], escape: '');
        }
    }
    fclose($out);
    exit;
}

// ── Page: the parameter master (Operations) ─────────────
function pagePerfParams(): void {
    if (!perfCanAdmin()) {
        echo '<div class="page-header"><h2>Performance Parameters</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to configure performance parameters.</div>';
        return;
    }
    if (!perfSchemaReady()) {
        echo '<div class="page-header"><h2>Performance Parameters</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    try {
        $rows = getDb()->query(
            'SELECT p.param_code, p.param_name, p.value_type, p.better, p.default_target,
                    p.sort_order, p.is_active,
                    (SELECT COUNT(*) FROM perf_values v WHERE v.param_code = p.param_code) AS uses,
                    (SELECT COUNT(*) FROM perf_targets t WHERE t.param_code = p.param_code) AS overrides
             FROM perf_parameters p ORDER BY p.sort_order, p.param_code')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo '<div class="page-header"><h2>Performance Parameters</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    // Editing one row = the same form, pre-filled.
    $editCode = trim((string)($_GET['edit'] ?? ''));
    $edit = null;
    foreach ($rows as $r) if ((string)$r['param_code'] === $editCode) $edit = $r;
?>
<div class="page-header">
    <h2>📐 Performance Parameters</h2>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="index.php?page=perf_targets">Outlet goals</a>
        <a class="btn btn-ghost btn-sm" href="index.php?page=perf_reviews">Reviews</a>
    </div>
</div>

<div class="report-header-box" style="margin-bottom:16px">
    The code is the sort key and the name the CSV matches on, so it is fixed once a parameter exists —
    rename freely, recode never. <b>Retiring</b> a parameter takes it out of the grid, the template and
    the import; every value and justification already recorded against it is kept, and comes back if it
    is made active again. Nothing here is ever deleted.<br>
    <b>Default goal</b> is the company-wide number this parameter is held to — typed the way the grid
    shows it (<code>2</code> for 2%, not 0.02). Whether it reads as a ceiling or a floor comes from
    <b>Good direction</b>: <i>lower is better</i> makes it a maximum, <i>higher is better</i> a minimum,
    <i>neither</i> means the figure is reported but not judged. Any outlet can
    <a href="index.php?page=perf_targets" style="color:var(--accent)">override it</a>.
</div>

<form method="POST" class="form-card" style="max-width:none;margin-bottom:18px">
    <input type="hidden" name="action" value="perf_save_parameter">
    <input type="hidden" name="orig_code" value="<?= h((string)($edit['param_code'] ?? '')) ?>">
    <div class="form-section-title" style="margin-top:0">
        <?= $edit ? 'Edit ' . h(perfParamLabel($edit)) : 'Add a parameter' ?>
    </div>
    <div class="form-grid" style="grid-template-columns:repeat(3,1fr);max-width:1000px">
        <div class="form-group">
            <label>Code <span class="required">*</span></label>
            <input type="text" name="param_code" class="form-control" maxlength="4" required
                   value="<?= h((string)($edit['param_code'] ?? '')) ?>"
                   <?= $edit ? 'readonly' : '' ?> placeholder="19">
        </div>
        <div class="form-group" style="grid-column:span 2">
            <label>Name <span class="required">*</span></label>
            <input type="text" name="param_name" class="form-control" maxlength="100" required
                   value="<?= h((string)($edit['param_name'] ?? '')) ?>" placeholder="Delivery Rating">
        </div>
        <div class="form-group">
            <label>Shown as</label>
            <select name="value_type" class="form-control">
                <?php foreach ([
                    'number'  => 'Count — 1,036',
                    'amount'  => 'Rupees — 4,45,000',
                    'percent' => 'Percent — 75.10%',
                    'decimal' => 'Score — 88.75',
                ] as $v => $lbl): ?>
                    <option value="<?= $v ?>" <?= ($edit['value_type'] ?? 'number') === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Good direction</label>
            <select name="better" class="form-control">
                <?php foreach ([
                    'up'   => 'Higher is better (goal = minimum)',
                    'down' => 'Lower is better (goal = maximum)',
                    'none' => 'Neither — reported, not judged',
                ] as $v => $lbl): ?>
                    <option value="<?= $v ?>" <?= ($edit['better'] ?? 'none') === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Default goal <span class="hint">blank = none</span></label>
            <input type="text" name="default_target" class="form-control"
                   value="<?= $edit && $edit['default_target'] !== null
                       ? h(rtrim(rtrim(number_format((float)$edit['default_target'], 4, '.', ''), '0'), '.')) : '' ?>"
                   placeholder="2">
        </div>
        <div class="form-group">
            <label>Sort order <span class="hint">blank = follow the code</span></label>
            <input type="number" name="sort_order" class="form-control" min="0"
                   value="<?= h((string)($edit['sort_order'] ?? '')) ?>">
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add parameter' ?></button>
        <?php if ($edit): ?><a class="btn btn-ghost" href="index.php?page=perf_params">Cancel</a><?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th>Code</th><th>Name</th><th>Shown as</th><th>Good direction</th>
            <th>Default goal</th><th>Outlet overrides</th><th>Values recorded</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $goal = $r['default_target'] === null ? null : (float)$r['default_target']; ?>
            <tr class="<?= (int)$r['is_active'] ? '' : 'row-inactive' ?>">
                <td data-label="Code"><strong><?= h((string)$r['param_code']) ?></strong></td>
                <td data-label="Name"><?= h((string)$r['param_name']) ?></td>
                <td data-label="Shown as" class="text-muted"><?= h((string)$r['value_type']) ?></td>
                <td data-label="Good direction" class="text-muted"><?= h((string)$r['better']) ?></td>
                <td data-label="Default goal"><?= $goal === null ? '<span class="text-muted">—</span>' : h(perfGoalLabel($goal, $r)) ?></td>
                <td data-label="Outlet overrides"><?= (int)$r['overrides'] ?: '<span class="text-muted">—</span>' ?></td>
                <td data-label="Values recorded" class="text-muted"><?= number_format((int)$r['uses']) ?></td>
                <td data-label="Status"><?= (int)$r['is_active']
                    ? '<span class="badge badge-green">Active</span>'
                    : '<span class="badge badge-grey">Retired</span>' ?></td>
                <td class="actions">
                    <a class="btn btn-sm btn-ghost" href="index.php?page=perf_params&edit=<?= urlencode((string)$r['param_code']) ?>">Edit</a>
                    <form method="POST" class="inline-form"
                          onsubmit="return confirm('<?= (int)$r['is_active'] ? 'Retire' : 'Reactivate' ?> <?= h(perfParamLabel($r)) ?>?')">
                        <input type="hidden" name="action" value="perf_toggle_parameter">
                        <input type="hidden" name="param_code" value="<?= h((string)$r['param_code']) ?>">
                        <button type="submit" class="btn btn-sm <?= (int)$r['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                            <?= (int)$r['is_active'] ? 'Retire' : 'Reactivate' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}

// ── Page: one outlet's goals (Operations) ───────────────
function pagePerfTargets(): void {
    if (!perfCanAdmin()) {
        echo '<div class="page-header"><h2>Outlet Goals</h2></div>';
        echo '<div class="rpt-prompt">You don\'t have access to configure outlet goals.</div>';
        return;
    }
    if (!perfSchemaReady()) {
        echo '<div class="page-header"><h2>Outlet Goals</h2></div>';
        echo '<div class="rpt-prompt">' . h(perfSchemaNotice()) . '</div>';
        return;
    }

    $locations = getActiveLocations();
    $locId     = (int)($_GET['loc'] ?? 0);
    if ($locId <= 0 && $locations) $locId = (int)$locations[0]['location_id'];
    $params = perfParameters();
    $goals  = $locId > 0 ? perfGoals($locId) : [];

    // Which of them are the outlet's own, as opposed to inherited.
    $own = [];
    if ($locId > 0) {
        try {
            $st = getDb()->prepare('SELECT param_code, target_value FROM perf_targets WHERE location_id = ?');
            $st->execute([$locId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $own[(string)$r['param_code']] = $r['target_value'] === null ? null : (float)$r['target_value'];
            }
        } catch (Exception $e) { $own = []; }
    }
?>
<div class="page-header">
    <h2>🎯 Outlet Goals</h2>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="index.php?page=perf_params">Parameters</a>
        <a class="btn btn-ghost btn-sm" href="index.php?page=perf_reviews">Reviews</a>
    </div>
</div>

<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="perf_targets">
    <label class="text-muted">Outlet</label>
    <select name="loc" class="form-control" style="width:260px" onchange="this.form.submit()">
        <?php foreach ($locations as $l): ?>
            <option value="<?= (int)$l['location_id'] ?>" <?= (int)$l['location_id'] === $locId ? 'selected' : '' ?>>
                <?= h($l['location_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-secondary btn-sm" type="submit">Go</button></noscript>
</form>

<div class="report-header-box" style="margin-bottom:16px">
    What this outlet is held to. <b>Leave a box blank</b> and it follows the company-wide default from
    <a href="index.php?page=perf_params" style="color:var(--accent)">Parameters</a> — useful when only a
    few outlets differ. Type the number the way the grid shows it: <code>2</code> for 2%, not 0.02.
    A goal turns the figure green or red in the review grid; a parameter whose direction is
    <i>neither</i> is never judged, so a goal there is ignored.
</div>

<?php if ($locId <= 0): ?>
    <div class="rpt-prompt">No active outlets to configure.</div>
<?php else: ?>
<form method="POST">
    <input type="hidden" name="action" value="perf_save_targets">
    <input type="hidden" name="location_id" value="<?= $locId ?>">
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th style="width:220px">Parameter</th><th style="width:150px">Good direction</th>
                <th style="width:130px">Company default</th><th style="width:180px">This outlet</th><th>In force</th>
            </tr></thead>
            <tbody>
            <?php foreach ($params as $p):
                $code = (string)$p['param_code'];
                $dflt = $p['default_target'] === null ? null : (float)$p['default_target'];
                $mine = array_key_exists($code, $own) ? $own[$code] : null;
                $eff  = $goals[$code] ?? null; ?>
                <tr>
                    <td data-label="Parameter"><strong><?= h($code) ?></strong> <?= h((string)$p['param_name']) ?></td>
                    <td data-label="Good direction" class="text-muted">
                        <?= $p['better'] === 'up' ? 'higher is better' : ($p['better'] === 'down' ? 'lower is better' : '— not judged') ?>
                    </td>
                    <td data-label="Company default" class="text-muted">
                        <?= $dflt === null ? '—' : h(perfGoalLabel($dflt, $p)) ?>
                    </td>
                    <td data-label="This outlet">
                        <input type="text" name="target[<?= h($code) ?>]" class="form-control"
                               value="<?= $mine === null ? '' : h(rtrim(rtrim(number_format($mine, 4, '.', ''), '0'), '.')) ?>"
                               placeholder="<?= $dflt === null ? 'none' : 'inherits ' . h(rtrim(rtrim(number_format($dflt, 4, '.', ''), '0'), '.')) ?>">
                    </td>
                    <td data-label="In force">
                        <?php if ($eff === null || $p['better'] === 'none'): ?>
                            <span class="text-muted">not judged</span>
                        <?php else: ?>
                            <?= h(perfGoalLabel($eff, $p)) ?>
                            <span class="text-muted" style="font-size:11px">(<?= $mine !== null ? 'this outlet' : 'default' ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save goals for <?= h(perfLocationName($locId)) ?></button></div>
</form>
<?php endif; ?>
<?php
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
                (SELECT COUNT(*) FROM perf_remarks m
                  WHERE m.review_id = r.id AND COALESCE(m.remark, \'\') <> \'\') AS remarks,
                (SELECT COUNT(*) FROM perf_remarks m
                  WHERE m.review_id = r.id AND m.flagged = 1) AS requested,
                (SELECT COUNT(*) FROM perf_remarks m
                  WHERE m.review_id = r.id AND m.flagged = 1
                    AND COALESCE(m.remark, \'\') = \'\') AS unanswered
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
        <!-- Wrapped in .actions: .page-header is space-between, so loose
             buttons get spread to the far corners of the page instead of
             sitting together as one group.
             Upload is the only route to that page — it is deliberately not
             a sidebar entry — so it stays the primary of the three. -->
        <div class="actions">
            <a class="btn btn-primary btn-sm" href="index.php?page=perf_upload">Upload month's data</a>
            <a class="btn btn-ghost btn-sm" href="index.php?page=perf_targets">Outlet goals</a>
            <a class="btn btn-ghost btn-sm" href="index.php?page=perf_params">Parameters</a>
        </div>
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
            <th>Outlet</th><th>Status</th><th>Justifications</th><th>Remarks</th>
            <th>Store Manager</th><th>Conclusion</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$outlets): ?>
            <tr><td colspan="7" class="empty-row">No outlets have data for this month.</td></tr>
        <?php endif; ?>
        <?php foreach ($outlets as $o):
            $r = $reviews[(int)$o['location_id']] ?? null; ?>
            <tr>
                <td data-label="Outlet"><strong><?= h($o['location_name']) ?></strong></td>
                <td data-label="Status"><?= perfStatusBadge($r['status'] ?? null) ?></td>
                <td data-label="Justifications">
                    <?php $req = (int)($r['requested'] ?? 0); $open = (int)($r['unanswered'] ?? 0); ?>
                    <?php if (!$req): ?>
                        <span class="text-muted">—</span>
                    <?php elseif ($open): ?>
                        <span class="badge badge-amber"><?= $open ?> of <?= $req ?> unanswered</span>
                    <?php else: ?>
                        <span class="badge badge-green"><?= $req ?> answered</span>
                    <?php endif; ?>
                </td>
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
                    <?php // Not on a concluded month: the remarks are locked there, so
                          // the button would open a page that cannot be typed into.
                          // Reopen it from the review first.
                          if (perfCanAdmin() && ($r['status'] ?? '') !== 'concluded'): ?>
                        <!-- Same page, remark boxes open. Operations may write the
                             per-parameter remarks for a manager who is sitting with
                             them or has not logged in; it is a deliberate mode
                             rather than boxes that are always live, so a normal
                             read-through cannot be typed into by accident. -->
                        <a class="btn btn-sm btn-ghost"
                           href="index.php?page=perf_review&loc=<?= (int)$o['location_id'] ?>&month=<?= h(perfMonthInput($month)) ?>&justify=1">Justify</a>
                    <?php endif; ?>
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

    // How far back to read, counted in financial years: 1 is the review
    // month's own Apr → Mar, 2 adds the year before it, 0 is everything on
    // file. isset() rather than ?? because 0 is a real choice here.
    $span = isset($_GET['fy']) ? (int)$_GET['fy'] : PERF_DEFAULT_FY_SPAN;
    if (!in_array($span, PERF_FY_CHOICES, true)) $span = PERF_DEFAULT_FY_SPAN;

    // The window is the review month and the months before it, oldest
    // first — reading left to right is reading forward in time, the way
    // the workbook's pivot already reads. Months after the review month
    // stay out even when the rest of their financial year is on file: the
    // year is read up to the month being reviewed, not past it.
    $upTo = array_values(array_filter($allMonths, fn($m) => $m <= $month));
    sort($upTo);
    if ($span > 0) {
        $from = sprintf('%04d-%02d-01',
            perfFyStartYear($month) - ($span - 1), PERF_FY_START_MONTH);
        $months = array_values(array_filter($upTo, fn($m) => $m >= $from));
    } else {
        $months = $upTo;
    }
    if (!in_array($month, $months, true)) $months[] = $month;

    return [
        'location_id' => $locId,
        'month'       => $month,
        'months'      => $months,
        'all_months'  => $allMonths,
        'fy_span'     => $span,
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
    $fySpan    = $ctx['fy_span'];
    // One row per financial year, per parameter; twelve fixed columns
    // Apr → Mar. $monthSet says which of those slots actually has a month
    // behind it — the earliest year may start mid-way and the latest stops
    // at the month under review.
    $fyList    = array_keys(perfMonthsByFy($months));
    sort($fyList);
    $monthSet  = array_flip($months);
    $reviewFy  = perfFyStart($month);
    $reviewPos = perfFyMonthNo($month);
    $locName   = perfLocationName($locId);
    $params    = perfParameters();

    $showRemarks = !isset($_GET['remarks']) || $_GET['remarks'] !== '0';
    $paramByCode = [];
    foreach ($params as $p) $paramByCode[(string)$p['param_code']] = $p;
    $benchmarks  = perfBenchmarks();
    $goals       = perfGoals($locId);

    // One month before the window as well: the arrow on the earliest April
    // compares against the March above it, which is outside the window.
    $grid     = perfValueGrid($locId, array_merge($months, [perfPrevMonth(min($months))]));
    $reviews  = perfReviewHeaders($locId, $months);
    $remarks  = perfRemarkGrid($reviews);
    $review   = $reviews[$month] ?? null;
    $status   = (string)($review['status'] ?? 'pending');

    $isConcluded  = $status === 'concluded';
    $canConclude  = perfCanConclude($locId);
    $myRemarks    = $remarks[$month] ?? [];

    // Writing remarks for an outlet that is not yours is "on behalf of"
    // the Store Manager, and takes the explicit Justify link to switch
    // into — the boxes are not live during an ordinary read. The manager
    // on their own outlet always has them.
    $justify   = ($_GET['justify'] ?? '') === '1';
    // Two editing modes, never both at once. Justify is Operations asking:
    // tick the parameters that need explaining and say what to explain.
    // Otherwise the Store Manager answers.
    $flagMode  = $justify && perfCanFlag($locId) && !$isConcluded;
    $canRemark = !$flagMode && perfCanRemark($locId) && !$isConcluded;
    $openReqs  = perfOpenRequests($myRemarks);
    $flagCount = 0;
    foreach ($myRemarks as $r) if ((int)($r['flagged'] ?? 0) === 1) $flagCount++;

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
        'month' => perfMonthInput($month), 'fy' => $fySpan,
        'remarks' => $showRemarks ? '1' : '0',
    ] + ($justify ? ['justify' => '1'] : []), $over));
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
/* What this outlet is held to, next to the name it belongs to — a green
   or red figure in the row means nothing without it. */
.perf-goal{font-weight:400;font-size:10px;color:var(--muted);margin-top:2px;letter-spacing:.02em}
.perf-grid th.perf-month{text-align:right;width:var(--perf-col)}
.perf-grid td.perf-cell{text-align:right;font-family:Consolas,monospace;padding:8px 12px}
.perf-grid .perf-num{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.perf-col-review,.perf-grid th.perf-col-review{background:rgba(26,143,227,.06)}
.perf-grid th.perf-col-review{width:var(--perf-review-col)}
/* The one cell actually under review, as opposed to its column, which is
   only the same month in the other years. */
.perf-grid td.perf-cell-now{background:rgba(26,143,227,.14);
    box-shadow:inset 0 0 0 1px rgba(26,143,227,.35)}
.perf-delta{font-size:10px;margin-left:5px;font-family:inherit}
.perf-up{color:var(--green)}.perf-down{color:var(--red)}
/* Met / missed the month's own benchmark — Achievement against Target.
   Independent of the delta arrow beside it, which is month-on-month: a
   figure can be down on last month and still ahead of target. */
.perf-hit{color:var(--green);font-weight:700}
.perf-miss{color:var(--red);font-weight:700}
.perf-note{color:var(--yellow);font-family:inherit;font-style:italic;font-size:11.5px}
/* The financial year is a column, not a header group: it sits beside the
   parameter and pins with it, so a row is always readable as
   "this parameter, this year" however far the months are scrolled. */
.perf-grid .perf-fy-col,.perf-grid .perf-fy-cell{position:sticky;left:200px;z-index:2;
    background:var(--surface);width:118px;text-align:left;white-space:nowrap;
    border-right:1px solid var(--border);font-size:11px;color:var(--muted);
    font-weight:600;letter-spacing:.02em}
.perf-grid thead .perf-fy-col{z-index:3;text-transform:none}
.perf-grid tbody tr:hover .perf-fy-cell{background:var(--surface)}
.perf-grid .perf-fy-cell-review{color:var(--accent)}
/* The line between one parameter's block of years and the next. */
.perf-grid tr.perf-row-first > th,.perf-grid tr.perf-row-first > td{border-top:2px solid var(--border)}
/* A slot with no month behind it — before the outlet's first upload, or
   after the month under review. Left empty on purpose: "not reached yet"
   and "a month we hold with no figure" are different statements, and the
   dash is reserved for the second. */
.perf-grid td.perf-cell-void{background:repeating-linear-gradient(135deg,
    transparent,transparent 5px,rgba(255,255,255,.022) 5px,rgba(255,255,255,.022) 10px)}
/* A remark is one click, not a paragraph wedged under a number: the cell
   keeps its height and the note opens over the page. */
.perf-remark-btn{display:inline-flex;align-items:center;gap:3px;margin-top:5px;
    padding:1px 6px;border:1px solid var(--border);border-radius:10px;
    background:transparent;color:var(--muted);font-family:inherit;font-size:10px;
    line-height:1.6;cursor:pointer}
.perf-remark-btn:hover{color:var(--text);border-color:var(--accent)}
.perf-remark-btn[aria-expanded="true"]{color:var(--accent);border-color:var(--accent)}
.perf-remark-btn-open{color:#ffce6b;border-color:rgba(245,158,11,.55);
    background:rgba(245,158,11,.10)}
.perf-remark-ico{font-size:9px;opacity:.85}
/* The panel itself is appended to <body> and positioned from the button:
   .table-wrap scrolls, so anything absolutely positioned inside a cell
   would be clipped by it. */
#perfPop{position:fixed;z-index:900;width:280px;max-height:46vh;overflow:auto;
    background:var(--surface);border:1px solid var(--border);border-radius:8px;
    box-shadow:0 10px 30px rgba(0,0,0,.45);padding:10px 12px;font-size:11.5px;
    line-height:1.5;white-space:normal;text-align:left}
#perfPop[hidden]{display:none}
.perf-pop-head{font-weight:600;font-size:11px;margin-bottom:6px;
    padding-bottom:5px;border-bottom:1px solid var(--border);color:var(--text)}
.perf-pop-head span{display:block;font-weight:400;font-size:10px;color:var(--muted);margin-top:1px}
.perf-pop-ask{border-left:2px solid var(--yellow);background:rgba(245,158,11,.10);
    border-radius:0 4px 4px 0;padding:5px 7px;margin-bottom:7px;color:#ffce6b;font-style:italic}
.perf-pop-body{color:var(--muted);font-style:italic}
.perf-cell-remark{margin-top:5px;padding-top:5px;border-top:1px dashed rgba(255,255,255,.12);
    font-family:inherit;font-size:11px;font-style:italic;color:var(--muted);
    text-align:left;white-space:normal;line-height:1.45}
/* Who wrote the justification. */
.perf-remark-by{font-style:normal;font-size:10px;opacity:.75;margin-top:2px}
/* A figure Operations asked about. In history this highlight is the only
   trace of the request — the question itself is not shown there, only the
   store's answer — so it has to carry the meaning on its own. */
.perf-flagged{background:rgba(245,158,11,.16);border-radius:3px;padding:1px 5px;
    box-shadow:inset 0 0 0 1px rgba(245,158,11,.45)}
.perf-cell-flagged{background:rgba(245,158,11,.05)}
.perf-cell-ask{margin-top:5px;padding:5px 7px;border-left:2px solid var(--yellow);
    background:rgba(245,158,11,.10);border-radius:0 4px 4px 0;
    font-family:inherit;font-size:11px;color:#ffce6b;text-align:left;
    white-space:normal;line-height:1.45}
.perf-grid textarea.perf-required{border-color:var(--yellow)}
.perf-flag-tick{display:flex;align-items:center;gap:5px;margin-top:6px;
    font-family:inherit;font-size:10.5px;color:var(--yellow);text-align:left;
    white-space:normal;cursor:pointer;user-select:none}
.perf-flag-tick input{width:13px;height:13px;cursor:pointer;flex:0 0 auto}
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
        <?php if (perfCanFlag($locId) && !$flagMode && !$isConcluded): ?>
            <a class="btn btn-sm btn-primary" href="<?= h($qs(['justify' => '1'])) ?>">Justify</a>
        <?php elseif ($flagMode): ?>
            <a class="btn btn-sm btn-ghost" href="<?= h($qs(['justify' => '0'])) ?>">Done</a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= h($qs(['page' => 'export_perf_review'])) ?>">Export CSV</a>
        <?php if (perfCanViewAll()): ?>
            <a class="btn btn-ghost btn-sm" href="index.php?page=perf_reviews&month=<?= h(perfMonthInput($month)) ?>">All outlets</a>
        <?php endif; ?>
    </div>
</div>

<form method="GET" class="filter-bar">
    <input type="hidden" name="page" value="perf_review">
    <?php if ($justify): ?><input type="hidden" name="justify" value="1"><?php endif; ?>
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

    <!-- Months grouped under the financial year they belong to, so picking
         one is picking a month of a year rather than off a flat list. -->
    <label class="text-muted">Review month</label>
    <select name="month" class="form-control" style="width:190px" onchange="this.form.submit()">
        <?php foreach (perfMonthsByFy($allMonths) as $fyStart => $fyMonths): ?>
            <optgroup label="<?= h(perfFyLabel($fyStart)) ?>">
                <?php foreach ($fyMonths as $m): ?>
                    <option value="<?= h(perfMonthInput($m)) ?>" <?= $m === $month ? 'selected' : '' ?>>
                        <?= h(perfMonthLabel($m)) ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>

    <label class="text-muted">History</label>
    <select name="fy" class="form-control" style="width:150px" onchange="this.form.submit()">
        <?php foreach (PERF_FY_CHOICES as $f): ?>
            <option value="<?= $f ?>" <?= $f === $fySpan ? 'selected' : '' ?>>
                <?= h(perfFySpanLabel($f)) ?>
            </option>
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
    <span class="text-muted"><b>Remarks</b>
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

<?php if ($isConcluded && perfCanRemark($locId) && !$canRemark): ?>
    <div class="alert alert-success">This month is concluded, so justifications are locked.
        <?= perfCanReopen($locId) ? 'Reopen it below to change them.' : 'Ask an administrator to reopen it if something needs changing.' ?></div>
<?php endif; ?>

<?php if ($flagMode):
    $smName = perfStoreManagerName($locId); ?>
    <div class="alert alert-error">
        <b>Asking <?= $smName !== '' ? h($smName) : 'the Store Manager' ?> for a justification</b>
        — <?= h($locName) ?>, <?= h(perfMonthLabel($month)) ?>.
        Tick any parameter that needs explaining and say what you want explained. The Store Manager
        cannot submit the month until every ticked parameter is answered.
    </div>
<?php elseif ($canRemark && $openReqs): ?>
    <div class="alert alert-error">
        <b><?= count($openReqs) ?> parameter<?= count($openReqs) === 1 ? '' : 's' ?>
        need<?= count($openReqs) === 1 ? 's' : '' ?> a justification before you can submit.</b>
        They are marked below; every other parameter is optional.
    </div>
<?php elseif ($canRemark && $flagCount): ?>
    <div class="alert alert-success">All <?= $flagCount ?> requested justification<?= $flagCount === 1 ? '' : 's' ?>
        answered. You can submit the month.</div>
<?php elseif (perfCanFlag($locId) && !$isConcluded): ?>
    <div class="text-muted" style="margin-bottom:12px">
        <?php if ($flagCount): ?>
            <?= $flagCount ?> parameter<?= $flagCount === 1 ? '' : 's' ?> marked for justification,
            <?= count($openReqs) ?> still unanswered. Use <b>Justify</b> above to change what is asked.
        <?php else: ?>
            Reading only — use <b>Justify</b> above to ask the Store Manager to explain a figure.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="POST" id="perfRemarkForm">
    <input type="hidden" name="action" value="<?= $flagMode ? 'perf_save_flags' : 'perf_save_remarks' ?>">
    <input type="hidden" name="location_id" value="<?= $locId ?>">
    <input type="hidden" name="period_month" value="<?= h($month) ?>">
    <?php if ($justify): ?><input type="hidden" name="justify" value="1"><?php endif; ?>

    <div class="table-wrap">
    <!-- Only the cell under review is typed into, so only its column needs
         the width for a textarea; the other eleven stay numeric. -->
    <table class="table perf-grid"
           style="--perf-col:112px;--perf-review-col:<?= ($canRemark || $flagMode) ? '240px' : '112px' ?>">
        <thead>
            <!-- The grid is a pivot, not a timeline: twelve fixed columns
                 running April → March, and one row per parameter per
                 financial year. Reading across a row is reading one year;
                 reading down a parameter's block is the same month a year
                 apart, which is the comparison Operations actually makes and
                 which a single run of months never put side by side. -->
            <tr>
                <th class="perf-param">Parameter</th>
                <th class="perf-fy-col">Financial year</th>
                <?php for ($pos = 1; $pos <= 12; $pos++): ?>
                    <th class="perf-month<?= $pos === $reviewPos ? ' perf-col-review' : '' ?>">
                        <?= h(perfFyPosLabel($pos)) ?>
                        <?php if ($pos === $reviewPos): ?><br><span style="font-size:10px;color:var(--accent)">under review</span><?php endif; ?>
                    </th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php if (!$params): ?>
            <tr><td colspan="14" class="empty-row">No parameters configured.</td></tr>
        <?php endif; ?>
        <?php foreach ($params as $p):
            $code = (string)$p['param_code'];
            foreach ($fyList as $fi => $fyStart): ?>
            <tr<?= $fi === 0 ? ' class="perf-row-first"' : '' ?>>
                <?php if ($fi === 0): ?>
                    <!-- Spans the parameter's financial years: the name is the
                         block, the years are the rows inside it. -->
                    <th class="perf-param" rowspan="<?= count($fyList) ?>">
                        <span class="perf-code"><?= h($code) ?></span><?= h($p['param_name']) ?>
                        <?php $goalLabel = perfGoalLabel($goals[$code] ?? null, $p); ?>
                        <?php if ($goalLabel !== ''): ?>
                            <div class="perf-goal" title="Goal for this outlet">goal <?= h($goalLabel) ?></div>
                        <?php endif; ?>
                    </th>
                <?php endif; ?>
                <td class="perf-fy-cell<?= $fyStart === $reviewFy ? ' perf-fy-cell-review' : '' ?>">
                    <?= h(perfFyLabel($fyStart)) ?>
                </td>
                <?php for ($pos = 1; $pos <= 12; $pos++):
                    $m = perfFyMonthKey($fyStart, $pos);

                    // Nothing uploaded against that slot — a year that started
                    // mid-way, or the months after the one under review. Left
                    // blank rather than dashed: "not reached yet" and "no
                    // figure for a month we do hold" are different statements.
                    if (!isset($monthSet[$m])): ?>
                        <td class="perf-cell perf-cell-void<?= $pos === $reviewPos ? ' perf-col-review' : '' ?>"></td>
                    <?php continue; endif;

                    $isReview = $m === $month;
                    $cell     = $grid[$code][$m] ?? null;
                    $prevCell = $grid[$code][perfPrevMonth($m)] ?? null;

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
                    } elseif ($cell && $cell['value_num'] !== null) {
                        // No month-specific benchmark, so judge against the
                        // outlet's standing goal — "wastage under 2% here,
                        // under 5% there".
                        $goal = $goals[$code] ?? null;
                        $met  = perfMeetsGoal((float)$cell['value_num'], $goal, (string)$p['better']);
                        if ($met !== null) {
                            $hitClass = $met ? 'perf-hit' : 'perf-miss';
                            $hitTitle = ($met ? 'Met goal ' : 'Missed goal ') . perfGoalLabel($goal, $p);
                        }
                    }

                    $shown  = perfDisplayValue($cell, $p);
                    $isNote = $cell && $cell['value_num'] === null && $cell['value_text'] !== null;

                    // The cell holds both halves of the exchange. Which half
                    // it may show is the whole rule: the month under review
                    // shows the question and the answer, because the manager
                    // has to know what is being asked. Every other month shows
                    // the answer only — the value is highlighted to say a
                    // justification was asked for, and the asking itself is
                    // not re-litigated in the history.
                    $row        = $remarks[$m][$code] ?? null;
                    $isFlagged  = $row && (int)($row['flagged'] ?? 0) === 1;
                    $answer     = trim((string)($row['remark'] ?? ''));
                    $question   = $isReview ? trim((string)($row['flag_note'] ?? '')) : '';
                    $unanswered = $isFlagged && $answer === '';
                    // The column carries no year, so the hover does.
                    $cellTitle  = perfMonthLabel($m);
                ?>
                    <td class="perf-cell<?= $pos === $reviewPos ? ' perf-col-review' : '' ?><?= $isReview ? ' perf-cell-now' : '' ?><?= $isFlagged ? ' perf-cell-flagged' : '' ?>">
                        <div class="perf-num<?= $isFlagged ? ' perf-flagged' : '' ?>"
                             title="<?= h($cellTitle
                                 . ($isFlagged ? ' · Justification ' . ($answer === '' ? 'requested' : 'given') : '')
                                 . ($hitTitle !== '' ? ' · ' . $hitTitle : '')) ?>">
                        <?php if ($shown === ''): ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($isNote): ?>
                            <span class="perf-note"><?= h($shown) ?></span>
                        <?php else: ?>
                            <span class="<?= $hitClass ?>"><?= h($shown) ?></span><?= $delta ?>
                        <?php endif; ?>
                        </div>

                        <?php if ($isReview && $flagMode): ?>
                            <label class="perf-flag-tick">
                                <input type="checkbox" name="flag[<?= h($code) ?>]" value="1"
                                       <?= $isFlagged ? 'checked' : '' ?>> needs justification
                            </label>
                            <textarea class="form-control" name="flag_note[<?= h($code) ?>]" rows="2"
                                      maxlength="1000"
                                      placeholder="What needs explaining?"><?= h($question) ?></textarea>
                            <?php if ($answer !== ''): ?>
                                <div class="perf-cell-remark"><?= nl2br(h($answer)) ?>
                                    <div class="perf-remark-by">— <?= h((string)($row['full_name'] ?? '')) ?></div>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($isReview && $canRemark): ?>
                            <?php if ($question !== ''): ?>
                                <div class="perf-cell-ask">
                                    <b>Asked:</b> <?= nl2br(h($question)) ?>
                                    <?php if (!empty($row['flagged_name'])): ?>
                                        <div class="perf-remark-by">— <?= h((string)$row['flagged_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isFlagged): ?>
                                <div class="perf-cell-ask"><b>Justification required</b></div>
                            <?php endif; ?>
                            <textarea class="form-control<?= $unanswered ? ' perf-required' : '' ?>"
                                      name="remark[<?= h($code) ?>]" rows="2" maxlength="4000"
                                      placeholder="<?= $isFlagged ? 'Justification (required)…' : 'Justification (optional)…' ?>"><?= h($answer) ?></textarea>

                        <?php else:
                            // Read-only: the words sit behind a small button
                            // rather than under the number. One long remark used
                            // to set the height of its whole row and push the
                            // year off the screen; collapsed, the grid stays a
                            // grid of figures and the note is one click away.
                            $by      = trim((string)($row['full_name'] ?? ''));
                            $askedBy = trim((string)($row['flagged_name'] ?? ''));
                            $hasAsk  = $isReview && $question !== '';
                            // An open request is not a remark: it survives the
                            // Show remarks toggle, the way it did when it was
                            // printed into the cell.
                            $hasNote = $hasAsk || $unanswered
                                    || ($showRemarks && $answer !== '');
                            if ($hasNote):
                                // Amber while a request is unanswered, plain once
                                // the store has written something back.
                                $btnCls = $unanswered ? ' perf-remark-btn-open' : '';
                                $label  = $answer !== '' ? 'Remark'
                                        : (($unanswered || $hasAsk) ? 'Asked' : 'Note');
                            ?>
                            <button type="button" class="perf-remark-btn<?= $btnCls ?>"
                                    aria-expanded="false"
                                    title="<?= h($label . ' · ' . perfMonthLabel($m)) ?>">
                                <span class="perf-remark-ico">&#128172;</span><?= h($label) ?>
                            </button>
                            <div class="perf-pop-src" hidden>
                                <div class="perf-pop-head">
                                    <?= h($p['param_name']) ?>
                                    <span><?= h(perfMonthLabel($m)) ?> · <?= h(perfFyLabel($m)) ?></span>
                                </div>
                                <?php if ($hasAsk): ?>
                                    <div class="perf-pop-ask">
                                        <b>Asked:</b> <?= nl2br(h($question)) ?>
                                        <?php if ($askedBy !== ''): ?>
                                            <div class="perf-remark-by">— <?= h($askedBy) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($answer !== ''): ?>
                                    <div class="perf-pop-body">
                                        <?= nl2br(h($answer)) ?>
                                        <?php if ($by !== ''): ?>
                                            <div class="perf-remark-by">— <?= h($by) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="perf-pop-body text-muted">Awaiting justification</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($flagMode): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save justification requests</button>
            <a class="btn btn-ghost" href="<?= h($qs(['justify' => '0'])) ?>">Cancel</a>
        </div>
        <div class="text-muted" style="margin-top:6px">
            Unticking a parameter withdraws the request. An answer the store has already given is
            kept; a question nobody answered leaves no trace.
        </div>
    <?php elseif ($canRemark): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">Save</button>
            <button type="submit" name="submit_review" value="1" class="btn btn-primary"
                <?= $openReqs ? 'title="' . count($openReqs) . ' requested justification(s) still unanswered"' : '' ?>>Submit for review</button>
        </div>
        <div class="text-muted" style="margin-top:6px">
            Save keeps the month open so you can come back to it. Submit tells Operations you are done
            — it is refused while a requested justification is unanswered, and you can still edit
            until the month is concluded.
        </div>
    <?php endif; ?>
</form>

<div class="perf-concl">
    <div class="form-section-title" style="margin-top:0">Operations conclusion · <?= h(perfMonthLabel($month)) ?></div>
    <?php if ($canConclude && !$isConcluded): ?>
        <form method="POST">
            <input type="hidden" name="action" value="perf_save_conclusion">
            <input type="hidden" name="location_id" value="<?= $locId ?>">
            <input type="hidden" name="period_month" value="<?= h($month) ?>">
            <textarea name="conclusion" class="form-control" rows="4" maxlength="8000"
                      placeholder="Closing remarks for the month — what went well, what has to change, what is agreed with the Store Manager."><?= h((string)($review['conclusion'] ?? '')) ?></textarea>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">Save draft</button>
                <button type="submit" name="conclude" value="1" class="btn btn-primary">Conclude month</button>
            </div>
        </form>
    <?php elseif ($isConcluded): ?>
        <div style="font-size:13px;line-height:1.7"><?= nl2br(h((string)($review['conclusion'] ?? ''))) ?></div>
        <div class="text-muted" style="margin-top:8px">
            Concluded by <?= h((string)($review['concluded_name'] ?? '')) ?>
            on <?= h((string)($review['concluded_at'] ?? '')) ?> — locked.
            <?= perfCanReopen($locId) ? 'Reopen it below to change anything.' : 'Ask an administrator to reopen it.' ?>
        </div>
        <?php if (perfCanReopen($locId)): ?>
            <form method="POST" style="margin-top:10px"
                  onsubmit="return confirm('Reopen <?= h(perfMonthLabel($month)) ?> so justifications can be edited?')">
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
                <span class="m"><?= h(perfFyLabel($m)) ?> · <?= h(perfMonthLabel($m)) ?></span>
                <span class="text-muted"><?= h((string)($reviews[$m]['concluded_name'] ?? '')) ?></span><br>
                <?= nl2br(h($c)) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
// One panel, reused. Each remark button carries its own text in a hidden
// sibling; clicking copies that into the panel and parks it under the
// button. Fixed positioning because the grid wrapper scrolls in both
// directions and would otherwise clip it.
(function () {
    var pop = null, openBtn = null;

    function panel() {
        if (!pop) {
            pop = document.createElement('div');
            pop.id = 'perfPop';
            pop.hidden = true;
            document.body.appendChild(pop);
        }
        return pop;
    }

    function close() {
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        openBtn = null;
        if (pop) pop.hidden = true;
    }

    function place(btn) {
        var p = panel(), r = btn.getBoundingClientRect();
        p.hidden = false;                       // measure at full size first
        var w = p.offsetWidth, hgt = p.offsetHeight, pad = 8;
        var left = Math.min(Math.max(pad, r.left), window.innerWidth - w - pad);
        // Below the button unless that runs off the bottom, then above it.
        var top = r.bottom + 6;
        if (top + hgt > window.innerHeight - pad) top = Math.max(pad, r.top - hgt - 6);
        p.style.left = left + 'px';
        p.style.top  = top + 'px';
    }

    document.addEventListener('click', function (e) {
        if (pop && pop.contains(e.target)) return;   // clicking inside keeps it open
        var btn = e.target.closest ? e.target.closest('.perf-remark-btn') : null;
        var was = openBtn;
        close();
        if (!btn || btn === was) return;             // same button again = toggle shut
        var src = btn.nextElementSibling;
        if (!src || !src.classList.contains('perf-pop-src')) return;
        panel().innerHTML = src.innerHTML;
        openBtn = btn;
        btn.setAttribute('aria-expanded', 'true');
        place(btn);
    });

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    // The panel is anchored to a button that moves when anything scrolls,
    // so follow it rather than leaving it stranded mid-page.
    window.addEventListener('resize', function () { if (openBtn) place(openBtn); });
    document.addEventListener('scroll', function () { if (openBtn) place(openBtn); }, true);
})();
</script>
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

    // Same shape as the screen: a row per parameter per financial year,
    // twelve fixed columns Apr → Mar, so a year lines up under the year
    // above it in the workbook too.
    $fyList   = array_keys(perfMonthsByFy($months));
    sort($fyList);
    $monthSet = array_flip($months);

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $locName) ?: 'outlet';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="performance_' . $slug . '_' . perfMonthInput($month) . '.csv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['Store Performance'], escape: '');
    fputcsv($out, ['Outlet', $locName], escape: '');
    fputcsv($out, ['Review month', perfMonthLabel($month)], escape: '');
    fputcsv($out, ['Financial year', perfFyLabel($month) . ' (Apr-Mar)'], escape: '');
    fputcsv($out, ['Status', (string)($reviews[$month]['status'] ?? 'pending')], escape: '');
    fputcsv($out, [], escape: '');

    $head = ['Parameter', 'Financial year'];
    for ($pos = 1; $pos <= 12; $pos++) $head[] = perfFyPosLabel($pos);
    fputcsv($out, $head, escape: '');
    foreach ($params as $p) {
        foreach ($fyList as $fyStart) {
            $row = [perfParamLabel($p), perfFyLabel($fyStart)];
            for ($pos = 1; $pos <= 12; $pos++) {
                $m = perfFyMonthKey($fyStart, $pos);
                $row[] = isset($monthSet[$m])
                    ? perfRawValue($grid[(string)$p['param_code']][$m] ?? null, $p) : '';
            }
            fputcsv($out, $row, escape: '');
        }
    }

    // The store's own words, every month in the window. The question that
    // prompted one is not repeated here for past months, the same rule the
    // screen follows; a * marks a month where one was asked.
    fputcsv($out, [], escape: '');
    fputcsv($out, ['Store Manager justifications', '(* = Operations asked for this one)'], escape: '');
    fputcsv($out, $head, escape: '');
    foreach ($params as $p) {
        foreach ($fyList as $fyStart) {
            $row = [perfParamLabel($p), perfFyLabel($fyStart)];
            for ($pos = 1; $pos <= 12; $pos++) {
                $m = perfFyMonthKey($fyStart, $pos);
                $r    = $remarks[$m][(string)$p['param_code']] ?? null;
                $text = trim((string)($r['remark'] ?? ''));
                $mark = $r && (int)($r['flagged'] ?? 0) === 1 ? '* ' : '';
                $row[] = $text === '' && $mark === '' ? '' : $mark . $text;
            }
            fputcsv($out, $row, escape: '');
        }
    }

    // What Operations asked for, review month only.
    $asked = [];
    foreach ($params as $p) {
        $r = $remarks[$month][(string)$p['param_code']] ?? null;
        if ($r && (int)($r['flagged'] ?? 0) === 1) $asked[] = [$p, $r];
    }
    if ($asked) {
        fputcsv($out, [], escape: '');
        fputcsv($out, ['Justifications requested · ' . perfMonthLabel($month)], escape: '');
        fputcsv($out, ['Parameter', 'Asked by', 'What needs explaining', 'Answered'], escape: '');
        foreach ($asked as [$p, $r]) {
            fputcsv($out, [
                perfParamLabel($p),
                (string)($r['flagged_name'] ?? ''),
                (string)($r['flag_note'] ?? ''),
                trim((string)($r['remark'] ?? '')) === '' ? 'no' : 'yes',
            ], escape: '');
        }
    }

    fputcsv($out, [], escape: '');
    fputcsv($out, ['Operations conclusion'], escape: '');
    fputcsv($out, ['Financial year', 'Month', 'By', 'On', 'Conclusion'], escape: '');
    foreach (array_reverse($months) as $m) {
        $c = trim((string)($reviews[$m]['conclusion'] ?? ''));
        if ($c === '') continue;
        fputcsv($out, [
            perfFyLabel($m),
            perfMonthLabel($m),
            (string)($reviews[$m]['concluded_name'] ?? ''),
            (string)($reviews[$m]['concluded_at'] ?? ''),
            $c,
        ], escape: '');
    }

    fclose($out);
    exit;
}
