<?php
// =========================================================
// Ticket Scheduler — auto-create tickets (issues) ahead of an
// event such as a service/AMC renewal.
//
// A schedule carries the ticket details + chosen participant
// departments, an event_date, and lead_days ("create the ticket
// N days before the event"). When due it spawns a ticket reusing
// the same shape as doCreateIssue() (insert issue, attach
// participants, status log, notifyIssue). Recurring schedules
// advance their event_date after firing; one-time ones deactivate.
//
// Fired by cron/run_ticket_schedules.php and a once-per-day lazy
// fallback in index.php. Gated by txn_ticket_scheduler.
// =========================================================

function ticketSchedCanManage(): bool {
    return isSuperadmin() || hasTxn('ticket_scheduler');
}

// Advance a date by one recurrence step. 'once'/unknown → unchanged.
function advanceTicketEventDate(string $date, string $recurrence, int $interval): string {
    $interval = max(1, $interval);
    $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$recurrence] ?? null;
    if ($unit === null) return $date;
    return date('Y-m-d', strtotime("+{$interval} {$unit}", strtotime($date)));
}

// ── Data access ──────────────────────────────────────────
function getTicketSchedules(): array {
    try {
        return getDb()->query(
            "SELECT s.*, l.location_name, c.category_name,
                    (SELECT COUNT(*) FROM ticket_schedule_depts d WHERE d.schedule_id = s.id) AS dept_count
             FROM ticket_schedules s
             LEFT JOIN locations l ON s.location_id = l.location_id
             LEFT JOIN issue_categories c ON s.category_id = c.id
             ORDER BY s.is_active DESC, s.event_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function getTicketSchedule(int $id): ?array {
    $st = getDb()->prepare("SELECT * FROM ticket_schedules WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $dst = getDb()->prepare("SELECT department_id FROM ticket_schedule_depts WHERE schedule_id = ?");
    $dst->execute([$id]);
    $row['dept_ids'] = array_map('intval', $dst->fetchAll(PDO::FETCH_COLUMN));
    return $row;
}

// ── Ticket creation (session-free: safe from cron) ───────
function createScheduledIssue(array $sched, array $deptIds): int {
    $db = getDb();
    $db->prepare(
        "INSERT INTO issues (summary, description, priority, category_id, location_id, reporter_code)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $sched['summary'],
        ($sched['description'] !== '' ? $sched['description'] : null),
        $sched['priority'],
        !empty($sched['category_id']) ? (int)$sched['category_id'] : null,
        (int)$sched['location_id'],
        $sched['created_by'],
    ]);
    $issueId = (int)$db->lastInsertId();

    if ($deptIds) {
        $pst = $db->prepare("INSERT IGNORE INTO issue_participants (issue_id, department_id) VALUES (?, ?)");
        foreach ($deptIds as $did) $pst->execute([$issueId, (int)$did]);
    }
    $db->prepare("INSERT INTO issue_status_logs (issue_id, old_status, new_status, changed_by) VALUES (?, '', 'assigned_to_concerned', ?)")
       ->execute([$issueId, $sched['created_by']]);

    if (function_exists('notifyIssue')) notifyIssue($issueId, 'created');
    return $issueId;
}

// ── Engine: create tickets for every due schedule ────────
// Due = active AND (event_date - lead_days) <= today. Each runs in its
// own try/catch so one bad schedule can't block the rest. Returns count.
function processDueTicketSchedules(): int {
    $db = getDb();
    try {
        $rows = $db->query(
            "SELECT * FROM ticket_schedules
             WHERE is_active = 1 AND DATE_SUB(event_date, INTERVAL lead_days DAY) <= CURDATE()
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return 0;
    }
    if (!$rows) return 0;

    $today   = date('Y-m-d');
    $created = 0;
    $dst = $db->prepare("SELECT department_id FROM ticket_schedule_depts WHERE schedule_id = ?");
    foreach ($rows as $s) {
        try {
            $dst->execute([(int)$s['id']]);
            $deptIds = array_map('intval', $dst->fetchAll(PDO::FETCH_COLUMN));
            $issueId = createScheduledIssue($s, $deptIds);
            $created++;

            if ($s['recurrence'] === 'once') {
                $db->prepare("UPDATE ticket_schedules SET is_active = 0, last_created_at = NOW(), last_issue_id = ? WHERE id = ?")
                   ->execute([$issueId, (int)$s['id']]);
            } else {
                // Roll the event date forward until the next ticket would be
                // created in the future — one ticket per run, never a backlog.
                $next  = (string)$s['event_date'];
                $lead  = (int)$s['lead_days'];
                $guard = 0;
                do {
                    $next = advanceTicketEventDate($next, $s['recurrence'], (int)$s['recur_interval']);
                    $createOn = date('Y-m-d', strtotime("-{$lead} day", strtotime($next)));
                    $guard++;
                } while ($createOn <= $today && $guard < 1000);
                $db->prepare("UPDATE ticket_schedules SET event_date = ?, last_created_at = NOW(), last_issue_id = ? WHERE id = ?")
                   ->execute([$next, $issueId, (int)$s['id']]);
            }
        } catch (Exception $e) {
            error_log('[ticket_scheduler] schedule ' . ($s['id'] ?? '?') . ' failed: ' . $e->getMessage());
        }
    }
    return $created;
}

// ── Lazy fallback: run due schedules at most once per calendar day ──
// Called from index.php on authenticated requests. Uses a file marker
// (uploads/ is already writable for email_debug.log) so it doesn't
// depend on a seeded system_settings row. Claims the day *before*
// processing so two same-day requests can't both fire tickets.
function ticketSchedLazyRun(): void {
    $marker = __DIR__ . '/../uploads/ticket_sched_lastrun.txt';
    $today  = date('Y-m-d');
    $last   = @file_get_contents($marker);
    if ($last !== false && trim($last) === $today) return;
    if (@file_put_contents($marker, $today, LOCK_EX) === false) return; // can't claim → skip quietly
    try {
        processDueTicketSchedules();
    } catch (Exception $e) {
        error_log('[ticket_scheduler] lazy run failed: ' . $e->getMessage());
    }
}

// ── POST: save (insert/update) a schedule ────────────────
function doSaveTicketSchedule(): void {
    if (!ticketSchedCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $db = getDb();
    $id          = (int)($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $summary     = trim($_POST['summary'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority    = in_array($_POST['priority'] ?? '', ['low','medium','high','critical'], true) ? $_POST['priority'] : 'medium';
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $locationId  = (int)($_POST['location_id'] ?? 0);
    $eventDate   = trim($_POST['event_date'] ?? '');
    $leadDays    = max(0, (int)($_POST['lead_days'] ?? 0));
    $recurrence  = in_array($_POST['recurrence'] ?? '', ['once','daily','weekly','monthly','yearly'], true) ? $_POST['recurrence'] : 'once';
    $interval    = max(1, (int)($_POST['recur_interval'] ?? 1));
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $deptIds     = array_values(array_unique(array_map('intval', (array)($_POST['dept_ids'] ?? []))));

    $back = 'index.php?page=ticket_schedules';
    if ($title === '') $title = $summary;
    if ($summary === '' || $locationId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        flash('error', 'Summary, location and a valid event date are required.');
        header('Location: ' . ($id ? "index.php?page=ticket_schedule_edit&id={$id}" : 'index.php?page=ticket_schedule_new')); exit;
    }

    try {
        if ($id) {
            $own = $db->prepare("SELECT id FROM ticket_schedules WHERE id = ?");
            $own->execute([$id]);
            if (!$own->fetch()) { flash('error', 'Schedule not found.'); header("Location: $back"); exit; }
            $db->prepare(
                "UPDATE ticket_schedules SET title=?, summary=?, description=?, priority=?, category_id=?,
                        location_id=?, event_date=?, lead_days=?, recurrence=?, recur_interval=?, is_active=?
                 WHERE id=?"
            )->execute([$title, $summary, ($description !== '' ? $description : null), $priority, ($categoryId ?: null),
                        $locationId, $eventDate, $leadDays, $recurrence, $interval, $isActive, $id]);
        } else {
            $db->prepare(
                "INSERT INTO ticket_schedules (title, summary, description, priority, category_id, location_id,
                        event_date, lead_days, recurrence, recur_interval, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$title, $summary, ($description !== '' ? $description : null), $priority, ($categoryId ?: null),
                        $locationId, $eventDate, $leadDays, $recurrence, $interval, $isActive, myCode()]);
            $id = (int)$db->lastInsertId();
        }
        // Replace participant departments.
        $db->prepare("DELETE FROM ticket_schedule_depts WHERE schedule_id = ?")->execute([$id]);
        if ($deptIds) {
            $pst = $db->prepare("INSERT IGNORE INTO ticket_schedule_depts (schedule_id, department_id) VALUES (?, ?)");
            foreach ($deptIds as $did) { if ($did > 0) $pst->execute([$id, $did]); }
        }
        flash('success', 'Schedule saved.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header("Location: $back"); exit;
}

// ── POST: toggle active ──────────────────────────────────
function doDeleteTicketSchedule(): void {
    if (!ticketSchedCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $id = (int)($_POST['id'] ?? 0);
    try {
        getDb()->prepare("UPDATE ticket_schedules SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        flash('success', 'Schedule updated.');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    header('Location: index.php?page=ticket_schedules'); exit;
}

// ── POST: run due schedules now (manual) ─────────────────
function doRunTicketSchedulesNow(): void {
    if (!ticketSchedCanManage()) { flash('error', 'Access denied.'); header('Location: index.php'); exit; }
    $n = processDueTicketSchedules();
    flash('success', $n > 0 ? "Created {$n} ticket(s) from due schedules." : 'No schedules are due right now.');
    header('Location: index.php?page=ticket_schedules'); exit;
}

// ── Page: schedule list ──────────────────────────────────
function pageTicketSchedules(): void {
    if (!ticketSchedCanManage()) { echo '<p>Access denied.</p>'; return; }
    $schedules = getTicketSchedules();
    $recurLabels = ['once' => 'One-time', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];
?>
<div class="page-header">
    <h2>⏰ Ticket Scheduler</h2>
    <div style="display:flex;gap:8px">
        <form method="POST" class="inline-form" style="display:inline">
            <input type="hidden" name="action" value="run_ticket_schedules">
            <button type="submit" class="btn btn-ghost">Run due now</button>
        </form>
        <a href="?page=ticket_schedule_new" class="btn btn-primary">+ New Schedule</a>
    </div>
</div>

<p class="text-muted" style="font-size:12px;margin-bottom:12px">Schedules raise a ticket <strong>lead-days before</strong> the event date. Recurring schedules roll forward automatically after firing.</p>

<?php if (empty($schedules)): ?>
<div class="rpt-prompt">No schedules yet. Click <strong>+ New Schedule</strong> to create one.</div>
<?php else: ?>
<div class="table-wrap" data-stack>
    <table class="table" style="font-size:13px">
        <thead><tr>
            <th>Title / Summary</th>
            <th style="width:140px">Location</th>
            <th style="width:110px">Event date</th>
            <th style="width:110px">Creates on</th>
            <th style="width:100px">Recurrence</th>
            <th style="width:70px">Depts</th>
            <th style="width:90px">Status</th>
            <th style="width:150px">Last run</th>
            <th style="width:170px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($schedules as $s):
            $createOn = date('Y-m-d', strtotime("-" . (int)$s['lead_days'] . " day", strtotime($s['event_date'])));
        ?>
            <tr class="<?= $s['is_active'] ? '' : 'row-inactive' ?>">
                <td>
                    <strong><?= h($s['title']) ?></strong>
                    <div class="text-muted" style="font-size:12px"><?= h($s['summary']) ?></div>
                </td>
                <td><?= h($s['location_name'] ?? '—') ?></td>
                <td style="white-space:nowrap"><?= date('d M Y', strtotime($s['event_date'])) ?></td>
                <td style="white-space:nowrap"><?= date('d M Y', strtotime($createOn)) ?><br><span class="text-muted" style="font-size:11px"><?= (int)$s['lead_days'] ?>d before</span></td>
                <td><?= h($recurLabels[$s['recurrence']] ?? $s['recurrence']) ?><?= $s['recurrence'] !== 'once' && (int)$s['recur_interval'] > 1 ? ' ×' . (int)$s['recur_interval'] : '' ?></td>
                <td><?= (int)$s['dept_count'] ?></td>
                <td><?= $s['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Paused</span>' ?></td>
                <td class="text-muted" style="font-size:12px">
                    <?php if (!empty($s['last_created_at'])): ?>
                        <?= date('d M Y H:i', strtotime($s['last_created_at'])) ?>
                        <?php if (!empty($s['last_issue_id'])): ?><br><a href="?page=view_issue&id=<?= (int)$s['last_issue_id'] ?>" target="_blank" style="color:var(--accent)">WP-<?= (int)$s['last_issue_id'] ?></a><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <a href="?page=ticket_schedule_edit&id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                    <form method="POST" class="inline-form" style="display:inline">
                        <input type="hidden" name="action" value="delete_ticket_schedule">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'badge-grey' : 'badge-green' ?>" style="cursor:pointer"><?= $s['is_active'] ? 'Pause' : 'Resume' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php }

// ── Page: create / edit form ─────────────────────────────
function pageTicketScheduleForm(?array $sched): void {
    if (!ticketSchedCanManage()) { echo '<p>Access denied.</p>'; return; }
    $isEdit     = $sched !== null;
    $categories = function_exists('getIssueCategories') ? getIssueCategories() : [];
    $locations  = getActiveLocations();
    $departments = getDepartments();
    $deptIds    = $sched['dept_ids'] ?? [];

    $v = fn($k, $d = '') => h((string)($sched[$k] ?? $d));
?>
<div class="page-header">
    <h2><?= $isEdit ? 'Edit Schedule' : 'New Schedule' ?></h2>
    <a href="?page=ticket_schedules" class="btn btn-ghost btn-sm">Back to list</a>
</div>

<div class="form-card" style="max-width:none">
    <form method="POST">
        <input type="hidden" name="action" value="save_ticket_schedule">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$sched['id'] ?>"><?php endif; ?>

        <div class="form-grid" style="gap:14px">
            <div class="form-group" style="grid-column:1/-1">
                <label>Schedule title <span class="text-muted">(internal label)</span></label>
                <input type="text" name="title" class="form-control" value="<?= $v('title') ?>" maxlength="150" placeholder="e.g. AMC renewal — AC units">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Ticket summary <span class="required">*</span></label>
                <input type="text" name="summary" class="form-control" value="<?= $v('summary') ?>" maxlength="300" required placeholder="Summary that will appear on the ticket">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Ticket description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Details for the ticket"><?= $v('description') ?></textarea>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select name="priority" class="form-control">
                    <?php foreach (['low','medium','high','critical'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($sched['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category_id" class="form-control">
                    <option value="0">— None —</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)($sched['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Location <span class="required">*</span></label>
                <select name="location_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($locations as $l): ?>
                    <option value="<?= (int)$l['location_id'] ?>" <?= (int)($sched['location_id'] ?? 0) === (int)$l['location_id'] ? 'selected' : '' ?>><?= h($l['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Event date <span class="required">*</span></label>
                <input type="date" name="event_date" class="form-control" value="<?= $v('event_date', date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group">
                <label>Create ticket — days before</label>
                <input type="number" name="lead_days" class="form-control" value="<?= (int)($sched['lead_days'] ?? 7) ?>" min="0" max="365">
            </div>
            <div class="form-group">
                <label>Recurrence</label>
                <select name="recurrence" class="form-control">
                    <?php foreach (['once'=>'One-time','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($sched['recurrence'] ?? 'once') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Every N (interval)</label>
                <input type="number" name="recur_interval" class="form-control" value="<?= (int)($sched['recur_interval'] ?? 1) ?>" min="1" max="99">
            </div>
        </div>

        <div class="form-group" style="margin-top:12px">
            <label>Participant departments <span class="text-muted">(added to each created ticket)</span></label>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 14px;border:1px solid var(--border);border-radius:6px;padding:10px;max-height:220px;overflow:auto">
                <?php foreach ($departments as $d): ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;cursor:pointer">
                    <input type="checkbox" name="dept_ids[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $deptIds, true) ? 'checked' : '' ?>>
                    <span><?= h($d['department_name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" style="margin-top:10px">
            <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer">
                <input type="checkbox" name="is_active" value="1" <?= ($sched['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Active (eligible to fire)</span>
            </label>
        </div>

        <div style="margin-top:14px;display:flex;gap:8px">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create schedule' ?></button>
            <a href="?page=ticket_schedules" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php }
