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
8. [Security notes](#security-notes)
9. [Customising it](#customising-it)
10. [Troubleshooting](#troubleshooting)

---

## Features

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
C:\xampp\htdocs\ClientFlow\database.sql
```

On macOS it is `/Applications/XAMPP/htdocs/ClientFlow/`, on Linux `/opt/lampp/htdocs/ClientFlow/`.
The folder name can be anything — the app uses relative paths throughout.

### 3. Create the database

1. Go to <http://localhost/phpmyadmin>
2. Click the **Import** tab
3. **Choose File** → select `ClientFlow/database.sql`
4. Leave **Character set of the file** as `utf-8` (the file sets `utf8mb4` itself)
5. Click **Go** / **Import**

The script creates the `clientflow_crm` database, all six tables and the demo data. You should see
`clientflow_crm` appear in the left sidebar.

> The SQL file starts with `DROP TABLE IF EXISTS`, so re-importing resets the database to the demo
> state. That is convenient during setup, but it also deletes anything you have entered.

### 4. Check the connection settings

Open `ClientFlow/config/config.php`. The defaults match a stock XAMPP install:

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

| Role  | Email                   | Password    |
|-------|-------------------------|-------------|
| Admin | `admin@clientflow.test` | `admin123`  |
| Staff | `sarah@clientflow.test` | `staff123`  |
| Staff | `marcus@clientflow.test`| `staff123`  |
| Staff | `priya@clientflow.test` | `staff123`  |

Sign in as the admin to see everything, then as Sarah to see the reduced permission set: the Users
section disappears from the sidebar, and records belonging to other staff cannot be edited or
deleted.

The seeded data: 12 clients, 14 leads, 12 deals spread across all six stages, 12 tasks (a few
deliberately overdue) and 30 timestamped activities.

---

## Project structure

```
ClientFlow/
├── config/
│   ├── config.php            # constants: DB credentials, app name, timezone
│   └── database.php          # PDO connection singleton
│
├── includes/
│   ├── bootstrap.php         # single entry point, required by every page
│   ├── functions.php         # escaping, flashes, CSRF, badges, pagination
│   ├── auth.php              # session hardening + require_login/require_admin/can_manage
│   ├── header.php            # <head>, page title, breadcrumbs, opens <main>
│   ├── navbar.php            # top bar with user dropdown
│   ├── sidebar.php           # navigation links (shared: desktop + mobile)
│   ├── alerts.php            # flash message rendering
│   └── footer.php            # closes <main>, footer, confirm modal, scripts
│
├── models/                   # every SQL statement lives here
│   ├── UserModel.php
│   ├── ClientModel.php
│   ├── LeadModel.php
│   ├── DealModel.php
│   ├── TaskModel.php
│   ├── ActivityModel.php
│   └── ReportModel.php
│
├── assets/
│   ├── css/style.css         # custom styles layered on Bootstrap
│   ├── js/app.js             # confirm dialogs, counters, auto-dismiss
│   └── vendor/               # Bootstrap 5 + Icons, vendored so the app works offline
│       ├── css/bootstrap.min.css
│       ├── css/bootstrap-icons.min.css
│       ├── js/bootstrap.bundle.min.js
│       ├── fonts/bootstrap-icons.woff2
│       ├── fonts/bootstrap-icons.woff
│       └── README.txt        # versions, licences, local modification
│
├── index.php                 # dashboard
├── login.php  logout.php  profile.php
├── clients.php  client_form.php  client_view.php  client_action.php
├── leads.php    lead_form.php    lead_view.php    lead_action.php
├── pipeline.php deal_form.php    deal_action.php
├── tasks.php    task_form.php    task_action.php
├── activities.php  activity_form.php  activity_action.php
├── reports.php
├── users.php    user_form.php    user_action.php
│
├── database.sql              # schema + demo data
└── README.md
```

Two conventions keep this readable:

- **Pages hold presentation and request handling only.** Any SQL is in `models/`.
- **`*_form.php` handles both create and edit**, and **`*_action.php` handles POST-only actions**
  such as delete or status change, so list pages contain no logic beyond display.

---

## Database schema

Six tables, all InnoDB, all `utf8mb4`, with foreign keys, indexes and automatic timestamps.

```
users ──┬──< clients ──┬──< deals >──┬── leads
        │              ├──< tasks     │
        │              └──< activities┘
        ├──< leads ────┬──< tasks
        │              └──< activities
        ├──< deals
        └──< tasks, activities
```

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Login accounts and record ownership | unique `email`, `role` enum, `password_hash`, `is_active` |
| `clients` | Customer accounts | `company_name`, `contact_person`, `status`, `assigned_to` → users |
| `leads` | Early-stage prospects | `lead_source`, `status`, `estimated_value`, `assigned_to` |
| `deals` | Pipeline opportunities | `stage`, `value`, `client_id`, `lead_id`, `expected_close_date` |
| `tasks` | Follow-ups | `due_date`, `priority`, `status`, optional `client_id` / `lead_id`, `completed_at` |
| `activities` | Interaction history | `type`, `title`, `details`, optional `client_id` / `lead_id`, `created_by` |

Design notes:

- `leads` and `clients` are separate. A lead is an unqualified prospect; a client is an account you
  already trade with.
- `deals` is the pipeline, and can link to a client, a lead, or both. Converting a lead creates a
  deal that keeps pointing back at it, so you can trace where the revenue came from.
- `tasks` and `activities` each have a nullable `client_id` **and** `lead_id`, because a follow-up
  can belong to either. Foreign keys use `ON DELETE CASCADE` here (delete the client, lose its
  activity history) and `ON DELETE SET NULL` for user references (delete a staff member, keep the
  records but mark them unassigned).
- Lookups that run on every list view are indexed: `clients.status`, `clients.assigned_to`,
  `leads.status`, `leads.lead_source`, `deals.stage`, `deals.client_id`, `tasks.status`,
  `tasks.due_date`, `activities.client_id`, `activities.created_at`.

---

## How the code fits together

Every page follows the same three-step shape:

```php
<?php
require_once __DIR__ . '/includes/bootstrap.php';   // config, db, helpers, session
require_login();                                    // auth guard

$errors = take_errors();   // pull validation errors from the last redirect
$old    = take_old();      // pull the submitted values back for redisplay

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();                                // reject tampered submissions
    $errors = client_validate($data);             // validate in the model
    if (!$errors) {
        client_create($data);                     // write via the model
        flash_success('Saved.');                  // queue a message
        redirect('clients.php');                  // PRG: post/redirect/get
    }
    redirect_with_errors('client_form.php', $errors, $_POST);  // bounce back
}

$rows = client_list([...]);   // read via the model
require __DIR__ . '/includes/header.php';
// ... HTML ...
require __DIR__ . '/includes/footer.php';
```

**Post/Redirect/Get** means a form submission always ends in a redirect. Refreshing the page after
saving does not resubmit, and success messages survive the hop through the session.

### Adding a page

1. Add any queries as functions in the relevant `models/*Model.php`.
2. Create the page in the project root.
3. `require` bootstrap, call `require_login()` (or `require_admin()`), set `$pageTitle`,
   `$pageHeading`, `$activeNav` and `$breadcrumbs`, then include `header.php` and `footer.php`.

### Adding a field

1. Add the column in `database.sql`.
2. Add it to the `SELECT`/`INSERT`/`UPDATE` in the model.
3. Add an `<input>` to the `*_form.php`, using `old_value($old, $record, 'your_field')` so it
   repopulates after a validation failure.
4. If it is an enum, add the option to the relevant list function in `functions.php` and to
   `*_validate()`.

### Useful helpers

| Helper | Purpose |
|---|---|
| `e($value)` | Escape for HTML output — use on every dynamic value |
| `csrf_field()` / `verify_csrf()` | CSRF token hidden input and check |
| `flash()` / `flash_success()` / `flash_error()` | One-request messages |
| `take_errors()` / `take_old()` / `old_value()` | Validation round-trip |
| `sort_link()` / `order_by()` | Sortable headers with a whitelisted `ORDER BY` |
| `render_pagination()` / `result_summary()` | Pagination UI |
| `empty_state()` | Consistent empty-state block |
| `money()` / `money_short()` / `nice_date()` / `time_ago()` | Formatting |
| `client_status_badge()` etc. | Coloured status pills |
| `require_login()` / `require_admin()` / `can_manage($row)` | Authorization |

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
- **Validation** — server-side only, with type, length, format and enum checks. Enum values from
  `$_POST` are whitelisted before use; foreign keys are confirmed to exist before saving.
- **Login errors** are deliberately identical for an unknown email and a wrong password, so the
  form cannot be used to discover which accounts exist.
- **Redirects** — post-action `return` values are whitelisted against known internal pages, closing
  off open-redirect abuse.
- **Output** — the database layer catches connection errors, logs the detail server-side and shows
  the user a plain-language troubleshooting page.

Not included, because this is a local portfolio project: email sending, file uploads, rate
limiting, remember-me tokens, CSRF-per-request tokens, and an audit log of who changed what.

---

## Customising it

| Want to change | Where |
|---|---|
| Database credentials | `config/config.php` |
| Timezone | `config/config.php` (`APP_TIMEZONE`) |
| Rows per page | `config/config.php` (`ROWS_PER_PAGE`) |
| Brand colours | `assets/css/style.css` (`:root` variables at the top) |
| Sidebar links | `includes/sidebar.php` (`$navItems`) |
| Client statuses | `functions.php` (`client_statuses()`) |
| Pipeline stages | `functions.php` (`deal_stages()`) |
| Forecast probabilities | `models/ReportModel.php` (`report_win_rate()`) |
| Table columns | `SELECT` in the matching model + the `<thead>` in the page |
| Dashboard tiles | the `$tiles` array near the top of `index.php` |
| Product name | `config/config.php` (`APP_NAME`, `APP_SHORT`) |

---

## Troubleshooting

**"Database connection failed"**
MySQL is not running, the database was never imported, or the credentials in `config/config.php` are
wrong. Start MySQL in the XAMPP Control Panel, re-import `database.sql` via phpMyAdmin, then check
the four `DB_*` constants.

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

## Licence

Provided as-is for learning and portfolio use. Bootstrap 5 and Bootstrap Icons are MIT licensed and
vendored under `assets/vendor/` — see `assets/vendor/README.txt` for versions, sources and the one
local modification made.
