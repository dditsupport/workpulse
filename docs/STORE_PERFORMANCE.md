# Store Performance — monthly MIS review

Replaces the "Target vs Achievement" pivot workbook. Operations uploads one
CSV a month, each Store Manager writes a remark against every parameter, and
Operations closes the month with a conclusion. The review screen shows the
history side by side — months across, parameters down — with each month's
remarks sitting under the number they explain.

Lives under **Audits** in the sidebar, as a single entry — *Performance
Review*. The upload page is reached from the button on it rather than from the
sidebar, the way *Create Audit* is reached from the Audit List: Operations has
one place to start. It is still gated on `txn_perf_admin` server-side, so
being off the sidebar is presentation, not permission.

## Setup, in order

```
migrations/2026-09-02_store_performance.sql          -- tables + the 18 parameters + 2 permissions
migrations/2026-09-02_store_performance_history.sql  -- Apr 2024 – Jul 2026, 22,951 data points
```

The history file stages the workbook's rows and joins them to
`locations.location_name`. Its **step 4** query lists any outlet name with no
matching location — expect zero rows. If it returns names, either add the
outlet under *HRMS → Locations* or fix the spelling in `perf_import_stage`,
then re-run steps 2 and 3. Drop the staging table (step 5) once step 4 is
clean. Both files are safe to re-run.

Then, in *Administration → Roles*, tick the new permissions on whichever roles
need them (see below). Nobody has them until you do.

## Who can do what

| | Upload | See every outlet | Parameter remarks | Conclusion |
|---|---|---|---|---|
| Operations Manager — `txn_perf_admin` | yes | yes | yes, via **Justify** | yes |
| Management / HO — `txn_perf_view` | no | yes | no | no |
| Store Manager — `employees.location_id` set | no | own outlet only | own outlet only | no |
| Superadmin | yes | yes | yes, via **Justify** | yes |

### Remarks on a manager's behalf

Reviews often happen with Operations and the Store Manager at one screen, or
with a manager who has not logged in, so Operations can write the
per-parameter remarks for any outlet. It is a deliberate mode rather than
boxes that are always live: **Justify**, on each row of the review list and in
the header of the review itself, opens them. An ordinary read-through cannot
be typed into by accident.

Whoever types is what gets recorded — `perf_remarks.updated_by`, shown under
each remark in the grid — so an entry made on a manager's behalf never reads
as the manager's own. A banner says whose behalf it is and under whose name it
will be saved. The Store Manager needs none of this on their own outlet: their
boxes are always live.

A Store Manager needs **no permission flag at all**: access is owning an
outlet. The outlet on their employee record is the only one they can open, and
putting another outlet's id in the URL still lands them on their own — the
review screen, the CSV export and the remark form all re-derive the outlet
from the session rather than trusting the request.

## Uploading

Two layouts are accepted, in either column order.

**Long** — the workbook's own `Data` sheet, so an export of it imports as it
stands. One file may carry many months.

```csv
Month,Outlet,Parameter,Value
2026/07,AHD - Haridarshan,01Target,445000
2026/07,AHD - Haridarshan,02Achivement,450523
```

**Wide** — one row per outlet, month taken from the form.

```csv
Outlet,01Target,02Achivement,03Target %,...
AHD - Haridarshan,445000,450523,101.25,...
```

Details that matter in practice:

- Outlet names match `locations` ignoring case, spacing and punctuation.
  Names that don't match are listed back to you; everything else still
  imports.
- Parameters match on the numeric prefix, the full label, or the bare label —
  `01Target`, `Target` and `01` are the same thing. The percent sign is
  significant: `Valid phone` and `Valid phone %` are different parameters.
- `2,20,000`, `₹220000`, `93.5%` and `(500)` all parse. Blank cells are
  skipped rather than stored as zero, and Excel error cells (`#VALUE!`) are
  dropped. Text like `No Audit` is kept as written and shown in the grid.
- **Percentages go in as fractions** — `0.03` for 3% — which is what a
  spreadsheet's own percent formatting exports. See below.
- **Re-uploading a month overwrites that month's numbers and leaves remarks
  and conclusions untouched** — correcting a figure never costs a review.

The upload page offers both layouts as templates, **every active outlet
already listed** so the file is the month's whole grid ready to fill in:

- **wide** — one row per outlet, one column per parameter. Quickest to type a
  month into, and the shape to reach for.
- **long** — one row per number, grouped outlet by outlet, with a trailing
  note column saying how each parameter is written. The importer only reads
  columns it recognises, so that note column can be left in place.

Both import as they stand once values are typed in; outlets left blank are
skipped, so a half-finished file uploads fine and the rest can follow.

### Percentages

Stored the way everyone reads them: **3% is 3**, not 0.03. That is the scale
the historical import loaded and the scale the review grid and the export use.

Uploads name their own scale, on the form:

| Setting | `0.03` means | `3` means |
|---|---|---|
| **Fractions** (default) | 3% | 300% |
| Whole numbers | 0.03% | 3% |

Leave it on *Fractions* — that is what Operations exports. Pick *Whole
numbers* only for a file that already carries `3` for 3%, such as an export
of the old Target vs Achievement sheet.

Either way, a cell that carries its own `%` sign (`3%`) is taken as written
and never multiplied, so the CSV export — which writes percentages with the
sign — re-imports unchanged. Only the eight `%` parameters are affected;
counts and amounts are never rescaled. The import result tells you how many
cells were multiplied, so a wrong choice shows up immediately.

## The review

Columns run oldest to newest, ending on the month under review; pick 6, 12, 24
or 36 months of history. Each figure carries a ▲/▼ against the month before
it, coloured by whether that movement is good for *that* parameter — wastage
falling is green, wastage rising is red.

**Achievement is also coloured against that month's Target**: green once it
matches or beats it, red while it is short. That is separate from the arrow
beside it, so a month can read green and still carry a red ▼ — ahead of
target, down on last month. A month with no target, or a target of zero, is
left uncoloured, because every figure clears zero and saying so would be
noise. The pairing lives in `perfBenchmarks()` in `modules/store_performance.php`;
one line there gives another parameter the same treatment.

The Store Manager gets a remark box per parameter in the review month's
column. **Save** keeps the month open; **Submit for review** tells Operations
the remarks are done, and they stay editable until the month is concluded.
Operations then writes the conclusion and **Conclude month**, which locks the
remarks. Reopening is one button, and keeps the conclusion text.

## Adding or changing a parameter

Edit `perf_parameters`. `param_code` is the sort key and the stable identity
the CSV matches on and remarks hang off, so keep it once assigned — renaming
`param_name` is free and orphans nothing. `value_type` picks the display
format (`amount` uses Indian grouping, `percent` adds one decimal and a `%`),
and `better` (`up` / `down` / `none`) decides which way the delta arrow is
good news.
