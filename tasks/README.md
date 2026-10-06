# Tasks

Follow-ups and to-dos, optionally attached to a client or a lead.

| File | Route | Role |
|---|---|---|
| `index.php` | `/tasks/` | List: status tabs, priority and owner filters, overdue-only toggle, one-click complete |
| `form.php` | `/tasks/form.php[?id=1\|?client_id=N\|?lead_id=N]` | Create and edit |
| `action.php` | POST only | Complete, reopen, delete |

## Status and priority

- status: `pending`, `in_progress`, `completed`
- priority: `low`, `medium`, `high`

Both are enums in `database.sql`; the lists are `task_statuses()` and
`task_priorities()` in `app/functions.php`.

## completed_at

`action.php` stamps `completed_at` when a task is closed and clears it again on
reopen, so the table records when work actually finished rather than when the
status was last edited. Reopening deliberately wipes the stamp — a reopened task
has not been completed since.

## Overdue

A task is overdue when `due_date` is in the past and the status is not
`completed`. The dashboard widget and the `?overdue=1` filter use the same
condition, written once in `task_list()`.

Data access is `app/models/TaskModel.php`.
