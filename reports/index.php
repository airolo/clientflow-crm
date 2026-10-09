<?php
/**
 * Reports - CRM statistics and trends.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$summary  = report_summary();
$winRate  = report_win_rate();
$stages   = report_deals_by_stage();
$leads    = report_leads_by_status();
$sources  = lead_count_by_source();
$monthly  = report_monthly_activity(6);
$growth   = report_monthly_growth(6);
$team     = report_team_performance();
$mix      = report_activity_mix();
$wonLost  = report_won_lost();
$owners   = report_clients_by_owner();
$wonDeals = deal_won_list(8);

$maxStageValue = max(1.0, ...array_map(fn($s) => $s['total_value'], $stages));
$maxLeadTotal  = max(1, ...array_map(fn($s) => $s['total'], $leads));
$maxMonthly    = max(1, ...array_map(fn($m) => $m['total'], $monthly));
$activityTotal = max(1, array_sum(array_column($mix, 'total')));

$pageTitle    = 'Reports';
$pageHeading  = 'Reports';
$pageSubtitle = 'Sales performance, funnel health and team activity';
$activeNav    = 'reports';
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Reports' => null];
$pageActions = [[
    'label' => 'Print',
    'tag' => 'button',
    'variant' => 'outline-secondary',
    'icon' => 'bi-printer',
    // Static application code, not user input.
    'attrs' => ['onclick' => 'window.print()'],
]];

require __DIR__ . '/../views/header.php';
?>

<!-- ---------- KPI row ---------- -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Total pipeline', money($winRate['open_value']), 'Open deal value', 'bi-kanban-fill', 'primary'],
        ['Weighted forecast', money($winRate['weighted_forecast']), 'Stage-adjusted', 'bi-graph-up-arrow', 'info'],
        ['Won revenue', money($summary['won_value']), $summary['won_deals'] . ' deals won', 'bi-trophy-fill', 'success'],
        ['Win rate', $winRate['won_pct'] . '%', $winRate['won_count'] . 'W / ' . $winRate['lost_count'] . 'L', 'bi-percent', 'warning'],
    ];
    foreach ($kpis as [$label, $value, $hint, $icon, $tint]):
    ?>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-tint-<?= e($tint) ?>"><i class="bi <?= e($icon) ?>"></i></div>
                <div class="min-w-0">
                    <div class="stat-label"><?= e($label) ?></div>
                    <div class="stat-value" style="font-size:1.35rem"><?= e($value) ?></div>
                    <div class="stat-hint"><?= e($hint) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- ---------- Monthly activity chart ---------- -->
    <div class="col-12 col-xl-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Monthly activity (last 6 months)</div>
            <div class="card-body">
                <div style="height:210px" class="d-flex align-items-end gap-2 mb-2">
                    <?php foreach ($monthly as $month):
                        $height = round(($month['total'] / $maxMonthly) * 100, 1);
                    ?>
                        <div class="flex-fill text-center d-flex flex-column justify-content-end h-100">
                            <div class="small fw-semibold mono mb-1"><?= (int) $month['total'] ?></div>
                            <!--
                                'column', not a bare .chart-bar-fill: that class is a
                                999px-radius pill meant for the 10px horizontal bars.
                                On a vertical bar it rounds into a capsule, so a tall
                                month looked like a circle.
                            -->
                            <div class="chart-bar-fill column mx-auto"
                                 style="width:100%;height:<?= max($height, 2) ?>%"
                                 title="<?= e($month['label']) ?>: <?= (int) $month['total'] ?> activities"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2">
                    <?php foreach ($monthly as $month): ?>
                        <div class="flex-fill text-center small text-secondary"><?= e($month['label']) ?></div>
                    <?php endforeach; ?>
                </div>
                <hr class="my-3">
                <div class="row g-2">
                    <?php foreach ($monthly as $month): ?>
                        <div class="col-6 col-md-4 col-lg-2">
                            <div class="small text-secondary"><?= e($month['label']) ?> mix</div>
                            <div class="small">
                                <span class="text-primary"><?= (int) $month['calls'] ?> calls</span> ·
                                <span class="text-success"><?= (int) $month['emails'] ?> emails</span><br>
                                <span class="text-warning"><?= (int) $month['meetings'] ?> meetings</span> ·
                                <span class="text-info"><?= (int) $month['notes'] ?> notes</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ---------- Deals by stage table ---------- -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-kanban me-2 text-primary"></i>Deal values by stage</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th class="text-end">Deals</th>
                            <th style="width:38%">Share of value</th>
                            <th class="text-end">Total value</th>
                            <th class="text-end">Avg deal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stages as $stage):
                        $avg = $stage['deal_count'] > 0 ? $stage['total_value'] / $stage['deal_count'] : 0;
                        $pct = round(($stage['total_value'] / $maxStageValue) * 100, 1);
                        $cls = match ($stage['stage']) {
                            'won'  => 'success',
                            'lost' => 'danger',
                            'proposal', 'negotiation' => 'warning',
                            'contacted' => 'info',
                            default => '',
                        };
                    ?>
                        <tr>
                            <td><?= stage_badge($stage['stage']) ?></td>
                            <td class="text-end mono"><?= (int) $stage['deal_count'] ?></td>
                            <td>
                                <div class="chart-bar-track">
                                    <div class="chart-bar-fill <?= e($cls) ?>" style="width:<?= e((string) $pct) ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end mono fw-semibold"><?= e(money($stage['total_value'])) ?></td>
                            <td class="text-end mono text-secondary"><?= e(money($avg)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th>Total</th>
                            <th class="text-end"><?= array_sum(array_column($stages, 'deal_count')) ?></th>
                            <th></th>
                            <th class="text-end mono"><?= e(money(array_sum(array_column($stages, 'total_value')))) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ---------- Team performance ---------- -->
        <div class="card">
            <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>Team performance</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Team member</th>
                            <th class="text-end">Clients</th>
                            <th class="text-end">Leads</th>
                            <th class="text-end">Open deals</th>
                            <th class="text-end">Open value</th>
                            <th class="text-end">Won</th>
                            <th class="text-end">Won value</th>
                            <th class="text-end">Tasks done</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($team as $member): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($member['name'])) ?>">
                                        <?= e(initials($member['name'])) ?>
                                    </span>
                                    <div>
                                        <div class="fw-semibold small"><?= e($member['name']) ?></div>
                                        <span class="badge text-bg-<?= $member['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                            <?= e(ucfirst($member['role'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end mono"><?= (int) $member['clients'] ?></td>
                            <td class="text-end mono"><?= (int) $member['leads'] ?></td>
                            <td class="text-end mono"><?= (int) $member['open_deals'] ?></td>
                            <td class="text-end mono"><?= e(money($member['open_value'])) ?></td>
                            <td class="text-end mono"><?= (int) $member['won_deals'] ?></td>
                            <td class="text-end mono fw-semibold text-success"><?= e(money($member['won_value'])) ?></td>
                            <td class="text-end mono"><?= (int) $member['tasks_done'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ---------- Right rail ---------- -->
    <div class="col-12 col-xl-4">
        <!-- Won vs lost -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-trophy me-2 text-primary"></i>Won vs lost</div>
            <div class="card-body">
                <?php foreach ($wonLost as $outcome): ?>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div class="stat-icon bg-tint-<?= e($outcome['tint']) ?>">
                            <i class="bi <?= e($outcome['icon']) ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label"><?= e($outcome['outcome']) ?></div>
                            <div class="fw-bold mono"><?= e(money($outcome['value'])) ?></div>
                            <div class="small text-secondary"><?= (int) $outcome['count'] ?> deal<?= $outcome['count'] === 1 ? '' : 's' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <hr class="my-3">
                <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Count win rate</span>
                    <span class="fw-semibold"><?= e((string) $winRate['won_pct']) ?>%</span>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Value win rate</span>
                    <span class="fw-semibold"><?= e((string) $winRate['won_value_pct']) ?>%</span>
                </div>
            </div>
        </div>

        <!-- Leads by status -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-funnel me-2 text-primary"></i>Leads by status</div>
            <div class="card-body">
                <?php foreach ($leads as $row): ?>
                    <div class="chart-bar-row">
                        <div style="width:92px"><?= lead_status_badge($row['status']) ?></div>
                        <div class="chart-bar-track">
                            <div class="chart-bar-fill" style="width:<?= e((string) round(($row['total'] / $maxLeadTotal) * 100, 1)) ?>%"></div>
                        </div>
                        <div class="small text-end" style="width:28px"><?= (int) $row['total'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Lead sources -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-broadcast me-2 text-primary"></i>Lead sources</div>
            <div class="card-body">
                <?php $sourceTotal = max(1, array_sum($sources)); ?>
                <?php foreach ($sources as $source => $total): ?>
                    <div class="chart-bar-row">
                        <div style="width:118px" class="small text-secondary"><?= e(pretty($source)) ?></div>
                        <div class="chart-bar-track">
                            <div class="chart-bar-fill info" style="width:<?= e((string) round(($total / $sourceTotal) * 100, 1)) ?>%"></div>
                        </div>
                        <div class="small text-end" style="width:56px">
                            <?= (int) $total ?> · <?= round(($total / $sourceTotal) * 100) ?>%
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Activity mix -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-pie-chart me-2 text-primary"></i>Activity mix</div>
            <div class="card-body">
                <?php foreach ($mix as $entry): ?>
                    <?php $pct = round(($entry['total'] / $activityTotal) * 100, 1); ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><i class="bi <?= e(activity_icon($entry['type'])[1]) ?> me-1"></i><?= e($entry['label']) ?></span>
                            <span class="text-secondary"><?= (int) $entry['total'] ?> (<?= e((string) $pct) ?>%)</span>
                        </div>
                        <div class="chart-bar-track">
                            <div class="chart-bar-fill" style="width:<?= e((string) $pct) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Clients by owner -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i>Clients by owner</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr><th>Owner</th><th class="text-end">Total</th><th class="text-end">Active</th><th class="text-end">Prospects</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($owners as $owner): ?>
                        <tr>
                            <td class="small"><?= e($owner['owner']) ?></td>
                            <td class="text-end mono"><?= (int) $owner['total'] ?></td>
                            <td class="text-end mono text-success"><?= (int) $owner['active'] ?></td>
                            <td class="text-end mono text-info"><?= (int) $owner['prospects'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent wins -->
        <div class="card">
            <div class="card-header"><i class="bi bi-stars me-2 text-primary"></i>Recent wins</div>
            <?php if (!$wonDeals): ?>
                <?= empty_state('bi-trophy', 'No wins yet', 'Deals marked as won will be listed here.') ?>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($wonDeals as $deal): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small"><?= e($deal['deal_title']) ?></div>
                                <div class="small text-secondary">
                                    <?= e($deal['client_name'] ?: 'Unlinked') ?> · <?= e($deal['owner_name'] ?: 'Unassigned') ?>
                                </div>
                            </div>
                            <span class="mono fw-semibold text-success"><?= e(money($deal['value'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../views/footer.php'; ?>
