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
