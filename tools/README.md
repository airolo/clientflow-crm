# tools

Helper scripts. None of them are part of the application, and nothing in the
app depends on them — the CRM runs perfectly well with this folder deleted.

## regression.ps1

The main quality gate. Drives the running site over HTTP and asserts on real
behaviour: every page under both roles, CRUD lifecycles, validation rejections,
CSRF, stored and reflected XSS, SQL injection attempts, the forced password
change, sign-in throttling, soft delete and restore, flash messages,
accessibility spot checks and orphaned rows.

```powershell
# XAMPP running, project in htdocs, database.sql imported
powershell -ExecutionPolicy Bypass -File tools\regression.ps1
```

It also reads the Apache error log after each request rather than trusting the
response body, because `display_errors` can be on and still hide warnings from a
body-level scan.

It cleans up everything it creates, restores the seeded demo state — including
the `must_change_password` flags and the `login_attempts` table — and exits
non-zero on failure, so it works as a pre-commit check.

Needs PowerShell 5.1 (bundled with Windows) and `mysql.exe` at
`C:\xampp\mysql\bin\mysql.exe`. Override with `-BaseUrl` if your project folder
is named differently.

### It signs in with accounts it creates

Neither script uses the demo passwords. Each creates its own throwaway
accounts — `regression-admin@`, `regression-staff@`, `forced@` and the two
`verify-@` accounts — and deletes them at the end.

That is deliberate: the app forces every seeded account to change its password,
so sharing the demo credentials meant that following the app's own instructions
broke the suite with `ABORT: admin login failed`.

Neither script resets a password it did not set. Silently replacing the admin
password of whatever database they are pointed at would be worse than failing the
run.

Two guards keep repeat runs safe:

- A pre-run sweep clears leftovers from an aborted run before the baseline is
  captured, so a stale row cannot make the suite fail the second time.
- The seeded accounts are snapshotted and restored during cleanup, and the
  restore is asserted. The last-admin test attempts a demotion, and without the
  restore a run where that wrongly succeeded left the demo admin as a staff
  user for every later run.

## verify_phase1.ps1

26 checks covering the sign-in hardening on its own: `DEMO_MODE` gating, the
forced password change, throttling and lockout, and the two static checks behind
the CSP. Useful when changing anything in `app/auth.php` or
`app/models/LoginAttemptModel.php`.

```powershell
powershell -ExecutionPolicy Bypass -File tools\verify_phase1.ps1
```

It restores the seeded demo state when it finishes.

## check_tenancy.ps1

Static check that no SQL statement reads or writes a tenant-scoped table without
a tenant predicate on that table's own alias.

```powershell
powershell -ExecutionPolicy Bypass -File tools\check_tenancy.ps1
```

No running site and no database needed — this is source analysis, so it is the
cheapest of the four and the one to run before every commit.

For each statement it resolves the base table (`FROM` / `INSERT INTO` / `UPDATE`
/ `DELETE FROM`), works out that table's alias, and requires the predicate in the
outer `WHERE`. Matching on the alias specifically is the point: an earlier
version tested "does the statement mention `tenant_id` anywhere" and it passed a
genuinely leaky query, because `client_find()` had both its `LEFT JOIN`s scoped
while its `WHERE` was wide open. Scoping a join and scoping the base table are
different things, and only the second filters rows.

It takes the **last** `WHERE` as the outer one, since a subquery in the select
list appears before `FROM` and would otherwise be mistaken for it.

Statements built with an interpolated table name (`SoftDeleteModel`'s
`UPDATE {$meta['table']}`) are recognised too. They were invisible to an earlier
version, which needed a literal word after the SQL keyword — and that was the one
file where blindness would have cost the most, since it restores and permanently
purges rows by bare `id`.

`list_query()` callers are judged on declaring which alias to filter under a
`'tenant'` key. Deleting that key does not leak: the builder's default alias is
`t`, so a query aliased `c` gets an unknown-column error and a 500. It fails
closed, which is the desired outcome — but it means the check is worth keeping,
because it catches the mistake at commit time instead of at runtime.

## isolation_test.ps1

123 assertions across two real workspaces. This is the only check that can prove
one business cannot see another's data: with a single workspace every page
renders correctly whether or not a query is scoped, so `regression.ps1` passing
331 assertions says nothing about isolation.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\isolation_test.ps1
```

It creates two throwaway workspaces, signs in to each in its own browser session,
and asserts that neither can reach the other's records — listings, detail pages
reached by walking `?id=`, and writes. The writes matter most: delete, restore,
purge and edit all address rows by a bare `id` from the query string, so every
list can look correctly filtered while those still destroy another workspace's
data.

Also covered: the same email address existing in two workspaces, a suspended
workspace being signed out, throttling not crossing workspaces, and an unknown
workspace producing the same message as a wrong password.

Everything is removed afterwards. Two details worth keeping:

- A page that returns **500 counts as a failure**, not as "no marker found". A
  hard error that reads as a passing absence check is worse than no check.
- Cleanup deletes `login_attempts` by email pattern rather than joining through
  `tenants`. Attempts against an unknown workspace are stored with a null
  `tenant_id` on purpose, so a tenant-join sweep misses them; they accumulated
  until the deliberately global per-IP limit locked the test itself out.

Known gap: this covers data access, not the admin-management guard. Making
`user_admin_count()` global again does not fail here, because no section demotes
an admin across workspaces. `check_tenancy.ps1` is what covers that one.

## settings_test.ps1

43 assertions over two workspaces configured with different currencies and timezones.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\settings_test.ps1
```

`isolation_test.ps1` covers record access. This one covers the leak that is
easy to make and hard to see: a *presentation* setting crossing a workspace
boundary. Currency and timezone are the first settings that are genuinely
per-tenant rather than per-user, and both are easy to get wrong by reading a
global constant instead of the session.

It also covers the parts that are easy to leave broken: that `money()` is really
wired to the setting rather than still hardcoded, that a timezone change takes
effect immediately rather than on the next sign-in, that validation rejects
codes and zones that are not on the allowed lists, that staff cannot reach the
page, and that the workspace slug is not editable.

Self-cleaning. It changes the demo workspace's currency and timezone, so those
are snapshotted and restored.

### Asserting the offset, not the clock

The settings page prints the current time for the workspace with its UTC offset,
and that offset is the assertion. Two zones can show the same `HH:MM` at some
times of day, so a clock-based assertion would be flaky rather than sharp.

The display exists for the same reason the assertion does. Without it the
timezone setting was stored and applied but invisible, and the suite could not
tell "saved and applied" from "saved and ignored" — deleting the
`date_default_timezone_set()` call left it at 38/38. A setting nobody can see
working is its own kind of bug.

Verified by removing that call again: 3 assertions fail, including the one for a
timezone saved earlier in the same run, so both the sign-in path and the
save-then-reload path are covered.

### Currency symbols in a .ps1 file

The symbols are built from code points (`[char]0x00A3`) rather than typed
literally. PowerShell 5.1 reads a `.ps1` without a BOM as ANSI, so a literal
pound or euro sign in the source is a parse error — the script will not compile
at all. No file in this folder carries non-ASCII bytes.

## export_test.ps1

53 assertions over the five CSV exports.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\export_test.ps1
```

`isolation_test.ps1` covers record access through pages; this covers the export
path, which has its own queries and so is a separate place for a tenant filter to
go missing. It also covers the risk nothing else does: **formula injection**.
A company name typed by sales staff ends up in a file someone opens in Excel,
and a cell starting with `=` is a live formula.

Checked: the download headers, the workspace-scoped filename, completeness
(37 rows, past the 100-row page cap), commas and quotes and newlines not
shifting every later column, formula neutralisation including the padded-`=`
case, soft-deleted rows staying out, per-workspace scoping on all five types,
and bad or missing types being refused.

Verified by breaking it on purpose: removing the formula guard fails 4
assertions, and dropping `tenant_id` from `client_export_rows()` fails 4 here
and is also caught by `check_tenancy.ps1`.

### Two traps when building fixtures with awkward characters

Both of these produced a test that confidently reported the *app* losing
characters, when the characters had never reached the database:

- **A double quote inside a string passed to `mysql.exe --execute` is eaten by
  Windows argument parsing.** The fixture arrives without its quotes, and the
  export then appears to strip them. Build such values in SQL with `CHAR(34)`
  instead. Confirmed by inserting the same literal both ways and comparing
  `HEX()`: the `--execute` route produced `The Big Client`, the `CHAR(34)`
  route produced `The "Big" Client`.
- **`||` is logical OR in MySQL, not string concatenation**, unless
  `PIPES_AS_CONCAT` is set. Joining SQL fragments with `||` yields the number
  0 rather than the intended text, silently.

### One thing this suite cannot catch

`export.php` used to end with a `while (ob_get_level()) { ob_end_clean(); }`
loop. In that file it is a no-op, because `csv_send_headers()` has already
cleared the buffers — a probe page with the same loop but no earlier one
discarded an entire response body. It was removed rather than tested for,
because a no-op has no observable behaviour to assert on. Defence is removal,
not a regression test.

## import_test.ps1

62 assertions over the CSV import flow, for clients and leads.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\import_test.ps1
```

Import is the only path that writes many rows from data the app did not
create, so this is where the correctness work lives. Checked in order of how
badly each one would hurt:

- **Nothing is written before confirmation.** Upload shows a plan and the
  database is unchanged; only the confirm step writes.
- **A plan cannot be replayed**, so a reload cannot import twice.
- **Cross-tenant.** B confirming a plan A built imports nothing. This is the
  same class of bug as trusting a tenant id from a form.
- **A plan for clients cannot be committed as leads.**
- **Bad rows are skipped and reported**, not fatal and not silent.
- **Duplicates** are detected, skipped by default, and importable on request.
- **Real-world files:** semicolon delimiter, UTF-8 BOM, CRLF, `GBP1,234.56`.
- **Whole-file failures:** missing required column, headers only, empty file.
- **Staff can import** into their own workspace, and imported rows behave
  exactly like typed ones.
- **Round trip:** the app's own export headers are importable unchanged.

Verified by breaking it on purpose: removing the money assignment fails 1
assertion, and making the upload write before the review step fails 3.

### The PowerShell trap that made half of these assertions vacuous

```powershell
Check 'nothing was written' (Count-Where "...") -eq 0
```

does **not** test what it looks like. PowerShell coerces the parenthesised
value to `[bool]` when binding the argument, so `-eq 0` is never evaluated —
every such assertion was only testing "non-zero". It passes for `-eq 1`
assertions by accident and fails for `-eq 0` ones, which reads like an app
bug. Reproduced outside the app in three lines.

The fix is to put the whole comparison inside the parens:

```powershell
Check 'nothing was written' ((Count-Where "...") -eq 0)
```

The other suites were already correct because they wrote `([int](Sql "...") -eq 0)`.

Two more traps this suite hit, both of which made the *test* wrong rather than
the app:

- **The CSRF token must come from the page you are about to post to.** The
  app issues one per rendered form, so the token captured at sign-in is stale.
- **Keep the query string when fetching that page.** `import.php` stages a
  plan per record type and discards it when loaded with a different `type=`,
  so stripping `?type=lead` before fetching the token threw the staged plan
  away and the confirm step silently did nothing.

## signup_test.ps1

58 assertions over workspace creation and the first-run welcome screen.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\signup_test.ps1
```

Signup is the only endpoint reachable with no session, so this suite thinks
about abuse rather than just correctness: throttling (both limits, and that a
fresh cookie jar does not reset them), slug allocation not leaking which slugs
exist, one-transaction creation of workspace plus owner, the new workspace being
empty and correctly scoped, validation, and the welcome screen showing once.

Verified by breaking it on purpose: disabling `signup_locked_out()` fails 4
assertions, and making slug allocation stop de-duplicating fails 2.

### Three ways this suite throttled itself

Worth recording, because each one looked like an application bug:

- The per-IP limit is 5/hour and the validation section makes six signups from
  one IP, so the last is correctly refused. The suite now resets counters between
  sections, and says why.
- An assertion meant to prove "a fresh session does not reset the limit" first
  deleted the `signup_attempts` rows — which of course reset it. That tested the
  database delete. It now only opens a new cookie jar.
- The password variable was called `$pass`. At script scope that is the same
  variable as `$script:pass`, the pass counter, so the suite ran with a string
  where a number was expected.

And one general trap: `Write-Output` inside a helper that callers assign the
result of does not reach the console — it lands in their variable instead. Use
`Write-Host` for tracing.

## backup_test.ps1

56 assertions covering backup, restore and the workspace bundle.

```powershell
# XAMPP running, project in htdocs
powershell -ExecutionPolicy Bypass -File tools\backup_test.ps1
```

A backup that has never been restored is a guess, so this takes a real dump,
restores it into a scratch database (`cf_phasee_restore`, dropped afterwards),
and compares row counts table by table — all seven matching. The live database is
never restored into.

Also checked: every restore guard fires, the safety copy is itself restorable,
and a bundle holds this workspace's rows and nothing from another workspace's.

### Two bugs this found, both in tooling people rely on in a panic

- **`backup.ps1` printed its restore command using the `mysqldump` binary.**
  mysqldump *produces* dumps; it does not read them back. Run literally, the
  printed command created zero tables. The instructions now name `mysql.exe`, say
  why, and point at `restore.ps1`.
- **`restore.ps1` wrote its safety copy with PowerShell's `>` redirection**,
  which is UTF-16 in PowerShell 5.1 — a malformed copy of the database at exactly
  the moment it is needed. It now uses `--result-file=` like `backup.ps1` does,
  and the copy is checked for a dump header before the restore starts.

And one in the bundle: `ZipArchive` reads its members when `close()` finalises
the archive, so deleting the temp CSVs straight after `addFile()` left it with
nothing to write. The bundle also had `ob_end_clean()` in its `finally`, which
discarded the zip bytes it had just written — serving a correct
`application/zip` response with a zero-byte body. The archive is now size-checked
so this fails loudly instead of shipping an empty download.

## restore.ps1

The other half of `backup.ps1`. A safety copy is taken first and printed,
the dump is inspected before it is applied, `-Force` is required to overwrite a
database that has tables, and row counts are printed afterwards.

```powershell
powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql -Verify
powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql
powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql -Force
```

`-Verify` is the one to run against an offsite copy: it reports whether the dump
looks loadable and touches no database at all.

## backup.ps1

Dumps the database to a timestamped `.sql` file. There is no export button in
the app, and `database.sql` starts with `DROP TABLE`, so this is the safety net
before any risky change.

```powershell
powershell -ExecutionPolicy Bypass -File tools\backup.ps1
powershell -ExecutionPolicy Bypass -File tools\backup.ps1 -Keep 90
```

Default output is `%LOCALAPPDATA%\ClientFlow\backups`. Backups older than
`-Keep` days (30 by default, `0` to keep everything) are pruned afterwards.

**The script refuses to write anywhere under `C:\xampp\htdocs` or inside the
project folder.** This is not a formality: the project `.htaccess` blocks `.sql`
files, but only inside the project folder. A dump written to
`C:\xampp\htdocs\backups` is served as plain text — it was verified serving a
29 KB dump, password hashes included, before the guard was fixed. If you pass
your own `-OutputDir`, keep it outside the web root.

To restore, import the file through phpMyAdmin (select the database, then
Import), or:

```powershell
"C:\xampp\mysql\bin\mysql.exe" --user=root clientflow_crm < "path\to\backup.sql"
```

Import a *backup*, never `database.sql`, unless you intend to wipe the data.
