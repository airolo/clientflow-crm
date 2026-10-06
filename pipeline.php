<?php
/**
 * Sales pipeline - Kanban board of deals grouped by stage.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$stageFilter  = (string) ($_GET['stage'] ?? '');
$ownerFilter  = (int) ($_GET['assigned_to'] ?? 0);
$userId       = (int) current_user_id();
$sidebarPendingTasks = task_count_open_for_sidebar($userId);

// Validate filters before using them in a query.
if (!is_valid_option($stageFilter, deal_stages())) {
    $stageFilter = '';
}
if ($ownerFilter > 0 && !user_find($ownerFilter)) {
    $ownerFilter = 0;
}

$board      = deal_board($ownerFilter ?: null);
$totals     = deal_stage_totals();
$users      = user_all(true);
$winRate    = report_win_rate();
$stages     = deal_stages();
$openStages = open_deal_stages();

$openValue   = deal_value_open();
$openCount   = deal_count_open();
$weightedSum = 0.0;

$pageTitle    = 'Pipeline';
$pageHeading  = 'Sales pipeline';
$pageSubtitle = $openCount . ' open deal' . ($openCount === 1 ? '' : 's') . ' worth ' . money($openValue);
$activeNav    = 'pipeline';
$breadcrumbs  = ['Dashboard' => 'index.php', 'Pipeline' => null];
$pageActions  = '<a href="deal_form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add deal</a>';

require __DIR__ . '/includes/header.php';
?>

<!-- ---------- Filters ---------- -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="get" action="pipeline.php" class="row g-2 align-items-end">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="assigned_to" class="form-label">Show deals for</label>
                <select name="assigned_to" id="assigned_to" class="form-select">
                    <option value="0">Everyone</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $ownerFilter === (int) $user['id'] ? 'selected' : '' ?>>
                            <?= e($user['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="stage" class="form-label">Jump to stage</label>
                <select name="stage" id="stage" class="form-select">
                    <option value="">All stages</option>
                    <?= select_options($stages, $stageFilter) ?>
                </select>
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a href="pipeline.php" class="btn btn-light border" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ---------- Summary strip ---------- -->
<div class="row g-3 mb-3">
    <?php
    $summaryCards = [
        [
            'label' => 'Open pipeline',
            'value' => money($openValue),
            'icon'  => 'bi-kanban-fill',
            'tint'  => 'primary',
            'hint'  => $openCount . ' deals in play',
        ],
        [
            'label' => 'Weighted forecast',
            'value' => money($winRate['weighted_forecast']),
            'icon'  => 'bi-graph-up-arrow',
            'tint'  => 'info',
            'hint'  => 'Based on stage probabilities',
        ],
        [
            'label' => 'Won value',
            'value' => money($winRate['won_value']),
            'icon'  => 'bi-trophy-fill',
            'tint'  => 'success',
            'hint'  => $winRate['won_count'] . ' deals won (' . $winRate['won_pct'] . '%)',
        ],
        [
            'label' => 'Lost value',
            'value' => money($winRate['lost_value']),
            'icon'  => 'bi-x-octagon-fill',
            'tint'  => 'danger',
            'hint'  => $winRate['lost_count'] . ' deals lost',
        ],
    ];
    foreach ($summaryCards as $card):
    ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-tint-<?= e($card['tint']) ?>"><i class="bi <?= e($card['icon']) ?>"></i></div>
                <div class="min-w-0">
                    <div class="stat-label"><?= e($card['label']) ?></div>
                    <div class="stat-value" style="font-size:1.25rem"><?= e($card['value']) ?></div>
                    <div class="stat-hint"><?= e($card['hint']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ---------- Board ---------- -->
<?php
$hasDeals = array_sum(array_map('count', $board)) > 0;
if (!$hasDeals):
    echo '<div class="card"><div class="card-body">';
    echo empty_state(
        'bi-kanban',
        'The pipeline is empty',
        'Create a deal or convert an existing lead to start tracking opportunities.',
        'deal_form.php',
        'Add a deal'
    );
    echo '</div></div>';
else:
?>
<div class="pipeline-board">
    <?php foreach ($stages as $stage):
        $dealsInStage = $board[$stage];
        $totalValue   = $totals[$stage]['total_value'];
        $isHighlighted = $stageFilter === $stage;
        $isEmpty      = !$dealsInStage;
    ?>
        <div class="pipeline-col<?= $isHighlighted ? ' ring' : '' ?>" data-stage="<?= e($stage) ?>" id="stage-<?= e($stage) ?>">
            <div class="pipeline-col-header">
                <span>
                    <span class="stage-dot me-1" style="background:<?= e(match ($stage) {
                        'new_lead' => '#94a3b8', 'contacted' => '#0ea5e9', 'proposal' => '#f59e0b',
                        'negotiation' => '#ef4444', 'won' => '#10b981', 'lost' => '#64748b',
                    }) ?>"></span>
                    <?= e(pretty(str_replace('_', ' ', $stage))) ?>
                </span>
                <span class="pipeline-total"><?= count($dealsInStage) ?> · <?= e(money_short($totalValue)) ?></span>
            </div>

            <div class="pipeline-col-body">
                <?php if ($isEmpty): ?>
                    <div class="text-center text-secondary small py-3">
                        <i class="bi bi-inbox d-block mb-1" style="font-size:1.3rem"></i>
                        No deals
                    </div>
                <?php else: ?>
                    <?php foreach ($dealsInStage as $deal):
                        $subject = $deal['client_name'] ?: ($deal['lead_company'] ?: $deal['lead_name'] ?: 'Unlinked');
                        $canMove = can_manage($deal);
                    ?>
                        <div class="deal-card">
                            <h6><?= e($deal['deal_title']) ?></h6>
                            <div class="deal-meta d-flex align-items-center gap-1 mb-2">
                                <i class="bi bi-building"></i>
                                <span class="text-truncate"><?= e($subject) ?></span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="deal-value mono"><?= e(money($deal['value'])) ?></span>
                                <?php if ($deal['expected_close_date']): ?>
                                    <span class="small text-secondary"><?= e(nice_date($deal['expected_close_date'])) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center gap-1">
                                <span class="small text-secondary text-truncate">
                                    <i class="bi bi-person"></i> <?= e($deal['owner_name'] ?: 'Unassigned') ?>
                                </span>
                                <div class="btn-group btn-group-sm">
                                    <a href="deal_form.php?id=<?= (int) $deal['id'] ?>"
                                       class="btn btn-outline-secondary border-0" title="Edit deal">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" action="deal_action.php" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $deal['id'] ?>">
                                        <input type="hidden" name="return" value="pipeline.php">
                                        <button type="submit" class="btn btn-outline-danger border-0"
                                                data-confirm="Delete deal &quot;<?= e($deal['deal_title']) ?>&quot;?"
                                                title="Delete deal">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($canMove): ?>
                                <form method="post" action="deal_action.php" class="mt-2 pt-2 border-top">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="id" value="<?= (int) $deal['id'] ?>">
                                    <input type="hidden" name="return" value="pipeline.php?assigned_to=<?= $ownerFilter ?>">
                                    <div class="input-group input-group-sm">
                                        <select name="stage" class="form-select form-select-sm" aria-label="Move deal to stage">
                                            <?= select_options($stages, $deal['stage']) ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary">Move</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="small text-secondary">
    <i class="bi bi-info-circle me-1"></i>
    Use the <strong>Move</strong> control on a card to change its stage. Open stages count towards the
    weighted forecast at 10% / 25% / 55% / 80% respectively.
</p>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>