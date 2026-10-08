<?php
/**
 * Report model - aggregate queries powering the dashboard and reports page.
 * Read-only: everything here is a SELECT.
 */

declare(strict_types=1);

/** Headline figures for the dashboard stat tiles. */
function report_summary(): array
{
    $won = deal_value_won_lost();

    return [
        'total_clients'   => client_count(),
        'active_leads'    => lead_count_active(),
        'open_deals'      => deal_count_open(),
        'open_deal_value' => deal_value_open(),
        'won_deals'       => deal_count_won(),
        'won_value'       => $won['won_value'],
        'lost_deals'      => $won['lost_count'],
        'lost_value'      => $won['lost_value'],
        'pending_tasks'   => task_count_pending(),
        'overdue_tasks'   => task_overdue_count(),
        'activities'      => activity_count(),
        'new_leads_30d'   => report_leads_created_in_days(30),
    ];
}

/** Leads created in the last N days (sales velocity). */
function report_leads_created_in_days(int $days): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM leads
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $days, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/** Status distribution for the leads donut chart. */
function report_leads_by_status(): array
{
    $counts = lead_count_by_status();
    $rows = [];
    foreach (lead_statuses() as $status) {
        $rows[] = [
            'status' => $status,
            'label'  => pretty($status),
            'total'  => (int) ($counts[$status] ?? 0),
        ];
    }
    return $rows;
}

/** Stage distribution with values, for the pipeline report. */
function report_deals_by_stage(): array
{
    $data = deal_value_by_stage();
    $rows = [];
    foreach (deal_stages() as $stage) {
        $row = $data[$stage] ?? ['deal_count' => 0, 'total_value' => 0];
        $rows[] = [
            'stage'       => $stage,
            'label'       => pretty(str_replace('_', ' ', $stage)),
            'deal_count'  => (int) $row['deal_count'],
            'total_value' => (float) $row['total_value'],
        ];
    }
    return $rows;
}

/** Win rate, weighted forecast and pipeline coverage. */
function report_win_rate(): array
{
    $won = deal_value_won_lost();
    $closed = $won['won_count'] + $won['lost_count'];

    $wonPct  = $closed > 0 ? round(($won['won_count'] / $closed) * 100, 1) : 0.0;
    $valuePct = ($won['won_value'] + $won['lost_value']) > 0
        ? round(($won['won_value'] / ($won['won_value'] + $won['lost_value'])) * 100, 1)
        : 0.0;

    // Classic weighted forecast: each open stage gets a probability.
    $weights = [
        'new_lead'    => 0.10,
        'contacted'   => 0.25,
        'proposal'    => 0.55,
        'negotiation' => 0.80,
    ];
    $weighted = 0.0;
    $totals = deal_stage_totals();
    foreach ($weights as $stage => $weight) {
        $weighted += $totals[$stage]['total_value'] * $weight;
    }

    return [
        'won_count'         => $won['won_count'],
        'lost_count'        => $won['lost_count'],
        'closed_count'      => $closed,
        'won_pct'           => $wonPct,
        'won_value_pct'     => $valuePct,
        'won_value'         => $won['won_value'],
        'lost_value'        => $won['lost_value'],
        'weighted_forecast' => $weighted,
        'open_value'        => deal_value_open(),
    ];
}

/**
 * Activity counts for the last N months (monthly activity chart).
 *
 * @return array [['month' => '2026-05', 'label' => 'May', 'total' => 12, ...], ...]
 */
function report_monthly_activity(int $months = 6): array
{
    $stmt = db()->prepare(
        'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month,
                COUNT(*) AS total,
                SUM(type = "call")    AS calls,
                SUM(type = "email")   AS emails,
                SUM(type = "meeting") AS meetings,
                SUM(type = "note")    AS notes
         FROM activities
         WHERE tenant_id = ? AND deleted_at IS NULL
           AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), "%Y-%m-01")
         GROUP BY month
         ORDER BY month ASC'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $months, PDO::PARAM_INT);
    $stmt->execute();

    $byMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMonth[$row['month']] = $row;
    }

    // Fill gaps so the chart always shows a continuous run of months.
    $series = [];
    $cursor = new DateTimeImmutable('first day of this month');
    $cursor = $cursor->modify('-' . ($months - 1) . ' months');

    for ($i = 0; $i < $months; $i++) {
        $key = $cursor->format('Y-m');
        $row = $byMonth[$key] ?? null;
        $series[] = [
            'month'    => $key,
            'label'    => $cursor->format('M'),
            'total'    => (int) ($row['total'] ?? 0),
            'calls'    => (int) ($row['calls'] ?? 0),
            'emails'   => (int) ($row['emails'] ?? 0),
            'meetings' => (int) ($row['meetings'] ?? 0),
            'notes'    => (int) ($row['notes'] ?? 0),
        ];
        $cursor = $cursor->modify('+1 month');
    }

    return $series;
}

/** New clients vs new leads per month (business growth). */
function report_monthly_growth(int $months = 6): array
{
    $stmt = db()->prepare(
        'SELECT month, SUM(clients) AS clients, SUM(leads) AS leads FROM (
            SELECT DATE_FORMAT(created_at, "%Y-%m") AS month, COUNT(*) AS clients, 0 AS leads
              FROM clients
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), "%Y-%m-01")
             GROUP BY month
            UNION ALL
            SELECT DATE_FORMAT(created_at, "%Y-%m") AS month, 0 AS clients, COUNT(*) AS leads
              FROM leads
             WHERE tenant_id = ? AND deleted_at IS NULL
               AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), "%Y-%m-01")
             GROUP BY month
         ) combined
         GROUP BY month ORDER BY month ASC'
    );
    // Positional binding: the tenant id is repeated because the clients and
    // leads branches of the UNION are separate queries that only share a result set.
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $months, PDO::PARAM_INT);
    $stmt->bindValue(3, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(4, $months, PDO::PARAM_INT);
    $stmt->execute();

    $byMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMonth[$row['month']] = $row;
    }

    $series = [];
    $cursor = new DateTimeImmutable('first day of this month');
    $cursor = $cursor->modify('-' . ($months - 1) . ' months');

    for ($i = 0; $i < $months; $i++) {
        $key = $cursor->format('Y-m');
        $row = $byMonth[$key] ?? null;
        $series[] = [
            'month'  => $key,
            'label'  => $cursor->format('M'),
            'clients' => (int) ($row['clients'] ?? 0),
            'leads'   => (int) ($row['leads'] ?? 0),
        ];
        $cursor = $cursor->modify('+1 month');
    }

    return $series;
}

/** Won vs lost deals for the reports table. */
function report_won_lost(): array
{
    $won = deal_value_won_lost();

    return [
        [
            'outcome'  => 'Won',
            'count'    => $won['won_count'],
            'value'    => $won['won_value'],
            'badge'    => 'success',
            'icon'     => 'bi-trophy-fill',
            'tint'     => 'success',
        ],
        [
            'outcome'  => 'Lost',
            'count'    => $won['lost_count'],
            'value'    => $won['lost_value'],
            'badge'    => 'dark',
            'icon'     => 'bi-x-octagon-fill',
            'tint'     => 'danger',
        ],
    ];
}

/** Per-user performance table for the reports page. */
function report_team_performance(): array
{
    // Every correlated subquery carries d.tenant_id = u.tenant_id. Without it
    // the counts would silently include other workspaces' records whenever two
    // businesses happened to use the same user id - which they will, because
    // ids are only unique per tenant.
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.role,
                (SELECT COUNT(*) FROM leads l WHERE l.tenant_id = u.tenant_id AND l.assigned_to = u.id AND l.deleted_at IS NULL) AS leads,
                (SELECT COUNT(*) FROM clients c WHERE c.tenant_id = u.tenant_id AND c.assigned_to = u.id AND c.deleted_at IS NULL) AS clients,
                (SELECT COUNT(*) FROM deals d
                   WHERE d.tenant_id = u.tenant_id AND d.assigned_to = u.id AND d.deleted_at IS NULL
                     AND d.stage IN ("new_lead","contacted","proposal","negotiation")) AS open_deals,
                (SELECT COALESCE(SUM(d.value),0) FROM deals d
                   WHERE d.tenant_id = u.tenant_id AND d.assigned_to = u.id AND d.deleted_at IS NULL
                     AND d.stage IN ("new_lead","contacted","proposal","negotiation")) AS open_value,
                (SELECT COUNT(*) FROM deals d
                   WHERE d.tenant_id = u.tenant_id AND d.assigned_to = u.id AND d.deleted_at IS NULL AND d.stage = "won") AS won_deals,
                (SELECT COALESCE(SUM(d.value),0) FROM deals d
                   WHERE d.tenant_id = u.tenant_id AND d.assigned_to = u.id AND d.deleted_at IS NULL AND d.stage = "won") AS won_value,
                (SELECT COUNT(*) FROM tasks t
                   WHERE t.tenant_id = u.tenant_id AND t.assigned_to = u.id AND t.deleted_at IS NULL AND t.status = "completed") AS tasks_done
         FROM users u
         WHERE u.tenant_id = ? AND u.is_active = 1
         ORDER BY won_value DESC, open_value DESC'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll();
}

/** Activity mix used by the reports page. */
function report_activity_mix(): array
{
    $counts = activity_count_by_type();
    $rows = [];
    $colours = ['call' => 'primary', 'email' => 'success', 'meeting' => 'warning', 'note' => 'info'];
    foreach (activity_types() as $type) {
        $rows[] = [
            'type'    => $type,
            'label'   => pretty($type),
            'total'   => (int) ($counts[$type] ?? 0),
            'colour'  => $colours[$type],
        ];
    }
    return $rows;
}

/** Clients by owner, for the reports page. */
function report_clients_by_owner(): array
{
    $stmt = db()->prepare(
        'SELECT COALESCE(u.name, "Unassigned") AS owner,
                COUNT(c.id) AS total,
                SUM(c.status = "active")   AS active,
                SUM(c.status = "prospect") AS prospects
         FROM clients c
         LEFT JOIN users u ON u.id = c.assigned_to AND u.tenant_id = c.tenant_id
         WHERE c.tenant_id = ? AND c.deleted_at IS NULL
         GROUP BY u.name
         ORDER BY total DESC'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll();
}