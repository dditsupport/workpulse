<?php
// =========================================================
// Time Tracking Module — personal timesheets + admin report
//
// Every employee logs blocks of time ("My Time"), optionally against
// a ticket (issues.id → WP-{id}) or against a task from the shared task
// list. The list is company-wide: adding to it needs txn_time_task, but
// anybody may log time against anything on it.
// Duration is stored as whole minutes on `time_entries`. A cross-
// employee report ("Time Tracking Report") is gated by txn_time_report and
// shows the week's timesheets for everybody, each openable as that
// employee's own My Time view.
// Mirrors the conventions used across modules/: getDb() (PDO), myCode()
// for the owner, flash() + header() redirects on POST, h() on output.
// =========================================================

// ── Duration helpers ─────────────────────────────────────
// A <select> of duration slots in 15-minute steps (15m … 8h), values in
// whole minutes, labels like "2h 30m". $value (minutes) pre-selects a slot.
function durationSelect(string $name, int $value = 0, bool $required = false, string $extraClass = '', string $style = ''): string {
    $opts = '<option value="">--</option>';
    for ($mins = 15; $mins <= 8 * 60; $mins += 15) {
        $sel   = $mins === $value ? ' selected' : '';
        $opts .= '<option value="' . $mins . '"' . $sel . '>' . fmtMinutes($mins) . '</option>';
    }
    // A value that is not on a 15-minute slot (typed before this was a select)
    // still needs somewhere to sit, or editing the row would silently drop it.
    if ($value > 0 && $value % 15 !== 0) {
        $opts .= '<option value="' . $value . '" selected>' . fmtMinutes($value) . '</option>';
    }
    $req = $required ? ' required' : '';
    $cls = 'form-control' . ($extraClass !== '' ? ' ' . $extraClass : '');
    $sty = $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="' . $cls . '"' . $req . $sty . '>' . $opts . '</select>';
}

// Format minutes back to a compact "2h 30m" / "45m" string.
function fmtMinutes(int $m): string {
    $m = max(0, $m);
    $h  = intdiv($m, 60);
    $mm = $m % 60;
    if ($h && $mm) return "{$h}h {$mm}m";
    if ($h)        return "{$h}h";
    return "{$mm}m";
}

// Sunday that starts the week containing $date (Y-m-d). Matches the
// Sun–Sat layout of the reference timesheet UI.
function weekStartSunday(string $date): string {
    $t = strtotime($date);
    if ($t === false) $t = time();
    $dow = (int)date('w', $t); // 0=Sun … 6=Sat
    return date('Y-m-d', strtotime("-{$dow} days", $t));
}

// The seven day columns (Sun → Sat) of the week starting $weekStart.
function timeWeekDays(string $weekStart): array {
    $days = [];
    for ($d = 0; $d < 7; $d++) $days[] = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
    return $days;
}

// ── Tasks are a shared list ──────────────────────────────
// Creating a task is a permission (txn_time_task); logging time against one
// is not. Everybody picks from the same list, so the same work carries the
// same name across employees and the report can add it up.
function canManageTimeTasks(): bool { return isSuperadmin() || hasTxn('time_task'); }

// chk_checklists.frequency is optional (see chkHasFrequency), so it is only
// selected when it exists — the timesheet must not break on a database that
// has not run the checklist-cycle migration.
function ttChecklistFreqCol(): string {
    return (function_exists('chkHasFrequency') && chkHasFrequency())
        ? ', cc.frequency AS checklist_freq' : '';
}

// Same for time_entries.chk_item_id, which names the checklist task an entry
// came from. The name is joined rather than stored on the entry: task_label is
// nulled whenever an entry is edited, so a copy kept there would not survive
// someone correcting a duration.
function ttChkTaskCol(): string {
    return (function_exists('chkTimeEntryHasItem') && chkTimeEntryHasItem())
        ? ', ci.task_description AS chk_task_name' : '';
}
function ttChkTaskJoin(): string {
    return (function_exists('chkTimeEntryHasItem') && chkTimeEntryHasItem())
        ? ' LEFT JOIN chk_items ci ON ci.id = t.chk_item_id' : '';
}

// Which of the three things an entry is against. Same order of precedence as
// timeEntryLabel(): a ticket wins, then a task, then a checklist; 'other' is
// a legacy free-text row from before tasks became first-class.
function timeEntryKind(array $e): string {
    if (!empty($e['issue_id']))     return 'ticket';
    if (!empty($e['task_id']))      return 'task';
    if (!empty($e['checklist_id'])) return 'checklist';
    return 'other';
}

// The checklist an entry came from, cycle included: "Admin - HO",
// "Operation - Paresh — Monthly". Two cycles of one department share a name,
// so the cycle is what tells them apart.
function timeChecklistName(array $e): string {
    $name = (string)($e['checklist_name'] ?? ('#' . (int)($e['checklist_id'] ?? 0)));
    $freq = (string)($e['checklist_freq'] ?? 'daily');
    if ($freq !== 'daily' && function_exists('chkFreqLabel')) $name .= ' — ' . chkFreqLabel($freq);
    return $name;
}

// Display label for an entry: "WP-12 — summary" when tied to a ticket, the
// task's name when tied to a task or to a checklist task, else the legacy
// free-text label (rows logged before tasks became first-class).
//
// The checklist's own name is deliberately not in the label: a timesheet row
// is about the work, and "Reconcile petty cash — Admin - HO" repeated down a
// week is mostly the same eleven characters. Which list the row came from is
// carried by timeEntryIcon()'s tooltip instead — and $withSource brings it
// back for the CSV export, where there is no icon to hover.
function timeEntryLabel(array $e, bool $withSource = false): string {
    if (!empty($e['issue_id'])) {
        $s = trim((string)($e['issue_summary'] ?? ''));
        return 'WP-' . (int)$e['issue_id'] . ($s !== '' ? ' — ' . $s : '');
    }
    if (!empty($e['task_id'])) return (string)($e['task_name'] ?? ('Task #' . (int)$e['task_id']));
    if (!empty($e['checklist_id'])) {
        $name = timeChecklistName($e);
        // Entries written before chk_item_id existed name no task, so the
        // checklist is all they have to go on and it stays in the label.
        // Only the task name goes in a timesheet label — the clarification
        // half of the description would run the row off the grid.
        $task = trim((string)($e['chk_task_name'] ?? ''));
        if ($task !== '' && function_exists('chkTaskName')) $task = chkTaskName($task);
        if ($task === '')  return 'Checklist — ' . $name;
        return $withSource ? ($task . ' — ' . $name) : $task;
    }
    return (string)($e['task_label'] ?? '—');
}

// ── Row icons ────────────────────────────────────────────
// Ticket / task / checklist glyphs for the timesheet rows, drawn from the
// same set as the sidebar (navIcon 'issues', 'tasks', 'checklist') so a row
// reads as the thing its nav entry does. Inline SVG rather than an emoji so
// it inherits the surrounding text colour, matching chkClockIcon().
function timeKindIcon(string $kind, int $px = 14, string $title = ''): string {
    $body = [
        'ticket'    => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>'
                     . '<polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/>'
                     . '<line x1="9" y1="15" x2="15" y2="15"/>',
        'task'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'
                     . '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'checklist' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
                     . '<polyline points="22 4 12 14.01 9 11.01"/>',
    ][$kind] ?? '';
    if ($body === '') return '';
    $t = $title !== '' ? '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' : '';
    return '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img"'
         . ' style="vertical-align:-2px;flex:none;color:var(--muted);margin-right:6px">' . $t . $body . '</svg>';
}

// The icon for one entry (or one grid row), titled with what it is — and for
// a checklist row, with the list its task belongs to, which the label above
// no longer spells out.
function timeEntryIcon(array $e, int $px = 14): string {
    $kind = timeEntryKind($e);
    $title = ['ticket' => 'Ticket', 'task' => 'Task', 'checklist' => 'Checklist'][$kind] ?? '';
    if ($kind === 'checklist') $title .= ' — ' . timeChecklistName($e);
    return timeKindIcon($kind, $px, $title);
}

// ── AJAX: search tickets + tasks for the reference picker ──
// Empty keyword returns the shared task list + most recent tickets; a keyword
// filters tasks by name and tickets by WP-number or summary. JSON out.
function doSearchTimeRefs(): void {
    header('Content-Type: application/json');
    $db   = getDb();
    $kw   = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
    $out  = [];

    // Every active task — the list is company-wide, not per employee.
    if ($kw === '') {
        $ts = $db->prepare('SELECT id, name FROM time_tasks WHERE is_active=1 ORDER BY name LIMIT 25');
        $ts->execute();
    } else {
        $ts = $db->prepare('SELECT id, name FROM time_tasks WHERE is_active=1 AND name LIKE ? ORDER BY name LIMIT 25');
        $ts->execute(["%{$kw}%"]);
    }
    foreach ($ts->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $out[] = ['kind' => 'task', 'id' => (int)$t['id'], 'title' => $t['name'], 'sub' => 'Task'];
    }

    // Tickets (any issue — matched by id/WP-number or summary).
    if ($kw === '') {
        $is = $db->prepare('SELECT id, summary FROM issues ORDER BY id DESC LIMIT 15');
        $is->execute();
    } else {
        $idMatch = null;
        if (preg_match('/^WP-?(\d+)$/i', $kw, $m)) $idMatch = (int)$m[1];
        elseif (ctype_digit($kw))                  $idMatch = (int)$kw;
        $is = $db->prepare('SELECT id, summary FROM issues WHERE id = ? OR summary LIKE ? ORDER BY id DESC LIMIT 20');
        $is->execute([$idMatch ?? 0, "%{$kw}%"]);
    }
    foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $out[] = ['kind' => 'ticket', 'id' => (int)$i['id'], 'title' => 'WP-' . (int)$i['id'], 'sub' => $i['summary'] ?? ''];
    }

    echo json_encode(['ok' => true, 'items' => $out]);
    exit;
}

// ── POST: save (insert/update) a time entry ──────────────
function doSaveTimeEntry(): void {
    $db        = getDb();
    $id        = (int)($_POST['id'] ?? 0);
    $ticketRaw = trim($_POST['ticket'] ?? '');
    $taskId    = (int)($_POST['task_id'] ?? 0);
    $entryDate = trim($_POST['entry_date'] ?? '');
    $minutes   = (int)($_POST['minutes'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');
    $weekParam = trim($_POST['week'] ?? '');

    $back = 'index.php?page=my_time' . ($weekParam !== '' ? '&week=' . urlencode($weekParam) : '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) || !strtotime($entryDate)) {
        flash('error', 'Enter a valid work date.');
        header("Location: $back"); exit;
    }
    // Duration is chosen directly in 15-minute slots (15m … 8h).
    if ($minutes <= 0 || $minutes > 8 * 60 || $minutes % 15 !== 0) {
        flash('error', 'Choose a duration between 15 minutes and 8 hours.');
        header("Location: $back"); exit;
    }

    // An entry references either a ticket OR a pre-created task; a ticket wins.
    // Rows logged from a checklist reference neither — they keep their
    // checklist link so the owner can still fix the date/duration/notes here.
    $issueId       = null;
    $taskIdSave    = null;
    $keepChecklist = 0;
    if ($ticketRaw !== '') {
        if (preg_match('/^WP-?(\d+)$/i', $ticketRaw, $m)) $issueId = (int)$m[1];
        elseif (ctype_digit($ticketRaw))                  $issueId = (int)$ticketRaw;
        else {
            flash('error', 'Ticket must look like WP-11 or 11.');
            header("Location: $back"); exit;
        }
        $chk = $db->prepare('SELECT id FROM issues WHERE id = ?');
        $chk->execute([$issueId]);
        if (!$chk->fetch()) {
            flash('error', "Ticket WP-{$issueId} not found.");
            header("Location: $back"); exit;
        }
    } elseif ($taskId > 0) {
        // Any active task will do — the list is shared, so who created it
        // does not decide who may log time against it.
        $tk = $db->prepare('SELECT id FROM time_tasks WHERE id = ? AND is_active = 1');
        $tk->execute([$taskId]);
        if (!$tk->fetch()) {
            flash('error', 'Selected task not found. Ask for it to be added under Tasks.');
            header("Location: $back"); exit;
        }
        $taskIdSave = $taskId;
    } elseif ($id) {
        $cs = $db->prepare('SELECT checklist_id FROM time_entries WHERE id = ?');
        try { $cs->execute([$id]); $keepChecklist = (int)($cs->fetchColumn() ?: 0); } catch (Exception $e) { $keepChecklist = 0; }
        if ($keepChecklist <= 0) {
            flash('error', 'Choose a task or enter a ticket number.');
            header("Location: $back"); exit;
        }
    } else {
        flash('error', 'Choose a task or enter a ticket number.');
        header("Location: $back"); exit;
    }

    try {
        if ($id) {
            // Ownership guard — only the owner (or superadmin) may edit.
            $own = $db->prepare('SELECT employee_code FROM time_entries WHERE id = ?');
            $own->execute([$id]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row || (!isSuperadmin() && $row['employee_code'] !== myCode())) {
                flash('error', 'Entry not found or access denied.');
                header("Location: $back"); exit;
            }
            // Pointing a checklist row at a ticket/task drops the checklist
            // link, so the next checklist sync can't delete it underneath.
            // Repointing a checklist row at a ticket or task drops its
            // checklist link, so the task it named goes with it — otherwise the
            // entry would keep claiming a checklist task it no longer belongs to.
            $dropItem = (function_exists('chkTimeEntryHasItem') && chkTimeEntryHasItem() && $keepChecklist <= 0)
                ? ', chk_item_id=NULL' : '';
            $db->prepare(
                'UPDATE time_entries SET issue_id=?, task_id=?, checklist_id=?, task_label=NULL' . $dropItem
                . ', entry_date=?, minutes=?, notes=? WHERE id=?'
            )->execute([$issueId, $taskIdSave, ($keepChecklist > 0 ? $keepChecklist : null), $entryDate, $minutes, ($notes !== '' ? $notes : null), $id]);
            flash('success', 'Time entry updated.');
        } else {
            $db->prepare(
                'INSERT INTO time_entries (employee_code, issue_id, task_id, entry_date, minutes, notes)
                 VALUES (?,?,?,?,?,?)'
            )->execute([myCode(), $issueId, $taskIdSave, $entryDate, $minutes, ($notes !== '' ? $notes : null)]);
            flash('success', 'Time logged.');
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: delete a time entry ────────────────────────────
function doDeleteTimeEntry(): void {
    $db        = getDb();
    $id        = (int)($_POST['id'] ?? 0);
    $weekParam = trim($_POST['week'] ?? '');
    $back = 'index.php?page=my_time' . ($weekParam !== '' ? '&week=' . urlencode($weekParam) : '');

    $own = $db->prepare('SELECT employee_code FROM time_entries WHERE id = ?');
    $own->execute([$id]);
    $row = $own->fetch(PDO::FETCH_ASSOC);
    if (!$row || (!isSuperadmin() && $row['employee_code'] !== myCode())) {
        flash('error', 'Entry not found or access denied.');
        header("Location: $back"); exit;
    }
    try {
        $db->prepare('DELETE FROM time_entries WHERE id = ?')->execute([$id]);
        flash('success', 'Time entry deleted.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: create a task ──────────────────────────────────
// Gated by txn_time_task: the list is company-wide, so it is curated by the
// people who hold that permission rather than grown by everyone who logs time.
function doSaveTimeTask(): void {
    $db   = getDb();
    $name = trim($_POST['name'] ?? '');
    $back = 'index.php?page=time_tasks';
    if (!canManageTimeTasks()) {
        flash('error', 'You do not have permission to create tasks.');
        header("Location: $back"); exit;
    }
    if ($name === '') {
        flash('error', 'Enter a task name.');
        header("Location: $back"); exit;
    }
    $name = mb_substr($name, 0, 200);
    try {
        // One shared list means one row per name — a second "Query solving"
        // would split the same work across two report rows.
        $dup = $db->prepare('SELECT id FROM time_tasks WHERE is_active = 1 AND name = ?');
        $dup->execute([$name]);
        if ($dup->fetch()) {
            flash('error', 'A task with that name already exists.');
            header("Location: $back"); exit;
        }
        $db->prepare('INSERT INTO time_tasks (employee_code, name) VALUES (?,?)')
           ->execute([myCode(), $name]);
        flash('success', 'Task created.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: delete (deactivate) a task ─────────────────────
// Soft-delete so existing time entries keep their label. Same permission as
// creating one — the task belongs to the company, not to whoever added it.
function doDeleteTimeTask(): void {
    $db   = getDb();
    $id   = (int)($_POST['id'] ?? 0);
    $back = 'index.php?page=time_tasks';
    if (!canManageTimeTasks()) {
        flash('error', 'You do not have permission to remove tasks.');
        header("Location: $back"); exit;
    }
    $own = $db->prepare('SELECT id FROM time_tasks WHERE id = ?');
    $own->execute([$id]);
    if (!$own->fetch()) {
        flash('error', 'Task not found.');
        header("Location: $back"); exit;
    }
    try {
        $db->prepare('UPDATE time_tasks SET is_active = 0 WHERE id = ?')->execute([$id]);
        flash('success', 'Task removed.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── Page: Tasks (the shared task list) ───────────────────
// Everyone sees the whole list and how much of their own time sits on each
// task; only txn_time_task holders can add to it or retire a task.
function pageTimeTasks(): void {
    $db     = getDb();
    $code   = myCode();
    $manage = canManageTimeTasks();
    // Totals across employees stay behind the report permission — this page
    // is otherwise about the viewer's own time.
    $seeAll = isSuperadmin() || hasTxn('time_report');

    // Every active task, with the viewer's own entry count + minutes on it.
    // The employee_code filter sits in the JOIN, not the WHERE, so a task
    // nobody has logged time against still lists (with a zero).
    $st = $db->prepare(
        "SELECT tt.id, tt.name, tt.created_at, tt.employee_code AS created_by,
                e.full_name AS created_by_name,
                COUNT(te.id) AS entry_count, COALESCE(SUM(te.minutes),0) AS total_minutes,
                (SELECT COALESCE(SUM(a.minutes),0) FROM time_entries a WHERE a.task_id = tt.id) AS all_minutes
         FROM time_tasks tt
         LEFT JOIN employees e     ON e.employee_code = tt.employee_code
         LEFT JOIN time_entries te ON te.task_id = tt.id AND te.employee_code = ?
         WHERE tt.is_active = 1
         GROUP BY tt.id, tt.name, tt.created_at, tt.employee_code, e.full_name
         ORDER BY tt.name"
    );
    $st->execute([$code]);
    $tasks = $st->fetchAll(PDO::FETCH_ASSOC);

    // Per-task time entries (which day, how long) for the expandable detail.
    $byTask = [];
    if ($tasks) {
        $es = $db->prepare(
            "SELECT task_id, entry_date, minutes, notes
             FROM time_entries
             WHERE employee_code = ? AND task_id IS NOT NULL
             ORDER BY entry_date DESC, id ASC"
        );
        $es->execute([$code]);
        foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $byTask[(int)$e['task_id']][] = $e;
        }
    }
?>
<div class="page-header">
    <h2>Tasks</h2>
    <a href="?page=my_time" class="btn btn-ghost btn-sm">Go to My Time</a>
</div>

<?php if ($manage): ?>
<div class="form-card" style="margin-bottom:18px">
    <h3 style="font-size:15px;margin-bottom:12px">Create a task</h3>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="save_time_task">
        <div class="form-group" style="flex:1 1 280px;min-width:240px;margin:0">
            <label>Task name</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Query solving" maxlength="200" required>
        </div>
        <button type="submit" class="btn btn-primary">Add task</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin-top:8px">A task created here is available to every employee on their My Time page. Time tied to a ticket uses the ticket number instead.</p>
</div>
<?php else: ?>
<div class="rpt-prompt" style="margin-bottom:18px">These tasks are shared across the company — pick one on <a href="?page=my_time" style="color:var(--accent)">My Time</a> to log time against it. Need a task that isn't listed? Ask a Time Tracking administrator to add it.</div>
<?php endif; ?>

<?php if (empty($tasks)): ?>
<div class="rpt-prompt">No tasks yet<?= $manage ? '. Create one above to start logging time against it.' : ' — nothing has been added to the shared list.' ?></div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr>
            <th>Task</th>
            <th style="width:110px">My entries</th>
            <th style="width:110px">My total</th>
            <?php if ($seeAll): ?><th style="width:120px">All employees</th><?php endif; ?>
            <th style="width:150px">Created by</th>
            <th style="width:120px">Created</th>
            <?php if ($manage): ?><th style="width:100px"></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): $tid = (int)$t['id']; $rows = $byTask[$tid] ?? []; ?>
            <tr class="tk-row" data-tid="<?= $tid ?>" style="cursor:pointer">
                <td>
                    <span class="tk-caret" style="display:inline-block;width:14px;color:var(--muted)">▸</span>
                    <?= h($t['name']) ?>
                </td>
                <td><?= (int)$t['entry_count'] ?></td>
                <td><?= h(fmtMinutes((int)$t['total_minutes'])) ?></td>
                <?php if ($seeAll): ?>
                <td><?= (int)$t['all_minutes'] > 0 ? h(fmtMinutes((int)$t['all_minutes'])) : '<span class="text-muted">—</span>' ?></td>
                <?php endif; ?>
                <td class="text-muted" style="font-size:12px"><?= h($t['created_by_name'] ?? $t['created_by'] ?? '—') ?></td>
                <td class="text-muted" style="font-size:12px"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                <?php if ($manage): ?>
                <td style="white-space:nowrap">
                    <form method="POST" class="inline-form" style="display:inline" onsubmit="event.stopPropagation();return confirm('Remove this task? It disappears from everyone\'s picker; existing time entries keep their label.')" onclick="event.stopPropagation()">
                        <input type="hidden" name="action" value="delete_time_task">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Remove</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <tr class="tk-detail tk-detail-<?= $tid ?>" style="display:none;background:var(--bg)">
                <td colspan="<?= 4 + ($seeAll ? 1 : 0) + ($manage ? 1 : 0) ?>" style="padding:0">
                    <?php if (empty($rows)): ?>
                    <div class="text-muted" style="padding:10px 16px;font-size:12px">You have not logged any time against this task yet.</div>
                    <?php else: ?>
                    <table class="table" style="font-size:12px;margin:0;background:transparent">
                        <thead><tr><th style="width:140px">Day</th><th style="width:90px">Duration</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $e): ?>
                            <tr>
                                <td style="white-space:nowrap"><?= date('D, d M Y', strtotime($e['entry_date'])) ?></td>
                                <td><?= h(fmtMinutes((int)$e['minutes'])) ?></td>
                                <td class="text-muted"><?= h($e['notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="text-muted" style="font-size:12px;margin-top:8px">Click a task to see which day and how much of your own time was logged against it.</p>
<script>
(function () {
    document.querySelectorAll('.tk-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = row.getAttribute('data-tid');
            var caret = row.querySelector('.tk-caret');
            var opened = false;
            document.querySelectorAll('.tk-detail-' + id).forEach(function (d) {
                var show = (d.style.display === 'none');
                d.style.display = show ? '' : 'none';
                opened = show;
            });
            if (caret) caret.textContent = opened ? '▾' : '▸';
        });
    });
})();
</script>
<?php endif; ?>
<?php }

// ── Shared: one employee's week ──────────────────────────
// My Time and the report's per-employee view read the same rows through here,
// so a timesheet looks the same whoever is looking at it.
function timeWeekEntries(string $empCode, string $weekStart, string $weekEnd): array {
    $st = getDb()->prepare(
        "SELECT t.*, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name" . ttChecklistFreqCol() . ttChkTaskCol() . "
         FROM time_entries t
         LEFT JOIN issues i      ON t.issue_id = i.id
         LEFT JOIN time_tasks tk ON t.task_id  = tk.id
         LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id" . ttChkTaskJoin() . "
         WHERE t.employee_code = ? AND t.entry_date BETWEEN ? AND ?
         ORDER BY t.entry_date ASC, t.created_at ASC"
    );
    $st->execute([$empCode, $weekStart, $weekEnd]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Build the timesheet grid: one row per task/ticket, minutes summed into each
// day column (mirrors the ClickUp Task × Day layout). Also keeps a flat
// per-entry list per row, for the expandable sub-rows.
// Returns [grid, dayTotals, weekTotal].
function timeBuildGrid(array $entries, array $days): array {
    $grid      = [];                       // rowKey => entry fields + ['cells'=>[date=>min],'total']
    $dayTotals = array_fill_keys($days, 0);
    $weekTotal = 0;
    foreach ($entries as $e) {
        if (!empty($e['issue_id']))         $key = 'i' . (int)$e['issue_id'];
        elseif (!empty($e['task_id']))      $key = 'k' . (int)$e['task_id'];
        // Keyed by task as well as checklist, so each task is its own row.
        // Entries from before chk_item_id existed have none and still collapse
        // into one row per checklist, as they were written.
        elseif (!empty($e['checklist_id'])) $key = 'c' . (int)$e['checklist_id'] . ':' . (int)($e['chk_item_id'] ?? 0);
        else                                $key = 't' . mb_strtolower(trim((string)($e['task_label'] ?? '')));
        if (!isset($grid[$key])) {
            $grid[$key] = [
                'issue_id'       => $e['issue_id'],
                'issue_summary'  => $e['issue_summary'] ?? null,
                'task_id'        => $e['task_id'] ?? null,
                'task_name'      => $e['task_name'] ?? null,
                'checklist_id'   => $e['checklist_id'] ?? null,
                'checklist_name' => $e['checklist_name'] ?? null,
                'checklist_freq' => $e['checklist_freq'] ?? null,
                'chk_item_id'    => $e['chk_item_id'] ?? null,
                'chk_task_name'  => $e['chk_task_name'] ?? null,
                'task_label'     => $e['task_label'] ?? null,
                'cells'         => array_fill_keys($days, 0),
                'total'         => 0,
                'entries'       => [],   // individual rows, for the expandable sub-rows
            ];
        }
        $grid[$key]['cells'][$e['entry_date']] += (int)$e['minutes'];
        $grid[$key]['total']                   += (int)$e['minutes'];
        $grid[$key]['entries'][]                = $e;
        $dayTotals[$e['entry_date']]           += (int)$e['minutes'];
        $weekTotal                             += (int)$e['minutes'];
    }
    return [$grid, $dayTotals, $weekTotal];
}

// Weekly Task/Ticket × day grid. $editable adds the Edit/Delete controls on
// the expanded entries — the report shows somebody else's week, so it renders
// the same grid with those controls off.
function renderTimesheetGrid(array $grid, array $days, array $dayTotals, int $weekTotal, string $weekStart, bool $editable): void {
    $today = date('Y-m-d');
?>
<div class="table-wrap" data-stack style="margin-bottom:18px">
    <table class="table" style="font-size:13px">
        <thead>
            <tr>
                <th style="min-width:200px">Task / Ticket</th>
                <?php foreach ($days as $day): ?>
                <th style="width:84px;text-align:right<?= $day === $today ? ';color:var(--accent)' : '' ?>">
                    <?= date('D', strtotime($day)) ?><br>
                    <span style="font-weight:400"><?= date('d M', strtotime($day)) ?></span>
                </th>
                <?php endforeach; ?>
                <th style="width:90px;text-align:right">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php $gi = 0; foreach ($grid as $row): $gi++; $cnt = count($row['entries']); ?>
            <tr class="tt-task-row" data-grp="<?= $gi ?>" style="cursor:pointer">
                <td>
                    <span class="tt-caret" style="display:inline-block;width:14px;color:var(--muted)">▸</span>
                    <?= timeEntryIcon($row) ?>
                    <?php if (!empty($row['issue_id'])): ?>
                    <a href="?page=view_issue&id=<?= (int)$row['issue_id'] ?>" target="_blank" style="color:var(--accent)" onclick="event.stopPropagation()">WP-<?= (int)$row['issue_id'] ?></a>
                    <?php if (!empty($row['issue_summary'])): ?><span class="text-muted"> — <?= h($row['issue_summary']) ?></span><?php endif; ?>
                    <?php else: ?>
                    <?= h(timeEntryLabel($row)) ?>
                    <?php endif; ?>
                    <span class="text-muted" style="font-size:11px;margin-left:6px">(<?= $cnt ?> entr<?= $cnt === 1 ? 'y' : 'ies' ?>)</span>
                </td>
                <?php foreach ($days as $day): $m = (int)$row['cells'][$day]; ?>
                <td style="text-align:right<?= $day === $today ? ';background:rgba(99,102,241,.06)' : '' ?>">
                    <?= $m > 0 ? h(fmtMinutes($m)) : '<span class="text-muted">—</span>' ?>
                </td>
                <?php endforeach; ?>
                <td style="text-align:right;font-weight:600"><?= h(fmtMinutes((int)$row['total'])) ?></td>
            </tr>
            <?php foreach ($row['entries'] as $e): ?>
            <tr class="tt-entry-row tt-grp-<?= $gi ?>" style="display:none;background:var(--bg)">
                <td style="padding-left:28px">
                    <span class="text-muted" style="white-space:nowrap"><?= h(fmtMinutes((int)$e['minutes'])) ?></span>
                    <?php if (!empty($e['notes'])): ?><span class="text-muted" style="font-size:11px"> · <?= h($e['notes']) ?></span><?php endif; ?>
                    <?php if ($editable): ?>
                    <span style="margin-left:8px;white-space:nowrap">
                        <a href="?page=my_time&week=<?= h($weekStart) ?>&edit=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                        <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Delete this time entry?')">
                            <input type="hidden" name="action" value="delete_time_entry">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="week" value="<?= h($weekStart) ?>">
                            <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Delete</button>
                        </form>
                    </span>
                    <?php endif; ?>
                </td>
                <?php foreach ($days as $day): ?>
                <td style="text-align:right<?= $day === $today ? ';background:rgba(99,102,241,.06)' : '' ?>">
                    <?= $e['entry_date'] === $day ? h(fmtMinutes((int)$e['minutes'])) : '<span class="text-muted">—</span>' ?>
                </td>
                <?php endforeach; ?>
                <td style="text-align:right"><?= h(fmtMinutes((int)$e['minutes'])) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th style="text-align:right">Daily total</th>
                <?php foreach ($days as $day): ?>
                <th style="text-align:right"><?= $dayTotals[$day] > 0 ? h(fmtMinutes((int)$dayTotals[$day])) : '<span class="text-muted">0h</span>' ?></th>
                <?php endforeach; ?>
                <th style="text-align:right;color:var(--accent)"><?= h(fmtMinutes($weekTotal)) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<p class="text-muted" style="font-size:12px">Click a task row to expand its individual time entries<?= $editable ? ' and edit or delete them' : '' ?>.</p>
<script>
(function () {
    document.querySelectorAll('.tt-task-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var g = row.getAttribute('data-grp');
            var caret = row.querySelector('.tt-caret');
            var opened = false;
            document.querySelectorAll('.tt-grp-' + g).forEach(function (sr) {
                var show = (sr.style.display === 'none');
                sr.style.display = show ? '' : 'none';
                opened = show;
            });
            if (caret) caret.textContent = opened ? '▾' : '▸';
        });
    });
})();
</script>
<?php }

// The same week as a day-grouped list of individual entries.
function renderTimeEntriesList(array $entries, array $days, string $weekStart, bool $editable): void {
    $today = date('Y-m-d');
    foreach ($days as $day):
        $dayRows = array_values(array_filter($entries, fn($e) => $e['entry_date'] === $day));
        if (empty($dayRows)) continue;
        $dayTotal = 0; foreach ($dayRows as $e) $dayTotal += (int)$e['minutes'];
?>
<div class="form-card" style="margin-bottom:12px;padding:12px;max-width:none">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="<?= $day === $today ? 'color:var(--accent)' : '' ?>"><?= date('l, d M', strtotime($day)) ?></strong>
        <span class="badge badge-grey"><?= h(fmtMinutes($dayTotal)) ?></span>
    </div>
    <div class="table-wrap" data-stack>
        <table class="table" style="font-size:13px;table-layout:fixed;width:100%">
            <thead><tr>
                <th style="width:240px">Task / Ticket</th>
                <th>Description</th>
                <th style="width:90px">Duration</th>
                <?php if ($editable): ?><th style="width:130px"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($dayRows as $e): ?>
                <tr>
                    <td style="word-break:break-word">
                        <?= timeEntryIcon($e) ?>
                        <?php if (!empty($e['issue_id'])): ?>
                        <a href="?page=view_issue&id=<?= (int)$e['issue_id'] ?>" target="_blank" style="color:var(--accent)">WP-<?= (int)$e['issue_id'] ?></a>
                        <?php if (!empty($e['issue_summary'])): ?><span class="text-muted"> — <?= h($e['issue_summary']) ?></span><?php endif; ?>
                        <?php else: ?>
                        <?= h(timeEntryLabel($e)) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="white-space:normal;word-break:break-word"><?= h($e['notes'] ?? '') ?: '—' ?></td>
                    <td><?= h(fmtMinutes((int)$e['minutes'])) ?></td>
                    <?php if ($editable): ?>
                    <td style="white-space:nowrap">
                        <a href="?page=my_time&week=<?= h($weekStart) ?>&tview=entries&edit=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                        <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Delete this time entry?')">
                            <input type="hidden" name="action" value="delete_time_entry">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="week" value="<?= h($weekStart) ?>">
                            <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Delete</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach;
}

// ── Page: My Time (personal weekly timesheet) ────────────
function pageMyTime(): void {
    $db   = getDb();
    $code = myCode();

    $weekStart = weekStartSunday($_GET['week'] ?? date('Y-m-d'));
    $weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $prevWeek  = date('Y-m-d', strtotime($weekStart . ' -7 days'));
    $nextWeek  = date('Y-m-d', strtotime($weekStart . ' +7 days'));

    // Entry under edit (own rows only).
    $edit = null;
    if (!empty($_GET['edit'])) {
        $est = $db->prepare(
            "SELECT t.*, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name" . ttChecklistFreqCol() . ttChkTaskCol() . "
             FROM time_entries t
             LEFT JOIN issues i      ON t.issue_id = i.id
             LEFT JOIN time_tasks tk ON t.task_id  = tk.id
             LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id" . ttChkTaskJoin() . "
             WHERE t.id = ?"
        );
        $est->execute([(int)$_GET['edit']]);
        $r = $est->fetch(PDO::FETCH_ASSOC);
        if ($r && ($r['employee_code'] === $code || isSuperadmin())) $edit = $r;
    }

    $entries = timeWeekEntries($code, $weekStart, $weekEnd);
    $days    = timeWeekDays($weekStart);
    [$grid, $dayTotals, $weekTotal] = timeBuildGrid($entries, $days);

    // Form prefill (edit row > ?issue_id deep-link > blank). The reference
    // picker keeps two hidden fields (ticket, task_id) and one display label.
    $prefillTicket = '';
    if ($edit && $edit['issue_id'])                 $prefillTicket = 'WP-' . (int)$edit['issue_id'];
    elseif (!$edit && !empty($_GET['issue_id']))    $prefillTicket = 'WP-' . (int)$_GET['issue_id'];
    $prefillTaskId = $edit ? (int)($edit['task_id'] ?? 0) : 0;
    // The picker field is one line of text with no icon beside it, so a
    // checklist row names its list here even though the grid rows do not.
    if ($edit)                                      $prefillRefLabel = timeEntryLabel($edit, true);
    elseif ($prefillTicket !== '')                  $prefillRefLabel = $prefillTicket;
    else                                            $prefillRefLabel = '';
    $prefillDate     = $edit['entry_date'] ?? date('Y-m-d');
    $prefillMinutes  = $edit ? (int)($edit['minutes'] ?? 0) : 0;
    $prefillNotes    = $edit['notes'] ?? '';

    $weekLabel = date('d M', strtotime($weekStart)) . ' – ' . date('d M Y', strtotime($weekEnd));

    // View toggle: 'timesheet' (Task × day grid) or 'entries' (day-grouped list).
    $view = ($_GET['tview'] ?? '') === 'entries' ? 'entries' : 'timesheet';
?>
<div class="page-header">
    <h2>My Time</h2>
    <span class="badge badge-blue" style="font-size:13px">Week total: <?= h(fmtMinutes($weekTotal)) ?></span>
</div>

<!-- Week navigation -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
    <a href="?page=my_time&week=<?= h($prevWeek) ?>" class="btn btn-ghost btn-sm">‹ Prev</a>
    <strong style="min-width:200px;text-align:center"><?= h($weekLabel) ?></strong>
    <a href="?page=my_time&week=<?= h($nextWeek) ?>" class="btn btn-ghost btn-sm">Next ›</a>
    <a href="?page=my_time" class="btn btn-ghost btn-sm">This week</a>
    <span style="display:inline-flex;align-items:center;gap:6px;margin-left:6px">
        <span class="text-muted" style="font-size:12px">📅 Jump to week</span>
        <input type="date" id="tt-week-pick" class="form-control" value="<?= h($weekStart) ?>"
               style="width:160px" title="Pick any day to open that week">
    </span>
    <span style="margin-left:auto;display:inline-flex;gap:0;border:1px solid var(--border);border-radius:6px;overflow:hidden">
        <a href="?page=my_time&week=<?= h($weekStart) ?>&tview=timesheet"
           class="btn btn-sm <?= $view === 'timesheet' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:0;border:0">Timesheet</a>
        <a href="?page=my_time&week=<?= h($weekStart) ?>&tview=entries"
           class="btn btn-sm <?= $view === 'entries' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:0;border:0">Time entries</a>
    </span>
</div>
<script>
(function () {
    var p = document.getElementById('tt-week-pick');
    if (p) p.addEventListener('change', function () {
        if (this.value) window.location.href = '?page=my_time&week=' + encodeURIComponent(this.value);
    });
})();
</script>

<!-- Log / edit form -->
<div class="form-card" style="margin-bottom:18px;max-width:none">
    <h3 style="font-size:15px;margin-bottom:12px"><?= $edit ? 'Edit time entry' : 'Log time' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="save_time_entry">
        <input type="hidden" name="week" value="<?= h($weekStart) ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="form-grid" style="grid-template-columns:150px 140px 1fr;gap:12px">
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="entry_date" class="form-control" value="<?= h($prefillDate) ?>" required>
            </div>
            <div class="form-group">
                <label>Duration</label>
                <?= durationSelect('minutes', $prefillMinutes, true) ?>
            </div>
            <div class="form-group">
                <label>Notes <span class="text-muted">(optional)</span></label>
                <input type="text" name="notes" class="form-control" value="<?= h($prefillNotes) ?>" placeholder="What did you work on?">
            </div>
        </div>
        <div class="form-group" style="margin-top:12px">
            <label>Ticket or Task</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" id="tt-ref-label" class="form-control" value="<?= h($prefillRefLabel) ?>" placeholder="No reference selected — click Search" readonly style="flex:1;min-width:220px;cursor:pointer">
                <button type="button" id="tt-ref-search" class="btn btn-ghost" title="Search tickets &amp; tasks">🔍 Search</button>
                <button type="button" id="tt-ref-clear" class="btn btn-ghost" title="Clear selection">✕</button>
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add entry' ?></button>
                <?php if ($edit): ?>
                <a href="?page=my_time&week=<?= h($weekStart) ?>" class="btn btn-ghost">Cancel</a>
                <?php endif; ?>
            </div>
            <input type="hidden" name="ticket"  id="tt-ref-ticket" value="<?= h($prefillTicket) ?>">
            <input type="hidden" name="task_id" id="tt-ref-task"   value="<?= (int)$prefillTaskId ?>">
            <p class="text-muted" style="font-size:12px;margin-top:4px">Search a ticket (WP-#) or one of the shared <a href="?page=time_tasks" style="color:var(--accent)">tasks</a> by keyword. Tasks are created by a Time Tracking administrator, so everybody logs the same work under the same name.</p>
        </div>
    </form>
</div>

<!-- Ticket/Task search popup -->
<div id="tt-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:flex-start;justify-content:center">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;width:min(560px,94vw);margin-top:8vh;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.5)">
        <div style="display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid var(--border)">
            <strong style="flex:1">Select ticket or task</strong>
            <button type="button" id="tt-modal-close" class="btn btn-ghost btn-sm">✕</button>
        </div>
        <div style="padding:12px 16px">
            <input type="text" id="tt-modal-search" class="form-control" placeholder="Search by keyword or WP-number…" autocomplete="off">
        </div>
        <div id="tt-modal-results" style="overflow:auto;padding:0 8px 12px"></div>
    </div>
</div>
<script>
(function () {
    var modal   = document.getElementById('tt-modal');
    if (!modal) return;
    var openBtn = document.getElementById('tt-ref-search');
    var labelEl = document.getElementById('tt-ref-label');
    var clearEl = document.getElementById('tt-ref-clear');
    var closeEl = document.getElementById('tt-modal-close');
    var searchEl  = document.getElementById('tt-modal-search');
    var resultsEl = document.getElementById('tt-modal-results');
    var ticketHid = document.getElementById('tt-ref-ticket');
    var taskHid   = document.getElementById('tt-ref-task');
    var timer = null;
    var ICON_TICKET = <?= json_encode(timeKindIcon('ticket', 14, 'Ticket'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var ICON_TASK   = <?= json_encode(timeKindIcon('task',   14, 'Task'),   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function openModal()  { modal.style.display = 'flex'; searchEl.value = ''; searchEl.focus(); run(''); }
    function closeModal() { modal.style.display = 'none'; }

    function run(kw) {
        var fd = new FormData();
        fd.append('action', 'time_search_refs');
        fd.append('kw', kw);
        fetch('index.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok || !d.items.length) {
                    resultsEl.innerHTML = '<div style="padding:14px;color:var(--muted);font-size:13px">No matches.</div>';
                    return;
                }
                resultsEl.innerHTML = d.items.map(function (it) {
                    // Same glyphs the timesheet rows carry, so a result reads
                    // as the row it will become.
                    var badge = (it.kind === 'ticket' ? ICON_TICKET : ICON_TASK)
                        + (it.kind === 'ticket'
                            ? '<span class="badge badge-blue" style="margin-right:6px">Ticket</span>'
                            : '<span class="badge badge-grey" style="margin-right:6px">Task</span>');
                    return '<div class="tt-res" data-kind="' + it.kind + '" data-id="' + it.id +
                        '" data-title="' + esc(it.title) + '" data-sub="' + esc(it.sub) +
                        '" style="padding:10px 12px;border-radius:6px;cursor:pointer">' +
                        '<div style="font-size:13px">' + badge + '<strong>' + esc(it.title) + '</strong></div>' +
                        (it.sub ? '<div style="font-size:12px;color:var(--muted);margin-top:2px">' + esc(it.sub) + '</div>' : '') +
                        '</div>';
                }).join('');
                Array.prototype.forEach.call(resultsEl.querySelectorAll('.tt-res'), function (node) {
                    node.addEventListener('mouseenter', function () { node.style.background = 'var(--bg)'; });
                    node.addEventListener('mouseleave', function () { node.style.background = ''; });
                    node.addEventListener('click', function () {
                        if (node.dataset.kind === 'ticket') {
                            ticketHid.value = node.dataset.title; taskHid.value = '0';
                            labelEl.value = node.dataset.title + (node.dataset.sub ? ' — ' + node.dataset.sub : '');
                        } else {
                            taskHid.value = node.dataset.id; ticketHid.value = '';
                            labelEl.value = node.dataset.title;
                        }
                        closeModal();
                    });
                });
            })
            .catch(function () { /* silent */ });
    }

    openBtn.addEventListener('click', openModal);
    labelEl.addEventListener('click', openModal);
    closeEl.addEventListener('click', closeModal);
    clearEl.addEventListener('click', function () { ticketHid.value = ''; taskHid.value = '0'; labelEl.value = ''; });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    searchEl.addEventListener('input', function (e) {
        clearTimeout(timer);
        var kw = e.target.value.trim();
        timer = setTimeout(function () { run(kw); }, 200);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });
})();
</script>

<?php if (empty($entries)): ?>
<div class="rpt-prompt">No time logged this week yet. Use the form above to add an entry.</div>

<?php elseif ($view === 'entries'): ?>
<!-- Time entries view — grouped by day -->
<?php renderTimeEntriesList($entries, $days, $weekStart, true); ?>

<?php else: ?>
<!-- Weekly timesheet grid (Task/Ticket × day) -->
<?php renderTimesheetGrid($grid, $days, $dayTotals, $weekTotal, $weekStart, true); ?>
<?php endif; ?>
<?php }

// The report's ticket box takes "WP-11" or "11"; null means it was typed as
// something that can never match an issue, which the callers turn into a
// filter that selects nothing rather than one they silently ignore.
function timeTicketFilterId(string $ticket): ?int {
    if (preg_match('/^WP-?(\d+)$/i', $ticket, $m)) return (int)$m[1];
    if (ctype_digit($ticket))                      return (int)$ticket;
    return null;
}

// ── Shared query for the report + its CSV export ─────────
function timeReportRows(string $emp, string $from, string $to, string $ticket): array {
    $db = getDb();
    $where = [];
    $params = [];
    if ($emp !== '')  { $where[] = 't.employee_code = ?'; $params[] = $emp; }
    if ($from !== '') { $where[] = 't.entry_date >= ?';   $params[] = $from; }
    if ($to !== '')   { $where[] = 't.entry_date <= ?';   $params[] = $to; }
    if ($ticket !== '') {
        $tid = timeTicketFilterId($ticket);
        if ($tid !== null) { $where[] = 't.issue_id = ?'; $params[] = $tid; }
        else               { $where[] = '1=0'; }
    }
    $sql = "SELECT t.*, e.full_name AS emp_name, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name" . ttChecklistFreqCol() . ttChkTaskCol() . "
            FROM time_entries t
            LEFT JOIN employees e  ON t.employee_code = e.employee_code
            LEFT JOIN issues i     ON t.issue_id = i.id
            LEFT JOIN time_tasks tk ON t.task_id = tk.id
            LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id" . ttChkTaskJoin() . "";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY t.entry_date DESC, e.full_name ASC, t.created_at ASC LIMIT 1000';
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($rows as $r) $total += (int)$r['minutes'];
    return [$rows, $total];
}

// ── Per-employee day totals for one week ─────────────────
// One grouped query for the whole company, keyed employee_code => date =>
// minutes. Rows for employees who logged nothing simply do not come back.
function timeReportDayTotals(string $from, string $to, string $ticket): array {
    $where  = ['t.entry_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($ticket !== '') {
        $tid = timeTicketFilterId($ticket);
        if ($tid !== null) { $where[] = 't.issue_id = ?'; $params[] = $tid; }
        else               { $where[] = '1=0'; }
    }
    $st = getDb()->prepare(
        'SELECT t.employee_code, t.entry_date, SUM(t.minutes) AS minutes
         FROM time_entries t
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY t.employee_code, t.entry_date'
    );
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['employee_code']][(string)$r['entry_date']] = (int)$r['minutes'];
    }
    return $out;
}

// Initials for the people list, e.g. "Pradhyuman Solanki" → "PS".
function timeInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (!$parts) return '?';
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last  = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

// ── Page: Time Tracking Report (cross-employee) ──────────
// Two views behind one page: the week's timesheets for everybody (People ×
// day, the default), and one employee's week opened from it — which renders
// through the same grid as My Time, read-only.
function pageTimeReport(): void {
    if (!hasTxn('time_report')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }
    $emp    = trim($_GET['emp'] ?? '');
    $ticket = trim($_GET['ticket'] ?? '');
    $idle   = !empty($_GET['idle']);   // also list employees with no time this week

    // Week filter (Sun–Sat) drives the from/to range.
    $weekStart = weekStartSunday($_GET['week'] ?? date('Y-m-d'));
    $weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $prevWeek  = date('Y-m-d', strtotime($weekStart . ' -7 days'));
    $nextWeek  = date('Y-m-d', strtotime($weekStart . ' +7 days'));
    $thisWeek  = weekStartSunday(date('Y-m-d'));
    $weekLabel = date('d M', strtotime($weekStart)) . ' – ' . date('d M Y', strtotime($weekEnd));
    $from = $weekStart;
    $to   = $weekEnd;
    $days  = timeWeekDays($weekStart);
    $today = date('Y-m-d');

    $employees = getEmployeesLite();
    $empName   = '';
    foreach ($employees as $e) { if ($e['employee_code'] === $emp) { $empName = $e['full_name']; break; } }
    if ($emp !== '' && $empName === '') $empName = $emp;   // code with no employee row

    // Nav links / export preserve the applied filters.
    $navBase   = '?page=time_report'
        . ($emp !== ''    ? '&emp=' . urlencode($emp) : '')
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '')
        . ($idle ? '&idle=1' : '');
    $exportUrl = '?page=export_time_report&from_date=' . urlencode($from) . '&to_date=' . urlencode($to)
        . ($emp !== ''    ? '&emp=' . urlencode($emp) : '')
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '');
?>
<div class="page-header">
    <h2><?= $emp !== '' ? 'Timesheet · ' . h($empName) : 'All Timesheets' ?></h2>
    <?php if ($emp !== ''): ?>
    <a href="?page=time_report&week=<?= h($weekStart) ?><?= $ticket !== '' ? '&ticket=' . urlencode($ticket) : '' ?><?= $idle ? '&idle=1' : '' ?>" class="btn btn-ghost btn-sm">‹ All timesheets</a>
    <?php endif; ?>
</div>

<!-- Week navigation -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
    <a href="<?= $navBase ?>&week=<?= h($prevWeek) ?>" class="btn btn-ghost btn-sm">‹ Prev</a>
    <strong style="min-width:180px;text-align:center"><?= h($weekLabel) ?></strong>
    <a href="<?= $navBase ?>&week=<?= h($nextWeek) ?>" class="btn btn-ghost btn-sm">Next ›</a>
    <a href="<?= $navBase ?>&week=<?= h($thisWeek) ?>" class="btn btn-ghost btn-sm">This week</a>
    <span style="display:inline-flex;align-items:center;gap:6px;margin-left:6px">
        <span class="text-muted" style="font-size:12px">📅 Jump to week</span>
        <input type="date" id="trWeekPick" class="form-control" value="<?= h($weekStart) ?>" style="width:160px">
    </span>
    <a href="<?= $exportUrl ?>" class="btn btn-ghost btn-sm" target="_blank" style="margin-left:auto">Export CSV</a>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <input type="hidden" name="page" value="time_report">
    <input type="hidden" name="week" value="<?= h($weekStart) ?>">
    <div class="form-group" style="margin:0">
        <label>Employee</label>
        <span class="input-clear-wrap" style="display:flex;width:240px">
            <input type="hidden" name="emp" id="trEmpId" value="<?= h($emp) ?>">
            <input type="text" id="trEmpSearch" class="form-control" placeholder="All employees — type to search"
                   value="<?= h($emp !== '' ? $empName : '') ?>" autocomplete="off">
            <button type="button" id="trEmpClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
            <div id="trEmpList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
        </span>
    </div>
    <div class="form-group" style="margin:0">
        <label>Ticket</label>
        <input type="text" name="ticket" class="form-control" style="width:140px" value="<?= h($ticket) ?>" placeholder="e.g. WP-11">
    </div>
    <?php if ($emp === ''): ?>
    <label class="checkbox-label" style="font-size:13px;display:flex;align-items:center;gap:6px;margin:0 0 6px">
        <input type="checkbox" name="idle" value="1" <?= $idle ? 'checked' : '' ?>>
        Show employees with no time
    </label>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Apply</button>
</form>
<script>
(function () {
    var wp = document.getElementById('trWeekPick');
    if (wp) wp.addEventListener('change', function () {
        if (this.value) window.location.href = '<?= $navBase ?>&week=' + encodeURIComponent(this.value);
    });
    var search = document.getElementById('trEmpSearch');
    var hidden = document.getElementById('trEmpId');
    var clearBtn = document.getElementById('trEmpClear');
    var list = document.getElementById('trEmpList');
    if (!search || !hidden || !list) return;
    var data = <?= json_encode(array_map(fn($e) => ['code' => $e['employee_code'], 'name' => $e['full_name'] . ((int)$e['is_active'] === 0 ? ' (inactive)' : '')], $employees), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    function render(q) {
        q = (q || '').trim().toLowerCase();
        var m = q === '' ? data : data.filter(function (x) { return x.name.toLowerCase().indexOf(q) !== -1 || x.code.toLowerCase().indexOf(q) !== -1; });
        var html = '<div class="tr-emp-opt" data-code="" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.06)">All employees</div>';
        if (!m.length) { html += '<div style="padding:10px 12px;color:var(--muted);font-size:13px">No matches</div>'; }
        else { html += m.slice(0, 300).map(function (x) {
            return '<div class="tr-emp-opt" data-code="' + esc(x.code) + '" style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(255,255,255,.04)">' + esc(x.name) + '</div>';
        }).join(''); }
        list.innerHTML = html;
        list.style.display = 'block';
    }
    function hide() { list.style.display = 'none'; }
    search.addEventListener('focus', function () { render(search.value); });
    search.addEventListener('input', function () { hidden.value = ''; render(search.value); });
    list.addEventListener('mousedown', function (ev) {
        var o = ev.target.closest('.tr-emp-opt'); if (!o) return;
        ev.preventDefault();
        hidden.value = o.getAttribute('data-code');
        search.value = o.getAttribute('data-code') === '' ? '' : o.textContent;
        hide();
    });
    document.addEventListener('mousedown', function (ev) {
        if (ev.target !== search && !list.contains(ev.target) && ev.target !== clearBtn) hide();
    });
    if (clearBtn) clearBtn.addEventListener('click', function () { search.value = ''; hidden.value = ''; search.focus(); render(''); });
})();
</script>
<?php
    if ($emp !== '') {
        timeReportEmployeeView($emp, $empName, $weekStart, $weekEnd, $days, $ticket);
        return;
    }

    // ── People × day, the week's timesheets for everybody ──
    $totals = timeReportDayTotals($from, $to, $ticket);

    // Departments give each row a subtitle, the way the reference layout
    // carries one under the name.
    $deptNames = [];
    foreach (getDepartments() as $d) $deptNames[(int)$d['id']] = (string)$d['department_name'];

    $people = [];
    foreach ($employees as $e) {
        $code  = (string)$e['employee_code'];
        $cells = $totals[$code] ?? [];
        // Inactive employees who logged time that week still belong in the
        // week's numbers; the ones who did not are hidden with the rest.
        $hasTime = !empty($cells);
        if (!$hasTime && (!$idle || (int)$e['is_active'] === 0)) continue;
        $row = ['code' => $code, 'name' => (string)$e['full_name'],
                'dept' => $deptNames[(int)($e['department_id'] ?? 0)] ?? '',
                'inactive' => (int)$e['is_active'] === 0,
                'cells' => [], 'total' => 0];
        foreach ($days as $day) {
            $m = (int)($cells[$day] ?? 0);
            $row['cells'][$day] = $m;
            $row['total'] += $m;
        }
        $people[] = $row;
        unset($totals[$code]);
    }
    // Time logged by a code with no employee row (deleted staff) would other-
    // wise vanish from the week's totals, so it gets a row of its own.
    foreach ($totals as $code => $cells) {
        $row = ['code' => (string)$code, 'name' => (string)$code, 'dept' => '', 'inactive' => true,
                'cells' => [], 'total' => 0];
        foreach ($days as $day) {
            $m = (int)($cells[$day] ?? 0);
            $row['cells'][$day] = $m;
            $row['total'] += $m;
        }
        $people[] = $row;
    }
    usort($people, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $weekTotal = 0;
    foreach ($people as $p) $weekTotal += $p['total'];
    $linkBase = '?page=time_report&week=' . urlencode($weekStart)
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '')
        . ($idle ? '&idle=1' : '');
?>
<?php if (empty($people)): ?>
<div class="rpt-prompt">No time logged in this week<?= $ticket !== '' ? ' against ' . h($ticket) : '' ?>. Tick <strong>Show employees with no time</strong> to list everybody anyway.</div>
<?php else: ?>
<div style="display:flex;align-items:center;gap:10px;margin:8px 0 10px;flex-wrap:wrap">
    <div class="table-count" style="margin:0"><?= count($people) ?> <?= count($people) === 1 ? 'person' : 'people' ?> · total <?= h(fmtMinutes($weekTotal)) ?></div>
    <input type="text" id="trPeopleFilter" class="form-control" placeholder="Filter people…" style="width:220px;margin-left:auto">
</div>
<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead>
            <tr>
                <th style="min-width:220px">People (<?= count($people) ?>)</th>
                <th style="width:110px"></th>
                <?php foreach ($days as $day): ?>
                <th style="width:84px;text-align:right<?= $day === $today ? ';color:var(--accent)' : '' ?>">
                    <?= date('D', strtotime($day)) ?><br>
                    <span style="font-weight:400"><?= date('d M', strtotime($day)) ?></span>
                </th>
                <?php endforeach; ?>
                <th style="width:90px;text-align:right">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($people as $p): $open = $linkBase . '&emp=' . urlencode($p['code']); ?>
            <tr class="tr-person" data-name="<?= h(mb_strtolower($p['name'] . ' ' . $p['code'] . ' ' . $p['dept'])) ?>">
                <td>
                    <span style="display:inline-flex;align-items:center;gap:8px">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;font-weight:600;flex:none"><?= h(timeInitials($p['name'])) ?></span>
                        <span>
                            <a href="<?= $open ?>" style="color:inherit;font-weight:600"><?= h($p['name']) ?></a>
                            <?php if ($p['inactive']): ?><span class="badge badge-grey" style="margin-left:6px;font-size:10px">inactive</span><?php endif; ?>
                            <?php if ($p['dept'] !== ''): ?><br><span class="text-muted" style="font-size:11px"><?= h($p['dept']) ?></span><?php endif; ?>
                        </span>
                    </span>
                </td>
                <td><a href="<?= $open ?>" class="btn btn-ghost btn-sm">Open →</a></td>
                <?php foreach ($days as $day): $m = (int)$p['cells'][$day]; ?>
                <td style="text-align:right<?= $day === $today ? ';background:rgba(99,102,241,.06)' : '' ?>">
                    <?= $m > 0 ? h(fmtMinutes($m)) : '<span class="text-muted">0h</span>' ?>
                </td>
                <?php endforeach; ?>
                <td style="text-align:right;font-weight:600"><?= $p['total'] > 0 ? h(fmtMinutes((int)$p['total'])) : '<span class="text-muted">0h</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="text-muted" style="font-size:12px;margin-top:8px">Open a person to see their week the way they see it on My Time.</p>
<script>
(function () {
    var box = document.getElementById('trPeopleFilter');
    if (!box) return;
    box.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('.tr-person').forEach(function (row) {
            row.style.display = (q === '' || row.getAttribute('data-name').indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>
<?php endif;
}

// One employee's week, rendered through the same grid as My Time but with
// the editing controls off — the report reads other people's timesheets.
function timeReportEmployeeView(string $emp, string $empName, string $weekStart, string $weekEnd, array $days, string $ticket): void {
    $entries = timeWeekEntries($emp, $weekStart, $weekEnd);
    [$grid, $dayTotals, $weekTotal] = timeBuildGrid($entries, $days);
    $view = ($_GET['tview'] ?? '') === 'entries' ? 'entries' : 'timesheet';
    $base = '?page=time_report&emp=' . urlencode($emp) . '&week=' . urlencode($weekStart)
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '');
?>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
    <span class="badge badge-blue" style="font-size:13px">Week total: <?= h(fmtMinutes($weekTotal)) ?></span>
    <span style="margin-left:auto;display:inline-flex;gap:0;border:1px solid var(--border);border-radius:6px;overflow:hidden">
        <a href="<?= $base ?>&tview=timesheet" class="btn btn-sm <?= $view === 'timesheet' ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:0;border:0">Timesheet</a>
        <a href="<?= $base ?>&tview=entries"   class="btn btn-sm <?= $view === 'entries'   ? 'btn-primary' : 'btn-ghost' ?>" style="border-radius:0;border:0">Time entries</a>
    </span>
</div>

<?php if ($ticket !== ''): ?>
<p class="text-muted" style="font-size:12px;margin:-6px 0 12px">Showing the whole week — the <?= h($ticket) ?> filter narrows the people list, not an opened timesheet.</p>
<?php endif; ?>

<?php if (empty($entries)): ?>
<div class="rpt-prompt"><?= h($empName) ?> logged no time in this week.</div>
<?php elseif ($view === 'entries'): ?>
<?php renderTimeEntriesList($entries, $days, $weekStart, false); ?>
<?php else: ?>
<?php renderTimesheetGrid($grid, $days, $dayTotals, $weekTotal, $weekStart, false); ?>
<?php endif; ?>
<?php }

// ── CSV export for the report ────────────────────────────
function exportTimeReport(): void {
    if (!hasTxn('time_report')) { http_response_code(403); exit; }
    $emp    = trim($_GET['emp'] ?? '');
    $from   = trim($_GET['from_date'] ?? '');
    $to     = trim($_GET['to_date'] ?? '');
    $ticket = trim($_GET['ticket'] ?? '');
    [$rows, $total] = timeReportRows($emp, $from, $to, $ticket);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="time_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Employee Code','Employee','Ticket','Task','Duration','Minutes','Notes'], escape: '');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['entry_date'],
            $r['employee_code'],
            $r['emp_name'] ?? '',
            !empty($r['issue_id']) ? 'WP-' . (int)$r['issue_id'] : '',
            !empty($r['issue_id']) ? ($r['issue_summary'] ?? '') : timeEntryLabel($r, true),
            fmtMinutes((int)$r['minutes']),
            (int)$r['minutes'],
            $r['notes'] ?? '',
        ], escape: '');
    }
    fputcsv($out, [], escape: '');
    fputcsv($out, ['', '', '', '', 'TOTAL', fmtMinutes($total), $total, ''], escape: '');
    fclose($out);
    exit;
}
