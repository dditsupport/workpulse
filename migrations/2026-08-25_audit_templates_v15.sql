-- =========================================================
-- WorkPulse — Audit 2026 templates: v15
-- 2026-08-25
--
-- Run after 2026-08-25_audit_conditions_schema.sql.
--
-- Seeds the v15 "Store Hygiene" and "Audit" checklists as two independent
-- templates in the existing audit_templates / audit_categories /
-- audit_parameters / audit_parameter_options tables — the same tables and
-- the same per-template-id isolation the app already uses to keep any
-- number of templates apart. No new tables, no PHP changes: modules/audit.php
-- already reads/writes every template generically by id.
--
--   'Store Hygiene' / 'Audit' — v15 sheet, is_active = 1
--
-- (An earlier version of this file also seeded a "Store Hygiene (v10)" /
-- "Audit (v10)" pair as an admin-toggleable old-format alternative. That
-- pair, and the "Store Hygine" template that predated this work, were
-- deleted outright at the user's request — see
-- 2026-08-25_audit_delete_unused_templates.sql — so this file no longer
-- creates them.)
--
-- Source sheet: audit_templates_Audit_2026_v15.xlsx, "Audit Checklist"
-- tab. Every question, category, weight, condition, action hint and
-- points value below is copied unchanged from the reference migration
-- 20260825_audit_templates_v15_refresh.sql.
--
-- Weightage: every section is scored out of 100 and carries a weightage
-- of 100, so an audit's total score is the average of its section scores.
-- Inside a section, score_weightage is already proportioned to sum to 100
-- across that section's questions in the source sheet.
--
-- Conditions: a positive condition scores the question's full marks
-- (= max_value) and a negative one scores zero. The auditor picks one
-- (option_mode 'radio' throughout the sheet — it doesn't use 'checkbox').
-- The pick is snapshotted onto audit_responses (option_id/option_text) so
-- a filed audit keeps the exact wording chosen even if the master option
-- list is edited later.
--
-- This is a one-time seed, not idempotent — it always INSERTs two new
-- templates. Re-running this file will create duplicates. Before running,
-- confirm neither name above already exists:
--   SELECT name, is_active FROM audit_templates WHERE name IN ('Store Hygiene','Audit');
-- =========================================================

START TRANSACTION;

-- =========================================================
-- NEW FORMAT (v15) — active by default
-- =========================================================

-- =========================================================
-- TEMPLATE: Store Hygiene (v15)   -- new format, active by default
-- 7 sections, 42 questions
-- =========================================================
INSERT INTO `audit_templates` (`name`, `is_active`) VALUES ('Store Hygiene', 1);
SET @tpl := LAST_INSERT_ID();

-- Customer -- 6 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Customer', 100, 1);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the store music on and playing the right way?', 'value', 'radio', 12.00, 15, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Music is on, with the correct playlist and volume during opening hours', 'No action', 12.00, 1),
  (@prm, 'Music is off because of a fault, and a ticket is already raised', 'Check the fault ticket number', 12.00, 2),
  (@prm, 'Music is off on purpose (no fault)', 'Write why it was turned off', 0.00, 3),
  (@prm, 'Music is on but wrong (wrong volume, wrong playlist, or off during opening hours)', 'Write what was wrong', 0.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'How did the staff behave with customers?', 'value', 'radio', 22.00, 20, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Polite and helpful, no complaints', 'Check that no CRM complaint is open', 22.00, 1),
  (@prm, 'Small slip, fixed on the spot', 'Note the coaching you gave', 22.00, 2),
  (@prm, 'Rude behaviour, or a CRM complaint is open', 'Call the customer, check CCTV, get both sides, and resolve the complaint', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are there any suspicious numbers in the reward points data?', 'value', 'radio', 12.00, 15, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'No suspicious numbers found in the reward points data', 'No action', 12.00, 1),
  (@prm, 'Suspicious numbers found (possible misuse)', 'Note the numbers and report them', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the stock set as per the planogram and are all price tags visible?', 'value', 'radio', 30.00, 25, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Set exactly as per planogram, and every tag is there and easy to read', 'Add a photo of the display', 30.00, 1),
  (@prm, 'Small changes or a few tags missing, fixed during the audit or a ticket raised', 'Write a note or check the ticket number', 30.00, 2),
  (@prm, 'Not set as per planogram, or tags missing/wrong with no ticket', 'Add a photo of the display', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Does the store smell fresh?', 'value', 'radio', 10.00, 10, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Fresh and pleasant smell', 'No action', 10.00, 1),
  (@prm, 'No smell, or a bad smell', 'Write what\'s causing it', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the ambience set up as per SOP?', 'value', 'radio', 14.00, 15, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Ambience is set up as per SOP', 'Add a photo', 14.00, 1),
  (@prm, 'Ambience is not set up as per SOP', 'Add a photo and write what\'s off', 0.00, 2);

-- Sales -- 4 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Sales', 100, 2);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff doing suggestive selling (all offers)?', 'value', 'radio', 40.00, 40, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Staff is pushing all the offers', 'Check CCTV and add remark of timestamp', 40.00, 1),
  (@prm, 'Only some offers are being pushed', 'Note what they missed', 40.00, 2),
  (@prm, 'Not doing it at all', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are Swiggy/Zomato rejections and the visibility score being watched?', 'value', 'radio', 30.00, 30, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Checked, and there are no rejections that could be avoided', 'Add a screenshot of the dashboard', 30.00, 1),
  (@prm, 'Some avoidable rejections, and a ticket is raised for the extra quantity/cancellation', 'Attach the ticket', 30.00, 2),
  (@prm, 'Not being watched, or lots of avoidable rejections with no ticket', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Does the store manager know the targets and sales numbers?', 'value', 'radio', 15.00, 15, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Knows the current targets and sales', 'No action', 15.00, 1),
  (@prm, 'Doesn\'t know the targets or sales', 'Write a note', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff aware of all the active offers?', 'value', 'radio', 15.00, 15, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Staff knows all the active offers', 'No action', 15.00, 1),
  (@prm, 'Staff doesn\'t know all the offers', 'Note which offers they missed', 0.00, 2);

-- Cash -- 1 question, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Cash', 100, 3);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is banking done on time?', 'value', 'radio', 100.00, 100, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Banking done before the given time', 'Attach the deposit slip', 100.00, 1),
  (@prm, 'Banking not done before the given time, and CRM/operations team needs to take daily follow-up', 'Add remarks with the reason for the delay', 0.00, 2),
  (@prm, 'Banking not done before the given time, and no daily follow-up is needed', 'Add remarks with the reason for the delay', 0.00, 3);

-- Equipment -- 11 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Equipment', 100, 4);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the brand signage working and clean?', 'value', 'radio', 7.00, 20, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Lit up, working and clean', 'Add a photo', 7.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 7.00, 2),
  (@prm, 'Not working or dirty, and no ticket raised', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the CCTV working?', 'value', 'radio', 16.00, 20, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and recording is available', 'Check the last recording date', 16.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 16.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the pastry counter working and clean?', 'value', 'radio', 14.00, 15, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and temperature is as per SOP', 'Add a photo of the temperature display', 14.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 14.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the TV working?', 'value', 'radio', 14.00, 5, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and image is as per SOP', 'Add a photo of the screen', 14.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 14.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Write a note', 14.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the printer working?', 'value', 'radio', 8.00, 5, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 8.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 8.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the computer working?', 'value', 'radio', 8.00, 5, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 8.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 8.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the air curtain working?', 'value', 'radio', 7.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 7.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 7.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Note that it\'s not installed', 7.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the puff warmer working and clean?', 'value', 'radio', 7.00, 5, 8, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, clean, and operated as per SOP', 'Add a photo', 7.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 7.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Note that it\'s not installed', 7.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the insect killer working and clean?', 'value', 'radio', 5.00, 5, 9, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 5.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 5.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the microwave working and clean?', 'value', 'radio', 5.00, 5, 10, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and operated as per SOP', 'No action', 5.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 5.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the AC set to 24-26 C?', 'value', 'radio', 9.00, 10, 11, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Set between 24 and 26 C', 'Add a photo of the remote or AC display', 9.00, 1),
  (@prm, 'Set outside the range.', 'Add a photo of the display', 0.00, 2);

-- Inventory -- 3 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Inventory', 100, 5);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Procurement handling as per SOP', 'value', 'radio', 53.00, 55, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Are GI/GR issues sorted within a day? Dispatch/assembly/Trade Store GI/GR still pending.', 'No action', 53.00, 1),
  (@prm, 'Are GI/GR issues sorted within a day? Outlet-to-outlet GI/GR still pending after a day', 'Write a note', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Storage handling as per SOP', 'value', 'radio', 42.00, 40, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Is the cream kept in a covered box? Kept in a paper or plastic box', 'Add a photo', 42.00, 1),
  (@prm, 'Is the cream kept in a covered box? Left open or on a paper plate', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are there alternate items in any category?', 'value', 'radio', 5.00, 5, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, '2 or fewer alternate items in every category', 'No action', 5.00, 1),
  (@prm, 'Sent mail for alternate item to clear alternate item.', 'Share data of alternate', 0.00, 2);

-- People -- 9 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'People', 100, 6);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Did the staff wear gloves while handling products?', 'value', 'radio', 35.00, 35, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Handling products with gloves on', 'Add a photo', 35.00, 1),
  (@prm, 'Handling products without gloves', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff in proper uniform (uniform, belt, grooming)?', 'value', 'radio', 20.00, 20, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Full uniform, crocs, belt and grooming all good', 'Add a photo', 20.00, 1),
  (@prm, 'Not in proper uniform', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff wearing crocs (footwear)?', 'value', 'radio', 6.00, 5, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff\'s hair neat and well-groomed (shave, hairstyle)?', 'value', 'radio', 6.00, 10, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff\'s nails properly trimmed?', 'value', 'radio', 6.00, 5, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff wearing their caps properly?', 'value', 'radio', 6.00, 5, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff free from any foul smell?', 'value', 'radio', 5.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 5.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff well-trained, with no training gaps noticed?', 'value', 'radio', 8.00, 10, 8, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', 'No action', 8.00, 1),
  (@prm, 'No', 'Add remarks', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Does the staff seem well-motivated, with no concerns noticed?', 'value', 'radio', 8.00, 5, 9, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', 'No action', 8.00, 1),
  (@prm, 'No', 'Add remarks', 0.00, 2);

-- Operation -- 8 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Operation', 100, 7);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is garbage cleared regularly?', 'value', 'radio', 6.00, 10, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Garbage cleared, area is clean', 'Add a photo', 6.00, 1),
  (@prm, 'Garbage not cleared, area is dirty', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the customer washroom and wash basin clean and hygienic?', 'value', 'radio', 16.00, 15, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean and hygienic', 'Add a photo', 16.00, 1),
  (@prm, 'Not clean, or not hygienic', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the main glass door, glass wall and outside area clean?', 'value', 'radio', 20.00, 20, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'All of it is clean', 'Add a photo', 20.00, 1),
  (@prm, 'One or two spots clean, the rest not', 'Add a photo and note what\'s pending', 20.00, 2),
  (@prm, 'Nothing cleaned', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the ceiling, platform and floor clean?', 'value', 'radio', 16.00, 15, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'All of it is clean', 'Add a photo', 16.00, 1),
  (@prm, 'Partly clean', 'Add a photo and note what\'s pending', 16.00, 2),
  (@prm, 'Not clean', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the pastry trays clean?', 'value', 'radio', 12.00, 20, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean', 'Add a photo', 12.00, 1),
  (@prm, 'Not clean', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the cash counter clean and tidy?', 'value', 'radio', 14.00, 10, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean and tidy', 'Add a photo', 14.00, 1),
  (@prm, 'Not clean', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are cleaning supplies (liquid cleaner, broom, mop, microfibre cloth) available in sufficient quantity?', 'value', 'radio', 6.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the outlet\'s civil infrastructure in good condition (no repair needed)?', 'value', 'radio', 10.00, 5, 8, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes or ticket raised', 'No action', 10.00, 1),
  (@prm, 'No', 'Add a photo and note what needs to be repair and raise ticket', 0.00, 2);

-- =========================================================
-- TEMPLATE: Audit (v15)   -- new format, active by default
-- 8 sections, 44 questions
-- =========================================================
INSERT INTO `audit_templates` (`name`, `is_active`) VALUES ('Audit', 1);
SET @tpl := LAST_INSERT_ID();

-- Customer -- 4 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Customer', 100, 1);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the store music on and playing the right way?', 'value', 'radio', 18.00, 30, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Music is on, with the correct playlist and volume during opening hours', 'No action', 18.00, 1),
  (@prm, 'Music is off because of a fault, and a ticket is already raised', 'Check the fault ticket number', 18.00, 2),
  (@prm, 'Music is off on purpose (no fault)', 'Write why it was turned off', 0.00, 3),
  (@prm, 'Music is on but wrong (wrong volume, wrong playlist, or off during opening hours)', 'Write what was wrong', 0.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the stock set as per the planogram and are all price tags visible?', 'value', 'radio', 46.00, 35, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Set exactly as per planogram, and every tag is there and easy to read', 'Add a photo of the display', 46.00, 1),
  (@prm, 'Small changes or a few tags missing, fixed during the audit or a ticket raised', 'Write a note or check the ticket number', 46.00, 2),
  (@prm, 'Not set as per planogram, or tags missing/wrong with no ticket', 'Add a photo of the display', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Does the store smell fresh?', 'value', 'radio', 15.00, 15, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Fresh and pleasant smell', 'No action', 15.00, 1),
  (@prm, 'No smell, or a bad smell', 'Write what\'s causing it', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the ambience set up as per SOP?', 'value', 'radio', 21.00, 20, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Ambience is set up as per SOP', 'Add a photo', 21.00, 1),
  (@prm, 'Ambience is not set up as per SOP', 'Add a photo and write what\'s off', 0.00, 2);

-- Sales -- 1 question, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Sales', 100, 2);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff doing suggestive selling (all offers)?', 'value', 'radio', 100.00, 100, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Staff is pushing all the offers', 'Check CCTV and add remark of timestamp', 100.00, 1),
  (@prm, 'Only some offers are being pushed', 'Note what they missed', 100.00, 2),
  (@prm, 'Not doing it at all', 'Write a note', 0.00, 3);

-- Cash -- 4 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Cash', 100, 3);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Do the system cash, counter cash and banking tally?', 'value', 'radio', 39.00, 40, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'System cash, counter cash and banking all match (tallied)', 'Attach the deposit slip', 39.00, 1),
  (@prm, 'A difference exists, but a voucher or pending bill from staff is available', 'Attach the voucher or pending bill', 39.00, 2),
  (@prm, 'Cash is short with nothing to explain it', 'Write the short amount', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the manual bill book maintained properly, with all cash-memo pages present and in order?', 'value', 'radio', 24.00, 25, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'All cash-memo pages are present and in order, and the bill book is up to date', 'No action', 24.00, 1),
  (@prm, 'A cash-memo page is missing, or the bill book isn\'t available', 'Write the missing page or bill numbers', 0.00, 2),
  (@prm, 'The bill book is messy or incomplete', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Was the expense voucher made and sent to HO within 15 days?', 'value', 'radio', 22.00, 20, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Made and sent to HO within 15 days', 'No action', 22.00, 1),
  (@prm, 'Not made, or sent late (after 15 days)', 'Write a note', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the GR receipt/voucher file kept in order?', 'value', 'radio', 15.00, 15, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Receipts and vouchers are filed neatly', 'No action', 15.00, 1),
  (@prm, 'Receipts/vouchers are placed randomly', 'Write a note', 0.00, 2);

-- Equipment -- 12 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Equipment', 100, 4);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the brand signage working and clean?', 'value', 'radio', 6.00, 15, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Lit up, working and clean', 'Add a photo', 6.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 6.00, 2),
  (@prm, 'Not working or dirty, and no ticket raised', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the CCTV working?', 'value', 'radio', 15.00, 15, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and recording is available', 'Check the last recording date', 15.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 15.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the pastry counter working and clean?', 'value', 'radio', 13.00, 15, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and temperature is as per SOP', 'Add a photo of the temperature display', 13.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 13.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the TV working?', 'value', 'radio', 13.00, 5, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and image is as per SOP', 'Add a photo of the screen', 13.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 13.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Write a note', 13.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the printer working?', 'value', 'radio', 8.00, 5, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 8.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 8.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the computer working?', 'value', 'radio', 8.00, 5, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 8.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 8.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the air curtain working?', 'value', 'radio', 6.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 6.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 6.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Note that it\'s not installed', 6.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the puff warmer working and clean?', 'value', 'radio', 6.00, 5, 8, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, clean, and operated as per SOP', 'Add a photo', 6.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 6.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3),
  (@prm, 'Not available at this outlet', 'Note that it\'s not installed', 6.00, 4);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the insect killer working and clean?', 'value', 'radio', 5.00, 5, 9, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working fine', 'No action', 5.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 5.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the microwave working and clean?', 'value', 'radio', 5.00, 5, 10, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Working, and operated as per SOP', 'No action', 5.00, 1),
  (@prm, 'Not working, but a ticket is raised', 'Check the ticket number', 5.00, 2),
  (@prm, 'Not working, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the AC filter cleaned properly?', 'value', 'radio', 7.00, 10, 11, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Filter cleaned (ladder was available)', 'Add a photo', 7.00, 1),
  (@prm, 'Filter not cleaned', 'Write a note', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the AC set to 24-26 C?', 'value', 'radio', 8.00, 10, 12, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Set between 24 and 26 C', 'Add a photo of the remote or AC display', 8.00, 1),
  (@prm, 'Set outside the range.', 'Add a photo of the display', 0.00, 2);

-- Inventory -- 6 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Inventory', 100, 5);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Wastage handling as per SOP', 'value', 'radio', 32.00, 45, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Are there any expired products on the shelf or in the store? - No expired products found | Was the near-expiry panning chocolate sent back 15 days before expiry? - Sent to the factory on time | Was expired trading stock removed within 7 days and sent to the factory? - Sent to the factory within 7 days', 'No action', 32.00, 1),
  (@prm, 'Found expired products | Not sent before the 15-day mark | Not sent within 7 days', 'Add a photo of the expired items', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Procurement handling as per SOP', 'value', 'radio', 11.00, 10, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Are GI/GR issues sorted within a day? Dispatch/assembly/Trade Store GI/GR still pending.', 'No action', 11.00, 1),
  (@prm, 'Are GI/GR issues sorted within a day? Outlet-to-outlet GI/GR still pending after a day', 'Write a note', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Storage handling as per SOP', 'value', 'radio', 8.00, 5, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Is the cream kept in a covered box? Kept in a paper or plastic box', 'Add a photo', 8.00, 1),
  (@prm, 'Is the cream kept in a covered box? Left open or on a paper plate', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Was the finished goods stock count done (anything short)?', 'value', 'radio', 19.00, 15, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Count done, nothing short', 'No action', 19.00, 1),
  (@prm, 'Count not done, or something is short', 'Write the difference', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Was the trading goods stock count done (anything short)?', 'value', 'radio', 19.00, 15, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Count done, nothing short', 'No action', 19.00, 1),
  (@prm, 'Count not done, or something is short', 'Write the difference', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the advance order book kept properly?', 'value', 'radio', 11.00, 10, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Kept properly and up to date', 'No action', 11.00, 1),
  (@prm, 'Not kept properly, or incomplete', 'Write a note', 0.00, 2);

-- People -- 7 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'People', 100, 6);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Did the staff wear gloves while handling products?', 'value', 'radio', 42.00, 35, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Handling products with gloves on', 'Add a photo', 42.00, 1),
  (@prm, 'Handling products without gloves', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff in proper uniform (uniform, belt, grooming)?', 'value', 'radio', 24.00, 30, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Full uniform, crocs, belt and grooming all good', 'Add a photo', 24.00, 1),
  (@prm, 'Not in proper uniform', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff wearing crocs (footwear)?', 'value', 'radio', 7.00, 10, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 7.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff\'s hair neat and well-groomed (shave, hairstyle)?', 'value', 'radio', 7.00, 10, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 7.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff\'s nails properly trimmed?', 'value', 'radio', 7.00, 5, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 7.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the staff wearing their caps properly?', 'value', 'radio', 7.00, 5, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 7.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the staff free from any foul smell?', 'value', 'radio', 6.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

-- Operation -- 7 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Operation', 100, 7);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is garbage cleared regularly?', 'value', 'radio', 7.00, 10, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Garbage cleared, area is clean', 'Add a photo', 7.00, 1),
  (@prm, 'Garbage not cleared, area is dirty', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the customer washroom and wash basin clean and hygienic?', 'value', 'radio', 18.00, 15, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean and hygienic', 'Add a photo', 18.00, 1),
  (@prm, 'Not clean, or not hygienic', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the main glass door, glass wall and outside area clean?', 'value', 'radio', 22.00, 20, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'All of it is clean', 'Add a photo', 22.00, 1),
  (@prm, 'One or two spots clean, the rest not', 'Add a photo and note what\'s pending', 22.00, 2),
  (@prm, 'Nothing cleaned', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the ceiling, platform and floor clean?', 'value', 'radio', 18.00, 20, 4, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'All of it is clean', 'Add a photo', 18.00, 1),
  (@prm, 'Partly clean', 'Add a photo and note what\'s pending', 18.00, 2),
  (@prm, 'Not clean', 'Add a photo', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are the pastry trays clean?', 'value', 'radio', 13.00, 20, 5, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean', 'Add a photo', 13.00, 1),
  (@prm, 'Not clean', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the cash counter clean and tidy?', 'value', 'radio', 16.00, 10, 6, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Clean and tidy', 'Add a photo', 16.00, 1),
  (@prm, 'Not clean', 'Add a photo', 0.00, 2);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Are cleaning supplies (liquid cleaner, broom, mop, microfibre cloth) available in sufficient quantity?', 'value', 'radio', 6.00, 5, 7, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Yes', NULL, 6.00, 1),
  (@prm, 'No', 'Add a photo', 0.00, 2);

-- Compliance -- 3 questions, scored out of 100
INSERT INTO `audit_categories` (`template_id`, `name`, `weightage`, `sort_order`)
VALUES (@tpl, 'Compliance', 100, 8);
SET @cat := LAST_INSERT_ID();

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the weighing machine licence in the outlet?', 'value', 'radio', 30.00, 30, 1, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Valid licence is there', 'Add a photo of the licence', 30.00, 1),
  (@prm, 'Close to expiry, but a renewal ticket is raised', 'Check the ticket number', 30.00, 2),
  (@prm, 'Not there or expired, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the GST certificate in the outlet?', 'value', 'radio', 35.00, 35, 2, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Valid GST certificate is there', 'Add a photo', 35.00, 1),
  (@prm, 'Close to expiry, but a renewal ticket is raised', 'Check the ticket number', 35.00, 2),
  (@prm, 'Not there or expired, and no ticket raised', 'Write a note', 0.00, 3);

INSERT INTO `audit_parameters`
  (`category_id`, `parameter_text`, `type`, `option_mode`, `max_value`, `score_weightage`, `sort_order`, `is_active`)
VALUES (@cat, 'Is the FSSAI licence in the outlet?', 'value', 'radio', 35.00, 35, 3, 1);
SET @prm := LAST_INSERT_ID();
INSERT INTO `audit_parameter_options`
  (`parameter_id`, `option_text`, `action_hint`, `points`, `sort_order`)
VALUES
  (@prm, 'Valid FSSAI licence is there', 'Add a photo', 35.00, 1),
  (@prm, 'Close to expiry, but a renewal ticket is raised', 'Check the ticket number', 35.00, 2),
  (@prm, 'Not there or expired, and no ticket raised', 'Write a note', 0.00, 3);


COMMIT;
