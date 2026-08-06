<?php
// =========================================================
// Inward Barcode Register — MRP · BarCode · Expiry
//
// One row per BARCODE, not per item code. The same item_code
// legitimately carries several simultaneously-active barcodes at
// different MRPs: when the MRP changes, old-MRP stock stays on the
// shelf beside new-MRP stock, so both barcodes must live in the ERP
// at once. Each barcode has its own expiry, after which it has to be
// pulled back out of the ERP.
//
// Maker / checker:
//   txn_inward_item      — enter inward barcodes (form + CSV import)
//   txn_inward_validate  — validator: create the barcode in the ERP,
//                          then confirm its removal once it expires
//
// Lifecycle:  pending → active → removed
// "Due for removal" is DERIVED, never stored:
//     status = 'active' AND expire_on <= CURDATE()
// so confirming removal clears the dashboard tile and drops the row
// out of the next digest in a single write — there is no second flag
// to keep in sync.
//
// Schema: migration_2026_08_06_inward_items.sql
// =========================================================

// ── Constants ────────────────────────────────────────────
define('INW_CSV_MAX_BYTES', 5 * 1024 * 1024); // 5 MB
define('INW_PAGE_SIZE',     50);
define('INW_MAX_FORM_ROWS', 25);              // repeater rows per submit
define('INW_MAX_ERRORS',    50);              // import errors listed before truncating

// ── Access gates ─────────────────────────────────────────
function inwCanEnter(): bool {
    return isSuperadmin() || hasTxn('inward_item') || hasTxn('inward_validate');
}
function inwCanCreate(): bool {
    return isSuperadmin() || hasTxn('inward_item');
}
function inwCanValidate(): bool {
    return isSuperadmin() || hasTxn('inward_validate');
}

// Schema detection — same convention as pvHasConfirmCols(). Lets the
// pages show a "run the migration" notice instead of a fatal error on
// an install where the migration hasn't been applied yet.
function inwHasTable(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT id FROM inward_items LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

function inwMigrationNotice(): string {
    return '<div class="alert alert-error">Inward barcode register is not enabled — '
         . 'run migration_2026_08_06_inward_items.sql.</div>';
}

// ── Field parsing / validation ───────────────────────────

// Build a Y-m-d date from the three CSV parts. Returns null when the
// parts don't form a real calendar date.
//
// The year column accepts BOTH widths and they may be mixed row to row:
//   '26'   → 2026     (1-2 digits: 2000 + YY)
//   '06'   → 2006
//   '2026' → 2026     (4 digits: as-is)
//   '226', '20265', '2o26', '' → rejected
//
// Width is measured on the trimmed STRING, never on an int cast — casting
// first collapses '06' to 6 and there is then no way to tell 2006 from
// 2026 apart. checkdate() is what rejects 31 September rather than letting
// mktime() silently roll it into October.
function inwBuildDate($dRaw, $mRaw, $yRaw): ?string {
    $d = trim((string)$dRaw);
    $m = trim((string)$mRaw);
    $y = trim((string)$yRaw);
    if (!ctype_digit($d) || !ctype_digit($m) || !ctype_digit($y)) return null;

    $len = strlen($y);
    if     ($len <= 2)  $year = 2000 + (int)$y;
    elseif ($len === 4) $year = (int)$y;
    else                return null;          // 3- or 5-digit is a typo, not a guess
    if ($year < 2000 || $year > 2100) return null;

    $day = (int)$d;
    $mon = (int)$m;
    if (!checkdate($mon, $day, $year)) return null;
    return sprintf('%04d-%02d-%02d', $year, $mon, $day);
}

// The form posts a single Y-m-d from <input type="date">; run it through
// the same validator so the two entry paths can't drift apart.
function inwParseFormDate($raw): ?string {
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim((string)$raw), $m)) return null;
    return inwBuildDate($m[3], $m[2], $m[1]);
}

// ── MRP + barcode composition ────────────────────────────
// Both entry paths take the MRP and the plain barcode as separate fields
// and the system joins them: 55 + 7622202357039 becomes the stored
// 55-7622202357039. Keeping the two apart at entry is what makes "the
// same product at two live MRPs" natural — one barcode typed once, a row
// per price — and removes the chance of a hand-typed prefix disagreeing
// with the MRP column beside it.
//
// The separator is a hyphen so a fractional price stays readable and
// unambiguous: 53.50 composes to 53.5-7622202357039, where the dot can
// only be the decimal point and the hyphen can only be the join. A dot
// separator would have produced 53.5.7622202357039, which no longer says
// where the price ends.
const INW_BARCODE_SEP = '-';

// The plain barcode as entered: digits only. A separator or a dot here
// means someone pasted an already-joined value, which would compose to
// 53-53-7622…, so it is rejected with a message rather than mangled.
function inwCleanEan($raw): ?string {
    $b = trim((string)$raw);
    if ($b === '' || strlen($b) > 48) return null;
    if (!ctype_digit($b)) return null;
    return $b;
}

// The MRP rendered for the barcode prefix. Whole values lose the decimal
// part (53.00 → "53") so the same price always yields the same barcode no
// matter how it was typed; a genuine fractional price keeps its digits,
// minus trailing zeros (53.50 → "53.5").
function inwMrpForBarcode(float $mrp): string {
    if (abs($mrp - round($mrp)) < 0.005) return (string)(int)round($mrp);
    return rtrim(rtrim(number_format($mrp, 2, '.', ''), '0'), '.');
}

function inwComposeBarcode(float $mrp, string $ean): string {
    return inwMrpForBarcode($mrp) . INW_BARCODE_SEP . $ean;
}

// Inverse, for the CSV export: hand back the plain barcode so an exported
// sheet re-imports to exactly the same stored value. Falls back to the
// whole string if the stored value doesn't carry the expected prefix.
function inwBarcodeEan(string $barcode, $mrp): string {
    $prefix = inwMrpForBarcode((float)$mrp) . INW_BARCODE_SEP;
    return str_starts_with($barcode, $prefix) ? substr($barcode, strlen($prefix)) : $barcode;
}

function inwCleanMrp($raw): ?float {
    $v = str_replace([',', '₹', ' '], '', trim((string)$raw));
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    if ($f < 0 || $f > 99999999.99) return null;
    return round($f, 2);
}

function inwCleanCode($raw): ?string {
    $c = trim((string)$raw);
    if ($c === '' || strlen($c) > 64) return null;
    return $c;
}

// ── CSV column contract ──────────────────────────────────
// Single source of truth for the importer, the sample template and the
// export header, so the template can never drift from the parser.
function inwCsvColumns(): array {
    return [
        'item_code' => ['label' => 'Code',            'required' => true,
                        'aliases' => ['code', 'item code']],
        'item_name' => ['label' => 'Name',            'required' => false,
                        'aliases' => ['name', 'item name', 'description']],
        'mrp'       => ['label' => 'MRP',             'required' => true,
                        'aliases' => ['mrp', 'price']],
        'barcode'   => ['label' => 'BarCode',         'required' => true,
                        'aliases' => ['barcode', 'bar code']],
        'exp_day'   => ['label' => 'Expire On Date',  'required' => true,
                        'aliases' => ['expire on date', 'expiry date', 'exp date', 'expire date', 'day']],
        'exp_month' => ['label' => 'Expire On Month', 'required' => true,
                        'aliases' => ['expire on month', 'expiry month', 'exp month', 'expire month', 'month']],
        'exp_year'  => ['label' => 'Expire On Year',  'required' => true,
                        'aliases' => ['expire on year', 'expiry year', 'exp year', 'expire year', 'year']],
    ];
}

function inwCsvHeaderLine(): string {
    return implode(',', array_map(fn($c) => $c['label'], inwCsvColumns()));
}

// Normalise a header cell for alias matching: drop a trailing parenthetical
// note (so "Name (Update if new name describe)" and "Name (Optional)" both
// match "name"), fold underscores/hyphens to spaces, collapse runs of
// whitespace, lowercase.
function inwNormalizeHeader($raw): string {
    $h = trim((string)$raw);
    $h = preg_replace('/\s*\([^)]*\)\s*$/u', '', $h);
    $h = str_replace(['_', '-'], ' ', $h);
    $h = preg_replace('/\s+/u', ' ', $h);
    return strtolower(trim((string)$h));
}

// ── Data helpers ─────────────────────────────────────────
function inwGetItem(int $id): ?array {
    if ($id < 1) return null;
    try {
        $st = getDb()->prepare('SELECT * FROM inward_items WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// The live row for a barcode, if any. "Live" excludes 'removed' on purpose:
// a barcode that was confirmed out of the ERP and then reappears in a sheet
// genuinely has to go back in, so it starts a fresh pending row rather than
// reopening the settled one.
function inwFindLive(string $barcode): ?array {
    try {
        $st = getDb()->prepare(
            "SELECT * FROM inward_items
             WHERE barcode = ? AND status <> 'removed'
             ORDER BY id LIMIT 1"
        );
        $st->execute([$barcode]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

// Fall back to the master price list for a name the sheet left blank.
function inwLookupName(string $itemCode): ?string {
    if ($itemCode === '') return null;
    try {
        $st = getDb()->prepare('SELECT item_name FROM price_list WHERE item_code = ? LIMIT 1');
        $st->execute([$itemCode]);
        $n = trim((string)($st->fetchColumn() ?: ''));
        return $n !== '' ? $n : null;
    } catch (Exception $e) { return null; }
}

// Resolve list filters from the query string.
function inwResolveFilter(): array {
    return [
        'q'      => trim((string)($_GET['q'] ?? '')),
        'status' => (string)($_GET['status'] ?? ''),
        // due: '' (any) | today | overdue | due (today + overdue) | upcoming
        'due'    => (string)($_GET['due'] ?? (isset($_GET['view']) ? '' : 'due')),
        'from'   => trim((string)($_GET['from'] ?? '')),
        'to'     => trim((string)($_GET['to'] ?? '')),
        'page'   => max(1, (int)($_GET['p'] ?? 1)),
    ];
}

function inwBuildWhere(array $f): array {
    $where  = '';
    $params = [];

    if ($f['q'] !== '') {
        $like    = '%' . $f['q'] . '%';
        $where  .= ' AND (item_code LIKE ? OR item_name LIKE ? OR barcode LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (in_array($f['status'], ['pending', 'active', 'removed'], true)) {
        $where   .= ' AND status = ?';
        $params[] = $f['status'];
    }
    // The "due" buckets only ever describe barcodes still live in the ERP,
    // so they carry their own status predicate.
    switch ($f['due']) {
        case 'today':    $where .= " AND status = 'active' AND expire_on = CURDATE()"; break;
        case 'overdue':  $where .= " AND status = 'active' AND expire_on < CURDATE()"; break;
        case 'due':      $where .= " AND status = 'active' AND expire_on <= CURDATE()"; break;
        case 'upcoming': $where .= " AND status = 'active' AND expire_on > CURDATE()"
                                 . " AND expire_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; break;
    }
    if ($f['from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
        $where .= ' AND expire_on >= ?'; $params[] = $f['from'];
    }
    if ($f['to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
        $where .= ' AND expire_on <= ?'; $params[] = $f['to'];
    }
    return ['where' => $where, 'params' => $params];
}

function inwGetList(array $f): array {
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => INW_PAGE_SIZE];
    if (!inwHasTable()) return $empty;

    $flt  = inwBuildWhere($f);
    $base = ' FROM inward_items WHERE 1=1' . $flt['where'];

    try {
        $c = getDb()->prepare('SELECT COUNT(*)' . $base);
        $c->execute($flt['params']);
        $total = (int)$c->fetchColumn();
    } catch (Exception $e) { return $empty; }

    $pages = $total > 0 ? (int)ceil($total / INW_PAGE_SIZE) : 1;
    $page  = min(max(1, (int)$f['page']), $pages);
    $off   = ($page - 1) * INW_PAGE_SIZE;

    try {
        $st = getDb()->prepare(
            'SELECT *' . $base
            . ' ORDER BY expire_on ASC, item_code ASC, mrp ASC'
            . ' LIMIT ' . (int)INW_PAGE_SIZE . ' OFFSET ' . (int)$off
        );
        $st->execute($flt['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    return ['rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => $pages, 'per_page' => INW_PAGE_SIZE];
}

// Bucket counts for the filter chips on the list page.
function inwCounts(): array {
    $out = ['pending' => 0, 'active' => 0, 'removed' => 0, 'today' => 0, 'overdue' => 0, 'upcoming' => 0];
    if (!inwHasTable()) return $out;
    try {
        $r = getDb()->query(
            "SELECT
               SUM(status = 'pending')                          AS pending,
               SUM(status = 'active')                           AS active,
               SUM(status = 'removed')                          AS removed,
               SUM(status = 'active' AND expire_on = CURDATE()) AS today,
               SUM(status = 'active' AND expire_on < CURDATE()) AS overdue,
               SUM(status = 'active' AND expire_on > CURDATE()
                   AND expire_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS upcoming
             FROM inward_items"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
    } catch (Exception $e) { /* pre-migration */ }
    return $out;
}

// Rows expiring today / already expired and still live in the ERP.
// $onlyUnalerted limits to rows not already reported today — used by the
// daily digest so a second run the same day is a no-op. The dashboard
// passes false: it always shows the true live state.
function inwDueRows(string $bucket, bool $onlyUnalerted = false, int $limit = 500): array {
    if (!inwHasTable()) return [];
    $cmp = $bucket === 'today' ? '=' : '<';
    $sql = "SELECT * FROM inward_items
            WHERE status = 'active' AND expire_on {$cmp} CURDATE()";
    if ($onlyUnalerted) $sql .= ' AND (last_alert_date IS NULL OR last_alert_date < CURDATE())';
    $sql .= ' ORDER BY expire_on ASC, item_code ASC LIMIT ' . (int)$limit;
    try {
        return getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function inwPendingRows(int $limit = 20): array {
    if (!inwHasTable()) return [];
    try {
        return getDb()->query(
            "SELECT * FROM inward_items WHERE status = 'pending'
             ORDER BY created_at DESC LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── Display helpers ──────────────────────────────────────
function inwStatusBadge(array $r): string {
    $st = (string)($r['status'] ?? '');
    if ($st === 'removed') return '<span class="badge badge-grey">Removed</span>';
    if ($st === 'pending') return '<span class="badge badge-amber">Pending ERP</span>';
    // active — split by expiry so the actionable ones stand out
    $exp   = (string)($r['expire_on'] ?? '');
    $today = date('Y-m-d');
    if ($exp !== '' && $exp <  $today) return '<span class="badge badge-red">Expired</span>';
    if ($exp !== '' && $exp === $today) return '<span class="badge badge-red">Expires today</span>';
    return '<span class="badge badge-green">Active</span>';
}

function inwFmtDate(?string $ymd): string {
    if (!$ymd) return '—';
    $t = strtotime($ymd);
    return $t ? date('d M Y', $t) : h($ymd);
}

function inwFmtMoney($v): string { return '₹' . number_format((float)$v, 2); }

// Signed day offset from today; negative = overdue.
function inwDaysLeft(string $ymd): int {
    $a = strtotime(date('Y-m-d'));
    $b = strtotime($ymd);
    if (!$b) return 0;
    return (int)round(($b - $a) / 86400);
}

// ═════════════════════════════════════════════════════════
//  POST handlers
// ═════════════════════════════════════════════════════════

// Shared writer for both entry paths (form + import). $rows carry already
// validated values. Returns [inserted, updated].
//
// Upsert rule: match on barcode among rows NOT yet removed. A match refreshes
// the descriptive fields in place and keeps status + validator history, so
// re-importing a corrected sheet never resurrects settled work or re-queues a
// barcode the validator has already put into the ERP.
function inwUpsertRows(array $rows, string $source): array {
    $db  = getDb();
    $me  = myCode();
    $ins = $db->prepare(
        'INSERT INTO inward_items
            (item_code, item_name, mrp, barcode, expire_on, status, source, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, NOW())'
    );
    // Re-uploading the same item code and barcode with a later date moves
    // the expiry on the existing row — no second row, and the row keeps its
    // status and validator history. mrp is written too, but the composed
    // barcode already encodes it, so a barcode match implies an mrp match
    // and the assignment is a no-op in practice.
    $upd = $db->prepare(
        'UPDATE inward_items SET item_name = ?, mrp = ?, expire_on = ? WHERE id = ?'
    );

    $inserted = 0; $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $live = inwFindLive($r['barcode']);
            if ($live) {
                // A blank Name keeps whatever is on file; a filled one
                // overwrites it (that is how a renamed product is corrected).
                $name = $r['item_name'] !== null && $r['item_name'] !== ''
                      ? $r['item_name']
                      : ($live['item_name'] ?? null);
                $upd->execute([$name, $r['mrp'], $r['expire_on'], (int)$live['id']]);
                $updated++;
            } else {
                $name = $r['item_name'] !== null && $r['item_name'] !== ''
                      ? $r['item_name']
                      : inwLookupName($r['item_code']);
                $ins->execute([$r['item_code'], $name, $r['mrp'], $r['barcode'],
                               $r['expire_on'], $source, $me]);
                $inserted++;
            }
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return [$inserted, $updated];
}

// ── POST: add rows from the entry form ───────────────────
function doInwardItemAdd(): void {
    $back = 'index.php?page=inward_item_new';
    if (!inwCanCreate())  { flash('error', 'Access denied.');   header('Location: index.php?page=inward_items'); exit; }
    if (!inwHasTable())   { flash('error', 'Inward barcode register is not enabled — run migration_2026_08_06_inward_items.sql.');
                            header('Location: index.php?page=inward_items'); exit; }

    $codes    = $_POST['item_code'] ?? [];
    $names    = $_POST['item_name'] ?? [];
    $mrps     = $_POST['mrp']       ?? [];
    $barcodes = $_POST['barcode']   ?? [];
    $expiries = $_POST['expire_on'] ?? [];
    if (!is_array($codes)) { flash('error', 'Nothing to save.'); header('Location: ' . $back); exit; }

    $rows = []; $errors = []; $seen = [];
    $n = min(count($codes), INW_MAX_FORM_ROWS);
    for ($i = 0; $i < $n; $i++) {
        $code = trim((string)($codes[$i] ?? ''));
        $bc   = trim((string)($barcodes[$i] ?? ''));
        $mrp  = trim((string)($mrps[$i] ?? ''));
        $exp  = trim((string)($expiries[$i] ?? ''));
        // Fully blank row — the repeater always renders at least one.
        if ($code === '' && $bc === '' && $mrp === '' && $exp === '') continue;

        $label   = 'Row ' . ($i + 1) . ': ';
        $vCode   = inwCleanCode($code);
        $vEan    = inwCleanEan($bc);
        $vMrp    = inwCleanMrp($mrp);
        $vExp    = inwParseFormDate($exp);

        if ($vCode === null) $errors[] = $label . 'item code is required.';
        if ($vEan  === null) $errors[] = $label . (str_contains($bc, INW_BARCODE_SEP) || str_contains($bc, '.')
            ? 'enter the barcode without the MRP (e.g. 7622202357039) — the MRP is joined automatically.'
            : 'barcode must be digits only (e.g. 7622202357039).');
        if ($vMrp  === null) $errors[] = $label . 'MRP must be a number.';
        if ($vExp  === null) $errors[] = $label . 'pick a valid expiry date.';
        if ($vCode === null || $vEan === null || $vMrp === null || $vExp === null) continue;

        // Same join as the importer — one rule for both entry paths.
        $vBar = inwComposeBarcode($vMrp, $vEan);

        if (isset($seen[$vBar])) { $errors[] = $label . 'barcode ' . $vBar . ' is repeated in this form.'; continue; }
        $seen[$vBar] = true;

        $live = inwFindLive($vBar);
        if ($live && (string)$live['item_code'] !== $vCode) {
            $errors[] = $label . 'barcode ' . $vBar . ' is already registered to item code ' . $live['item_code'] . '.';
            continue;
        }
        $rows[] = ['item_code' => $vCode, 'item_name' => trim((string)($names[$i] ?? '')),
                   'mrp' => $vMrp, 'barcode' => $vBar, 'expire_on' => $vExp];
    }

    if ($errors) { flash('error', implode(' ', array_slice($errors, 0, 10))); header('Location: ' . $back); exit; }
    if (!$rows)  { flash('error', 'Fill in at least one row.');               header('Location: ' . $back); exit; }

    try {
        [$ins, $upd] = inwUpsertRows($rows, 'form');
        flash('success', "Saved — {$ins} new, {$upd} updated.");
        header('Location: index.php?page=inward_items&view=1'); exit;
    } catch (Exception $e) {
        flash('error', 'Save failed: ' . $e->getMessage());
        header('Location: ' . $back); exit;
    }
}

// ── POST: CSV import ─────────────────────────────────────
// Two deliberate departures from doPriceListImport():
//  1. The whole file is validated before anything is written, and every
//     bad line is reported at once — aborting on the first error turns a
//     50-row sheet into a fix-one-rerun-repeat loop.
//  2. No "replace" mode. Truncating would destroy validator history, so
//     import is always an upsert.
function doInwardItemImport(): void {
    $back = 'index.php?page=inward_items&view=1';
    if (!inwCanCreate()) { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }
    if (!inwHasTable())  { flash('error', 'Inward barcode register is not enabled — run migration_2026_08_06_inward_items.sql.');
                           header('Location: ' . $back); exit; }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed. Pick a CSV file and try again.');
        header('Location: ' . $back); exit;
    }
    $file = $_FILES['csv'];
    if ($file['size'] > INW_CSV_MAX_BYTES) {
        flash('error', 'File too large (max ' . (INW_CSV_MAX_BYTES / 1024 / 1024) . ' MB).');
        header('Location: ' . $back); exit;
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        flash('error', 'Only .csv files are allowed.');
        header('Location: ' . $back); exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        flash('error', 'File does not look like a CSV (mime: ' . h($mime) . ').');
        header('Location: ' . $back); exit;
    }

    $fh = fopen($file['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read file.'); header('Location: ' . $back); exit; }

    // Strip the BOM Excel writes, else the first header cell won't match.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh, null, ',', '"', '');
    if (!$header) {
        fclose($fh);
        flash('error', 'CSV has no header row.');
        header('Location: ' . $back); exit;
    }

    // Map header cells → canonical keys via the alias table.
    $spec = inwCsvColumns();
    $idx  = [];
    foreach ($header as $pos => $cell) {
        $norm = inwNormalizeHeader($cell);
        if ($norm === '') continue;
        foreach ($spec as $key => $def) {
            if (isset($idx[$key])) continue;
            if ($norm === strtolower($def['label']) || in_array($norm, $def['aliases'], true)) {
                $idx[$key] = $pos;
                break;
            }
        }
    }
    $missing = [];
    foreach ($spec as $key => $def) {
        if ($def['required'] && !isset($idx[$key])) $missing[] = $def['label'];
    }
    if ($missing) {
        fclose($fh);
        flash('error', 'CSV missing columns: ' . h(implode(', ', $missing))
                     . '. Expected header: ' . h(inwCsvHeaderLine()));
        header('Location: ' . $back); exit;
    }

    $cell = function (array $r, string $key) use ($idx) {
        return isset($idx[$key]) ? ($r[$idx[$key]] ?? '') : '';
    };

    $rows = []; $errors = []; $seen = [];
    $line = 1;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue; // blank line

        $vCode = inwCleanCode($cell($r, 'item_code'));
        $vEan  = inwCleanEan($cell($r, 'barcode'));
        $vMrp  = inwCleanMrp($cell($r, 'mrp'));
        $vExp  = inwBuildDate($cell($r, 'exp_day'), $cell($r, 'exp_month'), $cell($r, 'exp_year'));
        $name  = trim((string)$cell($r, 'item_name'));

        if ($vCode === null) $errors[] = "Line {$line}: Code is required.";
        if ($vEan  === null) {
            $rawBar = trim((string)$cell($r, 'barcode'));
            // Point at the format explicitly — a sheet carrying the MRP in
            // the barcode fails here and the reason isn't obvious otherwise.
            $joined = str_contains($rawBar, INW_BARCODE_SEP) || str_contains($rawBar, '.');
            $errors[] = $joined
                ? "Line {$line}: BarCode \"{$rawBar}\" already contains the MRP — enter the barcode on its own, the MRP is joined automatically."
                : "Line {$line}: BarCode \"{$rawBar}\" is invalid — digits only.";
        }
        if ($vMrp  === null) $errors[] = "Line {$line}: MRP \"" . trim((string)$cell($r, 'mrp')) . '" is not a number.';
        if ($vExp  === null) $errors[] = "Line {$line}: expiry " . trim((string)$cell($r, 'exp_day')) . '/'
                                       . trim((string)$cell($r, 'exp_month')) . '/'
                                       . trim((string)$cell($r, 'exp_year')) . ' is not a valid date.';
        if ($vCode === null || $vEan === null || $vMrp === null || $vExp === null) continue;

        // MRP and barcode arrive separately; the stored value joins them.
        $vBar = inwComposeBarcode($vMrp, $vEan);

        if (isset($seen[$vBar])) {
            $errors[] = "Line {$line}: BarCode {$vBar} already appears on line {$seen[$vBar]}.";
            continue;
        }
        $seen[$vBar] = $line;

        // A barcode that already belongs to another item code is a data
        // error, not something to silently reassign.
        $live = inwFindLive($vBar);
        if ($live && (string)$live['item_code'] !== $vCode) {
            $errors[] = "Line {$line}: BarCode {$vBar} is already registered to item code {$live['item_code']}.";
            continue;
        }

        $rows[] = ['item_code' => $vCode, 'item_name' => $name, 'mrp' => $vMrp,
                   'barcode' => $vBar, 'expire_on' => $vExp];
    }
    fclose($fh);

    if ($errors) {
        $shown = array_slice($errors, 0, INW_MAX_ERRORS);
        $more  = count($errors) - count($shown);
        $msg   = 'Nothing was imported — fix these ' . count($errors) . ' problem(s): ' . implode(' ', $shown);
        if ($more > 0) $msg .= " (+{$more} more)";
        flash('error', $msg);
        header('Location: ' . $back); exit;
    }
    if (!$rows) {
        flash('error', 'CSV had no data rows.');
        header('Location: ' . $back); exit;
    }

    try {
        [$ins, $upd] = inwUpsertRows($rows, 'import');
        flash('success', "Imported — {$ins} new, {$upd} updated.");
    } catch (Exception $e) {
        flash('error', 'Import failed: ' . $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

// ── POST: validator marks the barcode created in the ERP ──
function doInwardErpUpdated(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $back = 'index.php?page=inward_item_detail&id=' . $id;
    if (!inwCanValidate()) { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }

    $row = inwGetItem($id);
    if (!$row)                        { flash('error', 'Record not found.');                         header('Location: index.php?page=inward_items&view=1'); exit; }
    if ($row['status'] !== 'pending') { flash('error', 'Only a pending barcode can be marked as updated in ERP.'); header('Location: ' . $back); exit; }

    $remarks = trim((string)($_POST['remarks'] ?? ''));
    try {
        getDb()->prepare(
            "UPDATE inward_items
             SET status = 'active', erp_updated_by = ?, erp_updated_at = NOW(), erp_update_remarks = ?
             WHERE id = ? AND status = 'pending'"
        )->execute([myCode(), ($remarks !== '' ? mb_substr($remarks, 0, 500) : null), $id]);
        flash('success', 'Barcode ' . h($row['barcode']) . ' marked as updated in ERP.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: ' . $back); exit;
}

// ── POST: validator confirms the barcode is out of the ERP ──
// This is the single write that clears both the dashboard tile and the
// daily digest, because "due for removal" is derived from status.
function doInwardConfirmRemoved(): void {
    $id   = (int)($_POST['id'] ?? 0);
    $back = 'index.php?page=inward_item_detail&id=' . $id;
    if (!inwCanValidate()) { flash('error', 'Access denied.'); header('Location: ' . $back); exit; }

    $row = inwGetItem($id);
    if (!$row)                       { flash('error', 'Record not found.');                     header('Location: index.php?page=inward_items&view=1'); exit; }
    if ($row['status'] !== 'active') { flash('error', 'Only an active barcode can be removed.'); header('Location: ' . $back); exit; }

    $remarks = trim((string)($_POST['remarks'] ?? ''));
    try {
        getDb()->prepare(
            "UPDATE inward_items
             SET status = 'removed', removed_by = ?, removed_at = NOW(), removal_remarks = ?
             WHERE id = ? AND status = 'active'"
        )->execute([myCode(), ($remarks !== '' ? mb_substr($remarks, 0, 500) : null), $id]);
        flash('success', 'Barcode ' . h($row['barcode']) . ' confirmed removed from ERP.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: ' . $back); exit;
}

// ── POST: delete (superadmin only) ───────────────────────
function doInwardItemDelete(): void {
    if (!isSuperadmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=inward_items&view=1'); exit; }
    $id = (int)($_POST['id'] ?? 0);
    try {
        getDb()->prepare('DELETE FROM inward_items WHERE id = ?')->execute([$id]);
        flash('success', 'Record deleted.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=inward_items&view=1'); exit;
}

// ── POST (AJAX): item-code autocomplete off the master price list ──
// Deliberately its own endpoint rather than reusing pv_search_items:
// that one is gated on txn_price_variation, which an inward-entry user
// need not hold.
function doInwardSearchItems(): void {
    header('Content-Type: application/json');
    if (!inwCanCreate()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
    $kw = trim((string)($_POST['kw'] ?? $_GET['kw'] ?? ''));
    if ($kw === '') { echo json_encode(['ok' => true, 'items' => []]); exit; }
    $like = '%' . $kw . '%';
    $out  = [];
    try {
        $st = getDb()->prepare(
            'SELECT item_code, item_name FROM price_list
             WHERE item_code LIKE ? OR item_name LIKE ?
             ORDER BY item_name LIMIT 20'
        );
        $st->execute([$like, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['item_code' => $r['item_code'], 'item_name' => $r['item_name']];
        }
    } catch (Exception $e) { /* price_list absent → empty suggestions */ }
    echo json_encode(['ok' => true, 'items' => $out]);
    exit;
}

// ═════════════════════════════════════════════════════════
//  CSV downloads
// ═════════════════════════════════════════════════════════

// Import template. Built from inwCsvColumns() so it can never describe a
// format the parser doesn't accept.
function doInwardSampleCsv(): void {
    if (!inwCanCreate()) { http_response_code(403); echo 'Access denied.'; return; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inward_items_sample.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it cleanly
    fputcsv($out, array_map(fn($c) => $c['label'], inwCsvColumns()), ',', '"', '');
    // BarCode is the plain barcode — the MRP is joined on automatically.
    // The two 704804 rows show the shape that matters: one barcode, two
    // live MRPs, becoming 53-7622202357039 and 55-7622202357039.
    fputcsv($out, ['700757', '10 Round Plate Classic (10Pc x 12PKT)', '10', '123456', '30', '9', '26'], ',', '"', '');
    fputcsv($out, ['704804', 'Cadbury - Bournville Cranberry 30 gm', '53', '7622202357039', '30', '9', '2026'], ',', '"', '');
    fputcsv($out, ['704804', 'Cadbury - Bournville Cranberry 30 gm', '55', '7622202357039', '30', '9', '2026'], ',', '"', '');
    fclose($out);
    exit;
}

// Export honours the on-screen filters. The first seven columns are exactly
// the import format, so an export can be corrected and fed straight back in;
// the status columns after them are ignored by the importer.
function doInwardItemsExport(): void {
    if (!inwCanEnter()) { http_response_code(403); echo 'Access denied.'; return; }

    $f = inwResolveFilter();
    $rows = [];
    if (inwHasTable()) {
        $flt = inwBuildWhere($f);
        try {
            $st = getDb()->prepare(
                'SELECT * FROM inward_items WHERE 1=1' . $flt['where']
                . ' ORDER BY expire_on ASC, item_code ASC, mrp ASC'
            );
            $st->execute($flt['params']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $rows = []; }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inward_items_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(
        array_map(fn($c) => $c['label'], inwCsvColumns()),
        ['Full Barcode', 'Status', 'Entered By', 'Entered At',
         'ERP Updated By', 'ERP Updated At', 'Removed By', 'Removed At']
    ), ',', '"', '');
    foreach ($rows as $r) {
        $t = strtotime((string)$r['expire_on']);
        fputcsv($out, [
            // BarCode is emitted plain, with the MRP prefix stripped back
            // off, so an exported sheet can be corrected and re-imported to
            // exactly the same stored value. The joined form follows in its
            // own column for reference; the importer ignores it.
            $r['item_code'], $r['item_name'] ?? '', $r['mrp'],
            inwBarcodeEan((string)$r['barcode'], $r['mrp']),
            $t ? date('d', $t) : '', $t ? date('n', $t) : '', $t ? date('Y', $t) : '',
            $r['barcode'],
            $r['status'], $r['created_by'], $r['created_at'],
            $r['erp_updated_by'] ?? '', $r['erp_updated_at'] ?? '',
            $r['removed_by'] ?? '', $r['removed_at'] ?? '',
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ═════════════════════════════════════════════════════════
//  Daily expiry email
// ═════════════════════════════════════════════════════════

// Same JSON shape the Settings page already writes for
// PriceVariationNotifyEmails: [{"email":"..."}] — a bare list of strings
// is accepted too.
function inwGetNotifyEmails(): array {
    $raw = getSetting('InwardBarcodeNotifyEmails', '');
    if (trim((string)$raw) === '') return [];
    $rows = json_decode((string)$raw, true);
    // Tolerate a plain comma-separated list, which is what people type
    // into a settings box when they haven't seen the JSON example.
    if (!is_array($rows)) $rows = preg_split('/[,;\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($rows as $r) {
        $e = is_array($r) ? trim((string)($r['email'] ?? '')) : trim((string)$r);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_values(array_unique($out));
}

function inwBuildDigestEmail(array $today, array $overdue): string {
    $base = rtrim((string)getSetting('AppBaseUrl', ''), '/');

    $table = function (array $rows, string $title, string $accent) use ($base): string {
        if (!$rows) return '';
        $h = "<h3 style='font:600 15px/1.4 Arial,sans-serif;color:{$accent};margin:22px 0 8px'>"
           . h($title) . ' (' . count($rows) . ")</h3>"
           . "<table cellpadding='0' cellspacing='0' style='border-collapse:collapse;width:100%;font:13px/1.5 Arial,sans-serif'>"
           . "<thead><tr style='background:#fafafa'>"
           . "<th style='padding:6px 8px;text-align:left;border-bottom:1px solid #ddd'>Code</th>"
           . "<th style='padding:6px 8px;text-align:left;border-bottom:1px solid #ddd'>Item</th>"
           . "<th style='padding:6px 8px;text-align:right;border-bottom:1px solid #ddd'>MRP</th>"
           . "<th style='padding:6px 8px;text-align:left;border-bottom:1px solid #ddd'>BarCode</th>"
           . "<th style='padding:6px 8px;text-align:left;border-bottom:1px solid #ddd'>Expiry</th>"
           . "<th style='padding:6px 8px;text-align:right;border-bottom:1px solid #ddd'>Overdue</th>"
           . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $days = inwDaysLeft((string)$r['expire_on']);
            $od   = $days < 0 ? (abs($days) . ' day' . (abs($days) === 1 ? '' : 's')) : '—';
            $bc   = h((string)$r['barcode']);
            if ($base !== '') {
                $bc = "<a href='" . h($base . '/index.php?page=inward_item_detail&id=' . (int)$r['id'])
                    . "' style='color:#1a8fe3;text-decoration:none'>" . $bc . '</a>';
            }
            $h .= '<tr>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee'>" . h((string)$r['item_code']) . '</td>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee'>" . h((string)($r['item_name'] ?? '—')) . '</td>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . inwFmtMoney($r['mrp']) . '</td>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee;font-family:monospace'>" . $bc . '</td>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee'>" . inwFmtDate((string)$r['expire_on']) . '</td>'
                . "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . h($od) . '</td>'
                . '</tr>';
        }
        return $h . '</tbody></table>';
    };

    return "<div style='font:14px/1.6 Arial,sans-serif;color:#222'>"
         . "<h2 style='font:600 18px/1.4 Arial,sans-serif;margin:0 0 6px'>Inward barcode expiry — "
         . h(date('d M Y')) . '</h2>'
         . "<p style='margin:0 0 4px;color:#555'>The barcodes below are still live in the ERP and need to be removed. "
         . 'Confirm each removal in Work Pulse and it will drop off this email automatically.</p>'
         . $table($today,   'Expiring today',                     '#c0392b')
         . $table($overdue, 'Expired earlier — still not removed', '#8e44ad')
         . "<p style='margin:22px 0 0;color:#888;font-size:12px'>Work Pulse · Price Variation › Inward Items</p>"
         . '</div>';
}

// Returns the number of barcodes reported. 0 means nothing to report, or
// no recipients configured — in the latter case nothing is stamped, so
// the rows stay queued for the next run once the setting is filled in.
function inwSendDailyExpiryDigest(): int {
    if (!inwHasTable()) return 0;

    $today   = inwDueRows('today',   true);
    $overdue = inwDueRows('overdue', true);
    $rows    = array_merge($today, $overdue);
    if (!$rows) return 0;

    $emails = inwGetNotifyEmails();
    if (!$emails) {
        error_log('[inward_items] ' . count($rows) . ' expiring barcode(s) but InwardBarcodeNotifyEmails is empty');
        return 0;
    }

    $subject = 'Barcode expiry — ' . count($rows) . ' to remove from ERP — ' . date('d M Y');
    $body    = inwBuildDigestEmail($today, $overdue);
    foreach ($emails as $e) sendSmtpEmailQuiet($e, $subject, $body);

    // Stamp only what actually went out, so a second run today is a no-op
    // while tomorrow's run picks the same overdue rows back up.
    try {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        getDb()->prepare("UPDATE inward_items SET last_alert_date = CURDATE() WHERE id IN ($ph)")
               ->execute($ids);
    } catch (Exception $e) {
        error_log('[inward_items] failed to stamp last_alert_date: ' . $e->getMessage());
    }
    return count($rows);
}

// Once-per-calendar-day fallback for installs without a server cron —
// same shape as ticketSchedLazyRun(). Claims the day BEFORE sending so two
// concurrent requests can't both fire the digest.
function inwExpiryLazyRun(): void {
    $dir    = __DIR__ . '/../uploads';
    $marker = $dir . '/inward_expiry_lastrun.txt';
    $today  = date('Y-m-d');
    $last   = @file_get_contents($marker);
    if ($last !== false && trim($last) === $today) return;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($marker, $today, LOCK_EX) === false) return; // can't claim → skip quietly
    try {
        inwSendDailyExpiryDigest();
    } catch (Exception $e) {
        error_log('[inward_items] lazy run failed: ' . $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════
//  Pages
// ═════════════════════════════════════════════════════════

function inwPageStyles(): void {
?>
<style>
.inw-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.inw-modal.active{display:flex}
.inw-modal-content{background:var(--surface);border-radius:10px;padding:16px;max-width:90vw;max-height:90vh;overflow-x:hidden;overflow-y:auto;position:relative;border:1px solid var(--border);box-sizing:border-box}
.inw-modal-close{position:absolute;top:8px;right:12px;font-size:22px;line-height:1;cursor:pointer;color:var(--muted)}
.inw-modal-title{margin:0 0 12px;font-size:15px;font-weight:600}
.inw-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border:1px solid var(--border);border-radius:999px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;white-space:nowrap}
.inw-chip:hover{border-color:var(--accent);color:var(--accent)}
.inw-chip.on{background:rgba(26,143,227,.14);border-color:rgba(26,143,227,.45);color:#9ed1f6}
.inw-chip b{font-weight:700;color:inherit}
.inw-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.inw-row-grid{display:grid;grid-template-columns:1.1fr 2fr .7fr 1.4fr 1fr 32px;gap:8px;align-items:end;margin-bottom:8px}
@media(max-width:900px){.inw-row-grid{grid-template-columns:1fr 1fr;}}
</style>
<?php
}

// ── Page: list ───────────────────────────────────────────
function pageInwardItems(): void {
    if (!inwCanEnter()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!inwHasTable()) { echo inwMigrationNotice(); return; }

    $f      = inwResolveFilter();
    $res    = inwGetList($f);
    $rows   = $res['rows'];
    $counts = inwCounts();
    $today  = date('Y-m-d');

    // Preserve the current filter on the chip links, swapping only "due".
    $chip = function (string $due, string $label, int $n) use ($f): string {
        $q = ['page' => 'inward_items', 'view' => 1, 'due' => $due, 'q' => $f['q'],
              'status' => $f['status'], 'from' => $f['from'], 'to' => $f['to']];
        $on = $f['due'] === $due ? ' on' : '';
        return '<a class="inw-chip' . $on . '" href="index.php?' . h(http_build_query($q)) . '">'
             . h($label) . ' <b>' . $n . '</b></a>';
    };

    inwPageStyles();
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Inward Items</h2>
    <span style="font-size:12px;color:var(--muted)">
        <?= (int)$res['total'] ?> matching
        <?php if ($res['pages'] > 1): ?> · page <?= (int)$res['page'] ?> of <?= (int)$res['pages'] ?><?php endif; ?>
    </span>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=export_inward_items&<?= h(http_build_query(['q'=>$f['q'],'status'=>$f['status'],'due'=>$f['due'],'from'=>$f['from'],'to'=>$f['to']])) ?>"
           class="btn btn-secondary">Export CSV</a>
        <?php if (inwCanCreate()): ?>
        <button type="button" class="btn btn-primary" onclick="inwOpenImport()">Import CSV</button>
        <a href="?page=inward_item_new" class="btn btn-success">+ New Inward Item</a>
        <?php endif; ?>
    </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <?= $chip('due',      'Due now',      $counts['today'] + $counts['overdue']) ?>
    <?= $chip('today',    'Expires today', $counts['today']) ?>
    <?= $chip('overdue',  'Overdue',      $counts['overdue']) ?>
    <?= $chip('upcoming', 'Next 30 days', $counts['upcoming']) ?>
    <?= $chip('',         'All',          $counts['pending'] + $counts['active'] + $counts['removed']) ?>
    <span class="inw-chip" style="cursor:default">Pending ERP <b><?= $counts['pending'] ?></b></span>
</div>

<div class="form-card" style="max-width:none;margin-bottom:14px">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="page" value="inward_items">
        <input type="hidden" name="view" value="1">
        <input type="hidden" name="due"  value="<?= h($f['due']) ?>">
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="q" value="<?= h($f['q']) ?>" class="form-control"
                   style="width:240px" placeholder="Item code, name or barcode">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control" style="width:150px">
                <option value="">Any</option>
                <?php foreach (['pending' => 'Pending ERP', 'active' => 'Active', 'removed' => 'Removed'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Expiry from</label>
            <input type="date" name="from" value="<?= h($f['from']) ?>" class="form-control" style="width:160px">
        </div>
        <div class="form-group">
            <label>Expiry to</label>
            <input type="date" name="to" value="<?= h($f['to']) ?>" class="form-control" style="width:160px">
        </div>
        <button type="submit" class="btn btn-primary">View</button>
        <a href="?page=inward_items&view=1&due=" class="btn btn-ghost">Reset</a>
    </form>
</div>

<?php if (inwCanCreate()): ?>
<div id="inwImportModal" class="inw-modal" onclick="inwCloseImport(event)">
    <div class="inw-modal-content" style="max-width:600px;width:92vw">
        <span class="inw-modal-close" onclick="inwCloseImport()">&times;</span>
        <h4 class="inw-modal-title">Import Inward Items (CSV)</h4>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="inw_import">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div style="background:rgba(26,143,227,.10);border:1px solid rgba(26,143,227,.35);border-radius:6px;padding:10px 12px;font-size:12px;line-height:1.6">
                    Put the <b>MRP</b> and the <b>plain barcode</b> in their own columns —
                    the system joins them with a <code><?= h(INW_BARCODE_SEP) ?></code>. The same item code may
                    appear on several rows, one per MRP, each keeping its own expiry:
                    <div style="margin-top:6px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;line-height:1.7">
                        53 + 7622202357039 &rarr; 53<?= h(INW_BARCODE_SEP) ?>7622202357039<br>
                        55 + 7622202357039 &rarr; 55<?= h(INW_BARCODE_SEP) ?>7622202357039<br>
                        <span style="opacity:.75">53.50 + 7622202357039 &rarr; 53.5<?= h(INW_BARCODE_SEP) ?>7622202357039</span>
                    </div>
                    <div style="margin-top:8px">
                        <a href="?page=inward_sample_csv" class="btn btn-sm btn-secondary">Download sample CSV</a>
                    </div>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">CSV file</label>
                    <input type="file" name="csv" accept=".csv,text/csv" required class="form-control" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:6px">
                        Header row required:
                        <code style="display:block;margin-top:3px;white-space:normal;word-break:break-word;line-height:1.5;background:rgba(255,255,255,.05);padding:4px 6px;border-radius:4px"><?= h(inwCsvHeaderLine()) ?></code>
                        <span style="opacity:.8;display:block;margin-top:5px">
                            BarCode is the plain barcode, without the MRP — the MRP column is joined onto it automatically.
                            Year takes 2 or 4 digits — <code>26</code> and <code>2026</code> both mean 2026.
                            Name is optional: leave it blank to keep the name already on file, or fill it in to update a renamed product.
                            Re-uploading the same item code and barcode with a new expiry updates that row's date — it does not add a second row.
                        </span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="inwCloseImport()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function inwOpenImport(){document.getElementById('inwImportModal').classList.add('active');}
function inwCloseImport(e){
    var m=document.getElementById('inwImportModal');
    if(!e||e.target===m||e.target.classList.contains('inw-modal-close')) m.classList.remove('active');
}
</script>
<?php endif; ?>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:90px">Code</th>
                <th>Item</th>
                <th style="width:90px;text-align:right">MRP</th>
                <th style="width:170px">BarCode</th>
                <th style="width:110px">Expiry</th>
                <th style="width:120px">Status</th>
                <th style="width:80px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">
                Nothing here. Try another filter, or use the chips above.
            </td></tr>
        <?php else: foreach ($rows as $r):
            $overdue = $r['status'] === 'active' && $r['expire_on'] < $today; ?>
            <tr>
                <td class="inw-code"><?= h((string)$r['item_code']) ?></td>
                <td><?= h((string)($r['item_name'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                <td style="text-align:right"><?= inwFmtMoney($r['mrp']) ?></td>
                <td class="inw-code"><?= h((string)$r['barcode']) ?></td>
                <td<?= $overdue ? ' style="color:var(--red);font-weight:600"' : '' ?>><?= h(inwFmtDate((string)$r['expire_on'])) ?></td>
                <td><?= inwStatusBadge($r) ?></td>
                <td><a href="?page=inward_item_detail&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary">Open</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($res['pages'] > 1):
    $qs = ['page' => 'inward_items', 'view' => 1, 'q' => $f['q'], 'status' => $f['status'],
           'due' => $f['due'], 'from' => $f['from'], 'to' => $f['to']]; ?>
<div style="display:flex;gap:8px;justify-content:center;margin-top:14px;align-items:center">
    <?php if ($res['page'] > 1): ?>
        <a class="btn btn-sm btn-secondary" href="index.php?<?= h(http_build_query($qs + ['p' => $res['page'] - 1])) ?>">Prev</a>
    <?php endif; ?>
    <span style="font-size:12px;color:var(--muted)">Page <?= (int)$res['page'] ?> of <?= (int)$res['pages'] ?></span>
    <?php if ($res['page'] < $res['pages']): ?>
        <a class="btn btn-sm btn-secondary" href="index.php?<?= h(http_build_query($qs + ['p' => $res['page'] + 1])) ?>">Next</a>
    <?php endif; ?>
</div>
<?php endif;
}

// ── Page: entry form ─────────────────────────────────────
function pageInwardItemNew(): void {
    if (!inwCanCreate()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!inwHasTable()) { echo inwMigrationNotice(); return; }
    inwPageStyles();
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">New Inward Item</h2>
    <div style="margin-left:auto"><a href="?page=inward_items&view=1" class="btn btn-secondary">Back to list</a></div>
</div>

<div class="form-card" style="max-width:none">
    <div style="font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:14px">
        One row per barcode. An item code can carry several barcodes at once — one per MRP —
        so add a row for each. Enter the <b>plain barcode</b>; the MRP is joined onto it with a
        <code><?= h(INW_BARCODE_SEP) ?></code> when saved, and the result is previewed under the field.
        Leave the name blank to pull it from the master price list.
    </div>
    <form method="POST" id="inwForm">
        <input type="hidden" name="action" value="inw_add">
        <div class="inw-row-grid" style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">
            <div>Item Code</div><div>Name</div><div>MRP</div><div>BarCode <span style="font-weight:400;text-transform:none;letter-spacing:0">(without MRP)</span></div><div>Expires On</div><div></div>
        </div>
        <div id="inwRows"></div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
            <button type="button" class="btn btn-secondary" onclick="inwAddRow()">+ Add row</button>
            <div style="margin-left:auto;display:flex;gap:8px">
                <a href="?page=inward_items&view=1" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </div>
    </form>
</div>

<datalist id="inwItemList"></datalist>
<script>
var INW_MAX_ROWS = <?= (int)INW_MAX_FORM_ROWS ?>;

function inwRowHtml(){
    return '<div class="inw-row-grid">'
      + '<div><input type="text" name="item_code[]" class="form-control" placeholder="704804" list="inwItemList" oninput="inwSuggest(this)" onchange="inwFillName(this)"></div>'
      + '<div><input type="text" name="item_name[]" class="form-control" placeholder="(auto from price list)"></div>'
      + '<div><input type="text" name="mrp[]" class="form-control" inputmode="decimal" placeholder="55" oninput="inwPreview(this)"></div>'
      + '<div><input type="text" name="barcode[]" class="form-control" inputmode="numeric" placeholder="7622202357039" oninput="inwPreview(this)">'
      +   '<div class="inw-preview" style="font-size:10px;color:var(--muted);margin-top:3px;min-height:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"></div></div>'
      + '<div><input type="date" name="expire_on[]" class="form-control"></div>'
      + '<div><button type="button" class="btn btn-sm btn-ghost" title="Remove row" onclick="inwDelRow(this)">&times;</button></div>'
      + '</div>';
}

// Show the value that will actually be stored, so the join isn't a
// surprise at save time. Mirrors inwMrpForBarcode(): a whole MRP drops its
// decimals, a fractional one keeps them minus trailing zeros.
var INW_SEP = <?= json_encode(INW_BARCODE_SEP) ?>;

function inwPreview(el){
    var row  = el.closest('.inw-row-grid');
    var mrp  = row.querySelector('input[name="mrp[]"]').value.trim().replace(/[₹, ]/g, '');
    var bc   = row.querySelector('input[name="barcode[]"]').value.trim();
    var out  = row.querySelector('.inw-preview');
    if (!out) return;
    if (bc === '' && mrp === '') { out.textContent = ''; return; }
    if (bc.indexOf(INW_SEP) !== -1 || bc.indexOf('.') !== -1) {
        out.textContent = 'Drop the MRP prefix — enter the barcode alone';
        out.style.color = 'var(--red)';
        return;
    }
    out.style.color = 'var(--muted)';
    if (bc === '' || mrp === '' || isNaN(parseFloat(mrp)) || !/^\d+$/.test(bc)) { out.textContent = ''; return; }
    var n = parseFloat(mrp);
    var pfx = Math.abs(n - Math.round(n)) < 0.005
            ? String(Math.round(n))
            : String(parseFloat(n.toFixed(2)));
    out.textContent = '→ ' + pfx + INW_SEP + bc;
}
function inwAddRow(){
    var box = document.getElementById('inwRows');
    if (box.children.length >= INW_MAX_ROWS) { alert('Up to ' + INW_MAX_ROWS + ' rows per save.'); return; }
    box.insertAdjacentHTML('beforeend', inwRowHtml());
}
function inwDelRow(btn){
    var box = document.getElementById('inwRows');
    var row = btn.closest('.inw-row-grid');
    if (box.children.length <= 1) { row.querySelectorAll('input').forEach(function(i){ i.value=''; }); return; }
    row.remove();
}

// Item-code autocomplete off the master price list. Cached by keyword so
// typing a long code doesn't fire a request per character.
var inwCache = {}, inwTimer = null;
function inwSuggest(input){
    var kw = input.value.trim();
    if (kw.length < 2) return;
    clearTimeout(inwTimer);
    inwTimer = setTimeout(function(){
        if (inwCache[kw]) { inwRenderList(inwCache[kw]); return; }
        var fd = new FormData(); fd.append('action','inw_search_items'); fd.append('kw', kw);
        fetch('index.php', {method:'POST', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(d){ if(d && d.ok){ inwCache[kw] = d.items || []; inwRenderList(inwCache[kw]); } })
          .catch(function(){});
    }, 220);
}
function inwRenderList(items){
    var dl = document.getElementById('inwItemList');
    dl.innerHTML = items.map(function(i){
        return '<option value="' + i.item_code + '">' + (i.item_name || '') + '</option>';
    }).join('');
}
// Fill the blank name box from whatever the datalist knows about the code.
function inwFillName(codeInput){
    var row  = codeInput.closest('.inw-row-grid');
    var name = row.querySelector('input[name="item_name[]"]');
    if (!name || name.value.trim() !== '') return;
    var opt = document.querySelector('#inwItemList option[value="' + CSS.escape(codeInput.value.trim()) + '"]');
    if (opt) name.value = opt.textContent;
}

inwAddRow();
</script>
<?php
}

// ── Page: detail + validator actions ─────────────────────
function pageInwardItemDetail(): void {
    if (!inwCanEnter()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    if (!inwHasTable()) { echo inwMigrationNotice(); return; }

    $r = inwGetItem((int)($_GET['id'] ?? 0));
    if (!$r) { echo '<div class="alert alert-error">Record not found.</div>'; return; }

    $days   = inwDaysLeft((string)$r['expire_on']);
    $isDue  = $r['status'] === 'active' && $days <= 0;
    inwPageStyles();
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Inward Item #<?= (int)$r['id'] ?></h2>
    <?= inwStatusBadge($r) ?>
    <div style="margin-left:auto"><a href="?page=inward_items&view=1" class="btn btn-secondary">Back to list</a></div>
</div>

<?php if ($isDue): ?>
<div class="alert alert-error" style="margin-bottom:14px">
    <b>Remove this barcode from the ERP.</b>
    <?= $days === 0 ? 'It expires today.' : 'It expired ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago.' ?>
    <?= inwCanValidate() ? 'Confirm removal below to clear it from the dashboard and the daily email.'
                         : 'A validator needs to confirm the removal.' ?>
</div>
<?php endif; ?>

<div class="form-card" style="max-width:none;margin-bottom:14px">
    <div class="form-section-title">Barcode</div>
    <div class="table-wrap" data-stack>
        <table class="table">
            <tbody>
                <tr><th style="width:190px">Item Code</th><td class="inw-code"><?= h((string)$r['item_code']) ?></td></tr>
                <tr><th>Item Name</th><td><?= h((string)($r['item_name'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td></tr>
                <tr><th>MRP</th><td><?= inwFmtMoney($r['mrp']) ?></td></tr>
                <tr><th>BarCode</th><td class="inw-code"><?= h((string)$r['barcode']) ?></td></tr>
                <tr><th>Expires On</th><td><?= h(inwFmtDate((string)$r['expire_on'])) ?>
                    <span style="color:var(--muted);font-size:12px">
                    <?php if ($r['status'] === 'removed'): ?>
                        · removed
                    <?php elseif ($days > 0): ?>
                        · <?= (int)$days ?> day<?= $days === 1 ? '' : 's' ?> left
                    <?php elseif ($days === 0): ?>
                        · today
                    <?php else: ?>
                        · <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> overdue
                    <?php endif; ?>
                    </span></td></tr>
                <tr><th>Entered</th><td><?= h((string)$r['created_at']) ?> by <?= h((string)$r['created_by']) ?>
                    <span style="color:var(--muted);font-size:12px">· via <?= h((string)$r['source']) ?></span></td></tr>
                <tr><th>ERP Updated</th><td>
                    <?php if ($r['erp_updated_at']): ?>
                        <?= h((string)$r['erp_updated_at']) ?> by <?= h((string)$r['erp_updated_by']) ?>
                        <?php if ($r['erp_update_remarks']): ?><div style="color:var(--muted);font-size:12px;margin-top:3px"><?= h((string)$r['erp_update_remarks']) ?></div><?php endif; ?>
                    <?php else: ?><span class="text-muted">Not yet</span><?php endif; ?>
                </td></tr>
                <tr><th>Removed From ERP</th><td>
                    <?php if ($r['removed_at']): ?>
                        <?= h((string)$r['removed_at']) ?> by <?= h((string)$r['removed_by']) ?>
                        <?php if ($r['removal_remarks']): ?><div style="color:var(--muted);font-size:12px;margin-top:3px"><?= h((string)$r['removal_remarks']) ?></div><?php endif; ?>
                    <?php else: ?><span class="text-muted">Not yet</span><?php endif; ?>
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php if (inwCanValidate() && $r['status'] === 'pending'): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--green)">
    <div class="form-section-title">Validator · create this barcode in the ERP</div>
    <form method="POST" onsubmit="return confirm('Confirm that barcode <?= h((string)$r['barcode']) ?> has been created in the ERP?');">
        <input type="hidden" name="action" value="inw_erp_updated">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="form-group">
            <label>Remarks (optional)</label>
            <input type="text" name="remarks" class="form-control" maxlength="500" placeholder="e.g. created in ERP, MRP verified">
        </div>
        <button type="submit" class="btn btn-success">Mark as updated in ERP</button>
    </form>
</div>
<?php endif; ?>

<?php if (inwCanValidate() && $r['status'] === 'active'): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--red)">
    <div class="form-section-title">Validator · confirm removal from the ERP</div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:10px">
        Confirming clears this barcode from the dashboard and stops it appearing in the daily expiry email.
    </div>
    <form method="POST" onsubmit="return confirm('Confirm that barcode <?= h((string)$r['barcode']) ?> has been removed from the ERP?');">
        <input type="hidden" name="action" value="inw_confirm_removed">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="form-group">
            <label>Remarks (optional)</label>
            <input type="text" name="remarks" class="form-control" maxlength="500" placeholder="e.g. deleted from ERP item master">
        </div>
        <button type="submit" class="btn btn-danger-solid">Confirm removed from ERP</button>
    </form>
</div>
<?php endif; ?>

<?php if (isSuperadmin()): ?>
<div class="form-card" style="max-width:none;border-left:3px solid var(--red)">
    <div class="form-section-title" style="color:var(--red)">Delete</div>
    <form method="POST" onsubmit="return confirm('Delete this inward record permanently?');">
        <input type="hidden" name="action" value="inw_delete">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn btn-danger-solid">Delete record</button>
    </form>
</div>
<?php endif;
}
