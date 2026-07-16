<?php
// =========================================================
// Time Tracking Module — personal timesheets + admin report
//
// Every employee logs blocks of time ("My Time"), optionally against
// a ticket (issues.id → WP-{id}) or with a free-text task label.
// Duration is stored as whole minutes on `time_entries`. A cross-
// employee report ("Time Tracking Report") is gated by txn_time_report.
// Mirrors the conventions used across modules/: getDb() (PDO), myCode()
// for the owner, flash() + header() redirects on POST, h() on output.
// =========================================================

// ── Duration helpers ─────────────────────────────────────
// A <select> of duration slots in 15-minute steps (15m … 8h), values in
// whole minutes, labels like "2h 30m". $value (minutes) pre-selects a slot.
function durationSelect(string $name, int $value = 0, bool $required = false): string {
    $opts = '<option value="">--</option>';
    for ($mins = 15; $mins <= 8 * 60; $mins += 15) {
        $sel   = $mins === $value ? ' selected' : '';
        $opts .= '<option value="' . $mins . '"' . $sel . '>' . fmtMinutes($mins) . '</option>';
    }
    $req = $required ? ' required' : '';
    return '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="form-control"' . $req . '>' . $opts . '</select>';
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

// Display label for an entry: "WP-12 — summary" when tied to a ticket,
// the created task's name when tied to a task, else the legacy free-text
// label (rows logged before tasks became first-class).
function timeEntryLabel(array $e): string {
    if (!empty($e['issue_id'])) {
        $s = trim((string)($e['issue_summary'] ?? ''));
        return 'WP-' . (int)$e['issue_id'] . ($s !== '' ? ' — ' . $s : '');
    }
    if (!empty($e['task_id'])) return (string)($e['task_name'] ?? ('Task #' . (int)$e['task_id']));
    if (!empty($e['checklist_id'])) return 'Checklist — ' . (string)($e['checklist_name'] ?? ('#' . (int)$e['checklist_id']));
    return (string)($e['task_label'] ?? '—');
}

// ── AJAX: search tickets + tasks for the reference picker ──
// Empty keyword returns the user's tasks + most recent tickets; a keyword
// filters tasks by name and tickets by WP-number or summary. JSON out.
function doSearchTimeRefs(): void {
    header('Content-Type: application/json');
    $db   = getDb();
    $code = myCode();
    $kw   = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
    $out  = [];

    // Own active tasks.
    if ($kw === '') {
        $ts = $db->prepare('SELECT id, name FROM time_tasks WHERE employee_code=? AND is_active=1 ORDER BY name LIMIT 25');
        $ts->execute([$code]);
    } else {
        $ts = $db->prepare('SELECT id, name FROM time_tasks WHERE employee_code=? AND is_active=1 AND name LIKE ? ORDER BY name LIMIT 25');
        $ts->execute([$code, "%{$kw}%"]);
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
    $issueId    = null;
    $taskIdSave = null;
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
        // The task must exist and belong to this user (superadmin may use any).
        $tk = $db->prepare('SELECT id FROM time_tasks WHERE id = ? AND is_active = 1' . (isSuperadmin() ? '' : ' AND employee_code = ?'));
        $tk->execute(isSuperadmin() ? [$taskId] : [$taskId, myCode()]);
        if (!$tk->fetch()) {
            flash('error', 'Selected task not found. Create it first under Tasks.');
            header("Location: $back"); exit;
        }
        $taskIdSave = $taskId;
    } else {
        flash('error', 'Choose a task or enter a ticket number. Create tasks under Tasks first.');
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
            $db->prepare(
                'UPDATE time_entries SET issue_id=?, task_id=?, task_label=NULL, entry_date=?, minutes=?, notes=? WHERE id=?'
            )->execute([$issueId, $taskIdSave, $entryDate, $minutes, ($notes !== '' ? $notes : null), $id]);
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
function doSaveTimeTask(): void {
    $name = trim($_POST['name'] ?? '');
    $back = 'index.php?page=time_tasks';
    if ($name === '') {
        flash('error', 'Enter a task name.');
        header("Location: $back"); exit;
    }
    try {
        getDb()->prepare('INSERT INTO time_tasks (employee_code, name) VALUES (?,?)')
               ->execute([myCode(), mb_substr($name, 0, 200)]);
        flash('success', 'Task created.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: delete (deactivate) a task ─────────────────────
// Soft-delete so existing time entries keep their label. Owner-or-superadmin.
function doDeleteTimeTask(): void {
    $db   = getDb();
    $id   = (int)($_POST['id'] ?? 0);
    $back = 'index.php?page=time_tasks';
    $own  = $db->prepare('SELECT employee_code FROM time_tasks WHERE id = ?');
    $own->execute([$id]);
    $row = $own->fetch(PDO::FETCH_ASSOC);
    if (!$row || (!isSuperadmin() && $row['employee_code'] !== myCode())) {
        flash('error', 'Task not found or access denied.');
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

// ── Page: Tasks (create & manage personal tasks) ─────────
function pageTimeTasks(): void {
    $db   = getDb();
    $code = myCode();

    // Each task with its logged-entry count + total minutes (own data).
    $st = $db->prepare(
        "SELECT tt.id, tt.name, tt.created_at,
                COUNT(te.id) AS entry_count, COALESCE(SUM(te.minutes),0) AS total_minutes
         FROM time_tasks tt
         LEFT JOIN time_entries te ON te.task_id = tt.id
         WHERE tt.employee_code = ? AND tt.is_active = 1
         GROUP BY tt.id, tt.name, tt.created_at
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
    <p class="text-muted" style="font-size:12px;margin-top:8px">Create tasks here, then pick them when logging time on the My Time page. Time tied to a ticket uses the ticket number instead.</p>
</div>

<?php if (empty($tasks)): ?>
<div class="rpt-prompt">No tasks yet. Create one above to start logging time against it.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr><th>Task</th><th style="width:110px">Entries</th><th style="width:110px">Total</th><th style="width:120px">Created</th><th style="width:100px"></th></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): $tid = (int)$t['id']; $rows = $byTask[$tid] ?? []; ?>
            <tr class="tk-row" data-tid="<?= $tid ?>" style="cursor:pointer">
                <td>
                    <span class="tk-caret" style="display:inline-block;width:14px;color:var(--muted)">▸</span>
                    <?= h($t['name']) ?>
                </td>
                <td><?= (int)$t['entry_count'] ?></td>
                <td><?= h(fmtMinutes((int)$t['total_minutes'])) ?></td>
                <td class="text-muted" style="font-size:12px"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
                <td style="white-space:nowrap">
                    <form method="POST" class="inline-form" style="display:inline" onsubmit="event.stopPropagation();return confirm('Remove this task? Existing time entries keep their label.')" onclick="event.stopPropagation()">
                        <input type="hidden" name="action" value="delete_time_task">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Remove</button>
                    </form>
                </td>
            </tr>
            <tr class="tk-detail tk-detail-<?= $tid ?>" style="display:none;background:var(--bg)">
                <td colspan="5" style="padding:0">
                    <?php if (empty($rows)): ?>
                    <div class="text-muted" style="padding:10px 16px;font-size:12px">No time logged against this task yet.</div>
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
<p class="text-muted" style="font-size:12px;margin-top:8px">Click a task to see which day and how much time was logged against it.</p>
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
            "SELECT t.*, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name
             FROM time_entries t
             LEFT JOIN issues i      ON t.issue_id = i.id
             LEFT JOIN time_tasks tk ON t.task_id  = tk.id
             LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id
             WHERE t.id = ?"
        );
        $est->execute([(int)$_GET['edit']]);
        $r = $est->fetch(PDO::FETCH_ASSOC);
        if ($r && ($r['employee_code'] === $code || isSuperadmin())) $edit = $r;
    }

    $st = $db->prepare(
        "SELECT t.*, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name
         FROM time_entries t
         LEFT JOIN issues i      ON t.issue_id = i.id
         LEFT JOIN time_tasks tk ON t.task_id  = tk.id
         LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id
         WHERE t.employee_code = ? AND t.entry_date BETWEEN ? AND ?
         ORDER BY t.entry_date ASC, t.created_at ASC"
    );
    $st->execute([$code, $weekStart, $weekEnd]);
    $entries = $st->fetchAll(PDO::FETCH_ASSOC);

    // The seven day columns (Sun → Sat) for this week.
    $days = [];
    for ($d = 0; $d < 7; $d++) $days[] = date('Y-m-d', strtotime($weekStart . " +{$d} days"));
    $today = date('Y-m-d');

    // Build the timesheet grid: one row per task/ticket, minutes summed
    // into each day column (mirrors the ClickUp Task × Day layout). Also
    // keep a flat per-entry list for the editable detail table below.
    $grid      = [];                       // rowKey => entry fields + ['cells'=>[date=>min],'total']
    $dayTotals = array_fill_keys($days, 0);
    $weekTotal = 0;
    foreach ($entries as $e) {
        if (!empty($e['issue_id']))         $key = 'i' . (int)$e['issue_id'];
        elseif (!empty($e['task_id']))      $key = 'k' . (int)$e['task_id'];
        elseif (!empty($e['checklist_id'])) $key = 'c' . (int)$e['checklist_id'];
        else                                $key = 't' . mb_strtolower(trim((string)($e['task_label'] ?? '')));
        if (!isset($grid[$key])) {
            $grid[$key] = [
                'issue_id'       => $e['issue_id'],
                'issue_summary'  => $e['issue_summary'] ?? null,
                'task_id'        => $e['task_id'] ?? null,
                'task_name'      => $e['task_name'] ?? null,
                'checklist_id'   => $e['checklist_id'] ?? null,
                'checklist_name' => $e['checklist_name'] ?? null,
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

    // Form prefill (edit row > ?issue_id deep-link > blank). The reference
    // picker keeps two hidden fields (ticket, task_id) and one display label.
    $prefillTicket = '';
    if ($edit && $edit['issue_id'])                 $prefillTicket = 'WP-' . (int)$edit['issue_id'];
    elseif (!$edit && !empty($_GET['issue_id']))    $prefillTicket = 'WP-' . (int)$_GET['issue_id'];
    $prefillTaskId = $edit ? (int)($edit['task_id'] ?? 0) : 0;
    if ($edit)                                      $prefillRefLabel = timeEntryLabel($edit);
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
            <p class="text-muted" style="font-size:12px;margin-top:4px">Search a ticket (WP-#) or one of your <a href="?page=time_tasks" style="color:var(--accent)">tasks</a> by keyword. Tip: create tasks under <a href="?page=time_tasks" style="color:var(--accent)">Tasks</a>.</p>
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
                    var badge = it.kind === 'ticket'
                        ? '<span class="badge badge-blue" style="margin-right:6px">Ticket</span>'
                        : '<span class="badge badge-grey" style="margin-right:6px">Task</span>';
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
<?php for ($d = 0; $d < 7; $d++):
    $day = $days[$d];
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
                <th style="width:130px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($dayRows as $e): ?>
                <tr>
                    <td style="word-break:break-word">
                        <?php if (!empty($e['issue_id'])): ?>
                        <a href="?page=view_issue&id=<?= (int)$e['issue_id'] ?>" target="_blank" style="color:var(--accent)">WP-<?= (int)$e['issue_id'] ?></a>
                        <?php if (!empty($e['issue_summary'])): ?><span class="text-muted"> — <?= h($e['issue_summary']) ?></span><?php endif; ?>
                        <?php else: ?>
                        <?= h(timeEntryLabel($e)) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="white-space:normal;word-break:break-word"><?= h($e['notes'] ?? '') ?: '—' ?></td>
                    <td><?= h(fmtMinutes((int)$e['minutes'])) ?></td>
                    <td style="white-space:nowrap">
                        <a href="?page=my_time&week=<?= h($weekStart) ?>&tview=entries&edit=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                        <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Delete this time entry?')">
                            <input type="hidden" name="action" value="delete_time_entry">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="week" value="<?= h($weekStart) ?>">
                            <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endfor; ?>

<?php else: ?>
<!-- Weekly timesheet grid (Task/Ticket × day) -->
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
                    <span style="margin-left:8px;white-space:nowrap">
                        <a href="?page=my_time&week=<?= h($weekStart) ?>&edit=<?= (int)$e['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                        <form method="POST" class="inline-form" style="display:inline" onsubmit="return confirm('Delete this time entry?')">
                            <input type="hidden" name="action" value="delete_time_entry">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="week" value="<?= h($weekStart) ?>">
                            <button type="submit" class="btn btn-sm badge-red" style="cursor:pointer">Delete</button>
                        </form>
                    </span>
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

<p class="text-muted" style="font-size:12px">Click a task row to expand its individual time entries (start–end) and edit or delete them.</p>
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
<?php endif; ?>
<?php }

// ── Shared query for the report + its CSV export ─────────
function timeReportRows(string $emp, string $from, string $to, string $ticket): array {
    $db = getDb();
    $where = [];
    $params = [];
    if ($emp !== '')  { $where[] = 't.employee_code = ?'; $params[] = $emp; }
    if ($from !== '') { $where[] = 't.entry_date >= ?';   $params[] = $from; }
    if ($to !== '')   { $where[] = 't.entry_date <= ?';   $params[] = $to; }
    if ($ticket !== '') {
        $tid = null;
        if (preg_match('/^WP-?(\d+)$/i', $ticket, $m)) $tid = (int)$m[1];
        elseif (ctype_digit($ticket))                  $tid = (int)$ticket;
        if ($tid !== null) { $where[] = 't.issue_id = ?'; $params[] = $tid; }
        else               { $where[] = '1=0'; }
    }
    $sql = "SELECT t.*, e.full_name AS emp_name, i.summary AS issue_summary, tk.name AS task_name, cc.name AS checklist_name
            FROM time_entries t
            LEFT JOIN employees e  ON t.employee_code = e.employee_code
            LEFT JOIN issues i     ON t.issue_id = i.id
            LEFT JOIN time_tasks tk ON t.task_id = tk.id
            LEFT JOIN chk_checklists cc ON t.checklist_id = cc.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY t.entry_date DESC, e.full_name ASC, t.created_at ASC LIMIT 1000';
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($rows as $r) $total += (int)$r['minutes'];
    return [$rows, $total];
}

// ── Page: Time Tracking Report (cross-employee) ──────────
function pageTimeReport(): void {
    if (!hasTxn('time_report')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }
    $emp    = trim($_GET['emp'] ?? '');
    $ticket = trim($_GET['ticket'] ?? '');

    // Week filter (Sun–Sat) drives the from/to range.
    $weekStart = weekStartSunday($_GET['week'] ?? date('Y-m-d'));
    $weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $prevWeek  = date('Y-m-d', strtotime($weekStart . ' -7 days'));
    $nextWeek  = date('Y-m-d', strtotime($weekStart . ' +7 days'));
    $thisWeek  = weekStartSunday(date('Y-m-d'));
    $weekLabel = date('d M', strtotime($weekStart)) . ' – ' . date('d M Y', strtotime($weekEnd));
    $from = $weekStart;
    $to   = $weekEnd;

    $hasFilters = isset($_GET['view']);
    $rows = []; $total = 0;
    if ($hasFilters) {
        [$rows, $total] = timeReportRows($emp, $from, $to, $ticket);
    }
    $employees = getEmployeesLite();
    $empName = '';
    foreach ($employees as $e) { if ($e['employee_code'] === $emp) { $empName = $e['full_name']; break; } }

    // Nav links / export preserve the applied employee + ticket filters.
    $navBase   = '?page=time_report&view=1'
        . ($emp !== ''    ? '&emp=' . urlencode($emp) : '')
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '');
    $exportUrl = '?page=export_time_report&from_date=' . urlencode($from) . '&to_date=' . urlencode($to)
        . ($emp !== ''    ? '&emp=' . urlencode($emp) : '')
        . ($ticket !== '' ? '&ticket=' . urlencode($ticket) : '');
?>
<div class="page-header"><h2>Time Tracking Report</h2></div>

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
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
    <input type="hidden" name="page" value="time_report">
    <input type="hidden" name="view" value="1">
    <input type="hidden" name="week" value="<?= h($weekStart) ?>">
    <div class="form-group" style="margin:0">
        <label>Employee</label>
        <span class="input-clear-wrap" style="display:flex;width:240px">
            <input type="hidden" name="emp" id="trEmpId" value="<?= h($emp) ?>">
            <input type="text" id="trEmpSearch" class="form-control" placeholder="All employees — type to search"
                   value="<?= h($empName) ?>" autocomplete="off">
            <button type="button" id="trEmpClear" class="input-clear-btn" data-no-auto aria-label="Clear" tabindex="-1">&times;</button>
            <div id="trEmpList" style="position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1px solid var(--border);border-radius:6px;margin-top:2px;max-height:280px;overflow-y:auto;display:none;z-index:100;box-shadow:0 6px 18px rgba(0,0,0,.35)"></div>
        </span>
    </div>
    <div class="form-group" style="margin:0">
        <label>Ticket</label>
        <input type="text" name="ticket" class="form-control" style="width:140px" value="<?= h($ticket) ?>" placeholder="e.g. WP-11">
    </div>
    <button type="submit" class="btn btn-primary">View</button>
    <?php if ($hasFilters): ?>
    <a href="<?= $exportUrl ?>" class="btn btn-ghost btn-sm" target="_blank">Export CSV</a>
    <?php endif; ?>
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

<?php if (!$hasFilters): ?>
<div class="rpt-prompt">Choose filters and click <strong>View</strong> to load time entries.</div>
<?php elseif (empty($rows)): ?>
<div class="rpt-prompt">No time entries match the selected filters.</div>
<?php else: ?>
<div class="table-count" style="margin:8px 0 10px"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?> · total <?= h(fmtMinutes($total)) ?></div>
<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:100px">Date</th>
                <th style="width:160px">Employee</th>
                <th>Task / Ticket</th>
                <th style="width:90px">Duration</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap"><?= date('d M Y', strtotime($r['entry_date'])) ?></td>
                <td><?= h($r['emp_name'] ?? $r['employee_code']) ?></td>
                <td>
                    <?php if (!empty($r['issue_id'])): ?>
                    <a href="?page=view_issue&id=<?= (int)$r['issue_id'] ?>" target="_blank" style="color:var(--accent)">WP-<?= (int)$r['issue_id'] ?></a>
                    <?php if (!empty($r['issue_summary'])): ?><span class="text-muted"> — <?= h($r['issue_summary']) ?></span><?php endif; ?>
                    <?php else: ?>
                    <?= h(timeEntryLabel($r)) ?>
                    <?php endif; ?>
                </td>
                <td><?= h(fmtMinutes((int)$r['minutes'])) ?></td>
                <td class="text-muted" style="font-size:12px"><?= h($r['notes'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
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
            !empty($r['issue_id']) ? ($r['issue_summary'] ?? '') : timeEntryLabel($r),
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
