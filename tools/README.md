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

## verify_phase1.ps1

24 checks covering the sign-in hardening on its own: `DEMO_MODE` gating, the
forced password change, throttling and lockout, and the two static checks behind
the CSP. Useful when changing anything in `app/auth.php` or
`app/models/LoginAttemptModel.php`.

```powershell
powershell -ExecutionPolicy Bypass -File tools\verify_phase1.ps1
```

It restores the seeded demo state when it finishes.

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
