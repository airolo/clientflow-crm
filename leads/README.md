# Leads

Early-stage prospects, before they become clients.

| File | Route | Role |
|---|---|---|
| `index.php` | `/leads/` | List: filter by status, source and owner |
| `view.php` | `/leads/view.php?id=1` | One lead, with inline status change, linked deals, follow-ups and history |
| `form.php` | `/leads/form.php[?id=1]` | Create and edit |
| `action.php` | POST only | Delete, and the quick status change used on the detail page |

## Lead vs client

A **lead** is an unqualified prospect. A **client** is an account you already
trade with. They are separate tables because their fields differ: a lead has a
`lead_source` and an `estimated_value`, a client has a postal address and a
contact person.

## Converting a lead

`pipeline/form.php?lead_id=N` creates a deal that keeps a foreign key back to the
lead, so the revenue can be traced to where it came from. The lead's status
advances to *Proposal* in the same transaction as the deal insert — both writes
either succeed or neither does.

Data access is `app/models/LeadModel.php`.
