# Admin

User and role management. Every page here calls `require_admin()` before doing
anything else, so a staff member is redirected to the dashboard rather than shown
a partially-rendered page.

| File | Route | Role |
|---|---|---|
| `users.php` | `/admin/users.php` | List accounts, with per-user workload counts |
| `user_form.php` | `/admin/user_form.php[?id=1]` | Create and edit an account |
| `user_action.php` | POST only | Create (from the list modal) and delete |
| `recycle_bin.php` | `/admin/recycle_bin.php[?type=client]` | Deleted records, with restore and delete-forever |
| `recycle_action.php` | POST only | Restore, or purge permanently |
| `settings.php` | `/admin/settings.php` | This workspace's name, currency and timezone |

## Guards

Four things are refused deliberately, each because they would lock everyone out
or hand over privilege:

- You cannot delete your own account.
- You cannot demote or disable the last active admin.
- `role` is checked against `['admin', 'staff']`, so a crafted POST cannot invent
  a third role.
- A user account is never soft-deleted — `is_active = 0` deactivates it, so
  `users` has no `deleted_at` and never appears in the bin.

Setting or resetting a password sets `must_change_password = 1`, which holds the
account on `auth/change_password.php` until it chooses its own. See the
`Sign-in hardening` section of the root README.

## The create-user modal

`users.php` posts to `user_action.php`, which bounces back with validation
errors. Because those errors render *inside* the modal, the page sets
`data-reopen-modal="newUserModal"` on `<body>` and `assets/js/app.js` reopens it —
otherwise a rejected form would appear to do nothing at all.

## The recycle bin

`recycle_bin.php` is the answer to the fact that deleting a client used to
cascade through to its deals, tasks and whole activity history, irreversibly.
A delete now stamps `deleted_at` / `deleted_by`; the row and all of its children
stay in the database and become invisible together.

- **Restore** clears the stamp. Because children were never touched, restoring a
  client brings its deals, tasks and activities straight back — there is no
  second step that could half-succeed.
- **Delete forever** is the only hard `DELETE` left in the app. It is the one
  irreversible action, so the dialog states how many linked records will go with
  it and the server requires the record's exact name in `confirm`. The
  client-side check in `app.js` only stops misclicks; `recycle_action.php`
  re-checks it and never trusts the browser.
- Purging can only ever touch a row that is *already* deleted
  (`... WHERE id = ? AND deleted_at IS NOT NULL`), so the endpoint cannot be
  aimed at live data by a crafted POST.

`type` is whitelisted against `soft_delete_types()`, so an unknown type is
rejected rather than interpolated into SQL. All of the behaviour lives in
`app/models/SoftDeleteModel.php`.

Data access is `app/models/UserModel.php`.

## Workspace settings

`settings.php` is the odd one out here: there is no admin page for editing
*another* workspace, and there will not be one. There is no workspace id in the
form at all — the id comes from the session — because a page that could edit any
workspace would undo the tenancy work rather than build on it.

The slug is displayed but not editable. People sign in with it, so changing it
would lock them out until you were told the new one.

It validates the currency against `tenant_currencies()` and the timezone against
PHP's own identifier list rather than against the curated dropdown, so a
workspace in a region nobody thought of is not blocked from setting its zone.

It also prints the current time for the workspace with its UTC offset. That is
not decoration: without something visible, "saved" and "actually applied" are
indistinguishable, and `tools\settings_test.ps1` had no way to tell them apart.
