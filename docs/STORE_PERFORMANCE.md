# Store Performance — monthly MIS review

Replaces the "Target vs Achievement" pivot workbook. Operations uploads one
CSV a month, each Store Manager writes a remark against every parameter, and
Operations closes the month with a conclusion. The review screen is a pivot —
twelve month columns running April to March, one row per parameter per
financial year — with each month's remarks one click behind the number they
explain.

Lives under **Audit and Performance** in the sidebar, as a single entry —
*Performance Review*. The upload page is reached from the button on it rather than from the
sidebar, the way *Create Audit* is reached from the Audit List: Operations has
one place to start. It is still gated on `txn_perf_admin` server-side, so
being off the sidebar is presentation, not permission.

## Setup, in order

```
migrations/2026-09-02_store_performance.sql          -- tables + the 18 parameters + 2 permissions
migrations/2026-09-02_store_performance_history.sql  -- Apr 2024 – Jul 2026, 22,951 data points
migrations/2026-09-07_perf_audit_score_decimals.sql  -- Audit Score keeps its decimals
migrations/2026-09-07_perf_justification_flags.sql   -- the ask/answer split
migrations/2026-09-07_perf_targets.sql               -- per-outlet goals
```

The three dated 2026-09-07 are only needed on a database migrated before that
day; the first file has since been updated to match, so a fresh install gets
them all either way. Running them anyway is harmless.

The history file stages the workbook's rows and joins them to
`locations.location_name`. Its **step 4** query lists any outlet name with no
matching location — expect zero rows. If it returns names, either add the
outlet under *HRMS → Locations* or fix the spelling in `perf_import_stage`,
then re-run steps 2 and 3. Drop the staging table (step 5) once step 4 is
clean. Both files are safe to re-run.

Then, in *Administration → Roles*, tick the new permissions on whichever roles
need them (see below). Nobody has them until you do.

## Who can do what

| | Upload | See every outlet | Ask | Answer | Conclude | Reopen |
|---|---|---|---|---|---|---|
| Operations Manager — `txn_perf_admin` | yes | yes | yes, via **Justify** | no | yes | no |
| Management / HO — `txn_perf_view` | no | yes | no | no | no | no |
| Store Manager — `employees.location_id` set | no | own outlet only | no | own outlet only | no | no |
| Superadmin | yes | yes | yes | no | yes | yes |

**Answering is the outlet's alone.** Only the manager whose employee record
carries that outlet gets the Save / Submit controls — not Operations, not
superadmin. A justification any onlooker could type would not be a
justification, and a submit gate they could satisfy would gate nothing. There
is deliberately no admin escape hatch: the auditable way to fix a bad entry is
to reopen the month.

**Reopening a concluded month is superadmin only.** Concluding is meant to be
the end of it, so undoing it sits a level above the people doing the
reviewing — Operations concludes, an administrator reverses it.

### Asking for a justification

Operations asks; the store answers. Those are separate fields on the same row,
and separate permissions — Operations cannot answer for the store, because a
submit gate they could satisfy themselves would gate nothing.

**Justify**, on each row of the review list and in the header of the review,
puts Operations into asking mode: tick any parameter that needs explaining and
write what you want explained. Unticking withdraws the request; an answer the
store already gave is kept, a question nobody answered leaves no trace. Justify
is not offered on a concluded month — reopen it first.

The Store Manager then sees, in the review month's column, the question against
each flagged figure and a box to answer it. Answering a flagged parameter is
required; every other parameter stays optional. **Submit is refused while any
requested justification is unanswered** — the message names the parameters, and
everything already typed is saved, so nothing is lost to a missed box. Save
alone never blocks.

Clearing an answer to a flagged parameter reopens the request rather than
deleting it.

### What history shows

Past months show **the store's answer only**. The question that prompted it is
a working document for the month under review and is not re-aired afterwards.
A figure that was questioned stays highlighted, so the record still shows
*that* an explanation was asked for — just not the asking.

The CSV export follows the same rule: the justifications block carries the
answers for every month with a `*` on the ones that were requested, and a
separate block lists what was asked for the review month only. Both grids in
the export carry the screen's shape — `Parameter, Financial year, Apr … Mar`,
a row per parameter per year — so it pastes back into the workbook as a pivot.

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

The grid is a **pivot, not a timeline**. Twelve fixed columns run April →
March, and there is one row per parameter per financial year:

| Parameter | Financial year | Apr | May | Jun | … | Mar |
|---|---|---|---|---|---|---|
| 01 Target | FY 2025-26 | | | | | |
| 01 Target | FY 2026-27 | | | | | |
| 02 Achivement | FY 2025-26 | | | | | |
| 02 Achivement | FY 2026-27 | | | | | |

Reading **across** a row is reading one financial year; reading **down** a
parameter's block is the same month a year apart, which is the comparison
Operations actually makes and which a single run of months never put side by
side. The parameter name spans its years, and the financial year is its own
column — both pin to the left, so a row stays readable as "this parameter, this
year" however far the months are scrolled.

How many years appear is the **History** picker: *This FY*, *2 financial years*,
*3 financial years* or *All years* (data starts Apr 2024). Months after the one
under review stay out even when the rest of their year is on file — the year is
read up to the month being reviewed, not past it — and those slots are left
blank and shaded, because "not reached yet" and "a month we hold with no figure"
are different statements; the `—` is reserved for the second. The month under
review is boxed, and its whole column is tinted so last year's August sits under
this year's.

Each figure carries a ▲/▼ against **the month before it in the calendar**, not
the cell to its left: April's arrow compares against the March in the row above,
so the year boundary does not break the run. That is coloured by whether the
movement is good for *that* parameter — wastage falling is green, wastage rising
is red.

A month that carries a justification shows a small **💬 Remark** button under
the figure; clicking it opens the note over the page, and Escape or a click
elsewhere closes it. It was inline before, which let one long remark set the
height of its whole row and push a twelve-month year off the screen — collapsed,
the grid stays a grid of figures. The button turns amber and reads **Asked**
while a requested justification is still unanswered. *Show remarks* hides the
buttons altogether. The boxes Operations and the Store Manager type into are
unaffected: those stay open in the cell under review.

**Achievement is also coloured against that month's Target**: green once it
matches or beats it, red while it is short. That is separate from the arrow
beside it, so a month can read green and still carry a red ▼ — ahead of
target, down on last month. A month with no target, or a target of zero, is
left uncoloured, because every figure clears zero and saying so would be
noise. The pairing lives in `perfBenchmarks()` in `modules/store_performance.php`;
one line there gives another parameter the same treatment.

The Store Manager gets a justification box per parameter in the review month's
column, with the flagged ones marked and required (see *Asking for a
justification* above). **Save** keeps the month open; **Submit for review**
tells Operations they are done, and is refused while a request is unanswered.
Answers stay editable until the month is concluded. Operations then writes the
conclusion and **Conclude month**, which locks the whole month for everyone —
the justifications *and* the conclusion itself, so a draft saved afterwards
cannot rewrite what was signed off. Reopening is one button for a superadmin,
and keeps the conclusion text.

## Goals

"Wastage under 2%" is not the same instruction at every outlet. Each parameter
carries a goal, and each outlet can override it:

- **Parameters** (`?page=perf_params`) sets the company-wide default.
- **Outlet goals** (`?page=perf_targets`) overrides it for one outlet. A blank
  box inherits the default, so only the outlets that differ need touching.

Type the number the way the grid shows it — `2` for 2%, not `0.02`. Which side
of it is good news comes from the parameter's **Good direction**: *lower is
better* makes the goal a ceiling (wastage at or under it is met), *higher is
better* a floor, *neither* means the figure is reported but never judged, and a
goal set against it is ignored.

In the review grid the goal prints under the parameter name and turns each
month's figure green or red against it. Achievement is the exception: it is
judged against that month's own uploaded Target, which is a moving number, not
a standing goal.

Nothing is seeded — until Operations sets a goal, the grid reads exactly as it
did before, because a goal nobody agreed to is not a standard.

## Adding or changing a parameter

Operations owns the list, on the **Parameters** page: add one, rename it,
change how it reads, set its default goal, reorder it, or retire it.

The **code** is the sort key and the name the CSV matches on, so it is fixed
once a parameter exists — rename freely, recode never.

**Retiring** takes a parameter out of the review grid, the CSV template and the
import. Every value and justification already recorded against it is kept and
comes back untouched if it is made active again. Nothing is ever deleted, and a
retired code cannot be reused for something else — reactivate it instead.

### Editing the table directly

Edit `perf_parameters`. `param_code` is the sort key and the stable identity
the CSV matches on and remarks hang off, so keep it once assigned — renaming
`param_name` is free and orphans nothing. `value_type` picks the display
format (`amount` uses Indian grouping, `percent` shows two decimals and a `%`),
and `better` (`up` / `down` / `none`) decides which way the delta arrow is
good news.

`value_type` options: `amount` (Indian grouping, whole rupees), `percent`
(two decimals, zeros kept, plus `%` — `75.10%`, `100.00%`), `decimal` (two
decimals, trailing zeros trimmed — for a graded figure like Audit Score, where
88.75 is not 88), `number` (a grouped whole count).
