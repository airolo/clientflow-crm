# Activities

The interaction history: calls, emails, meetings and notes against a client or a lead.

| File | Route | Role |
|---|---|---|
| `index.php` | `/activities/` | Filterable history across the whole CRM: by type, who logged it, and date range |
| `form.php` | `/activities/form.php[?id=1\|?client_id=N\|?lead_id=N]` | Log or edit one interaction |
| `action.php` | POST only | Delete |

## Types

`call`, `email`, `meeting`, `note` — an enum in `database.sql`, listed by
`activity_types()`.

## This is not an audit log

Activities are user-authored relationship notes: someone decides what is worth
recording, and anyone who owns the record can edit or delete it. They are the
CRM's memory of the relationship, **not** a tamper-evident history of who changed
what. The app has no audit trail; see the Security section of the root README
for why that is a deliberate omission.

## Return path

`form.php` works out where to send you afterwards from the `client_id` or
`lead_id` in the URL, so editing an activity you reached from a client page
takes you back to that client rather than the global list.

Data access is `app/models/ActivityModel.php`.
