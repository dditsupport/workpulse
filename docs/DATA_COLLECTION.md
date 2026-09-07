# Data Collection — ad-hoc collection drives

Replaces the WhatsApp group where Operations asks every outlet for the same
thing: P&P closing stock, a sales sheet, a photo of a device, "how many boxes
are left in the deep freezer". One person starts a **task**, picks the outlets,
and types the question each of them answers. Every outlet uploads its files,
writes its answer and presses **Submit** — one button, and it can keep
correcting what it sent. Operations then **confirms** a submission, and that is
what locks the outlet out of it. Operations downloads the lot as one ZIP — a
folder per location — and then **discards** the task, which erases every file
from the server.

Lives under **Store Operations** in the sidebar, as a single entry: *Data
Collection*. Both sides of the drive use it — Operations sees every task, a
store user sees only the ones their outlet was asked for, with a count of what
is still outstanding beside the sidebar label.

Any number of tasks run at once and none knows about the others. "Device
photos" across 40 outlets and "Item qty" across 12 are separate tasks, separate
boards, separate folders on disk; discarding one never touches another.

## Setup

```
migrations/2026-09-07_data_collection.sql              -- five tables + the permission
migrations/2026-09-08_data_collection_ops_confirm.sql  -- confirming moves to Operations
migrations/2026-09-09_data_collection_samples.sql      -- the sample / format file
migrations/2026-09-10_data_collection_sub_questions.sql -- sub-questions, one box each
```

All four are safe to re-run. The later three are only needed on a
database that took the first before those dates; the first has since been
updated to match, so a fresh install gets everything from it and the other two
become no-ops. Until they are
applied the page shows a "run the migration" notice rather than an error.

Then, in *Administration → Roles*, tick **Data Collection · Manage** on
whichever roles run these drives. Nobody has it until you do. Submitting needs
no permission at all.

## Who can do what

| | Start / edit / close a task | Submit for an outlet | File on behalf | Confirm / reopen a submission | Download | Discard |
|---|---|---|---|---|---|---|
| `txn_data_collect` (Operations) | ✅ | ✅ any targeted outlet | ✅ | ✅ | ✅ everything | ✅ |
| Store user — outlet on their profile | — | ✅ their outlet | — | — | ✅ their own outlet | — |
| Store / Operation Manager in *Manager Mapping* | — | ✅ every outlet mapped to them | — | — | ✅ those outlets | — |
| Superadmin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

A location has one button, **Submit**, and never confirms anything. Confirming
is Operations' acceptance of what came in, and it is what takes the submission
away from the outlet.

An employee reaches a task through the outlet on their profile
(`employees.location_id`) or through *Store Operations → Manager Mapping*
naming them Store Manager or Operation Manager of one — so an area manager
covering five stores files for all five, picking the outlet from a dropdown.

## The lifecycle

1. **Start** — title, an optional due date, the question, instructions, an
   optional **sample / format file**, and the locations to ask. Every retail outlet starts ticked; HO and the factory do
   not, the same default the app's other cross-location screens use. Untick
   *A file must be attached* to run a question on its own, with the written
   answer as the whole submission.
2. **Submit** — the outlet downloads the sample if there is one, fills it in,
   adds files, writes its answer and presses *Submit*,
   as many times as it likes: submitting again adds files and replaces the
   answer, and it can remove a file it sent by mistake. The board shows it as
   *Submitted*, which is the number to chase — that outlet has done its part.
3. **Confirm** — Operations accepts a submission, from the board row or with
   *Confirm all submitted* once the ZIP is down. Only now does the outlet lose
   the ability to add, remove or change anything, and only now does the board
   count it as *Confirmed*. A confirm is refused for an outlet that sent
   nothing, or that sent no file when the task requires one.
4. **Reopen** (if needed) — Operations hands a confirmed submission back, and
   the outlet can change it and it can be confirmed again. Operations can also
   correct a confirmed submission directly, which leaves it confirmed.
5. **Download** — *Download all (ZIP)*: one folder per location, each file
   under the name the outlet gave it, plus `answers.csv` at the root listing
   every targeted outlet — status (Not submitted / Submitted / Confirmed),
   answer, who filed it, when, file count — including the ones that sent
   nothing. *Download answers (CSV)* gives that
   sheet on its own and needs no ZIP support on the server.

   ```
   P&P closing stock 31-08-2026.zip
   ├── answers.csv
   ├── AHD - Sattadhar/sattadhar 31-08-2026 P&P closing stock.xlsx
   ├── AHD - South Bopal/SOBO Physical P&P closing stock 31082026.xls
   └── AHD - Hansol/Physical P&P closing stock hansol outlet.xlsx
   ```
6. **Close** (optional) — stops every outlet editing at once, without
   confirming them one by one, while leaving everything downloadable.
   Reversible.
7. **Discard** — **irreversible, and it leaves no record.** Every uploaded file
   is erased from disk, the upload folder is removed, and the task, its
   answers and its submission history are deleted. The dialog asks you to type
   `DISCARD`, and warns in red when nobody has downloaded the task yet.
   **Download before you discard** — the download is the only record.

## Questions and sub-questions

A task carries one main **question**, printed above the outlet's answer box, and
any number of **sub-questions** under it, each with its own box. One long
sentence — *"photo of every AC, and the sub-zero meters from a distance, no
meter reading, and where every meter is zero send the old AC photos too"* — is
read once and half-answered; three numbered boxes get three answers. Each
sub-question is also its own column in `answers.csv`, so the report reads as a
table instead of a paragraph to re-read.

Add them on the task form with *+ Add a question*; clearing a line deletes that
question and its answers. A task with no sub-questions behaves exactly as it did
before. Questions are free text — there is no validation of what comes back, by
design: the outlets answer in their own words, including in Hindi or Gujarati.

The **Instructions** box is different: it is the standing note at the top of the
task ("which report to export, what the photo must show"), not something the
outlet answers.

## The sample / format file

A task can carry the blank format it wants back: the sheet with the right
columns and headings, an example photo, a one-page instruction PDF. Attach it
on the task form (several are allowed), and every location sees it in a box
above its own upload area — an image sample is shown inline as a thumbnail
rather than left as a download, because an example photo says in one look what
the words take a paragraph to say. Clicking it opens the full picture in a
preview window (click again to zoom to full resolution, Escape to close), the
same one the *Review Punch Request* screen uses. Photos an outlet sends back
open there too, so reviewing forty AC photos on the board does not mean
downloading forty files — *Download this, fill in your figures and upload it
back below* — so 41 outlets return 41 files with the same shape instead of 41
layouts. Only `txn_data_collect` attaches or removes one; anyone the task was
sent to can download it. Samples are erased with everything else on discard.

## Files

Uploads live in `uploads/data_collection/{task_id}/` under unguessable stored
names and are only ever served through `index.php?page=dc_file&id=N` (or
`page=dc_sample` for a format file), which re-checks who may read them. Sample
files sit in the same folder under a `dcs_` prefix, so discarding the task
takes them too. Accepted: `xlsx xls csv pdf doc docx jpg jpeg png
webp heic heif`, up to 15 MB each, checked by both extension and sniffed mime
type. Ten files per save; save again for more.

## Code

| | |
|---|---|
| `modules/data_collection.php` | the whole feature — gates, handlers, pages, downloads |
| `migrations/2026-09-07_data_collection.sql` | `dc_requests`, `dc_request_locations`, `dc_files`, `dc_submissions`, `dc_samples`, `dc_questions`, `dc_answers`, `roles.txn_data_collect` |
| `index.php` | the `dc_*` POST actions and the three download pages in the pre-HTML early-exit list |
| `modules/nav.php` | the sidebar entry, `allowedPages()`, `dispatchPage()` |
| `modules/dashboard.php` | `pendingForMe_dataCollection()` — open tasks land in *Pending For You* |

`dcSchemaReady()` probes the four core tables once per request, so an
un-migrated database shows a notice instead of a fatal error, and
`dcSamplesReady()` and `dcQuestionsReady()` do the same for `dc_samples` and the
sub-question pair on their own — a database that took only the first migration
keeps working, minus the sample box and the extra questions. `dcZipAvailable()` does the
same for the `ZipArchive` extension: without it the ZIP button is replaced by a
line telling the user to take the files individually, and everything else works.
