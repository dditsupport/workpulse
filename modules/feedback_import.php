<?php
// =========================================================
// Negative Feedback — one-off import of the old complaint tickets
//
// Before the module existed, customer complaints were raised as tickets
// under Service Type › Customer Complaint. This moves them across and
// then deletes them from Tickets, so each complaint lives in one place.
//
// Superadmin only, at index.php?page=feedback_import. The page is a
// preview first: every ticket, what it becomes, which files were found on
// disk. Nothing changes until "Import & delete tickets" is pressed.
//
// Per ticket, in this order:
//   1. copy it into fb_feedback (legacy_ticket_id = its WP- number), its
//      comments into fb_notes and its attachment files into
//      uploads/feedback/{id}/ — one transaction, files copied not moved;
//   2. only once that has committed, delete the ticket with
//      issuesDeleteOne(), exactly as the Delete Tickets page does.
// A ticket that fails at 1 is left in Tickets untouched. One that fails
// at 2 is already imported; the next run sees legacy_ticket_id and only
// retries the delete. So the page can be run again until nothing is left.
//
// Mapping:
//   assigned / waiting / in progress → Open (marked already escalated, so
//                                      the import does not mail a batch
//                                      of overdue alerts at once)
//   resolved → Resolution submitted, by whoever resolved the ticket,
//              waiting for a closer
//   closed   → Closed ('Closed in Tickets'), by whoever closed the ticket,
//              with the resolver's resolution shown as approved
// Source and platform reference are read from the text: "Swiggy" /
// "Zomato", or the order-ID shape (15 digits Swiggy, 10 digits Zomato).
// Anything unclear is Other; nothing is invented.
//
// Schema: migrations/2026-09-25_feedback_ticket_import.sql
// =========================================================

// Filed under Customer Complaint but not customer complaints: an employee
// asking to unlock their own phone number. They stay in Tickets.
const FB_IMPORT_SKIP = [582, 583];

const FB_IMPORT_CATEGORY = ['group' => 'service_type', 'name' => 'Customer Complaint'];

function fbImportCanUse(): bool {
    return isSuperadmin();
}

function fbImportCategoryId(): int {
    try {
        $st = getDb()->prepare('SELECT id FROM issue_categories WHERE category_group = ? AND category_name = ?');
        $st->execute([FB_IMPORT_CATEGORY['group'], FB_IMPORT_CATEGORY['name']]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// The tickets still waiting to be moved, oldest first.
function fbImportTickets(): array {
    $cat = fbImportCategoryId();
    if (!$cat) return [];
    $st = getDb()->prepare(
        'SELECT i.*, l.location_name, e.full_name AS reporter_name
           FROM issues i
           LEFT JOIN locations l ON l.location_id = i.location_id
           LEFT JOIN employees e ON e.employee_code = i.reporter_code
          WHERE i.category_id = ?
          ORDER BY i.id');
    $st->execute([$cat]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC),
        fn($t) => !in_array((int)$t['id'], FB_IMPORT_SKIP, true)));
}

function fbImportPendingCount(): int {
    return fbSchemaReady() && fbImportReady() ? count(fbImportTickets()) : 0;
}

// Read what the ticket's free text says: platform, order ID, customer.
// Returns ['source','source_ref','customer_name','customer_phone','text'].
function fbImportParse(array $t): array {
    $summary = trim(str_replace(["\r\n", "\r"], "\n", (string)$t['summary']));
    $descr   = trim(str_replace(["\r\n", "\r"], "\n", (string)$t['description']));
    $raw     = $summary . "\n" . $descr;

    $ref = null;
    // "order id", "orderid", and the misspellings stores type ("oreder id").
    if (preg_match('/(?:\bo\w{2,5}r\s*id|zomato\s*id)\s*[:\-]?\s*(\d{8,20})\b/i', $raw, $m)) {
        $ref = $m[1];
    } elseif (preg_match('/(?<!\d)(\d{12,20})(?!\d)/', $raw, $m)) {
        $ref = $m[1];
    }

    if (preg_match('/swiggy/i', $raw))          $source = 'swiggy';
    elseif (preg_match('/zomato|zomto/i', $raw)) $source = 'zomato';
    elseif ($ref !== null && strlen($ref) >= 12) $source = 'swiggy';
    elseif ($ref !== null && strlen($ref) === 10) $source = 'zomato';
    else                                         $source = 'other';

    // A number labelled as the contact wins; otherwise the first mobile-
    // shaped number that is not the order ID (a 10-digit Zomato ID looks
    // exactly like a phone number).
    $phone = null;
    if (preg_match('/(?:contact(?:\s*(?:number|no))?|mob(?:ile)?(?:\s*no)?|\bmo|phone|\bph)\s*[:=.\-]?\s*(?:\+?91[\s-]?)?([6-9]\d{9})(?!\d)/i', $raw, $m)) {
        $phone = $m[1];
    } elseif (preg_match_all('/(?<!\d)(?:\+?91[\s-]?)?([6-9]\d{9})(?!\d)/', $raw, $mm)) {
        foreach ($mm[1] as $p) if ($p !== $ref) { $phone = $p; break; }
    }

    // "name : Avani", "CUS: Shruti Patil" — but not "Item Name: Rainbow Cake".
    $name = null;
    if (preg_match('/(?<!item\s)(?<!item)\b(?:name|cus)\s*[:=]\s*([a-z][a-z .]{1,59})/i', $raw, $m)) {
        $name = trim(preg_replace('/\s+/', ' ', $m[1]));
        if ($name === '') $name = null;
    }

    // Summary, then the description unless it only repeats it. Tabs came
    // from sheets pasted into the box; they read better as separators.
    $text = ($descr === '' || $descr === $summary) ? $summary : $summary . "\n\n" . $descr;
    $text = str_replace("\t", ' · ', $text);

    return ['source' => $source, 'source_ref' => $ref, 'customer_name' => $name,
            'customer_phone' => $phone, 'text' => $text];
}

// Who resolved and who closed it, from the status log.
// ['resolved' => [code, at] | null, 'closed' => [code, at] | null]
function fbImportStatusPeople(int $ticketId): array {
    $st = getDb()->prepare("SELECT new_status, changed_by, changed_at FROM issue_status_logs
                             WHERE issue_id = ? AND new_status IN ('resolved','closed') ORDER BY id");
    $st->execute([$ticketId]);
    $out = ['resolved' => null, 'closed' => null];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['new_status']] = [(string)$r['changed_by'], (string)$r['changed_at']];
    }
    return $out;
}

function fbImportNewStatus(string $ticketStatus): string {
    if ($ticketStatus === 'closed')   return 'closed';
    if ($ticketStatus === 'resolved') return 'submitted';
    return 'open';
}

// [ ['row' => attachment row, 'path' => source file or null] ]
function fbImportAttachments(int $ticketId): array {
    $st = getDb()->prepare('SELECT * FROM issue_attachments WHERE issue_id = ? ORDER BY id');
    $st->execute([$ticketId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $cid  = $a['comment_id'] ? (int)$a['comment_id'] : null;
        $path = issueAttachmentDir($ticketId, $cid, false) . basename((string)$a['stored_name']);
        $out[] = ['row' => $a, 'path' => is_file($path) ? $path : null];
    }
    return $out;
}

function fbImportComments(int $ticketId): array {
    $st = getDb()->prepare('SELECT * FROM issue_comments WHERE issue_id = ? ORDER BY created_at, id');
    $st->execute([$ticketId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fbImportExistingId(int $ticketId): int {
    $st = getDb()->prepare('SELECT id FROM fb_feedback WHERE legacy_ticket_id = ?');
    $st->execute([$ticketId]);
    return (int)$st->fetchColumn();
}

// Copy one ticket in. Returns the new fb_feedback id; throws and leaves
// nothing behind (rows rolled back, copied files removed) on failure.
// $missing collects attachment names whose file was not on disk.
function fbImportOne(array $t, array &$missing): int {
    $db   = getDb();
    $tid  = (int)$t['id'];
    $p    = fbImportParse($t);
    $who  = fbImportStatusPeople($tid);
    $new  = fbImportNewStatus((string)$t['status']);
    $now  = date('Y-m-d H:i:s');
    $at   = (string)($t['created_at'] ?: $now);

    // Same platform reference already logged (twice-filed ticket, or a
    // complaint someone also typed into the module): keep this one, drop
    // the reference from it — it is still in the text.
    if ($p['source_ref'] !== null) {
        $dup = $db->prepare('SELECT id FROM fb_feedback WHERE source = ? AND source_ref = ?');
        $dup->execute([$p['source'], $p['source_ref']]);
        if ($dup->fetchColumn()) $p['source_ref'] = null;
    }

    $closedBy = $who['closed'][0] ?? null;
    $closedAt = $who['closed'][1] ?? ($t['resolved_at'] ?: ($t['updated_at'] ?: $at));

    $copied = [];
    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO fb_feedback
                        (source, location_id, customer_name, customer_phone, rating, feedback_text,
                         received_at, source_ref, status, created_by, created_at, open_since,
                         escalated_at, closed_by, closed_at, close_reason, close_note, legacy_ticket_id)
                      VALUES (?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$p['source'], (int)$t['location_id'], $p['customer_name'], $p['customer_phone'],
                      $p['text'], $at, $p['source_ref'], $new, (string)$t['reporter_code'], $at, $at,
                      // An open import is already overdue; count it as escalated
                      // rather than mail every closer about it the moment the
                      // list is opened.
                      $new === 'open' ? $now : null,
                      $new === 'closed' ? $closedBy : null,
                      $new === 'closed' ? $closedAt : null,
                      $new === 'closed' ? 'migrated' : null,
                      $new === 'closed' ? 'Closed in Tickets as WP-' . $tid . '.' : null,
                      $tid]);
        $fbId = (int)$db->lastInsertId();

        // Whoever marked it resolved in Tickets is its resolver here.
        if ($new !== 'open' && ($who['resolved'] || $new === 'submitted')) {
            $by = $who['resolved'][0] ?? (string)$t['reporter_code'];
            $on = $who['resolved'][1] ?? ($t['resolved_at'] ?: $at);
            $db->prepare('INSERT INTO fb_resolutions
                            (feedback_id, remark, submitted_by, submitted_at, decision, decided_by, decided_at)
                          VALUES (?,?,?,?,?,?,?)')
               ->execute([$fbId, 'Marked resolved in Tickets (WP-' . $tid . '). See Ticket History below.',
                          $by, $on,
                          $new === 'closed' ? 'approved' : 'pending',
                          $new === 'closed' ? $closedBy : null,
                          $new === 'closed' ? $closedAt : null]);
        }

        $noteIds = [];
        $insNote = $db->prepare('INSERT INTO fb_notes (feedback_id, author_code, body, created_at) VALUES (?,?,?,?)');
        foreach (fbImportComments($tid) as $c) {
            $insNote->execute([$fbId, (string)$c['author_code'], (string)$c['body'], (string)($c['created_at'] ?: $at)]);
            $noteIds[(int)$c['id']] = (int)$db->lastInsertId();
        }

        $atts = fbImportAttachments($tid);
        if ($atts) {
            $dir = fbFileDir($fbId);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('cannot write ' . $dir);
            $insFile = $db->prepare('INSERT INTO fb_files
                                       (resolution_id, feedback_id, note_id, kind, original_name, stored_name,
                                        mime_type, size_bytes, uploaded_by, uploaded_at)
                                     VALUES (NULL,?,?,\'ticket\',?,?,?,?,?,?)');
            foreach ($atts as $a) {
                $r = $a['row'];
                if ($a['path'] === null) { $missing[] = (string)$r['filename']; continue; }
                $ext    = mb_strtolower(pathinfo((string)$r['filename'], PATHINFO_EXTENSION)) ?: 'bin';
                $stored = uniqid('fbt_', true) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
                if (!copy($a['path'], $dir . $stored)) throw new RuntimeException('could not copy ' . $r['filename']);
                $copied[] = $dir . $stored;
                $insFile->execute([$fbId, $r['comment_id'] ? ($noteIds[(int)$r['comment_id']] ?? null) : null,
                                   mb_substr((string)$r['filename'], 0, 255), $stored, (string)$r['mime_type'],
                                   (int)$r['file_size'], (string)$r['uploaded_by'], (string)($r['created_at'] ?: $at)]);
            }
        }
        $db->commit();
        return $fbId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($copied as $c) @unlink($c);
        throw $e;
    }
}

// ── Handler: import & delete ────────────────────────────
function doFbImportTickets(): void {
    $back = 'index.php?page=feedback_import';
    if (!fbImportCanUse() || !fbSchemaReady() || !fbImportReady() || !function_exists('issuesDeleteOne')) {
        flash('error', 'Only the superadmin can import, and both Negative Feedback migrations must have run.');
        header("Location: {$back}"); exit;
    }
    @set_time_limit(300);
    $report = ['imported' => [], 'deleted' => [], 'failed' => [], 'missing' => []];
    foreach (fbImportTickets() as $t) {
        $tid  = (int)$t['id'];
        $fbId = fbImportExistingId($tid);
        if (!$fbId) {
            $missing = [];
            try {
                $fbId = fbImportOne($t, $missing);
                $report['imported'][] = 'WP-' . $tid . ' → ' . fbRef($fbId);
                if ($missing) $report['missing'][] = 'WP-' . $tid . ': ' . implode(', ', $missing);
            } catch (Throwable $e) {
                $report['failed'][] = 'WP-' . $tid . ' not imported (' . $e->getMessage() . ') — left in Tickets';
                continue;
            }
        }
        try {
            issuesDeleteOne($tid);
            $report['deleted'][] = 'WP-' . $tid;
        } catch (Throwable $e) {
            $report['failed'][] = 'WP-' . $tid . ' imported as ' . fbRef($fbId) . ' but not deleted (' . $e->getMessage() . ') — run again';
        }
    }
    // Every complaint is out: stop new ones being raised as tickets. The
    // category stays (the tickets left in it still point at it), switched off.
    if (!$report['failed'] && ($cat = fbImportCategoryId())) {
        getDb()->prepare('UPDATE issue_categories SET is_active = 0 WHERE id = ?')->execute([$cat]);
        $report['category_off'] = true;
    }
    $_SESSION['fb_import_report'] = $report;
    flash($report['failed'] ? 'error' : 'success',
          count($report['imported']) . ' imported, ' . count($report['deleted']) . ' ticket(s) deleted'
          . ($report['failed'] ? ', ' . count($report['failed']) . ' problem(s) — see below' : '') . '.');
    header("Location: {$back}"); exit;
}

// ── Page: preview ───────────────────────────────────────
function pageFeedbackImport(): void {
    if (!fbImportCanUse()) { echo '<div class="alert alert-error">Superadmin only.</div>'; return; }
    if (!fbSchemaReady()) { echo '<div class="alert alert-error">' . h(fbSchemaNotice()) . '</div>'; return; }
    if (!fbImportReady()) {
        echo '<div class="alert alert-error">Run migrations/2026-09-25_feedback_ticket_import.sql first.</div>';
        return;
    }
    $report = $_SESSION['fb_import_report'] ?? null;
    unset($_SESSION['fb_import_report']);
    $tickets = fbImportTickets();
    $names   = [];
    foreach (getDb()->query('SELECT employee_code, full_name FROM employees')->fetchAll(PDO::FETCH_KEY_PAIR) as $c => $n) $names[(string)$c] = (string)$n;
    $who = fn(?string $c) => $c ? fbWho($names[$c] ?? '', $c) : '—';
    $statusLabel = ['open' => 'Open', 'submitted' => 'Resolution submitted', 'closed' => 'Closed'];
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Import Complaint Tickets</h2>
    <a href="?page=feedback" class="btn btn-sm btn-ghost">← Negative Feedback</a>
</div>

<?php if ($report): ?>
<div class="form-card" style="max-width:none;margin-bottom:14px;font-size:13px">
    <div class="form-section-title" style="margin-top:0">Last run</div>
    <?php foreach (['imported' => 'Imported', 'deleted' => 'Deleted from Tickets', 'missing' => 'Attachments not found on disk (not copied)', 'failed' => 'Problems'] as $k => $label): ?>
        <?php if (!empty($report[$k])): ?>
        <div style="margin-bottom:8px"><strong><?= h($label) ?> (<?= count($report[$k]) ?>):</strong>
            <span class="<?= $k === 'failed' ? '' : 'text-muted' ?>" style="<?= $k === 'failed' ? 'color:var(--red)' : '' ?>"><?= h(implode(' · ', $report[$k])) ?></span></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!empty($report['category_off'])): ?>
    <div class="text-muted">The Customer Complaint ticket category is now inactive — new complaints go through Negative Feedback.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<p class="text-muted" style="font-size:12px;margin:-4px 0 14px">
    Every ticket under Service Type › Customer Complaint is copied into Negative Feedback — text, comments,
    attachments, who resolved and who closed it — and then <strong>deleted from Tickets</strong>.
    WP-<?= implode(', WP-', FB_IMPORT_SKIP) ?> are not customer complaints and stay in Tickets.
    Take a database and uploads/issues backup first: the delete cannot be undone.
</p>

<?php if (!$tickets): ?>
<div class="alert alert-info">Nothing left to import.</div>
<?php return; endif; ?>

<form method="POST" style="margin-bottom:14px"
      onsubmit="return confirm('Import <?= count($tickets) ?> ticket(s) into Negative Feedback and DELETE them from Tickets? This cannot be undone.');">
    <input type="hidden" name="action" value="fb_import_tickets">
    <button type="submit" class="btn btn-danger-solid">Import &amp; delete <?= count($tickets) ?> ticket(s)</button>
</form>

<div class="table-wrap" data-stack>
<table class="table">
    <thead>
        <tr>
            <th style="width:70px">Ticket</th>
            <th>Outlet</th>
            <th>Becomes</th>
            <th>Source · Reference</th>
            <th>Customer</th>
            <th>Resolved / closed by</th>
            <th style="width:90px">Carries</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($tickets as $t):
        $tid  = (int)$t['id'];
        $p    = fbImportParse($t);
        $wh   = fbImportStatusPeople($tid);
        $new  = fbImportNewStatus((string)$t['status']);
        $atts = fbImportAttachments($tid);
        $miss = count(array_filter($atts, fn($a) => $a['path'] === null));
        $done = fbImportExistingId($tid);
    ?>
        <tr>
            <td><strong>WP-<?= $tid ?></strong><div class="text-muted" style="font-size:11px"><?= h(date('d M Y', strtotime((string)$t['created_at']))) ?></div></td>
            <td style="font-size:12px"><?= h($t['location_name']) ?></td>
            <td style="font-size:12px">
                <?= h($statusLabel[$new]) ?>
                <div class="text-muted" style="font-size:11px">was <?= h(str_replace('_', ' ', (string)$t['status'])) ?></div>
                <?php if ($done): ?><span class="badge badge-amber">Already <?= fbRef($done) ?> — delete only</span><?php endif; ?>
            </td>
            <td style="font-size:12px">
                <?= fbSourceBadge($p['source']) ?>
                <div style="margin-top:2px"><?= $p['source_ref'] !== null ? h($p['source_ref']) : '<span class="text-muted">—</span>' ?></div>
                <div class="text-muted" style="font-size:11px"><?= h(mb_strimwidth($p['text'], 0, 90, '…')) ?></div>
            </td>
            <td style="font-size:12px"><?= h((string)$p['customer_name']) ?: '<span class="text-muted">—</span>' ?>
                <?php if ($p['customer_phone']): ?><div class="text-muted" style="font-size:11px"><?= h($p['customer_phone']) ?></div><?php endif; ?></td>
            <td style="font-size:12px">
                <?php if ($new !== 'open'): ?>
                <div>Resolved: <?= h($who($wh['resolved'][0] ?? ($new === 'submitted' ? (string)$t['reporter_code'] : null))) ?></div>
                <?php endif; ?>
                <?php if ($new === 'closed'): ?><div>Closed: <?= h($who($wh['closed'][0] ?? null)) ?></div><?php endif; ?>
                <?php if ($new === 'open'): ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td style="font-size:12px">
                <?= count(fbImportComments($tid)) ?> comment(s)<br>
                <?= count($atts) ?> file(s)<?php if ($miss): ?> <span class="badge badge-red"><?= $miss ?> missing</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="table-count"><?= count($tickets) ?> ticket(s) to import</div>
<?php
}
