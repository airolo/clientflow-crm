# Auth

Sign in, sign out and the signed-in user's own profile.

| File | Route | Role |
|---|---|---|
| `login.php` | `/auth/login.php` | Sign in. The only page reachable without a session |
| `logout.php` | POST only | Sign out. POST-only so a stray link or prefetch cannot log you out |
| `profile.php` | `/auth/profile.php` | View and edit your own details; change your password |

The shared session machinery — hardened cookie flags, idle timeout, id
regeneration, `attempt_login()`, `require_login()`, `require_admin()` and the
ownership check `can_manage()` — is in `app/auth.php`. Which workspace a session
belongs to comes from `app/tenancy.php`. These three pages are just the screens
for it.

## Signing in

`attempt_login()` takes three values: **workspace slug, email, password**. The
slug is not decoration. Users are unique on `(tenant_id, email)` rather than on
`email` alone, so two businesses may legitimately both have an
`admin@company.com` and their accounts must stay separate. Resolving the
workspace first is what keeps them separate.

An unknown workspace returns the same message as a wrong password, so the form
cannot be used to discover which slugs exist. Throttling is keyed on
`(tenant_id, email)` for the same reason, with the per-IP limit left global.

## Roles

`admin` and `staff`. Admins reach `/admin/` and can modify any record; staff can
only modify records they created or are assigned to. The check is `can_manage()`,
applied again in every `action.php`, not just used to hide buttons.

A staff member cannot escalate: `role` is validated against a fixed list on the
way in, and the last active admin cannot be demoted, disabled or deleted. That
count is per workspace — a global count would let one business's admin activity
decide whether another could demote its own last admin.

`login.php` renders its own `<head>` rather than using `views/header.php`,
because it is the one page with no navbar, sidebar or session.
