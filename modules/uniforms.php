<?php
// =========================================================
// Uniforms — HRMS uniform management
//
// Replaces the "UNIFORM SIZE OF STAFF" spreadsheet: one sheet with every
// employee's jeans / T-shirt size, one with who was dispatched what, and
// a stock block (Receive / Dispatch / Available per size) kept right by
// hand. Here the three are one thing:
//
//   · Items        — what is handed out, the sizes it comes in, and how
//                    many pieces one issue is (2: everyone gets a pair).
//   · Size register — each employee's size per item. Feeds the Issue
//                    form's default and the "still to be issued" demand.
//   · Stock ledger — every piece in or out is a row in uniform_moves:
//                    receive (+), issue (−), return (+), adjust (±).
//                    Available stock is SUM(qty) of the live rows —
//                    never a stored number that can drift from the
//                    ledger.
//   · Requests     — a size someone needs that could not be issued yet
//                    ("4XL REQ" in the old sheet). Shown against stock as
//                    the shortfall to order.
//
// A wrong entry is voided, not deleted, so the ledger always reads back.
// An issue can never take a size below zero — the stock check and the
// insert run under a lock on the item row.
//
// Permission: txn_uniforms (superadmin always).
// Schema:     migrations/2026-09-26_uniforms.sql
// =========================================================

define('UNI_CSV_MAX_BYTES', 5 * 1024 * 1024);
define('UNI_CSV_MAX_ROWS',  5000);

const UNI_MOVE_TYPES = [
    'receive' => 'Received',
    'issue'   => 'Issued',
    'return'  => 'Returned',
    'adjust'  => 'Adjustment',
];

// What the form offers. Exchange is not a move type of its own: it is a
// return of one size and an issue of another, written together.
const UNI_FORM_TYPES = [
    'receive'  => 'Receive Stock',
    'issue'    => 'Issue to Employee',
    'return'   => 'Return from Employee',
    'exchange' => 'Exchange Size',
    'adjust'   => 'Stock Adjustment',
];

// ── Schema probe ────────────────────────────────────────
function uniSchemaReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        getDb()->query('SELECT 1 FROM uniform_items LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM uniform_staff_sizes LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM uniform_moves LIMIT 0')->fetch();
        getDb()->query('SELECT 1 FROM uniform_requests LIMIT 0')->fetch();
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function uniSchemaNotice(): string {
    return 'Uniforms is not set up on this database yet — run migrations/2026-09-26_uniforms.sql.';
}

// ── Permissions ─────────────────────────────────────────
function uniCanManage(): bool {
    return isSuperadmin() || hasTxn('uniforms');
}

// Who to record against an entry. Superadmin logs in without an employee
// code, so it is named rather than left blank.
function uniActor(): string {
    return myCode() !== '' ? myCode() : 'superadmin';
}

// Shared guard for every page: prints the reason and returns false.
function uniPageGate(): bool {
    if (!uniCanManage()) { echo '<div class="alert alert-error">Access denied.</div>'; return false; }
    if (!uniSchemaReady()) {
        echo '<div class="alert alert-error">' . h(uniSchemaNotice()) . '</div>'; return false;
    }
    return true;
}

// Shared guard for every POST handler: flashes and redirects on failure.
function uniPostGate(string $back): void {
    if (!uniCanManage() || !uniSchemaReady()) {
        flash('error', 'You do not have permission to manage uniforms.');
        header("Location: {$back}"); exit;
    }
}

// =========================================================
// Items and sizes
// =========================================================
function uniItems(bool $activeOnly = false): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = getDb()->query('SELECT * FROM uniform_items ORDER BY sort_order, name')
                           ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $r['size_list'] = uniSplitSizes((string)$r['sizes']);
                $cache[(int)$r['id']] = $r;
            }
        } catch (Exception $e) { /* leave empty */ }
    }
    return $activeOnly ? array_filter($cache, fn($i) => (int)$i['is_active'] === 1) : $cache;
}

function uniItem(int $id): ?array {
    return uniItems()[$id] ?? null;
}

function uniSplitSizes(string $csv): array {
    $out = [];
    foreach (explode(',', $csv) as $s) {
        $s = mb_strtoupper(trim($s));
        if ($s !== '' && !in_array($s, $out, true)) $out[] = mb_substr($s, 0, 20);
    }
    return $out;
}

// What people actually write in a size cell, mapped onto the item's own
// list: "XXXL" for 3XL, "2XL" for XXL, "32.0" out of a spreadsheet.
// Returns the size exactly as the item spells it, or null.
function uniNormalizeSize(array $item, $raw): ?string {
    $s = mb_strtoupper(trim((string)$raw));
    $s = preg_replace('/\s+/', '', $s);
    if ($s === '' || $s === '0' || $s === '-') return null;
    if (preg_match('/^(\d+)\.0+$/', $s, $m)) $s = $m[1];
    foreach ($item['size_list'] as $known) {
        if (preg_replace('/\s+/', '', $known) === $s) return $known;
    }
    // nXL ⇄ XX…XL, whichever spelling the item uses.
    if (preg_match('/^(X+)L$/', $s, $m) && strlen($m[1]) >= 2) {
        $alt = strlen($m[1]) . 'XL';
        if (in_array($alt, $item['size_list'], true)) return $alt;
    }
    if (preg_match('/^(\d)XL$/', $s, $m) && (int)$m[1] >= 2) {
        $alt = str_repeat('X', (int)$m[1]) . 'L';
        if (in_array($alt, $item['size_list'], true)) return $alt;
    }
    return null;
}

// =========================================================
// Stock
// =========================================================

// [item_id][size] => receive / issue / return / adjust / available.
// issue is reported as a positive count of pieces out.
function uniStockMatrix(): array {
    $out = [];
    foreach (uniItems() as $id => $it) {
        foreach ($it['size_list'] as $s) {
            $out[$id][$s] = ['receive' => 0, 'issue' => 0, 'return' => 0, 'adjust' => 0, 'available' => 0];
        }
    }
    try {
        $rows = getDb()->query(
            'SELECT item_id, size, move_type, SUM(qty) AS q
             FROM uniform_moves WHERE voided_at IS NULL
             GROUP BY item_id, size, move_type'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $id = (int)$r['item_id']; $s = (string)$r['size']; $q = (int)$r['q'];
        // A size since dropped from the item still holds stock — keep it
        // visible rather than let the pieces vanish from the totals.
        if (!isset($out[$id][$s])) {
            $out[$id][$s] = ['receive' => 0, 'issue' => 0, 'return' => 0, 'adjust' => 0, 'available' => 0];
        }
        $out[$id][$s][$r['move_type']] += $r['move_type'] === 'issue' ? -$q : $q;
        $out[$id][$s]['available']    += $q;
    }
    return $out;
}

// Live on-hand count of one size. Pass $lock inside a transaction to
// serialise concurrent issues of the same item.
function uniAvailable(int $itemId, string $size, bool $lock = false): int {
    $db = getDb();
    if ($lock) {
        $db->prepare('SELECT id FROM uniform_items WHERE id = ? FOR UPDATE')->execute([$itemId]);
    }
    $st = $db->prepare(
        'SELECT COALESCE(SUM(qty), 0) FROM uniform_moves
         WHERE item_id = ? AND size = ? AND voided_at IS NULL'
    );
    $st->execute([$itemId, $size]);
    return (int)$st->fetchColumn();
}

// Open requests, [item_id][size] => pieces.
function uniOpenRequestQty(): array {
    $out = [];
    try {
        $rows = getDb()->query(
            "SELECT item_id, size, SUM(qty) AS q FROM uniform_requests
             WHERE status = 'open' GROUP BY item_id, size"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[(int)$r['item_id']][(string)$r['size']] = (int)$r['q'];
    } catch (Exception $e) { /* none */ }
    return $out;
}

// =========================================================
// Size register
// =========================================================

// [employee_id][item_id] => size
function uniStaffSizes(): array {
    $out = [];
    try {
        $rows = getDb()->query('SELECT employee_id, item_id, size FROM uniform_staff_sizes')
                       ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[(int)$r['employee_id']][(int)$r['item_id']] = (string)$r['size'];
    } catch (Exception $e) { /* none */ }
    return $out;
}

// What each employee is holding right now: issued minus returned, per
// item and size. [employee_id][item_id][size] => pieces (only > 0).
function uniHoldings(): array {
    $out = [];
    try {
        $rows = getDb()->query(
            "SELECT employee_id, item_id, size, -SUM(qty) AS held
             FROM uniform_moves
             WHERE voided_at IS NULL AND employee_id IS NOT NULL
               AND move_type IN ('issue','return')
             GROUP BY employee_id, item_id, size
             HAVING held > 0"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[(int)$r['employee_id']][(int)$r['item_id']][(string)$r['size']] = (int)$r['held'];
        }
    } catch (Exception $e) { /* none */ }
    return $out;
}

function uniHeldTotal(array $holdings, int $empId, int $itemId): int {
    return array_sum($holdings[$empId][$itemId] ?? []);
}

// Serving staff, plus anyone who has left while still holding uniform —
// those are the pieces to recover.
function uniRegisterEmployees(array $holdings): array {
    try {
        $rows = getDb()->query(
            'SELECT id, employee_code, full_name, location_id, is_active
             FROM employees ORDER BY full_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    return array_values(array_filter($rows,
        fn($e) => (int)$e['is_active'] === 1 || !empty($holdings[(int)$e['id']])));
}

// One item's status for one employee.
//   nosize  — no size in the register
//   pending — size known, fewer pieces held than one issue
//   issued  — holding at least one full issue
function uniStatus(?string $size, int $held, int $perIssue): string {
    if ($held >= max(1, $perIssue)) return 'issued';
    return $size === null ? 'nosize' : 'pending';
}

// The pieces still to hand out to serving staff whose size is known,
// [item_id][size] => pieces. The "8 / 2 / 1" line under Available in the
// old stock sheet.
function uniPendingDemand(): array {
    $items    = uniItems(true);
    $sizes    = uniStaffSizes();
    $holdings = uniHoldings();
    $out      = [];
    try {
        $ids = getDb()->query('SELECT id FROM employees WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
    foreach ($ids as $eid) {
        $eid = (int)$eid;
        foreach ($items as $iid => $it) {
            $size = $sizes[$eid][$iid] ?? null;
            if ($size === null) continue;
            $short = (int)$it['qty_per_issue'] - uniHeldTotal($holdings, $eid, $iid);
            if ($short > 0) $out[$iid][$size] = ($out[$iid][$size] ?? 0) + $short;
        }
    }
    return $out;
}

function uniSaveSize(int $empId, int $itemId, ?string $size): void {
    if ($size === null) {
        getDb()->prepare('DELETE FROM uniform_staff_sizes WHERE employee_id = ? AND item_id = ?')
               ->execute([$empId, $itemId]);
        return;
    }
    getDb()->prepare(
        'INSERT INTO uniform_staff_sizes (employee_id, item_id, size, updated_by)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE size = VALUES(size), updated_by = VALUES(updated_by)'
    )->execute([$empId, $itemId, $size, uniActor()]);
}

function uniEmployeeByCode(string $code): ?array {
    $code = trim($code);
    if ($code === '') return null;
    try {
        $st = getDb()->prepare(
            'SELECT id, employee_code, full_name, location_id, is_active
             FROM employees WHERE employee_code = ? ORDER BY is_active DESC, id LIMIT 1'
        );
        $st->execute([$code]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function uniLocationNames(): array {
    $out = [];
    foreach (getLocations() as $l) $out[(int)$l['location_id']] = (string)$l['location_name'];
    return $out;
}

// =========================================================
// Writing the ledger
// =========================================================
function uniInsertMove(array $m): int {
    getDb()->prepare(
        'INSERT INTO uniform_moves
            (item_id, size, move_type, qty, employee_id, employee_code, employee_name,
             location_id, move_date, reference, notes, request_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        (int)$m['item_id'], (string)$m['size'], (string)$m['move_type'], (int)$m['qty'],
        $m['employee']['id']            ?? null,
        $m['employee']['employee_code'] ?? null,
        $m['employee']['full_name']     ?? null,
        isset($m['employee']['location_id']) ? (int)$m['employee']['location_id'] : null,
        (string)$m['move_date'],
        ($m['reference'] ?? '') !== '' ? mb_substr((string)$m['reference'], 0, 100) : null,
        ($m['notes']     ?? '') !== '' ? mb_substr((string)$m['notes'], 0, 255)     : null,
        $m['request_id'] ?? null,
        uniActor(),
    ]);
    return (int)getDb()->lastInsertId();
}

function uniPostDate(): string {
    $d = trim((string)($_POST['move_date'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : date('Y-m-d');
}

// ── Handler: every stock movement ───────────────────────
function doUniformMove(): void {
    uniPostGate('index.php?page=uniforms');
    $type = (string)($_POST['type'] ?? '');
    if (!isset(UNI_FORM_TYPES[$type])) {
        flash('error', 'Unknown movement type.');
        header('Location: index.php?page=uniforms'); exit;
    }
    $form  = 'index.php?page=uniform_move&type=' . $type;
    $date  = uniPostDate();
    $notes = trim((string)($_POST['notes'] ?? ''));
    $db    = getDb();

    if ($type === 'receive') {
        $ref  = trim((string)($_POST['reference'] ?? ''));
        $rows = [];
        foreach ((array)($_POST['qty'] ?? []) as $itemId => $bySize) {
            $item = uniItem((int)$itemId);
            if (!$item || !is_array($bySize)) continue;
            foreach ($bySize as $size => $q) {
                $q = (int)$q;
                if ($q <= 0) continue;
                if (!in_array((string)$size, $item['size_list'], true)) continue;
                $rows[] = [$item, (string)$size, $q];
            }
        }
        if (!$rows) {
            flash('error', 'Enter the quantity received against at least one size.');
            header("Location: {$form}"); exit;
        }
        try {
            $db->beginTransaction();
            $total = 0;
            foreach ($rows as [$item, $size, $q]) {
                uniInsertMove(['item_id' => $item['id'], 'size' => $size, 'move_type' => 'receive',
                               'qty' => $q, 'move_date' => $date, 'reference' => $ref, 'notes' => $notes]);
                $total += $q;
            }
            $db->commit();
            flash('success', "Stock received — {$total} piece(s) across " . count($rows) . ' size(s).');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Could not save: ' . $e->getMessage());
            header("Location: {$form}"); exit;
        }
        header('Location: index.php?page=uniforms'); exit;
    }

    $item = uniItem((int)($_POST['item_id'] ?? 0));
    if (!$item) {
        flash('error', 'Pick the item.');
        header("Location: {$form}"); exit;
    }
    $size = uniNormalizeSize($item, $_POST['size'] ?? '');
    if ($size === null) {
        flash('error', 'Pick a size from the ' . $item['name'] . ' list.');
        header("Location: {$form}"); exit;
    }

    if ($type === 'adjust') {
        $q = (int)($_POST['qty'] ?? 0);
        if ($q === 0 || $notes === '') {
            flash('error', 'An adjustment needs a non-zero quantity (negative to reduce stock) and a reason.');
            header("Location: {$form}"); exit;
        }
        try {
            $db->beginTransaction();
            if ($q < 0 && uniAvailable((int)$item['id'], $size, true) + $q < 0) {
                $db->rollBack();
                flash('error', "Only " . uniAvailable((int)$item['id'], $size) . " {$item['name']} {$size} in stock — cannot reduce by " . (-$q) . '.');
                header("Location: {$form}"); exit;
            }
            uniInsertMove(['item_id' => $item['id'], 'size' => $size, 'move_type' => 'adjust',
                           'qty' => $q, 'move_date' => $date, 'notes' => $notes]);
            $db->commit();
            flash('success', "Stock adjusted: {$item['name']} {$size} " . ($q > 0 ? '+' : '') . $q . '.');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Could not save: ' . $e->getMessage());
            header("Location: {$form}"); exit;
        }
        header('Location: index.php?page=uniforms'); exit;
    }

    // issue / return / exchange — all against one employee.
    $emp = getEmployee((int)($_POST['employee_id'] ?? 0));
    if (!$emp) {
        flash('error', 'Pick the employee.');
        header("Location: {$form}"); exit;
    }
    $form .= '&emp=' . (int)$emp['id'];
    $q = (int)($_POST['qty'] ?? 0);
    if ($q <= 0) {
        flash('error', 'Quantity must be at least 1.');
        header("Location: {$form}"); exit;
    }
    $back = 'index.php?page=uniform_ledger&emp=' . (int)$emp['id'];

    try {
        $db->beginTransaction();

        if ($type === 'return' || $type === 'exchange') {
            $retSize = $type === 'exchange' ? uniNormalizeSize($item, $_POST['return_size'] ?? '') : $size;
            if ($retSize === null) {
                $db->rollBack();
                flash('error', 'Pick the size being handed back.');
                header("Location: {$form}"); exit;
            }
            $held = uniHoldings()[(int)$emp['id']][(int)$item['id']][$retSize] ?? 0;
            if ($q > $held && empty($_POST['allow_unrecorded'])) {
                $db->rollBack();
                flash('error', "{$emp['full_name']} holds {$held} {$item['name']} {$retSize} on record. "
                             . 'Tick "not issued through this system" to accept the return anyway.');
                header("Location: {$form}"); exit;
            }
            uniInsertMove(['item_id' => $item['id'], 'size' => $retSize, 'move_type' => 'return',
                           'qty' => $q, 'employee' => $emp, 'move_date' => $date,
                           'notes' => $type === 'exchange' ? trim('Exchange for ' . $size . '. ' . $notes) : $notes]);
            // Damaged: counted as returned, then written straight off, so
            // Returned stays true and Available does not grow.
            if ($type === 'return' && !empty($_POST['damaged'])) {
                uniInsertMove(['item_id' => $item['id'], 'size' => $retSize, 'move_type' => 'adjust',
                               'qty' => -$q, 'move_date' => $date,
                               'notes' => 'Written off — returned damaged by ' . $emp['employee_code']]);
            }
        }

        if ($type === 'issue' || $type === 'exchange') {
            $avail = uniAvailable((int)$item['id'], $size, true);
            if ($avail < $q) {
                $db->rollBack();
                flash('error', "Only {$avail} {$item['name']} {$size} in stock — cannot issue {$q}. "
                             . 'Raise a request instead and it will show as a shortfall on the stock page.');
                header("Location: index.php?page=uniform_requests&new=1&emp=" . (int)$emp['id']
                     . '&item=' . (int)$item['id'] . '&size=' . urlencode($size) . '&qty=' . $q); exit;
            }
            $reqId = (int)($_POST['request_id'] ?? 0) ?: null;
            uniInsertMove(['item_id' => $item['id'], 'size' => $size, 'move_type' => 'issue',
                           'qty' => -$q, 'employee' => $emp, 'move_date' => $date,
                           'notes' => $notes, 'request_id' => $reqId]);
            if ($reqId) {
                $db->prepare(
                    "UPDATE uniform_requests SET status = 'fulfilled', closed_by = ?, closed_at = NOW()
                     WHERE id = ? AND status = 'open'"
                )->execute([uniActor(), $reqId]);
            }
            if (!empty($_POST['save_size'])) uniSaveSize((int)$emp['id'], (int)$item['id'], $size);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Could not save: ' . $e->getMessage());
        header("Location: {$form}"); exit;
    }

    $what = ['issue' => 'issued to', 'return' => 'returned by', 'exchange' => 'exchanged for'][$type];
    flash('success', "{$q} × {$item['name']} {$size} {$what} {$emp['full_name']}.");
    header("Location: {$back}"); exit;
}

// ── Handler: void a ledger row ──────────────────────────
function doUniformVoidMove(): void {
    $back = 'index.php?page=uniform_ledger';
    uniPostGate($back);
    $id     = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $db     = getDb();
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT * FROM uniform_moves WHERE id = ? AND voided_at IS NULL FOR UPDATE');
        $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            $db->rollBack();
            flash('error', 'That entry does not exist or is already voided.');
            header("Location: {$back}"); exit;
        }
        // Voiding stock that came IN must not leave a size below zero —
        // those pieces may already have been issued.
        if ((int)$m['qty'] > 0) {
            $avail = uniAvailable((int)$m['item_id'], (string)$m['size'], true);
            if ($avail - (int)$m['qty'] < 0) {
                $db->rollBack();
                flash('error', "Cannot void: only {$avail} of that size left in stock, "
                             . "and this entry added {$m['qty']}. Void the issues made from it first.");
                header("Location: {$back}"); exit;
            }
        }
        $db->prepare('UPDATE uniform_moves SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?')
           ->execute([uniActor(), $reason !== '' ? mb_substr($reason, 0, 255) : null, $id]);
        // An issue that fulfilled a request puts the request back to open.
        if ($m['move_type'] === 'issue' && !empty($m['request_id'])) {
            $db->prepare(
                "UPDATE uniform_requests SET status = 'open', closed_by = NULL, closed_at = NULL
                 WHERE id = ? AND status = 'fulfilled'"
            )->execute([(int)$m['request_id']]);
        }
        $db->commit();
        flash('success', 'Entry voided — stock recalculated.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Could not void: ' . $e->getMessage());
    }
    $ret = (string)($_POST['return'] ?? '');
    header('Location: ' . (str_starts_with($ret, 'index.php?page=uniform_') ? $ret : $back)); exit;
}

// ── Handler: save one employee's sizes ──────────────────
function doUniformSaveSizes(): void {
    $back = 'index.php?page=uniform_staff';
    uniPostGate($back);
    $emp = getEmployee((int)($_POST['employee_id'] ?? 0));
    if (!$emp) {
        flash('error', 'Employee not found.');
        header("Location: {$back}"); exit;
    }
    $bad = [];
    try {
        foreach (uniItems(true) as $iid => $item) {
            if (!array_key_exists((string)$iid, (array)($_POST['size'] ?? []))) continue;
            $raw  = (string)($_POST['size'][$iid] ?? '');
            $size = uniNormalizeSize($item, $raw);
            if ($size === null && trim($raw) !== '') { $bad[] = $item['name'] . ' "' . $raw . '"'; continue; }
            uniSaveSize((int)$emp['id'], (int)$iid, $size);
        }
    } catch (Exception $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }
    flash($bad ? 'error' : 'success', $bad
        ? 'Saved, except sizes not in the item list: ' . implode(', ', $bad) . '.'
        : 'Sizes saved for ' . $emp['full_name'] . '.');
    $ret = (string)($_POST['return'] ?? '');
    header('Location: ' . (str_starts_with($ret, 'index.php?page=uniform_') ? $ret : $back)); exit;
}

// ── Size register CSV ───────────────────────────────────
// Header: Emp Id, then one column per item named like the item ("Jeans
// Pant", "T-Shirt"). Any other column (Sr. No, Outlet Name, EMP Name) is
// ignored, so the old sheet can be saved as CSV and fed straight in.
function uniNormHeader($raw): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$raw)));
}

function uniMapSizeCsvHeader(array $header): array {
    $codeCol = null; $itemCols = [];
    $codeAliases = ['empid', 'employeeid', 'code', 'empcode', 'employeecode'];
    foreach ($header as $pos => $cell) {
        $n = uniNormHeader($cell);
        if ($n === '') continue;
        if ($codeCol === null && in_array($n, $codeAliases, true)) { $codeCol = $pos; continue; }
        foreach (uniItems(true) as $iid => $it) {
            if (isset($itemCols[$iid])) continue;
            $name  = uniNormHeader($it['name']);
            $first = uniNormHeader(explode(' ', trim($it['name']))[0]);
            // Exact, or sharing the item's first word — the old sheet heads
            // its jeans column "Jeans  Paint".
            if ($n === $name || ($first !== '' && str_starts_with($n, $first))) {
                $itemCols[$iid] = $pos;
                break;
            }
        }
    }
    return [$codeCol, $itemCols];
}

function doUniformSizesSampleCsv(): void {
    if (!uniCanManage() || !uniSchemaReady()) { http_response_code(403); echo 'Access denied.'; return; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniform_sizes_sample.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $items = uniItems(true);
    fputcsv($out, array_merge(['Emp Id', 'EMP Name'], array_column($items, 'name')), ',', '"', '');
    $demo = [['7001', 'Example Employee'], ['7002', 'Another Employee']];
    foreach ($demo as $i => $d) {
        $row = $d;
        foreach ($items as $it) $row[] = $it['size_list'][min($i + 2, count($it['size_list']) - 1)] ?? '';
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

function doUniformImportSizes(): void {
    $back = 'index.php?page=uniform_staff';
    uniPostGate($back);
    $file = $_FILES['csv'] ?? null;
    if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
        flash('error', 'Pick a CSV file to import.');
        header("Location: {$back}"); exit;
    }
    if ($file['size'] > UNI_CSV_MAX_BYTES) {
        flash('error', 'File too large (max ' . (UNI_CSV_MAX_BYTES / 1024 / 1024) . ' MB).');
        header("Location: {$back}"); exit;
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        flash('error', 'Only .csv files are allowed. In Excel use File › Save As › CSV and try again.');
        header("Location: {$back}"); exit;
    }
    $fh = fopen($file['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read the file.'); header("Location: {$back}"); exit; }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    [$codeCol, $itemCols] = uniMapSizeCsvHeader(fgetcsv($fh, null, ',', '"', '') ?: []);
    if ($codeCol === null || !$itemCols) {
        fclose($fh);
        flash('error', 'The CSV needs an "Emp Id" column and at least one item column ('
                     . implode(', ', array_column(uniItems(true), 'name')) . ').');
        header("Location: {$back}"); exit;
    }

    $asIssued = !empty($_POST['as_issued']);
    $date     = uniPostDate();

    // Read everything first: an "as issued" import is all-or-nothing on
    // stock, so the shortfall has to be known before anything is written.
    $plan = []; $errors = []; $line = 1; $rows = 0;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
        if (++$rows > UNI_CSV_MAX_ROWS) { $errors[] = 'Stopped at ' . UNI_CSV_MAX_ROWS . ' rows.'; break; }
        $code = trim((string)($r[$codeCol] ?? ''));
        if (preg_match('/^(\d+)\.0+$/', $code, $m)) $code = $m[1];
        if ($code === '' || $code === '0') { $errors[] = "Line {$line}: no Emp Id — skipped."; continue; }
        $emp = uniEmployeeByCode($code);
        if (!$emp) { $errors[] = "Line {$line}: no employee with code {$code}."; continue; }
        foreach ($itemCols as $iid => $pos) {
            $item = uniItem($iid);
            $raw  = trim((string)($r[$pos] ?? ''));
            $size = uniNormalizeSize($item, $raw);
            if ($size === null) {
                if ($raw !== '' && $raw !== '0') $errors[] = "Line {$line}: {$item['name']} size \"{$raw}\" is not in the list.";
                continue;
            }
            $plan[] = [$emp, $item, $size];
        }
    }
    fclose($fh);

    // Issues to write: only where the person is not already holding a
    // full issue of that item, so importing the same sheet twice issues
    // nothing the second time.
    $issues = [];
    if ($asIssued) {
        $holdings = uniHoldings();
        $need     = [];
        foreach ($plan as [$emp, $item, $size]) {
            $short = (int)$item['qty_per_issue'] - uniHeldTotal($holdings, (int)$emp['id'], (int)$item['id']);
            if ($short <= 0) continue;
            $issues[] = [$emp, $item, $size, $short];
            $k = $item['id'] . '|' . $size;
            $need[$k] = ($need[$k] ?? 0) + $short;
        }
        $stock = uniStockMatrix();
        $short = [];
        foreach ($need as $k => $n) {
            [$iid, $size] = explode('|', $k, 2);
            $have = $stock[(int)$iid][$size]['available'] ?? 0;
            if ($have < $n) $short[] = uniItem((int)$iid)['name'] . " {$size}: need {$n}, have {$have}";
        }
        if ($short) {
            flash('error', 'Nothing imported — not enough stock to record these as issued. '
                         . 'Receive the stock first. Short: ' . implode('; ', $short) . '.');
            header("Location: {$back}"); exit;
        }
    }

    $db = getDb();
    try {
        $db->beginTransaction();
        foreach ($plan as [$emp, $item, $size]) uniSaveSize((int)$emp['id'], (int)$item['id'], $size);
        foreach ($issues as [$emp, $item, $size, $q]) {
            uniInsertMove(['item_id' => $item['id'], 'size' => $size, 'move_type' => 'issue',
                           'qty' => -$q, 'employee' => $emp, 'move_date' => $date,
                           'notes' => 'Imported from size sheet']);
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Import failed, nothing saved: ' . $e->getMessage());
        header("Location: {$back}"); exit;
    }

    $msg = 'Import done — ' . count($plan) . ' size(s) saved'
         . ($asIssued ? ', ' . count($issues) . ' issue(s) recorded' : '') . '.';
    if ($errors) {
        $msg .= ' ' . count($errors) . ' problem(s): ' . implode(' ', array_slice($errors, 0, 10))
              . (count($errors) > 10 ? ' …' : '');
    }
    flash($errors ? 'error' : 'success', $msg);
    header("Location: {$back}"); exit;
}

// ── Handler: requests ───────────────────────────────────
function doUniformSaveRequest(): void {
    $back = 'index.php?page=uniform_requests';
    uniPostGate($back);
    $emp  = getEmployee((int)($_POST['employee_id'] ?? 0));
    $item = uniItem((int)($_POST['item_id'] ?? 0));
    $size = $item ? uniNormalizeSize($item, $_POST['size'] ?? '') : null;
    $q    = max(1, (int)($_POST['qty'] ?? 0));
    if (!$emp || !$item || $size === null) {
        flash('error', 'Pick the employee, item and size.');
        header("Location: {$back}&new=1"); exit;
    }
    try {
        getDb()->prepare(
            'INSERT INTO uniform_requests
                (employee_id, employee_code, employee_name, item_id, size, qty, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([(int)$emp['id'], $emp['employee_code'], $emp['full_name'], (int)$item['id'],
                    $size, $q, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255) ?: null,
                    uniActor()]);
        if (!empty($_POST['save_size'])) uniSaveSize((int)$emp['id'], (int)$item['id'], $size);
        flash('success', "Request raised: {$q} × {$item['name']} {$size} for {$emp['full_name']}.");
    } catch (Exception $e) {
        flash('error', 'Could not save: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

function doUniformCancelRequest(): void {
    $back = 'index.php?page=uniform_requests';
    uniPostGate($back);
    try {
        getDb()->prepare(
            "UPDATE uniform_requests SET status = 'cancelled', closed_by = ?, closed_at = NOW()
             WHERE id = ? AND status = 'open'"
        )->execute([uniActor(), (int)($_POST['id'] ?? 0)]);
        flash('success', 'Request cancelled.');
    } catch (Exception $e) {
        flash('error', 'Could not cancel: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// ── Handler: item setup ─────────────────────────────────
function doUniformSaveItem(): void {
    $back = 'index.php?page=uniform_items';
    uniPostGate($back);
    $id    = (int)($_POST['id'] ?? 0);
    $name  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
    $sizes = uniSplitSizes((string)($_POST['sizes'] ?? ''));
    $per   = max(1, (int)($_POST['qty_per_issue'] ?? 1));
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $act   = !empty($_POST['is_active']) ? 1 : 0;
    if ($name === '' || !$sizes) {
        flash('error', 'An item needs a name and at least one size.');
        header("Location: {$back}"); exit;
    }
    if (strlen(implode(',', $sizes)) > 500) {
        flash('error', 'Too many sizes for one item.');
        header("Location: {$back}"); exit;
    }
    try {
        if ($id > 0) {
            $old = uniItem($id);
            if (!$old) { flash('error', 'Item not found.'); header("Location: {$back}"); exit; }
            // A size can only be dropped once nothing refers to it — no
            // stock movement and no one registered in it.
            $dropped = array_diff($old['size_list'], $sizes);
            if ($dropped) {
                $in = implode(',', array_fill(0, count($dropped), '?'));
                $p  = array_merge([$id], array_values($dropped));
                $st = getDb()->prepare("SELECT DISTINCT size FROM uniform_moves WHERE item_id = ? AND size IN ({$in})
                                        UNION SELECT DISTINCT size FROM uniform_staff_sizes WHERE item_id = ? AND size IN ({$in})
                                        UNION SELECT DISTINCT size FROM uniform_requests WHERE item_id = ? AND size IN ({$in})");
                $st->execute(array_merge($p, $p, $p));
                $used = $st->fetchAll(PDO::FETCH_COLUMN);
                if ($used) {
                    flash('error', 'Cannot remove size(s) ' . implode(', ', $used)
                                 . ' — they have stock entries, requests or staff registered in them.');
                    header("Location: {$back}"); exit;
                }
            }
            getDb()->prepare('UPDATE uniform_items SET name=?, sizes=?, qty_per_issue=?, sort_order=?, is_active=? WHERE id=?')
                   ->execute([$name, implode(',', $sizes), $per, $sort, $act, $id]);
            flash('success', $name . ' updated.');
        } else {
            getDb()->prepare('INSERT INTO uniform_items (name, sizes, qty_per_issue, sort_order, is_active) VALUES (?,?,?,?,?)')
                   ->execute([$name, implode(',', $sizes), $per, $sort, $act]);
            flash('success', $name . ' added.');
        }
    } catch (PDOException $e) {
        flash('error', $e->getCode() === '23000' ? 'An item with that name already exists.' : 'Could not save: ' . $e->getMessage());
    }
    header("Location: {$back}"); exit;
}

// Turns every <select data-searchable> on the page into a type-to-search
// box — a few hundred staff are too many to scroll. Every word typed must
// appear somewhere in the option (code, name or outlet, any order), so
// "7001", "tushar" and "maninagar raj" all narrow it down.
//
// The <select> stays in the form, hidden, and still carries the value:
// picking sets it and fires its change event, so the scripts that listen
// on it (size defaults, stock hints) keep working untouched. The search
// box takes over "required", since the browser cannot point at a hidden
// field — typing without picking leaves it invalid.
function uniSearchableSelectScript(): void {
    static $done = false;
    if ($done) return;
    $done = true;
?>
<script>
(function () {
    document.querySelectorAll('select[data-searchable]').forEach(function (sel) {
        var opts = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; })
            .map(function (o) { return { value: o.value, label: o.textContent.replace(/\s+/g, ' ').trim() }; });

        var wrap = document.createElement('div');
        wrap.className = 'combo-wrap';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control combo-input';
        input.placeholder = sel.dataset.searchable || 'Search…';
        input.autocomplete = 'off';
        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'combo-clear';
        clear.setAttribute('aria-label', 'Clear');
        clear.innerHTML = '&times;';
        var list = document.createElement('div');
        list.className = 'combo-dropdown';
        list.setAttribute('role', 'listbox');
        wrap.appendChild(input); wrap.appendChild(clear); wrap.appendChild(list);
        sel.parentNode.insertBefore(wrap, sel);
        sel.style.display = 'none';
        if (sel.required) { sel.required = false; input.required = true; }

        var shown = [], active = -1;
        function current() {
            var o = sel.options[sel.selectedIndex];
            return o && o.value !== '' ? o.textContent.replace(/\s+/g, ' ').trim() : '';
        }
        function validate() {
            input.setCustomValidity(input.value && !sel.value ? 'Pick an employee from the list.' : '');
            wrap.classList.toggle('has-value', !!input.value);
        }
        function render() {
            var words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
            shown = opts.filter(function (o) {
                var l = o.label.toLowerCase();
                return words.every(function (w) { return l.indexOf(w) !== -1; });
            }).slice(0, 100);
            list.innerHTML = '';
            if (!shown.length) {
                var e = document.createElement('div');
                e.className = 'combo-option empty';
                e.textContent = 'No matches';
                list.appendChild(e);
            }
            shown.forEach(function (o, i) {
                var d = document.createElement('div');
                d.className = 'combo-option';
                d.setAttribute('role', 'option');
                d.textContent = o.label;
                d.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(i); });
                list.appendChild(d);
            });
            active = -1;
            list.classList.add('open');
        }
        function pick(i) {
            var o = shown[i];
            if (!o) return;
            sel.value = o.value;
            input.value = o.label;
            list.classList.remove('open');
            validate();
            sel.dispatchEvent(new Event('change'));
        }
        function highlight(i) {
            var nodes = list.querySelectorAll('.combo-option:not(.empty)');
            if (!nodes.length) return;
            active = (i + nodes.length) % nodes.length;
            nodes.forEach(function (n, k) { n.classList.toggle('active', k === active); });
            nodes[active].scrollIntoView({ block: 'nearest' });
        }

        input.value = current();
        validate();
        input.addEventListener('focus', function () { input.select(); render(); });
        input.addEventListener('input', function () {
            if (sel.value !== '') { sel.value = ''; sel.dispatchEvent(new Event('change')); }
            validate();
            render();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown')      { e.preventDefault(); if (!list.classList.contains('open')) render(); highlight(active + 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); highlight(active - 1); }
            else if (e.key === 'Enter' && list.classList.contains('open')) {
                // Enter picks the highlighted row, or the only match —
                // never submits the form half-filled.
                e.preventDefault();
                pick(active >= 0 ? active : (shown.length === 1 ? 0 : -1));
            }
            else if (e.key === 'Escape')    { list.classList.remove('open'); }
        });
        input.addEventListener('blur', function () { list.classList.remove('open'); });
        clear.addEventListener('mousedown', function (e) {
            e.preventDefault();
            input.value = '';
            if (sel.value !== '') { sel.value = ''; sel.dispatchEvent(new Event('change')); }
            validate();
            input.focus();
        });
    });
})();
</script>
<?php
}

// =========================================================
// Pages
// =========================================================

// The sub-navigation every Uniforms page carries.
function uniTabs(string $current): void {
    $tabs = [
        'uniforms'         => 'Stock',
        'uniform_staff'    => 'Staff Sizes',
        'uniform_ledger'   => 'Movements',
        'uniform_requests' => 'Requests',
        'uniform_items'    => 'Items',
    ];
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">';
    foreach ($tabs as $p => $l) {
        $cls = $p === $current ? 'btn-primary' : 'btn-ghost';
        echo '<a href="?page=' . $p . '" class="btn btn-sm ' . $cls . '">' . h($l) . '</a>';
    }
    echo '</div>';
}

// ── Page: stock overview ────────────────────────────────
function pageUniforms(): void {
    if (!uniPageGate()) return;
    $items   = uniItems(true);
    $stock   = uniStockMatrix();
    $reqs    = uniOpenRequestQty();
    $pending = uniPendingDemand();
?>
<div class="page-header">
    <h2>Uniforms · Stock</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=uniform_move&type=receive" class="btn btn-secondary">+ Receive Stock</a>
        <a href="?page=uniform_move&type=issue" class="btn btn-primary">+ Issue</a>
        <a href="?page=uniform_move&type=return" class="btn btn-ghost">Return</a>
        <a href="?page=uniform_move&type=exchange" class="btn btn-ghost">Exchange</a>
        <a href="?page=uniform_move&type=adjust" class="btn btn-ghost">Adjust</a>
    </div>
</div>
<?php uniTabs('uniforms'); ?>

<?php if (!$items): ?>
<div class="alert alert-info">No uniform items yet — add them on the <a href="?page=uniform_items">Items</a> tab.</div>
<?php endif; ?>

<?php foreach ($items as $iid => $it):
    $sizes = array_keys($stock[$iid] ?? []);
    $tot   = ['receive' => 0, 'issue' => 0, 'return' => 0, 'adjust' => 0, 'available' => 0, 'pending' => 0, 'req' => 0, 'short' => 0];
    $cells = [];
    foreach ($sizes as $s) {
        $c = $stock[$iid][$s];
        $c['pending'] = $pending[$iid][$s] ?? 0;
        $c['req']     = $reqs[$iid][$s] ?? 0;
        $c['short']   = max(0, $c['pending'] + $c['req'] - $c['available']);
        foreach ($tot as $k => $_) $tot[$k] += $c[$k];
        $cells[$s] = $c;
    }
    $rowsDef = [
        ['receive',   'Received',              ''],
        ['issue',     'Issued',                ''],
        ['return',    'Returned',              ''],
        ['adjust',    'Adjusted',              ''],
        ['available', 'Available',             'font-weight:700'],
        ['pending',   'Pending staff issue',   'color:var(--muted)'],
        ['req',       'Open requests',         'color:var(--muted)'],
        ['short',     'Short — to order',      'color:var(--red);font-weight:700'],
    ];
?>
<div class="form-card" style="margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0"><?= h($it['name']) ?></h3>
        <span class="text-muted"><?= (int)$it['qty_per_issue'] ?> piece(s) per issue ·
            <a href="?page=uniform_ledger&item=<?= (int)$iid ?>&filter=1">movements</a></span>
    </div>
    <div class="table-wrap" style="margin-top:10px">
    <table class="table">
        <thead>
            <tr>
                <th></th>
                <?php foreach ($sizes as $s): ?><th style="text-align:center"><?= h($s) ?></th><?php endforeach; ?>
                <th style="text-align:center">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rowsDef as [$k, $label, $style]):
            if ($k === 'adjust' && !array_filter(array_column($cells, 'adjust'))) continue; ?>
            <tr>
                <td style="<?= $style ?>;white-space:nowrap"><?= h($label) ?></td>
                <?php foreach ($sizes as $s):
                    $v = $cells[$s][$k];
                    $st = $style;
                    if ($k === 'available' && $v <= 0) $st .= ';color:var(--red)';
                ?>
                <td style="text-align:center;<?= $st ?>"><?= $v === 0 && in_array($k, ['pending','req','short','adjust'], true) ? '<span class="text-muted">·</span>' : $v ?></td>
                <?php endforeach; ?>
                <td style="text-align:center;font-weight:700;<?= $k === 'short' && $tot[$k] > 0 ? 'color:var(--red)' : '' ?>"><?= $tot[$k] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>
<p class="hint">
    <strong>Available</strong> = Received − Issued + Returned ± Adjusted, worked out from the movement ledger.
    <strong>Pending staff issue</strong> is the pieces still owed to serving staff whose size is in the register;
    <strong>Short</strong> is how many more of a size are needed to cover that and the open requests.
</p>
<?php
}

// ── Page: staff size register ───────────────────────────
function pageUniformStaff(): void {
    if (!uniPageGate()) return;
    $items    = uniItems(true);
    $sizes    = uniStaffSizes();
    $holdings = uniHoldings();
    $locNames = uniLocationNames();
    $emps     = uniRegisterEmployees($holdings);

    $search = trim((string)($_GET['search'] ?? ''));
    $locSel = array_values(array_filter(array_map('intval', (array)($_GET['loc'] ?? []))));
    $status = (string)($_GET['status'] ?? '');
    $editId = (int)($_GET['edit'] ?? 0);
    $locOptions = [];
    foreach (getActiveLocations() as $l) $locOptions[(string)$l['location_id']] = $l['location_name'];
    // Every outlet ticked is no filter at all — it must not hide staff
    // with no outlet set.
    if (count($locSel) >= count($locOptions)) $locSel = [];

    $rows = [];
    $counts = ['all' => 0, 'pending' => 0, 'nosize' => 0, 'issued' => 0, 'left' => 0];
    foreach ($emps as $e) {
        $eid = (int)$e['id'];
        $st  = [];
        foreach ($items as $iid => $it) {
            $st[$iid] = uniStatus($sizes[$eid][$iid] ?? null, uniHeldTotal($holdings, $eid, $iid), (int)$it['qty_per_issue']);
        }
        $left = (int)$e['is_active'] !== 1;
        $e['_st']   = $st;
        $e['_left'] = $left;
        $counts['all']++;
        if ($left) $counts['left']++;
        elseif (in_array('pending', $st, true)) $counts['pending']++;
        if (!$left && in_array('nosize', $st, true)) $counts['nosize']++;
        if (!$left && $st && !array_diff($st, ['issued'])) $counts['issued']++;

        if ($search !== '' && stripos($e['employee_code'] . ' ' . $e['full_name'] . ' ' . ($locNames[(int)$e['location_id']] ?? ''), $search) === false) continue;
        if ($locSel && !in_array((int)$e['location_id'], $locSel, true)) continue;
        if ($status === 'pending' && ($left || !in_array('pending', $st, true))) continue;
        if ($status === 'nosize'  && ($left || !in_array('nosize', $st, true))) continue;
        if ($status === 'issued'  && ($left || !$st || array_diff($st, ['issued']))) continue;
        if ($status === 'left'    && !$left) continue;
        $rows[] = $e;
    }
    $sortKey = fn($e) => mb_strtolower(($locNames[(int)$e['location_id']] ?? "\u{FFFF}") . "\t" . $e['full_name']);
    usort($rows, fn($a, $b) => strcmp($sortKey($a), $sortKey($b)));

    $qs = http_build_query(array_filter(['search' => $search, 'loc' => $locSel, 'status' => $status]));
    $self = 'index.php?page=uniform_staff' . ($qs !== '' ? '&' . $qs : '');
?>
<div class="page-header">
    <h2>Uniforms · Staff Sizes</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=export_uniform_staff<?= $qs !== '' ? '&' . h($qs) : '' ?>" class="btn btn-ghost">Export CSV</a>
        <a href="?page=uniform_move&type=issue" class="btn btn-primary">+ Issue</a>
    </div>
</div>
<?php uniTabs('uniform_staff'); ?>

<div class="stats-grid-sm" style="margin-bottom:18px">
    <?php foreach ([
        ['',        'Staff',             $counts['all'] - $counts['left'], ''],
        ['issued',  'Fully issued',      $counts['issued'],  'stat-green'],
        ['pending', 'Pending issue',     $counts['pending'], 'stat-yellow'],
        ['nosize',  'Size missing',      $counts['nosize'],  'stat-red'],
        ['left',    'Left — to recover', $counts['left'],    'stat-purple'],
    ] as [$k, $l, $v, $c]): ?>
    <a href="?page=uniform_staff<?= $k !== '' ? '&status=' . $k : '' ?>" class="stat-card <?= $c ?>" style="text-decoration:none<?= $status === $k ? ';outline:2px solid var(--blue)' : '' ?>">
        <div class="stat-val"><?= (int)$v ?></div><div class="stat-lbl"><?= h($l) ?></div>
    </a>
    <?php endforeach; ?>
</div>

<form method="GET" class="rpt-filter">
    <input type="hidden" name="page" value="uniform_staff">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
    <span class="input-clear-wrap" style="flex:1 1 auto;min-width:200px">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search code, name, outlet..." class="form-control">
        <button type="button" class="input-clear-btn" aria-label="Clear search" tabindex="-1">&times;</button>
    </span>
    <?php msFilterField('loc', 'Outlet', $locOptions, array_map('strval', $locSel ?: array_keys($locOptions)), '220px'); ?>
    <button class="btn btn-primary">Filter</button>
    <a href="?page=uniform_staff" class="btn btn-ghost">Clear</a>
</form>
<?php msFilterScript(); ?>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th>Outlet</th><th>Employee</th>
            <?php foreach ($items as $it): ?><th><?= h($it['name']) ?></th><?php endforeach; ?>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= 3 + count($items) ?>" class="empty-row">No staff match.</td></tr>
    <?php else: foreach ($rows as $e):
        $eid = (int)$e['id'];
        $editing = $editId === $eid;
    ?>
        <tr>
            <td><?= h($locNames[(int)$e['location_id']] ?? '—') ?></td>
            <td>
                <a href="?page=uniform_ledger&emp=<?= $eid ?>"><code><?= h($e['employee_code']) ?></code></a><br>
                <?= h($e['full_name']) ?>
                <?php if ($e['_left']): ?><br><span class="badge badge-purple">Left — recover</span><?php endif; ?>
            </td>
            <?php if ($editing): ?>
            <td colspan="<?= count($items) ?>">
                <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input type="hidden" name="action" value="uniform_save_sizes">
                    <input type="hidden" name="employee_id" value="<?= $eid ?>">
                    <input type="hidden" name="return" value="<?= h($self) ?>">
                    <?php foreach ($items as $iid => $it): ?>
                    <label style="font-size:12px"><?= h($it['name']) ?>
                        <select name="size[<?= (int)$iid ?>]" class="form-control" style="width:auto;display:inline-block">
                            <option value="">—</option>
                            <?php foreach ($it['size_list'] as $s): ?>
                            <option value="<?= h($s) ?>" <?= ($sizes[$eid][$iid] ?? '') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endforeach; ?>
                    <button class="btn btn-sm btn-primary">Save</button>
                    <a href="<?= h($self) ?>" class="btn btn-sm btn-ghost">Cancel</a>
                </form>
            </td>
            <?php else: foreach ($items as $iid => $it):
                $size = $sizes[$eid][$iid] ?? null;
                $held = $holdings[$eid][$iid] ?? [];
                $st   = $e['_st'][$iid];
            ?>
            <td>
                <strong><?= $size !== null ? h($size) : '<span class="text-muted">—</span>' ?></strong>
                <?php if ($st === 'issued'): ?>
                    <span class="badge badge-green">Issued</span>
                <?php elseif ($st === 'pending'): ?>
                    <span class="badge badge-yellow">Pending</span>
                <?php elseif (!$e['_left']): ?>
                    <span class="badge badge-red">No size</span>
                <?php endif; ?>
                <?php if ($held): ?>
                <br><span class="text-muted">holds
                    <?= h(implode(', ', array_map(fn($s, $n) => "{$s}×{$n}", array_keys($held), $held))) ?></span>
                <?php endif; ?>
            </td>
            <?php endforeach; endif; ?>
            <td class="actions">
                <?php if (!$editing): ?>
                <a href="<?= h($self . '&edit=' . $eid) ?>" class="btn btn-sm btn-secondary">Sizes</a>
                <?php endif; ?>
                <?php if (!$e['_left'] && in_array('pending', $e['_st'], true)): ?>
                <a href="?page=uniform_move&type=issue&emp=<?= $eid ?>" class="btn btn-sm btn-primary">Issue</a>
                <?php endif; ?>
                <?php if ($e['_left'] || !empty($holdings[$eid])): ?>
                <a href="?page=uniform_move&type=return&emp=<?= $eid ?>" class="btn btn-sm btn-ghost">Return</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<p class="table-count"><?= count($rows) ?> employee(s)</p>

<div class="form-card" style="margin-top:18px">
    <div class="form-section-title">Import sizes from CSV</div>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="uniform_import_sizes">
        <div class="form-group" style="flex:1 1 280px;margin:0">
            <label>CSV file</label>
            <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
            <span class="hint">
                Columns: <code>Emp Id</code> plus one per item
                (<code><?= h(implode('</code>, <code>', array_column($items, 'name'))) ?></code>).
                Other columns such as Outlet or Name are ignored, so the existing staff size sheet can be saved as CSV and imported as it is.
                XXXL is read as 3XL; blank or 0 is skipped.
            </span>
        </div>
        <div class="form-group" style="margin:0">
            <label class="rpt-filter-chk"><input type="checkbox" name="as_issued" value="1"> Also record as issued on</label>
            <input type="date" name="move_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div style="display:flex;gap:8px;margin-bottom:2px">
            <button class="btn btn-primary">Import</button>
            <a href="?page=uniform_sizes_sample" class="btn btn-ghost">Sample CSV</a>
        </div>
    </form>
    <p class="hint" style="margin-top:8px">
        "Also record as issued" books one full issue of each item to everyone on the sheet who is not already holding one —
        use it for the dispatch list of uniforms already handed out. It checks stock first and imports nothing if any size would go below zero,
        so receive the stock before importing. Importing the same sheet again issues nothing twice.
    </p>
</div>
<?php
}

function exportUniformStaffCsv(): void {
    if (!uniCanManage() || !uniSchemaReady()) { http_response_code(403); echo 'Access denied.'; return; }
    $items    = uniItems(true);
    $sizes    = uniStaffSizes();
    $holdings = uniHoldings();
    $locNames = uniLocationNames();
    $locSel   = array_values(array_filter(array_map('intval', (array)($_GET['loc'] ?? []))));
    $search   = trim((string)($_GET['search'] ?? ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniform_staff_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Outlet Name', 'Emp Id', 'EMP Name', 'Status'];
    foreach ($items as $it) array_push($head, $it['name'], $it['name'] . ' Held', $it['name'] . ' Status');
    fputcsv($out, $head, ',', '"', '');
    foreach (uniRegisterEmployees($holdings) as $e) {
        $eid = (int)$e['id'];
        $loc = $locNames[(int)$e['location_id']] ?? '';
        if ($locSel && !in_array((int)$e['location_id'], $locSel, true)) continue;
        if ($search !== '' && stripos($e['employee_code'] . ' ' . $e['full_name'] . ' ' . $loc, $search) === false) continue;
        $row = [$loc, $e['employee_code'], $e['full_name'], (int)$e['is_active'] ? 'Serving' : 'Left'];
        foreach ($items as $iid => $it) {
            $size = $sizes[$eid][$iid] ?? null;
            $held = uniHeldTotal($holdings, $eid, $iid);
            array_push($row, $size ?? '', $held,
                ['issued' => 'Issued', 'pending' => 'Pending', 'nosize' => 'No size'][uniStatus($size, $held, (int)$it['qty_per_issue'])]);
        }
        fputcsv($out, $row, ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── Page: movement form ─────────────────────────────────
function pageUniformMove(): void {
    if (!uniPageGate()) return;
    $type = (string)($_GET['type'] ?? 'issue');
    if (!isset(UNI_FORM_TYPES[$type])) $type = 'issue';
    $items    = uniItems(true);
    $empId    = (int)($_GET['emp'] ?? 0);
    $itemId   = (int)($_GET['item'] ?? 0);
    $reqId    = (int)($_GET['req'] ?? 0);
    $req      = null;
    if ($reqId > 0) {
        $st = getDb()->prepare("SELECT * FROM uniform_requests WHERE id = ? AND status = 'open'");
        $st->execute([$reqId]);
        $req = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($req) { $empId = (int)$req['employee_id']; $itemId = (int)$req['item_id']; }
    }
    $stock    = uniStockMatrix();
    $staff    = uniStaffSizes();
    $holdings = uniHoldings();
    $locNames = uniLocationNames();

    // The data the size picker needs, for the small script at the bottom.
    $js = ['items' => [], 'avail' => [], 'staff' => $staff, 'held' => $holdings];
    foreach ($items as $iid => $it) {
        $js['items'][$iid] = ['sizes' => $it['size_list'], 'per' => (int)$it['qty_per_issue']];
        foreach ($stock[$iid] ?? [] as $s => $c) $js['avail'][$iid][$s] = $c['available'];
    }
    $needsEmp = in_array($type, ['issue', 'return', 'exchange'], true);
    $emps = [];
    if ($needsEmp) {
        try {
            $emps = getDb()->query('SELECT id, employee_code, full_name, location_id, is_active FROM employees ORDER BY full_name')
                           ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $emps = []; }
        // Issue goes to serving staff; a return can come from anyone holding stock.
        $emps = array_values(array_filter($emps, fn($e) =>
            (int)$e['is_active'] === 1 || ($type !== 'issue' && !empty($holdings[(int)$e['id']]))));
    }
?>
<div class="page-header">
    <h2>Uniforms · <?= h(UNI_FORM_TYPES[$type]) ?></h2>
    <a href="?page=uniforms" class="btn btn-ghost">← Stock</a>
</div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
    <?php foreach (UNI_FORM_TYPES as $t => $l): ?>
    <a href="?page=uniform_move&type=<?= $t ?><?= $empId && $t !== 'receive' && $t !== 'adjust' ? '&emp=' . $empId : '' ?>"
       class="btn btn-sm <?= $t === $type ? 'btn-primary' : 'btn-ghost' ?>"><?= h($l) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($req): ?>
<div class="alert alert-info">Fulfilling request #<?= (int)$req['id'] ?>: <?= (int)$req['qty'] ?> × <?= h(uniItem((int)$req['item_id'])['name'] ?? '') ?> <?= h($req['size']) ?> for <?= h($req['employee_name']) ?>.</div>
<?php endif; ?>

<div class="form-card">
<form method="POST" id="uniMoveForm">
    <input type="hidden" name="action" value="uniform_move">
    <input type="hidden" name="type"   value="<?= h($type) ?>">
    <?php if ($req): ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><?php endif; ?>

<?php if ($type === 'receive'): ?>
    <div class="form-grid">
        <div class="form-group">
            <label>Date Received <span class="required">*</span></label>
            <input type="date" name="move_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label>Supplier / Bill / Challan No.</label>
            <input type="text" name="reference" class="form-control" maxlength="100">
        </div>
    </div>
    <?php foreach ($items as $iid => $it): ?>
    <div class="form-section-title"><?= h($it['name']) ?> — pieces received per size</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($it['size_list'] as $s): ?>
        <div class="form-group" style="width:80px;margin:0">
            <label style="text-align:center"><?= h($s) ?></label>
            <input type="number" name="qty[<?= (int)$iid ?>][<?= h($s) ?>]" class="form-control" min="0" step="1" placeholder="0" style="text-align:center">
            <span class="hint" style="text-align:center">has <?= (int)($stock[$iid][$s]['available'] ?? 0) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="form-group" style="margin-top:14px">
        <label>Notes</label>
        <input type="text" name="notes" class="form-control" maxlength="255">
    </div>

<?php else: ?>
    <div class="form-grid">
        <?php if ($needsEmp): ?>
        <div class="form-group">
            <label>Employee <span class="required">*</span></label>
            <select name="employee_id" id="uniEmp" class="form-control" required data-searchable="Type code, name or outlet…">
                <option value="">— Select —</option>
                <?php foreach ($emps as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $empId === (int)$e['id'] ? 'selected' : '' ?>>
                    <?= h($e['employee_code'] . ' — ' . $e['full_name']
                        . (isset($locNames[(int)$e['location_id']]) ? ' · ' . $locNames[(int)$e['location_id']] : '')
                        . ((int)$e['is_active'] ? '' : ' · LEFT')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Item <span class="required">*</span></label>
            <select name="item_id" id="uniItem" class="form-control" required>
                <?php foreach ($items as $iid => $it): ?>
                <option value="<?= (int)$iid ?>" <?= $itemId === (int)$iid ? 'selected' : '' ?>><?= h($it['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($type === 'exchange'): ?>
        <div class="form-group">
            <label>Size handed back <span class="required">*</span></label>
            <select name="return_size" id="uniRetSize" class="form-control" required></select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label><?= $type === 'exchange' ? 'New size given' : ($type === 'return' ? 'Size returned' : 'Size') ?> <span class="required">*</span></label>
            <select name="size" id="uniSize" class="form-control" required data-want="<?= h($req['size'] ?? '') ?>"></select>
            <span class="hint" id="uniAvail"></span>
        </div>
        <div class="form-group">
            <label>Quantity (pieces) <span class="required">*</span></label>
            <input type="number" name="qty" id="uniQty" class="form-control" required step="1"
                   <?= $type === 'adjust' ? '' : 'min="1"' ?>
                   value="<?= $req ? (int)$req['qty'] : '' ?>">
            <?php if ($type === 'adjust'): ?>
            <span class="hint">Positive adds to stock, negative removes (damaged, lost, count correction).</span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label>Date <span class="required">*</span></label>
            <input type="date" name="move_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Notes<?= $type === 'adjust' ? ' / Reason <span class="required">*</span>' : '' ?></label>
            <input type="text" name="notes" class="form-control" maxlength="255" <?= $type === 'adjust' ? 'required' : '' ?>>
        </div>
        <?php if ($type === 'issue' || $type === 'exchange'): ?>
        <div class="form-group" style="grid-column:1/-1">
            <label class="rpt-filter-chk"><input type="checkbox" name="save_size" value="1" checked> Save this as the employee's size in the register</label>
        </div>
        <?php endif; ?>
        <?php if ($type === 'return'): ?>
        <div class="form-group" style="grid-column:1/-1">
            <label class="rpt-filter-chk"><input type="checkbox" name="damaged" value="1"> Damaged — do not put back into stock</label>
        </div>
        <?php endif; ?>
        <?php if ($type === 'return' || $type === 'exchange'): ?>
        <div class="form-group" style="grid-column:1/-1">
            <label class="rpt-filter-chk"><input type="checkbox" name="allow_unrecorded" value="1"> Accept even if the pieces were not issued through this system</label>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="?page=uniforms" class="btn btn-ghost">Cancel</a>
    </div>
</form>
</div>

<?php if ($needsEmp) uniSearchableSelectScript(); ?>
<?php if ($type !== 'receive'): ?>
<script>
(function () {
    var D     = <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var type  = <?= json_encode($type) ?>;
    var emp   = document.getElementById('uniEmp');
    var item  = document.getElementById('uniItem');
    var size  = document.getElementById('uniSize');
    var ret   = document.getElementById('uniRetSize');
    var qty   = document.getElementById('uniQty');
    var info  = document.getElementById('uniAvail');
    var firstFill = true;

    function held(e, i) { return (D.held[e] && D.held[e][i]) || {}; }
    function fill(sel, list, want, label) {
        sel.innerHTML = '';
        list.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s; o.textContent = label ? label(s) : s;
            if (s === want) o.selected = true;
            sel.appendChild(o);
        });
    }
    function refresh() {
        var i = item.value, it = D.items[i];
        if (!it) return;
        var e = emp ? emp.value : '';
        var reg = (e && D.staff[e] && D.staff[e][i]) || '';
        var h = held(e, i);
        var avail = D.avail[i] || {};
        var want = (firstFill && size.dataset.want) || reg;
        if (type === 'return') {
            var hs = Object.keys(h);
            fill(size, hs.length ? hs.concat(it.sizes.filter(function (s) { return hs.indexOf(s) < 0; })) : it.sizes,
                 hs[0] || reg, function (s) { return s + (h[s] ? '  (holds ' + h[s] + ')' : ''); });
        } else {
            fill(size, it.sizes, want, function (s) {
                return type === 'adjust' || type === 'issue' || type === 'exchange'
                    ? s + '  (in stock ' + (avail[s] || 0) + ')' : s;
            });
        }
        if (ret) {
            var hk = Object.keys(h);
            fill(ret, hk.length ? hk : it.sizes, hk[0] || reg, function (s) { return s + (h[s] ? '  (holds ' + h[s] + ')' : ''); });
        }
        if (qty && !qty.value && type !== 'adjust') {
            qty.value = type === 'return' ? (h[size.value] || it.per) : it.per;
        }
        firstFill = false;
        showInfo();
    }
    function showInfo() {
        if (!info) return;
        var e = emp ? emp.value : '';
        var reg = (e && D.staff[e] && D.staff[e][item.value]) || '';
        var a = (D.avail[item.value] || {})[size.value] || 0;
        var bits = [];
        if (type !== 'return') bits.push(a + ' in stock');
        if (reg) bits.push('register size: ' + reg);
        info.textContent = bits.join(' · ');
    }
    if (emp) emp.addEventListener('change', function () { if (qty && type !== 'adjust') qty.value = ''; refresh(); });
    item.addEventListener('change', function () { if (qty && type !== 'adjust') qty.value = ''; refresh(); });
    size.addEventListener('change', showInfo);
    refresh();
})();
</script>
<?php endif; ?>
<?php
}

// ── Page: movement ledger ───────────────────────────────
function uniLedgerFilters(): array {
    $f = [
        'emp'    => (int)($_GET['emp'] ?? 0),
        'item'   => (int)($_GET['item'] ?? 0),
        'type'   => (string)($_GET['type'] ?? ''),
        'from'   => (string)($_GET['from'] ?? ''),
        'to'     => (string)($_GET['to'] ?? ''),
        'search' => trim((string)($_GET['search'] ?? '')),
        'voided' => !empty($_GET['voided']),
    ];
    if (!isset(UNI_MOVE_TYPES[$f['type']])) $f['type'] = '';
    foreach (['from', 'to'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
    return $f;
}

function uniLedgerRows(array $f, int $limit = 1000): array {
    $sql = 'SELECT * FROM uniform_moves WHERE 1=1';
    $p   = [];
    if (!$f['voided'])  { $sql .= ' AND voided_at IS NULL'; }
    if ($f['emp'])      { $sql .= ' AND employee_id = ?'; $p[] = $f['emp']; }
    if ($f['item'])     { $sql .= ' AND item_id = ?';     $p[] = $f['item']; }
    if ($f['type'])     { $sql .= ' AND move_type = ?';   $p[] = $f['type']; }
    if ($f['from'])     { $sql .= ' AND move_date >= ?';  $p[] = $f['from']; }
    if ($f['to'])       { $sql .= ' AND move_date <= ?';  $p[] = $f['to']; }
    if ($f['search'] !== '') {
        $sql .= ' AND (employee_code LIKE ? OR employee_name LIKE ? OR reference LIKE ? OR notes LIKE ? OR size = ?)';
        $like = '%' . $f['search'] . '%';
        array_push($p, $like, $like, $like, $like, $f['search']);
    }
    $sql .= ' ORDER BY move_date DESC, id DESC' . ($limit > 0 ? ' LIMIT ' . (int)$limit : '');
    try {
        $st = getDb()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function pageUniformLedger(): void {
    if (!uniPageGate()) return;
    $f        = uniLedgerFilters();
    $rows     = uniLedgerRows($f);
    $items    = uniItems();
    $locNames = uniLocationNames();
    $emp      = $f['emp'] ? getEmployee($f['emp']) : null;
    $qs       = http_build_query(array_filter($f));
    $self     = 'index.php?page=uniform_ledger' . ($qs !== '' ? '&' . $qs : '');
?>
<div class="page-header">
    <h2><?= $emp ? 'Uniforms · ' . h($emp['full_name']) : 'Uniforms · Movements' ?></h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=export_uniform_ledger<?= $qs !== '' ? '&' . h($qs) : '' ?>" class="btn btn-ghost">Export CSV</a>
        <?php if ($emp): ?>
        <a href="?page=uniform_move&type=issue&emp=<?= (int)$emp['id'] ?>" class="btn btn-primary">+ Issue</a>
        <a href="?page=uniform_move&type=return&emp=<?= (int)$emp['id'] ?>" class="btn btn-ghost">Return</a>
        <a href="?page=uniform_move&type=exchange&emp=<?= (int)$emp['id'] ?>" class="btn btn-ghost">Exchange</a>
        <?php endif; ?>
    </div>
</div>
<?php uniTabs('uniform_ledger'); ?>

<?php if ($emp):
    $sizes    = uniStaffSizes()[(int)$emp['id']] ?? [];
    $holdings = uniHoldings()[(int)$emp['id']] ?? [];
?>
<div class="form-card" style="margin-bottom:18px">
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <div><strong><code><?= h($emp['employee_code']) ?></code> — <?= h($emp['full_name']) ?></strong></div>
        <div class="text-muted"><?= h($locNames[(int)$emp['location_id']] ?? '') ?></div>
        <?php if (!(int)$emp['is_active']): ?><span class="badge badge-purple">Left</span><?php endif; ?>
        <?php foreach (uniItems(true) as $iid => $it): $h = $holdings[$iid] ?? []; ?>
        <div>
            <?= h($it['name']) ?>: <strong><?= h($sizes[$iid] ?? '—') ?></strong>
            <span class="text-muted">· holds <?= $h ? h(implode(', ', array_map(fn($s, $n) => "{$s}×{$n}", array_keys($h), $h))) : 'none' ?></span>
        </div>
        <?php endforeach; ?>
        <a href="?page=uniform_staff&edit=<?= (int)$emp['id'] ?>&search=<?= urlencode($emp['employee_code']) ?>" class="btn btn-sm btn-secondary" style="margin-left:auto">Edit Sizes</a>
    </div>
</div>
<?php endif; ?>

<form method="GET" class="rpt-filter" style="flex-wrap:wrap">
    <input type="hidden" name="page"   value="uniform_ledger">
    <input type="hidden" name="filter" value="1">
    <?php if ($f['emp']): ?><input type="hidden" name="emp" value="<?= (int)$f['emp'] ?>"><?php endif; ?>
    <input type="text" name="search" value="<?= h($f['search']) ?>" placeholder="Code, name, reference, size..." class="form-control" style="flex:1 1 200px">
    <select name="item" class="form-control" style="width:auto">
        <option value="">All items</option>
        <?php foreach ($items as $iid => $it): ?>
        <option value="<?= (int)$iid ?>" <?= $f['item'] === (int)$iid ? 'selected' : '' ?>><?= h($it['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="type" class="form-control" style="width:auto">
        <option value="">All movements</option>
        <?php foreach (UNI_MOVE_TYPES as $t => $l): ?>
        <option value="<?= $t ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= h($f['from']) ?>" class="form-control" style="width:auto" title="From">
    <input type="date" name="to"   value="<?= h($f['to']) ?>"   class="form-control" style="width:auto" title="To">
    <label class="rpt-filter-chk"><input type="checkbox" name="voided" value="1" <?= $f['voided'] ? 'checked' : '' ?>> Show voided</label>
    <button class="btn btn-primary">Filter</button>
    <a href="?page=uniform_ledger<?= $f['emp'] ? '&emp=' . (int)$f['emp'] : '' ?>" class="btn btn-ghost">Clear</a>
</form>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr><th>Date</th><th>Movement</th><th>Item / Size</th><th style="text-align:right">Qty</th>
            <th>Employee</th><th>Reference / Notes</th><th>By</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty-row">No movements.</td></tr>
    <?php else: foreach ($rows as $m):
        $void = $m['voided_at'] !== null;
        $badge = ['receive' => 'badge-green', 'issue' => 'badge-blue', 'return' => 'badge-amber', 'adjust' => 'badge-grey'][$m['move_type']] ?? 'badge-grey';
    ?>
        <tr class="<?= $void ? 'row-inactive' : '' ?>">
            <td><?= date('d M Y', strtotime((string)$m['move_date'])) ?></td>
            <td>
                <span class="badge <?= $badge ?>"><?= h(UNI_MOVE_TYPES[$m['move_type']] ?? $m['move_type']) ?></span>
                <?php if ($void): ?><br><span class="badge badge-red">Voided</span><?php endif; ?>
            </td>
            <td><?= h($items[(int)$m['item_id']]['name'] ?? '#' . $m['item_id']) ?> · <strong><?= h($m['size']) ?></strong></td>
            <td style="text-align:right;font-weight:700;color:<?= (int)$m['qty'] < 0 ? 'var(--red)' : 'var(--green)' ?>">
                <?= (int)$m['qty'] > 0 ? '+' : '' ?><?= (int)$m['qty'] ?>
            </td>
            <td>
                <?php if ($m['employee_id']): ?>
                <a href="?page=uniform_ledger&emp=<?= (int)$m['employee_id'] ?>"><code><?= h($m['employee_code']) ?></code></a>
                <?= h($m['employee_name']) ?>
                <?php if ($m['location_id']): ?><br><span class="text-muted"><?= h($locNames[(int)$m['location_id']] ?? '') ?></span><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <?= h($m['reference'] ?? '') ?>
                <?php if (!empty($m['notes'])): ?><br><span class="text-muted"><?= h($m['notes']) ?></span><?php endif; ?>
                <?php if ($void): ?>
                <br><span class="text-muted">Voided by <?= h($m['voided_by']) ?> <?= date('d M Y', strtotime((string)$m['voided_at'])) ?><?= !empty($m['void_reason']) ? ' — ' . h($m['void_reason']) : '' ?></span>
                <?php endif; ?>
            </td>
            <td><?= h($m['created_by'] ?? '—') ?><br><span class="text-muted"><?= date('d M H:i', strtotime((string)$m['created_at'])) ?></span></td>
            <td class="actions">
                <?php if (!$void): ?>
                <form method="POST" class="inline-form"
                      onsubmit="var r = prompt('Why is this entry being voided?'); if (r === null) return false; this.reason.value = r; return true;">
                    <input type="hidden" name="action" value="uniform_void_move">
                    <input type="hidden" name="id"     value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <input type="hidden" name="return" value="<?= h($self) ?>">
                    <button class="btn btn-sm btn-danger">Void</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<p class="table-count"><?= count($rows) ?> movement(s)<?= count($rows) >= 1000 ? ' — showing the latest 1000; narrow the filter or export for the rest' : '' ?></p>
<?php
}

function exportUniformLedgerCsv(): void {
    if (!uniCanManage() || !uniSchemaReady()) { http_response_code(403); echo 'Access denied.'; return; }
    $items    = uniItems();
    $locNames = uniLocationNames();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniform_movements_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Movement', 'Item', 'Size', 'Qty', 'Emp Id', 'EMP Name', 'Outlet',
                   'Reference', 'Notes', 'Entered By', 'Entered At', 'Voided At', 'Voided By', 'Void Reason'], ',', '"', '');
    foreach (uniLedgerRows(uniLedgerFilters(), 0) as $m) {
        fputcsv($out, [
            $m['move_date'], UNI_MOVE_TYPES[$m['move_type']] ?? $m['move_type'],
            $items[(int)$m['item_id']]['name'] ?? '', $m['size'], (int)$m['qty'],
            $m['employee_code'] ?? '', $m['employee_name'] ?? '',
            $m['location_id'] ? ($locNames[(int)$m['location_id']] ?? '') : '',
            $m['reference'] ?? '', $m['notes'] ?? '', $m['created_by'] ?? '', $m['created_at'],
            $m['voided_at'] ?? '', $m['voided_by'] ?? '', $m['void_reason'] ?? '',
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

// ── Page: requests ──────────────────────────────────────
function pageUniformRequests(): void {
    if (!uniPageGate()) return;
    $items    = uniItems(true);
    $stock    = uniStockMatrix();
    $locNames = uniLocationNames();
    $showAll  = !empty($_GET['all']);
    $new      = !empty($_GET['new']);
    try {
        $rows = getDb()->query(
            'SELECT r.*, e.location_id FROM uniform_requests r
             LEFT JOIN employees e ON e.id = r.employee_id '
          . ($showAll ? '' : "WHERE r.status = 'open' ")
          . 'ORDER BY r.status = \'open\' DESC, r.created_at DESC LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        $emps = getDb()->query('SELECT id, employee_code, full_name, location_id FROM employees WHERE is_active = 1 ORDER BY full_name')
                       ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = []; $emps = [];
    }
    $preEmp  = (int)($_GET['emp'] ?? 0);
    $preItem = (int)($_GET['item'] ?? 0);
    $preSize = (string)($_GET['size'] ?? '');
    $preQty  = (int)($_GET['qty'] ?? 0);
?>
<div class="page-header">
    <h2>Uniforms · Requests</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?page=uniform_requests<?= $showAll ? '' : '&all=1' ?>" class="btn btn-ghost"><?= $showAll ? 'Open only' : 'Show closed too' ?></a>
        <?php if (!$new): ?><a href="?page=uniform_requests&new=1" class="btn btn-primary">+ New Request</a><?php endif; ?>
    </div>
</div>
<?php uniTabs('uniform_requests'); ?>
<p class="hint" style="margin-bottom:14px">
    A size someone needs that is not in stock yet — a 4XL, a replacement, a new joiner. Open requests count towards
    the <strong>Short — to order</strong> line on the Stock tab; issue against one once the stock arrives.
</p>

<?php if ($new): ?>
<div class="form-card" style="margin-bottom:18px">
<form method="POST">
    <input type="hidden" name="action" value="uniform_save_request">
    <div class="form-grid">
        <div class="form-group">
            <label>Employee <span class="required">*</span></label>
            <select name="employee_id" class="form-control" required data-searchable="Type code, name or outlet…">
                <option value="">— Select —</option>
                <?php foreach ($emps as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= $preEmp === (int)$e['id'] ? 'selected' : '' ?>>
                    <?= h($e['employee_code'] . ' — ' . $e['full_name'] . (isset($locNames[(int)$e['location_id']]) ? ' · ' . $locNames[(int)$e['location_id']] : '')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Item and Size <span class="required">*</span></label>
            <div style="display:flex;gap:6px">
                <select name="item_id" id="uniReqItem" class="form-control" required>
                    <?php foreach ($items as $iid => $it): ?>
                    <option value="<?= (int)$iid ?>" <?= $preItem === (int)$iid ? 'selected' : '' ?>><?= h($it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php foreach ($items as $iid => $it): ?>
                <select name="size" class="form-control uni-req-size" data-item="<?= (int)$iid ?>" style="width:auto">
                    <?php foreach ($it['size_list'] as $s): ?>
                    <option value="<?= h($s) ?>" <?= $preSize === $s ? 'selected' : '' ?>><?= h($s) ?> (stock <?= (int)($stock[$iid][$s]['available'] ?? 0) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label>Quantity (pieces)</label>
            <input type="number" name="qty" class="form-control" min="1" value="<?= $preQty > 0 ? $preQty : 2 ?>">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <input type="text" name="notes" class="form-control" maxlength="255" placeholder="e.g. XXL too tight, new joiner">
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label class="rpt-filter-chk"><input type="checkbox" name="save_size" value="1" checked> Save this as the employee's size in the register</label>
        </div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary">Raise Request</button>
        <a href="?page=uniform_requests" class="btn btn-ghost">Cancel</a>
    </div>
</form>
</div>
<script>
(function () {
    var item = document.getElementById('uniReqItem');
    function sync() {
        document.querySelectorAll('.uni-req-size').forEach(function (s) {
            var on = s.dataset.item === item.value;
            s.style.display = on ? '' : 'none';
            s.disabled = !on;
        });
    }
    item.addEventListener('change', sync);
    sync();
})();
</script>
<?php uniSearchableSelectScript(); ?>
<?php endif; ?>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr><th>Raised</th><th>Employee</th><th>Item / Size</th><th style="text-align:right">Qty</th>
            <th>In Stock</th><th>Notes</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty-row">No <?= $showAll ? '' : 'open ' ?>requests.</td></tr>
    <?php else: foreach ($rows as $r):
        $avail = (int)($stock[(int)$r['item_id']][$r['size']]['available'] ?? 0);
        $open  = $r['status'] === 'open';
    ?>
        <tr class="<?= $open ? '' : 'row-inactive' ?>">
            <td><?= date('d M Y', strtotime((string)$r['created_at'])) ?><br><span class="text-muted"><?= h($r['created_by'] ?? '') ?></span></td>
            <td>
                <a href="?page=uniform_ledger&emp=<?= (int)$r['employee_id'] ?>"><code><?= h($r['employee_code']) ?></code></a>
                <?= h($r['employee_name']) ?>
                <?php if ($r['location_id']): ?><br><span class="text-muted"><?= h($locNames[(int)$r['location_id']] ?? '') ?></span><?php endif; ?>
            </td>
            <td><?= h(uniItem((int)$r['item_id'])['name'] ?? '') ?> · <strong><?= h($r['size']) ?></strong></td>
            <td style="text-align:right"><?= (int)$r['qty'] ?></td>
            <td><?= $open ? ($avail >= (int)$r['qty'] ? '<span class="badge badge-green">' . $avail . '</span>' : '<span class="badge badge-red">' . $avail . '</span>') : '' ?></td>
            <td><?= h($r['notes'] ?? '') ?></td>
            <td>
                <?= ['open' => '<span class="badge badge-yellow">Open</span>', 'fulfilled' => '<span class="badge badge-green">Fulfilled</span>',
                     'cancelled' => '<span class="badge badge-grey">Cancelled</span>'][$r['status']] ?? h($r['status']) ?>
                <?php if (!$open && $r['closed_at']): ?><br><span class="text-muted"><?= h($r['closed_by'] ?? '') ?> · <?= date('d M Y', strtotime((string)$r['closed_at'])) ?></span><?php endif; ?>
            </td>
            <td class="actions">
                <?php if ($open): ?>
                <a href="?page=uniform_move&type=issue&req=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Issue</a>
                <form method="POST" class="inline-form" onsubmit="return confirm('Cancel this request?')">
                    <input type="hidden" name="action" value="uniform_cancel_request">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-ghost">Cancel</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php
}

// ── Page: item setup ────────────────────────────────────
function pageUniformItems(): void {
    if (!uniPageGate()) return;
    $items = uniItems();
?>
<div class="page-header">
    <h2>Uniforms · Items</h2>
</div>
<?php uniTabs('uniform_items'); ?>
<p class="hint" style="margin-bottom:14px">
    What is handed out and the sizes it comes in, left to right in the order they should be shown.
    <strong>Pieces per issue</strong> is the default quantity on the Issue form and what counts as "fully issued" in the register.
    A size can only be removed while nothing has been received, issued, requested or registered in it.
</p>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Name</th><th>Sizes (comma separated)</th><th>Pieces per issue</th><th>Order</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_merge($items, [0 => ['id' => 0, 'name' => '', 'sizes' => '', 'qty_per_issue' => 2, 'sort_order' => count($items) + 1, 'is_active' => 1]]) as $it):
        $fid = 'uniItemForm' . (int)$it['id']; ?>
    <tr>
        <td><input form="<?= $fid ?>" type="text" name="name" class="form-control" maxlength="100" required
                   value="<?= h($it['name']) ?>" placeholder="<?= $it['id'] ? '' : 'New item, e.g. Cap' ?>"></td>
        <td><input form="<?= $fid ?>" type="text" name="sizes" class="form-control" required
                   value="<?= h(str_replace(',', ', ', (string)$it['sizes'])) ?>" placeholder="S, M, L, XL"></td>
        <td><input form="<?= $fid ?>" type="number" name="qty_per_issue" class="form-control" min="1" style="width:80px" value="<?= (int)$it['qty_per_issue'] ?>"></td>
        <td><input form="<?= $fid ?>" type="number" name="sort_order" class="form-control" style="width:70px" value="<?= (int)$it['sort_order'] ?>"></td>
        <td><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= (int)$it['is_active'] ? 'checked' : '' ?>></td>
        <td>
            <form method="POST" id="<?= $fid ?>" class="inline-form">
                <input type="hidden" name="action" value="uniform_save_item">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <button class="btn btn-sm <?= $it['id'] ? 'btn-secondary' : 'btn-primary' ?>"><?= $it['id'] ? 'Save' : '+ Add' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
}
