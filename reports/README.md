# Reports

Read-only analytics. This folder has no models and no POST handlers — every
figure comes from a `SELECT` in `app/models/ReportModel.php`.

| File | Route | Role |
|---|---|---|
| `index.php` | `/reports/` | The whole report in one printable page |

## What it covers

- **KPI row** — open pipeline value, weighted forecast, won revenue, win rate
- **Monthly activity** — calls, emails, meetings and notes over the last 6 months
- **Deal values by stage** — count, value and average per stage
- **Won vs lost** — both a count win rate and a value win rate, which answer
  different questions: you can win more deals than you lose while earning less
- **Lead sources** and **activity mix** — where opportunities come from, and how
  the team actually spends its time
- **Team performance** — per user: clients, leads, open deals, won value, tasks done

## Charts

There is no charting library. The bars are `<div>`s with a percentage width, which
keeps the project dependency-free and prints cleanly. The `chart-bar-fill`
classes in `assets/css/style.css` do the styling.

There is a print stylesheet: the sidebar, navbar, footer, filter bars and row
actions are hidden, so `Ctrl+P` produces something you could hand to a manager.

## Gaps in the data

Months with no activity are still shown, as zero-height bars. Filling the gaps
happens in `report_monthly_activity()` rather than in the view, so the chart
cannot silently skip a quiet month.
