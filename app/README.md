# app

Everything that is not a page: configuration, the database connection, session
handling, helpers, the model layer and the shared list-page markup.

`app/` is never served over HTTP — the root `.htaccess` and `app/.htaccess` both
deny it. PHP loads these files from the filesystem with `require_once`; a browser
never requests them.

## Layout

| File | Responsibility |
|---|---|
| `bootstrap.php` | The single entry point. Every page requires this and nothing else. Loads config → database → helpers → models, installs the exception handler, starts the session |
| `auth.php` | Session hardening, `attempt_login()` (workspace + email + password, with throttling), `logout_user()`, `require_login()` (with the forced password change), `require_admin()`, `can_manage()`, `can_view()` |
| `tenancy.php` | Which workspace the request belongs to: `tenant_current()`, `tenant_id()`, `require_active_tenant()` |
| `csv.php` | CSV output: `csv_formula_safe()`, `csv_send_headers()`, `csv_export_filename()` |
| `functions.php` | Output escaping, flash messages, CSRF, length validation, badges, pagination, formatting, `url()` |
| `list_page.php` | `render_filter_bar()`, `render_th()`, `render_post_form_open()`, `render_table_footer()`, `render_list_empty_state()`, `render_page_actions()` |
| `config/config.php` | Constants: database credentials, app name, timezone, `APP_URL`, `DEMO_MODE` |
| `config/database.php` | The PDO singleton |
| `models/` | Every SQL statement in the project |
| `models/AuditModel.php` | Writing and reading `audit_log`: `audit_record()`, `audit_diff()`, `audit_record_update()`, `audit_list()`, `audit_for_entity()` |
| `views/audit_history.php` | The per-record "Change history" card. `require`d by a detail page after it sets `$auditEntityType` and `$auditEntityId`, never routed to directly |

## Reading order for a newcomer

1. `config/config.php` — the settings
2. `database.php` — one connection, exception mode, native prepares
3. `functions.php` — the vocabulary (`e()`, `url()`, `flash()`, `csrf_field()`)
4. `auth.php` — who is allowed to do what
5. `tenancy.php` — which workspace this request belongs to
6. `models/ClientModel.php` — a complete example of the data layer
7. `models/ListQuery.php` — how the list screens share one query builder
8. `models/SoftDeleteModel.php` — why nothing is destroyed by deleting it

## Tenancy

`tenancy.php` answers one question — which business does this request belong to
— so no page has to decide for itself.

**The tenant id comes from the session and nowhere else.** Not a URL parameter,
not a form field, not a hidden input. A page that accepted `?tenant_id=` would be
one forgotten parameter away from showing a stranger's customers, which is the
entire risk this design exists to remove.

`tenant_id()` throws rather than returning 0. `WHERE tenant_id = 0` matches
nothing, so the page would render empty rather than broken, and an empty page is
much harder to notice than an error. `tenant_current()` re-reads the workspace
from the database on each request, so one suspended mid-session stops working
immediately rather than in two hours when the idle timeout fires.

Two things enforce the filtering, because review alone does not survive 96
queries:

- `tools/check_tenancy.ps1` resolves each statement's base table and requires the
  predicate on that table's own alias in the outer `WHERE`. Scoping a `JOIN` and
  scoping the base table are different things — `client_find()` had both its
  joins scoped while its `WHERE` was open, and would still have leaked.
- `tools/isolation_test.ps1` creates two workspaces and proves neither can read
  or write the other's data, including writes that address rows by bare `id`.

## can_manage() versus can_view()

Both answer an ownership question, and they are deliberately separate functions
rather than one calling the other:

- `can_manage()` — may this person **change** this record? Checked in the edit
  forms and again in every `*_action.php`, so it is a server-side control rather
  than a hidden button.
- `can_view()` — may this person **read** this record's detail page? Added
  because `can_manage()` only ever gated writes, which left `clients/view.php`
  and `leads/view.php` open to anyone signed in: walking `?id=1,2,3…` exposed
  every client's email, phone, address and interaction history.

They apply the same rule today. Keeping them apart means a deployment can let
staff read the whole CRM while still only editing their own records — which is
often what a small business wants — without touching the write guard.

Lists and reports are **not** scoped to the individual user. They stay
org-wide on purpose, because narrowing them would break the team performance
report. They *are* scoped to the workspace, like everything else.

## Soft delete

`deleted_at` / `deleted_by` on the five record tables; `users` is excluded,
since an account is deactivated with `is_active` or removed outright and
neither should be reversible.

The live-row filter lives in `list_query()` rather than being repeated in every
query, because a single forgotten `WHERE` would silently show deleted records.
`SoftDeleteModel` holds the stamp/restore/purge behaviour and the type map that
keeps table names from being scattered as strings.

`soft_delete_find()` only matches rows already in the bin, which is what every
caller of it wants. `soft_delete_read_any()` is the counterpart used just before
a row enters it — using the bin-scoped read there would return null for a live
row and the audit entry would be silently lost.

## Audit log

`audit_record()` never throws. An audit failure must not roll back a business
operation that already succeeded: losing an edit because the log was full would
be a worse outcome than a missing row, so failures go to `error_log()` instead.

Callers pass the *before* state, not a computed diff. `audit_diff()` does the
comparison, so the "what changed" decision lives in one place rather than being
re-derived at every call site — which is how two paths end up disagreeing about
whether something changed.

Read the record first, write second. `client_update()`, `deal_move()`,
`task_set_status()` and friends all fetch the row before updating it, because
after the update the old value is gone.

Three PHP traps this code walks into on purpose, each noted at the call site:

- `$before + ['status' => $x]` **discards** `$x`. The `+` operator keeps the left
  operand's value for a key it already has. Use `array_merge()` when merging new
  values onto a fetched row.
- `null` and `''` both mean "empty" in `audit_diff()`, so clearing an already
  empty field is not a change.
- A failed sign-in against a nonexistent workspace slug has no tenant to file
  under and `tenant_id()` throws without a session. `audit_record()` returns
  early rather than inventing one; `login_attempts` covers that case.

## The one path that matters

`bootstrap.php` lives in `app/`, so `config/` and `models/` are its **siblings**,
not its children. Requires there read `__DIR__ . '/models/...'` and
`__DIR__ . '/config/...'`. Pages one level down use
`__DIR__ . '/../app/bootstrap.php'`.

## Chart bars have two shapes

`.chart-bar-fill` is a `border-radius: 999px` **pill**, written for the thin
horizontal progress bars (10px tall) on the dashboard and reports pages.

The monthly-activity chart uses the same class for **vertical columns**, and that
is wrong in a way that is invisible in code review. `999px` clamps to half the
element's shortest side, so on a 105px-wide column the top corners round to ~52px
— a dome. The taller the month, the taller the dome, so a large value reads as a
circle rather than a bar. Measured in Edge before the fix: `w=113 h=186
rTL=999px`.

Columns therefore use `.chart-bar-fill.column`, which is square at the base and
rounded only at the top:

```css
.chart-bar-fill.column { height: auto; border-radius: 4px 4px 0 0; }
```

`height: auto` is in that rule because the base class hard-codes
`height: 100%`, which would otherwise override the inline `height:` percentage
the reports page sets on each column. `regression.ps1` asserts both the CSS and
that the reports page renders the `column` class.

## APP_URL

`config/config.php` works out where the project is served from by comparing its
own location against `DOCUMENT_ROOT`, so it is correct at a web root (`''`) and
in a subfolder (`/ClientFlow`) without being configured.

Every internal link goes through `url()`. Never write a bare relative path: it
would break the moment a page moved between folders, which is exactly what
happened when the project was reorganised into feature folders.

That rule has been broken twice, both times the same way, so it is worth being
precise about *why* it bites. A path like `tasks/form.php` is project-relative,
but from `/tasks/index.php` the browser resolves it against the current
directory and asks for `/tasks/tasks/form.php`. `render_page_actions()` and the
breadcrumb loop in `views/header.php` both emitted their href raw, so every
list page's buttons were 404s. They go through `url()` now, and
`tools\regression.ps1` clicks every one of them.

The one deliberate exception is `render_th()`'s sort link, which stays relative.
`sort_href()` returns a query-only string (`?sort=title&dir=asc`) that has to
keep working on the page you are already on; prefixing `APP_URL` would send
every column header to the app root instead of the list being viewed.
