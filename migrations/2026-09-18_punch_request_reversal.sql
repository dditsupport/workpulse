-- =========================================================
-- WorkPulse — HR can reverse an approved punch request
-- 2026-09-18
--
-- Approving a punch request writes a row into attendance_logs, and until
-- now that was a one-way door: a request approved in error stayed
-- approved and its manual punch stayed in attendance for good. HR can now
-- reverse an approval on HRMS › Punch Requests (filter: Approved) — the
-- request flips back to rejected and the punch it created is removed from
-- attendance_logs, so reports, the odd-punch list and the ERP export all
-- read as though the approval never happened.
--
-- Knowing WHICH attendance row an approval created is what makes that
-- safe, hence punch_requests.attendance_log_id: written at approval time,
-- cleared on reversal. Rows approved before this migration have no id, so
-- the backfill below matches them on the only thing the INSERT used —
-- employee_code + the exact punch datetime + punch_method 'manual'. A
-- request that still finds no match reverses without deleting anything
-- rather than guessing at a row.
--
-- Additive and safe to run more than once.
-- =========================================================

ALTER TABLE `punch_requests`
  ADD COLUMN IF NOT EXISTS `attendance_log_id` int(11) DEFAULT NULL AFTER `review_note`;

ALTER TABLE `punch_requests`
  ADD KEY IF NOT EXISTS `idx_pr_attendance_log` (`attendance_log_id`);

-- ── Backfill: link already-approved requests to their punch ──
-- The approval INSERT is the whole match key: same employee, same
-- datetime, punch_method 'manual'. ORDER BY id keeps a re-run stable if a
-- duplicate manual punch exists for that exact second.
UPDATE `punch_requests` pr
   SET pr.`attendance_log_id` = (
         SELECT al.`id`
           FROM `attendance_logs` al
          WHERE al.`employee_code` = pr.`employee_code`
            AND al.`punch_method`  = 'manual'
            AND al.`punch_time`    = TIMESTAMP(pr.`punch_date`, pr.`punch_time`)
          ORDER BY al.`id` ASC
          LIMIT 1)
 WHERE pr.`status` = 'approved'
   AND pr.`attendance_log_id` IS NULL;

-- Two approvals of the same employee/datetime would both land on that one
-- punch, and reversing either would delete the other's row. Only the
-- earliest request keeps the claim; the rest fall back to "no linked row"
-- and reverse without touching attendance.
UPDATE `punch_requests` pr
  JOIN (SELECT `attendance_log_id`, MIN(`id`) AS keep_id
          FROM `punch_requests`
         WHERE `attendance_log_id` IS NOT NULL
         GROUP BY `attendance_log_id`
        HAVING COUNT(*) > 1) dup
    ON dup.`attendance_log_id` = pr.`attendance_log_id`
   AND pr.`id` <> dup.`keep_id`
   SET pr.`attendance_log_id` = NULL;

-- A rejected or pending request never created a punch, so it must never
-- carry a claim on one.
UPDATE `punch_requests`
   SET `attendance_log_id` = NULL
 WHERE `status` <> 'approved'
   AND `attendance_log_id` IS NOT NULL;
