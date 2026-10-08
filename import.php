<?php
/**
 * CSV import (any signed-in user).
 *
 * Three steps, and the middle one is the point:
 *
 *   1. Choose a file.
 *   2. See the plan - how many rows will be created, which are duplicates, what
 *      is wrong with each row that failed - and confirm.
 *   3. See what happened.
 *
 * Nothing is written at step 1. The plan is built in step 2 and held in the
 * session, so a file is parsed and validated exactly once and the user is never
 * asked to confirm blind.
 *
 * The plan carries the workspace and user it was built for, and step 3 refuses a
 * plan whose ids do not match the current session. Without that check a plan
 * built in one workspace could be confirmed in another, which is the same class
 * of bug as trusting a tenant id from a form.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/csv.php';
require_login();

$type = (string) ($_GET['type'] ?? $_POST['type'] ?? 'client');
if (!in_array($type, ['client', 'lead'], true)) {
    $type = 'client';
}

// Field labels for this type, needed both by the POST handler (error messages)
// and by the column-reference table. Resolved once, here, so neither half can
// work without the other.
$labels = import_field_labels($type);

$errors = take_errors();
$plan   = $_SESSION['_import_plan'] ?? null;
$result = $_SESSION['_import_result'] ?? null;
unset($_SESSION['_import_result']);

// A plan built for the other type, or for another workspace or user, is not ours.
// Without this a plan built in one workspace could be confirmed in another,
// which is the same class of bug as trusting a tenant id from a form.
if (is_array($plan)) {
    if (($plan['type'] ?? '') !== $type
        || (int) ($plan['tenant_id'] ?? 0) !== tenant_id()
        || (int) ($plan['user_id'] ?? 0) !== (int) current_user_id()) {
        $plan = null;
        $_SESSION['_import_plan'] = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // ---------------------------------------------------------------- step 3
    if (post_str('action') === 'commit' && is_array($plan)) {
        $includeDuplicates = post_str('include_duplicates') === '1';

        try {
            $outcome = import_commit($plan['rows'], $plan['type'], $includeDuplicates);
            $_SESSION['_import_result'] = $outcome + ['type' => $plan['type']];
        } catch (Throwable $e) {
            // import_commit rolls back and rethrows on a transactional failure.
            error_log('ClientFlow import failed: ' . $e->getMessage());
            flash_error('The import could not be completed, so nothing was saved. Try again, or split the file into smaller batches.', 'import.php?type=' . $type);
        }

        // The plan is single-use. A reload must not be able to import twice.
        $_SESSION['_import_plan'] = null;
        redirect('import.php?type=' . $type);
    }

    // ---------------------------------------------------------------- step 2
    $file = $_FILES['csv'] ?? null;
    $read = import_read_upload(is_array($file) ? $file : []);

    if (!$read['ok']) {
        flash_error($read['error'], 'import.php?type=' . $type);
    } else {
        $mapped = import_map_headers($read['headers'], $type);
        $built  = import_build_plan($read['rows'], $mapped['map'], $type);

        if ($built['summary']['total'] === 0) {
            flash_error('That file has a header row but no data rows.', 'import.php?type=' . $type);
        } elseif (count($built['summary']['missing_required']) > 0) {
            // A missing required column is not fixable per row, so it is a
            // whole-file failure rather than 400 identical row errors.
            // The ?? fallback keeps a usable message if a field ever reaches
            // here that import_field_labels() does not know about.
            $missing = array_map(
                static fn ($f) => $labels[$f] ?? $f,
                $built['summary']['missing_required']
            );
            flash_error(
                'That file has no ' . implode(' or ', $missing) . ' column, so it cannot be imported. '
                . 'The first row must be a header naming each column.',
                'import.php?type=' . $type
            );
        } else {
            $_SESSION['_import_plan'] = [
                'type'      => $type,
                'tenant_id' => tenant_id(),
                'user_id'   => (int) current_user_id(),
                'filename'  => (string) ($_FILES['csv']['name'] ?? 'upload.csv'),
                'headers'   => $read['headers'],
                'unmatched' => $mapped['unmatched'],
                'rows'      => $built['rows'],
                'summary'   => $built['summary'],
            ];
            redirect('import.php?type=' . $type);
        }
    }
}

$pageTitle = 'Import ' . strtolower($labels['company_name'] ?? 'records');
$pageTitle = $type === 'client' ? 'Import clients' : 'Import leads';
$pageHeading = $pageTitle;
$activeNav  = $type === 'client' ? 'clients' : 'leads';

require __DIR__ . '/views/header.php';
?>

<?php if ($result): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">Import finished</h2>
            <p class="mb-2">
                <strong><?= (int) ($result['imported'] ?? 0) ?></strong> created,
                <strong><?= (int) ($result['skipped'] ?? 0) ?></strong> skipped.
            </p>
            <?php if (!empty($result['failed'])): ?>
                <div class="alert alert-danger mb-0">
                    <strong><?= count($result['failed']) ?> row(s) could not be saved:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach (array_slice($result['failed'], 0, 20) as $message): ?>
                            <li><?= e($message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="<?= e(url($type === 'client' ? 'clients/index.php' : 'leads/index.php')) ?>"
               class="btn btn-primary mt-3">Go to <?= e(strtolower($pageHeading)) ?></a>
        </div>
    </div>
<?php endif; ?>

<?php if (is_array($plan)): ?>
    <?php
    $summary = $plan['summary'];
    // Show at most this many problem rows; a 2000-row file with one bad column
    // would otherwise render 2000 identical errors.
    $shown = 0;
    ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-1">Check before importing</h2>
            <p class="text-secondary mb-3">
                Nothing has been saved yet. <code><?= e($plan['filename']) ?></code> has
                <?= (int) $summary['total'] ?> data row<?= $summary['total'] === 1 ? '' : 's' ?>.
            </p>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3">
                        <div class="fs-4 fw-bold"><?= (int) $summary['ready'] ?></div>
                        <div class="small text-secondary">will be created</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3">
                        <div class="fs-4 fw-bold"><?= (int) $summary['duplicates'] ?></div>
                        <div class="small text-secondary">look like existing records</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3">
                        <div class="fs-4 fw-bold"><?= (int) $summary['errors'] ?></div>
                        <div class="small text-secondary">will be skipped</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-3">
                        <div class="fs-4 fw-bold"><?= (int) $summary['warnings'] ?></div>
                        <div class="small text-secondary">notes on values</div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info d-flex gap-2" role="alert">
                <i class="bi bi-info-circle-fill flex-shrink-0"></i>
                <div>
                    These columns were recognised:
                    <strong><?= e(implode(', ', array_map(
                        static fn ($f) => $labels[$f],
                        array_values(array_filter(
                            array_keys($labels),
                            static fn ($f) => true
                        ))
                    ))) ?></strong>
                    <?php if (!empty($plan['unmatched'])): ?>
                        <br>Ignored, because the header did not match anything:
                        <code><?= e(implode('</code>, <code>', $plan['unmatched'])) ?></code>
                    <?php endif; ?>
                </div>
            </div>

            <?php $problemRows = array_filter($plan['rows'], static fn ($r) => $r['errors'] || $r['warnings']); ?>
            <?php if ($problemRows): ?>
                <h3 class="h6 fw-bold mt-4">Rows to look at</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Row</th>
                                <th scope="col">What will happen</th>
                                <th scope="col">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($problemRows as $row): ?>
                                <?php if ($shown++ >= 50) { break; ?>
                                <tr class="table-light">
                                    <td colspan="3" class="text-secondary small">
                                        <?= count($problemRows) - 50 ?> more row(s) not shown.
                                    </td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <td class="text-nowrap"><?= (int) $row['line'] ?></td>
                                    <td>
                                        <?php if ($row['errors']): ?>
                                            <span class="badge text-bg-danger">skipped</span>
                                        <?php elseif (isset($row['data']['__duplicate_of'])): ?>
                                            <span class="badge text-bg-warning">duplicate</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">will import</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php foreach ($row['errors'] as $message): ?>
                                            <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= e($message) ?></div>
                                        <?php endforeach; ?>
                                        <?php foreach ($row['warnings'] as $message): ?>
                                            <div class="text-secondary"><i class="bi bi-exclamation-circle me-1"></i><?= e($message) ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('import.php?type=' . $type)) ?>" class="mt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="commit">

                <?php if ($summary['duplicates'] > 0): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="include_duplicates"
                               id="include_duplicates" value="1">
                        <label class="form-check-label" for="include_duplicates">
                            Also import the <?= (int) $summary['duplicates'] ?> row(s) that look like records
                            you already have
                        </label>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary"
                        <?= (int) $summary['ready'] === 0 ? 'disabled' : '' ?>>
                    <i class="bi bi-check-lg me-1"></i>
                    Import <?= (int) $summary['ready'] ?> record<?= (int) $summary['ready'] === 1 ? '' : 's' ?>
                </button>
                <a href="<?= e(url('import.php?type=' . $type)) ?>" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">
                Import <?= e(strtolower($type === 'client' ? 'clients' : 'leads')) ?>
            </h2>

            <p class="text-secondary">
                Choose a CSV and you will see exactly what would be added before anything is saved.
                Rows with problems are listed and skipped rather than stopping the whole file.
            </p>

            <div class="btn-group mb-4" role="group" aria-label="Record type">
                <a href="<?= e(url('import.php?type=client')) ?>"
                   class="btn btn-outline-secondary<?= $type === 'client' ? 'active' : '' ?>">Clients</a>
                <a href="<?= e(url('import.php?type=lead')) ?>"
                   class="btn btn-outline-secondary<?= $type === 'lead' ? 'active' : '' ?>">Leads</a>
            </div>

            <form method="post" action="<?= e(url('import.php?type=' . $type)) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="csv" class="form-label">CSV file</label>
                    <input type="file" name="csv" id="csv" class="form-control" accept=".csv,text/csv" required>
                    <div class="form-text">
                        Up to <?= e(import_human_bytes(IMPORT_MAX_BYTES)) ?>,
                        <?= IMPORT_MAX_ROWS ?> rows. The first row must be a header.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload me-1"></i>Upload and check
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h2 class="h6 fw-bold mb-2">Column headings this accepts</h2>
            <p class="small text-secondary mb-3">
                Headings are matched loosely, so "Company Name" and "company_name" both work.
                Columns it does not recognise are ignored, and the check screen tells you which.
            </p>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Field</th>
                            <th scope="col">Also accepted as</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (import_field_aliases($type) as $field => $aliases): ?>
                            <tr>
                                <td><?= e($labels[$field]) ?><?= in_array($field, import_required_fields($type), true) ? ' <span class="text-danger">*</span>' : '' ?></td>
                                <td class="small text-secondary"><code><?= e(implode('</code>, <code>', array_slice($aliases, 1))) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-secondary mt-3 mb-0">
                <span class="text-danger">*</span> required. Status and source must be one of the app's
                own values; a file using "In Progress" instead of "in_progress" is reported rather than
                guessed at.
            </p>
        </div>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/views/footer.php'; ?>