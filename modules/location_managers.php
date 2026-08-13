<?php
// =========================================================
// Location manager mapping — who runs each store, as a tree:
//
//   Location
//     └ Store Manager            (location_managers.store_manager_code)
//         ├ Store Executive 1    (location_executives, up to 8)
//         └ Store Executive …
//     └ Operation Manager        (location_managers.operation_manager_code)
//
// Every logged-in user reads the list (it is the company's "who runs this
// store" lookup); only the Operation Review permission edits it. audit_new
// auto-fills the Store Manager from this map. The operation manager and
// the executives are reference data; nothing auto-fills from them yet.
// =========================================================

// Executives per location. MySQL cannot express "at most N rows per group",
// so the cap lives here and is enforced on save and in the form.
const LOCMGR_MAX_EXECS = 8;

// Reading is open to every logged-in user; editing needs txn_manager_mapping
// (Store Operations → "Manager Mapping · Edit" on the Roles page). It used to
// borrow txn_audit_operation from the audit workflow — 2026-08-17 splits that
// off and seeds the new flag from the old one so nobody loses access.
function locMgrCanManage(): bool {
    return isSuperadmin() || hasTxn('manager_mapping');
}

// operation_manager_code arrives with 2026-08-15_location_operation_manager.sql.
// Until that runs the column is hidden everywhere instead of being offered
// and then rejected on INSERT, so PHP may deploy before the SQL.
function locMgrHasOpsColumn(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        // Catch Throwable, not Exception: a PDO configured to return false
        // instead of throwing would otherwise turn ->fetch() into a fatal.
        $st = getDb()->query("SHOW COLUMNS FROM location_managers LIKE 'operation_manager_code'");
        $cached = $st !== false && $st->fetch() !== false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

// location_executives arrives with 2026-08-16_location_store_executives.sql.
// Same deploy-PHP-before-SQL guard as above: no table, no executive tree.
function locMgrHasExecTable(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $cached = getDb()->query('SELECT 1 FROM location_executives LIMIT 1') !== false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

// Active employees for the pickers and the tree, with the mobile number
// the page shows next to every name. getEmployeesLite() omits phone.
function locMgrEmployees(): array {
    try {
        return getDb()->query(
            'SELECT employee_code, full_name, phone
             FROM employees WHERE is_active = 1 ORDER BY full_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// [location_id => store_manager_code] — used by audit_new for defaults.
function getLocationManagerMap(): array {
    try {
        $rows = getDb()->query('SELECT location_id, store_manager_code FROM location_managers')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $code = trim((string)$r['store_manager_code']);
        if ($code !== '') $map[(int)$r['location_id']] = $code;
    }
    return $map;
}

// [location_id => operation_manager_code] — empty until the migration runs.
function getLocationOperationManagerMap(): array {
    if (!locMgrHasOpsColumn()) return [];
    try {
        $rows = getDb()->query('SELECT location_id, operation_manager_code FROM location_managers')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $r) {
        $code = trim((string)($r['operation_manager_code'] ?? ''));
        if ($code !== '') $map[(int)$r['location_id']] = $code;
    }
    return $map;
}

// Current mappings, keyed by location_id:
//   ['sm'=>code,'sm_name'=>,'sm_phone'=>, 'om'=>code,'om_name'=>,'om_phone'=>]
// Names fall back to the code so a mapping to a since-deleted employee
// still shows something the user can act on.
function locMgrMappings(): array {
    $hasOps = locMgrHasOpsColumn();
    $sql = 'SELECT lm.location_id, lm.store_manager_code, sm.full_name AS sm_name, sm.phone AS sm_phone'
         . ($hasOps ? ', lm.operation_manager_code, om.full_name AS om_name, om.phone AS om_phone' : '')
         . ' FROM location_managers lm
            LEFT JOIN employees sm ON sm.employee_code = lm.store_manager_code'
         . ($hasOps ? ' LEFT JOIN employees om ON om.employee_code = lm.operation_manager_code' : '');
    try {
        $rows = getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return []; // table not migrated yet
    }
    $mapped = [];
    foreach ($rows as $r) {
        $sm = trim((string)$r['store_manager_code']);
        $om = trim((string)($r['operation_manager_code'] ?? ''));
        $mapped[(int)$r['location_id']] = [
            'sm'       => $sm,
            'sm_name'  => $sm === '' ? '' : ((string)($r['sm_name'] ?? '') ?: $sm),
            'sm_phone' => $sm === '' ? '' : trim((string)($r['sm_phone'] ?? '')),
            'om'       => $om,
            'om_name'  => $om === '' ? '' : ((string)($r['om_name'] ?? '') ?: $om),
            'om_phone' => $om === '' ? '' : trim((string)($r['om_phone'] ?? '')),
        ];
    }
    return $mapped;
}

// [location_id => [ ['code'=>,'name'=>,'phone'=>], … ]] in tree order.
// Empty until the migration runs.
function locMgrExecutives(): array {
    if (!locMgrHasExecTable()) return [];
    try {
        $rows = getDb()->query(
            'SELECT le.location_id, le.employee_code, e.full_name, e.phone
             FROM location_executives le
             LEFT JOIN employees e ON e.employee_code = le.employee_code
             ORDER BY le.location_id, le.sort_order, le.employee_code'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    $execs = [];
    foreach ($rows as $r) {
        $code = trim((string)$r['employee_code']);
        if ($code === '') continue;
        $execs[(int)$r['location_id']][] = [
            'code'  => $code,
            'name'  => (string)($r['full_name'] ?? '') ?: $code,
            'phone' => trim((string)($r['phone'] ?? '')),
        ];
    }
    return $execs;
}

// "Name (CODE)" — the label both the tree and the pickers use.
function locMgrLabel(string $name, string $code): string {
    return $code === '' ? '' : $name . ' (' . $code . ')';
}

// ── POST: upsert one mapping ─────────────────────────────
function doSaveLocationManager(): void {
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $db      = getDb();
    $hasOps  = locMgrHasOpsColumn();
    $hasExec = locMgrHasExecTable();
    $loc     = (int)($_POST['location_id'] ?? 0);
    $code    = trim($_POST['store_manager_code'] ?? '');
    $opCode  = $hasOps ? trim($_POST['operation_manager_code'] ?? '') : '';
    $back    = 'index.php?page=location_managers';

    // The form posts one executive_codes[] entry per row, blanks included
    // (a row the user emptied). Drop the blanks, keep the first occurrence
    // of each employee, and preserve the on-screen order as sort_order.
    $execs = [];
    if ($hasExec) {
        foreach ((array)($_POST['executive_codes'] ?? []) as $ec) {
            $ec = trim((string)$ec);
            if ($ec !== '' && !in_array($ec, $execs, true)) $execs[] = $ec;
        }
    }

    if ($loc <= 0 || ($code === '' && $opCode === '' && !$execs)) {
        flash('error', $hasOps || $hasExec ? 'Select a location and at least one person.' : 'Select a location and a store manager.');
        header("Location: $back"); exit;
    }
    if (count($execs) > LOCMGR_MAX_EXECS) {
        flash('error', 'At most ' . LOCMGR_MAX_EXECS . ' store executives per location.');
        header("Location: $back"); exit;
    }
    // An executive who is also the store manager of the same store would
    // appear twice in the tree, once as parent and once as child.
    if ($code !== '' && in_array($code, $execs, true)) {
        flash('error', 'The store manager cannot also be listed as a store executive.');
        header("Location: $back"); exit;
    }

    $locOk = $db->prepare('SELECT 1 FROM locations WHERE location_id = ? LIMIT 1');
    $locOk->execute([$loc]);
    if (!$locOk->fetchColumn()) { flash('error', 'Location not found.'); header("Location: $back"); exit; }

    $empOk = $db->prepare('SELECT 1 FROM employees WHERE employee_code = ? AND is_active = 1 LIMIT 1');
    if ($code !== '') {
        $empOk->execute([$code]);
        if (!$empOk->fetchColumn()) { flash('error', 'Store manager must be an active employee.'); header("Location: $back"); exit; }
    }
    if ($opCode !== '') {
        $empOk->execute([$opCode]);
        if (!$empOk->fetchColumn()) { flash('error', 'Operation manager must be an active employee.'); header("Location: $back"); exit; }
    }
    foreach ($execs as $ec) {
        $empOk->execute([$ec]);
        if (!$empOk->fetchColumn()) { flash('error', 'Store executives must be active employees.'); header("Location: $back"); exit; }
    }

    try {
        $db->beginTransaction();
        if ($hasOps) {
            $db->prepare(
                'INSERT INTO location_managers (location_id, store_manager_code, operation_manager_code, updated_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code),
                                         operation_manager_code = VALUES(operation_manager_code),
                                         updated_by = VALUES(updated_by)'
            )->execute([$loc, $code, ($opCode === '' ? null : $opCode), myCode()]);
        } else {
            $db->prepare(
                'INSERT INTO location_managers (location_id, store_manager_code, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code), updated_by = VALUES(updated_by)'
            )->execute([$loc, $code, myCode()]);
        }
        if ($hasExec) {
            // The form always posts the whole list, so replace rather than
            // merge — that is what makes removing an executive work.
            $db->prepare('DELETE FROM location_executives WHERE location_id = ?')->execute([$loc]);
            if ($execs) {
                $ins = $db->prepare('INSERT INTO location_executives (location_id, employee_code, sort_order, updated_by) VALUES (?, ?, ?, ?)');
                foreach ($execs as $i => $ec) $ins->execute([$loc, $ec, $i, myCode()]);
            }
        }
        $db->commit();
        flash('success', 'Mapping saved.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: remove a mapping ───────────────────────────────
function doDeleteLocationManager(): void {
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $loc = (int)($_POST['location_id'] ?? 0);
    try {
        // Clearing the row clears the whole tree hanging off it.
        if (locMgrHasExecTable()) {
            getDb()->prepare('DELETE FROM location_executives WHERE location_id = ?')->execute([$loc]);
        }
        getDb()->prepare('DELETE FROM location_managers WHERE location_id = ?')->execute([$loc]);
        flash('success', 'Mapping removed.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: index.php?page=location_managers'); exit;
}

// ── CSV export — the tree flattened, one row per person ───
// A location with 8 executives cannot be one row per store without 20-odd
// mostly-empty columns, so each person gets a row tagged with their Role.
// Sorting by Location then filtering on Role rebuilds any view you want.
function exportLocationManagersCsv(): void {
    // Same data the page shows, and the page is readable by everyone —
    // the routing gate in allowedPages() is the only check needed.
    $mapped = locMgrMappings();
    $execs  = locMgrExecutives();
    try {
        $locations = getDb()->query('SELECT location_id, location_code, location_name FROM locations WHERE is_active = 1 ORDER BY location_name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $locations = [];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="location_managers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads non-ASCII names
    fputcsv($out, ['Location Code', 'Location', 'Role', 'Employee', 'Employee Code', 'Mobile'], escape: '');

    foreach ($locations as $l) {
        $lid  = (int)$l['location_id'];
        $lcod = (string)($l['location_code'] ?? '');
        $lnam = (string)$l['location_name'];
        $m    = $mapped[$lid] ?? null;
        $rows = [];

        if ($m && $m['sm'] !== '') $rows[] = ['Store Manager', $m['sm_name'], $m['sm'], $m['sm_phone']];
        foreach ($execs[$lid] ?? [] as $i => $x) {
            $rows[] = ['Store Executive ' . ($i + 1), $x['name'], $x['code'], $x['phone']];
        }
        if ($m && $m['om'] !== '') $rows[] = ['Operation Manager', $m['om_name'], $m['om'], $m['om_phone']];

        // Keep unmapped stores in the file — "nobody is assigned here" is
        // the answer someone is exporting this to find.
        if (!$rows) $rows[] = ['', '', '', ''];

        foreach ($rows as $r) fputcsv($out, array_merge([$lcod, $lnam], $r), escape: '');
    }
    fclose($out);
    exit;
}

// ── CSV import — Location, Role, Employee Code ───────────
// Reads only the three columns that decide the mapping. The export's
// Employee and Mobile columns are there for people to read and are
// ignored here: a name is not an identity (two employees share one), and
// a phone number belongs to the employee record, not to this mapping.
// Roles as the export spells them: "Store Manager", "Operation Manager",
// "Store Executive N". Returns 'sm' | 'om' | 'exec' | '' (unrecognised),
// and the executive's position when the role carries one.
function locMgrParseRole(string $raw, ?int &$pos = null): string {
    $pos = null;
    $r = strtolower(trim(preg_replace('/\s+/', ' ', $raw)));
    $r = trim($r, ".:-");
    if ($r === '') return '';
    if (in_array($r, ['store manager', 'storemanager', 'sm', 'manager'], true)) return 'sm';
    if (in_array($r, ['operation manager', 'operations manager', 'operation', 'om'], true)) return 'om';
    if (preg_match('/^(?:store )?exec(?:utive)?\s*(\d+)?$/', $r, $mm)) {
        if (isset($mm[1]) && $mm[1] !== '') $pos = (int)$mm[1];
        return 'exec';
    }
    return '';
}

function doImportLocationManagers(): void {
    $back = 'index.php?page=location_managers';
    if (!locMgrCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    if (!locMgrHasExecTable() || !locMgrHasOpsColumn()) {
        flash('error', 'Run the pending database migrations before importing.');
        header("Location: $back"); exit;
    }
    if (empty($_FILES['csv']['name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'No file uploaded or upload error.'); header("Location: $back"); exit;
    }
    $fh = @fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$fh) { flash('error', 'Could not read uploaded file.'); header("Location: $back"); exit; }

    $fail = function (string $msg) use ($fh, $back) {
        if (is_resource($fh)) fclose($fh);
        flash('error', $msg);
        header("Location: $back"); exit;
    };

    // Header row — lowercase keys, BOM stripped, so a file straight out of
    // Excel lines up with the export's own column names.
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) $fail('File is empty or unreadable.');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim((string)$col));
        if ($key !== '') $idx[$key] = $i;
    }
    if (!isset($idx['location']) && !isset($idx['location code'])) {
        $fail('Missing a "Location" or "Location Code" column. Export CSV first to get the expected format.');
    }
    if (!isset($idx['role'])) $fail('Missing the "Role" column. Export CSV first to get the expected format.');
    if (!isset($idx['employee code'])) {
        $fail('Missing the "Employee Code" column. People are identified by code here, not by name.');
    }

    $db = getDb();
    // Lookups: locations by code and by name, employees by code only.
    // Location names are matched case-insensitively and only when unambiguous.
    $locByCode = []; $locByName = [];
    foreach ($db->query('SELECT location_id, location_code, location_name FROM locations')->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $c = strtolower(trim((string)($l['location_code'] ?? '')));
        $n = strtolower(trim((string)$l['location_name']));
        if ($c !== '') $locByCode[$c] = (int)$l['location_id'];
        if ($n !== '') $locByName[$n][] = (int)$l['location_id'];
    }
    $empActive = [];
    foreach ($db->query('SELECT employee_code, is_active FROM employees')->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $code = trim((string)$e['employee_code']);
        if ($code !== '') $empActive[$code] = (int)($e['is_active'] ?? 0);
    }

    // Parse the whole file before touching anything, so a bad line on row
    // 400 does not leave the first 399 applied.
    $plan     = [];   // location_id => ['sm'=>, 'om'=>, 'execs'=>[[pos,idx,code],…]]
    $order    = [];   // location_id => first line seen, for the summary
    $inactive = [];   // employee codes accepted but no longer active
    $line = 1; $seq = 0;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($r) === 1 && trim((string)$r[0]) === '') continue; // blank line
        $get = fn(string $k) => isset($idx[$k]) && isset($r[$idx[$k]]) ? trim((string)$r[$idx[$k]]) : '';

        $lCode = $get('location code');
        $lName = $get('location');
        $roleR = $get('role');
        $eCode = $get('employee code');

        // Resolve the location: code wins, name is the fallback.
        $lid = null;
        if ($lCode !== '' && isset($locByCode[strtolower($lCode)])) {
            $lid = $locByCode[strtolower($lCode)];
        } elseif ($lName !== '') {
            $hits = $locByName[strtolower($lName)] ?? [];
            if (count($hits) === 1) $lid = $hits[0];
            elseif (count($hits) > 1) $fail("Line {$line}: more than one location is named \"{$lName}\" — use the Location Code column.");
        }
        if ($lid === null) {
            $what = $lCode !== '' ? "code \"{$lCode}\"" : "name \"{$lName}\"";
            $fail("Line {$line}: no location matches {$what}.");
        }
        if (!isset($plan[$lid])) { $plan[$lid] = ['sm' => '', 'om' => '', 'execs' => []]; $order[$lid] = $line; }

        // A row with no role and no code is the export's placeholder for an
        // unassigned store — it means "this store has nobody", not an error.
        if ($roleR === '' && $eCode === '') continue;

        $pos  = null;
        $role = locMgrParseRole($roleR, $pos);
        if ($role === '') $fail("Line {$line}: unrecognised Role \"{$roleR}\". Use Store Manager, Operation Manager, or Store Executive.");

        if ($eCode === '') $fail("Line {$line}: Role \"{$roleR}\" has no Employee Code against it.");
        if (!isset($empActive[$eCode])) $fail("Line {$line}: no employee has the code \"{$eCode}\".");
        $code = $eCode;
        // Deactivated staff are accepted: an export of a store whose manager
        // has since left must stay importable. They are counted and reported.
        if (!$empActive[$code]) $inactive[$code] = true;

        if ($role === 'sm') {
            if ($plan[$lid]['sm'] !== '') $fail("Line {$line}: two Store Manager rows for the same location.");
            $plan[$lid]['sm'] = $code;
        } elseif ($role === 'om') {
            if ($plan[$lid]['om'] !== '') $fail("Line {$line}: two Operation Manager rows for the same location.");
            $plan[$lid]['om'] = $code;
        } else {
            foreach ($plan[$lid]['execs'] as $x) {
                if ($x[2] === $code) $fail("Line {$line}: \"{$code}\" is listed twice as a store executive for the same location.");
            }
            // Numbered roles ("Store Executive 3") order the tree; unnumbered
            // ones keep file order behind them.
            $plan[$lid]['execs'][] = [$pos ?? PHP_INT_MAX, $seq++, $code];
        }
    }
    fclose($fh);
    if (!$plan) { flash('error', 'No data rows found in file.'); header("Location: $back"); exit; }

    // Whole-file checks that only make sense once a location's rows are all in.
    foreach ($plan as $lid => $p) {
        if (count($p['execs']) > LOCMGR_MAX_EXECS) {
            $fail('Location on line ' . $order[$lid] . ' has ' . count($p['execs']) . ' store executives; the limit is ' . LOCMGR_MAX_EXECS . '.');
        }
        foreach ($p['execs'] as $x) {
            if ($p['sm'] !== '' && $x[2] === $p['sm']) {
                $fail('Location on line ' . $order[$lid] . ': "' . $p['sm'] . '" is both the store manager and a store executive.');
            }
        }
    }

    $saved = 0; $cleared = 0;
    try {
        $db->beginTransaction();
        $up = $db->prepare(
            'INSERT INTO location_managers (location_id, store_manager_code, operation_manager_code, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE store_manager_code = VALUES(store_manager_code),
                                     operation_manager_code = VALUES(operation_manager_code),
                                     updated_by = VALUES(updated_by)'
        );
        $delMgr  = $db->prepare('DELETE FROM location_managers WHERE location_id = ?');
        $delExec = $db->prepare('DELETE FROM location_executives WHERE location_id = ?');
        $insExec = $db->prepare('INSERT INTO location_executives (location_id, employee_code, sort_order, updated_by) VALUES (?, ?, ?, ?)');

        foreach ($plan as $lid => $p) {
            // Sort by the role's number, then by the order read from the file.
            usort($p['execs'], fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $delExec->execute([$lid]);
            if ($p['sm'] === '' && $p['om'] === '' && !$p['execs']) {
                // The file lists this store with nobody on it — same outcome
                // as the Clear button.
                $delMgr->execute([$lid]);
                $cleared++;
                continue;
            }
            $up->execute([$lid, $p['sm'], ($p['om'] === '' ? null : $p['om']), myCode()]);
            foreach ($p['execs'] as $i => $x) $insExec->execute([$lid, $x[2], $i, myCode()]);
            $saved++;
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', 'Import failed, nothing was changed: ' . $e->getMessage());
        header("Location: $back"); exit;
    }

    $msg = "Imported {$saved} location" . ($saved === 1 ? '' : 's');
    if ($cleared) $msg .= ", cleared {$cleared}";
    $msg .= '. Locations absent from the file were left alone.';
    if ($inactive) $msg .= ' ' . count($inactive) . ' mapped employee(s) are no longer active: ' . h(implode(', ', array_keys($inactive))) . '.';
    flash('success', $msg);
    header("Location: $back"); exit;
}

// ── Page ─────────────────────────────────────────────────
function pageLocationManagers(): void {
    $canEdit   = locMgrCanManage();
    $hasOps    = locMgrHasOpsColumn();
    $hasExec   = locMgrHasExecTable();
    $locations = getActiveLocations();
    $mapped    = locMgrMappings();
    $execs     = $hasExec ? locMgrExecutives() : [];

    // location_id => every picker's hidden value + visible label, so
    // picking a location refills the whole form with what is already
    // mapped instead of silently overwriting the parts left untouched.
    // Only editors get the form, so only they need the employee list.
    $employees = [];
    $formMap   = [];
    if ($canEdit) {
        $employees = locMgrEmployees();
        $lids = array_unique(array_merge(array_keys($mapped), array_keys($execs)));
        foreach ($lids as $lid) {
            $m = $mapped[$lid] ?? ['sm' => '', 'sm_name' => '', 'om' => '', 'om_name' => ''];
            $formMap[(string)$lid] = [
                'sm'      => $m['sm'],
                'smLabel' => locMgrLabel($m['sm_name'], $m['sm']),
                'om'      => $m['om'],
                'omLabel' => locMgrLabel($m['om_name'], $m['om']),
                'execs'   => array_map(
                    fn($x) => ['code' => $x['code'], 'label' => locMgrLabel($x['name'], $x['code'])],
                    $execs[$lid] ?? []
                ),
            ];
        }
    }

    // One markup shape for every employee picker on the page — the script
    // wires each by class, so the executive rows can be cloned at runtime.
    $pickerHtml = function (string $nameAttr, string $width = '280px', string $placeholder = 'Type to search employee') {
        ob_start(); ?>
        <span class="input-clear-wrap lm-pick" style="display:flex;width:<?= h($width) ?>">
            <input type="hidden" name="<?= h($nameAttr) ?>" class="lm-pick-code" value="">
            <input type="text" class="form-control lm-pick-search" placeholder="<?= h($placeholder) ?>" autocomplete="off">
            <button type="button" class="input-clear-btn lm-pick-clear" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
            <div class="lm-pick-list" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
        </span>
        <?php return ob_get_clean();
    };

    // One person in the tree: name, code, and the mobile the page exists to
    // surface. Phone is optional on employees, so say so rather than leave a gap.
    $personHtml = function (string $name, string $code, string $phone): string {
        if ($code === '') return '<span class="text-muted">— not set —</span>';
        return h($name) . ' <span class="text-muted">(' . h($code) . ')</span>'
             . '<div class="text-muted" style="font-size:11px">'
             . ($phone !== '' ? '📱 ' . h($phone) : 'no mobile on file')
             . '</div>';
    };
?>
<style>
/* Executive tree hanging off the store manager in its cell. */
.lm-tree{list-style:none;margin:8px 0 0;padding:0 0 0 10px;border-left:1px solid var(--border)}
.lm-tree>li{position:relative;padding:4px 0 4px 12px;font-size:12px}
.lm-tree>li::before{content:'';position:absolute;left:0;top:11px;width:8px;height:1px;background:var(--border)}
.lm-tree-label{font-size:11px;letter-spacing:.03em;text-transform:uppercase;margin-top:10px}
</style>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h2 style="margin:0">🏬 Store Manager Mapping</h2>
    <div style="display:flex;gap:8px;align-items:center">
        <a href="?page=export_location_managers" class="btn btn-secondary btn-sm">Export CSV</a>
        <?php if ($canEdit && $hasExec && $hasOps): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="lmToggleImport()">Import CSV</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit && $hasExec && $hasOps): ?>
<div class="form-card" id="lmImportCard" style="display:none;margin-bottom:16px;max-width:none">
    <h3 style="font-size:15px;margin-bottom:4px">Import mapping (CSV)</h3>
    <p class="text-muted" style="font-size:12px;margin:0 0 12px">
        Needs three columns: <code>Location Code</code> (or <code>Location</code>), <code>Role</code>, <code>Employee Code</code> — one row per person.
        The file <a href="?page=export_location_managers">Export CSV</a> produces already has them, so you can export it, edit it and import it back;
        its <code>Employee</code> and <code>Mobile</code> columns are along for the ride and are ignored.
    </p>
    <ul class="text-muted" style="font-size:12px;margin:0 0 12px;padding-left:18px;line-height:1.7">
        <li><strong>Role</strong> must read <code>Store Manager</code>, <code>Operation Manager</code> or <code>Store Executive</code> (a trailing number orders the tree).</li>
        <li>People are identified by <strong><code>Employee Code</code> only</strong> — names are not unique, so they are never used to decide who is who.</li>
        <li>Phone numbers are not imported. They live on the employee record; change them on the Employees page.</li>
        <li>Each location in the file is <strong>replaced by its rows</strong> — delete a person's row to unassign them. A location listed with no people is cleared.</li>
        <li><strong>Locations missing from the file are left untouched</strong>, so you can import a few stores at a time.</li>
        <li>Nothing is written unless the whole file is valid; the first bad line is reported with its line number.</li>
    </ul>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="import_location_managers">
        <div class="form-group" style="margin:0">
            <label>CSV file</label>
            <input type="file" name="csv" accept=".csv,text/csv" required class="form-control" style="width:320px">
        </div>
        <button type="submit" class="btn btn-primary">Import</button>
        <button type="button" class="btn btn-secondary" onclick="lmToggleImport()">Cancel</button>
    </form>
</div>
<script>
function lmToggleImport() {
    var c = document.getElementById('lmImportCard');
    if (c) c.style.display = c.style.display === 'none' ? '' : 'none';
}
</script>
<?php endif; ?>
<?php if ($canEdit): ?>
<div class="form-card" style="margin-bottom:16px;max-width:none">
    <h3 style="font-size:15px;margin-bottom:12px">Set / update mapping</h3>
    <form method="POST" id="lmForm">
        <input type="hidden" name="action" value="save_location_manager">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0">
                <label>Location</label>
                <select name="location_id" id="lmLoc" class="form-control" style="width:240px" required>
                    <option value="">— Select location —</option>
                    <?php foreach ($locations as $l): $lid = (int)$l['location_id']; ?>
                    <option value="<?= $lid ?>"><?= h($l['location_name']) ?><?= isset($mapped[$lid]) && $mapped[$lid]['sm_name'] !== '' ? ' • ' . h($mapped[$lid]['sm_name']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0" id="lmSmGroup">
                <label>Store Manager</label>
                <?= $pickerHtml('store_manager_code') ?>
            </div>
            <?php if ($hasOps): ?>
            <div class="form-group" style="margin:0" id="lmOmGroup">
                <label>Operation Manager</label>
                <?= $pickerHtml('operation_manager_code') ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($hasExec): ?>
        <div class="form-group" style="margin:14px 0 0">
            <label>Store Executives <span class="text-muted" style="font-weight:400">— reporting to the store manager, up to <?= LOCMGR_MAX_EXECS ?></span></label>
            <div id="lmExecRows" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px"></div>
            <button type="button" id="lmExecAdd" class="btn btn-ghost btn-sm">+ Add executive</button>
            <span id="lmExecCount" class="text-muted" style="font-size:11px;margin-left:8px"></span>
        </div>
        <!-- Cloned per executive row; kept out of the form's POST by <template>. -->
        <template id="lmExecTpl">
            <div class="lm-exec-row" style="display:flex;gap:8px;align-items:center">
                <?= $pickerHtml('executive_codes[]', '320px', 'Type to search employee') ?>
                <button type="button" class="btn btn-sm badge-red lm-exec-del" style="cursor:pointer">Remove</button>
            </div>
        </template>
        <?php endif; ?>

        <div style="margin-top:14px">
            <button type="submit" class="btn btn-primary">Save mapping</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr>
            <th style="width:200px">Location</th>
            <th style="width:340px">Store Manager<?= $hasExec ? ' &amp; Executives' : '' ?></th>
            <?php if ($hasOps): ?><th style="width:260px">Operation Manager</th><?php endif; ?>
            <?php if ($canEdit): ?><th style="width:120px"></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($locations as $l): $lid = (int)$l['location_id']; $m = $mapped[$lid] ?? null; $rowExecs = $execs[$lid] ?? []; ?>
            <tr>
                <td><?= h($l['location_name']) ?></td>
                <td>
                    <?= $personHtml($m['sm_name'] ?? '', $m['sm'] ?? '', $m['sm_phone'] ?? '') ?>
                    <?php if ($hasExec): ?>
                        <?php if ($rowExecs): ?>
                        <div class="text-muted lm-tree-label">Store Executives (<?= count($rowExecs) ?>)</div>
                        <ul class="lm-tree">
                            <?php foreach ($rowExecs as $x): ?>
                            <li><?= $personHtml($x['name'], $x['code'], $x['phone']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php elseif ($m && $m['sm'] !== ''): ?>
                        <div class="text-muted" style="font-size:11px;margin-top:8px">No store executives assigned</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php if ($hasOps): ?>
                <td><?= $personHtml($m['om_name'] ?? '', $m['om'] ?? '', $m['om_phone'] ?? '') ?></td>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                <td style="white-space:nowrap">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="lmEdit(<?= $lid ?>)"><?= $m ? 'Edit' : 'Set' ?></button>
                    <?php if ($m): ?>
                    <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Remove this mapping?')">
                        <input type="hidden" name="action" value="delete_location_manager">
                        <input type="hidden" name="location_id" value="<?= $lid ?>">
                        <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Clear</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($canEdit): ?>
<script>
(function () {
    var data = <?= json_encode(array_map(
        fn($e) => ['code' => (string)$e['employee_code'], 'name' => (string)$e['full_name'], 'phone' => trim((string)($e['phone'] ?? ''))],
        $employees
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var formMap = <?= json_encode($formMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var MAX_EXECS = <?= LOCMGR_MAX_EXECS ?>;
    var locSel = document.getElementById('lmLoc');
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };

    // Every picker on the page, so one document listener can close them all.
    var pickers = [];

    // One employee picker over a .lm-pick wrapper: text box + hidden code +
    // filtered dropdown. Wired by class, not id, so executive rows cloned at
    // runtime work the same as the two fixed ones.
    function picker(wrap) {
        if (!wrap) return null;
        var search   = wrap.querySelector('.lm-pick-search');
        var hidden   = wrap.querySelector('.lm-pick-code');
        var clearBtn = wrap.querySelector('.lm-pick-clear');
        var list     = wrap.querySelector('.lm-pick-list');
        if (!search || !hidden || !list) return null;

        function render(q) {
            q = (q || '').trim().toLowerCase();
            var m = q === '' ? data : data.filter(function (x) {
                return x.name.toLowerCase().indexOf(q) !== -1
                    || x.code.toLowerCase().indexOf(q) !== -1
                    || (x.phone || '').toLowerCase().indexOf(q) !== -1;
            });
            if (!m.length) { list.innerHTML = '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No matches</div>'; }
            else { list.innerHTML = m.slice(0, 300).map(function (x) {
                return '<div class="lm-opt" data-code="' + esc(x.code) + '" data-label="' + esc(x.name + ' (' + x.code + ')') + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">'
                     + esc(x.name) + ' <span style="color:var(--muted)">(' + esc(x.code) + ')</span>'
                     + (x.phone ? '<span style="color:var(--muted);float:right">' + esc(x.phone) + '</span>' : '')
                     + '</div>';
            }).join(''); }
            list.style.display = 'block';
        }
        function hide() { list.style.display = 'none'; }

        // Refocusing a filled box: the text is a "Name (CODE)" label, which
        // matches nothing as a query, so offer the whole list instead of
        // greeting the user with "No matches".
        search.addEventListener('focus', function () { render(hidden.value ? '' : search.value); });
        search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
        list.addEventListener('mousedown', function (ev) {
            var o = ev.target.closest('.lm-opt'); if (!o) return;
            ev.preventDefault();
            hidden.value = o.getAttribute('data-code');
            search.value = o.getAttribute('data-label');
            hide();
        });
        if (clearBtn) clearBtn.addEventListener('click', function () { search.value = ''; hidden.value = ''; search.focus(); render(''); });

        var api = {
            el: wrap,
            code: function () { return hidden.value; },
            set: function (code, label) { hidden.value = code || ''; search.value = label || ''; },
            focus: function () { search.focus(); },
            hide: hide,
            owns: function (t) { return wrap.contains(t); },
            // Typed-but-not-picked: the hidden code is what gets saved, so
            // leaving it empty would silently blank the name on the row.
            dangling: function () { return search.value.trim() !== '' && hidden.value === ''; }
        };
        pickers.push(api);
        return api;
    }

    // Click anywhere outside a picker closes every open dropdown.
    document.addEventListener('mousedown', function (ev) {
        pickers.forEach(function (p) { if (!p.owns(ev.target)) p.hide(); });
    });

    var smGroup = document.getElementById('lmSmGroup');
    var omGroup = document.getElementById('lmOmGroup');
    var sm = smGroup ? picker(smGroup.querySelector('.lm-pick')) : null;
    var om = omGroup ? picker(omGroup.querySelector('.lm-pick')) : null;

    // ── Store executive rows (0…MAX_EXECS) ──────────────────
    var execRows = document.getElementById('lmExecRows');
    var execTpl  = document.getElementById('lmExecTpl');
    var execAdd  = document.getElementById('lmExecAdd');
    var execCnt  = document.getElementById('lmExecCount');
    var execPickers = [];

    function execCount() { return execPickers.length; }
    function syncExecUi() {
        var n = execCount();
        if (execAdd) {
            execAdd.disabled = n >= MAX_EXECS;
            execAdd.style.opacity = n >= MAX_EXECS ? '.5' : '';
        }
        if (execCnt) execCnt.textContent = n + ' of ' + MAX_EXECS + (n >= MAX_EXECS ? ' — limit reached' : '');
    }
    function addExecRow(code, label, focusIt) {
        if (!execRows || !execTpl || execCount() >= MAX_EXECS) return null;
        var row = execTpl.content.firstElementChild.cloneNode(true);
        execRows.appendChild(row);
        var p = picker(row.querySelector('.lm-pick'));
        if (p) { p.set(code, label); execPickers.push(p); }
        var del = row.querySelector('.lm-exec-del');
        if (del) del.addEventListener('click', function () {
            // Drop it from both the DOM and the registries, so the freed
            // slot is reusable and the removed row stops being validated.
            pickers = pickers.filter(function (x) { return x !== p; });
            execPickers = execPickers.filter(function (x) { return x !== p; });
            row.remove();
            syncExecUi();
        });
        syncExecUi();
        if (focusIt && p) p.focus();
        return p;
    }
    function clearExecRows() {
        execPickers.forEach(function (p) { pickers = pickers.filter(function (x) { return x !== p; }); });
        execPickers = [];
        if (execRows) execRows.innerHTML = '';
        syncExecUi();
    }
    if (execAdd) execAdd.addEventListener('click', function () { addExecRow('', '', true); });
    syncExecUi();

    // Every picker always shows the whole row, so saving one name never
    // wipes another by leaving its box empty.
    function fill(locId) {
        var cur = formMap[String(locId)] || {};
        if (sm) sm.set(cur.sm, cur.smLabel);
        if (om) om.set(cur.om, cur.omLabel);
        clearExecRows();
        (cur.execs || []).forEach(function (x) { addExecRow(x.code, x.label, false); });
    }
    if (locSel) locSel.addEventListener('change', function () { fill(locSel.value); });

    var form = document.getElementById('lmForm');
    if (form) form.addEventListener('submit', function (ev) {
        function stop(msg, p) { ev.preventDefault(); alert(msg); if (p) p.focus(); }
        if (sm && sm.dangling()) return stop('Pick the store manager from the list.', sm);
        if (om && om.dangling()) return stop('Pick the operation manager from the list.', om);

        var seen = {}, execCodes = [];
        for (var i = 0; i < execPickers.length; i++) {
            var p = execPickers[i];
            if (p.dangling()) return stop('Pick store executive ' + (i + 1) + ' from the list.', p);
            var c = p.code();
            if (!c) continue;                       // an empty row is just skipped
            if (seen[c]) return stop('That store executive is already on this store.', p);
            if (sm && c === sm.code()) return stop('The store manager cannot also be a store executive.', p);
            seen[c] = 1; execCodes.push(c);
        }
        if (execCodes.length > MAX_EXECS) return stop('At most ' + MAX_EXECS + ' store executives per location.', null);
        if ((!sm || !sm.code()) && (!om || !om.code()) && !execCodes.length) {
            return stop('Select at least one person.', sm);
        }
    });

    window.lmEdit = function (locId) {
        if (locSel) locSel.value = String(locId);
        fill(locId);
        if (sm) sm.focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };
})();
</script>
<?php endif; ?>
<?php }
