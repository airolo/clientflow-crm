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
| `auth.php` | Session hardening, `attempt_login()`, `logout_user()`, `require_login()`, `require_admin()`, `can_manage()` |
| `functions.php` | Output escaping, flash messages, CSRF, length validation, badges, pagination, formatting, `url()` |
| `list_page.php` | `render_filter_bar()`, `render_th()`, `render_post_form_open()`, `render_table_footer()`, `render_list_empty_state()`, `render_page_actions()` |
| `config/config.php` | Constants: database credentials, app name, timezone, `APP_URL` |
| `config/database.php` | The PDO singleton |
| `models/` | Every SQL statement in the project |

## Reading order for a newcomer

1. `config/config.php` — the settings
2. `database.php` — one connection, exception mode, native prepares
3. `functions.php` — the vocabulary (`e()`, `url()`, `flash()`, `csrf_field()`)
4. `auth.php` — who is allowed to do what
5. `models/ClientModel.php` — a complete example of the data layer
6. `models/ListQuery.php` — how the list screens share one query builder

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
