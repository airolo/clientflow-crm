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
| `functions.php` | Output escaping, flash messages, CSRF, length validation, badges, pagination, formatting, `url()` |
| `list_page.php` | `render_filter_bar()`, `render_th()`, `render_post_form_open()`, `render_table_footer()`, `render_list_empty_state()`, `render_page_actions()` |
| `config/config.php` | Constants: database credentials, app name, timezone, `APP_URL`, `DEMO_MODE` |
| `config/database.php` | The PDO singleton |
| `models/` | Every SQL statement in the project |

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

## The one path that matters

`bootstrap.php` lives in `app/`, so `config/` and `models/` are its **siblings**,
not its children. Requires there read `__DIR__ . '/models/...'` and
`__DIR__ . '/config/...'`. Pages one level down use
`__DIR__ . '/../app/bootstrap.php'`.

## APP_URL

`config/config.php` works out where the project is served from by comparing its
own location against `DOCUMENT_ROOT`, so it is correct at a web root (`''`) and
in a subfolder (`/ClientFlow`) without being configured.

Every internal link goes through `url()`. Never write a bare relative path: it
would break the moment a page moved between folders, which is exactly what
happened when the project was reorganised into feature folders.
