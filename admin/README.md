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
