# Admin

User and role management. Every page here calls `require_admin()` before doing
anything else, so a staff member is redirected to the dashboard rather than shown
a partially-rendered page.

| File | Route | Role |
|---|---|---|
| `users.php` | `/admin/users.php` | List accounts, with per-user workload counts |
| `user_form.php` | `/admin/user_form.php[?id=1]` | Create and edit an account |
| `user_action.php` | POST only | Create (from the list modal) and delete |

## Guards

Three things are refused deliberately, each because they would lock everyone out
or hand over privilege:

- You cannot delete your own account.
- You cannot demote or disable the last active admin.
- `role` is checked against `['admin', 'staff']`, so a crafted POST cannot invent
  a third role.

## The create-user modal

`users.php` posts to `user_action.php`, which bounces back with validation
errors. Because those errors render *inside* the modal, the page sets
`data-reopen-modal="newUserModal"` on `<body>` and `assets/js/app.js` reopens it —
otherwise a rejected form would appear to do nothing at all.

Data access is `app/models/UserModel.php`.
