<?php
/**
 * Dashboard - headline figures, pipeline snapshot, tasks and recent activity.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_login();

$summary  = report_summary();
$winRate  = report_win_rate();
$totals   = deal_stage_totals();
$monthly  = report_monthly_activity(6);
$stages   = report_leads_by_status();
$recent   = activity_recent(7);
$dueSoon  = task_due_soon(5);
$topDeals = deal_top_open(5);
$newLeads = lead_unconverted(5);

$pageTitle   = 'Dashboard';
$pageHeading = 'Welcome back, ' . current_user()['name'];
$pageSubtitle = 'Here is what is happening across your accounts today.';
$activeNav    = 'dashboard';
$breadcrumbs  = ['Dashboard' => null];

require __DIR__ . '/views/header.php';
?>

<!-- ---------- Stat tiles ---------- -->
<div class="row g-3 mb-4">
    <?php
    $tiles = [
        ['label' => 'Total clients', 'value' => $summary['total_clients'], 'hint' => 'All accounts on file',
         'icon' => 'bi-people-fill', 'tint' => 'primary', 'link' => 'clients/index.php'],
        ['label' => 'Active leads', 'value' => $summary['active_leads'], 'hint' => $summary['new_leads_30d'] . ' new in 30 days',
         'icon' => 'bi-funnel-fill', 'tint' => 'info', 'link' => 'leads/index.php'],
        ['label' => 'Open deals', 'value' => $summary['open_deals'], 'hint' => money_short($summary['open_deal_value']) . ' in play',
         'icon' => 'bi-kanban-fill', 'tint' => 'warning', 'link' => 'pipeline/index.php'],
        ['label' => 'Won deals', 'value' => $summary['won_deals'], 'hint' => money_short($summary['won_value']) . ' revenue',
         'icon' => 'bi-trophy-fill', 'tint' => 'success', 'link' => 'pipeline/index.php?stage=won'],
        ['label' => 'Pending tasks', 'value' => $summary['pending_tasks'],
         'hint' => $summary['overdue_tasks'] . ' overdue', 'icon' => 'bi-check2-square', 'tint' => 'danger', 'link' => 'tasks/index.php'],
        ['label' => 'Interactions logged', 'value' => $summary['activities'], 'hint' => 'Calls, emails, meetings, notes',
         'icon' => 'bi-clock-history', 'tint' => 'secondary', 'link' => 'activities/index.php'],
    ];
    foreach ($tiles as $tile):
    ?>
        <div class="col-12 col-sm-6 col-xl-4">
            <!--
                url(), not e(). Without APP_URL a relative href resolves
                against the current URL, so every tile breaks the moment the
                app is served from a subfolder - and "clients/index.php" from
                a page at /dashboard.php becomes /clients/index.php.
            -->
            <a href="<?= e(url($tile['link'])) ?>" class="text-decoration-none text-reset">
                <div class="stat-card">
                    <div class="stat-icon bg-tint-<?= e($tile['tint']) ?>"><i class="bi <?= e($tile['icon']) ?>"></i></div>
                    <div class="min-w-0">
                        <div class="stat-label"><?= e($tile['label']) ?></div>
                        <div class="stat-value"><?= (int) $tile['value'] ?></div>
                        <div class="stat-hint"><?= e($tile['hint']) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- ---------- Pipeline snapshot ---------- -->
    <div class="col-12 col-xl-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-kanban me-2 text-primary"></i>Pipeline value by stage</span>
                <a href="<?= url('pipeline/index.php') ?>" class="btn btn-sm btn-outline-primary">Open pipeline</a>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($totals as $stage => $data): ?>
                        <?php $isOpen = in_array($stage, open_deal_stages(), true); ?>
                        <div class="col-12">
                            <div class="chart-bar-row">
                                <div style="width:105px" class="small text-secondary"><?= e(pretty(str_replace('_', ' ', $stage))) ?></div>
                                <div class="chart-bar-track">
                                    <?php
                                    $max = max(1.0, ...array_values(array_map(fn($d) => (float) $d['total_value'], $totals)));
                                    $pct = round(($data['total_value'] / $max) * 100, 1);
                                    $cls = match ($stage) {
                                        'won'  => 'success',
                                        'lost' => 'danger',
                                        'proposal', 'negotiation' => 'warning',
                                        'contacted' => 'info',
                                        default => '',
                                    };
                                    ?>
                                    <div class="chart-bar-fill <?= e($cls) ?>" style="width:<?= e((string) $pct) ?>%"></div>
                                </div>
                                <div class="small text-end" style="width:132px">
                                    <span class="mono fw-semibold"><?= e(money_short($data['total_value'])) ?></span>
                                    <span class="text-secondary">· <?= (int) $data['deal_count'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-3">
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="small text-secondary">Win rate</div>
                        <div class="fw-bold fs-5"><?= e((string) $winRate['won_pct']) ?>%</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-secondary">Weighted forecast</div>
                        <div class="fw-bold fs-5"><?= e(money_short($winRate['weighted_forecast'])) ?></div>
                    </div>
                <div class="col-4">
                    <div class="small text-secondary">Open pipeline</div>
                    <div class="fw-bold fs-5"><?= e(money_short($winRate['open_value'])) ?></div>
                </div>
                <div class="col-4">
                    <div class="small text-secondary">Won revenue</div>
                    <div class="fw-bold fs-5"><?= e(money_short($winRate['won_value'])) ?></div>
                </div>
                </div>
            </div>
        </div>

        <!-- ---------- Leads by status ---------- -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-funnel me-2 text-primary"></i>Leads by status</span>
                <a href="<?= url('leads/index.php') ?>" class="btn btn-sm btn-outline-primary">View all leads</a>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($stages as $row):
                        $max = max(1, ...array_values(array_map(fn($s) => (int) $s['total'], $stages)));
                        $pct = round(($row['total'] / $max) * 100, 1);
                    ?>
                        <div class="col-12">
                            <div class="chart-bar-row">
                                <div style="width:105px" class="small text-secondary"><?= lead_status_badge($row['status']) ?></div>
                                <div class="chart-bar-track">
                                    <div class="chart-bar-fill" style="width:<?= e((string) $pct) ?>%"></div>
                                </div>
                                <div class="small text-end" style="width:44px"><?= (int) $row['total'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ---------- Right rail ---------- -->
    <div class="col-12 col-xl-4">
        <!-- Quick actions -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning-charge me-2 text-primary"></i>Quick actions</div>
            <div class="card-body d-grid gap-2">
                <a href="<?= url('clients/form.php') ?>" class="btn btn-primary"><i class="bi bi-person-plus me-2"></i>Add client</a>
                <a href="<?= url('leads/form.php') ?>" class="btn btn-outline-primary"><i class="bi bi-funnel me-2"></i>Add lead</a>
                <a href="<?= url('tasks/form.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-check2-square me-2"></i>Add task</a>
                <a href="<?= url('activities/form.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-2"></i>Log activity</a>
            </div>
        </div>

        <!-- Due soon -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-alarm me-2 text-danger"></i>Due today or overdue</span>
                <a href="<?= url('tasks/index.php') ?>?status=pending" class="btn btn-sm btn-outline-secondary">All</a>
            </div>
            <?php if (!$dueSoon): ?>
                <?= empty_state('bi-check2-circle', 'Nothing outstanding', 'No open tasks are due today. Nice work.', 'tasks/form.php', 'Add a task') ?>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($dueSoon as $task):
                        $isLate = $task['due_date'] < date('Y-m-d');
                    ?>
                        <li class="list-group-item d-flex gap-2 align-items-start py-3">
                            <form method="post" action="<?= url('tasks/action.php') ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                                <input type="hidden" name="return" value="dashboard.php">
                                <button type="submit" class="btn btn-sm btn-light border" title="Mark complete">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold small"><?= e($task['title']) ?></div>
                                <div class="small text-secondary">
                                    <?= e($task['company_name'] ?: ($task['lead_name'] ?: 'Internal task')) ?>
                                </div>
                                <div class="small mt-1">
                                    <?= priority_badge($task['priority']) ?>
                                    <span class="<?= $isLate ? 'text-danger fw-semibold' : 'text-secondary' ?>">
                                        <?= $isLate ? 'Overdue ' : 'Due ' ?><?= e(nice_date($task['due_date'])) ?>
                                    </span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <!-- ---------- Top open deals ---------- -->
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Largest open deals</span>
                <a href="<?= url('pipeline/index.php') ?>" class="btn btn-sm btn-outline-primary">Pipeline</a>
            </div>
            <?php if (!$topDeals): ?>
                <?= empty_state('bi-kanban', 'No open deals', 'Deals you add to the pipeline will appear here.', 'pipeline/form.php', 'Add a deal') ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Deal</th>
                                <th>Stage</th>
                                <th class="text-end">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topDeals as $deal): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($deal['deal_title']) ?></div>
                                    <div class="small text-secondary">
                                        <?= e($deal['client_name'] ?: ($deal['lead_name'] ?? '') ?: 'Unlinked') ?>
                                        · <?= e($deal['owner_name'] ?: 'Unassigned') ?>
                                    </div>
                                </td>
                                <td><?= stage_badge($deal['stage']) ?></td>
                                <td class="text-end mono fw-semibold"><?= e(money($deal['value'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ---------- Recent activity ---------- -->
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-primary"></i>Recent activity</span>
                <a href="<?= url('activities/index.php') ?>" class="btn btn-sm btn-outline-primary">All activity</a>
            </div>
            <div class="card-body">
                <?php if (!$recent): ?>
                    <?= empty_state('bi-clock-history', 'No activity yet', 'Log a call, email or meeting to build the history.', 'activities/form.php', 'Log activity') ?>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($recent as $activity):
                            [$typeLabel, $typeIcon] = activity_icon($activity['type']);
                            $subject = $activity['company_name'] ?: $activity['lead_name'] ?: 'Internal update';
                            $subjectLink = $activity['client_id']
                                ? 'clients/view.php?id=' . (int) $activity['client_id']
                                : ($activity['lead_id'] ? 'leads/view.php?id=' . (int) $activity['lead_id'] : null);
                        ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?= e($activity['type']) ?>"><i class="bi <?= e($typeIcon) ?>"></i></div>
                                <div class="timeline-title">
                                    <?php if ($subjectLink): ?>
                                        <a href="<?= e(url($subjectLink)) ?>" class="text-reset"><?= e($activity['title']) ?></a>
                                    <?php else: ?>
                                        <?= e($activity['title']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-meta">
                                    <?= e($typeLabel) ?> · <?= e($subject) ?>
                                    · <?= e($activity['owner_name'] ?: 'Unknown') ?>
                                    · <span title="<?= e($activity['created_at']) ?>"><?= e(time_ago($activity['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($newLeads): ?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-stars me-2 text-primary"></i>Hot leads not on the pipeline yet</span>
            <a href="<?= url('leads/index.php') ?>" class="btn btn-sm btn-outline-primary">View all leads</a>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($newLeads as $lead): ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="d-flex align-items-center gap-2 p-2 border rounded-3 h-100">
                            <span class="avatar avatar-sm" style="background:<?= e(avatar_colour($lead['lead_name'])) ?>">
                                <?= e(initials($lead['lead_name'])) ?>
                            </span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold small text-truncate"><?= e($lead['lead_name']) ?></div>
                                <div class="small text-secondary text-truncate"><?= e($lead['company'] ?: 'No company') ?></div>
                                <div class="mt-1"><?= lead_status_badge($lead['status']) ?></div>
                            </div>
                            <div class="text-end">
                                <div class="small fw-semibold mono"><?= e(money_short($lead['estimated_value'])) ?></div>
                                <a href="<?= url('pipeline/form.php') ?>?lead_id=<?= (int) $lead['id'] ?>"
                                   class="btn btn-sm btn-outline-primary mt-1" title="Create a deal">
                                    <i class="bi bi-plus-lg"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/views/footer.php'; ?>
