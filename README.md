# BlotterCast — XAMPP + MySQL + Python Edition

This is the full rebuild of the prototype on a real stack: **Apache/PHP** for the
web app and REST API, **MySQL** as the single data store, and a **Python
(scikit-learn) microservice** for the machine-learning pipeline described in
Chapters 1–3. Nothing runs in browser JavaScript anymore — the frontend is a
thin client that calls real server endpoints.

## Architecture

```
Browser (HTML/CSS/JS pages)
   │
   ├─→  Apache + PHP  (this folder, served by XAMPP)         → MySQL "blottercast" DB
   │      api/auth.php        session login/logout, account status check
   │      api/records.php     CRUD: incidents, blotter, settlements
   │      api/documents.php   CRUD: census, barangay clearance, indigency certs
   │      api/users.php       user accounts (CRUD, suspend/activate) + audit log
   │      api/settings.php    system settings (key-value) + full SQL schema+data backups
   │      api/analytics.php   dashboard / heatmap / trends aggregates
   │      api/reports.php     generates real PDF/CSV reports (TCPDF)
   │      api/seed.php        one-time setup: schema data + demo dataset + accounts
   │
   └─→  Python ML service (ml/service.py, Flask, port 5000)   → same MySQL DB
          /train    trains Logistic Regression, Decision Tree, Random Forest,
                     Gradient Boosting (scikit-learn) + Naive Bayes type
                     classifier on live incidents, caches result in ml_runs
          /latest   returns the last cached training run
```

Every page in the sidebar is backed by a real table and a real endpoint —
none of the CRUD pages use mock in-memory arrays or localStorage. The only
static content left is `landing.html` (a marketing splash page with no
data of its own) and the read-only Role Permission Matrix on the Users
page (reference documentation, not a data table).

## Setup (Windows/macOS/Linux with XAMPP)

1. **Copy this folder** into your XAMPP web root, e.g. `C:\xampp\htdocs\blottercast`
   (or `/Applications/XAMPP/htdocs/blottercast` on macOS).

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Create the database.** Open phpMyAdmin (`http://localhost/phpmyadmin`) and
   import `schema.sql`, or from a terminal:
   ```
   mysql -u root < schema.sql
   ```
   This creates the `blottercast` database, all tables, the barangay zone
   reference data, and a `blottercast` MySQL user (password `blottercast`)
   that the Python service uses to connect.

   > If your XAMPP MySQL root user has a password, edit `api/config.php`
   > (`DB_PASS`) before continuing.

   > TCPDF (used for report PDFs) needs the PHP `curl`, `gd`, and `mbstring`
   > extensions. Stock XAMPP ships with all three enabled by default, so
   > this normally needs no action — if reports fail to generate, check
   > `php.ini` for `extension=curl`, `extension=gd`, `extension=mbstring`.

4. **Seed the demo dataset.** Visit:
   ```
   http://localhost/blottercast/api/seed.php
   ```
   This generates ~18 months of synthetic blotter/incident/settlement records
   with realistic spatio-temporal structure (weekend & payday surges,
   category-specific hours, per-zone hotspots — the patterns the ML models
   learn from), plus five demo accounts covering every role:

   | Username   | Password     | Role              |
   |------------|--------------|-------------------|
   | `admin`    | `admin123`   | System Admin      |
   | `kapitan`  | `kapitan123` | Barangay Captain  |
   | `jdelacuz` | `officer123` | Desk Officer      |
   | `msantos`  | `officer123` | Desk Officer      |
   | `pencoder` | `encoder123` | Data Encoder      |

   The Users & Roles page (`users.html`) manages these accounts for real —
   create, edit, suspend/activate, and delete all hit `api/users.php` and
   the MySQL `users` table. Suspended or Inactive accounts are blocked at
   login. Every login, logout, and account change is written to the
   `audit_logs` table and shown on both the Users page and the login demo
   pills.

5. **Install the Python ML dependencies (one-time setup).**
   ```
   cd blottercast/ml
   pip install -r requirements.txt
   ```
   You don't need to manually run `python service.py` after this, and you
   don't need to keep a terminal open — the Predictions page starts the
   Flask service automatically in the background the first time it's
   needed (via `api/ml_proxy.php`, using PHP's `proc_open()`), and leaves
   it running for the rest of the session. The very first request may take
   a few seconds while it loads; everything after that is instant. If it
   ever can't start (Python missing, a package not installed), the page
   shows a clear error instead of a silent failure — check
   `ml/service.log` for details, and confirm `pip install -r
   ml/requirements.txt` completed without errors.
   If you changed the MySQL password, set environment variables before
   it starts: `BC_DB_USER`, `BC_DB_PASS`, `BC_DB_HOST`, `BC_DB_NAME`.

6. **Open the app:**
   ```
   http://localhost/blottercast/login.html
   ```
   Log in with `admin` / `admin123`.

## What each prediction task maps to (thesis SOPs)

- **Feature engineering (SOP 1):** `ml/service.py:build_panel()` turns raw
  incidents into one row per (zone, day) with zone identity, day-of-week,
  weekend/payday flags, month seasonality, 1- and 7-day lag counts, 7/30-day
  rolling averages, days-since-last-incident (recurrence), and barangay-wide
  prior-day activity — computed with pandas directly from MySQL.
- **Binary occurrence prediction (SOP 2a):** four real scikit-learn
  classifiers (`LogisticRegression`, `DecisionTreeClassifier`,
  `RandomForestClassifier`, `GradientBoostingClassifier`), trained on a
  chronological 80/20 split so the test set is always the most recent
  period — no data leakage from the future.
- **Multi-class incident-type prediction (SOP 2b):** `MultinomialNB` over
  zone + day-of-week + time-of-day, evaluated on held-out incidents.
- **Hotspot / spatial risk (SOP 2c):** `forecast_hotspots()` rolls each
  zone's features forward day-by-day using the active model's own
  predictions as pseudo-observations, producing a 7-day mean daily
  probability and expected incident count per zone.
- **Evaluation (SOP 3):** accuracy, precision, recall, F1, and ROC-AUC are
  computed with `sklearn.metrics` on the held-out set — not hard-coded.
  Expect roughly 65–75% accuracy and AUC ≈ 0.65–0.75, consistent with the
  data-sparsity limitations discussed in Chapter 1 for barangay-scale data.

Every metric shown in the UI is read straight from a live `/train` response
or the `ml_runs` cache table — nothing is a placeholder.

## Reports

The Reports page produces real files, written by `api/reports.php` using
**TCPDF** (bundled in `vendor/tcpdf/`, classic single-file v6.11.3 — no
Composer needed, just `require`). Incident Summary, Settlement Compliance,
and Trend Analysis render a branded PDF with a letterhead, section
headings, and styled data tables (colored header row, alternating row
shading, auto page breaks); Predictive Risk Assessment and Patrol
Deployment pull the latest cached ML run. Any report can also be exported
as an Excel-compatible CSV. Generated files are written to
`generated_reports/` and logged in the `generated_reports` MySQL table, so
the "Recent Generated Reports" list on that page is real history, and the
Download buttons stream the actual file.

## Resident search picker

Clearance, Certificate of Residency, and Certificate of Indigency all
used a plain `<select>` dropdown of every census resident to choose who
a document is for — fine with a handful of residents, unwieldy once
there are hundreds. Replaced with a type-to-search input
(`bcInitResidentPicker()` in `app.js`, shared by all three pages rather
than three separate implementations): typing filters the resident list
live, and each suggestion shows name, age, address, and household
number — not just a name — so it's possible to tell two same-named
residents apart before picking. Selecting a suggestion still populates
the same hidden field the rest of the form already reads, so issuance,
the blotter-record warning, and the census "Issue Document" prefill
flow all keep working exactly as before — only the picking UI changed.

## Settlement Monitor blotter-case picker

The "Add Settlement" form's blotter-case dropdown is now a search input
too, using the same type-to-filter interaction as the resident picker
above but shaped around blotter cases instead of residents — each
suggestion shows the docket number, case title (complainant vs.
respondent), and date filed, rather than a single dropdown line. This
is a separate small implementation in `settlement.html`
(`_filterBlotterCases()`/`chooseBlotterCase()`) rather than a reuse of
`bcInitResidentPicker()`, since the two pickers show fundamentally
different fields — one is about people, the other about case records —
though the overall pattern (type to filter, click a suggestion, hidden
field holds the real id) is the same one used everywhere else in this
app. Only cases without an existing settlement are offered, same as
before.

## Census residency requirement for blotter records

A blotter record can no longer be filed unless at least one of the two
parties — complainant or respondent, either role counts — matches an
existing Census resident. Both fields on the Blotter form are now
search-pickers, same as Clearance/Indigency/Residency: typing filters
Census residents live, and picking one links the record to that exact
resident via new `complainant_id`/`respondent_id` columns on
`blotter_records`. Both complainant and respondent have a "Not a
resident / outside party" checkbox that swaps the picker for a plain
text field — but only one at a time: checking one disables the other
(`_updateOutsideToggleAvailability()` in `blotter.html`), since at
least one party still has to be a real Census resident. The backend
enforces the same rule independently regardless of what the UI allows,
so this can't be bypassed by calling the API directly.

**Why this matters beyond just the UI:** the earlier version matched
residents by name text alone, which is ambiguous the moment two
residents share a name — exactly the case that motivated switching to
search-pickers everywhere else. Now that a blotter entry stores the
resident's real ID, the blotter-record warning shown before issuing a
Clearance/Indigency/Residency certificate (`?type=blotter_check` in
`api/documents.php`) matches by that exact ID first, and only falls
back to fuzzy name-text matching for blotter entries saved before this
column existed. Verified directly: created two residents both named
"Garcia, Jose," filed a blotter record against only one of them, and
confirmed the warning correctly fires for that one and stays silent for
the other — the same-name false positive this was built to prevent.

Bulk import (`api/blotter_import.php`) follows the same rule and the
same conservative fallback: if an imported name matches exactly one
Census resident, that row gets linked by ID; if the name matches more
than one resident, the row still imports (as long as the residency
requirement is otherwise satisfied) but is deliberately left unlinked
rather than guessing which same-named resident it is.


## Blotter-record warning on document issuance

Before a Barangay Clearance, Certificate of Residency, or Certificate
of Indigency is issued, the app checks whether the selected resident
has any blotter record — as either complainant or respondent, since a
person can show up on either side of a case
(`api/documents.php?type=blotter_check`, matched by name against
`blotter_records.complainant`/`respondent` since blotter entries aren't
linked to a census `resident_id` the way settlements are). If a match
is found, an amber warning panel shows the real record — docket number,
the other party, nature of the case, filing date, and status — right in
the issuance form, and the person issuing the document has to
explicitly check an acknowledgment box before "Issue & Preview" will
proceed. Nothing is blocked outright; the point is to make sure whoever
is issuing the document has actually seen the blotter history and made
a deliberate choice, not to prevent issuance automatically.

## Certificate of Residency

A new document type alongside Clearance and Indigency
(`residency.html`, `barangay_residency` table, `?type=residency` in
`api/documents.php`), built on the identical pattern: a resident picker
requiring the person already exist in Census (same `resident_id`
foreign key, same `ON DELETE CASCADE`), the same real letterhead image,
the same auto-generated sequential control numbers (`BR-YYYY-NNN`), and
the same blotter-record warning described above. The one field unique
to this document is **Years of Residency**, entered manually at
issuance time (Census doesn't track a resident's move-in date, so this
isn't derived automatically) and printed into the certificate body —
"is a bonafide resident of ___, for ___ year(s)" — rather than the
clearance's "has no derogatory record" language.

## Certificate of Non-Residency

The inverse of Certificate of Residency — certifies that a person on
file in Census does **not** qualify as a resident of this barangay
(e.g. insufficient length of stay, primary residence elsewhere), rather
than that they do. Built from the client's actual reference document
(`assets/non-residency-letterhead.jpg`) using the same official
letterhead as every other certificate here. Like Residency, the
applicant is still picked from Census (`resident_id` foreign key,
`ON DELETE CASCADE`) — being on file in Census doesn't by itself make
someone a resident, so requiring the pick and having the certificate
state non-residency aren't in tension. Auto-generated control numbers
use the `NR-YYYY-NNN` prefix (`barangay_non_residency` table,
`?type=non_residency`).

The certifying text is intentionally shorter than the other
certificates — no age, civil status, or address fields, since the
document is only asserting *not* being a resident, not describing one
— which is why its signature block sits much higher on the page (~61%
vs Clearance's ~82-87%). Dedicated `.cert-ov-nr-body` /
`.cert-ov-nr-sigimg` / `.cert-ov-nr-sigblock` CSS classes in
`styles.css` handle this document's positioning rather than reusing
the shared `.cert-ov-body` / `.cert-ov-sigimg` / `.cert-ov-sigblock`
classes, which are calibrated for the other three certificates' much
larger 28%-82% gap between body and signature.

**Two real bugs surfaced when the person actually rendered and sent
back a screenshot of this certificate**, both fixed by looking at the
real output rather than measurements alone:

- The source letterhead had **"BARANGAY CLEARANCE" baked directly into
  the image** (it was evidently adapted from the Clearance letterhead
  and the title was never swapped out), sitting exactly where the
  "CERTIFICATE OF NON-RESIDENCY" overlay renders — the two titles
  were stacking on top of each other. Fixed by inpainting it out of
  `assets/non-residency-letterhead.jpg` with OpenCV, masking only the
  actual dark text strokes (not a full rectangle) so the watermark and
  green accent bar around it blend naturally instead of smearing.
- The signature block was **overlapping the body paragraphs**. The
  first pass reused Clearance's body font-size/line-height, which is
  sized for a ~54-percentage-point gap between body and signature —
  non-residency only has about 30 points of room, so the real
  four-paragraph body text ran taller than the space available and
  spilled into the signature area. Fixed by giving this document its
  own smaller, more compact body text sizing (`.cert-ov-nr-body`)
  instead of inheriting Clearance's, then stress-tested with a
  deliberately long applicant name, long purpose text, and long
  captain name to confirm nothing overflows under realistic worst-case
  content, not just the short placeholder values used the first time.

**A third round of fixes** brought the wording and fields in line with
a real certificate the client had actually issued (rather than the
generic phrasing used up to that point):

- The body text now reads "This is to certify that Mr./Mrs./Ms. ___,
  which previous home address is ___. He/She no longer resides in the
  given address," followed by "This certification was issued upon the
  request of ___" and "Given this ___ day of ___ ___, at the office
  of the Barangay Chairman, Mapulang Lupa, Pandi" — matching the real
  document instead of the "is NOT a bonafide resident... for the
  purpose of..." phrasing used before.
- Added a **Previous / Former Home Address** field
  (`previous_address` column, prefilled from the resident's Census
  address but editable, since the actual former address on the
  request can differ in wording from what Census has on file) — the
  real certificate names a specific address the person no longer
  lives at, which the earlier version had no field for at all.
- **Purpose is now free text** instead of a dropdown of generic
  categories (Employment, School Requirement, etc.) — the real
  certificate names a specific requesting party ("PNP-Pandi"), which
  doesn't fit a fixed category list.
- The Control No. line moved to a small, unobtrusive line above the
  title (`.cert-ov-nr-ctrl`) instead of the shared prominent
  "Control No. | O.R. No. | Amount Paid" bar the other three
  certificates use — the real certificate doesn't show O.R./Amount in
  that position at all.
- Fixed a **duplicate-id bug** found while rebuilding this page: the
  certificate preview's control-number `<div>` and the form's O.R.
  Number `<input>` both used `id="nr_or"`, so
  `document.getElementById('nr_or')` always resolved to the preview
  div (first in document order) — meaning the O.R. Number a user
  typed was never actually being read when a certificate was issued.
  Every id on this page was audited for uniqueness after the rebuild.

**A fourth round of fixes** made the layout match the reference
exactly, not just the wording: the body text and signature block were
centered full-width (84% of the page), but the client's actual
certificate has them in a **narrower column to the right of the
green photo/council panel** — measured directly from the reference
(the panel's right edge sits at ~38%, so the text column runs from
~39% to ~95%). `.cert-ov-nr-body` and `.cert-ov-nr-sigblock` in
`styles.css` now override the shared `.cert-ov` positioning with their
own `left`/`right`/`width` and a smaller font-size to fit the narrower
space, with the signature block left-aligned within that column
instead of centered — verified with a side-by-side image comparison
against the reference, and stress-tested with a much longer name,
address, and requesting-party string to confirm the narrower column
doesn't overflow into the panel or off the page edge. The title stays
full-width and centered, matching the reference — only the body text
and signature needed to move.

**A fifth round** made three further changes:

- **Body text size increased** (0.63rem → 0.82rem). This needed the
  signature to actually move away from the reference's cramped ~48%
  position rather than stay pinned to it — the client confirmed the
  signature should instead sit where it does on Clearance/Residency/
  Indigency (~82-87%, the standard bottom-right position), which is
  what freed up the room the larger text needed.
- **Voter / Non-Voter checkbox** — "( ) VOTER ( ) NON-VOTER" turns out
  to be baked into this letterhead image itself (unlike Clearance,
  whose background has no such text), so it was never actually being
  filled in. Added a checkmark overlay following the same
  resident-voter-status pattern Clearance uses, with both parenthesis
  positions (`.cert-ov-nr-voter-check`/`.cert-ov-nr-nonvoter-check`)
  located by scanning the actual letterhead pixel-by-pixel for where
  each "(" sits, rather than eyeballing it. `voter_status` was also
  added to the non-residency GET query so a certificate reopened later
  from the log still shows the correct box checked, not just a
  newly-issued one.
- **Real signature image** — the mechanism to show an uploaded
  Barangay Captain signature already existed in code, but had never
  actually been tested with a real uploaded file in this environment
  (a fresh install has no signature uploaded, so it always looked
  "missing" even though the code was right). Verified end-to-end this
  time: logged in as the seeded Captain account, uploaded a signature
  through the real API, and confirmed both that the API correctly
  returns the new file and that it renders in the correct position on
  the certificate.

While wiring the checkmark overlay, a real positioning bug turned up:
the checkmark's CSS `left: 37.9%` was written assuming that percentage
was relative to the full page, but its parent element had already
inherited the shared `.cert-ov` class's own `left: 8%; width: 84%` —
so the checkmark was actually being positioned as 37.9% of that
*narrower, offset* box, landing several points off from the real
parenthesis position. Fixed by giving the voter-line container
`left: 0; width: 100%` so child percentages map directly to page
percentages, and verified the fix by rendering with a bright debug
background color on each checkmark span and measuring exactly where
the rendered pixels landed, rather than trusting the CSS math alone a
second time.

**A sixth round** removed a second, separate static text block found
on this letterhead: a duplicate "HON. ALEXIS ROQUE CRUZ / BARANGAY
CHAIRMAN" signature line near the bottom of the page (distinct from
the photo panel's caption higher up), sitting in the same spot as the
dynamic Captain signature overlay and colliding with it. Removed with
the same targeted OpenCV inpainting approach used earlier for the
"BARANGAY CLEARANCE" title — masking only the actual text strokes so
the footer bar directly below it stayed untouched, confirmed by
comparing dark-pixel density in that row before and after (several
hundred to over a thousand, down to under 25 — consistent with
background texture, not readable text).

The "( ) VOTER ( ) NON-VOTER" checkboxes and the "SIGNATURE OVER
PRINTED NAME" line were also left as blank static form fields up to
this point, meant to be filled in by hand after printing. The voter
checkboxes already got a dynamic checkmark in an earlier round; this
round added the applicant's own printed name
(`.cert-ov-nr-applicant-name`) on the signature line — the standard
"sign above your printed name" acknowledgment convention on this kind
of form, not the Captain's name, which already appears separately at
the bottom. Position was measured directly from the letterhead's
underline (~75.5%, spanning ~37.6%-78.9%), with the name sitting just
above it and a confirmed clean gap before the italic label below.

## schema.sql re-import safety

`schema.sql` is written to be safe to re-run against a database that
already has data — every `CREATE TABLE` uses `IF NOT EXISTS`, every
later `ALTER TABLE ... ADD COLUMN` uses `IF NOT EXISTS` too. One piece
wasn't actually safe though: the stored procedure used to add
`blotter_records`' foreign keys (`_bc_add_blotter_fks_if_missing` —
needed because a plain `ALTER TABLE ADD CONSTRAINT` has no
`IF NOT EXISTS` form) was created and then dropped at the very end of
that block, with no guard on the `CREATE PROCEDURE` itself. If any
earlier import of the file was ever interrupted between those two
points — a dropped connection, an error anywhere earlier in the
script — the procedure was left behind, and every subsequent import
then failed immediately at `CREATE PROCEDURE ... already exists`. The
`mysql < schema.sql` client stops processing the rest of the file the
moment it hits an error, so everything after that point — including
every `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE ADD COLUMN` for
newer features — silently never ran. In practice this surfaced as a
plain 500 error from `api/documents.php?type=non_residency`, because
the underlying `INSERT` referenced a column
(`barangay_non_residency.previous_address`) that never actually got
added to the live database.

Fixed with a `DROP PROCEDURE IF EXISTS` immediately before the
`CREATE PROCEDURE`, so the block self-heals regardless of what state a
previous partial run left behind. Verified by deliberately recreating
that broken state (manually leaving the procedure behind, simulating
an interrupted import) and confirming a fresh `schema.sql` import
against it now completes cleanly with all tables and columns intact,
not just confirming a normal double-import works.

**If you hit this error on an existing installation**, the fastest
fix without waiting for a fresh download is to run this directly
against your database:

```sql
DROP PROCEDURE IF EXISTS _bc_add_blotter_fks_if_missing;
```

then re-run `schema.sql` as normal (via phpMyAdmin's Import tab, or
`mysql -u root blottercast < schema.sql`) — the rest of the file will
now run to completion and add whatever tables/columns were missing.

## Census ↔ Clearance / Indigency linkage

The same pattern as Blotter ↔ Settlement applies here: a Barangay
Clearance or Certificate of Indigency can only be issued to someone who
already exists as a resident in Census —
`barangay_clearance.resident_id` and `indigency_certificates.resident_id`
are real foreign keys to `census_records.id` (`ON DELETE CASCADE`), so
deleting a resident also removes any certificates issued to them. The
issuance forms on both pages reflect this — instead of typing the
applicant's name, age, civil status, and address freely, you pick from
a dropdown of registered residents, and those fields (plus voter status
on Clearance) are derived automatically: age is computed live from the
resident's date of birth rather than re-entered. `api/documents.php`
enforces this server-side too — a 400 if no `residentId` is given, a 404
if it doesn't match a real Census row.

**A bug this surfaced and fixed:** the `Created` audit-log entries for
Census, Clearance, Indigency, and new user accounts were being written
via `logAudit()` *before* the code read back `$mysqli->insert_id` —
since that property reflects the connection's most recent insert,
`logAudit()`'s own `INSERT INTO audit_logs` silently overwrote it, so
every one of those four "create" endpoints was actually returning the
audit log row's id instead of the real record's id. It went unnoticed
until this stricter foreign-key validation made a wrong id immediately
fail with "does not exist" instead of silently working. Fixed by
capturing `insert_id` into a variable immediately after `execute()`, in
all four places, before the audit log call runs.

## Blotter ↔ Settlement linkage

A settlement record can only be created against an existing blotter
case — `settlements.blotter_id` is a real foreign key to
`blotter_records.id` (`ON DELETE CASCADE`), enforced in
`api/records.php`: creating a settlement without a valid `blotterId`
returns a 400, and an unknown id returns a 404. The Settlement Monitor
page's "Add Settlement Record" form reflects this — instead of typing
case title, complainant, and respondent freely, you pick from a
dropdown of blotter cases that don't already have a settlement attached
(one settlement per blotter case), and the case title, nature, and date
filed are inherited automatically from that record rather than
re-entered. The blotter link itself can't be changed after creation;
editing a settlement only updates its own progress fields (confrontation
date, action taken, settlement/execution dates, status, remarks).

## Dashboard notifications

The bell icon on the Dashboard is backed by a real `notifications` table,
not fabricated content. `api/notifications.php` scans for three genuine
conditions on every `list`/`unread_count` call and inserts any that
aren't already recorded (de-duplicated by `type` + `ref_table` + `ref_id`,
so the same event never generates a second alert on a later page load):

- a **High-priority incident** was logged in the last 3 days
- a **settlement** has sat at `Pending` for 14+ days since it was filed
- the **latest ML training run** flags a zone at or above the configured
  risk threshold (Settings → General → risk threshold, default 75%)

Read/unread state is tracked per-user in `notification_reads` (a
join table, not a single flag on the notification itself), so every
account sees its own unread badge independent of what other accounts
have already read — confirmed directly: marking everything read as one
user leaves another user's count untouched.

## Automated backups

"Backup Frequency" and "Backup Time" under Settings → Backup & Recovery
are no longer just stored preferences — since XAMPP has no built-in
cron/task scheduler, the automation works as a check-on-visit: every
time a System Admin opens the Dashboard or the Settings page,
`api/settings.php?action=auto_backup_check` compares how long it's been
since the last successful backup against the configured frequency
(Daily / Every 12 hours / Weekly) and silently runs a real backup
in the background if one is due, logging it to the same `backups` table
and `audit_logs` as a manual "Run Backup Now" click (both now share one
`runDatabaseBackup()` function, so they can't drift out of sync with
each other). Automatic backups are attributed to `system (automatic)`
in the history table so they're distinguishable from manually-triggered
ones. Only System Admin can trigger this (`system_settings` permission),
matching who can already see the Settings page at all.

Each backup is a single self-contained `.sql` file — full schema
(`DROP TABLE IF EXISTS` + `CREATE TABLE`, taken verbatim from `SHOW CREATE
TABLE`) followed by every row as an `INSERT` statement — generated
natively in PHP via `mysqli` rather than by shelling out to the
`mysqldump` binary. This avoids depending on `exec()` being enabled or on
`mysqldump` being installed/discoverable on the server, which vary across
XAMPP installs and hosts. Files are written to a `backup/` folder at the
project root (created automatically on first run, alongside `api/`) and
named `blottercast-backup-YYYYMMDD-HHMMSS.sql`.

## Settings page

The ML Model tab, Email/SMS notification toggles, and the MFA / Force
Password Change on First Login / Database-Level Encryption toggles under
Security have been removed — they were either unimplemented placeholders
or, in the case of the ML Model tab, better placed elsewhere.

The one setting from that tab that was actually load-bearing —
**Barangay Default Model** (which model Predictions uses for anyone who
hasn't picked one in their own browser yet) — moved to a small control
directly on the Predictions page instead of disappearing. Anyone with
`view_analytics` can see the current default there; only Admin/Captain
(`retrain_ml`) can change it, via a lightweight endpoint
(`api/settings.php?action=ml_model`) that doesn't require full
`system_settings` access the way the old Settings-page version did — a
Desk Officer can now correctly see the barangay default without needing
admin rights just to read one value.

## Automatic record numbering

Incident report numbers (`INC-YYYY-NNNN`), blotter docket numbers
(`BLT-YYYY-NNN`), and settlement case numbers (`STL-YYYY-NNN`) are all
assigned automatically and sequentially — `nextSeqNo()` (shared out of
`api/nextseq.php` so both `api/records.php` and the Blotter import below
use the exact same logic) finds the *highest* existing number for the
prefix+year and assigns the next one, with a collision-check loop as a
safety net. Census/Clearance/Indigency control numbers work the same
way via `nextCtrlNo()` in `api/documents.php`. None of the "Add" forms
have an editable number field; each shows a disabled "Auto-generated on
save" input that fills in with the real number once the record exists.

Two real bugs lived here before this was fixed:
- `mt_rand()` was originally used instead of a real sequence, which
  could hand out duplicate or wildly out-of-order numbers.
- After that was fixed to `COUNT(*) + 1`, adding a new blotter record
  could still throw a 500 (`Duplicate entry ... for key 'docket_no'`)
  whenever the existing sequence had any gap — which `api/seed.php`
  itself was silently causing, since its docket/report/case counters
  were single global variables shared across every year of seeded data
  instead of resetting per year (e.g. 2025's last number flowing straight
  into 2026's first, instead of 2026 starting fresh at 001). A `COUNT(*)`
  of "how many 2026 rows exist" is one lower than "the highest 2026
  number in use" the moment there's a gap like that, so the count-based
  next-number collided with a number that was already taken. Fixed by
  switching to `MAX(existing number) + 1` (which is correct regardless
  of gaps) and by giving the seeder a genuine per-year counter.

## Blotter Record import

The Blotter Records page's Import button (`api/blotter_import.php`)
accepts `.xlsx` or `.csv` files using the exact same eleven columns as
the **Blotter Record** export, so exporting, editing in Excel, and
re-importing round-trips cleanly — verified directly by exporting real
data and re-importing that same file. Reading `.xlsx` server-side uses
a small dependency-free reader (`api/xlsx_reader.php`, the counterpart
to the writer used for exports) that parses the OOXML zip/XML structure
directly via `ZipArchive` and `SimpleXML` — no Composer/PhpSpreadsheet
needed, consistent with the rest of this project.

Each row always creates a `blotter_records` entry (Case Title is split
on "vs."/"vs" into complainant/respondent). If any of the
settlement-related columns for that row have data — date of initial
confrontation, action taken, date of settlement/award, date of
execution, main point of agreement, or status of compliance — a linked
`settlements` row is created too, using the blotter record that row
just created as the required `blotter_id`. Rows with none of those
columns filled in only create the blotter entry, matching how the two
modules are meant to work everywhere else in this app (a settlement
can't exist without a blotter case, but not every blotter case has
reached the settlement stage yet). Gated by the same `import_data`
permission as the Census import.

## Excel exports (official forms)

Three exports generate `.xlsx` files matching specific official
Katarungang Pambarangay forms exactly — column names, order, and
grouped/merged headers — built with a small dependency-free writer
(`api/xlsx_writer.php`) that constructs the OOXML spreadsheet format
directly via PHP's `ZipArchive`, so no Composer/PhpSpreadsheet install
is required, consistent with how this project bundles TCPDF instead of
using a package manager.

- **Settlement Monitor page → Export to Excel** — "Monitoring of
  Compliance to Settlement or Award", with the merged "SETTLEMENT OR
  AWARD" header spanning Date Agreed / Date of Execution sub-columns.
- **Blotter Records page → Export to Excel → Blotter Record** — the
  wide form combining blotter details with settlement-tracking columns
  (date of initial confrontation, action taken, settlement/execution
  dates, compliance status), left-joined so blotter cases without a
  settlement yet still appear with those columns blank.
- **Blotter Records page → Export to Excel → Blotter Entry Record** —
  the docket-style logbook format with separate Criminal/Civil marker
  columns (a check mark in whichever column matches the case type).

All three require the `generate_reports` permission (Admin, Captain,
Desk Officer — not Data Encoder), same as the PDF Reports page. Clicking
Export opens a small filter dialog (all records / a specific year / a
specific year and month) before the download starts — `api/exports.php`
accepts `?year=YYYY` and optional `&month=M` query parameters and
filters every query by `date_filed`, with the title row and downloaded
filename reflecting whatever period was chosen.

## Login and logout

Logging out now asks "Are you sure you want to log out?" before it
actually signs you out — `doLogout()` in `app.js` is shared by every
page's sidebar, so this one change covers all 13 internal pages at once
rather than needing to be added per page.

The login screen's Admin/Captain/Officer/Encoder toggle above the
username field has been removed — it never actually did anything (its
own code comment said "Cosmetic only — the backend determines the real
role from the account"), and it sat right above the "Fill demo
credentials" pills that already do the real job: clicking one fills in
that account's actual username and password. Those pills are unchanged
and still work exactly as before.

## Documents module (Census, Clearance, Indigency)

Adding a resident in Census now requires every field except Middle
Name (some residents genuinely don't have one) — enforced in the
actual save logic in `census.html`, not just cosmetic `required`
attributes, since the Save button is a plain button rather than a
native form submit that HTML5 validation would otherwise catch.
Missing fields are listed by name in the alert rather than a generic
"fill in required fields" message.

While making this change, found and fixed a real pre-existing gap: the
form has always had a Nationality field, but `census_records` never had
a column for it — every resident's nationality was silently discarded
on save, and the field would come back blank when editing an existing
resident regardless of what had been typed in. Added the missing
`nationality` column (`schema.sql`), wired it through both the create
and update handlers in `api/documents.php`, and fixed `census.html` to
actually read it back when opening a resident for editing.

Census records, Barangay Clearance, and Certificate of Indigency are full
CRUD-backed modules (`census_records`, `barangay_clearance`,
`indigency_certificates` tables via `api/documents.php`) — add, edit,
issue, and delete all persist to MySQL. Clearance and Indigency
certificate numbers auto-increment per year (`BC-2026-001`,
`CI-2026-001`, …). Census has a real CSV import (Settings → the
Import button on the Census page) that creates one resident row per CSV
line. Consistent with the thesis's stated system scope, these modules are
record/monitoring only — no ML predictions are computed from them.

**Certificate layout.** The Clearance and Indigency printable/preview
certificates use the client's actual official letterhead images
(`assets/clearance-letterhead.jpg`, `assets/indigency-letterhead.jpg`)
as a background, including both barangay and municipal seals, the green
accent bars, the security watermark, and the footer contact details.
Both source documents supplied for this project used the same blank
letterhead; the Indigency version has had its thumbmark boxes,
VOTER/NON-VOTER checkbox, and "SIGNATURE OVER PRINTED NAME" label
removed via image inpainting (`cv2.inpaint`), since a Certificate of
Indigency doesn't use those — Barangay Clearance keeps all three. The
dynamic certification text, title, control number, and (for Clearance)
the VOTER/NON-VOTER checkbox are positioned on top using
percentage-based coordinates in `styles.css` (`.cert-sheet`, `.cert-bg`,
`.cert-ov*`), measured against a real LibreOffice render of the source
`.docx` so the overlay lines up with where Word actually places content,
not just where the blank letterhead image looks empty.

**Captain's signature.** Neither certificate hard-codes a name or
signature image. On the Users & Roles page, editing an account with the
role **Barangay Captain** reveals an e-signature upload field (PNG or
JPEG, max 2MB) — `api/users.php?action=upload_signature` stores it under
`assets/signatures/` and records the path on that user's row
(`users.signature_path`). Clearance and Indigency both fetch whichever
active Barangay Captain has a signature on file via
`api/users.php?action=captain_signature` — a lightweight, non-sensitive
endpoint any signed-in role can read (a Desk Officer issuing a clearance
doesn't have `manage_users`, but still needs the captain's name and
signature to print a valid document). If no captain has uploaded a
signature yet, the certificate simply prints with a blank signature line
rather than a placeholder name — there's no fake "Hon. ___" anywhere.
Because the overlay is percentage-positioned against each image's own
aspect ratio, it stays aligned at any screen size and in print. If the letterhead images are
ever replaced, the overlay coordinates were measured against a
1795×2539px source and will need re-measuring against the new file's
proportions.

## Settings

Every field and toggle across all five Settings tabs (General,
Notifications, Security, Backup & Recovery, ML Model) persists to the
`system_settings` table via `api/settings.php` and reloads on every visit.
The **ML Model → Active Model** dropdown genuinely sets which model the
Predictions page defaults to (until a user picks a different one in their
own browser). **Backup & Recovery → Run Backup Now** writes a real
schema+data SQL dump of the live database to `backup/`, logs it to the
`backups` table with actual file size, and the entry becomes downloadable
immediately — nothing here is simulated. Toggles like "Database-Level Encryption" and
"Multi-Factor Authentication" save as configuration flags but don't
implement the underlying infrastructure (that would mean shipping actual
disk encryption / TOTP — out of scope for a student prototype); the page
is honest about this in the on-screen help text rather than pretending.

## Design system

The whole app shares one visual identity, defined in `styles.css`:

- **Brand mark:** the real seal (`logo.png`) is used everywhere — sidebar,
  login screen, landing page hero, and the printable certificate header —
  instead of a text/emoji placeholder.
- **Icons:** every icon in the app (sidebar nav, buttons, table row actions,
  toasts, settings tabs) is a small inline-SVG line icon from `icons.js`.
  There are no emoji anywhere in the UI. Icons render via
  `<span data-icon="name"></span>`, sized with an optional
  `data-icon-size` attribute; a `MutationObserver` in `icons.js` renders
  any icon added later (table re-renders, modals) automatically — no
  page needs to remember to call a render function.
- **Palette:** a single forest-green scale (`--forest-50` through
  `--forest-900` in `styles.css`) plus a small set of semantic accents
  (amber for pending/ongoing, red for overdue/danger, blue/violet for a
  couple of status chips) — no other colors are introduced.
- **Type:** DM Serif Display for headings, DM Sans for everything else,
  loaded once and reused everywhere.

## Role-based access control

Access is enforced against the same permission matrix shown on the Users
& Roles page, in two layers:

- **Server-side (the real security boundary):** `api/permissions.php`
  defines which of the four roles (System Admin, Barangay Captain, Desk
  Officer, Data Encoder) may use each permission — viewing records,
  editing, deleting, generating reports, viewing analytics, retraining
  the ML model, importing data, managing users, and system settings.
  Every API endpoint that needs a permission calls `require_permission()`
  before doing anything, so a request that bypasses the UI entirely
  (a crafted `fetch()`, curl, or a browser devtools call) is blocked
  with a 403 exactly the same as a click would be. `require_login()`
  runs first everywhere, so no endpoint is reachable without a valid
  session regardless of role.
- **Client-side (UI polish, not a security boundary on its own):**
  `permissions.js` mirrors the same matrix so the sidebar hides links a
  role can't use, and a page a role has no business on redirects to the
  Dashboard instead of rendering. Buttons for actions a role can't
  perform (edit, delete, import) are hidden the same way via a
  `data-perm="..."` attribute, including ones added dynamically when a
  table re-renders.

The Python ML service itself has no concept of sessions or roles, so the
browser never calls it directly anymore — `api/ml_proxy.php` sits in
front of it and enforces `view_analytics` (and the stricter
`retrain_ml` specifically for training a fresh model) before forwarding
the request to Flask. A Desk Officer can open Predictions and see the
last model an Admin or Captain trained, but clicking a model card or
Retrain is blocked both in the UI and by the proxy.

If you change a permission, update **both** `api/permissions.php` and
`permissions.js` — the PHP copy is authoritative and the JS copy exists
only so the UI doesn't show controls a click would just get rejected
for.

## Notes

- **Zone boundary:** `mapulang-lupa.geojson` is still a placeholder polygon
  for Barangay Mapulang Lupa, Pandi, Bulacan — swap in the official boundary
  file when available; the heat map's point-in-polygon filter and Leaflet
  layer will pick it up automatically.
- **Scope:** Census, Clearance, and Certificate of Indigency are fully
  real CRUD modules (see above) but intentionally have no ML predictions
  wired to them, matching the thesis's stated system scope.
- **Security:** sessions are PHP's native `session_start()`; passwords are
  bcrypt-hashed via `password_hash()`. This is a student prototype — add
  HTTPS, CSRF tokens, and rate limiting before any real deployment.
- **Resetting data:** Settings → Data Management → "Reset Demo Dataset"
  re-runs `api/seed.php`, wiping and regenerating all transactional tables
  and resetting the five demo accounts to their default passwords (see the
  table above). Any additional accounts you created are not removed but
  their passwords are untouched; only the five demo usernames are reset.
