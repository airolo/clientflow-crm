# Pipeline

The Kanban board and the deals that sit on it.

| File | Route | Role |
|---|---|---|
| `index.php` | `/pipeline/` | The board: deals grouped into six stage columns, plus stage totals and a weighted forecast |
| `form.php` | `/pipeline/form.php[?id=1\|?client_id=N\|?lead_id=N]` | Create and edit a deal |
| `action.php` | POST only | Move a deal to another stage, or delete it |

## Stages

`new_lead → contacted → proposal → negotiation → won | lost`

The enum lives in `database.sql`; the list is in `deal_stages()` in
`app/functions.php`, and both must stay in step.

## Weighted forecast

Open stages are weighted at 10 / 25 / 55 / 80 % (new lead / contacted /
proposal / negotiation) and summed in `report_win_rate()`
(`app/models/ReportModel.php`). Won and lost deals are excluded — they are
outcomes, not forecast.

## Moving a deal

Each card has a stage selector that posts to `action.php`. Moves are not
drag-and-drop: a form post is keyboard accessible, works without JavaScript and
cannot be faked into moving the wrong record, because the id comes from the
hidden field next to the selector.

Data access is `app/models/DealModel.php`.
