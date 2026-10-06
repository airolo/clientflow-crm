# Clients

Everything about customer accounts.

| File | Route | Role |
|---|---|---|
| `index.php` | `/clients/` | List: search across company/contact/email/phone, filter by status and owner, sortable columns, pagination |
| `view.php` | `/clients/view.php?id=1` | One client: contact details, notes, and their deals, tasks and full interaction history |
| `form.php` | `/clients/form.php[?id=1]` | Create and edit share one file; the `id` decides which |
| `action.php` | POST only | Delete. No GET access |

The list, the empty state and the table footer all come from
`app/list_page.php` rather than being written out here.

Data access is `app/models/ClientModel.php`. The list query is declared in a
`list_query()` call — search columns, filters, joins and the sortable whitelist
are all visible in one place at the top of `ClientModel.php`.

Deleting a client cascades to its deals, tasks and activities
(`ON DELETE CASCADE` in `database.sql`). The confirmation dialog says so.
