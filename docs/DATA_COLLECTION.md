# Data Collection — ad-hoc collection drives

Replaces the WhatsApp group where Operations asks every outlet for the same
thing: P&P closing stock, a sales sheet, a photo of a device, "how many boxes
are left in the deep freezer". One person starts a **task**, picks the outlets,
and types the question each of them answers. Every outlet uploads its files and
writes its answer, then presses **Confirm submission**, which locks what it
sent. Operations downloads the lot as one ZIP — a folder per location — and
then **discards** the task, which erases every file from the server.

Lives under **Store Operations** in the sidebar, as a single entry: *Data
Collection*. Both sides of the drive use it — Operations sees every task, a
store user sees only the ones their outlet was asked for, with a count of what
is still outstanding beside the sidebar label.

Any number of tasks run at once and none knows about the others. "Device
photos" across 40 outlets and "Item qty" across 12 are separate tasks, separate
boards, separate folders on disk; discarding one never touches another.

## Setup

```
migrations/2026-09-07_data_collection.sql   -- four tables + the permission
```

Safe to re-run. Until it is applied the page shows a "run the migration"
notice rather than an error.

Then, in *Administration → Roles*, tick **Data Collection · Manage** on
whichever roles run these drives. Nobody has it until you do. Submitting needs
no permission at all.

## Who can do what

| | Start / edit / close a task | Submit for an outlet | File on behalf | Reopen a confirmed submission | Download | Discard |
|---|---|---|---|---|---|---|
| `txn_data_collect` (Operations) | ✅ | ✅ any targeted outlet | ✅ | ✅ | ✅ everything | ✅ |
| Store user — outlet on their profile | — | ✅ their outlet | — | — | ✅ their own outlet | — |
| Store / Operation Manager in *Manager Mapping* | — | ✅ every outlet mapped to them | — | — | ✅ those outlets | — |
| Superadmin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

An employee reaches a task through the outlet on their profile
(`employees.location_id`) or through *Store Operations → Manager Mapping*
naming them Store Manager or Operation Manager of one — so an area manager
covering five stores files for all five, picking the outlet from a dropdown.

## The lifecycle

1. **Start** — title, an optional due date, the question, instructions, and the
   locations to ask. Every retail outlet starts ticked; HO and the factory do
   not, the same default the app's other cross-location screens use. Untick
   *A file must be attached* to run a question on its own, with the written
   answer as the whole submission.
2. **Draft** — the outlet adds files and writes its answer, as many times as it
   likes. It can remove a file it sent by mistake and rewrite the answer.
   Nothing is locked and the board shows the outlet as *Draft*.
3. **Confirm** — the outlet presses *Confirm submission*. The block goes
   read-only: no more files in or out, no more edits, and only now does the
   board count it as *Confirmed*. A confirm is refused if the task wants a file
   and none is attached.
4. **Reopen** (if needed) — Operations hands a confirmed submission back as a
   draft, and the outlet can fix it and confirm again. Operations can also
   correct a confirmed submission directly, which leaves it confirmed.
5. **Download** — *Download all (ZIP)*: one folder per location, each file
   under the name the outlet gave it, plus `answers.csv` at the root listing
   every targeted outlet — status, answer, who filed it, when, file count —
   including the ones that sent nothing. *Download answers (CSV)* gives that
   sheet on its own and needs no ZIP support on the server.

   ```
   P&P closing stock 31-08-2026.zip
   ├── answers.csv
   ├── AHD - Sattadhar/sattadhar 31-08-2026 P&P closing stock.xlsx
   ├── AHD - South Bopal/SOBO Physical P&P closing stock 31082026.xls
   └── AHD - Hansol/Physical P&P closing stock hansol outlet.xlsx
   ```
6. **Close** (optional) — stops further submissions while leaving everything
   downloadable. Reversible.
7. **Discard** — **irreversible, and it leaves no record.** Every uploaded file
   is erased from disk, the upload folder is removed, and the task, its
   answers and its submission history are deleted. The dialog asks you to type
   `DISCARD`, and warns in red when nobody has downloaded the task yet.
   **Download before you discard** — the download is the only record.

## Files

Uploads live in `uploads/data_collection/{task_id}/` under unguessable stored
names and are only ever served through `index.php?page=dc_file&id=N`, which
re-checks who may read them. Accepted: `xlsx xls csv pdf doc docx jpg jpeg png
webp heic heif`, up to 15 MB each, checked by both extension and sniffed mime
type. Ten files per save; save again for more.

## Code

| | |
|---|---|
| `modules/data_collection.php` | the whole feature — gates, handlers, pages, downloads |
| `migrations/2026-09-07_data_collection.sql` | `dc_requests`, `dc_request_locations`, `dc_files`, `dc_submissions`, `roles.txn_data_collect` |
| `index.php` | the `dc_*` POST actions and the three download pages in the pre-HTML early-exit list |
| `modules/nav.php` | the sidebar entry, `allowedPages()`, `dispatchPage()` |
| `modules/dashboard.php` | `pendingForMe_dataCollection()` — open tasks land in *Pending For You* |

`dcSchemaReady()` probes the four tables once per request, so an un-migrated
database shows a notice instead of a fatal error. `dcZipAvailable()` does the
same for the `ZipArchive` extension: without it the ZIP button is replaced by a
line telling the user to take the files individually, and everything else works.
