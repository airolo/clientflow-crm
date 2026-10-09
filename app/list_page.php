<?php
/**
 * Reusable fragments for the list pages.
 *
 * Six screens shared a copy-pasted filter bar, table wrapper, row-action
 * group and table footer. Centralising them also meant the accessibility
 * fixes (scope="col" on every header, an accessible name on every icon-only
 * button) had one place to live instead of sixty.
 */

declare(strict_types=1);

/**
 * Render a filter bar.
 *
 * @param array $config
 *   action   string  form action, relative to the project root
 *   hidden   array   name => value carried through the query string
 *   fields   array   field definitions, see render_filter_field()
 *   layout   string  extra class on the form row
 */
function render_filter_bar(array $config): void
{
    $action = $config['action'];
    $fields = $config['fields'] ?? [];
    ?>
    <div class="card mb-3">
        <div class="card-body filter-bar">
            <form method="get" action="<?= e(url($action)) ?>" class="row g-2 align-items-end <?= e($config['layout'] ?? '') ?>">
                <?php foreach (($config['hidden'] ?? []) as $name => $value): ?>
                    <?php if ($value !== '' && $value !== null): ?>
                        <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($fields as $field) {
                    render_filter_field($field);
                } ?>
                <div class="<?= e($config['actions_col'] ?? 'col-12 col-md-2') ?> d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="<?= e(url($action)) ?>" class="btn btn-light border" title="Clear filters">
                        <i class="bi bi-x-lg"></i><span class="visually-hidden">Clear filters</span>
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Render one control inside a filter bar.
 *
 * @param array $field
 *   type    'search' | 'select' | 'date' | 'hidden'
 *   name    input name
 *   label   visible label (not needed for 'hidden')
 *   col     bootstrap column classes
 *   value   current value
 *   placeholder / options / options_label
 */
function render_filter_field(array $field): void
{
    $name  = $field['name'];
    $id    = 'filter-' . $name;
    $value = (string) ($field['value'] ?? '');
    $col   = $field['col'] ?? 'col-12 col-md-3';
    $type  = $field['type'] ?? 'select';

    // Carried through the filter form but not shown, e.g. the recycle bin's
    // record type, which has to survive a search or a page change.
    if ($type === 'hidden') {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
        return;
    }
    ?>
    <div class="<?= e($col) ?>">
        <label for="<?= e($id) ?>" class="form-label"><?= e($field['label']) ?></label>
        <?php if ($type === 'search'): ?>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" name="<?= e($name) ?>" id="<?= e($id) ?>"
                       class="form-control" value="<?= e($value) ?>"
                       placeholder="<?= e($field['placeholder'] ?? '') ?>">
            </div>
        <?php elseif ($type === 'date'): ?>
            <input type="date" name="<?= e($name) ?>" id="<?= e($id) ?>"
                   class="form-control" value="<?= e($value) ?>">
        <?php else: ?>
            <select name="<?= e($name) ?>" id="<?= e($id) ?>" class="form-select">
                <option value=""><?= e($field['options_label'] ?? 'All') ?></option>
                <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <option value="<?= e((string) $optionValue) ?>" <?= $value === (string) $optionValue ? 'selected' : '' ?>>
                        <?= e($optionLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the row-action button group.
 *
 * $actions entries: ['url' => link] or ['action' => post, 'confirm' => string].
 * Every icon-only control carries both a title and an aria-label, because
 * title alone is unreliable for screen-reader and touch users.
 */
function render_row_actions(array $actions): void
{
    if (!$actions) {
        return;
    }
    echo '<div class="btn-group btn-group-sm">';
    foreach ($actions as $action) {
        if (isset($action['url'])) {
            $label = $action['label'] ?? 'Open';
            printf(
                '<a href="%s" class="btn btn-outline-%s" title="%s" aria-label="%s"><i class="bi %s"></i></a>',
                e(url($action['url'])),
                e($action['variant'] ?? 'secondary'),
                e($label),
                e($action['aria'] ?? $label),
                e($action['icon'] ?? 'bi-eye')
            );
        } else {
            printf(
                '<button type="submit" class="btn btn-outline-%s" title="%s" aria-label="%s"%s>'
                . '<i class="bi %s"></i></button>',
                e($action['variant'] ?? 'danger'),
                e($action['label'] ?? 'Delete'),
                e($action['aria'] ?? $action['label'] ?? 'Delete'),
                isset($action['confirm']) ? ' data-confirm="' . e($action['confirm']) . '"' : '',
                e($action['icon'] ?? 'bi-trash')
            );
        }
    }
    echo '</div>';
}

/**
 * Open a row-action POST form.
 *
 * $hidden entries other than 'action', 'id' and 'return' are treated as the
 * form's target endpoint, so callers do not have to repeat it.
 */
function render_post_form_open(array $hidden = []): void
{
    $actionUrl = 'dashboard.php';
    foreach ($hidden as $name => $value) {
        if ($name === 'action_url') {
            $actionUrl = (string) $value;
            unset($hidden[$name]);
        }
    }
    printf('<form method="post" action="%s" class="m-0 d-inline">', e(url($actionUrl)));
    echo csrf_field();
    foreach ($hidden as $name => $value) {
        printf('<input type="hidden" name="%s" value="%s">', e((string) $name), e((string) $value));
    }
}

/** Close a row-action form. */
function render_post_form_close(): void
{
    echo '</form>';
}

/**
 * Render the page-header action buttons.
 *
 * $actions is a list of ['label', 'href', 'variant', 'icon', 'attrs'] so the
 * markup is built here rather than assembled as raw HTML in each page. The
 * label is always escaped and the href is restricted to internal pages, which
 * closes what was previously an unescaped sink for page-level markup.
 *
 * Any 'attrs' beyond icon/variant are treated as literal attribute names
 * carrying literal values - never as a way to inject markup.
 */
function render_page_actions(array $actions): void
{
    if (!$actions) {
        return;
    }
    echo '<div class="page-actions">';
    foreach ($actions as $action) {
        $label   = (string) ($action['label'] ?? '');
        $variant = (string) ($action['variant'] ?? 'primary');
        $icon    = (string) ($action['icon'] ?? '');
        $extra   = $action['attrs'] ?? [];
        // 'button' is needed for modals and print; everything else is a link.
        $tag     = ($action['tag'] ?? 'a') === 'button' ? 'button' : 'a';

if ($tag === 'button') {
        printf('<button type="button" class="btn btn-%s"', e($variant));
    } else {
        // url(), not the raw href. These are written as project-relative paths
        // ('tasks/form.php') and rendered on a page that lives in a folder
        // (/tasks/index.php), so emitting them bare made the browser resolve
        // them against the current directory and request /tasks/tasks/form.php.
        // Now they are absolute from the app root on every page.
        printf(
            '<a href="%s" class="btn btn-%s"',
            e(url((string) ($action['href'] ?? ''))),
            e($variant)
        );
    }
        foreach ($extra as $name => $value) {
            // Only simple attribute names, to keep this an attribute map
            // rather than a way to inject markup.
            if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $name)) {
                printf(' %s="%s"', e((string) $name), e((string) $value));
            }
        }
        echo '>';
        if ($icon !== '') {
            echo '<i class="bi ' . e($icon) . ' me-1"></i>';
        }
        echo e($label);
        echo $tag === 'button' ? '</button>' : '</a>';
    }
    echo '</div>';
}

/**
 * A table header cell. Static headers pass no link; sortable ones pass one.
 *
 * The link is NOT passed through url(), deliberately. Callers pass
 * sort_href(), which returns a query-only string ('?sort=title&dir=asc') that
 * must stay relative to the current page. Prefixing APP_URL would point every
 * column header at the app root instead of the list being viewed.
 */
function render_th(string $label, ?string $link = null, string $class = ''): void
{
    printf('<th scope="col"%s>', $class !== '' ? ' class="' . e($class) . '"' : '');
    if ($link !== null) {
        printf('<a href="%s" class="text-decoration-none text-reset">%s</a>', e($link), e($label));
    } else {
        echo e($label);
    }
    echo '</th>';
}

/**
 * The summary line and pagination links under a list.
 */
function render_table_footer(int $total, int $offset, int $count): void
{
    if ($total === 0) {
        return;
    }
    ?>
    <div class="table-footer">
        <div><?= result_summary($total, $offset, $count) ?></div>
        <?= render_pagination($total) ?>
    </div>
    <?php
}

/**
 * Pick the right empty state: one that acknowledges active filters when
 * filters are set, one that offers a first record when they are not.
 */
function render_list_empty_state(bool $filtered, string $icon, string $filteredTitle, string $filteredMessage, string $clearUrl, ?string $emptyIcon = null, string $emptyTitle = '', string $emptyMessage = '', ?string $createUrl = null, string $createLabel = ''): void
{
    if ($filtered) {
        echo empty_state($icon, $filteredTitle, $filteredMessage, $clearUrl, 'Clear filters');
        return;
    }
    echo empty_state(
        $emptyIcon ?? $icon,
        $emptyTitle,
        $emptyMessage,
        $createUrl,
        $createLabel
    );
}
