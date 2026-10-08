# ClientFlow CRM

A small, complete CRM built with **PHP 8**, **MySQL/MariaDB**, **Bootstrap 5**, plain CSS and vanilla
JavaScript. It runs locally on XAMPP and ships with realistic demo data so every module is
populated the moment you log in.

No Composer, no build step, no JavaScript framework. Copy the folder into `htdocs`, import one SQL
file, and it runs.

---

## Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Setup (5 steps)](#setup)
4. [Demo accounts](#demo-accounts)
5. [Project structure](#project-structure)
6. [Database schema](#database-schema)
7. [How the code fits together](#how-the-code-fits-together)
8. [The landing page](#the-landing-page)
9. [Security notes](#security-notes)
10. [Backwards compatibility](#backwards-compatibility)
11. [Customising it](#customising-it)
12. [Troubleshooting](#troubleshooting)
13. [Running the tests](#running-the-tests)
14. [Migrations](#migrations)
15. [Licence](#licence)

---

## Features

**Landing page** — `/` is a public marketing page: hero, about, and five tabs (Features, Live
preview, Workflow, Integrations, FAQ) plus a four-step setup section. It renders no business data —
it is static markup, so it cannot leak customer details by being indexed or cached — and it names
what has *not* been built as plainly as what has. Its screenshots are real captures of this app.
The signed-in dashboard lives at `/dashboard.php`.

**Dashboard** — total clients, active leads, open deals, won deals, pending tasks and total
interactions as stat tiles; pipeline value by stage; leads by status; tasks due today or overdue;
largest open deals; recent activity feed; hot leads not yet on the pipeline.

**Clients** — full CRUD (company, contact person, email, phone, address, status, assigned staff,
notes). Search across four fields, filter by status and owner, sortable columns, pagination, and a
detail page aggregating that client's deals, tasks and interaction history.

**Leads** — full CRUD (name, company, contact details, source, status, estimated value, owner).
Filter by status, source and owner. Quick inline status change. A lead converts into a deal in one
click, which advances the lead to *Proposal*.

**Pipeline** — Kanban board of deals across six stages: New Lead → Contacted → Proposal →
Negotiation → Won / Lost. Each card can be moved between stages. Stage totals and a weighted
forecast (10 / 25 / 55 / 80 %) are shown above the board.

**Tasks** — CRUD with due date, priority (low/medium/high), status (pending/in progress/completed),
description, and an optional link to a client or a lead. Status tabs, an overdue-only filter, a
one-click complete/reopen toggle that stamps `completed_at`, and overdue highlighting.

**Activities** — interaction history (call, email, meeting, note) with timestamps and the user who
logged it, linkable to a client or a lead. Filterable by type, who logged it and date range.

**Reports** — monthly activity chart (6 months), deal values by stage, won vs lost with count and
value win rates, lead sources, activity mix, clients by owner, per-user team performance, and
recent wins. Has a print stylesheet.

**Users** (admin only) — create, edit, deactivate and delete accounts. Guards prevent deleting your
own account or demoting the last active admin.

Throughout: server-side validation with field-level messages, flash success/error messages,
confirmation dialogs on every destructive action, and purpose-written empty states.

---

## Requirements

- XAMPP (or any Apache + PHP 8.0+ + MySQL/MariaDB stack)
- PHP 8.0 or newer with `pdo_mysql` enabled (XAMPP ships this by default)
- A modern browser

No internet connection is needed at runtime. Bootstrap 5 and Bootstrap Icons are vendored under
`assets/vendor/`.

---

## Setup

### 1. Start Apache and MySQL

Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.

### 2. Copy the project into htdocs

Copy the whole `ClientFlow` folder into XAMPP's web root so the path looks like:

```
C:\xampp\htdocs\ClientFlow\index.php
C:\xampp\htdocs\ClientFlow\dashboard.php
C:\xampp\htdocs\ClientFlow\database.sql
```

`/` serves the public landing page; `/dashboard.php` is the app and needs a sign-in.

On macOS it is `/Applications/XAMPP/htdocs/ClientFlow/`, on Linux `/opt/lampp/htdocs/ClientFlow/`.
The folder name can be anything — `APP_URL` works out where the project is served from by
comparing its own location against the document root, so nothing needs reconfiguring.

### 3. Create the database

1. Go to <http://localhost/phpmyadmin>
2. Click the **Import** tab
3. **Choose File** → select `ClientFlow/database.sql`
4. Leave **Character set of the file** as `utf-8` (the file sets `utf8mb4` itself)
5. Click **Go** / **Import**

The script creates the `clientflow_crm` database, all eight tables and the demo data. You should see
`clientflow_crm` appear in the left sidebar.

> The SQL file starts with `DROP TABLE IF EXISTS`, so re-importing resets the database to the demo
> state. That is convenient during setup, but it also deletes anything you have entered.

### 4. Check the connection settings

Open `ClientFlow/app/config/config.php`. The defaults match a stock XAMPP install:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'clientflow_crm');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Only change these if you set a MySQL password or renamed the database. If you prefer to keep
credentials out of the file, define the matching environment variables (`DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`) before launching Apache — the config file reads them with
`getenv()`.

Two other settings live in the same file:

```php
define('APP_TIMEZONE', 'Europe/London');   // change to match your locale
define('ROWS_PER_PAGE', 10);               // rows per page on list screens
```

### 5. Run it

Visit <http://localhost/clientflow/>

If you renamed the folder, substitute that name. You will land on the login page — the demo
credentials are printed on it.

---

## Demo accounts

Sign-in takes a **workspace** as well as an email address, because two businesses may legitimately
both have an `admin@company.com` and their accounts are separate.

| Workspace         | Role  | Email                    | Password    |
|-------------------|-------|--------------------------|-------------|
| `clientflow-demo` | Admin | `admin@clientflow.test`  | `admin123`  |
| `clientflow-demo` | Staff | `sarah@clientflow.test`  | `staff123`  |
| `clientflow-demo` | Staff | `marcus@clientflow.test` | `staff123`  |
| `clientflow-demo` | Staff | `priya@clientflow.test`  | `staff123`  |

**Each of these is flagged to force a password change on first sign-in.** Sign in with any of them
and you go straight to a "choose a new password" screen; the rest of the app stays locked until
that is done. That is deliberate — these passwords are published in `database.sql`, this README and
the Git history, so they are not treated as a secret.

If you signed in before this became multi-tenant, your existing rows were adopted by a workspace
called `my-business` when you ran migration 003. Sign in with that slug.

Sign in as the admin to see everything, then as Sarah to see the reduced permission set: the Users
section disappears from the sidebar, and records belonging to other staff cannot be edited or
deleted.

The seeded data: 12 clients, 14 leads, 12 deals spread across all six stages, 12 tasks (a few
deliberately overdue) and 30 timestamped activities.

> On a real hostname the sign-in page does not display these credentials at all. See
> [Sign-in hardening](#sign-in-hardening).

---

## Project structure

```
ClientFlow/
│
├── index.php                     # public landing page — no sign-in required
├── dashboard.php                 # the app, behind require_login()
│
├── auth/                         # sign in, sign out, my profile
│   ├── login.php
│   ├── logout.php
│   ├── profile.php
│   ├── change_password.php       #   forced when the password is still the seeded one
│   └── README.md
│
├── clients/                      # customer accounts
│   ├── index.php                 #   list, search, filter, sort, paginate
│   ├── view.php                  #   one client: deals, tasks, history
│   ├── form.php                  #   create + edit
│   ├── action.php                #   POST: delete
│   └── README.md
│
├── leads/                        # early-stage prospects
│   ├── index.php
│   ├── view.php
│   ├── form.php
│   ├── action.php
│   └── README.md
│
├── pipeline/                     # the Kanban board
│   ├── index.php                 #   board, grouped by stage
│   ├── form.php                  #   create + edit a deal
│   ├── action.php                #   POST: move stage, delete
│   └── README.md
│
├── tasks/                        # follow-ups
│   ├── index.php
│   ├── form.php
│   ├── action.php
│   └── README.md
│
├── activities/                   # interaction history
│   ├── index.php
│   ├── form.php
│   ├── action.php
│   └── README.md
│
├── reports/                      # read-only analytics
│   ├── index.php
│   └── README.md
│
├── admin/                        # admin only (require_admin)
│   ├── users.php
│   ├── user_form.php
│   ├── user_action.php
│   ├── recycle_bin.php           #   deleted records: restore or delete forever
│   ├── recycle_action.php
│   └── README.md
│
├── app/                          # never served over HTTP
│   ├── bootstrap.php             #   the single entry point
│   ├── auth.php                  #   sessions, login, guards
│   ├── tenancy.php               #   which workspace this request belongs to
│   ├── functions.php             #   escaping, CSRF, badges, pagination
│   ├── list_page.php             #   shared list-page markup
│   ├── config/
│   │   ├── config.php            #     constants, APP_URL
│   │   └── database.php          #     PDO singleton
│   ├── models/                   #     every SQL statement
│   │   ├── ListQuery.php         #       shared list-query builder
│   │   ├── SoftDeleteModel.php   #       delete, restore, purge
│   │   ├── LoginAttemptModel.php #       sign-in throttling + audit trail
│   │   ├── TenantModel.php       #       workspaces
│   │   ├── ClientModel.php
│   │   ├── LeadModel.php
│   │   ├── DealModel.php
│   │   ├── TaskModel.php
│   │   ├── ActivityModel.php
│   │   ├── UserModel.php
│   │   └── ReportModel.php
│   └── README.md                 # reading order for a newcomer
│
├── views/                        # presentation shell, never served
│   ├── header.php  footer.php
│   ├── navbar.php  sidebar.php  alerts.php
│   └── README.md
│
├── assets/
│   ├── css/style.css              #   the signed-in shell
│   ├── css/landing.css            #   layered on top, for index.php only
│   ├── js/app.js
│   ├── img/                       # landing-page screenshots of this app
│   └── vendor/                    # Bootstrap 5 + Icons, vendored for offline use
│       ├── css/  js/  fonts/
│       └── README.txt             # versions, licences, local modification
│
├── tools/
│   ├── regression.ps1            # 331-assertion end-to-end suite
│   ├── verify_phase1.ps1         # 26 checks for the sign-in hardening
│   ├── isolation_test.ps1        # 123 checks across two live workspaces
│   ├── check_tenancy.ps1         # static: no query without a tenant filter
│   ├── backup.ps1                # mysqldump to a timestamped file
│   └── README.md
│
├── migrations/                   # ALTER scripts for existing installs
│   ├── 001_login_hardening.sql
│   ├── 002_soft_delete.sql
│   └── 003_multi_tenancy.sql
│
├── .htaccess                     # access rules and legacy URL redirects
├── .gitignore  .gitattributes
├── database.sql                  # schema + demo data
└── README.md
```

Each feature folder has its own `README.md` explaining what the files in it are
for and which model backs it. `app/README.md` gives a reading order, and
`views/README.md` documents the page variables a page sets before including the
header.

### The four rules

- **Pages hold presentation and request handling only.** Any SQL is in `app/models/`.
- **Every page follows the same four steps**: require `app/bootstrap.php`, call
  `require_login()` (or `require_admin()`), set the page variables, include
  `views/header.php` and `views/footer.php`.
- **`form.php` handles both create and edit** — an `id` decides which — and
  **`action.php` handles POST-only actions**, so list pages contain no logic beyond
  display.
- **Every internal link goes through `url()`**, never a bare relative path. That is
  what allowed the pages to move into folders without touching a single href.

### Inside a feature folder

| File | Role |
|---|---|
| `index.php` | The list or main screen |
| `view.php` | One record in detail |
| `form.php` | Create and edit, shared |
| `action.php` | POST-only handlers (delete, status changes) |


---

## Database schema

Eight tables, all InnoDB, all `utf8mb4`, with foreign keys, indexes and automatic timestamps.

```
tenants ──┬──< users ──┬──< clients ──┬──< deals >──┬── leads
          │            │              ├──< tasks     │
          │            │              └──< activities┘
          │            ├──< leads ────┬──< tasks
          │            │              └──< activities
          │            ├──< deals
          │            └──< tasks, activities
          └──< login_attempts (nullable)
```

| Table | Purpose | Key columns |
|---|---|---|
| `tenants` | One row per business using the CRM | unique `slug`, `plan`, `status`, `currency`, `timezone` |
| `users` | Login accounts and record ownership | `tenant_id` → tenants, unique per tenant on `email`, `role` enum, `password_hash`, `is_active`, `must_change_password` |
| `clients` | Customer accounts | `tenant_id`, `company_name`, `contact_person`, `status`, `assigned_to` → users, `deleted_at`, `deleted_by` |
| `leads` | Early-stage prospects | `tenant_id`, `lead_source`, `status`, `estimated_value`, `assigned_to`, `deleted_at`, `deleted_by` |
| `deals` | Pipeline opportunities | `tenant_id`, `stage`, `value`, `client_id`, `lead_id`, `expected_close_date`, `deleted_at`, `deleted_by` |
| `tasks` | Follow-ups | `tenant_id`, `due_date`, `priority`, `status`, optional `client_id` / `lead_id`, `completed_at`, `deleted_at`, `deleted_by` |
| `activities` | Interaction history | `tenant_id`, `type`, `title`, `details`, optional `client_id` / `lead_id`, `created_by`, `deleted_at`, `deleted_by` |
| `login_attempts` | Every sign-in attempt, for throttling | `tenant_id` (nullable), `email`, `ip`, `succeeded` |

Design notes:

- **Every record table carries `tenant_id`.** The id is read from the session and nowhere else —
  never a URL, form field or hidden input — and every query filters on it. `tenant_id()` throws
  rather than returning 0, because `WHERE tenant_id = 0` matches nothing and an empty page is much
  harder to notice than an error. `tools/check_tenancy.ps1` enforces this mechanically and
  `tools/isolation_test.ps1` proves it against two live workspaces. See *Workspaces*.
- `users` is unique on `(tenant_id, email)`, not on `email` globally. That is what lets two
  businesses both have an `admin@company.com`.
- `login_attempts.tenant_id` is nullable and deliberately has no foreign key: failures against an
  unknown workspace are still recorded, with `tenant_id` left null.

- `leads` and `clients` are separate. A lead is an unqualified prospect; a client is an account you
  already trade with.
- `deals` is the pipeline, and can link to a client, a lead, or both. Converting a lead creates a
  deal that keeps pointing back at it, so you can trace where the revenue came from.
- `tasks` and `activities` each have a nullable `client_id` **and** `lead_id`, because a follow-up
  can belong to either. User references use `ON DELETE SET NULL` (delete a staff member, keep the
  records but mark them unassigned).
- The `ON DELETE CASCADE` from `clients` to its deals, tasks and activities is still in the schema,
  but a normal delete no longer triggers it — that only happens on *Delete forever* in the recycle
  bin. See *Nothing is destroyed by deleting it*.
- `users` is deliberately the one table with no `deleted_at`: an account is deactivated with
  `is_active = 0` or removed outright, and neither should be reversible.
- Lookups that run on every list view are indexed: `clients.status`, `clients.assigned_to`,
  `clients.deleted_at`, `leads.status`, `leads.lead_source`, `deals.stage`, `deals.client_id`,
  `tasks.status`, `tasks.due_date`, `activities.client_id`, `activities.created_at`, and
  `deleted_at` on all five record tables.

---

## How the code fits together

Every page follows the same three-step shape:

```php
require_once __DIR__ . '/../app/bootstrap.php';  // config, db, helpers, auth, models
require_login();                                 // auth guard

$errors = take_errors();   // pull validation errors from the last redirect
$old    = take_old();      // pull the submitted values back for redisplay

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();                                // reject tampered submissions
    $errors = client_validate($data);             // validate in the model
    if (!$errors) {
        client_create($data);                     // write via the model
        flash_success('Saved.');                  // queue a message
        redirect('clients/index.php');             // PRG: post/redirect/get
    }
    redirect_with_errors('clients/form.php', $errors, $_POST);  // bounce back
}

$rows = client_list([...]);   // read via the model
require __DIR__ . '/../views/header.php';
// ... HTML ...
require __DIR__ . '/../views/footer.php';
```

Note the `__DIR__ . '/../'` on the requires: a page inside a feature folder is one level down, so
it reaches back up to `app/` and `views/`. `redirect()` and `url()` take paths relative to the
project root, which is why the strings above have no `../` in them.

**Post/Redirect/Get** means a form submission always ends in a redirect. Refreshing the page after
saving does not resubmit, and success messages survive the hop through the session.

### Adding a page

1. Add any queries as functions in the relevant `app/models/*Model.php`.
2. Create the page in the right feature folder — or a new folder if it starts a new module.
3. `require` bootstrap, call `require_login()` (or `require_admin()`), set `$pageTitle`,
   `$pageHeading`, `$activeNav` and `$breadcrumbs`, then include `header.php` and `footer.php`.
4. Put a link to it in `views/sidebar.php`.

### Adding a field

1. Add the column in `database.sql`.
2. Add it to the `SELECT`/`INSERT`/`UPDATE` in the model.
3. Add an `<input>` to the `form.php`, using `old_value($old, $record, 'your_field')` so it
   repopulates after a validation failure.
4. Add the matching length limit to the `length_errors()` call in `*_validate()`.
5. If it is an enum, add the option to the relevant list function in `app/functions.php`.

### Useful helpers

| Helper | Purpose |
|---|---|
| `e($value)` | Escape for HTML output — use on every dynamic value |
| `url($path)` | Build a URL from a project-root-relative path |
| `csrf_field()` / `verify_csrf()` | CSRF token hidden input and check |
| `flash()` / `flash_success()` / `flash_error()` | One-request messages |
| `take_errors()` / `take_old()` / `old_value()` | Validation round-trip |
| `sort_href()` / `render_th()` | Sortable headers with a whitelisted `ORDER BY` |
| `render_filter_bar()` / `render_table_footer()` | Shared list-page markup |
| `render_list_empty_state()` | Empty state that reflects active filters |
| `render_page_actions()` | Header buttons, escaped for you |
| `length_errors()` | Column-width validation |
| `money()` / `money_short()` / `nice_date()` / `time_ago()` | Formatting |
| `client_status_badge()` etc. | Coloured status pills |
| `require_login()` / `require_admin()` / `can_manage($row)` | Authorization |

---

## The landing page

`index.php` is the only page in the project that is **not** behind `require_login()`. It is the
site root, so it is what a visitor sees first and it has three rules of its own.

**It renders no business data.** The whole page is static markup defined in arrays at the top of
the file — feature bullets, workflow steps, FAQ entries. It never calls a model function. That is
deliberate: a public page that is indexed, cached or scraped must not be able to expose a
customer's name, and "it does not query the database" is a guarantee rather than a review item.

**It claims only what the app does.** The Integrations tab is split into what ships and a
*Not built yet* list, and the FAQ answers the awkward ones directly ("Can I export to CSV?" →
"No — `tools/backup.ps1` produces a mysqldump"). A CRM that lists integrations it does not have
is worse than one that admits it, and the repo is public enough that anyone can check.

**It has its own stylesheet, layered on top of the app's.** `index.php` loads `style.css` first,
then `assets/css/landing.css`. That ordering matters: `style.css` owns the brand tokens
(`--cf-primary`) and `.brand-mark`, and loading the page without it makes every `var(--cf-primary)`
invalid. That is not hypothetical — it is how the hero headline first rendered as invisible text,
because `color: transparent` had an invalid gradient behind it. `tools/regression.ps1` now checks
that every custom property `landing.css` reads is actually defined.

Its screenshots in `assets/img/` are real captures of a running installation, taken with Edge
headless against a throwaway copy whose `require_login()` was patched to inject a session. That
copy lived only at `htdocs/_shot`, was restricted to `Require local` while it existed, and was
deleted immediately afterwards — nothing that bypasses authentication is committed.

The dashboard moved to `dashboard.php` to make room. An old bookmark to `/index.php` now lands on
the marketing page, where **Sign in** is the first button.

---

## Security notes

- **Passwords** are stored with `password_hash()` (bcrypt) and checked with `password_verify()`.
  Plaintext passwords are never written or logged.
- **SQL injection** — every query uses PDO prepared statements with bound parameters. Connection
  uses `ATTR_EMULATE_PREPARES => false`. `LIMIT`/`OFFSET` are cast to integers, and `ORDER BY` comes
  from a whitelist (`order_by()`), so a crafted `?sort=` value cannot reach the SQL.
- **XSS** — every dynamic value passes through `e()` (`htmlspecialchars` with `ENT_QUOTES`).
  Wording and attributes both escape correctly, so stored `<script>` payloads render as text.
- **CSRF** — all state-changing forms include a per-session token verified with `hash_equals()`; a
  mismatch returns `403 Forbidden` and changes nothing. (`419` is avoided because Apache does not
  recognise it and silently rewrites it to a generic `500`.)
- **Sessions** — cookies are `HttpOnly` and `SameSite=Lax`, `Secure` when served over HTTPS. The
  session id regenerates on login and every 30 minutes, and idle sessions expire after 2 hours.
- **Authorization** — every page calls `require_login()`; admin areas call `require_admin()`. Staff
  can only modify records they created or are assigned to, enforced in `can_manage()` and checked
  again in the `*_action.php` handlers, not just hidden in the UI.
- **Reads are guarded too.** `can_manage()` originally only gated writes, which left every detail
  page open: a staff member could read any client's email, phone, address and full interaction
  history by walking `?id=1,2,3…`, even with no ability to edit them. `can_view()` now closes the
  two detail pages that exist (`clients/view.php`, `leads/view.php`), and is deliberately a
  separate function from `can_manage()` so a deployment can relax reading without loosening writing.
  **Lists and reports stay org-wide on purpose** — scoping them would break the team performance
  report, which is how a small business knows who is behind. Row-action buttons are hidden when the
  signed-in user cannot use them, so nobody is offered an Edit or Delete that would bounce.
- **Validation** — server-side only, with type, length, format and enum checks. Length limits
  mirror the `VARCHAR` widths in `database.sql` (see `length_errors()`), because the HTML
  `maxlength` attribute is a convenience rather than a control: a direct POST bypasses it and
  would otherwise overflow the column. Enum values from `$_POST` are whitelisted before use;
  foreign keys are confirmed to exist before saving.
- **Error handling** — one handler in `bootstrap.php` catches every uncaught `Throwable`, writes
  the class, file, line and message to the Apache error log, and renders a plain page. No SQL,
  file paths or stack traces reach the browser.
- **Transactions** — the two-step lead→deal conversion runs inside `beginTransaction()` /
  `commit()`, rolled back on failure, so a deal can never exist with its lead unadvanced.
- **Login errors** are deliberately identical for an unknown email and a wrong password, so the
  form cannot be used to discover which accounts exist.
- **Redirects** — post-action `return` values are whitelisted against known internal pages, closing
  off open-redirect abuse.
- **Web exposure** — `.htaccess` disables directory listings and denies `database.sql` (which
  contains password hashes), `README.md` (which contains demo credentials), the `app/` and
  `views/` directories, and any `.git`/`.svn`/`.hg` directory. The internals are never served;
  `database.sql` and `README.md` stay in the web root only because the setup instructions need
  them there.
- **Output** — the database layer catches connection errors, logs the detail server-side and shows
  the user a plain-language troubleshooting page.

### Sign-in hardening

The seeded demo passwords are public knowledge — they appear in `database.sql`, in this README and
in the repository history. Three controls keep that from becoming an account takeover:

- **Demo mode is off on any real hostname.** `DEMO_MODE` (`app/config/config.php`) resolves to `true`
  only on a loopback host, so the demo credentials are never *rendered* on a deployed site and can
  never be used to sign in there. Override it in `app/config/config.local.php`, which is git-ignored.
- **Forced password change.** Every account seeded with a demo password has
  `must_change_password = 1`. `require_login()` holds that account on `auth/change_password.php`
  until the password is replaced, so the published password cannot reach the app. An admin setting
  or resetting someone's password sets the same flag, so the two never both know it.
- **Throttling and lockout.** Every attempt is recorded in `login_attempts`, successful or not.
  Five failures against one address, or twenty from one IP, within fifteen minutes, are refused
  before the password is even compared (`app/models/LoginAttemptModel.php`). A successful sign-in
  clears the counter for that email, so a genuine user who mistypes a few times is not left locked
  out.

An existing installation needs `migrations/001_login_hardening.sql`; a fresh install already has
everything from `database.sql`.

### Nothing is destroyed by deleting it

Deleting a client used to issue a hard `DELETE`, and `deals`, `tasks` and
`activities` were all `ON DELETE CASCADE` from `clients` — so one click destroyed
its deals, its tasks and its entire interaction history, irrecoverably, with
nothing recorded.

Now a delete stamps `deleted_at` and `deleted_by`, and the record is hidden
everywhere: lists, search, detail pages, dashboard aggregates and every report.
`admin/recycle_bin.php` lists what has been deleted and who did it.

- **Restoring is one `UPDATE`.** Children are never stamped, only hidden by a
  join, so restoring a client brings its deals, tasks and history back with it.
  There is no second step that could partially succeed.
- **Delete forever** is the only hard `DELETE` left. The dialog states how many
  linked records go with it, and the server requires the record's exact name —
  checked in PHP, not just in the browser. It can only ever target a row that is
  already deleted.
- Login accounts are not part of this: `users` keeps `is_active` for
  deactivation and has no `deleted_at`.

One consequence worth knowing: **there is still no audit log.** Soft delete
preserves the data and records *that* something was removed and by whom, but not
what it looked like beforehand, or who edited it. Restoring is not a full undo.

### Response headers

`.htaccess` sets `Content-Security-Policy`, `Strict-Transport-Security`, `Permissions-Policy`,
`X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy`.

The CSP is `'self'`-only because every asset is vendored under `assets/` — the app makes no
third-party requests at all. That is what allows `script-src 'self'` with no inline exception, and
it is why the password toggle lives in `assets/js/app.js` rather than an inline `<script>`.
`style-src` still permits `'unsafe-inline'` for the inline style attributes Bootstrap components
expect. The error page loads its stylesheet from `assets/vendor/` too, so a crash neither depends on
the network nor sends the visitor's IP address to a CDN.

### Not included

Email sending, file uploads, remember-me tokens, CSRF-per-request tokens, an audit log of who
changed what, contacts as separate records, and CSV import/export.

The audit log is the most valuable gap. Without it an admin cannot investigate what a staff member
changed or destroyed — and once soft delete lands, restoring a record preserves *what* was lost but
still not *who* removed it.

---

## Backwards compatibility

The pages used to sit loose in the project root (`clients.php`, `login.php`, and so on). The root
`.htaccess` still maps those old URLs onto their new locations, so existing bookmarks and links
keep working. The block is commented and labelled — remove it once nothing points at the old
paths.

---

## Customising it

| Want to change | Where |
|---|---|
| Database credentials | `app/config/config.php` |
| Timezone | `app/config/config.php` (`APP_TIMEZONE`) |
| Rows per page | `app/config/config.php` (`ROWS_PER_PAGE`) |
| Product name | `app/config/config.php` (`APP_NAME`, `APP_SHORT`) |
| Brand colours | `assets/css/style.css` (`:root` variables at the top) |
| Sidebar links | `views/sidebar.php` (`$navItems`) |
| Page-header buttons | `$pageActions` in each page — a structured array, rendered by `render_page_actions()` |
| Column max lengths | `database.sql` **and** the `length_errors()` call in the matching `*_validate()` |
| Client statuses | `app/functions.php` (`client_statuses()`) |
| Pipeline stages | `app/functions.php` (`deal_stages()`) |
| Forecast probabilities | `app/models/ReportModel.php` (`report_win_rate()`) |
| List filters, search columns, sortable columns | the `list_query()` call at the top of each `*_list()` |
| Table columns | `select` in that `list_query()` config, plus the `<thead>` in the page |
| Dashboard tiles | the `$tiles` array near the top of `dashboard.php` |
| Confirmation dialogs | `assets/js/app.js` (`data-confirm` on any button or link) |

---

## Troubleshooting

**"Database connection failed"**
MySQL is not running, the database was never imported, or the credentials in `app/config/config.php`
are wrong. Start MySQL in the XAMPP Control Panel, re-import `database.sql` via phpMyAdmin, then
check the four `DB_*` constants.

**Blank page or "database not selected"**
The import did not complete. Re-run `database.sql` through phpMyAdmin and confirm `clientflow_crm`
appears in the sidebar.

**Styles look broken**
`assets/css/style.css` uses relative paths, so the folder must be served through Apache at
`localhost/clientflow/`. Opening the files directly with `file://` will not work — that is also
true of the app itself, since PHP has to be executed by the server.

**Bootstrap CSS or JS missing**
Both are vendored under `assets/vendor/`, so no internet connection is needed. If they are absent,
look for a 404 on `assets/vendor/css/bootstrap.min.css`. To upgrade, download the files from
jsDelivr and overwrite the copies in `assets/vendor/` — but if you replace
`bootstrap-icons.min.css`, re-apply the font-path fix described in `assets/vendor/README.txt`.

**Icons showing as empty boxes**
Bootstrap Icons is a webfont, so the browser also has to fetch
`assets/vendor/fonts/bootstrap-icons.woff2`. Check the Network tab for a 404 on that file.

**"Request blocked for security reasons" (403)**
The CSRF token did not match — usually the session expired while the form sat open, or two tabs
submitted the same form. Reload the page and try again. Nothing was saved.

**Session not sticking**
Usually a stale cookie from an earlier run. Clear cookies for `localhost` in the browser, then log
in again.

**Import fails with "Unknown collation"**
Your server is older than MySQL 5.7. Either use a newer XAMPP, or replace `utf8mb4_unicode_ci`
with `utf8_general_ci` throughout `database.sql`.

**Want a clean slate**
Re-import `database.sql`. It drops and recreates everything back to the demo state.

---

## Running the tests

`tools/regression.ps1` drives the running site over HTTP and asserts on real behaviour: every
page under both roles, CRUD lifecycles, validation rejections, CSRF, stored and reflected XSS, SQL
injection attempts, the forced password change, sign-in throttling, soft delete and restore, the
landing page, flash messages, accessibility spot checks and orphaned rows. It also reads the Apache
error log after each request rather than trusting the response body, because `display_errors` can be
on and still hide warnings from a body-level scan.

```powershell
# XAMPP running, project in htdocs, database.sql imported
powershell -ExecutionPolicy Bypass -File tools\regression.ps1
```

It cleans up everything it creates, restores the seeded demo state — including the
`must_change_password` flags and the `login_attempts` table — and exits non-zero on failure, so it
works as a pre-commit check. It needs PowerShell 5.1 (bundled with Windows) and `mysql.exe` on the
path as `C:\xampp\mysql\bin\mysql.exe`; override with `-BaseUrl` if your folder is named
differently.

### The suite signs in with accounts it creates

Both scripts create their own throwaway accounts — `regression-admin@`, `regression-staff@`,
`forced@` and the two `verify-@` accounts — and delete them afterwards. **None of them depend on
the demo passwords**, which matters because the app forces every seeded account to change its
password: using the demo accounts meant that following the app's own instructions broke the tests
with `ABORT: admin login failed`, and the suite had to reach into the seeded accounts (clearing
`must_change_password`, then restoring it) just to get in.

Nothing is ever overwritten that the script did not create. It deliberately does not reset a
password: quietly replacing the admin password of whatever database it is pointed at would be worse
than failing the run. `verify_phase1.ps1` does have to set a new password to test the forced-change
flow, which is exactly why it does that on its own account.

Two further guards, both added after they were found to be missing:

- **A pre-run sweep.** Anything a previous aborted run left behind is cleared *before* the baseline
  is captured, otherwise a leftover row counts as part of the baseline and the suite cannot be run
  twice in a row.
- **The seeded accounts are snapshotted and restored.** The last-admin test attempts a demotion;
  before the restore existed, a run where that attempt wrongly succeeded left the demo admin as a
  staff user for every subsequent run. Cleanup now puts the seeded accounts back from the snapshot
  and asserts it worked, so a failed test cannot corrupt the demo data.

`tools/verify_phase1.ps1` covers the sign-in hardening workstream on its own (26 checks) and is
handy when changing anything in `app/auth.php` or `app/models/LoginAttemptModel.php`.

### The two tenant tools

Neither of the other suites can catch a missing tenant filter. With one workspace every page renders
correctly whether or not the query is scoped, so 331 passing assertions say nothing about isolation.

```powershell
# Static: resolve each statement's base table, require the predicate on its own alias.
powershell -ExecutionPolicy Bypass -File tools\check_tenancy.ps1

# Behavioural: two real workspaces, 123 assertions, self-cleaning.
powershell -ExecutionPolicy Bypass -File tools\isolation_test.ps1
```

`check_tenancy.ps1` needs no running site and no database. `isolation_test.ps1` drives the running
site and creates its own throwaway workspaces, which it removes afterwards.

Both were verified by deliberately breaking the code, because a check that cannot fail is worse
than no check. Doing that turned up three things worth knowing:

- Deleting a `'tenant'` key does not leak. `list_query`'s default alias is `t`, so a query aliased `c`
  gets an unknown-column error and a 500. It fails closed — which is why `isolation_test.ps1`
  treats an erroring page as a failure rather than as "no marker found".
- Interpolated table names (`SoftDeleteModel`'s `UPDATE {$meta['table']}`) were invisible to the
  static checker, which needed a literal word after the SQL keyword. That was the one file where
  blindness cost the most.
- The isolation test leaked its own sign-in attempts, which eventually tripped the deliberately
  global per-IP limit and locked the test itself out.

`isolation_test.ps1` covers data access, not the admin-management guard: making `user_admin_count()`
global again does not fail it, because no section demotes an admin across workspaces. The static
check is what covers that one.

---

## Migrations

Fresh installs get everything from `database.sql`. An **existing** installation picks up changes
from `migrations/`, imported in order through phpMyAdmin:

| File                          | Adds                                                |
|-------------------------------|-----------------------------------------------------|
| `001_login_hardening.sql`     | `users.must_change_password`, `login_attempts`      |
| `002_soft_delete.sql`         | `deleted_at` / `deleted_by` on the five record tables |
| `003_multi_tenancy.sql`       | `tenants`, `tenant_id` on every record table, per-tenant email uniqueness |

Each file is idempotent — running it twice is a no-op — and none of them drop data.

> Run `003_multi_tenancy.sql` **before** deploying the multi-tenant code. The application filters
> every query on `tenant_id` and will error without the column. It adopts your existing rows into a
> single workspace called `my-business`, so nothing needs re-entering.

---

## Workspaces

ClientFlow is multi-tenant. One deployment serves many businesses, and each sees only its own
records.

Every table that holds CRM data carries a `tenant_id`, and the value comes from one place:
`app/tenancy.php` reads it from the session. **No page may take a tenant id from a URL, a form
field or a hidden input.** A page that accepted `?tenant_id=` would be one forgotten parameter away
from showing a stranger's customers, which is the whole risk the design exists to remove.

Sign-in is workspace slug plus email. `attempt_login()` resolves the workspace first, then looks up
the user *within* it. An unknown workspace produces exactly the same message as a wrong password, so
the form cannot be used to discover which slugs exist.

Two guards exist because one is not enough:

- `tools/check_tenancy.ps1` resolves each statement's base table and requires the tenant predicate on
  that table's own alias in the outer `WHERE`. Scoping a `JOIN` and scoping the base table are
  different things, and only the second filters rows.
- `tools/isolation_test.ps1` creates two real workspaces, signs in to each, and asserts neither can
  read or write the other's data — including the case where every list looks correct but the writes
  address rows by bare `id`.

Sign-in throttling is keyed on `(tenant_id, email)`, so one workspace's failed guesses cannot lock
out another workspace's account with the same address. The per-IP limit stays global on purpose, to
stop a distributed run of guesses.

### Adding a model function

Every query against a tenant-scoped table needs the filter. Two shapes:

```php
// Explicit: normalise it, because a missing predicate leaks another tenant's rows.
$stmt = db()->prepare('SELECT * FROM clients WHERE tenant_id = ? AND id = ?');
$stmt->execute([tenant_id(), $id]);

// Or route through list_query, declaring which alias to filter on.
return list_query([
    'from'   => 'FROM clients c ...',
    'soft_delete' => ['c'],
    'tenant'      => ['c'],   // must match the alias in 'from'
]);
```

Then run `tools/check_tenancy.ps1`. It fails if the predicate is missing — including the case where
you delete the `'tenant'` key entirely, which makes the builder's default alias (`t`) apply to a
query aliased something else. That produces an unknown-column error rather than a leak: it fails
closed.

---

## Licence

Provided as-is for learning and portfolio use. Bootstrap 5 and Bootstrap Icons are MIT licensed and
vendored under `assets/vendor/` — see `assets/vendor/README.txt` for versions, sources and the one
local modification made.
