<?php
// =========================================================
// Price Variation — aggregator (Swiggy/Zomato) variance reporting
//
// Manager submits an order: partner + N items + the aggregator's
// reported subtotal/discount/tax/net. The system compares the net
// received against what BOTH 3x and 4x catalog slots would expect,
// so admin can tell which slot the aggregator is billing on and
// whether basic price / tax is mis-configured upstream.
//
// Roles:
//   txn_price_variation        — store manager: submit + view own store
//   txn_price_variation_admin  — admin: import/export master, approve
//                                /reject, view all stores
// =========================================================

// ── Constants ────────────────────────────────────────────
define('PV_CSV_MAX_BYTES',  5 * 1024 * 1024); // 5 MB
define('PV_ATT_UPLOAD_DIR', __DIR__ . '/../uploads/price_variations/');
define('PV_ATT_MAX_BYTES',  5 * 1024 * 1024); // 5 MB per file
define('PV_ATT_MAX_COUNT',  6);               // up to 6 images per submission
define('PV_ATT_ALLOWED_EXT', ['jpg','jpeg','png','gif','webp','heic','heif']);
define('PV_ATT_ALLOWED_MIME', ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif']);

// Month bucket (e.g. "2026-05") for any datetime string. Stops the
// price_variations/ folder from sprawling — listings stay quick and the
// directory is human-skimmable. Anchored on the variation's submitted_at
// at read time, and on the request's current month at write time (the
// two match because both are NOW() in the same request).
function pvMonthBucket(string $when): string {
    $ts = strtotime($when);
    return date('Y-m', $ts ?: time());
}

// Resolve the on-disk path of a price-variation attachment. New uploads
// always go into the month-bucketed path; reads prefer the bucketed
// path but fall back to the legacy flat layout for files written before
// this change so nothing 404s during the migration.
function pvAttachmentPath(string $when, string $stored, bool $forWrite = false): string {
    $bucketed = PV_ATT_UPLOAD_DIR . pvMonthBucket($when) . '/' . $stored;
    if ($forWrite) return $bucketed;
    if (is_file($bucketed)) return $bucketed;
    $flat = PV_ATT_UPLOAD_DIR . $stored;
    return is_file($flat) ? $flat : $bucketed;
}

// ── Permission helpers ───────────────────────────────────
// pvCanSubmit gates VIEW access (list, detail, attachments) — admins have it
// too so they can browse what was submitted before deciding.
// pvCanCreate gates the actual new-variation form + submit handler — only
// users explicitly granted txn_price_variation may submit. Admins do NOT
// auto-inherit this; they must be granted both flags if they should also
// submit on behalf of a store.
function pvCanSubmit(): bool {
    return isSuperadmin() || hasTxn('price_variation') || hasTxn('price_variation_admin');
}
function pvCanCreate(): bool {
    return isSuperadmin() || hasTxn('price_variation');
}
function pvCanAdmin(): bool {
    return isSuperadmin() || hasTxn('price_variation_admin');
}
// POC who confirms a submitted variation. Admins (and superadmin) can
// also confirm so a backlog never blocks on a POC being unavailable.
function pvCanConfirm(): bool {
    return isSuperadmin() || hasTxn('price_variation_confirm') || hasTxn('price_variation_admin');
}
// Edit gate — the original submitter OR any other store-staff at the
// same location (employees.location_id === variation.location_id) with
// txn_price_variation may edit, as long as the row is still
// pre-decision (pending or confirmed). Superadmin bypasses location.
// Editing a 'confirmed' row reverts it to 'pending' and clears the POC's
// confirmation metadata, forcing a fresh re-confirmation.
function pvCanEdit(array $row): bool {
    if (!in_array($row['status'] ?? '', ['pending', 'confirmed'], true)) return false;
    if (isSuperadmin()) return true;
    if (!pvCanCreate()) return false;
    if ((string)($row['submitted_by'] ?? '') === (string)myCode()) return true;
    // Same-store fallback: another manager at the variation's location can
    // edit (covering for absent staff, shift handover). Guard against the
    // "no claimed location" case so users with myLocationId()=0 don't
    // match orphan rows that also have location_id=0.
    $my  = (int)myLocationId();
    $loc = (int)($row['location_id'] ?? 0);
    return $my > 0 && $my === $loc;
}
// Decision-remarks edit gate — the approver who decided the variation
// may amend their own remark text (after-the-fact typo / note). Status,
// timestamp, and decided_by stay frozen — only the remark string moves.
// Superadmin can edit any decision's remarks.
function pvCanEditDecisionRemarks(array $row): bool {
    if (!in_array($row['status'] ?? '', ['approved', 'rejected'], true)) return false;
    if (isSuperadmin()) return true;
    if (!pvCanAdmin()) return false;
    return (string)($row['decided_by'] ?? '') === (string)myCode();
}

// POC-remarks edit gate — mirrors the decision-remark edit but for the
// POC confirmation note. The POC who confirmed the row may amend their
// own remark, and superadmin/admin can fix any. Only available once the
// row has reached a state where confirm_remarks exist (i.e. POC has
// actually acted). Schema must have the confirm columns.
function pvCanEditConfirmRemarks(array $row): bool {
    if (!pvHasConfirmCols()) return false;
    if (!in_array($row['status'] ?? '', ['confirmed', 'approved', 'rejected'], true)) return false;
    if (empty($row['confirmed_by'])) return false;
    if (isSuperadmin() || pvCanAdmin()) return true;
    if (!pvCanConfirm()) return false;
    return (string)($row['confirmed_by'] ?? '') === (string)myCode();
}
// Cross-location visibility: admins always see every store, and POC
// confirmers also need to (their job is reviewing variations across
// the chain — scoping their list to a single self-claim location
// hides everything they're meant to act on).
function pvCanSeeAll(): bool {
    return pvCanAdmin() || pvCanConfirm();
}
// Attachment uploads are open to every participant at every stage:
// submitter, same-store staff (covered by pvCanCreate), POC, admin,
// superadmin. Used by the "+ Add attachment" form on the detail page.
function pvCanAddAttachment(): bool {
    return isSuperadmin()
        || hasTxn('price_variation')
        || hasTxn('price_variation_admin')
        || hasTxn('price_variation_confirm');
}
// Schema-detection — flips to true once the 2026-05-08 migration runs.
// Until then the page falls back to the legacy direct pending → approved
// flow so existing installs keep working.
function pvHasConfirmCols(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT confirmed_by FROM price_variations LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// ── Data fetchers ────────────────────────────────────────
// All three price_list queries share the same column list. Centralising
// it here keeps the 3x/4x/5x and tax columns aligned across read paths.
// 5x is included only after migration_2026_05_18_pv_5x has run so
// pre-migration installs don't blow up with an unknown-column error.
function pvPriceListColumns(): string {
    $base = 'id, item_code, item_name, swiggy_name, zomato_name,
             online_3x_price, online_4x_price';
    if (pvHas5xCol()) $base .= ', online_5x_price';
    if (pvHas6xCol()) $base .= ', online_6x_price';
    return $base . ', tax_pct';
}

// Fetch one price_list row by id (for the inline-edit handler).
function pvGetPriceListItem(int $id): ?array {
    if ($id < 1) return null;
    try {
        $st = getDb()->prepare(
            'SELECT ' . pvPriceListColumns() . ' FROM price_list WHERE id = ?'
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

function pvGetPriceList(): array {
    try {
        return getDb()->query(
            'SELECT ' . pvPriceListColumns() . ', created_at
             FROM price_list
             ORDER BY item_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function pvSearchPriceList(string $kw, int $limit = 20): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    $like = '%' . $kw . '%';
    try {
        $st = getDb()->prepare(
            'SELECT ' . pvPriceListColumns() . '
             FROM price_list
             WHERE item_code LIKE ?
                OR item_name LIKE ?
                OR swiggy_name LIKE ?
                OR zomato_name LIKE ?
             ORDER BY item_name
             LIMIT ' . (int)$limit
        );
        $st->execute([$like, $like, $like, $like]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function pvGetVariation(int $id): ?array {
    $extra5x = pvHas5xCol() ? ', expected_5x_amount, variance_5x, variance_5x_pct' : '';
    $extra6x = pvHas6xCol() ? ', expected_6x_amount, variance_6x, variance_6x_pct' : '';
    try {
        $st = getDb()->prepare(
            'SELECT id, location_id, location_name, partner, order_id, order_date,
                    bill_subtotal, discount_amount, taxes, net_received,
                    expected_3x_amount, expected_4x_amount,
                    variance_3x, variance_4x, variance_3x_pct, variance_4x_pct,
                    remarks, status, submitted_by, submitted_at,
                    decided_by, decided_at, decision_remarks,
                    confirmed_by, confirmed_at, confirm_remarks
                    ' . $extra5x . $extra6x . '
             FROM price_variations WHERE id = ?'
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { return null; }
}

function pvGetVariationItems(int $varId): array {
    $extra5x = pvHas5xCol() ? ', online_5x_price, expected_5x' : '';
    $extra6x = pvHas6xCol() ? ', online_6x_price, expected_6x' : '';
    try {
        $st = getDb()->prepare(
            'SELECT id, variation_id, price_list_id, item_code, item_name,
                    online_3x_price, online_4x_price, tax_pct,
                    quantity, partner_rate, expected_3x, expected_4x
                    ' . $extra5x . $extra6x . '
             FROM price_variation_items WHERE variation_id = ? ORDER BY id'
        );
        $st->execute([$varId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Did migration_2026_05_06_pv_partner_rate.sql run? Gates the per-line
// "rate as shown on the partner's bill" capture. Without it the form
// still submits, the rate just isn't persisted.
function pvHasPartnerRateCol(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT partner_rate FROM price_variation_items LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// Did migration_2026_05_18_pv_5x.sql run? Gates the 5x slot price + its
// variation-line snapshot (online_5x_price / expected_5x). Pre-migration
// installs continue to show only 3x and 4x slots; nothing breaks.
function pvHas5xCol(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT online_5x_price FROM price_list LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// Did migration_2026_05_19_pv_6x.sql run? Same shape as pvHas5xCol —
// gates the parallel 6x columns (online_6x_price / expected_6x /
// expected_6x_amount / variance_6x / variance_6x_pct).
function pvHas6xCol(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        getDb()->query('SELECT online_6x_price FROM price_list LIMIT 0')->fetch();
        $cached = true;
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

// ── Slot activity flag ──────────────────────────────────
// Admins flip slots on/off via the Slot Activity panel on the Master
// Price List page. The flag controls DISPLAY only — the DB always
// keeps every slot's data, and the Master Price List ignores the flag
// (so admins can still pre-fill prices for the next transition).
// Stored as a JSON array in system_settings.PriceSlotsActive — same
// shape as PriceVariationNotifyEmails (pvGetNotifyEmails).
const PV_ALL_SLOTS = ['3x', '4x', '5x', '6x'];

function pvActiveSlots(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw = function_exists('getSetting') ? (string)getSetting('PriceSlotsActive', '') : '';
    $parsed = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($parsed) || !$parsed) {
        // Missing / empty / malformed → show every slot. Keeps the
        // pre-deployment behaviour identical until the admin opts in.
        $cached = PV_ALL_SLOTS;
        return $cached;
    }
    $cached = array_values(array_intersect(PV_ALL_SLOTS, $parsed));
    if (!$cached) $cached = PV_ALL_SLOTS;
    return $cached;
}

// Invalidate the static cache after a write — the next read picks up
// the new setting value. Called from doSaveSlotActivity.
function pvActiveSlotsResetCache(): void {
    // Closures can't re-bind a function-static, so the cheapest reset
    // is via a sentinel re-read: we just call pvActiveSlots() with the
    // cache cleared via a known re-init pattern. Simpler: do nothing
    // here, accept one-request staleness (the redirect after save
    // starts a fresh request anyway, which re-initialises the static).
}

function pvIsSlotActive(string $slot): bool {
    return in_array($slot, pvActiveSlots(), true);
}

// Should this slot appear in the manager-facing variation flows?
// Combines column-exists (3x/4x always; 5x/6x post-migration) with
// the admin's active-flag preference.
function pvShowSlot(string $slot): bool {
    if (!pvIsSlotActive($slot)) return false;
    if ($slot === '5x') return pvHas5xCol();
    if ($slot === '6x') return pvHas6xCol();
    // 3x / 4x always exist.
    return in_array($slot, ['3x', '4x'], true);
}

// 15 rows per page on the variations list — keeps the table within one
// screen on mid-resolution monitors and avoids the old 500-row dump.
const PV_PAGE_SIZE = 15;

// Build the WHERE fragment + parameter list shared by the count and
// page queries. Returns ['where' => "AND ...", 'params' => [...]].
// Status filter "pending" is treated as a UNION of pending+confirmed
// rows (the new POC step is conceptually still "pre-approval"); other
// status values match exactly.
function pvBuildVariationsFilter(array $f): array {
    $where = '';
    $p     = [];
    // Admins + POC confirmers see every store; everyone else is scoped
    // to their self-claim location only.
    if (!pvCanSeeAll()) {
        $where .= ' AND v.location_id = ?';
        $p[] = myLocationId();
    } elseif (!empty($f['location_id'])) {
        $where .= ' AND v.location_id = ?';
        $p[] = (int)$f['location_id'];
    }
    if (!empty($f['partner']) && in_array($f['partner'], ['swiggy','zomato'], true)) {
        $where .= ' AND v.partner = ?';
        $p[] = $f['partner'];
    }
    // Status filter is now an array (multi-select). Empty array → no rows.
    if (isset($f['status']) && is_array($f['status'])) {
        $valid = pvHasConfirmCols()
            ? ['pending','confirmed','approved','rejected']
            : ['pending','approved','rejected'];
        $clean = array_values(array_intersect($valid, $f['status']));
        if ($clean) {
            $ph = implode(',', array_fill(0, count($clean), '?'));
            $where .= " AND v.status IN ($ph)";
            foreach ($clean as $s) $p[] = $s;
        } else {
            $where .= ' AND 1=0';
        }
    }
    if (!empty($f['order_id'])) {
        $where .= ' AND v.order_id LIKE ?';
        $p[] = '%' . $f['order_id'] . '%';
    }
    if (!empty($f['from_date'])) {
        $where .= ' AND v.submitted_at >= ?';
        $p[] = $f['from_date'] . ' 00:00:00';
    }
    if (!empty($f['to_date'])) {
        $where .= ' AND v.submitted_at <= ?';
        $p[] = $f['to_date'] . ' 23:59:59';
    }
    return ['where' => $where, 'params' => $p];
}

// Resolve the variations-list filter from $_GET. Shared by the list page
// and the CSV export so both honour the EXACT same selected criteria
// (store, partner, status multi-select, order id, date range). Defaults
// match the list page: current month-to-date window, in-flight queue
// (Pending + Confirmed) when no explicit status is in the URL.
function pvResolveListFilter(): array {
    $fromDate = trim($_GET['from_date'] ?? '');
    $toDate   = trim($_GET['to_date']   ?? '');
    if ($fromDate === '') $fromDate = date('Y-m-01');
    if ($toDate   === '') $toDate   = date('Y-m-d');

    $validStatuses = pvHasConfirmCols()
        ? ['pending','confirmed','approved','rejected']
        : ['pending','approved','rejected'];
    if (isset($_GET['status'])) {
        $raw = is_array($_GET['status']) ? $_GET['status'] : [$_GET['status']];
        $statusFilter = array_values(array_intersect($validStatuses, $raw));
    } else {
        $statusFilter = pvHasConfirmCols() ? ['pending','confirmed'] : ['pending'];
    }

    return [
        'location_id' => $_GET['location_id'] ?? '',
        'partner'     => $_GET['partner']     ?? '',
        'status'      => $statusFilter,
        'order_id'    => $_GET['order_id']    ?? '',
        'from_date'   => $fromDate,
        'to_date'     => $toDate,
        'page'        => max(1, (int)($_GET['p'] ?? 1)),
    ];
}

// Paginated list. Returns
//   ['rows' => [...], 'total' => N, 'page' => P, 'per_page' => 15, 'pages' => ceil(N/15)]
// $f['page'] is 1-based; out-of-range values clamp to the valid window.
function pvGetVariations(array $f): array {
    $perPage = PV_PAGE_SIZE;
    $page    = max(1, (int)($f['page'] ?? 1));

    $flt    = pvBuildVariationsFilter($f);
    $base   = ' FROM price_variations v WHERE 1=1' . $flt['where'];

    try {
        $cstmt = getDb()->prepare('SELECT COUNT(*)' . $base);
        $cstmt->execute($flt['params']);
        $total = (int)$cstmt->fetchColumn();
    } catch (Exception $e) { $total = 0; }

    $pages  = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    try {
        $sql = 'SELECT v.*,
                       (SELECT COUNT(*) FROM price_variation_items i WHERE i.variation_id = v.id) AS items_count'
             . $base
             . ' ORDER BY v.submitted_at DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $st = getDb()->prepare($sql);
        $st->execute($flt['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $pages,
    ];
}

// ── Computation helpers ──────────────────────────────────
// Per-line expected (incl tax). Prices in price_list are stored INCLUSIVE
// of tax — that's the rate the customer sees on the aggregator app. The
// basic rate (excl tax) is derived for display as price / (1 + tax/100).
// $taxPct is kept in the signature for clarity but is not needed here.
function pvComputeExpectedLine(float $price, int $qty, float $taxPct): float {
    return round($price * $qty, 2);
}

// Variance % expressed as a fraction of the expected total.
//   net 311.85 vs expected 504.00 → −38.13%  (aggregator paid 38% less)
function pvComputeVariancePct(float $expected, float $netReceived): float {
    if ($expected <= 0) return 0.0;
    return round((($netReceived - $expected) / $expected) * 100.0, 2);
}

// ── Display helpers ──────────────────────────────────────
function pvStatusBadge(string $status): string {
    return match ($status) {
        'pending'   => '<span class="badge badge-yellow">Pending</span>',
        'confirmed' => '<span class="badge badge-blue">Confirmed</span>',
        'approved'  => '<span class="badge badge-green">Approved</span>',
        'rejected'  => '<span class="badge badge-red">Rejected</span>',
        default     => '<span class="badge badge-grey">' . h($status) . '</span>',
    };
}
function pvFmtMoney(float $v): string { return '₹' . number_format($v, 2); }
function pvFmtPct(float $v): string {
    $sign = $v > 0 ? '+' : ($v < 0 ? '' : '');
    return $sign . number_format($v, 2) . '%';
}

// ── POST: Save Slot Activity (admin) ─────────────────────
// Updates the PriceSlotsActive setting from the checkbox panel on the
// Master Price List page. Validates against the allowlist so an
// attacker can't sneak arbitrary strings into the setting.
function doSaveSlotActivity(): void {
    if (!pvCanAdmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=price_list'); exit;
    }
    $picked = (array)($_POST['slots'] ?? []);
    $clean  = array_values(array_intersect(PV_ALL_SLOTS, $picked));
    // Empty selection = "show all" by default-on convention. We still
    // record an explicit empty array so the admin's intent ("hide
    // everything") survives the round-trip — pvActiveSlots() falls
    // back to "all" only when the value is missing/malformed, not
    // when it's a deliberate empty list.
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
    try {
        $db = getDb();
        $upd = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'PriceSlotsActive'");
        $upd->execute([$json]);
        if ($upd->rowCount() === 0) {
            // Setting row didn't exist yet (migration not run) — insert it.
            $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, description)
                 VALUES ('PriceSlotsActive', ?, 'JSON array of price-slot keys currently shown in variation forms (3x|4x|5x|6x).')"
            )->execute([$json]);
        }
        flash('success', $clean
            ? 'Slot Activity saved. Active slots: ' . implode(', ', $clean) . '.'
            : 'Slot Activity saved. No slots active — variation forms will hide all slot columns.'
        );
    } catch (Exception $e) {
        flash('error', 'Save failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_list'); exit;
}

// ── POST: Price list import ──────────────────────────────
function doPriceListImport(): void {
    if (!pvCanAdmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=price_list'); exit; }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed. Pick a CSV file and try again.');
        header('Location: index.php?page=price_list'); exit;
    }
    $file = $_FILES['csv'];
    if ($file['size'] > PV_CSV_MAX_BYTES) {
        flash('error', 'File too large (max ' . (PV_CSV_MAX_BYTES / 1024 / 1024) . ' MB).');
        header('Location: index.php?page=price_list'); exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash('error', 'Only .csv files are allowed.');
        header('Location: index.php?page=price_list'); exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        flash('error', 'File does not look like a CSV (mime: ' . h($mime) . ').');
        header('Location: index.php?page=price_list'); exit;
    }

    // Import mode — 'replace' wipes the entire table then inserts every
    // CSV row; 'update' upserts so existing rows are updated in place and
    // new item_codes are appended. Default keeps the historical
    // truncate-and-reload behaviour for back-compat with any tooling.
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'replace')));
    if (!in_array($mode, ['replace', 'update'], true)) $mode = 'replace';

    $fh = fopen($file['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read file.'); header('Location: index.php?page=price_list'); exit; }

    // Strip BOM if present so first header column matches.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = fgetcsv($fh, null, ',', '"', '');
    if (!$header) {
        fclose($fh);
        flash('error', 'CSV has no header row.');
        header('Location: index.php?page=price_list'); exit;
    }
    // online_5x_price and online_6x_price are OPTIONAL — keeps pre-5x/6x
    // CSVs (e.g. old exports or partner-supplied lists) importing
    // without modification. When the schema supports a column and the
    // column is in the CSV, we capture it; otherwise it defaults to 0.
    $expected = ['item_code','item_name','swiggy_name','zomato_name','online_3x_price','online_4x_price','tax_pct'];
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $missing = array_diff($expected, $header);
    if ($missing) {
        fclose($fh);
        flash('error', 'CSV missing columns: ' . implode(', ', $missing));
        header('Location: index.php?page=price_list'); exit;
    }
    $idx     = array_flip($header);
    $has5x   = pvHas5xCol();
    $has6x   = pvHas6xCol();
    $hasCol5 = $has5x && isset($idx['online_5x_price']);
    $hasCol6 = $has6x && isset($idx['online_6x_price']);

    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue; // skip blank lines
        $code = trim((string)($r[$idx['item_code']] ?? ''));
        $name = trim((string)($r[$idx['item_name']] ?? ''));
        if ($code === '' || $name === '') {
            fclose($fh);
            flash('error', "Line {$line}: item_code and item_name are required.");
            header('Location: index.php?page=price_list'); exit;
        }
        $rows[] = [
            'item_code'       => $code,
            'item_name'       => $name,
            'swiggy_name'     => trim((string)($r[$idx['swiggy_name']] ?? '')) ?: null,
            'zomato_name'     => trim((string)($r[$idx['zomato_name']] ?? '')) ?: null,
            'online_3x_price' => (float)($r[$idx['online_3x_price']] ?? 0),
            'online_4x_price' => (float)($r[$idx['online_4x_price']] ?? 0),
            'online_5x_price' => $hasCol5 ? (float)($r[$idx['online_5x_price']] ?? 0) : 0.0,
            'online_6x_price' => $hasCol6 ? (float)($r[$idx['online_6x_price']] ?? 0) : 0.0,
            'tax_pct'         => (float)($r[$idx['tax_pct']] ?? 0),
        ];
    }
    fclose($fh);

    if (!$rows) {
        flash('error', 'CSV had no data rows.');
        header('Location: index.php?page=price_list'); exit;
    }

    $db = getDb();
    try {
        // Replace mode uses TRUNCATE so AUTO_INCREMENT resets to 1 — the
        // statement issues an implicit commit in MySQL, so it must run
        // *before* the user-level transaction is opened. (No FKs point at
        // price_list, so the TRUNCATE itself can't be blocked.)
        if ($mode === 'replace') {
            $db->exec('TRUNCATE TABLE price_list');
        }
        $db->beginTransaction();

        // Dynamic column list — same pattern as doSavePriceListItem and
        // the variation submit/update handlers.
        $cols = ['item_code','item_name','swiggy_name','zomato_name',
                 'online_3x_price','online_4x_price'];
        if ($has5x) $cols[] = 'online_5x_price';
        if ($has6x) $cols[] = 'online_6x_price';
        $cols[] = 'tax_pct';
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO price_list (' . implode(',', $cols) . ") VALUES ($ph)";
        // Update mode: turn the INSERT into an UPSERT so existing rows are
        // updated in place (matched by the uniq_item_code index) and new
        // item codes are appended. Replace mode skipped this branch
        // because the prior DELETE means there's nothing to collide with.
        if ($mode === 'update') {
            $assign = array_map(fn($c) => "$c = VALUES($c)",
                                array_diff($cols, ['item_code']));
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assign);
        }
        $ins = $db->prepare($sql);

        $inserted = 0; $updated = 0;
        foreach ($rows as $r) {
            $vals = [$r['item_code'], $r['item_name'], $r['swiggy_name'], $r['zomato_name'],
                     $r['online_3x_price'], $r['online_4x_price']];
            if ($has5x) $vals[] = $r['online_5x_price'];
            if ($has6x) $vals[] = $r['online_6x_price'];
            $vals[] = $r['tax_pct'];
            $ins->execute($vals);
            // PDO rowCount on a MySQL UPSERT: 1 = inserted, 2 = updated,
            // 0 = matched-but-identical. Treat anything other than 1 as
            // an update for reporting purposes.
            if ($mode === 'update') {
                $rc = $ins->rowCount();
                if ($rc === 1) $inserted++; else $updated++;
            } else {
                $inserted++;
            }
        }
        $db->commit();
        if ($mode === 'update') {
            flash('success', "Update import — {$inserted} new, {$updated} updated.");
        } else {
            flash('success', 'Imported ' . count($rows) . ' items (replaced list).');
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Import failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_list'); exit;
}

// ── POST: Add a single new item to the master price list ────
// Mirrors doSavePriceListItem's validation but for an INSERT path.
// Bounces back to the page with the typed values preserved via flash
// on validation failure; on success returns to the price list.
function doAddPriceListItem(): void {
    if (!pvCanAdmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=price_list'); exit; }

    $itemCode   = trim((string)($_POST['item_code']    ?? ''));
    $itemName   = trim((string)($_POST['item_name']    ?? ''));
    $swiggyName = trim((string)($_POST['swiggy_name']  ?? ''));
    $zomatoName = trim((string)($_POST['zomato_name']  ?? ''));
    $price3x    = (string)($_POST['online_3x_price'] ?? '');
    $price4x    = (string)($_POST['online_4x_price'] ?? '');
    $price5x    = (string)($_POST['online_5x_price'] ?? '');
    $price6x    = (string)($_POST['online_6x_price'] ?? '');
    $taxPct     = (string)($_POST['tax_pct']         ?? '');
    $has5x      = pvHas5xCol();
    $has6x      = pvHas6xCol();

    if ($itemCode === '' || $itemName === '') {
        flash('error', 'Item code and name are required.');
        header('Location: index.php?page=price_list'); exit;
    }
    $checks = ['3x price' => $price3x, '4x price' => $price4x, 'tax %' => $taxPct];
    if ($has5x) $checks['5x price'] = $price5x;
    if ($has6x) $checks['6x price'] = $price6x;
    foreach ($checks as $label => $val) {
        if ($val !== '' && !is_numeric($val)) {
            flash('error', "Invalid {$label} — must be a number.");
            header('Location: index.php?page=price_list'); exit;
        }
        if ($val !== '' && (float)$val < 0) {
            flash('error', "Invalid {$label} — must be ≥ 0.");
            header('Location: index.php?page=price_list'); exit;
        }
    }

    try {
        $dup = getDb()->prepare('SELECT id FROM price_list WHERE item_code = ? LIMIT 1');
        $dup->execute([$itemCode]);
        if ($dup->fetchColumn()) {
            flash('error', 'Item code "' . $itemCode . '" already exists. Use the edit row or switch the import mode to Update.');
            header('Location: index.php?page=price_list'); exit;
        }

        $cols = ['item_code','item_name','swiggy_name','zomato_name',
                 'online_3x_price','online_4x_price'];
        $vals = [$itemCode, $itemName,
                 $swiggyName !== '' ? $swiggyName : null,
                 $zomatoName !== '' ? $zomatoName : null,
                 (float)($price3x !== '' ? $price3x : 0),
                 (float)($price4x !== '' ? $price4x : 0)];
        if ($has5x) { $cols[] = 'online_5x_price'; $vals[] = (float)($price5x !== '' ? $price5x : 0); }
        if ($has6x) { $cols[] = 'online_6x_price'; $vals[] = (float)($price6x !== '' ? $price6x : 0); }
        $cols[] = 'tax_pct'; $vals[] = (float)($taxPct !== '' ? $taxPct : 0);

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $st = getDb()->prepare('INSERT INTO price_list (' . implode(',', $cols) . ") VALUES ($ph)");
        $st->execute($vals);
        flash('success', 'Added "' . $itemName . '".');
    } catch (Exception $e) {
        flash('error', 'Add failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_list'); exit;
}

// ── POST: Save a single price-list item (inline edit) ───
// Existing variations snapshot item_code / item_name into
// price_variation_items, so editing the master row here doesn't
// rewrite history — old variations keep the values they were
// submitted with.
function doSavePriceListItem(): void {
    if (!pvCanAdmin()) { flash('error', 'Access denied.'); header('Location: index.php?page=price_list'); exit; }

    $id          = (int)($_POST['id'] ?? 0);
    $itemCode    = trim((string)($_POST['item_code']    ?? ''));
    $itemName    = trim((string)($_POST['item_name']    ?? ''));
    $swiggyName  = trim((string)($_POST['swiggy_name']  ?? ''));
    $zomatoName  = trim((string)($_POST['zomato_name']  ?? ''));
    $price3x     = (string)($_POST['online_3x_price'] ?? '');
    $price4x     = (string)($_POST['online_4x_price'] ?? '');
    $price5x     = (string)($_POST['online_5x_price'] ?? '');
    $price6x     = (string)($_POST['online_6x_price'] ?? '');
    $taxPct      = (string)($_POST['tax_pct']         ?? '');
    $has5x       = pvHas5xCol();
    $has6x       = pvHas6xCol();

    if ($id < 1 || !pvGetPriceListItem($id)) {
        flash('error', 'Item not found.');
        header('Location: index.php?page=price_list'); exit;
    }
    if ($itemCode === '' || $itemName === '') {
        flash('error', 'Item code and name are required.');
        header('Location: index.php?page=price_list&edit=' . $id); exit;
    }
    $checks = ['3x price' => $price3x, '4x price' => $price4x, 'tax %' => $taxPct];
    if ($has5x) $checks['5x price'] = $price5x;
    if ($has6x) $checks['6x price'] = $price6x;
    foreach ($checks as $label => $val) {
        if ($val !== '' && !is_numeric($val)) {
            flash('error', "Invalid {$label} — must be a number.");
            header('Location: index.php?page=price_list&edit=' . $id); exit;
        }
        if ($val !== '' && (float)$val < 0) {
            flash('error', "Invalid {$label} — must be ≥ 0.");
            header('Location: index.php?page=price_list&edit=' . $id); exit;
        }
    }

    try {
        // Reject if another row already owns this item_code.
        $dup = getDb()->prepare('SELECT id FROM price_list WHERE item_code = ? AND id <> ? LIMIT 1');
        $dup->execute([$itemCode, $id]);
        if ($dup->fetchColumn()) {
            flash('error', 'Another item already uses code "' . $itemCode . '".');
            header('Location: index.php?page=price_list&edit=' . $id); exit;
        }

        // Dynamic SET clause — 5x/6x columns only included when their
        // respective migrations have run. Keeps pre-migration installs
        // working with the legacy 3x/4x schema.
        $sets = ['item_code=?','item_name=?','swiggy_name=?','zomato_name=?',
                 'online_3x_price=?','online_4x_price=?'];
        $vals = [$itemCode, $itemName,
                 $swiggyName !== '' ? $swiggyName : null,
                 $zomatoName !== '' ? $zomatoName : null,
                 (float)($price3x !== '' ? $price3x : 0),
                 (float)($price4x !== '' ? $price4x : 0)];
        if ($has5x) { $sets[] = 'online_5x_price=?'; $vals[] = (float)($price5x !== '' ? $price5x : 0); }
        if ($has6x) { $sets[] = 'online_6x_price=?'; $vals[] = (float)($price6x !== '' ? $price6x : 0); }
        $sets[] = 'tax_pct=?';
        $vals[] = (float)($taxPct !== '' ? $taxPct : 0);
        $vals[] = $id;

        $st = getDb()->prepare('UPDATE price_list SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->execute($vals);
        flash('success', 'Saved "' . $itemName . '".');
    } catch (Exception $e) {
        flash('error', 'Save failed: ' . $e->getMessage());
        header('Location: index.php?page=price_list&edit=' . $id); exit;
    }
    header('Location: index.php?page=price_list'); exit;
}

// ── GET: Price list export (CSV download) ───────────────
function doPriceListExport(): void {
    if (!pvCanAdmin()) { http_response_code(403); echo 'Access denied.'; return; }

    $rows = pvGetPriceList();
    $has5x = pvHas5xCol();
    $has6x = pvHas6xCol();
    $filename = 'price_list_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    // Header + rows include online_5x/6x only when the schema supports
    // them. Re-importing the export produces a byte-identical round-trip;
    // pre-migration exports stay back-compat with old tools.
    $headerCols = ['item_code','item_name','swiggy_name','zomato_name','online_3x_price','online_4x_price'];
    if ($has5x) $headerCols[] = 'online_5x_price';
    if ($has6x) $headerCols[] = 'online_6x_price';
    $headerCols[] = 'tax_pct';
    fputcsv($out, $headerCols, ',', '"', '');
    foreach ($rows as $r) {
        $rowOut = [
            $r['item_code'], $r['item_name'],
            $r['swiggy_name'] ?? '', $r['zomato_name'] ?? '',
            $r['online_3x_price'], $r['online_4x_price'],
        ];
        if ($has5x) $rowOut[] = $r['online_5x_price'] ?? 0;
        if ($has6x) $rowOut[] = $r['online_6x_price'] ?? 0;
        $rowOut[] = $r['tax_pct'];
        fputcsv($out, $rowOut, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── GET: Price variations export (CSV download, honours list filters) ──
// Streams every variation matching the SAME criteria the list page is
// showing — store, partner, status multi-select, order id, date range,
// resolved via pvResolveListFilter() — with no pagination. Variance
// columns follow the active-slot config (pvShowSlot) so the export lines
// up with what's on screen (e.g. 3x + 5x).
function doPriceVariationsExport(): void {
    if (!pvCanSubmit()) { http_response_code(403); echo 'Access denied.'; return; }

    $f   = pvResolveListFilter();
    $flt = pvBuildVariationsFilter($f);

    try {
        $sql = 'SELECT v.*,
                       (SELECT COUNT(*) FROM price_variation_items i WHERE i.variation_id = v.id) AS items_count
                FROM price_variations v WHERE 1=1' . $flt['where']
             . ' ORDER BY v.submitted_at DESC';
        $st = getDb()->prepare($sql);
        $st->execute($flt['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }

    $slots = array_values(array_filter(PV_ALL_SLOTS, 'pvShowSlot'));
    if (!$slots) $slots = ['3x', '4x'];

    $filename = 'price_variations_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    $header = ['Submitted', 'Order Date', 'Store', 'Partner', 'Order ID', 'Items', 'Net Received'];
    foreach ($slots as $slot) {
        $header[] = 'Variance ' . $slot;
        $header[] = 'Variance ' . $slot . ' %';
    }
    $header[] = 'Status';
    fputcsv($out, $header, ',', '"', '');

    foreach ($rows as $r) {
        $row = [
            !empty($r['submitted_at']) ? date('d M Y H:i', strtotime($r['submitted_at'])) : '',
            !empty($r['order_date'])   ? date('d M Y', strtotime($r['order_date']))       : '',
            $r['location_name'] ?? '',
            ucfirst((string)($r['partner'] ?? '')),
            $r['order_id'] ?? '',
            (int)($r['items_count'] ?? 0),
            number_format((float)($r['net_received'] ?? 0), 2, '.', ''),
        ];
        foreach ($slots as $slot) {
            $row[] = number_format((float)($r['variance_' . $slot] ?? 0), 2, '.', '');
            $row[] = number_format((float)($r['variance_' . $slot . '_pct'] ?? 0), 2, '.', '');
        }
        $row[] = ucfirst((string)($r['status'] ?? ''));
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── POST: Submit a multi-item price variation (manager) ─
function doSubmitPriceVariation(): void {
    if (!pvCanCreate()) { flash('error', 'Access denied.'); header('Location: index.php?page=price_variation_new'); exit; }

    // Store: any active location may be picked. Default for the dropdown is
    // the manager's own location_id, but they can pick another (e.g. when
    // covering for a sister outlet).
    $locId = (int)($_POST['location_id'] ?? 0);
    if ($locId <= 0 && !isSuperadmin()) $locId = myLocationId();
    if ($locId <= 0) { flash('error', 'Pick a store.'); header('Location: index.php?page=price_variation_new'); exit; }
    $locRow = null;
    try {
        $st = getDb()->prepare('SELECT location_name FROM locations WHERE location_id = ? AND is_active = 1');
        $st->execute([$locId]); $locRow = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    if (!$locRow) { flash('error', 'Store not found or inactive.'); header('Location: index.php?page=price_variation_new'); exit; }

    $partner = $_POST['partner'] ?? '';
    if (!in_array($partner, ['swiggy', 'zomato'], true)) {
        flash('error', 'Pick Swiggy or Zomato.'); header('Location: index.php?page=price_variation_new'); exit;
    }

    $orderId = trim($_POST['order_id'] ?? '');
    if ($orderId === '') { flash('error', 'Order ID required.'); header('Location: index.php?page=price_variation_new'); exit; }

    // Reject duplicates — same (partner, order_id) can only be filed once,
    // unless every prior submission for that order was rejected (in which
    // case the manager is free to re-submit a corrected version).
    try {
        $st = getDb()->prepare("SELECT id FROM price_variations
                                WHERE partner = ? AND order_id = ? AND status <> 'rejected'
                                LIMIT 1");
        $st->execute([$partner, $orderId]);
        $dupId = (int)$st->fetchColumn();
    } catch (Exception $e) { $dupId = 0; }
    if ($dupId > 0) {
        flash('error', 'A price variation for ' . ucfirst($partner) . ' Order ID "' . $orderId . '" has already been submitted (#' . $dupId . ').');
        header('Location: index.php?page=price_variation_new'); exit;
    }

    // Order date — optional but if provided must parse.
    $orderDateRaw = trim($_POST['order_date'] ?? '');
    $orderDate    = null;
    if ($orderDateRaw !== '') {
        $ts = strtotime($orderDateRaw);
        if ($ts === false) { flash('error', 'Invalid order date.'); header('Location: index.php?page=price_variation_new'); exit; }
        $orderDate = date('Y-m-d', $ts);
    }

    // Image attachments — validate up front so bad uploads don't waste a
    // DB write. At least one image is now mandatory (partner-app
    // screenshot / POS bill — the audit trail is incomplete without it).
    try {
        $attachments = pvHandleAttachmentUploads();
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        header('Location: index.php?page=price_variation_new'); exit;
    }
    if (!$attachments) {
        flash('error', 'At least one attachment (partner-app screenshot or POS bill) is required.');
        header('Location: index.php?page=price_variation_new'); exit;
    }

    // Items array: items[i][price_list_id], items[i][quantity]
    $itemsIn = $_POST['items'] ?? [];
    if (!is_array($itemsIn) || !$itemsIn) {
        flash('error', 'Add at least one item.'); header('Location: index.php?page=price_variation_new'); exit;
    }

    $has5x = pvHas5xCol();
    $has6x = pvHas6xCol();

    // Resolve each line from master list, snapshot every value we need.
    $resolved = [];
    $expected3xTotal = 0.0;
    $expected4xTotal = 0.0;
    $expected5xTotal = 0.0;
    $expected6xTotal = 0.0;
    foreach ($itemsIn as $line) {
        $plId = (int)($line['price_list_id'] ?? 0);
        $qty  = (int)($line['quantity']      ?? 0);
        if ($plId <= 0 || $qty <= 0) {
            flash('error', 'Each item needs a valid product and a quantity ≥ 1.');
            header('Location: index.php?page=price_variation_new'); exit;
        }
        // Per-line TOTAL as shown on the partner bill (incl tax, the
        // line subtotal — NOT a per-unit rate). The DB column is still
        // named partner_rate for back-compat, but it holds the line
        // total now (see 2026-05-18 form change). The JS form blocks
        // submission when any total is blank; this is the matching
        // server check for non-browser callers.
        $partnerRateRaw = isset($line['partner_rate']) ? trim((string)$line['partner_rate']) : '';
        if ($partnerRateRaw === '' || !is_numeric($partnerRateRaw) || (float)$partnerRateRaw < 0) {
            flash('error', 'Each item needs a non-negative partner Total (₹).');
            header('Location: index.php?page=price_variation_new'); exit;
        }
        $partnerRate = round((float)$partnerRateRaw, 2);
        try {
            $st = getDb()->prepare('SELECT * FROM price_list WHERE id = ?');
            $st->execute([$plId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $item = null; }
        if (!$item) {
            flash('error', 'One of the items is no longer in the master list — refresh and try again.');
            header('Location: index.php?page=price_variation_new'); exit;
        }
        $price3x = (float)$item['online_3x_price'];
        $price4x = (float)$item['online_4x_price'];
        $price5x = $has5x ? (float)($item['online_5x_price'] ?? 0) : 0.0;
        $price6x = $has6x ? (float)($item['online_6x_price'] ?? 0) : 0.0;
        $taxPct  = (float)$item['tax_pct'];
        $exp3x   = pvComputeExpectedLine($price3x, $qty, $taxPct);
        $exp4x   = pvComputeExpectedLine($price4x, $qty, $taxPct);
        $exp5x   = pvComputeExpectedLine($price5x, $qty, $taxPct);
        $exp6x   = pvComputeExpectedLine($price6x, $qty, $taxPct);
        $resolved[] = [
            'price_list_id'   => (int)$item['id'],
            'item_code'       => $item['item_code'],
            'item_name'       => $item['item_name'],
            'online_3x_price' => $price3x,
            'online_4x_price' => $price4x,
            'online_5x_price' => $price5x,
            'online_6x_price' => $price6x,
            'tax_pct'         => $taxPct,
            'quantity'        => $qty,
            'partner_rate'    => $partnerRate,
            'expected_3x'     => $exp3x,
            'expected_4x'     => $exp4x,
            'expected_5x'     => $exp5x,
            'expected_6x'     => $exp6x,
        ];
        $expected3xTotal += $exp3x;
        $expected4xTotal += $exp4x;
        $expected5xTotal += $exp5x;
        $expected6xTotal += $exp6x;
    }
    $expected3xTotal = round($expected3xTotal, 2);
    $expected4xTotal = round($expected4xTotal, 2);
    $expected5xTotal = round($expected5xTotal, 2);
    $expected6xTotal = round($expected6xTotal, 2);

    // All four aggregator fields are mandatory — empty = error (0 is fine).
    foreach (['bill_subtotal','discount_amount','taxes','net_received'] as $f) {
        if (!isset($_POST[$f]) || trim((string)$_POST[$f]) === '') {
            flash('error', 'All Aggregator Order fields are required (use 0 for none).');
            header('Location: index.php?page=price_variation_new'); exit;
        }
    }
    $subtotal  = (float)$_POST['bill_subtotal'];
    $discAmt   = (float)$_POST['discount_amount'];
    $taxes     = (float)$_POST['taxes'];
    $netRcvd   = (float)$_POST['net_received'];
    $remarks   = trim($_POST['remarks'] ?? '');
    if ($subtotal < 0 || $discAmt < 0 || $taxes < 0 || $netRcvd < 0) {
        flash('error', 'Amounts cannot be negative.'); header('Location: index.php?page=price_variation_new'); exit;
    }

    $variance3x    = round($netRcvd - $expected3xTotal, 2);
    $variance4x    = round($netRcvd - $expected4xTotal, 2);
    $variance5x    = round($netRcvd - $expected5xTotal, 2);
    $variance6x    = round($netRcvd - $expected6xTotal, 2);
    $variance3xPct = pvComputeVariancePct($expected3xTotal, $netRcvd);
    $variance4xPct = pvComputeVariancePct($expected4xTotal, $netRcvd);
    $variance5xPct = pvComputeVariancePct($expected5xTotal, $netRcvd);
    $variance6xPct = pvComputeVariancePct($expected6xTotal, $netRcvd);

    $db = getDb();
    try {
        $db->beginTransaction();
        // Dynamically-shaped INSERT: 5x/6x columns added only when their
        // respective migrations have run. Keeps pre-migration installs
        // working with the original 3x/4x-only schema.
        $varCols = ['location_id','location_name','partner','order_id','order_date',
                    'bill_subtotal','discount_amount','taxes','net_received',
                    'expected_3x_amount','expected_4x_amount'];
        if ($has5x) $varCols[] = 'expected_5x_amount';
        if ($has6x) $varCols[] = 'expected_6x_amount';
        array_push($varCols, 'variance_3x','variance_4x');
        if ($has5x) $varCols[] = 'variance_5x';
        if ($has6x) $varCols[] = 'variance_6x';
        array_push($varCols, 'variance_3x_pct','variance_4x_pct');
        if ($has5x) $varCols[] = 'variance_5x_pct';
        if ($has6x) $varCols[] = 'variance_6x_pct';
        array_push($varCols, 'remarks','status','submitted_by');

        $varVals = [$locId, $locRow['location_name'], $partner, $orderId, $orderDate,
                    $subtotal, $discAmt, $taxes, $netRcvd,
                    $expected3xTotal, $expected4xTotal];
        if ($has5x) $varVals[] = $expected5xTotal;
        if ($has6x) $varVals[] = $expected6xTotal;
        array_push($varVals, $variance3x, $variance4x);
        if ($has5x) $varVals[] = $variance5x;
        if ($has6x) $varVals[] = $variance6x;
        array_push($varVals, $variance3xPct, $variance4xPct);
        if ($has5x) $varVals[] = $variance5xPct;
        if ($has6x) $varVals[] = $variance6xPct;
        array_push($varVals, $remarks !== '' ? $remarks : null, 'pending', myCode());

        $placeholders = implode(',', array_fill(0, count($varCols), '?'));
        $st = $db->prepare('INSERT INTO price_variations (' . implode(',', $varCols) . ") VALUES ($placeholders)");
        $st->execute($varVals);
        $varId = (int)$db->lastInsertId();

        // Items INSERT — same dynamic-column pattern. partner_rate is
        // gated by pvHasPartnerRateCol; 5x/6x snapshot columns are
        // gated by pvHas5xCol/pvHas6xCol.
        $hasRateCol = pvHasPartnerRateCol();
        $itemCols = ['variation_id','price_list_id','item_code','item_name',
                     'online_3x_price','online_4x_price'];
        if ($has5x) $itemCols[] = 'online_5x_price';
        if ($has6x) $itemCols[] = 'online_6x_price';
        array_push($itemCols, 'tax_pct','quantity');
        if ($hasRateCol) $itemCols[] = 'partner_rate';
        array_push($itemCols, 'expected_3x','expected_4x');
        if ($has5x) $itemCols[] = 'expected_5x';
        if ($has6x) $itemCols[] = 'expected_6x';

        $itemPh = implode(',', array_fill(0, count($itemCols), '?'));
        $insItem = $db->prepare('INSERT INTO price_variation_items (' . implode(',', $itemCols) . ") VALUES ($itemPh)");
        foreach ($resolved as $r) {
            $vals = [$varId, $r['price_list_id'], $r['item_code'], $r['item_name'],
                     $r['online_3x_price'], $r['online_4x_price']];
            if ($has5x) $vals[] = $r['online_5x_price'];
            if ($has6x) $vals[] = $r['online_6x_price'];
            array_push($vals, $r['tax_pct'], $r['quantity']);
            if ($hasRateCol) $vals[] = $r['partner_rate'];
            array_push($vals, $r['expected_3x'], $r['expected_4x']);
            if ($has5x) $vals[] = $r['expected_5x'];
            if ($has6x) $vals[] = $r['expected_6x'];
            $insItem->execute($vals);
        }
        if ($attachments) {
            $insAtt = $db->prepare(
                'INSERT INTO price_variation_attachments
                   (variation_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($attachments as $a) {
                $insAtt->execute([
                    $varId, $a['original_name'], $a['stored_name'], $a['mime_type'], $a['size_bytes'], myCode(),
                ]);
            }
        }
        $db->commit();
        pvNotifyAdminsOfNewVariation($varId);
        flash('success', 'Variation submitted. Admins have been notified.');
        header('Location: index.php?page=price_variation_detail&id=' . $varId); exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Files were already moved to disk before the transaction — clean up
        // any that we created so they don't accumulate as orphans. The
        // 'now' anchor matches the bucket the writer used a moment ago.
        foreach ($attachments as $a) {
            $p = pvAttachmentPath('now', $a['stored_name'], true);
            if (is_file($p)) @unlink($p);
        }
        flash('error', 'Save failed: ' . $e->getMessage());
        header('Location: index.php?page=price_variation_new'); exit;
    }
}

// ── POST: POC confirms a submitted variation ───────────
// Sits between submit (pending) and approve (approved). Once the POC
// confirms, the row moves to 'confirmed' and the approver's
// Approve/Reject buttons become available. Reject still flows through
// the admin's decision handler — POC's only action here is to confirm.
function doConfirmPriceVariation(): void {
    if (!pvCanConfirm()) { flash('error', 'Access denied.'); header('Location: index.php?page=price_variations'); exit; }
    if (!pvHasConfirmCols()) {
        flash('error', 'POC confirmation is not enabled — run migration_2026_05_08_pv_confirm.sql.');
        header('Location: index.php?page=price_variations'); exit;
    }
    $id      = (int)($_POST['id'] ?? 0);
    $remarks = trim((string)($_POST['confirm_remarks'] ?? ''));
    if (mb_strlen($remarks) > 500) $remarks = mb_substr($remarks, 0, 500);

    $row = pvGetVariation($id);
    if (!$row) { flash('error', 'Variation not found.'); header('Location: index.php?page=price_variations'); exit; }
    if ($row['status'] !== 'pending') {
        flash('error', 'Variation is no longer pending — cannot confirm.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }
    try {
        getDb()->prepare(
            "UPDATE price_variations
                SET status='confirmed', confirmed_by=?, confirmed_at=NOW(), confirm_remarks=?
              WHERE id=? AND status='pending'"
        )->execute([myCode(), $remarks !== '' ? $remarks : null, $id]);
        flash('success', 'Variation confirmed. Awaiting approver decision.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
}

// ── POST: Store manager edits an existing variation ─────
// Allowed only on pending/confirmed rows owned by the submitter (or by
// superadmin). When editing a 'confirmed' variation, status reverts to
// 'pending' and the POC's confirmation metadata is cleared — the row
// goes back through the POC queue. Identity fields (partner / store /
// order_id) stay locked; everything else (items, aggregator totals,
// remarks, order date, additional attachments) can be updated.
function doUpdatePriceVariation(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = pvGetVariation($id);
    if (!$row) {
        flash('error', 'Variation not found.');
        header('Location: index.php?page=price_variations'); exit;
    }
    if (!pvCanEdit($row)) {
        flash('error', 'You cannot edit this variation.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }

    $wasConfirmed = ($row['status'] === 'confirmed');
    $locId        = (int)$row['location_id'];
    $partner      = $row['partner'];
    $editBack     = 'index.php?page=price_variation_edit&id=' . $id;

    // Order date — optional but if provided must parse.
    $orderDateRaw = trim($_POST['order_date'] ?? '');
    $orderDate    = null;
    if ($orderDateRaw !== '') {
        $ts = strtotime($orderDateRaw);
        if ($ts === false) { flash('error', 'Invalid order date.'); header('Location: ' . $editBack); exit; }
        $orderDate = date('Y-m-d', $ts);
    }

    // Items array — same shape as the new form.
    $itemsIn = $_POST['items'] ?? [];
    if (!is_array($itemsIn) || !$itemsIn) {
        flash('error', 'Add at least one item.'); header('Location: ' . $editBack); exit;
    }

    $has5x = pvHas5xCol();
    $has6x = pvHas6xCol();
    $resolved = [];
    $expected3xTotal = 0.0;
    $expected4xTotal = 0.0;
    $expected5xTotal = 0.0;
    $expected6xTotal = 0.0;
    foreach ($itemsIn as $line) {
        $plId = (int)($line['price_list_id'] ?? 0);
        $qty  = (int)($line['quantity']      ?? 0);
        if ($plId <= 0 || $qty <= 0) {
            flash('error', 'Each item needs a valid product and a quantity ≥ 1.');
            header('Location: ' . $editBack); exit;
        }
        // partner_rate column now stores the line total (see
        // doSubmitPriceVariation comment for context).
        $partnerRateRaw = isset($line['partner_rate']) ? trim((string)$line['partner_rate']) : '';
        if ($partnerRateRaw === '' || !is_numeric($partnerRateRaw) || (float)$partnerRateRaw < 0) {
            flash('error', 'Each item needs a non-negative partner Total (₹).');
            header('Location: ' . $editBack); exit;
        }
        $partnerRate = round((float)$partnerRateRaw, 2);
        try {
            $st = getDb()->prepare('SELECT * FROM price_list WHERE id = ?');
            $st->execute([$plId]);
            $item = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $item = null; }
        if (!$item) {
            flash('error', 'One of the items is no longer in the master list — refresh and try again.');
            header('Location: ' . $editBack); exit;
        }
        $price3x = (float)$item['online_3x_price'];
        $price4x = (float)$item['online_4x_price'];
        $price5x = $has5x ? (float)($item['online_5x_price'] ?? 0) : 0.0;
        $price6x = $has6x ? (float)($item['online_6x_price'] ?? 0) : 0.0;
        $taxPct  = (float)$item['tax_pct'];
        $exp3x   = pvComputeExpectedLine($price3x, $qty, $taxPct);
        $exp4x   = pvComputeExpectedLine($price4x, $qty, $taxPct);
        $exp5x   = pvComputeExpectedLine($price5x, $qty, $taxPct);
        $exp6x   = pvComputeExpectedLine($price6x, $qty, $taxPct);
        $resolved[] = [
            'price_list_id'   => (int)$item['id'],
            'item_code'       => $item['item_code'],
            'item_name'       => $item['item_name'],
            'online_3x_price' => $price3x,
            'online_4x_price' => $price4x,
            'online_5x_price' => $price5x,
            'online_6x_price' => $price6x,
            'tax_pct'         => $taxPct,
            'quantity'        => $qty,
            'partner_rate'    => $partnerRate,
            'expected_3x'     => $exp3x,
            'expected_4x'     => $exp4x,
            'expected_5x'     => $exp5x,
            'expected_6x'     => $exp6x,
        ];
        $expected3xTotal += $exp3x;
        $expected4xTotal += $exp4x;
        $expected5xTotal += $exp5x;
        $expected6xTotal += $exp6x;
    }
    $expected3xTotal = round($expected3xTotal, 2);
    $expected4xTotal = round($expected4xTotal, 2);
    $expected5xTotal = round($expected5xTotal, 2);
    $expected6xTotal = round($expected6xTotal, 2);

    // Aggregator totals — all four mandatory (0 OK), non-negative.
    foreach (['bill_subtotal','discount_amount','taxes','net_received'] as $f) {
        if (!isset($_POST[$f]) || trim((string)$_POST[$f]) === '') {
            flash('error', 'All Aggregator Order fields are required (use 0 for none).');
            header('Location: ' . $editBack); exit;
        }
    }
    $subtotal = (float)$_POST['bill_subtotal'];
    $discAmt  = (float)$_POST['discount_amount'];
    $taxes    = (float)$_POST['taxes'];
    $netRcvd  = (float)$_POST['net_received'];
    $remarks  = trim($_POST['remarks'] ?? '');
    if ($subtotal < 0 || $discAmt < 0 || $taxes < 0 || $netRcvd < 0) {
        flash('error', 'Amounts cannot be negative.'); header('Location: ' . $editBack); exit;
    }

    $variance3x    = round($netRcvd - $expected3xTotal, 2);
    $variance4x    = round($netRcvd - $expected4xTotal, 2);
    $variance5x    = round($netRcvd - $expected5xTotal, 2);
    $variance6x    = round($netRcvd - $expected6xTotal, 2);
    $variance3xPct = pvComputeVariancePct($expected3xTotal, $netRcvd);
    $variance4xPct = pvComputeVariancePct($expected4xTotal, $netRcvd);
    $variance5xPct = pvComputeVariancePct($expected5xTotal, $netRcvd);
    $variance6xPct = pvComputeVariancePct($expected6xTotal, $netRcvd);

    // Add-only attachments — existing ones are kept; new files appended.
    // pvHandleAttachmentUploads tolerates zero files and returns [] in that
    // case (we ignore the original new-form "at least one required" check
    // here because the row already has prior attachments).
    $newAttachments = [];
    if (!empty($_FILES['attachments']['name'][0] ?? '')) {
        try {
            $newAttachments = pvHandleAttachmentUploads();
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            header('Location: ' . $editBack); exit;
        }
    }

    $db = getDb();
    $hasConfirmCols = pvHasConfirmCols();
    $hasRateCol     = pvHasPartnerRateCol();
    try {
        $db->beginTransaction();

        // Base row update — dynamic SET clause so 5x/6x columns only
        // appear post-migration. When editing a confirmed variation,
        // drop back to pending and clear the POC metadata.
        $sets   = ['order_date=?','bill_subtotal=?','discount_amount=?','taxes=?','net_received=?',
                   'expected_3x_amount=?','expected_4x_amount=?'];
        if ($has5x) $sets[] = 'expected_5x_amount=?';
        if ($has6x) $sets[] = 'expected_6x_amount=?';
        array_push($sets, 'variance_3x=?','variance_4x=?');
        if ($has5x) $sets[] = 'variance_5x=?';
        if ($has6x) $sets[] = 'variance_6x=?';
        array_push($sets, 'variance_3x_pct=?','variance_4x_pct=?');
        if ($has5x) $sets[] = 'variance_5x_pct=?';
        if ($has6x) $sets[] = 'variance_6x_pct=?';
        $sets[] = 'remarks=?';
        if ($wasConfirmed && $hasConfirmCols) {
            $sets[] = "status='pending'";
            $sets[] = 'confirmed_by=NULL';
            $sets[] = 'confirmed_at=NULL';
            $sets[] = 'confirm_remarks=NULL';
        }

        $updVals = [$orderDate, $subtotal, $discAmt, $taxes, $netRcvd,
                    $expected3xTotal, $expected4xTotal];
        if ($has5x) $updVals[] = $expected5xTotal;
        if ($has6x) $updVals[] = $expected6xTotal;
        array_push($updVals, $variance3x, $variance4x);
        if ($has5x) $updVals[] = $variance5x;
        if ($has6x) $updVals[] = $variance6x;
        array_push($updVals, $variance3xPct, $variance4xPct);
        if ($has5x) $updVals[] = $variance5xPct;
        if ($has6x) $updVals[] = $variance6xPct;
        $updVals[] = $remarks !== '' ? $remarks : null;
        $updVals[] = $id;

        $st = $db->prepare('UPDATE price_variations SET ' . implode(', ', $sets) . ' WHERE id=?');
        $st->execute($updVals);

        // Replace items wholesale — simpler than a per-row diff and
        // matches how the new-form path persists them.
        $db->prepare('DELETE FROM price_variation_items WHERE variation_id = ?')->execute([$id]);
        $itemCols = ['variation_id','price_list_id','item_code','item_name',
                     'online_3x_price','online_4x_price'];
        if ($has5x) $itemCols[] = 'online_5x_price';
        if ($has6x) $itemCols[] = 'online_6x_price';
        array_push($itemCols, 'tax_pct','quantity');
        if ($hasRateCol) $itemCols[] = 'partner_rate';
        array_push($itemCols, 'expected_3x','expected_4x');
        if ($has5x) $itemCols[] = 'expected_5x';
        if ($has6x) $itemCols[] = 'expected_6x';
        $itemPh  = implode(',', array_fill(0, count($itemCols), '?'));
        $insItem = $db->prepare('INSERT INTO price_variation_items (' . implode(',', $itemCols) . ") VALUES ($itemPh)");
        foreach ($resolved as $r) {
            $vals = [$id, $r['price_list_id'], $r['item_code'], $r['item_name'],
                     $r['online_3x_price'], $r['online_4x_price']];
            if ($has5x) $vals[] = $r['online_5x_price'];
            if ($has6x) $vals[] = $r['online_6x_price'];
            array_push($vals, $r['tax_pct'], $r['quantity']);
            if ($hasRateCol) $vals[] = $r['partner_rate'];
            array_push($vals, $r['expected_3x'], $r['expected_4x']);
            if ($has5x) $vals[] = $r['expected_5x'];
            if ($has6x) $vals[] = $r['expected_6x'];
            $insItem->execute($vals);
        }

        // Append any newly uploaded attachments.
        if ($newAttachments) {
            $insAtt = $db->prepare(
                'INSERT INTO price_variation_attachments
                   (variation_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($newAttachments as $a) {
                $insAtt->execute([
                    $id, $a['original_name'], $a['stored_name'], $a['mime_type'], $a['size_bytes'], myCode(),
                ]);
            }
        }

        $db->commit();
        // Notify the admin queue + store contact on every edit, regardless
        // of whether the status moved (confirmed→pending vs. pending→
        // pending). Re-confirmation cases get the appropriate copy via
        // the $wasConfirmed flag inside the helper.
        pvNotifyOfEdit($id, $wasConfirmed);
        flash('success', $wasConfirmed
            ? 'Variation updated. It has been sent back to the POC for re-confirmation and the store has been notified.'
            : 'Variation updated. Reviewers and the store have been notified.'
        );
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($newAttachments as $a) {
            $p = pvAttachmentPath('now', $a['stored_name'], true);
            if (is_file($p)) @unlink($p);
        }
        flash('error', 'Update failed: ' . $e->getMessage());
        header('Location: ' . $editBack); exit;
    }
}

// ── POST: Approver amends their own decision remark ─────
// Lets the admin who decided the variation (or a superadmin) fix a typo
// or add context to their decision remark without re-opening the
// decision itself. Status, decided_by, and decided_at stay frozen.
function doEditDecisionRemarks(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = pvGetVariation($id);
    if (!$row) {
        flash('error', 'Variation not found.');
        header('Location: index.php?page=price_variations'); exit;
    }
    if (!pvCanEditDecisionRemarks($row)) {
        flash('error', 'You cannot edit this decision remark.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }
    $remarks = trim((string)($_POST['decision_remarks'] ?? ''));
    // Mirror the new-decision rule: a 'rejected' row must always carry a
    // remark (it doubles as the rejection reason for the manager).
    if ($row['status'] === 'rejected' && $remarks === '') {
        flash('error', 'Decision remark is required when the variation is rejected.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }
    try {
        getDb()->prepare(
            "UPDATE price_variations SET decision_remarks = ? WHERE id = ?"
        )->execute([$remarks !== '' ? $remarks : null, $id]);
        // Re-notify the store: same plumbing as the original approve/reject
        // email, with an "updated" intro so the manager knows this is an
        // amendment rather than a fresh decision. Silent if the location
        // has no contact email set.
        pvNotifyManagerOfRemarkUpdate($id);
        flash('success', 'Decision remark updated. The store has been notified.');
    } catch (Exception $e) {
        flash('error', 'Update failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
}

// ── POST: Edit POC confirm remarks ───────────────────────
// Sibling of doEditDecisionRemarks. The confirmer (or admin/superadmin)
// may amend the confirm note after the fact. Status, confirmed_by, and
// confirmed_at stay frozen — only the remark text moves.
function doEditConfirmRemarks(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = pvGetVariation($id);
    if (!$row) {
        flash('error', 'Variation not found.');
        header('Location: index.php?page=price_variations'); exit;
    }
    if (!pvCanEditConfirmRemarks($row)) {
        flash('error', 'You cannot edit this POC remark.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }
    $remarks = trim((string)($_POST['confirm_remarks'] ?? ''));
    try {
        getDb()->prepare(
            'UPDATE price_variations SET confirm_remarks = ? WHERE id = ?'
        )->execute([$remarks !== '' ? $remarks : null, $id]);
        flash('success', 'POC remark updated.');
    } catch (Exception $e) {
        flash('error', 'Update failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
}

// Notify everyone that an existing variation was edited. Fires on every
// successful pv_update — both when status stays 'pending' (manager
// fixing a typo on an unconfirmed row) and when status reverts from
// 'confirmed' back to 'pending' (the re-confirmation path). Mails the
// admin notification list (so the POC queue sees it again) and the
// store's contact email (so the original submitter knows their row
// changed if it was someone else who edited). Silent skip when either
// channel has no recipients configured.
function pvNotifyOfEdit(int $id, bool $wasConfirmed): void {
    $v = pvGetVariation($id);
    if (!$v) return;
    $items   = pvGetVariationItems($id);
    $editor  = myCode();
    $intro   = 'Price Variation #' . (int)$v['id']
             . ' was edited by ' . $editor . '.';
    if ($wasConfirmed) {
        $intro .= ' The previous POC confirmation has been cleared — it is back to pending and needs re-confirmation.';
    } else {
        $intro .= ' Current status: ' . $v['status'] . '.';
    }
    $subject = 'Price variation edited — ' . $v['location_name']
             . ' — ' . ucfirst($v['partner']) . ' — Order ' . $v['order_id'];
    $body    = pvBuildVariationEmail($v, $items, $intro);

    foreach (pvGetNotifyEmails() as $email) {
        sendSmtpEmailQuiet($email, $subject, $body);
    }
    $locEmail = pvGetLocationEmail((int)$v['location_id']);
    if ($locEmail) {
        sendSmtpEmailQuiet($locEmail, $subject, $body);
    }
}

// Notify the manager that the approver amended the decision remark. The
// row's status / decided_by / decided_at are unchanged — only the remark
// text moved. Falls back silently when no contact email is on file (same
// behaviour as pvNotifyManagerOfDecision).
function pvNotifyManagerOfRemarkUpdate(int $id): void {
    $v = pvGetVariation($id);
    if (!$v) return;
    $locEmail = pvGetLocationEmail((int)$v['location_id']);
    if (!$locEmail) return;
    $items   = pvGetVariationItems($id);
    $verb    = $v['status'] === 'approved' ? 'approved' : 'rejected';
    $editor  = myCode();
    $subject = 'Price variation decision remark updated — Order ' . $v['order_id'];
    $intro   = 'The decision remark on Price Variation #' . (int)$v['id']
             . ' (' . $verb . ') has been updated by ' . $editor . '.';
    $body    = pvBuildVariationEmail($v, $items, $intro);
    sendSmtpEmailQuiet($locEmail, $subject, $body);
}

// ── POST: Approve / reject (admin) ──────────────────────
// Post-migration the approver acts on 'confirmed' rows only — the POC
// step is mandatory. Pre-migration DBs without confirm columns keep
// the legacy direct path (pending → approved/rejected) so existing
// queues can still clear.
function doDecidePriceVariation(): void {
    $isAdmin   = pvCanAdmin();
    $isConfirm = pvCanConfirm();
    if (!$isAdmin && !$isConfirm) {
        flash('error', 'Access denied.'); header('Location: index.php?page=price_variations'); exit;
    }
    $id       = (int)($_POST['id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $remarks  = trim($_POST['decision_remarks'] ?? '');

    if (!in_array($decision, ['approve', 'reject'], true)) {
        flash('error', 'Invalid decision.'); header('Location: index.php?page=price_variations'); exit;
    }
    $row = pvGetVariation($id);
    if (!$row) { flash('error', 'Variation not found.'); header('Location: index.php?page=price_variations'); exit; }
    $detail = 'index.php?page=price_variation_detail&id=' . $id;

    // Who may act on this row, and from which status:
    //   • Admin/approver — approve OR reject a 'confirmed' row (or a
    //     'pending' row on legacy pre-confirm schemas).
    //   • POC confirmer  — reject a still-'pending' row outright, with no
    //     approver review needed. (Moving it forward stays on the separate
    //     pv_confirm path.) Works even if the POC is also an admin.
    $adminFrom    = pvHasConfirmCols() ? ['confirmed'] : ['pending'];
    $adminMayAct  = $isAdmin   && in_array($row['status'], $adminFrom, true);
    $pocMayReject = $isConfirm && pvHasConfirmCols() && $row['status'] === 'pending';

    if ($decision === 'approve' && !$adminMayAct) {
        $msg = (pvHasConfirmCols() && $row['status'] === 'pending')
            ? 'Waiting on POC confirmation before this variation can be approved.'
            : 'This variation cannot be approved at its current status.';
        flash('error', $msg);
        header('Location: ' . $detail); exit;
    }
    if ($decision === 'reject' && !$adminMayAct && !$pocMayReject) {
        flash('error', 'This variation cannot be rejected at its current status.');
        header('Location: ' . $detail); exit;
    }
    if ($decision === 'reject' && $remarks === '') {
        flash('error', 'Remarks are required when rejecting.');
        header('Location: ' . $detail); exit;
    }

    try {
        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $fromStatus = $row['status'];
        getDb()->prepare(
            'UPDATE price_variations
                SET status=?, decided_by=?, decided_at=NOW(), decision_remarks=?
              WHERE id=? AND status=?'
        )->execute([$newStatus, myCode(), $remarks !== '' ? $remarks : null, $id, $fromStatus]);
        pvNotifyManagerOfDecision($id);
        flash('success', 'Marked as ' . $newStatus . '.');
    } catch (Exception $e) { flash('error', $e->getMessage()); }
    header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
}

// ── POST: AJAX item search ──────────────────────────────
// Used by the new-variation form only — gate to creators, not admins.
function doSearchPriceListItems(): void {
    header('Content-Type: application/json');
    if (!pvCanCreate()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'denied']); exit; }
    $kw = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
    if ($kw === '') { echo json_encode(['ok' => true, 'items' => []]); exit; }
    $items = pvSearchPriceList($kw, 20);
    $has5x = pvHas5xCol();
    $has6x = pvHas6xCol();
    $out = array_map(function ($r) use ($has5x, $has6x) {
        $o = [
            'id'              => (int)$r['id'],
            'item_code'       => $r['item_code'],
            'item_name'       => $r['item_name'],
            'swiggy_name'     => $r['swiggy_name'],
            'zomato_name'     => $r['zomato_name'],
            'online_3x_price' => (float)$r['online_3x_price'],
            'online_4x_price' => (float)$r['online_4x_price'],
            'tax_pct'         => (float)$r['tax_pct'],
        ];
        // Only emit 5x/6x post-migration. Pre-migration the JS treats
        // a missing field as 0, so the new-form's 5x/6x columns
        // simply render ₹0.00 — no crash, no surprises.
        if ($has5x) $o['online_5x_price'] = (float)($r['online_5x_price'] ?? 0);
        if ($has6x) $o['online_6x_price'] = (float)($r['online_6x_price'] ?? 0);
        return $o;
    }, $items);
    echo json_encode(['ok' => true, 'items' => $out]);
    exit;
}

// ── Attachments ─────────────────────────────────────────
function pvGetAttachments(int $variationId): array {
    try {
        $st = getDb()->prepare(
            'SELECT id, original_name, stored_name, mime_type, size_bytes, uploaded_by, uploaded_at
             FROM price_variation_attachments
             WHERE variation_id = ?
             ORDER BY id'
        );
        $st->execute([$variationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// Validate + move uploaded files. Returns list of [original, stored, mime, size]
// rows ready to insert. Throws RuntimeException with a user-facing message on
// any validation failure (caller flashes + redirects).
function pvHandleAttachmentUploads(): array {
    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'])) return [];
    $files = $_FILES['attachments'];
    // Normalize the awkward $_FILES multi-shape: name[], type[], tmp_name[], error[], size[].
    $names    = (array)$files['name'];
    $tmps     = (array)$files['tmp_name'];
    $errs     = (array)$files['error'];
    $sizes    = (array)$files['size'];
    $count    = count($names);

    // Strip empty slots (browsers sometimes send unfilled inputs).
    $present = [];
    for ($i = 0; $i < $count; $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $present[] = $i;
    }
    if (!$present) return [];
    if (count($present) > PV_ATT_MAX_COUNT) {
        throw new RuntimeException('Too many attachments — max ' . PV_ATT_MAX_COUNT . '.');
    }
    if (!is_dir(PV_ATT_UPLOAD_DIR)) @mkdir(PV_ATT_UPLOAD_DIR, 0755, true);
    if (!is_dir(PV_ATT_UPLOAD_DIR) || !is_writable(PV_ATT_UPLOAD_DIR)) {
        throw new RuntimeException('Upload directory not writable.');
    }
    // Bucketed destination (uploads/price_variations/YYYY-MM/). The
    // bucket is anchored to NOW() so it matches the variation row's
    // submitted_at (also NOW()) when reads come back.
    $bucketDir = dirname(pvAttachmentPath('now', 'placeholder', true)) . '/';
    if (!is_dir($bucketDir)) @mkdir($bucketDir, 0755, true);
    if (!is_dir($bucketDir) || !is_writable($bucketDir)) {
        throw new RuntimeException('Upload directory not writable.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $out = [];
    foreach ($present as $i) {
        if ($errs[$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for "' . basename((string)$names[$i]) . '".');
        }
        if ((int)$sizes[$i] > PV_ATT_MAX_BYTES) {
            throw new RuntimeException('"' . basename((string)$names[$i]) . '" exceeds ' . (PV_ATT_MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        $orig = basename((string)$names[$i]);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, PV_ATT_ALLOWED_EXT, true)) {
            throw new RuntimeException('Unsupported file type "' . $ext . '". Allowed: ' . implode(', ', PV_ATT_ALLOWED_EXT) . '.');
        }
        $mime = $finfo->file((string)$tmps[$i]) ?: '';
        if (!in_array($mime, PV_ATT_ALLOWED_MIME, true)) {
            throw new RuntimeException('"' . $orig . '" is not a recognized image (mime: ' . $mime . ').');
        }
        $stored = uniqid('pv_', true) . '.' . $ext;
        if (!move_uploaded_file((string)$tmps[$i], $bucketDir . $stored)) {
            throw new RuntimeException('Could not save "' . $orig . '".');
        }
        $out[] = [
            'original_name' => $orig,
            'stored_name'   => $stored,
            'mime_type'     => $mime,
            'size_bytes'    => (int)$sizes[$i],
        ];
    }
    return $out;
}

// ── POST: add attachment(s) to an existing variation ──────────
// Open to every participant role at every stage of the variation,
// including post-decision (approved / rejected). Files go through the
// same upload pipeline as the create/edit flow.
function doPvAddAttachment(): void {
    if (!pvCanAddAttachment()) {
        flash('error', 'You do not have permission to add attachments.');
        header('Location: index.php?page=price_variations'); exit;
    }
    $varId = (int)($_POST['variation_id'] ?? 0);
    if ($varId < 1) {
        flash('error', 'Variation not specified.');
        header('Location: index.php?page=price_variations'); exit;
    }
    // Confirm the row exists so we don't try to attach to a phantom id.
    try {
        $check = getDb()->prepare('SELECT id FROM price_variations WHERE id = ?');
        $check->execute([$varId]);
        if (!$check->fetchColumn()) {
            flash('error', 'Variation not found.');
            header('Location: index.php?page=price_variations'); exit;
        }
    } catch (Exception $e) {
        flash('error', 'Database error.');
        header('Location: index.php?page=price_variations'); exit;
    }

    try {
        $files = pvHandleAttachmentUploads();
    } catch (Exception $e) {
        flash('error', $e->getMessage());
        header('Location: index.php?page=price_variation_detail&id=' . $varId); exit;
    }
    if (!$files) {
        flash('error', 'No file selected.');
        header('Location: index.php?page=price_variation_detail&id=' . $varId); exit;
    }

    try {
        $ins = getDb()->prepare(
            'INSERT INTO price_variation_attachments
               (variation_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($files as $a) {
            $ins->execute([$varId, $a['original_name'], $a['stored_name'], $a['mime_type'], $a['size_bytes'], myCode()]);
        }
        $n = count($files);
        flash('success', $n === 1 ? 'Attachment added.' : ($n . ' attachments added.'));
    } catch (Exception $e) {
        // DB insert failed — best-effort unlink the freshly-moved files so
        // they don't accumulate as orphans on disk.
        foreach ($files as $a) {
            $p = pvAttachmentPath('now', $a['stored_name'], true);
            if (is_file($p)) @unlink($p);
        }
        flash('error', 'Save failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_variation_detail&id=' . $varId); exit;
}

function doDownloadPvAttachment(): void {
    if (!pvCanSubmit()) { http_response_code(403); echo 'Access denied.'; return; }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(404); echo 'Not found.'; return; }
    try {
        $st = getDb()->prepare(
            'SELECT a.*, v.location_id, v.submitted_at
             FROM price_variation_attachments a
             JOIN price_variations v ON v.id = a.variation_id
             WHERE a.id = ?'
        );
        $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $a = null; }
    if (!$a) { http_response_code(404); echo 'Not found.'; return; }
    // Manager scope: cannot fetch other stores' attachments. Admins
    // and POC confirmers see across stores so the same gate applies.
    if (!pvCanSeeAll() && (int)$a['location_id'] !== myLocationId()) {
        http_response_code(403); echo 'Access denied.'; return;
    }
    // Bucketed layout for new uploads, flat legacy path as fallback.
    $path = pvAttachmentPath((string)($a['submitted_at'] ?? 'now'), (string)$a['stored_name'], false);
    if (!is_file($path)) { http_response_code(404); echo 'File missing.'; return; }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($path) ?: ($a['mime_type'] ?: 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$a['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Email notifications ─────────────────────────────────
// Read recipient list for new-submission notifications. The setting holds a
// JSON array like [{"email":"a@x"},{"email":"b@y"}]. Returns array of strings.
function pvGetNotifyEmails(): array {
    $raw = getSetting('PriceVariationNotifyEmails', '');
    if ($raw === '') return [];
    $rows = json_decode((string)$raw, true);
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $r) {
        $e = is_array($r) ? trim((string)($r['email'] ?? '')) : trim((string)$r);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_values(array_unique($out));
}

// Lookup the location's contact_email for decision notifications.
function pvGetLocationEmail(int $locationId): ?string {
    if ($locationId <= 0) return null;
    try {
        $st = getDb()->prepare('SELECT contact_email FROM locations WHERE location_id = ?');
        $st->execute([$locationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return null; }
    $e = trim((string)($row['contact_email'] ?? ''));
    return ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
}

function pvNotifyAdminsOfNewVariation(int $id): void {
    $v = pvGetVariation($id);
    if (!$v) return;
    $emails = pvGetNotifyEmails();
    if (!$emails) return;
    $items   = pvGetVariationItems($id);
    $subject = 'New price variation — ' . $v['location_name'] . ' — ' . ucfirst($v['partner']) . ' — Order ' . $v['order_id'];
    $body    = pvBuildVariationEmail($v, $items, 'A store manager has submitted a new price variation for your review.');
    foreach ($emails as $email) {
        sendSmtpEmailQuiet($email, $subject, $body);
    }
}

function pvNotifyManagerOfDecision(int $id): void {
    $v = pvGetVariation($id);
    if (!$v) return;
    $locEmail = pvGetLocationEmail((int)$v['location_id']);
    if (!$locEmail) return; // location has no contact email set — silent skip
    $items   = pvGetVariationItems($id);
    $verb    = $v['status'] === 'approved' ? 'approved' : 'rejected';
    $subject = 'Price variation ' . $verb . ' — Order ' . $v['order_id'];
    $body    = pvBuildVariationEmail($v, $items, 'Price variation #' . (int)$v['id'] . ' has been ' . $verb . '.');
    sendSmtpEmailQuiet($locEmail, $subject, $body);
}

function pvBuildVariationEmail(array $v, array $items, string $intro): string {
    // Slots shown in the email follow the admin's PriceSlotsActive setting
    // — e.g. if 3x and 5x are active, 4x/6x columns are hidden. Each slot
    // key like "3x" maps to a set of columns: per-item price + line-total,
    // and per-variation expected amount + variance + variance %.
    $slots = pvActiveSlots(); // ['3x','4x','5x','6x'] subset, in order
    $slotN = function (string $slot): int { return (int)rtrim($slot, 'x'); };

    // ── Items table ────────────────────────────────────
    // Header: Item | Qty | <slot prices…> | Tax | <line totals per slot…>
    $itemHead = "<thead><tr style='background:#fafafa'>"
              . "<th style='padding:6px 8px;text-align:left'>Item</th>"
              . "<th style='padding:6px 8px;text-align:right'>Qty</th>";
    foreach ($slots as $s) {
        $itemHead .= "<th style='padding:6px 8px;text-align:right'>" . h($s) . "</th>";
    }
    $itemHead .= "<th style='padding:6px 8px;text-align:right'>Tax</th>";
    foreach ($slots as $s) {
        $itemHead .= "<th style='padding:6px 8px;text-align:right'>Line " . h($s) . "</th>";
    }
    $itemHead .= "</tr></thead>";

    $itemRows = '';
    foreach ($items as $it) {
        $row = "<tr>"
             . "<td style='padding:5px 8px;border-bottom:1px solid #eee'>" . h($it['item_name']) . " <span style='color:#888'>(" . h($it['item_code']) . ")</span></td>"
             . "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . (int)$it['quantity'] . "</td>";
        foreach ($slots as $s) {
            $col = 'online_' . $s . '_price';
            $row .= "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . pvFmtMoney((float)($it[$col] ?? 0)) . "</td>";
        }
        $row .= "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . number_format((float)$it['tax_pct'], 2) . "%</td>";
        foreach ($slots as $s) {
            $col = 'expected_' . $s;
            $row .= "<td style='padding:5px 8px;border-bottom:1px solid #eee;text-align:right'>" . pvFmtMoney((float)($it[$col] ?? 0)) . "</td>";
        }
        $itemRows .= $row . "</tr>";
    }

    // ── Comparison table ───────────────────────────────
    // Two rows per active slot: Expected total + Variance (₹ and %).
    $cmpRows = [];
    foreach ($slots as $s) {
        $expCol = 'expected_' . $s . '_amount';
        $cmpRows[] = ['Expected (' . h($s) . ' slot)', pvFmtMoney((float)($v[$expCol] ?? 0))];
    }
    foreach ($slots as $s) {
        $varCol    = 'variance_' . $s;
        $varPctCol = 'variance_' . $s . '_pct';
        $cmpRows[] = ['Variance vs ' . h($s),
                      pvFmtMoney((float)($v[$varCol] ?? 0)) . '  (' . pvFmtPct((float)($v[$varPctCol] ?? 0)) . ')'];
    }

    $orderDateStr = !empty($v['order_date']) ? date('d M Y', strtotime($v['order_date'])) : '—';
    $headerRows = [
        ['Store',       h($v['location_name'])],
        ['Partner',     ucfirst(h($v['partner']))],
        ['Order ID',    h($v['order_id'])],
        ['Order Date',  h($orderDateStr)],
        ['Submitted',   h($v['submitted_at']) . ' by ' . h($v['submitted_by'])],
    ];
    $aggRows = [
        ['Bill Subtotal',  pvFmtMoney((float)$v['bill_subtotal'])],
        ['Discount',       pvFmtMoney((float)$v['discount_amount'])],
        ['Taxes',          pvFmtMoney((float)$v['taxes'])],
        ['Net Received',   pvFmtMoney((float)$v['net_received'])],
    ];

    $tbl = function (array $rows): string {
        $h = "<table style='border-collapse:collapse;width:100%;font-size:13px;margin:8px 0'>";
        foreach ($rows as $r) {
            $h .= "<tr><td style='padding:6px 8px;border-bottom:1px solid #f0f0f0;color:#666;width:38%'>{$r[0]}</td>"
                . "<td style='padding:6px 8px;border-bottom:1px solid #f0f0f0'><b>{$r[1]}</b></td></tr>";
        }
        return $h . "</table>";
    };

    $html  = "<div style='font-family:Arial,sans-serif;color:#222;max-width:720px'>";
    $html .= "<div style='background:#1a1612;color:#f6ecd3;padding:14px 20px;border-radius:6px 6px 0 0'>"
           . "<h2 style='margin:0;font-size:18px'>Price Variation #" . (int)$v['id'] . "</h2></div>";
    $html .= "<div style='border:1px solid #e5e5e5;border-top:none;padding:14px 20px;border-radius:0 0 6px 6px'>";
    $html .= "<p style='margin:0 0 10px'>" . h($intro) . "</p>";
    $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Order</h3>" . $tbl($headerRows);
    $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Items</h3>";
    $html .= "<table style='border-collapse:collapse;width:100%;font-size:12.5px'>"
           . $itemHead
           . "<tbody>{$itemRows}</tbody></table>";
    $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Aggregator Reported</h3>" . $tbl($aggRows);
    $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Comparison</h3>" . $tbl($cmpRows);
    if (!empty($v['remarks']))          $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Manager Remarks</h3><div style='background:#fafafa;padding:8px 10px;border-radius:4px;white-space:pre-wrap'>" . h($v['remarks']) . "</div>";
    if (!empty($v['decision_remarks'])) $html .= "<h3 style='font-size:14px;margin:14px 0 4px'>Decision Remarks</h3><div style='background:#fafafa;padding:8px 10px;border-radius:4px;white-space:pre-wrap'>" . h($v['decision_remarks']) . "</div>";
    $html .= "</div></div>";
    return $html;
}

// =========================================================
//  PAGE RENDERERS
// =========================================================

// ── Page: Master price list (admin) ─────────────────────
function pagePriceList(): void {
    if (!pvCanAdmin()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    $rows         = pvGetPriceList();
    $editId       = (int)($_GET['edit'] ?? 0);
    $has5x        = pvHas5xCol();
    $has6x        = pvHas6xCol();
    $activeSlots  = pvActiveSlots();
    // Inactive-marker helper for slot column headers below.
    $slotLabel    = function (string $slot) use ($activeSlots): string {
        return in_array($slot, $activeSlots, true)
            ? $slot
            : $slot . ' <span style="font-size:9px;color:var(--muted);font-weight:400;text-transform:uppercase;letter-spacing:.04em">(inactive)</span>';
    };
    // colspan tracks the empty-state cell width so the message stays
    // centred. Base 8 cols + one per enabled slot (5x, 6x).
    $colCount = 8 + ($has5x ? 1 : 0) + ($has6x ? 1 : 0);
    // CSV header hint reflects the actual accepted columns. 5x/6x are
    // optional in the import (back-compat) but advertised here when
    // the schema supports them.
    $csvHeader = 'item_code,item_name,swiggy_name,zomato_name,online_3x_price,online_4x_price'
        . ($has5x ? ',online_5x_price' : '')
        . ($has6x ? ',online_6x_price' : '')
        . ',tax_pct';
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">Master Price List</h2>
    <span style="font-size:12px;color:var(--muted)"><?= count($rows) ?> items</span>
    <div style="margin-left:auto;display:flex;gap:8px">
        <a href="?page=price_list_export" class="btn btn-secondary">Export CSV</a>
        <button type="button" class="btn btn-primary" onclick="plOpenImport()">Import CSV</button>
        <button type="button" class="btn btn-ghost" onclick="plOpenSlots()" title="Pick which slot columns appear on the variation pages">⚙ Slot Activity</button>
        <button type="button" class="btn btn-success" onclick="plOpenAdd()">+ Add Item</button>
    </div>
</div>

<!-- Import CSV modal — mirrors the shelf_life import popup -->
<div id="plImportModal" class="pl-modal" onclick="plCloseImport(event)">
    <div class="pl-modal-content" style="max-width:560px;width:90vw">
        <span class="pl-modal-close" onclick="plCloseImport()">&times;</span>
        <h4 class="pl-modal-title">Import Price List (CSV)</h4>
        <form method="POST" enctype="multipart/form-data" id="plImportForm" onsubmit="return plConfirmImport()">
            <input type="hidden" name="action" value="pl_import">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:6px">Mode</label>
                    <div style="display:flex;flex-direction:column;gap:6px;font-size:13px">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="mode" value="replace" checked style="margin-top:3px" onchange="plSyncImportMode()">
                            <span><strong>Replace</strong>
                                <span style="display:block;font-size:11px;color:var(--muted)">Truncates the list (auto_increment resets to 1) and re-inserts every CSV row.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="radio" name="mode" value="update" style="margin-top:3px" onchange="plSyncImportMode()">
                            <span><strong>Update</strong>
                                <span style="display:block;font-size:11px;color:var(--muted)">Upsert by <code>item_code</code> — existing rows updated, new items added.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div id="plImportReplaceWarn" style="background:#fff4e5;border:1px solid #f4c37d;border-radius:6px;padding:10px 12px;font-size:12px;color:#7a4a00;line-height:1.5">
                    <b>Warning:</b> Replace will <b>delete every row in the price list</b> before re-inserting from the CSV.
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">CSV file</label>
                    <input type="file" name="csv" accept=".csv,text/csv" required class="form-control" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">
                        Header row required:
                        <code style="display:block;margin-top:3px;white-space:normal;word-break:break-word;line-height:1.5;background:rgba(255,255,255,.05);padding:4px 6px;border-radius:4px"><?= h($csvHeader) ?></code>
                        <?php if ($has5x || $has6x): ?><span style="opacity:.75;display:block;margin-top:3px">(<?= h(implode(' / ', array_filter([$has5x ? 'online_5x_price' : '', $has6x ? 'online_6x_price' : '']))) ?> optional — missing column<?= ($has5x && $has6x) ? 's default' : ' defaults' ?> to 0.)</span><?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="plCloseImport()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="plImportSubmit">Replace &amp; Import</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function plOpenImport(){
    document.getElementById('plImportModal').classList.add('active');
    plSyncImportMode();
}
function plCloseImport(e){
    if(!e||e.target===document.getElementById('plImportModal')||e.target.classList.contains('pl-modal-close'))
        document.getElementById('plImportModal').classList.remove('active');
}
function plCurrentImportMode(){
    var form = document.getElementById('plImportForm');
    if (!form) return 'replace';
    var sel = form.querySelector('input[name="mode"]:checked');
    return sel ? sel.value : 'replace';
}
// Mirror the shelf_life pattern: hide the destructive warning + relabel
// the submit button when the user picks Update mode.
function plSyncImportMode(){
    var mode = plCurrentImportMode();
    var warn = document.getElementById('plImportReplaceWarn');
    var btn  = document.getElementById('plImportSubmit');
    if (mode === 'update') {
        if (warn) warn.style.display = 'none';
        if (btn)  btn.textContent = 'Update from CSV';
    } else {
        if (warn) warn.style.display = '';
        if (btn)  btn.textContent = 'Replace & Import';
    }
}
function plConfirmImport(){
    if (plCurrentImportMode() === 'update') return true;
    return confirm('Replace mode will TRUNCATE the price list and reload it from this CSV. Continue?');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('plImportModal')?.classList.remove('active');
});
</script>

<!-- Add Item modal — mirrors the shelf_life "Add Product" modal -->
<div id="plAddModal" class="pl-modal" onclick="plCloseAdd(event)">
    <div class="pl-modal-content" style="max-width:540px;width:90vw">
        <span class="pl-modal-close" onclick="plCloseAdd()">&times;</span>
        <h4 class="pl-modal-title">Add Item</h4>
        <form method="POST">
            <input type="hidden" name="action" value="pl_add_item">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Code <span class="required">*</span></label>
                    <input type="text" name="item_code" class="form-control" required maxlength="50"
                           style="width:100%;font-family:Consolas,monospace;font-size:12.5px">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Item Name <span class="required">*</span></label>
                    <input type="text" name="item_name" class="form-control" required maxlength="200" style="width:100%">
                </div>
                <div style="display:flex;gap:12px">
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Swiggy Name</label>
                        <input type="text" name="swiggy_name" class="form-control" maxlength="200" style="width:100%" placeholder="Optional">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Zomato Name</label>
                        <input type="text" name="zomato_name" class="form-control" maxlength="200" style="width:100%" placeholder="Optional">
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <div style="flex:1;min-width:90px">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">3x Price</label>
                        <input type="number" name="online_3x_price" class="form-control" min="0" step="0.01" value="0" style="width:100%">
                    </div>
                    <div style="flex:1;min-width:90px">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">4x Price</label>
                        <input type="number" name="online_4x_price" class="form-control" min="0" step="0.01" value="0" style="width:100%">
                    </div>
                    <?php if ($has5x): ?>
                    <div style="flex:1;min-width:90px">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">5x Price</label>
                        <input type="number" name="online_5x_price" class="form-control" min="0" step="0.01" value="0" style="width:100%">
                    </div>
                    <?php endif; ?>
                    <?php if ($has6x): ?>
                    <div style="flex:1;min-width:90px">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">6x Price</label>
                        <input type="number" name="online_6x_price" class="form-control" min="0" step="0.01" value="0" style="width:100%">
                    </div>
                    <?php endif; ?>
                    <div style="flex:1;min-width:90px">
                        <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px">Tax %</label>
                        <input type="number" name="tax_pct" class="form-control" min="0" max="100" step="0.01" value="0" style="width:100%">
                    </div>
                </div>
                <div style="font-size:11px;color:var(--muted)">Item code must be unique across the price list.</div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="plCloseAdd()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </div>
        </form>
    </div>
</div>
<style>
.pl-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center}
.pl-modal.active{display:flex}
.pl-modal-content{background:var(--surface);border-radius:10px;padding:16px;max-width:90vw;max-height:90vh;overflow-x:hidden;overflow-y:auto;position:relative;border:1px solid var(--border);box-sizing:border-box}
.pl-modal-content *{box-sizing:border-box;max-width:100%}
.pl-modal-close{position:absolute;top:8px;right:14px;font-size:24px;color:var(--muted);cursor:pointer;z-index:1}
.pl-modal-close:hover{color:var(--text)}
.pl-modal-title{font-size:14px;margin-bottom:10px;padding-right:24px;color:var(--text)}
</style>
<script>
function plOpenAdd(){document.getElementById('plAddModal').classList.add('active');}
function plCloseAdd(e){
    if(!e||e.target===document.getElementById('plAddModal')||e.target.classList.contains('pl-modal-close'))
        document.getElementById('plAddModal').classList.remove('active');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('plAddModal')?.classList.remove('active');
});
</script>

<!-- Slot Activity modal -->
<div id="plSlotsModal" class="pl-modal" onclick="plCloseSlots(event)">
    <div class="pl-modal-content" style="max-width:480px;width:90vw">
        <span class="pl-modal-close" onclick="plCloseSlots()">&times;</span>
        <h4 class="pl-modal-title">Slot Activity</h4>
        <form method="POST">
            <input type="hidden" name="action" value="pl_save_slot_activity">
            <div style="display:flex;flex-direction:column;gap:12px">
                <div style="font-size:12px;color:var(--muted);line-height:1.5">
                    Pick which slot columns appear on the <strong>New / Edit / Detail</strong> variation pages.
                    Admins can still edit every slot price in the table below regardless of the active flag —
                    handy for pre-filling the next transition.
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:14px;padding:6px 0">
                    <?php foreach (PV_ALL_SLOTS as $slot):
                        $exists  = ($slot === '5x') ? $has5x : (($slot === '6x') ? $has6x : true);
                        $checked = in_array($slot, $activeSlots, true);
                        if (!$exists) continue; // schema-missing slots don't get a checkbox
                    ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;color:var(--text);padding:6px 10px;border:1px solid var(--border);border-radius:6px">
                        <input type="checkbox" name="slots[]" value="<?= h($slot) ?>" <?= $checked ? 'checked' : '' ?>
                               style="width:16px;height:16px;cursor:pointer">
                        <span><?= h($slot) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                    <button type="button" class="btn btn-secondary" onclick="plCloseSlots()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function plOpenSlots(){document.getElementById('plSlotsModal').classList.add('active');}
function plCloseSlots(e){
    if(!e||e.target===document.getElementById('plSlotsModal')||e.target.classList.contains('pl-modal-close'))
        document.getElementById('plSlotsModal').classList.remove('active');
}
// Extend the existing Escape-handler to this modal too.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.getElementById('plSlotsModal')?.classList.remove('active');
});
</script>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Swiggy Name</th>
            <th>Zomato Name</th>
            <th style="text-align:right"><?= $slotLabel('3x') ?></th>
            <th style="text-align:right"><?= $slotLabel('4x') ?></th>
            <?php if ($has5x): ?><th style="text-align:right"><?= $slotLabel('5x') ?></th><?php endif; ?>
            <?php if ($has6x): ?><th style="text-align:right"><?= $slotLabel('6x') ?></th><?php endif; ?>
            <th style="text-align:right">Tax %</th>
            <th style="width:140px;text-align:center">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= (int)$colCount ?>" class="empty-row">No items yet. Import a CSV above.</td></tr>
    <?php else: foreach ($rows as $r):
        $isEditing = $editId > 0 && $editId === (int)$r['id'];
    ?>
        <?php if ($isEditing): ?>
        <!-- Inline edit row — turns the entire row into form inputs.
             Uses a separate <form> per row so cancellation is just
             clicking the link (no JS state to reset). -->
        <tr style="background:rgba(26,143,227,.08)">
            <form method="POST" id="plEditForm-<?= (int)$r['id'] ?>"></form>
            <td>
                <input type="hidden" name="action" value="pl_save_item" form="plEditForm-<?= (int)$r['id'] ?>">
                <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>" form="plEditForm-<?= (int)$r['id'] ?>">
                <input type="text" name="item_code" class="form-control" required maxlength="50"
                       value="<?= h($r['item_code']) ?>" form="plEditForm-<?= (int)$r['id'] ?>"
                       style="font-family:Consolas,monospace;font-size:12px">
            </td>
            <td>
                <input type="text" name="item_name" class="form-control" required maxlength="200"
                       value="<?= h($r['item_name']) ?>" form="plEditForm-<?= (int)$r['id'] ?>">
            </td>
            <td>
                <input type="text" name="swiggy_name" class="form-control" maxlength="200"
                       value="<?= h($r['swiggy_name'] ?? '') ?>" form="plEditForm-<?= (int)$r['id'] ?>">
            </td>
            <td>
                <input type="text" name="zomato_name" class="form-control" maxlength="200"
                       value="<?= h($r['zomato_name'] ?? '') ?>" form="plEditForm-<?= (int)$r['id'] ?>">
            </td>
            <td>
                <input type="number" name="online_3x_price" class="form-control" min="0" step="0.01"
                       value="<?= h(number_format((float)$r['online_3x_price'], 2, '.', '')) ?>"
                       form="plEditForm-<?= (int)$r['id'] ?>" style="text-align:right">
            </td>
            <td>
                <input type="number" name="online_4x_price" class="form-control" min="0" step="0.01"
                       value="<?= h(number_format((float)$r['online_4x_price'], 2, '.', '')) ?>"
                       form="plEditForm-<?= (int)$r['id'] ?>" style="text-align:right">
            </td>
            <?php if ($has5x): ?>
            <td>
                <input type="number" name="online_5x_price" class="form-control" min="0" step="0.01"
                       value="<?= h(number_format((float)($r['online_5x_price'] ?? 0), 2, '.', '')) ?>"
                       form="plEditForm-<?= (int)$r['id'] ?>" style="text-align:right">
            </td>
            <?php endif; ?>
            <?php if ($has6x): ?>
            <td>
                <input type="number" name="online_6x_price" class="form-control" min="0" step="0.01"
                       value="<?= h(number_format((float)($r['online_6x_price'] ?? 0), 2, '.', '')) ?>"
                       form="plEditForm-<?= (int)$r['id'] ?>" style="text-align:right">
            </td>
            <?php endif; ?>
            <td>
                <input type="number" name="tax_pct" class="form-control" min="0" max="100" step="0.01"
                       value="<?= h(number_format((float)$r['tax_pct'], 2, '.', '')) ?>"
                       form="plEditForm-<?= (int)$r['id'] ?>" style="text-align:right">
            </td>
            <td style="text-align:center;white-space:nowrap">
                <button type="submit" class="btn btn-sm btn-success"
                        form="plEditForm-<?= (int)$r['id'] ?>">Save</button>
                <a href="?page=price_list" class="btn btn-sm btn-ghost">Cancel</a>
            </td>
        </tr>
        <?php else: ?>
        <tr>
            <td><code><?= h($r['item_code']) ?></code></td>
            <td><?= h($r['item_name']) ?></td>
            <td><?= h($r['swiggy_name'] ?? '') ?></td>
            <td><?= h($r['zomato_name'] ?? '') ?></td>
            <td style="text-align:right"><?= pvFmtMoney((float)$r['online_3x_price']) ?></td>
            <td style="text-align:right"><?= pvFmtMoney((float)$r['online_4x_price']) ?></td>
            <?php if ($has5x): ?>
            <td style="text-align:right"><?= pvFmtMoney((float)($r['online_5x_price'] ?? 0)) ?></td>
            <?php endif; ?>
            <?php if ($has6x): ?>
            <td style="text-align:right"><?= pvFmtMoney((float)($r['online_6x_price'] ?? 0)) ?></td>
            <?php endif; ?>
            <td style="text-align:right"><?= number_format((float)$r['tax_pct'], 2) ?>%</td>
            <td style="text-align:center">
                <a href="?page=price_list&edit=<?= (int)$r['id'] ?>#row-<?= (int)$r['id'] ?>"
                   id="row-<?= (int)$r['id'] ?>"
                   class="btn btn-sm btn-secondary">Edit</a>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php
}

// ── Page: New variation form (multi-item, dual-slot preview) ──
function pagePriceVariationNew(): void {
    if (!pvCanCreate()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    // Locations list — managers may pick any active store; default to their own.
    $locations    = getActiveLocations();
    $defaultLocId = isSuperadmin() ? 0 : myLocationId();
    // Active slot set drives which Amount/Total columns + aggregator
    // columns render in this form (and the JS recompute).
    $show3x = pvShowSlot('3x');
    $show4x = pvShowSlot('4x');
    $show5x = pvShowSlot('5x');
    $show6x = pvShowSlot('6x');
    $slotsActive = pvActiveSlots();
    // 5 fixed leading cols (#, Code, Item, Qty, Total) + 2 per slot
    // (Amount + Total) + trailing × col.
    $activeCount = (int)$show3x + (int)$show4x + (int)$show5x + (int)$show6x;
    $itemsColspan = 5 + 1 /* tax */ + 2 * $activeCount + 1 /* × */;
?>
<style>
/* All cream-card text is forced dark so it stays readable on the app's dark theme. */
.pv-card { background:#f9f7f2; border:1px solid #e7e2d6; border-radius:8px; padding:12px; color:#1a1612; }
.pv-card .pv-label { font-size:11px; color:#7a6f60; font-weight:500; letter-spacing:.04em; text-transform:uppercase; }
.pv-card .pv-value { font-size:18px; font-weight:700; color:#1a1612; line-height:1.2; }
.pv-card .pv-value.pv-num { font-variant-numeric:tabular-nums; }
.pv-summary-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.pv-totals-bar { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-top:8px; }
/* Item entry bar (search + qty + rate + add). Sits directly above the items table. */
.pv-entry-bar { display:grid; grid-template-columns:1fr 100px 130px 110px; gap:8px; align-items:stretch; margin-bottom:10px; }
.pv-entry-search { position:relative; }
.pv-entry-bar input[type=text],
.pv-entry-bar input[type=number] {
    width:100%; background:#fff; color:#1a1612; border:1px solid #d8d0c2; border-radius:6px;
    padding:9px 12px; font-size:14px; box-sizing:border-box; height:100%;
}
.pv-entry-bar input[type=number] { text-align:right; font-variant-numeric:tabular-nums; }
.pv-entry-bar button.pv-entry-add {
    background:#1a8fe3; color:#fff; border:1px solid #1a8fe3; border-radius:6px;
    cursor:pointer; font-weight:600; font-size:14px; padding:0 14px;
}
.pv-entry-bar button.pv-entry-add:disabled { background:#aaa; border-color:#aaa; cursor:not-allowed; }
.pv-entry-bar button.pv-entry-add:hover:not(:disabled) { background:#1577c2; border-color:#1577c2; }
/* Mobile: stack item-name search on its own row, with qty/rate/+Add below. */
@media (max-width: 900px) {
    .pv-entry-bar { grid-template-columns: 1fr; }
    .pv-entry-bar input[type=number],
    .pv-entry-bar button.pv-entry-add { width:100%; height:auto; padding:10px 12px; }
}
.pv-results-box {
    position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:50;
    background:#fff; color:#1a1612; border:1px solid #d8d0c2; border-radius:6px;
    max-height:280px; overflow:auto; box-shadow:0 8px 20px rgba(0,0,0,.08);
}
.pv-result { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f0ebe0; }
.pv-result:hover { background:#faf5e8; }
.pv-result.selected { background:#f3ead6; }

/* Items table — billing-style with footer subtotals. */
.pv-items-wrap { background:#fbf9f4; border:1px solid #e7e2d6; border-radius:8px; padding:0; overflow-x:auto; }
.pv-items-table { width:100%; border-collapse:collapse; color:#1a1612; font-size:13px; }
.pv-items-table th, .pv-items-table td { padding:8px 10px; border-bottom:1px solid #ece5d4; text-align:left; }
.pv-items-table th { background:#f3ead6; font-weight:600; color:#1a1612; font-size:12px; vertical-align:bottom; }
.pv-items-table th.num, .pv-items-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.pv-items-table tbody tr:hover { background:#fdfaf2; }
.pv-items-table .pv-empty td { color:#888; text-align:center; padding:18px; font-style:italic; }
.pv-items-table .pv-row-qty,
.pv-items-table .pv-row-rate {
    width:80px; background:#fff; color:#1a1612; border:1px solid #d8d0c2; border-radius:4px;
    padding:4px 6px; font-size:13px; text-align:right; font-variant-numeric:tabular-nums;
}
.pv-items-table .pv-row-qty { width:70px; }
.pv-items-table .pv-row-rm {
    background:transparent; color:#a33; border:none; cursor:pointer; font-size:16px; padding:2px 6px;
}
.pv-items-table .pv-row-rm:hover { color:#c44; }
.pv-items-table tfoot td { background:#f9f1dd; font-weight:700; color:#1a1612; border-top:2px solid #e7d9b2; border-bottom:none; }
.pv-items-table tfoot tr.pv-foot-tot td { background:#f3ead6; }
.pv-items-table tfoot td.pv-foot-label { text-align:right; font-weight:600; color:#5a4d3c; }
.pv-pos-card  { background:#fff5f5; border-color:#f3c8c8; }
.pv-pos-card.ok { background:#ebf7f0; border-color:#bfe4cd; }
.pv-pos-card.bad { background:#fdecea; border-color:#f3b1a8; }

/* Aggregator-vs-slots comparison table (Step 3). */
.pv-aggr-wrap { background:#fbf9f4; border:1px solid #e7e2d6; border-radius:8px; overflow:hidden; max-width:840px; margin-bottom:14px; }
.pv-aggr-table { width:100%; border-collapse:collapse; color:#1a1612; font-size:13px; }
.pv-aggr-table th, .pv-aggr-table td { padding:9px 12px; border-bottom:1px solid #ece5d4; background:#fbf9f4; color:#1a1612; vertical-align:middle; }
.pv-aggr-table th { background:#f3ead6; font-weight:600; text-align:left; }
.pv-aggr-table th.num, .pv-aggr-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.pv-aggr-table .pv-aggr-label { width:220px; font-weight:600; color:#3a2f24; }
.pv-aggr-table tbody tr:last-child td { border-bottom:none; }
.pv-aggr-table input[type=number],
.pv-aggr-table input[type=text] {
    width:100%; background:#fff; color:#1a1612; border:1px solid #d8d0c2; border-radius:4px;
    padding:6px 8px; text-align:right; font-variant-numeric:tabular-nums; font-size:13px; box-sizing:border-box;
}
.pv-aggr-table tr.pv-aggr-derived td { background:#f4efe5; }
.pv-aggr-table tr.pv-aggr-derived td.pv-aggr-label { background:#ece4d0; color:#1a1612; }
.pv-aggr-cell.gap-ok  { color:#1a7a3d; font-weight:600; }
.pv-aggr-cell.gap-bad { color:#a83232; font-weight:600; }
</style>

<div class="page-header"><h2>New Price Variation</h2></div>

<form method="POST" id="pv-form" class="form-card" style="max-width:none" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="action" value="pv_submit">

    <!-- Step 1: Order header (partner, store, order id, order date) -->
    <div class="form-section-title">1. Order Header</div>
    <div class="form-grid" style="grid-template-columns:repeat(2,1fr);max-width:840px">
        <div class="form-group">
            <label>Partner <span class="required">*</span></label>
            <div style="display:flex;gap:24px;padding:6px 0">
                <label class="checkbox-label" style="cursor:pointer"><input type="radio" name="partner" value="swiggy" required> Swiggy</label>
                <label class="checkbox-label" style="cursor:pointer"><input type="radio" name="partner" value="zomato"> Zomato</label>
            </div>
        </div>
        <div class="form-group">
            <label>Store <span class="required">*</span></label>
            <select name="location_id" id="pv-location" class="form-control" required>
                <?php if (isSuperadmin()): ?><option value="">— pick a store —</option><?php endif; ?>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['location_id'] ?>" <?= (int)$l['location_id'] === $defaultLocId ? 'selected' : '' ?>>
                        <?= h($l['location_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Order ID <span class="required">*</span></label>
            <input type="text" name="order_id" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Order Date</label>
            <input type="date" name="order_date" class="form-control" value="<?= h(date('Y-m-d')) ?>">
        </div>
    </div>

    <!-- Step 2: Items -->
    <div class="form-section-title" style="margin-top:14px">2. Items</div>

    <!-- Reference image — shows where to read each line's per-unit rate
         on the partner's bill. Hidden until a partner is picked; swapped
         via JS based on the partner radio. -->
    <div id="pv-ref-item-card" style="display:none;margin-bottom:12px;max-width:840px;background:#fbf9f4;border:1px solid #e7e2d6;border-radius:8px;padding:10px 12px">
        <div style="font-size:11px;font-weight:600;color:#7a6f60;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px">
            Reference — where to find the per-item rate in your <span id="pv-ref-item-partner-label"></span> bill
        </div>
        <img id="pv-ref-item-swiggy" src="assets/swiggy_item.png" alt="Swiggy item rate reference"
             style="display:none;max-width:100%;height:auto;border:1px solid #e7e2d6;border-radius:6px;background:#fff">
        <img id="pv-ref-item-zomato" src="assets/zomato_item.png" alt="Zomato item rate reference"
             style="display:none;max-width:100%;height:auto;border:1px solid #e7e2d6;border-radius:6px;background:#fff">
    </div>

    <div class="pv-entry-bar">
        <div class="pv-entry-search">
            <input type="text" id="pv-search" placeholder="Search by item code or name…" autocomplete="off">
            <div id="pv-search-results" class="pv-results-box" style="display:none"></div>
        </div>
        <input type="number" id="pv-entry-qty" min="1" step="1" value="1" title="Quantity">
        <input type="number" id="pv-entry-total" min="0" step="0.01" placeholder="Total ₹ *"
               title="Line total as shown on the Swiggy/Zomato bill (required)">
        <button type="button" id="pv-entry-add" class="pv-entry-add" disabled>+ Add</button>
    </div>

    <div class="pv-items-wrap">
    <table class="pv-items-table">
        <thead>
            <tr>
                <th class="num" style="width:36px">#</th>
                <th style="width:90px">Code</th>
                <th>Item</th>
                <th class="num" style="width:80px">Qty</th>
                <th class="num" style="width:110px">Total ₹ <span class="required">*</span><br><span style="font-weight:400;font-size:10px;color:#7a6f60">partner</span></th>
                <th class="num" style="width:60px">Tax %</th>
                <?php if ($show3x): ?><th class="num" style="width:100px">3x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($show4x): ?><th class="num" style="width:100px">4x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($show5x): ?><th class="num" style="width:100px">5x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($show6x): ?><th class="num" style="width:100px">6x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($show3x): ?><th class="num" style="width:100px">3x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($show4x): ?><th class="num" style="width:100px">4x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($show5x): ?><th class="num" style="width:100px">5x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($show6x): ?><th class="num" style="width:100px">6x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <th style="width:36px"></th>
            </tr>
        </thead>
        <tbody id="pv-items-tbody">
            <tr class="pv-empty"><td colspan="<?= (int)$itemsColspan ?>">No items added yet — search above to add.</td></tr>
        </tbody>
        <tfoot>
            <tr class="pv-foot-sub">
                <td colspan="3" class="pv-foot-label">Sub-total (excl tax)</td>
                <td class="num" id="pv-tot-qty">0</td>
                <td class="num" id="pv-tot-partner">₹0.00</td>
                <td></td>
                <?php if ($show3x): ?><td class="num" id="pv-sub-3x">₹0.00</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-sub-4x">₹0.00</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-sub-5x">₹0.00</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-sub-6x">₹0.00</td><?php endif; ?>
                <td colspan="<?= (int)($activeCount + 1) ?>"></td>
            </tr>
            <tr class="pv-foot-tot">
                <td colspan="3" class="pv-foot-label">Total (incl tax)</td>
                <td colspan="<?= (int)(3 + $activeCount) ?>"></td>
                <?php if ($show3x): ?><td class="num" id="pv-tot-3x">₹0.00</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-tot-4x">₹0.00</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-tot-5x">₹0.00</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-tot-6x">₹0.00</td><?php endif; ?>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>

    <!-- Step 3: Aggregator Order Details (Partner vs 3x vs 4x comparison) -->
    <div class="form-section-title" style="margin-top:18px">3. Aggregator Order Details <span style="font-size:11px;font-weight:400;color:var(--muted)">(all amount fields required — use 0 if not applicable)</span></div>

    <!-- Reference image — shows where to read each number from on the partner's bill.
         Hidden until a partner is picked; swapped via JS based on the partner radio. -->
    <div id="pv-ref-card" style="display:none;margin-bottom:12px;max-width:840px;background:#fbf9f4;border:1px solid #e7e2d6;border-radius:8px;padding:10px 12px">
        <div style="font-size:11px;font-weight:600;color:#7a6f60;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px">
            Reference — where to find these numbers in your <span id="pv-ref-partner-label"></span> bill
        </div>
        <img id="pv-ref-swiggy" src="assets/swiggy.png" alt="Swiggy bill breakdown reference"
             style="display:none;max-width:100%;height:auto;border:1px solid #e7e2d6;border-radius:6px;background:#fff">
        <img id="pv-ref-zomato" src="assets/zomato.png" alt="Zomato bill breakdown reference"
             style="display:none;max-width:100%;height:auto;border:1px solid #e7e2d6;border-radius:6px;background:#fff">
    </div>

    <div class="pv-aggr-wrap">
    <table class="pv-aggr-table">
        <thead>
            <tr>
                <th class="pv-aggr-label"></th>
                <th class="num">Partner Details</th>
                <?php if ($show3x): ?><th class="num">3x</th><?php endif; ?>
                <?php if ($show4x): ?><th class="num">4x</th><?php endif; ?>
                <?php if ($show5x): ?><th class="num">5x</th><?php endif; ?>
                <?php if ($show6x): ?><th class="num">6x</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="pv-aggr-label">Bill Subtotal (₹) <span class="required">*</span></td>
                <td class="num"><input type="number" name="bill_subtotal"   id="pv-subtotal" step="0.01" min="0" required></td>
                <?php if ($show3x): ?><td class="num" id="pv-aggr-sub-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-aggr-sub-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-aggr-sub-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-aggr-sub-6x">—</td><?php endif; ?>
            </tr>
            <tr>
                <td class="pv-aggr-label">Discount (₹) <span class="required">*</span></td>
                <td class="num"><input type="number" name="discount_amount" id="pv-discount" step="0.01" min="0" required></td>
                <?php if ($show3x): ?><td class="num" id="pv-aggr-disc-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-aggr-disc-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-aggr-disc-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-aggr-disc-6x">—</td><?php endif; ?>
            </tr>
            <tr>
                <td class="pv-aggr-label">Taxes (₹) <span class="required">*</span></td>
                <td class="num"><input type="number" name="taxes"           id="pv-taxes"    step="0.01" min="0" required></td>
                <?php if ($show3x): ?><td class="num" id="pv-aggr-tax-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-aggr-tax-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-aggr-tax-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-aggr-tax-6x">—</td><?php endif; ?>
            </tr>
            <tr>
                <td class="pv-aggr-label">Net Received (₹) <span class="required">*</span></td>
                <td class="num"><input type="number" name="net_received"    id="pv-net"      step="0.01" min="0" required></td>
                <?php if ($show3x): ?><td class="num" id="pv-aggr-net-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num" id="pv-aggr-net-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num" id="pv-aggr-net-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num" id="pv-aggr-net-6x">—</td><?php endif; ?>
            </tr>
            <tr class="pv-aggr-derived">
                <td class="pv-aggr-label">Difference Amt</td>
                <td></td>
                <?php if ($show3x): ?><td class="num pv-aggr-cell" id="pv-aggr-diff-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num pv-aggr-cell" id="pv-aggr-diff-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num pv-aggr-cell" id="pv-aggr-diff-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num pv-aggr-cell" id="pv-aggr-diff-6x">—</td><?php endif; ?>
            </tr>
            <tr class="pv-aggr-derived">
                <td class="pv-aggr-label">Difference %</td>
                <td></td>
                <?php if ($show3x): ?><td class="num pv-aggr-cell" id="pv-aggr-diffpct-3x">—</td><?php endif; ?>
                <?php if ($show4x): ?><td class="num pv-aggr-cell" id="pv-aggr-diffpct-4x">—</td><?php endif; ?>
                <?php if ($show5x): ?><td class="num pv-aggr-cell" id="pv-aggr-diffpct-5x">—</td><?php endif; ?>
                <?php if ($show6x): ?><td class="num pv-aggr-cell" id="pv-aggr-diffpct-6x">—</td><?php endif; ?>
            </tr>
        </tbody>
    </table>
    </div>

    <div class="form-group" style="max-width:840px">
        <label>Remarks (optional)</label>
        <textarea name="remarks" rows="2" class="form-control" placeholder="Anything admin should know..."></textarea>
    </div>

    <!-- Step 4: Attachments (required) -->
    <div class="form-section-title" style="margin-top:14px">4. Attachments <span class="required">*</span> <span style="font-size:11px;font-weight:400;color:var(--muted)">(partner-app screenshot, POS bill, etc.)</span></div>
    <div class="form-group" style="max-width:840px">
        <input type="file" name="attachments[]" id="pv-attachments" accept="image/*" multiple class="form-control" required>
        <div style="font-size:11px;color:var(--muted);margin-top:4px">
            At least one image is required. Up to <?= PV_ATT_MAX_COUNT ?> images, max <?= PV_ATT_MAX_BYTES / 1024 / 1024 ?> MB each. Allowed: <?= implode(', ', PV_ATT_ALLOWED_EXT) ?>.
        </div>
        <div id="pv-att-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px"></div>
    </div>

    <div id="pv-submit-hint"
         style="display:none;margin-top:14px;padding:10px 12px;border-radius:6px;
                background:rgba(201,168,0,.10);border:1px solid rgba(201,168,0,.35);
                color:var(--yellow);font-size:13px;line-height:1.5">
        <strong>Before you can submit:</strong>
        <ul id="pv-submit-hint-list" style="margin:6px 0 0 18px;padding:0"></ul>
    </div>

    <div class="form-actions" style="margin-top:14px">
        <button type="submit" class="btn btn-primary" id="pv-submit-btn">Submit for Approval</button>
        <a href="?page=price_variations" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const fmt = (v) => '₹' + (Math.round(v * 100) / 100).toFixed(2);
    // Active slot set + items-table colspan emitted from PHP. Drives
    // which Amount/Total cells render in renderTable and which footer
    // / aggregator cells get written. setText() is null-safe so calls
    // for a hidden slot are silent no-ops rather than crashes.
    const PV_ACTIVE  = <?= json_encode($slotsActive) ?>;
    const PV_COLSPAN = <?= (int)$itemsColspan ?>;
    const slotOn = (s) => PV_ACTIVE.indexOf(s) !== -1;
    const setText = (id, val) => { const el = $(id); if (el) el.textContent = val; };
    const pctSigned = (v) => (v > 0 ? '+' : '') + (Math.round(v * 100) / 100).toFixed(2) + '%';
    const escapeHtml = (s) =>
        String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);

    // Authoritative items list. Each line: { item, qty, total }
    //   item  = { id, item_code, item_name, online_3x_price, online_4x_price, tax_pct, ...}
    //   total = line-level subtotal in ₹ as printed on the partner bill
    //           (null if not entered). Stored in partner_rate on submit —
    //           the column name is unchanged for back-compat but it now
    //           holds the line total, not a per-unit rate.
    const lines = [];
    let candidate = null; // item picked in the entry bar but not yet added

    // ── Top entry bar: search → pick → set qty/total → Add ─────
    const searchEl   = $('pv-search');
    const resultsBox = $('pv-search-results');
    const qtyEl      = $('pv-entry-qty');
    const totalEl    = $('pv-entry-total');
    const addBtn     = $('pv-entry-add');

    let searchTimer;
    searchEl.addEventListener('input', e => {
        candidate = null; addBtn.disabled = true;
        clearTimeout(searchTimer);
        const kw = e.target.value.trim();
        if (kw.length < 2) { resultsBox.style.display = 'none'; return; }
        searchTimer = setTimeout(() => doSearch(kw), 200);
    });
    searchEl.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!addBtn.disabled) addItem();
        }
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('.pv-entry-search')) resultsBox.style.display = 'none';
    });
    qtyEl.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); if (!addBtn.disabled) addItem(); }
    });
    totalEl.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); if (!addBtn.disabled) addItem(); }
    });
    addBtn.addEventListener('click', addItem);

    function doSearch(kw) {
        const fd = new FormData();
        fd.append('action', 'pv_search_items');
        fd.append('kw', kw);
        fetch('index.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok || !d.items.length) {
                    resultsBox.innerHTML = '<div style="padding:10px;color:#888;font-size:13px">No matches.</div>';
                    resultsBox.style.display = 'block'; return;
                }
                resultsBox.innerHTML = d.items.map(it => `
                    <div class="pv-result" data-id="${it.id}">
                        <div style="font-weight:600;font-size:13px;color:#1a1612">${escapeHtml(it.item_name)}</div>
                        <div style="font-size:11px;color:#666">${escapeHtml(it.item_code)}${it.swiggy_name ? ' · S: ' + escapeHtml(it.swiggy_name) : ''}${it.zomato_name ? ' · Z: ' + escapeHtml(it.zomato_name) : ''}</div>
                        <div style="font-size:11px;color:#7a6f60">3x ₹${it.online_3x_price.toFixed(2)} · 4x ₹${it.online_4x_price.toFixed(2)} · Tax ${it.tax_pct.toFixed(2)}%</div>
                    </div>
                `).join('');
                resultsBox.style.display = 'block';
                resultsBox.querySelectorAll('.pv-result').forEach(node => {
                    node.addEventListener('click', () => {
                        const id = +node.dataset.id;
                        candidate = d.items.find(x => x.id === id);
                        searchEl.value = candidate.item_name + ' (' + candidate.item_code + ')';
                        resultsBox.style.display = 'none';
                        addBtn.disabled = false;
                        qtyEl.focus();
                        qtyEl.select();
                    });
                });
            })
            .catch(() => { /* silent */ });
    }

    function addItem() {
        if (!candidate) return;
        const qty = Math.max(1, parseInt(qtyEl.value, 10) || 1);
        const totalRaw = totalEl.value.trim();
        const totalNum = parseFloat(totalRaw);
        if (totalRaw === '' || isNaN(totalNum) || totalNum <= 0) {
            alert('Total is required. Enter the line total (₹) as shown on the partner bill before adding the item.');
            totalEl.focus();
            totalEl.select();
            return;
        }
        const total = Math.max(0, totalNum);
        // If the same item is already in the table, bump its qty and
        // overwrite the total with the latest entry — last write wins.
        const existing = lines.find(l => l.item.id === candidate.id);
        if (existing) {
            existing.qty += qty;
            existing.total = total;
        } else {
            lines.push({ item: candidate, qty, total });
        }
        // Reset entry bar
        candidate = null;
        searchEl.value = '';
        qtyEl.value = '1';
        totalEl.value = '';
        addBtn.disabled = true;
        searchEl.focus();
        renderTable();
        recompute();
    }

    // ── Table render ─────────────────────────────────────
    const tbody = $('pv-items-tbody');

    function renderTable() {
        if (!lines.length) {
            tbody.innerHTML = '<tr class="pv-empty"><td colspan="' + PV_COLSPAN + '">No items added yet — search above to add.</td></tr>';
            return;
        }
        tbody.innerHTML = lines.map((l, i) => {
            const it = l.item;
            const tax = it.tax_pct;
            // price_list values are INCLUSIVE of tax.
            //   total incl tax = price × qty
            //   amount excl tax = (price × qty) / (1 + tax/100)  ← the "basic rate" sub-total
            // Each slot's cells emit only when that slot is active —
            // inactive slots stay 100% absent from the DOM, so the
            // table is narrower and updateRowDerived's class-based
            // queries can't get confused by stale indices.
            const p5x  = +(it.online_5x_price || 0);
            const p6x  = +(it.online_6x_price || 0);
            const tot3x = it.online_3x_price * l.qty;
            const tot4x = it.online_4x_price * l.qty;
            const tot5x = p5x * l.qty;
            const tot6x = p6x * l.qty;
            const amt3x = tax > 0 ? tot3x / (1 + tax / 100) : tot3x;
            const amt4x = tax > 0 ? tot4x / (1 + tax / 100) : tot4x;
            const amt5x = tax > 0 ? tot5x / (1 + tax / 100) : tot5x;
            const amt6x = tax > 0 ? tot6x / (1 + tax / 100) : tot6x;
            const totalVal = (l.total === null || l.total === undefined) ? '' : l.total;
            const amtCell = (slot, v) => slotOn(slot) ? `<td class="num pv-cell-amt-${slot}">${fmt(v)}</td>` : '';
            const totCell = (slot, v) => slotOn(slot) ? `<td class="num pv-cell-tot-${slot}">${fmt(v)}</td>` : '';
            return `
            <tr data-i="${i}">
                <td class="num">${i + 1}</td>
                <td><code style="font-size:12px">${escapeHtml(it.item_code)}</code></td>
                <td>${escapeHtml(it.item_name)}</td>
                <td class="num">
                    <input type="number" class="pv-row-qty" min="1" step="1" value="${l.qty}" data-i="${i}">
                    <input type="hidden" name="items[${i}][price_list_id]" value="${it.id}">
                    <input type="hidden" name="items[${i}][quantity]"      value="${l.qty}">
                </td>
                <td class="num">
                    <input type="number" class="pv-row-total" min="0" step="0.01" value="${totalVal}" placeholder="—" data-i="${i}">
                    <input type="hidden" name="items[${i}][partner_rate]" value="${totalVal}">
                </td>
                <td class="num">${tax.toFixed(2)}%</td>
                ${amtCell('3x', amt3x)}${amtCell('4x', amt4x)}${amtCell('5x', amt5x)}${amtCell('6x', amt6x)}
                ${totCell('3x', tot3x)}${totCell('4x', tot4x)}${totCell('5x', tot5x)}${totCell('6x', tot6x)}
                <td><button type="button" class="pv-row-rm" data-i="${i}" title="Remove">×</button></td>
            </tr>`;
        }).join('');

        // Slot cells are addressed by class (pv-cell-amt-Nx / pv-cell-tot-Nx)
        // instead of children[N] because their positional indices shift
        // with the active-slot set. setCell is a no-op when the cell
        // isn't present.
        const setCell = (tr, cls, v) => {
            const c = tr.querySelector('.' + cls);
            if (c) c.textContent = fmt(v);
        };
        const updateRowDerived = (tr, i) => {
            const it = lines[i].item;
            const tax = it.tax_pct;
            const p5x  = +(it.online_5x_price || 0);
            const p6x  = +(it.online_6x_price || 0);
            const tot3x = it.online_3x_price * lines[i].qty;
            const tot4x = it.online_4x_price * lines[i].qty;
            const tot5x = p5x * lines[i].qty;
            const tot6x = p6x * lines[i].qty;
            const amt3x = tax > 0 ? tot3x / (1 + tax / 100) : tot3x;
            const amt4x = tax > 0 ? tot4x / (1 + tax / 100) : tot4x;
            const amt5x = tax > 0 ? tot5x / (1 + tax / 100) : tot5x;
            const amt6x = tax > 0 ? tot6x / (1 + tax / 100) : tot6x;
            setCell(tr, 'pv-cell-amt-3x', amt3x);
            setCell(tr, 'pv-cell-amt-4x', amt4x);
            setCell(tr, 'pv-cell-amt-5x', amt5x);
            setCell(tr, 'pv-cell-amt-6x', amt6x);
            setCell(tr, 'pv-cell-tot-3x', tot3x);
            setCell(tr, 'pv-cell-tot-4x', tot4x);
            setCell(tr, 'pv-cell-tot-5x', tot5x);
            setCell(tr, 'pv-cell-tot-6x', tot6x);
        };

        tbody.querySelectorAll('.pv-row-qty').forEach(input => {
            input.addEventListener('input', e => {
                const i = +e.target.dataset.i;
                lines[i].qty = Math.max(1, parseInt(e.target.value, 10) || 1);
                const tr = e.target.closest('tr');
                tr.querySelector('input[name$="[quantity]"]').value = lines[i].qty;
                updateRowDerived(tr, i);
                recompute();
            });
        });
        tbody.querySelectorAll('.pv-row-total').forEach(input => {
            input.addEventListener('input', e => {
                const i = +e.target.dataset.i;
                const raw = e.target.value.trim();
                lines[i].total = raw === '' ? null : Math.max(0, parseFloat(raw) || 0);
                const tr = e.target.closest('tr');
                // Hidden field is still named partner_rate (column name
                // unchanged) — the value is the line total now.
                tr.querySelector('input[name$="[partner_rate]"]').value = raw;
                updateRowDerived(tr, i);
                recompute();
            });
        });
        tbody.querySelectorAll('.pv-row-rm').forEach(btn => {
            btn.addEventListener('click', e => {
                const i = +e.target.dataset.i;
                lines.splice(i, 1);
                renderTable();
                recompute();
            });
        });
    }

    // ── Items footer + Step 3 comparison table ───────────
    //
    // Per-item formulas (price_list values are INCLUSIVE of tax):
    //   tot_slot = price × qty                       (incl tax → net for that slot)
    //   sub_slot = tot_slot / (1 + tax_pct/100)      (basic, excl tax)
    //   tax_slot = tot_slot − sub_slot               (computed tax)
    //
    // No platform discount in the system — we just compare what the catalog
    // would total to vs what the partner actually paid.
    //
    // Difference Amt slot = partner_net − tot_slot
    // Difference %  slot  = (partner_net − tot_slot) / tot_slot × 100
    function recompute() {
        let totQty = 0, sub3x = 0, sub4x = 0, sub5x = 0, sub6x = 0,
            tot3x = 0, tot4x = 0, tot5x = 0, tot6x = 0, partnerTot = 0;
        lines.forEach(l => {
            const it = l.item;
            const tax = it.tax_pct;
            const p5x = +(it.online_5x_price || 0);
            const p6x = +(it.online_6x_price || 0);
            const t3 = it.online_3x_price * l.qty;
            const t4 = it.online_4x_price * l.qty;
            const t5 = p5x * l.qty;
            const t6 = p6x * l.qty;
            const s3 = tax > 0 ? t3 / (1 + tax / 100) : t3;
            const s4 = tax > 0 ? t4 / (1 + tax / 100) : t4;
            const s5 = tax > 0 ? t5 / (1 + tax / 100) : t5;
            const s6 = tax > 0 ? t6 / (1 + tax / 100) : t6;
            totQty += l.qty;
            tot3x  += t3;  tot4x  += t4;  tot5x  += t5;  tot6x  += t6;
            sub3x  += s3;  sub4x  += s4;  sub5x  += s5;  sub6x  += s6;
            // Partner total is now entered per-line as the line total
            // (no qty multiplication — the value IS the line subtotal).
            if (l.total !== null && l.total !== undefined) partnerTot += l.total;
        });

        // Items table footer — setText is null-safe so inactive slots
        // are silent no-ops (their cells simply don't exist in the DOM).
        setText('pv-tot-qty',     totQty);
        setText('pv-tot-partner', fmt(partnerTot));
        setText('pv-sub-3x', fmt(sub3x));
        setText('pv-sub-4x', fmt(sub4x));
        setText('pv-sub-5x', fmt(sub5x));
        setText('pv-sub-6x', fmt(sub6x));
        setText('pv-tot-3x', fmt(tot3x));
        setText('pv-tot-4x', fmt(tot4x));
        setText('pv-tot-5x', fmt(tot5x));
        setText('pv-tot-6x', fmt(tot6x));

        // Step 3 — slot columns. Discount row stays "—" (no system discount).
        setText('pv-aggr-sub-3x', fmt(sub3x));
        setText('pv-aggr-sub-4x', fmt(sub4x));
        setText('pv-aggr-sub-5x', fmt(sub5x));
        setText('pv-aggr-sub-6x', fmt(sub6x));
        setText('pv-aggr-disc-3x', '—');
        setText('pv-aggr-disc-4x', '—');
        setText('pv-aggr-disc-5x', '—');
        setText('pv-aggr-disc-6x', '—');
        setText('pv-aggr-tax-3x', fmt(tot3x - sub3x));
        setText('pv-aggr-tax-4x', fmt(tot4x - sub4x));
        setText('pv-aggr-tax-5x', fmt(tot5x - sub5x));
        setText('pv-aggr-tax-6x', fmt(tot6x - sub6x));
        setText('pv-aggr-net-3x', fmt(tot3x));
        setText('pv-aggr-net-4x', fmt(tot4x));
        setText('pv-aggr-net-5x', fmt(tot5x));
        setText('pv-aggr-net-6x', fmt(tot6x));

        // Step 3 — derived rows (partner_net − slot_total)
        const partnerNet = parseFloat($('pv-net').value) || 0;
        function setDiff(slot, slotNet) {
            const amtCell = $('pv-aggr-diff-' + slot);
            const pctCell = $('pv-aggr-diffpct-' + slot);
            if (!amtCell && !pctCell) return; // slot inactive — skip
            const diff = partnerNet - slotNet;
            const pct  = slotNet > 0 ? (diff / slotNet) * 100 : 0;
            if (amtCell) amtCell.textContent = slotNet > 0 ? fmt(diff)      : '—';
            if (pctCell) pctCell.textContent = slotNet > 0 ? pctSigned(pct) : '—';
            for (const cell of [amtCell, pctCell]) {
                if (!cell) continue;
                cell.classList.remove('gap-ok','gap-bad');
                if (slotNet > 0 && partnerNet > 0) {
                    if      (Math.abs(pct) < 1.5) cell.classList.add('gap-ok');
                    else if (Math.abs(pct) > 5)   cell.classList.add('gap-bad');
                }
            }
        }
        setDiff('3x', tot3x);
        setDiff('4x', tot4x);
        setDiff('5x', tot5x);
        setDiff('6x', tot6x);

        // Submit-button state. The button stays clickable so the user
        // gets an alert() listing what's missing instead of a silent
        // disabled state — the inline yellow hint below is the live
        // version of the same list. Only locks while imagery is being
        // compressed (real wait state).
        const compressing = (typeof attCompressing !== 'undefined' && attCompressing);
        $('pv-submit-btn').disabled = compressing;

        // Inline hint list — refreshed live as the user fills the form.
        const missing = computeMissing();
        const hintEl  = $('pv-submit-hint');
        const listEl  = $('pv-submit-hint-list');
        if (hintEl && listEl) {
            if (missing.length === 0) {
                hintEl.style.display = 'none';
                listEl.innerHTML = '';
            } else {
                listEl.innerHTML = missing.map(m =>
                    '<li>' + String(m).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '</li>'
                ).join('');
                hintEl.style.display = '';
            }
        }
    }

    // What's still missing from the form? Returns an array of plain
    // English sentences. Empty array = ready to submit. Used by both
    // the inline hint (recompute) and the click-time alert() in the
    // capture-phase submit listener.
    function computeMissing() {
        const partnerPicked = !!document.querySelector('input[name="partner"]:checked');
        const orderIdOk     = !!document.querySelector('input[name="order_id"]').value.trim();
        const filled        = id => $(id).value.trim() !== '';
        const aggrFilled    = filled('pv-subtotal') && filled('pv-discount') && filled('pv-taxes') && filled('pv-net');
        const subOk         = parseFloat($('pv-subtotal').value) > 0;
        const partnerNetVal = parseFloat($('pv-net').value) || 0;
        const netOk         = partnerNetVal > 0;
        const attElGate     = $('pv-attachments');
        const attOk         = !!(attElGate && attElGate.files && attElGate.files.length > 0);
        const compressing   = (typeof attCompressing !== 'undefined' && attCompressing);

        // Every added item needs a partner Total filled in — otherwise
        // the partner sub-total cell shows ₹0.00 and we can't compute
        // accurate variances downstream.
        const itemsMissingTotal = lines.filter(l => l.total === null || l.total === undefined || l.total === '');
        const missingTotalNames = itemsMissingTotal
            .map(l => (l.item && l.item.item_name) ? l.item.item_name : (l.item && l.item.item_code) || '')
            .filter(Boolean);

        const missing = [];
        if (!partnerPicked)     missing.push('Pick a partner (Swiggy or Zomato).');
        if (lines.length === 0) missing.push('Add at least one item to the bill.');
        if (itemsMissingTotal.length > 0) {
            missing.push('Enter the partner Total (₹) for ' +
                (missingTotalNames.length > 0
                    ? missingTotalNames.length + ' item(s): ' + missingTotalNames.slice(0, 3).join(', ') +
                      (missingTotalNames.length > 3 ? ', …' : '')
                    : 'every added item') + '.');
        }
        if (!orderIdOk)         missing.push('Enter the Order ID.');
        if (!aggrFilled)        missing.push('Fill all four aggregator amounts (Bill Subtotal, Discount, Taxes, Net Received). Use 0 if not applicable.');
        else if (!subOk)        missing.push('Bill Subtotal must be greater than 0.');
        else if (!netOk)        missing.push('Net Received must be greater than 0.');
        if (!attOk)             missing.push('Attach at least one image (partner-app screenshot, POS bill, etc.).');
        if (compressing)        missing.push('Image compression is still running — please wait a moment.');
        return missing;
    }

    // Reference-image swap: show the bill-breakdown screenshot for the
    // currently selected partner. Hidden until a partner is picked.
    function syncRefImage() {
        const sel = document.querySelector('input[name="partner"]:checked');
        const partnerLbl = sel ? (sel.value === 'swiggy' ? 'Swiggy' : 'Zomato') : '';
        // Step 3 — aggregator-bill breakdown reference
        const card = document.getElementById('pv-ref-card');
        const sw   = document.getElementById('pv-ref-swiggy');
        const zo   = document.getElementById('pv-ref-zomato');
        const lbl  = document.getElementById('pv-ref-partner-label');
        if (card && sw && zo) {
            if (!sel) { card.style.display = 'none'; }
            else {
                card.style.display = '';
                sw.style.display = sel.value === 'swiggy' ? '' : 'none';
                zo.style.display = sel.value === 'zomato' ? '' : 'none';
                if (lbl) lbl.textContent = partnerLbl;
            }
        }
        // Step 2 — per-item rate reference
        const iCard = document.getElementById('pv-ref-item-card');
        const iSw   = document.getElementById('pv-ref-item-swiggy');
        const iZo   = document.getElementById('pv-ref-item-zomato');
        const iLbl  = document.getElementById('pv-ref-item-partner-label');
        if (iCard && iSw && iZo) {
            if (!sel) { iCard.style.display = 'none'; }
            else {
                iCard.style.display = '';
                iSw.style.display = sel.value === 'swiggy' ? '' : 'none';
                iZo.style.display = sel.value === 'zomato' ? '' : 'none';
                if (iLbl) iLbl.textContent = partnerLbl;
            }
        }
    }
    document.querySelectorAll('input[name="partner"]').forEach(r => r.addEventListener('change', () => { syncRefImage(); recompute(); }));
    syncRefImage();
    ['pv-net','pv-subtotal','pv-discount','pv-taxes'].forEach(id => $(id).addEventListener('input', recompute));
    document.querySelector('input[name="order_id"]').addEventListener('input', recompute);

    // ── Attachment preview + client-side compression ─────
    // Phone photos of partner-app screenshots / POS bills routinely run
    // 4–8 MB each; submitting six of them on patchy LTE was the same
    // upload-fail trap we hit on the bank-deposit form. Same recipe:
    // images larger than 600 KB get downscaled to a 1600 px long edge
    // and re-encoded as JPEG q=0.75; smaller files and non-images pass
    // through untouched. Submit is gated until compression finishes.
    const attEl = $('pv-attachments');
    const attPreview = $('pv-att-preview');
    let attCompressing = false;
    const ATT_MAX_EDGE     = 1600;
    const ATT_SKIP_BELOW   = 600 * 1024;
    const ATT_JPEG_QUALITY = 0.75;
    const ATT_IMAGE_RE     = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;

    function fmtAttSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(2) + ' MB';
    }
    function setAttFiles(arr) {
        try {
            const dt = new DataTransfer();
            arr.forEach(f => dt.items.add(f));
            attEl.files = dt.files;
            return true;
        } catch (e) { return false; }
    }
    function decodeImg(file) {
        if (typeof createImageBitmap === 'function') {
            try { return createImageBitmap(file, { imageOrientation: 'from-image' }); }
            catch (e) { return createImageBitmap(file); }
        }
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload  = () => { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('decode failed')); };
            img.src = url;
        });
    }
    function compressOne(file) {
        if (!ATT_IMAGE_RE.test(file.type)) return Promise.resolve(file);
        if (file.size <= ATT_SKIP_BELOW)   return Promise.resolve(file);
        return decodeImg(file).then(bmp => {
            const w = bmp.width || bmp.naturalWidth;
            const h = bmp.height || bmp.naturalHeight;
            if (!w || !h) return file;
            const scale = Math.min(1, ATT_MAX_EDGE / Math.max(w, h));
            const tw = Math.round(w * scale), th = Math.round(h * scale);
            const canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            canvas.getContext('2d').drawImage(bmp, 0, 0, tw, th);
            return new Promise(resolve => {
                canvas.toBlob(blob => {
                    if (!blob || blob.size >= file.size) { resolve(file); return; }
                    const nameBase = (file.name || 'photo').replace(/\.(png|jpe?g|gif|webp|heic|heif)$/i, '');
                    resolve(new File([blob], nameBase + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', ATT_JPEG_QUALITY);
            });
        }).catch(() => file);
    }

    function renderAttPreview(files, statusMsg, statusColor) {
        attPreview.innerHTML = '';
        if (statusMsg) {
            const note = document.createElement('div');
            note.style.cssText = 'flex:1;font-size:12px;align-self:center;color:' + (statusColor || '#7a6f60') + ';width:100%';
            note.textContent = statusMsg;
            attPreview.appendChild(note);
        }
        files.slice(0, <?= PV_ATT_MAX_COUNT ?>).forEach(f => {
            if (!f.type.startsWith('image/')) return;
            const url = URL.createObjectURL(f);
            const wrap = document.createElement('div');
            wrap.style.cssText = 'border:1px solid #d8d0c2;border-radius:6px;overflow:hidden;background:#fff;width:100px;';
            wrap.innerHTML = `
                <img src="${url}" style="display:block;width:100px;height:80px;object-fit:cover">
                <div style="font-size:10px;color:#666;padding:3px 5px;text-align:center;background:#fbf9f4;color:#1a1612" title="${escapeHtml(f.name)}">
                    ${escapeHtml(f.name.length > 14 ? f.name.slice(0, 12) + '…' : f.name)}
                </div>
            `;
            attPreview.appendChild(wrap);
        });
        if (files.length > <?= PV_ATT_MAX_COUNT ?>) {
            const warn = document.createElement('div');
            warn.style.cssText = 'flex:1;color:#a83232;font-size:12px;align-self:center;width:100%';
            warn.textContent = `Only the first <?= PV_ATT_MAX_COUNT ?> images will be uploaded (you picked ${files.length}).`;
            attPreview.appendChild(warn);
        }
    }

    if (attEl) {
        attEl.addEventListener('change', () => {
            const original = Array.from(attEl.files || []);
            if (!original.length) {
                attPreview.innerHTML = '';
                recompute();
                return;
            }
            const origTotal = original.reduce((n, f) => n + f.size, 0);
            const compressableAny = original.some(f => ATT_IMAGE_RE.test(f.type) && f.size > ATT_SKIP_BELOW);
            if (!compressableAny) {
                renderAttPreview(original);
                recompute();
                return;
            }
            attCompressing = true;
            recompute(); // force-disable submit while we work
            renderAttPreview(original, 'Compressing photo(s)…');
            Promise.all(original.map(compressOne)).then(out => {
                const newTotal = out.reduce((n, f) => n + f.size, 0);
                if (!setAttFiles(out)) {
                    // Browser refused to swap input.files — fall through with originals.
                    renderAttPreview(original, 'Could not replace selected files — uploading originals (' + fmtAttSize(origTotal) + ').', '#c47a32');
                } else if (newTotal < origTotal) {
                    renderAttPreview(out,
                        out.length + ' file(s) ready — ' + fmtAttSize(origTotal) + ' → ' + fmtAttSize(newTotal)
                            + ' (' + Math.round((1 - newTotal / origTotal) * 100) + '% smaller).',
                        '#1a7a3d');
                } else {
                    renderAttPreview(out, out.length + ' file(s) ready — ' + fmtAttSize(newTotal));
                }
            }).catch(err => {
                renderAttPreview(original, 'Compression failed — uploading originals (' + fmtAttSize(origTotal) + ').' + (err && err.message ? ' ' + err.message : ''), '#c47a32');
            }).then(() => {
                attCompressing = false;
                recompute();
            });
        });
    }

    // Final safety net: if anything bypasses the disabled-submit gate
    // (script-disabled fallback, exotic browser, etc.), the form still
    // refuses to POST while compression is mid-flight.
    const pvForm = document.getElementById('pv-form');
    if (pvForm) {
        pvForm.addEventListener('submit', e => {
            if (attCompressing) {
                e.preventDefault();
                renderAttPreview(Array.from(attEl.files || []), 'Still compressing — please wait a moment and try again.', '#c47a32');
                return;
            }
            // If anything is missing, abort and pop a clear alert listing
            // every requirement. Capture phase fires before the global
            // double-submit guard, so preventDefault here also stops the
            // guard from marking the form as in-flight.
            const missing = computeMissing();
            if (missing.length > 0) {
                e.preventDefault();
                alert('Please fix the following before submitting:\n\n• ' + missing.join('\n• '));
                // Scroll the inline hint into view so the user can also
                // see the warning persistently after dismissing the alert.
                const hintEl = document.getElementById('pv-submit-hint');
                if (hintEl) hintEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, true);
    }

    // Initial paint
    renderTable();
    recompute();
    searchEl.focus();
})();
</script>
<?php
}

// ── Page: Edit existing variation (store manager) ───────
// Stripped-down twin of the new-variation form. Identity fields
// (partner / store / order_id) are shown read-only; items are loaded as
// editable rows with qty + rate inputs and a "remove" button; aggregator
// totals + remarks + order date are pre-populated. Attachments are
// add-only — existing attachments are listed for context. Submitting
// posts to pv_update, which routes through doUpdatePriceVariation().
function pagePriceVariationEdit(): void {
    $id  = (int)($_GET['id'] ?? 0);
    $v   = pvGetVariation($id);
    if (!$v) { echo '<div class="alert alert-error">Variation not found.</div>'; return; }
    if (!pvCanEdit($v)) { echo '<div class="alert alert-error">You cannot edit this variation.</div>'; return; }

    $items       = pvGetVariationItems($id);
    $attachments = pvGetAttachments($id);
    $wasConfirmed = ($v['status'] === 'confirmed');
?>
<style>
.pv-card { background:#f9f7f2; border:1px solid #e7e2d6; border-radius:8px; padding:12px; color:#1a1612; }
.pv-card .pv-label { font-size:11px; color:#7a6f60; font-weight:500; letter-spacing:.04em; text-transform:uppercase; }
.pv-card .pv-value { font-size:16px; font-weight:700; color:#1a1612; line-height:1.2; }
.pve-items-wrap { background:#fbf9f4; border:1px solid #e7e2d6; border-radius:8px; overflow-x:auto; }
.pve-items-tbl { width:100%; border-collapse:collapse; color:#1a1612; font-size:13px; }
.pve-items-tbl th, .pve-items-tbl td { padding:8px 10px; border-bottom:1px solid #ece5d4; text-align:left; }
.pve-items-tbl th { background:#f3ead6; font-weight:600; font-size:12px; }
.pve-items-tbl tbody tr:last-child td { border-bottom:none; }
.pve-items-tbl td.num, .pve-items-tbl th.num { text-align:right; font-variant-numeric:tabular-nums; }
.pve-items-tbl input.pve-qty,
.pve-items-tbl input.pve-rate {
    width:90px; background:#fff; color:#1a1612; border:1px solid #d8d0c2; border-radius:4px;
    padding:4px 6px; font-size:13px; text-align:right; font-variant-numeric:tabular-nums;
}
.pve-items-tbl input.pve-qty { width:70px; }
.pve-items-tbl .pve-rm {
    background:transparent; color:#a33; border:none; cursor:pointer; font-size:16px; padding:2px 6px;
}
.pve-aggr-wrap { background:#fbf9f4; border:1px solid #e7e2d6; border-radius:8px; padding:0; overflow:hidden; max-width:560px; }
.pve-aggr-tbl { width:100%; border-collapse:collapse; color:#1a1612; font-size:13px; }
.pve-aggr-tbl th, .pve-aggr-tbl td { padding:8px 12px; border-bottom:1px solid #ece5d4; background:#fbf9f4; }
.pve-aggr-tbl tr:last-child td { border-bottom:none; }
.pve-aggr-tbl td.pv-aggr-label { width:240px; font-weight:600; color:#3a2f24; }
.pve-aggr-tbl input { width:100%; background:#fff; color:#1a1612; border:1px solid #d8d0c2;
    border-radius:4px; padding:6px 8px; text-align:right; font-variant-numeric:tabular-nums; box-sizing:border-box; }
.pve-att-list { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0 0; padding:0; list-style:none; }
.pve-att-list li { background:rgba(255,255,255,.04); border:1px solid var(--border); border-radius:6px;
    padding:4px 10px; font-size:12px; color:var(--muted); }
</style>

<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">Edit Price Variation #<?= (int)$v['id'] ?></h2>
    <?= pvStatusBadge($v['status']) ?>
    <div style="margin-left:auto">
        <a href="?page=price_variation_detail&id=<?= (int)$v['id'] ?>" class="btn btn-ghost">← Back to detail</a>
    </div>
</div>

<?php if ($wasConfirmed): ?>
<div class="alert" style="margin-bottom:14px;background:rgba(201,168,0,.10);color:var(--yellow);border:1px solid rgba(201,168,0,.35);padding:10px 14px;border-radius:6px">
    <strong>Heads up:</strong> this variation is already confirmed. Saving any change will return it to <strong>pending</strong> and clear the existing POC confirmation — it will need to be confirmed again.
</div>
<?php endif; ?>

<form method="POST" class="form-card" style="max-width:none" enctype="multipart/form-data">
    <input type="hidden" name="action" value="pv_update">
    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">

    <!-- Locked identity fields, shown for context only. -->
    <div class="form-section-title">Order</div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
        <div class="pv-card"><div class="pv-label">Store</div><div class="pv-value"><?= h($v['location_name']) ?></div></div>
        <div class="pv-card"><div class="pv-label">Partner</div><div class="pv-value"><?= h(ucfirst($v['partner'])) ?></div></div>
        <div class="pv-card"><div class="pv-label">Order ID</div><div class="pv-value"><code><?= h($v['order_id']) ?></code></div></div>
        <div class="pv-card">
            <div class="pv-label">Order Date</div>
            <input type="date" name="order_date" class="form-control"
                   value="<?= h($v['order_date'] ?? '') ?>"
                   style="background:#fff;color:#1a1612;border:1px solid #d8d0c2;margin-top:2px">
        </div>
    </div>

    <!-- Items: editable qty + rate, removable row. No add-new-item search for v1. -->
    <div class="form-section-title">Items <span style="font-size:11px;font-weight:400;color:var(--muted)">— edit qty / rate, remove rows; submit must leave at least one line</span></div>
    <?php
        // Slot visibility on the edit form mirrors the new form (active
        // flag controls which slot Total columns appear).
        $edShow3x = pvShowSlot('3x');
        $edShow4x = pvShowSlot('4x');
        $edShow5x = pvShowSlot('5x');
        $edShow6x = pvShowSlot('6x');
    ?>
    <div class="pve-items-wrap" style="margin-bottom:14px">
        <table class="pve-items-tbl" id="pve-items">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Total ₹ <span style="font-weight:400;color:#7a6f60;font-size:10px">(partner)</span></th>
                    <th class="num">Tax %</th>
                    <?php if ($edShow3x): ?><th class="num">3x Total ₹</th><?php endif; ?>
                    <?php if ($edShow4x): ?><th class="num">4x Total ₹</th><?php endif; ?>
                    <?php if ($edShow5x): ?><th class="num">5x Total ₹</th><?php endif; ?>
                    <?php if ($edShow6x): ?><th class="num">6x Total ₹</th><?php endif; ?>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $it):
                $pRate = isset($it['partner_rate']) && $it['partner_rate'] !== null ? (float)$it['partner_rate'] : 0;
                $exp5  = pvHas5xCol() ? (float)($it['expected_5x'] ?? 0) : 0;
                $exp6  = pvHas6xCol() ? (float)($it['expected_6x'] ?? 0) : 0;
            ?>
                <tr>
                    <td>
                        <?= h($it['item_name']) ?> <span style="color:#999">(<?= h($it['item_code']) ?>)</span>
                        <input type="hidden" name="items[<?= $i ?>][price_list_id]" value="<?= (int)$it['price_list_id'] ?>">
                    </td>
                    <td class="num">
                        <input type="number" class="pve-qty" min="1" step="1"
                               name="items[<?= $i ?>][quantity]" value="<?= (int)$it['quantity'] ?>" required>
                    </td>
                    <td class="num">
                        <input type="number" class="pve-rate" min="0" step="0.01"
                               name="items[<?= $i ?>][partner_rate]" value="<?= h(number_format($pRate, 2, '.', '')) ?>" required>
                    </td>
                    <td class="num"><?= h(number_format((float)$it['tax_pct'], 2)) ?></td>
                    <?php if ($edShow3x): ?><td class="num"><?= h(number_format((float)$it['expected_3x'], 2)) ?></td><?php endif; ?>
                    <?php if ($edShow4x): ?><td class="num"><?= h(number_format((float)$it['expected_4x'], 2)) ?></td><?php endif; ?>
                    <?php if ($edShow5x): ?><td class="num"><?= h(number_format($exp5, 2)) ?></td><?php endif; ?>
                    <?php if ($edShow6x): ?><td class="num"><?= h(number_format($exp6, 2)) ?></td><?php endif; ?>
                    <td><button type="button" class="pve-rm" title="Remove">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Aggregator totals — pre-populated, editable. -->
    <div class="form-section-title">Aggregator Order Details <span style="font-size:11px;font-weight:400;color:var(--muted)">(all required; use 0 for none)</span></div>
    <div class="pve-aggr-wrap" style="margin-bottom:14px">
        <table class="pve-aggr-tbl">
            <tbody>
                <tr><td class="pv-aggr-label">Bill Subtotal (₹)</td>
                    <td><input type="number" name="bill_subtotal"   step="0.01" min="0" required
                               value="<?= h(number_format((float)$v['bill_subtotal'],   2, '.', '')) ?>"></td></tr>
                <tr><td class="pv-aggr-label">Discount (₹)</td>
                    <td><input type="number" name="discount_amount" step="0.01" min="0" required
                               value="<?= h(number_format((float)$v['discount_amount'], 2, '.', '')) ?>"></td></tr>
                <tr><td class="pv-aggr-label">Taxes (₹)</td>
                    <td><input type="number" name="taxes"           step="0.01" min="0" required
                               value="<?= h(number_format((float)$v['taxes'],           2, '.', '')) ?>"></td></tr>
                <tr><td class="pv-aggr-label">Net Received (₹)</td>
                    <td><input type="number" name="net_received"    step="0.01" min="0" required
                               value="<?= h(number_format((float)$v['net_received'],    2, '.', '')) ?>"></td></tr>
            </tbody>
        </table>
    </div>

    <div class="form-group" style="max-width:840px">
        <label>Remarks (optional)</label>
        <textarea name="remarks" rows="2" class="form-control" placeholder="Anything admin should know..."><?= h($v['remarks'] ?? '') ?></textarea>
    </div>

    <!-- Attachments: existing ones listed (kept as-is), plus optional new uploads. -->
    <div class="form-section-title" style="margin-top:14px">Attachments</div>
    <?php if ($attachments): ?>
    <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Existing (kept as-is):</div>
    <ul class="pve-att-list">
        <?php foreach ($attachments as $a): ?>
        <li>📎 <?= h($a['original_name']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div class="form-group" style="max-width:840px;margin-top:10px">
        <label>Add more attachments (optional)</label>
        <input type="file" name="attachments[]" accept="image/*" multiple class="form-control">
        <div style="font-size:11px;color:var(--muted);margin-top:4px">
            Up to <?= PV_ATT_MAX_COUNT ?> total. Max <?= PV_ATT_MAX_BYTES / 1024 / 1024 ?> MB each.
            Allowed: <?= implode(', ', PV_ATT_ALLOWED_EXT) ?>.
        </div>
    </div>

    <div class="form-actions" style="margin-top:14px">
        <button type="submit" class="btn btn-primary">
            <?= $wasConfirmed ? 'Save &amp; send back for re-confirmation' : 'Save Changes' ?>
        </button>
        <a href="?page=price_variation_detail&id=<?= (int)$v['id'] ?>" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script>
(function(){
    var tbody = document.querySelector('#pve-items tbody');
    if (!tbody) return;
    tbody.addEventListener('click', function(e){
        var btn = e.target.closest('.pve-rm');
        if (!btn) return;
        var rows = tbody.querySelectorAll('tr');
        if (rows.length <= 1) {
            alert('At least one line item is required.');
            return;
        }
        btn.closest('tr').remove();
    });
})();
</script>
<?php
}

// ── Page: Variations list ───────────────────────────────
function pagePriceVariationsList(): void {
    if (!pvCanSubmit()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    // Filter criteria (store, partner, status, order id, date range) are
    // resolved by the shared helper — which also applies the defaults
    // (current month-to-date window, in-flight status queue) — so the
    // "Export CSV" button below produces a download for the EXACT same
    // selection. $statusOptionsAll is still needed locally to render the
    // status checkbox dropdown.
    $f = pvResolveListFilter();
    $statusOptionsAll = pvHasConfirmCols()
        ? ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'approved' => 'Approved', 'rejected' => 'Rejected']
        : ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
    $statusFilter = $f['status'];
    $viewClicked = !empty($_GET['view']);
    $listResult  = $viewClicked
        ? pvGetVariations($f)
        : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => PV_PAGE_SIZE, 'pages' => 1];
    $rows  = $listResult['rows'];
    $total = $listResult['total'];
    $page  = $listResult['page'];
    $pages = $listResult['pages'];
    $locs  = pvCanSeeAll() ? getActiveLocations() : [];
?>
<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">Price Variations</h2>
    <?php if ($viewClicked): ?>
    <span style="font-size:12px;color:var(--muted)">
        <?= (int)$total ?> total
        <?php if ($pages > 1): ?> · page <?= (int)$page ?> of <?= (int)$pages ?><?php endif; ?>
    </span>
    <?php endif; ?>
    <?php if (pvCanCreate()): ?>
    <div style="margin-left:auto">
        <a href="?page=price_variation_new" class="btn btn-primary">New Variation</a>
    </div>
    <?php endif; ?>
</div>

<?php if (isSuperadmin()): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;border-left:3px solid var(--red)">
    <div class="form-section-title" style="color:var(--red)">⚠️ Delete Price Variations for a Month</div>
    <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
          onsubmit="return confirm('Delete ALL price variations (and their items + attachments) submitted in the selected month across every store? This cannot be undone.');">
        <input type="hidden" name="action" value="delete_price_variations_by_month">
        <div class="form-group">
            <label>Month</label>
            <select name="month" class="form-control" style="width:140px" required>
                <?php $curM = (int)date('m'); for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $curM ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select name="year" class="form-control" style="width:100px" required>
                <?php $curY = (int)date('Y'); for ($y = 2024; $y <= $curY + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $curY ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-danger-solid"
                title="Delete all price variations (and their attachment files) submitted in the selected month">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6"/>
                <path d="M14 11v6"/>
                <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
            </svg>
            Delete Month Data
        </button>
    </form>
</div>
<?php endif; ?>

<form method="GET" class="form-card" style="max-width:none;margin-bottom:16px">
    <input type="hidden" name="page" value="price_variations">
    <input type="hidden" name="view" value="1">
    <div class="form-grid" style="grid-template-columns:repeat(<?= pvCanSeeAll() ? 6 : 5 ?>,1fr)">
        <?php if (pvCanSeeAll()): ?>
        <div class="form-group">
            <label>Store</label>
            <select name="location_id" class="form-control">
                <option value="">All</option>
                <?php foreach ($locs as $l): ?>
                <option value="<?= (int)$l['location_id'] ?>" <?= (int)$f['location_id'] === (int)$l['location_id'] ? 'selected' : '' ?>>
                    <?= h($l['location_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Partner</label>
            <select name="partner" class="form-control">
                <option value="">All</option>
                <option value="swiggy" <?= $f['partner']==='swiggy'?'selected':'' ?>>Swiggy</option>
                <option value="zomato" <?= $f['partner']==='zomato'?'selected':'' ?>>Zomato</option>
            </select>
        </div>
        <?php
        $pvStatusCount = count($statusFilter);
        $pvStatusBtnLabel = $pvStatusCount === 0
            ? 'None selected'
            : ($pvStatusCount === count($statusOptionsAll)
                ? 'All statuses'
                : $pvStatusCount . ' selected');
        ?>
        <div class="form-group" style="position:relative">
            <label>Status</label>
            <button type="button" id="pv-status-btn" class="form-control"
                    style="text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:6px">
                <span id="pv-status-label"><?= h($pvStatusBtnLabel) ?></span>
                <span style="color:var(--muted);font-size:10px">▾</span>
            </button>
            <div id="pv-status-panel"
                 style="display:none;position:absolute;top:calc(100% + 4px);left:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:6px 0;z-index:100;min-width:200px;box-shadow:0 8px 20px rgba(0,0,0,.35)">
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer">
                    <input type="checkbox" id="pv-status-all">
                    <span>Select all</span>
                </label>
                <?php foreach ($statusOptionsAll as $val => $label): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px">
                    <input type="checkbox" class="pv-status-cb" name="status[]" value="<?= h($val) ?>"
                           <?= in_array($val, $statusFilter, true) ? 'checked' : '' ?>>
                    <span><?= h($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label>Order ID</label>
            <span class="input-clear-wrap" style="display:flex">
                <input type="text" name="order_id" class="form-control" value="<?= h($f['order_id']) ?>">
                <button type="button" class="input-clear-btn" aria-label="Clear order ID" tabindex="-1">&times;</button>
            </span>
        </div>
        <div class="form-group">
            <label>From</label>
            <input type="date" id="pv-from-date" name="from_date" class="form-control" value="<?= h($f['from_date']) ?>">
        </div>
        <div class="form-group">
            <label>To</label>
            <input type="date" id="pv-to-date" name="to_date" class="form-control" value="<?= h($f['to_date']) ?>">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn btn-secondary">View</button>
        <a href="?page=price_variations" class="btn btn-ghost">Reset</a>
        <?php if ($viewClicked): ?>
        <?php // Carry the current filter criteria (minus page routing + pagination) to the export. ?>
        <a href="?page=export_price_variations&<?= h(http_build_query(array_filter($_GET, fn($k) => !in_array($k, ['page','p'], true), ARRAY_FILTER_USE_KEY))) ?>"
           class="btn btn-secondary" target="_blank">Export CSV</a>
        <?php endif; ?>
    </div>
</form>
<script>
(function () {
    var fromEl = document.getElementById('pv-from-date');
    var toEl   = document.getElementById('pv-to-date');
    if (!fromEl || !toEl) return;
    fromEl.addEventListener('change', function () {
        if (!fromEl.value) return;
        var parts = fromEl.value.split('-');
        if (parts.length !== 3) return;
        var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
        if (!y || !m) return;
        var last = new Date(y, m, 0);
        var yyyy = last.getFullYear();
        var mm   = ('0' + (last.getMonth() + 1)).slice(-2);
        var dd   = ('0' + last.getDate()).slice(-2);
        toEl.value = yyyy + '-' + mm + '-' + dd;
    });
})();

// Status checkbox-dropdown — same widget as audit_summary / issues:
// click button to toggle, click outside to close, "Select all" toggles
// every status, button label reflects the current selection count.
(function () {
    var btn   = document.getElementById('pv-status-btn');
    var panel = document.getElementById('pv-status-panel');
    var label = document.getElementById('pv-status-label');
    var all   = document.getElementById('pv-status-all');
    if (!btn || !panel || !label || !all) return;
    var boxes = panel.querySelectorAll('.pv-status-cb');

    function syncLabel() {
        var n = 0;
        boxes.forEach(function (b) { if (b.checked) n++; });
        if (n === 0)                  label.textContent = 'None selected';
        else if (n === boxes.length)  label.textContent = 'All statuses';
        else                          label.textContent = n + ' selected';
        all.checked = (n === boxes.length);
        all.indeterminate = (n > 0 && n < boxes.length);
    }
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { panel.style.display = 'none'; });
    all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        syncLabel();
    });
    boxes.forEach(function (b) { b.addEventListener('change', syncLabel); });
    syncLabel();
})();
</script>

<?php if (!$viewClicked): ?>
<div class="rpt-prompt">Choose filters above and click <strong>View</strong> to load results.</div>
<?php else: ?>
<?php
// Variance columns follow the admin's active-slot config (PriceSlotsActive)
// just like the new-variation form and detail page — so if 3x + 5x are
// active, the list shows "vs 3x" / "vs 5x", not the old hardcoded 3x/4x.
$listSlots = array_values(array_filter(PV_ALL_SLOTS, 'pvShowSlot'));
if (!$listSlots) $listSlots = ['3x', '4x']; // defensive: never render zero variance columns
// Fixed cols (Submitted, Order Date, Store, Partner, Order, Items, Net,
// Status, Approver Comment, action) = 10, plus one variance column per active slot.
$listColspan = 10 + count($listSlots);
?>
<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Submitted</th>
            <th>Order Date</th>
            <th>Store</th>
            <th>Partner</th>
            <th>Order</th>
            <th style="text-align:right">Items</th>
            <th style="text-align:right">Net</th>
            <?php foreach ($listSlots as $slot): ?>
            <th style="text-align:right">vs <?= h($slot) ?></th>
            <?php endforeach; ?>
            <th>Status</th>
            <th>Approver Comment</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= (int)$listColspan ?>" class="empty-row">No variations match those filters.</td></tr>
    <?php else: foreach ($rows as $r): ?>
        <tr>
            <td><?= h(date('d M Y H:i', strtotime($r['submitted_at']))) ?></td>
            <td><?= !empty($r['order_date']) ? h(date('d M Y', strtotime($r['order_date']))) : '<span style="color:#888">—</span>' ?></td>
            <td><?= h($r['location_name']) ?></td>
            <td><?= ucfirst(h($r['partner'])) ?></td>
            <td><code><?= h($r['order_id']) ?></code></td>
            <td style="text-align:right"><?= (int)$r['items_count'] ?></td>
            <td style="text-align:right"><?= pvFmtMoney((float)$r['net_received']) ?></td>
            <?php foreach ($listSlots as $slot):
                $v    = (float)($r['variance_' . $slot] ?? 0);
                $vpct = (float)($r['variance_' . $slot . '_pct'] ?? 0);
                $col  = $v < -0.005 ? '#c44' : ($v > 0.005 ? '#3a8' : '#666');
            ?>
            <td style="text-align:right;color:<?= $col ?>;font-weight:600">
                <?= pvFmtMoney($v) ?> <span style="color:#888;font-weight:400">(<?= pvFmtPct($vpct) ?>)</span>
            </td>
            <?php endforeach; ?>
            <td><?= pvStatusBadge($r['status']) ?></td>
            <td style="max-width:260px">
                <?php $rmk = trim((string)($r['decision_remarks'] ?? '')); ?>
                <?php if ($rmk !== ''): ?>
                    <div title="<?= h($rmk) ?>"
                         style="font-size:12px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word">
                        <?= h($rmk) ?>
                    </div>
                <?php else: ?>
                    <span style="color:#888">—</span>
                <?php endif; ?>
            </td>
            <td class="actions"><a href="?page=price_variation_detail&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary">Open</a></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php
// ── Pagination — preserves every active filter via the existing $_GET
//    query string and only swaps the `p` (page) value. We render at
//    most 7 numbered links centred around the current page so the
//    control stays compact on long result sets.
if ($pages > 1):
    $qsBase = $_GET;
    $linkFor = function (int $p) use ($qsBase): string {
        $qs       = $qsBase;
        $qs['p']  = $p;
        $qs['view'] = 1;
        return '?' . http_build_query($qs);
    };
    $window = 3;
    $start  = max(1,      $page - $window);
    $end    = min($pages, $page + $window);
?>
<div style="display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-top:10px">
    <span style="font-size:11px;color:var(--muted);margin-right:6px">
        <?= (int)((($page - 1) * PV_PAGE_SIZE) + 1) ?>–<?= (int)min($total, $page * PV_PAGE_SIZE) ?>
        of <?= (int)$total ?>
    </span>
    <?php if ($page > 1): ?>
        <a class="btn btn-sm btn-ghost" href="<?= h($linkFor(1)) ?>" title="First">«</a>
        <a class="btn btn-sm btn-ghost" href="<?= h($linkFor($page - 1)) ?>" title="Previous">‹</a>
    <?php endif; ?>
    <?php if ($start > 1): ?><span style="color:var(--muted)">…</span><?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="btn btn-sm btn-primary" style="cursor:default"><?= $i ?></span>
        <?php else: ?>
            <a class="btn btn-sm btn-ghost" href="<?= h($linkFor($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($end < $pages): ?><span style="color:var(--muted)">…</span><?php endif; ?>
    <?php if ($page < $pages): ?>
        <a class="btn btn-sm btn-ghost" href="<?= h($linkFor($page + 1)) ?>" title="Next">›</a>
        <a class="btn btn-sm btn-ghost" href="<?= h($linkFor($pages)) ?>" title="Last">»</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php
}

// ── Page: Single variation detail ───────────────────────
function pagePriceVariationDetail(): void {
    if (!pvCanSubmit()) { echo '<div class="alert alert-error">Access denied.</div>'; return; }
    $id = (int)($_GET['id'] ?? 0);
    $v  = pvGetVariation($id);
    if (!$v) { echo '<div class="alert alert-error">Variation not found.</div>'; return; }
    if (!pvCanSeeAll() && (int)$v['location_id'] !== myLocationId()) {
        echo '<div class="alert alert-error">Access denied.</div>'; return;
    }
    $items       = pvGetVariationItems($id);
    $attachments = pvGetAttachments($id);
    // Visibility flags driven by the admin's Slot Activity selection.
    // Existing variations may have data for slots that are now inactive
    // — we hide those columns until the admin toggles the slot back on.
    $dtlShow3x   = pvShowSlot('3x');
    $dtlShow4x   = pvShowSlot('4x');
    $dtlShow5x   = pvShowSlot('5x');
    $dtlShow6x   = pvShowSlot('6x');
    // Keep the *Has* flags for snapshot reads — pvShowSlot would also
    // require active, but we still need to KNOW the column exists to
    // read it safely from the row.
    $dtlHas5x    = pvHas5xCol();
    $dtlHas6x    = pvHas6xCol();

    // Recompute slot-level totals for the comparison table from the item
    // snapshots. No system-side discount — slot Net = slot Total (incl tax).
    $sub3 = $sub4 = $sub5 = $sub6 = 0.0; $tot3 = $tot4 = $tot5 = $tot6 = 0.0;
    foreach ($items as $it) {
        $tx = (float)$it['tax_pct'];
        $t3 = (float)$it['expected_3x']; $t4 = (float)$it['expected_4x'];
        $t5 = $dtlHas5x ? (float)($it['expected_5x'] ?? 0) : 0.0;
        $t6 = $dtlHas6x ? (float)($it['expected_6x'] ?? 0) : 0.0;
        $s3 = $tx > 0 ? $t3 / (1 + $tx / 100) : $t3;
        $s4 = $tx > 0 ? $t4 / (1 + $tx / 100) : $t4;
        $s5 = $tx > 0 ? $t5 / (1 + $tx / 100) : $t5;
        $s6 = $tx > 0 ? $t6 / (1 + $tx / 100) : $t6;
        $sub3 += $s3; $sub4 += $s4; $sub5 += $s5; $sub6 += $s6;
        $tot3 += $t3; $tot4 += $t4; $tot5 += $t5; $tot6 += $t6;
    }
    $partnerNet = (float)$v['net_received'];
    $diff3 = $partnerNet - $tot3; $diff4 = $partnerNet - $tot4;
    $diff5 = $partnerNet - $tot5; $diff6 = $partnerNet - $tot6;
    $diff3pct = $tot3 > 0 ? ($diff3 / $tot3) * 100 : 0;
    $diff4pct = $tot4 > 0 ? ($diff4 / $tot4) * 100 : 0;
    $diff5pct = $tot5 > 0 ? ($diff5 / $tot5) * 100 : 0;
    $diff6pct = $tot6 > 0 ? ($diff6 / $tot6) * 100 : 0;
    $colDiff = function (float $v): string {
        return $v < -0.005 ? '#a83232' : ($v > 0.005 ? '#1a7a3d' : '#3a2f24');
    };
?>
<style>
.pv-d-card { background:#f9f7f2; border:1px solid #e7e2d6; border-radius:8px; padding:12px; color:#1a1612; }
.pv-d-label { font-size:11px; color:#7a6f60; text-transform:uppercase; letter-spacing:.04em; }
.pv-d-value { font-weight:700; color:#1a1612; }
/* Detail-page items table — cream rows on the dark form card so text stays readable. */
.pv-items-tbl { width:100%; border-collapse:collapse; font-size:13px; color:#1a1612;
                background:#fbf9f4; border:1px solid #e7e2d6; border-radius:8px; overflow:hidden; }
.pv-items-tbl th, .pv-items-tbl td { padding:8px 10px; border-bottom:1px solid #ece5d4; background:#fbf9f4; color:#1a1612; }
.pv-items-tbl th { background:#f3ead6; text-align:left; font-weight:600; }
.pv-items-tbl tbody tr:hover td { background:#fdfaf2; }
.pv-items-tbl tbody tr:last-child td { border-bottom:none; }
.pv-items-tbl td.num, .pv-items-tbl th.num { text-align:right; font-variant-numeric:tabular-nums; }
</style>

<div class="page-header" style="display:flex;align-items:center;gap:12px">
    <h2 style="margin:0">Price Variation #<?= (int)$v['id'] ?></h2>
    <?= pvStatusBadge($v['status']) ?>
    <div style="margin-left:auto;display:flex;gap:8px">
        <?php if (pvCanEdit($v)): ?>
        <a href="?page=price_variation_edit&id=<?= (int)$v['id'] ?>" class="btn btn-secondary"
           title="<?= $v['status'] === 'confirmed'
                   ? 'Editing will send this back to the POC for re-confirmation.'
                   : 'Update the items, totals, remarks or attachments.' ?>">✎ Edit</a>
        <form method="POST" style="margin:0"
              onsubmit="return confirm('Delete this request permanently? Its items and attachments will be removed. This cannot be undone.');">
            <input type="hidden" name="action" value="pv_delete">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <button type="submit" class="btn btn-danger"
                    title="Only possible while the request is still awaiting approval.">🗑 Delete Request</button>
        </form>
        <?php endif; ?>
        <a href="?page=price_variations" class="btn btn-ghost">← Back to list</a>
    </div>
</div>

<div class="form-card" style="max-width:none;margin-bottom:16px">
    <div class="form-section-title">Order</div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
        <div class="pv-d-card"><div class="pv-d-label">Store</div><div class="pv-d-value"><?= h($v['location_name']) ?></div></div>
        <div class="pv-d-card"><div class="pv-d-label">Partner</div><div class="pv-d-value"><?= ucfirst(h($v['partner'])) ?></div></div>
        <div class="pv-d-card"><div class="pv-d-label">Order ID</div><div class="pv-d-value"><code><?= h($v['order_id']) ?></code></div></div>
        <div class="pv-d-card"><div class="pv-d-label">Order Date</div><div class="pv-d-value"><?= !empty($v['order_date']) ? h(date('d M Y', strtotime($v['order_date']))) : '<span style="color:#888;font-weight:400">—</span>' ?></div></div>
        <div class="pv-d-card"><div class="pv-d-label">Submitted</div><div class="pv-d-value"><?= h(date('d M Y H:i', strtotime($v['submitted_at']))) ?> by <?= h($v['submitted_by']) ?></div></div>
    </div>

    <div class="form-section-title" style="margin-top:14px">Items</div>
    <table class="pv-items-tbl">
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Total ₹<br><span style="font-weight:400;font-size:10px;color:#7a6f60">partner</span></th>
                <th class="num">Tax %</th>
                <?php if ($dtlShow3x): ?><th class="num">3x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($dtlShow4x): ?><th class="num">4x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($dtlShow5x): ?><th class="num">5x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($dtlShow6x): ?><th class="num">6x Amount<br><span style="font-weight:400;font-size:10px;color:#7a6f60">excl tax</span></th><?php endif; ?>
                <?php if ($dtlShow3x): ?><th class="num">3x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($dtlShow4x): ?><th class="num">4x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($dtlShow5x): ?><th class="num">5x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
                <?php if ($dtlShow6x): ?><th class="num">6x Total<br><span style="font-weight:400;font-size:10px;color:#7a6f60">incl tax</span></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php
            // 4 fixed cols (Item, Qty, Total, Tax) + 2 per active slot (Amount + Total).
            $dtlActiveSlotCount = (int)$dtlShow3x + (int)$dtlShow4x + (int)$dtlShow5x + (int)$dtlShow6x;
            $dtlEmptyCols = 4 + 2 * $dtlActiveSlotCount;
        ?>
        <?php if (!$items): ?>
            <tr><td colspan="<?= (int)$dtlEmptyCols ?>" style="text-align:center;color:#888;padding:14px">No items recorded.</td></tr>
        <?php else: foreach ($items as $it):
            $taxPct = (float)$it['tax_pct'];
            $tot3x  = (float)$it['expected_3x'];     // incl tax (price × qty)
            $tot4x  = (float)$it['expected_4x'];
            $tot5x  = $dtlHas5x ? (float)($it['expected_5x'] ?? 0) : 0.0;
            $tot6x  = $dtlHas6x ? (float)($it['expected_6x'] ?? 0) : 0.0;
            $amt3x  = $taxPct > 0 ? $tot3x / (1 + $taxPct / 100) : $tot3x;
            $amt4x  = $taxPct > 0 ? $tot4x / (1 + $taxPct / 100) : $tot4x;
            $amt5x  = $taxPct > 0 ? $tot5x / (1 + $taxPct / 100) : $tot5x;
            $amt6x  = $taxPct > 0 ? $tot6x / (1 + $taxPct / 100) : $tot6x;
            // partner_rate column now holds the line total (per-line
            // subtotal in ₹). Display it directly — no qty multiplication.
            $pTot   = isset($it['partner_rate']) && $it['partner_rate'] !== null ? (float)$it['partner_rate'] : null;
        ?>
            <tr>
                <td><?= h($it['item_name']) ?> <span style="color:#999">(<?= h($it['item_code']) ?>)</span></td>
                <td class="num"><?= (int)$it['quantity'] ?></td>
                <td class="num"><?= $pTot !== null ? pvFmtMoney($pTot) : '—' ?></td>
                <td class="num"><?= number_format($taxPct, 2) ?>%</td>
                <?php if ($dtlShow3x): ?><td class="num"><?= pvFmtMoney($amt3x) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num"><?= pvFmtMoney($amt4x) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num"><?= pvFmtMoney($amt5x) ?></td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num"><?= pvFmtMoney($amt6x) ?></td><?php endif; ?>
                <?php if ($dtlShow3x): ?><td class="num"><?= pvFmtMoney($tot3x) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num"><?= pvFmtMoney($tot4x) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num"><?= pvFmtMoney($tot5x) ?></td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num"><?= pvFmtMoney($tot6x) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="form-section-title" style="margin-top:14px">Aggregator Order Details</div>
    <table class="pv-items-tbl" style="margin-top:0">
        <thead>
            <tr>
                <th></th>
                <th class="num">Partner Details</th>
                <?php if ($dtlShow3x): ?><th class="num">3x</th><?php endif; ?>
                <?php if ($dtlShow4x): ?><th class="num">4x</th><?php endif; ?>
                <?php if ($dtlShow5x): ?><th class="num">5x</th><?php endif; ?>
                <?php if ($dtlShow6x): ?><th class="num">6x</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="width:220px;font-weight:600">Bill Subtotal (₹)</td>
                <td class="num"><?= pvFmtMoney((float)$v['bill_subtotal']) ?></td>
                <?php if ($dtlShow3x): ?><td class="num"><?= pvFmtMoney($sub3) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num"><?= pvFmtMoney($sub4) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num"><?= pvFmtMoney($sub5) ?></td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num"><?= pvFmtMoney($sub6) ?></td><?php endif; ?>
            </tr>
            <tr>
                <td style="font-weight:600">Discount (₹)</td>
                <td class="num"><?= pvFmtMoney((float)$v['discount_amount']) ?></td>
                <?php if ($dtlShow3x): ?><td class="num">—</td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num">—</td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num">—</td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num">—</td><?php endif; ?>
            </tr>
            <tr>
                <td style="font-weight:600">Taxes (₹)</td>
                <td class="num"><?= pvFmtMoney((float)$v['taxes']) ?></td>
                <?php if ($dtlShow3x): ?><td class="num"><?= pvFmtMoney($tot3 - $sub3) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num"><?= pvFmtMoney($tot4 - $sub4) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num"><?= pvFmtMoney($tot5 - $sub5) ?></td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num"><?= pvFmtMoney($tot6 - $sub6) ?></td><?php endif; ?>
            </tr>
            <tr>
                <td style="font-weight:600">Net Received (₹)</td>
                <td class="num"><?= pvFmtMoney($partnerNet) ?></td>
                <?php if ($dtlShow3x): ?><td class="num"><?= pvFmtMoney($tot3) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num"><?= pvFmtMoney($tot4) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?><td class="num"><?= pvFmtMoney($tot5) ?></td><?php endif; ?>
                <?php if ($dtlShow6x): ?><td class="num"><?= pvFmtMoney($tot6) ?></td><?php endif; ?>
            </tr>
            <tr style="background:#f4efe5">
                <td style="font-weight:600;background:#ece4d0">Difference Amt</td>
                <td></td>
                <?php if ($dtlShow3x): ?><td class="num" style="color:<?= $colDiff($diff3) ?>;font-weight:600"><?= pvFmtMoney($diff3) ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num" style="color:<?= $colDiff($diff4) ?>;font-weight:600"><?= pvFmtMoney($diff4) ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?>
                <td class="num" style="color:<?= $colDiff($diff5) ?>;font-weight:600"><?= $tot5 > 0 ? pvFmtMoney($diff5) : '—' ?></td>
                <?php endif; ?>
                <?php if ($dtlShow6x): ?>
                <td class="num" style="color:<?= $colDiff($diff6) ?>;font-weight:600"><?= $tot6 > 0 ? pvFmtMoney($diff6) : '—' ?></td>
                <?php endif; ?>
            </tr>
            <tr style="background:#f4efe5">
                <td style="font-weight:600;background:#ece4d0">Difference %</td>
                <td></td>
                <?php if ($dtlShow3x): ?><td class="num" style="color:<?= $colDiff($diff3) ?>;font-weight:600"><?= $tot3 > 0 ? pvFmtPct($diff3pct) : '—' ?></td><?php endif; ?>
                <?php if ($dtlShow4x): ?><td class="num" style="color:<?= $colDiff($diff4) ?>;font-weight:600"><?= $tot4 > 0 ? pvFmtPct($diff4pct) : '—' ?></td><?php endif; ?>
                <?php if ($dtlShow5x): ?>
                <td class="num" style="color:<?= $colDiff($diff5) ?>;font-weight:600"><?= $tot5 > 0 ? pvFmtPct($diff5pct) : '—' ?></td>
                <?php endif; ?>
                <?php if ($dtlShow6x): ?>
                <td class="num" style="color:<?= $colDiff($diff6) ?>;font-weight:600"><?= $tot6 > 0 ? pvFmtPct($diff6pct) : '—' ?></td>
                <?php endif; ?>
            </tr>
        </tbody>
    </table>

    <?php if (!empty($v['remarks'])): ?>
    <div class="form-section-title" style="margin-top:14px">Manager Remarks</div>
    <div style="background:#fafafa;color:#1a1612;padding:10px;border-radius:6px;white-space:pre-wrap"><?= h($v['remarks']) ?></div>
    <?php endif; ?>

    <?php if ($attachments || pvCanAddAttachment()): ?>
    <div class="form-section-title" style="margin-top:14px">Attachments <span style="font-size:11px;font-weight:400;color:var(--muted)">(<?= count($attachments) ?>)</span></div>
    <?php if ($attachments): ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($attachments as $a):
            $url = '?page=download_pv_attachment&id=' . (int)$a['id'];
            $isImage = strpos((string)$a['mime_type'], 'image/') === 0;
        ?>
        <a href="<?= $url ?>" target="_blank" rel="noopener"
           style="display:block;width:140px;border:1px solid #d8d0c2;border-radius:6px;background:#fff;color:#1a1612;text-decoration:none;overflow:hidden">
            <?php if ($isImage): ?>
                <img src="<?= $url ?>" alt="<?= h($a['original_name']) ?>"
                     style="display:block;width:100%;height:110px;object-fit:cover;background:#f3ead6">
            <?php else: ?>
                <div style="height:110px;display:flex;align-items:center;justify-content:center;background:#f3ead6;color:#7a6f60;font-size:32px">📎</div>
            <?php endif; ?>
            <div style="padding:5px 8px;font-size:11px;color:#1a1612;background:#fbf9f4;border-top:1px solid #ece5d4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                 title="<?= h($a['original_name']) ?>">
                <?= h($a['original_name']) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (pvCanAddAttachment()): ?>
    <form method="POST" enctype="multipart/form-data" style="margin-top:10px;padding:10px 12px;border:1px dashed #d8d0c2;border-radius:6px;background:#fbf9f4;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="action" value="pv_add_attachment">
        <input type="hidden" name="variation_id" value="<?= (int)$v['id'] ?>">
        <span style="font-size:12px;color:#1a1612;font-weight:500">+ Add attachment(s):</span>
        <input type="file" name="attachments[]" multiple required accept="image/*,application/pdf"
               style="font-size:12px;flex:1 1 240px;color:#1a1612">
        <button type="submit" class="btn btn-primary btn-sm">Upload</button>
        <span style="font-size:11px;color:#7a6f60;flex:0 0 100%">Anyone on this variation (submitter, POC, admin) can add files at any stage.</span>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    // Show a POC confirmation card once status moves past 'pending' so
    // anyone reading the audit trail can see who confirmed it.
    $hasConfirmCols = pvHasConfirmCols();
    if ($hasConfirmCols && in_array($v['status'], ['confirmed','approved','rejected'], true) && !empty($v['confirmed_by'])):
        $canEditConfirm = pvCanEditConfirmRemarks($v);
    ?>
    <div class="form-section-title" style="margin-top:14px">POC Confirmation</div>
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px">
        <div class="pv-d-card">
            <div class="pv-d-label">Confirmed</div>
            <div class="pv-d-value">
                by <?= h($v['confirmed_by']) ?>
                <?php if (!empty($v['confirmed_at'])): ?>
                    on <?= h(date('d M Y H:i', strtotime($v['confirmed_at']))) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($v['confirm_remarks']) || $canEditConfirm): ?>
        <div class="pv-d-card">
            <div class="pv-d-label" style="display:flex;align-items:center;gap:8px">
                <span>POC Remarks</span>
                <?php if ($canEditConfirm): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="padding:1px 8px;font-size:11px"
                        onclick="document.getElementById('pv-poc-view').style.display='none';document.getElementById('pv-poc-edit').style.display='block';document.querySelector('#pv-poc-edit textarea').focus();">
                    ✎ Edit
                </button>
                <?php endif; ?>
            </div>
            <div id="pv-poc-view" class="pv-d-value" style="white-space:pre-wrap;font-weight:500">
                <?php if (!empty($v['confirm_remarks'])): ?>
                    <?= h($v['confirm_remarks']) ?>
                <?php else: ?>
                    <span style="color:#888;font-weight:400;font-style:italic">— no remark —</span>
                <?php endif; ?>
            </div>
            <?php if ($canEditConfirm): ?>
            <form id="pv-poc-edit" method="POST" style="display:none;margin-top:6px"
                  onsubmit="return confirm('Update the POC remark? Confirmation status and timestamp stay unchanged.');">
                <input type="hidden" name="action" value="pv_edit_confirm_remarks">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <textarea name="confirm_remarks" rows="3" maxlength="500" class="form-control"
                          style="background:#fff;color:#1a1612;border:1px solid #d8d0c2"
                          placeholder="Optional remark."><?= h($v['confirm_remarks'] ?? '') ?></textarea>
                <div style="margin-top:6px;display:flex;gap:6px">
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                    <button class="btn btn-ghost btn-sm" type="button"
                            onclick="document.getElementById('pv-poc-edit').style.display='none';document.getElementById('pv-poc-view').style.display='block';">Cancel</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($v['status'] !== 'pending' && $v['status'] !== 'confirmed'): ?>
    <?php $canEditRemark = pvCanEditDecisionRemarks($v); ?>
    <div class="form-section-title" style="margin-top:14px">Decision</div>
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px">
        <div class="pv-d-card">
            <div class="pv-d-label">Decided</div>
            <div class="pv-d-value">
                <?= ucfirst(h($v['status'])) ?> by <?= h($v['decided_by'] ?? '—') ?>
                <?php if (!empty($v['decided_at'])): ?>
                    on <?= h(date('d M Y H:i', strtotime($v['decided_at']))) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($v['decision_remarks']) || $canEditRemark): ?>
        <div class="pv-d-card">
            <div class="pv-d-label" style="display:flex;align-items:center;gap:8px">
                <span>Remarks</span>
                <?php if ($canEditRemark): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="padding:1px 8px;font-size:11px"
                        onclick="document.getElementById('pv-rmk-view').style.display='none';document.getElementById('pv-rmk-edit').style.display='block';document.querySelector('#pv-rmk-edit textarea').focus();">
                    ✎ Edit
                </button>
                <?php endif; ?>
            </div>
            <div id="pv-rmk-view" class="pv-d-value" style="white-space:pre-wrap;font-weight:500">
                <?php if (!empty($v['decision_remarks'])): ?>
                    <?= h($v['decision_remarks']) ?>
                <?php else: ?>
                    <span style="color:#888;font-weight:400;font-style:italic">— no remark —</span>
                <?php endif; ?>
            </div>
            <?php if ($canEditRemark): ?>
            <form id="pv-rmk-edit" method="POST" style="display:none;margin-top:6px"
                  onsubmit="return confirm('Update the decision remark? The decision status and timestamp stay unchanged.');">
                <input type="hidden" name="action" value="pv_edit_decision_remarks">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <textarea name="decision_remarks" rows="3" class="form-control"
                          style="background:#fff;color:#1a1612;border:1px solid #d8d0c2"
                          <?= $v['status'] === 'rejected' ? 'required' : '' ?>
                          placeholder="<?= $v['status'] === 'rejected' ? 'Rejection reason is required.' : 'Optional remark.' ?>"><?= h($v['decision_remarks'] ?? '') ?></textarea>
                <div style="margin-top:6px;display:flex;gap:6px">
                    <button class="btn btn-primary btn-sm" type="submit">Save</button>
                    <button class="btn btn-ghost btn-sm" type="button"
                            onclick="document.getElementById('pv-rmk-edit').style.display='none';document.getElementById('pv-rmk-view').style.display='block';">Cancel</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
// Action card — what's available depends on the current status:
//   pending   → POC sees "Confirm Variation" (move forward) OR "Reject
//               Request" (final, no approver review needed)
//   confirmed → Admin sees Approve / Reject
// Pre-migration DBs (no confirm cols) keep the legacy direct path:
//   pending   → Admin sees Approve / Reject
$showConfirmForm = $hasConfirmCols && $v['status'] === 'pending' && pvCanConfirm();
$showDecideForm  = $hasConfirmCols
    ? ($v['status'] === 'confirmed' && pvCanAdmin())
    : ($v['status'] === 'pending'   && pvCanAdmin());
?>

<?php if ($showConfirmForm): ?>
<div class="form-card" style="max-width:none">
    <div class="form-section-title">POC Confirmation</div>
    <p class="hint" style="margin:0 0 10px;font-size:12px">
        Please escalate this to the <?= ucfirst(h($v['partner'])) ?> partner regarding the discount variance,
        or reject the request outright if it's invalid — no approver review is needed.
    </p>
    <form method="POST">
        <input type="hidden" name="action" value="pv_confirm">
        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <div class="form-group">
            <label>Confirm Remarks (optional)</label>
            <textarea name="confirm_remarks" rows="3" maxlength="500" class="form-control" placeholder="Please escalate this to the Swiggy or Zomato partner regarding the discount variance."></textarea>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Confirm Variation</button>
        </div>
    </form>

    <!-- POC direct reject — final, bypasses the approver. Rejection reason
         is required (enforced server-side in doDecidePriceVariation). -->
    <form method="POST" style="margin-top:14px;border-top:1px solid var(--border);padding-top:14px"
          onsubmit="return confirm('Reject this variation? This is final — it will not go to an approver.');">
        <input type="hidden" name="action" value="pv_decide">
        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <div class="form-group">
            <label>Rejection Reason (required)</label>
            <textarea name="decision_remarks" rows="3" class="form-control" placeholder="Why is this variation being rejected?"></textarea>
        </div>
        <div class="form-actions">
            <button class="btn btn-danger" name="decision" value="reject" type="submit">Reject Request</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($showDecideForm): ?>
<div class="form-card" style="max-width:none">
    <div class="form-section-title">Decision</div>
    <form method="POST">
        <input type="hidden" name="action" value="pv_decide">
        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <div class="form-group">
            <label>Decision Remarks (required when rejecting)</label>
            <textarea name="decision_remarks" rows="3" class="form-control" placeholder="Please enter the invoice number or rejection reason."></textarea>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" name="decision" value="approve">Approve</button>
            <button class="btn btn-danger"  name="decision" value="reject">Reject</button>
        </div>
    </form>
</div>
<?php elseif ($hasConfirmCols && $v['status'] === 'pending' && pvCanAdmin() && !pvCanConfirm()): ?>
<div class="form-card" style="max-width:none">
    <div class="alert alert-info" style="margin:0;background:rgba(26,143,227,.10);color:#9ed1f6;border:1px solid rgba(26,143,227,.25);padding:10px 14px;border-radius:6px">
        Waiting on the <?= ucfirst(h($v['partner'])) ?> POC to confirm this variation before approval.
    </div>
</div>
<?php endif; ?>
<?php
}

// ── Store manager cancels/deletes their own request ─────
// Allowed only while the approver hasn't approved or rejected it — i.e.
// the row is still 'pending' or 'confirmed'. Reuses pvCanEdit() so the
// permission matches editing exactly: submitter, same-store creator, or
// superadmin. Approved/rejected rows can never be deleted here.
function doDeletePriceVariation(): void {
    $id  = (int)($_POST['id'] ?? 0);
    $row = pvGetVariation($id);
    if (!$row) {
        flash('error', 'Variation not found.');
        header('Location: index.php?page=price_variations'); exit;
    }
    if (!pvCanEdit($row)) {
        flash('error', 'This request can no longer be deleted — it has already been approved or rejected.');
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }

    $db = getDb();
    try {
        // Collect attachment files up-front so we can unlink them after the
        // DB rows are gone (mirrors doDeletePriceVariationsByMonth).
        $atts = [];
        try {
            $ast = $db->prepare('SELECT stored_name FROM price_variation_attachments WHERE variation_id = ?');
            $ast->execute([$id]);
            $atts = $ast->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist on legacy installs */ }

        $db->beginTransaction();
        try { $db->prepare('DELETE FROM price_variation_attachments WHERE variation_id = ?')->execute([$id]); } catch (Exception $e) {}
        try { $db->prepare('DELETE FROM price_variation_items WHERE variation_id = ?')->execute([$id]); } catch (Exception $e) {}
        $db->prepare('DELETE FROM price_variations WHERE id = ?')->execute([$id]);
        $db->commit();

        foreach ($atts as $a) {
            $p = pvAttachmentPath((string)($row['submitted_at'] ?? 'now'), (string)$a['stored_name'], false);
            if (is_file($p)) @unlink($p);
        }

        flash('success', 'Request deleted.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Delete failed: ' . $e->getMessage());
        header('Location: index.php?page=price_variation_detail&id=' . $id); exit;
    }
    header('Location: index.php?page=price_variations'); exit;
}

// ── Bulk delete price variations submitted in a month (superadmin only) ─
function doDeletePriceVariationsByMonth(): void {
    if (!isSuperadmin()) {
        flash('error', 'Access denied.');
        header('Location: index.php?page=price_variations'); exit;
    }
    $month = (int)($_POST['month'] ?? 0);
    $year  = (int)($_POST['year']  ?? 0);
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2099) {
        flash('error', 'Invalid month or year.');
        header('Location: index.php?page=price_variations'); exit;
    }
    $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart)) . ' 23:59:59';
    $monthName  = date('F Y', strtotime($monthStart));

    $db = getDb();
    try {
        $st = $db->prepare(
            'SELECT id FROM price_variations WHERE submitted_at BETWEEN ? AND ?'
        );
        $st->execute([$monthStart, $monthEnd]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            flash('error', 'No price variations submitted in ' . $monthName . '.');
            header('Location: index.php?page=price_variations'); exit;
        }

        $atts = [];
        try {
            $ast = $db->prepare(
                'SELECT a.stored_name, v.submitted_at
                 FROM price_variation_attachments a
                 JOIN price_variations v ON v.id = a.variation_id
                 WHERE a.variation_id IN (' . implode(',', $ids) . ')'
            );
            $ast->execute();
            $atts = $ast->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist on legacy installs */ }

        $db->beginTransaction();
        $in = '(' . implode(',', $ids) . ')';
        try { $db->exec('DELETE FROM price_variation_attachments WHERE variation_id IN ' . $in); } catch (Exception $e) {}
        try { $db->exec('DELETE FROM price_variation_items WHERE variation_id IN ' . $in); } catch (Exception $e) {}
        $db->exec('DELETE FROM price_variations WHERE id IN ' . $in);
        $db->commit();

        $unlinked = 0;
        foreach ($atts as $a) {
            $p = pvAttachmentPath((string)($a['submitted_at'] ?? 'now'), (string)$a['stored_name'], false);
            if (is_file($p) && @unlink($p)) $unlinked++;
        }

        flash('success', 'Deleted ' . count($ids) . ' price variation' . (count($ids) === 1 ? '' : 's') . ' for ' . $monthName . ' (' . $unlinked . ' attachment file' . ($unlinked === 1 ? '' : 's') . ' removed).');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Delete failed: ' . $e->getMessage());
    }
    header('Location: index.php?page=price_variations'); exit;
}
