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
| `auth.php` | Session hardening, `attempt_login()` (with throttling), `logout_user()`, `require_login()` (with the forced password change), `require_admin()`, `can_manage()`, `can_view()` |
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
5. `models/ClientModel.php` — a complete example of the data layer
6. `models/ListQuery.php` — how the list screens share one query builder
7. `models/SoftDeleteModel.php` — why nothing is destroyed by deleting it

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

Lists and reports are **not** scoped. They stay org-wide on purpose, because
narrowing them would break the team performance report.

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
