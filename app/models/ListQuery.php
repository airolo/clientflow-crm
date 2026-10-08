<?php
/**
 * Shared list-query builder.
 *
 * The clients, leads, tasks and activities list screens all had the same
 * shape: read a handful of options, turn them into WHERE fragments and
 * bound parameters, count the matches for pagination, then fetch one page.
 * Four copies of that scaffolding meant four places to fix a bug, and they
 * had already drifted apart.
 *
 * Each model now declares its own differences - columns to search, filters
 * to apply, joins, sortable columns - and this file does the rest.
 */

declare(strict_types=1);

/**
 * Run a paginated, searchable, filterable SELECT.
 *
 * Unless `include_deleted` is set, every query is restricted to live rows
 * (deleted_at IS NULL). Because that restriction has to apply to every list,
 * count, search and report without each model remembering it, it lives here
 * rather than being repeated in fifty WHERE clauses.
 *
 * @param array $config
 *   select      string  columns to return
 *   from        string  FROM clause including every JOIN
 *   count_from  string  FROM clause for the COUNT (defaults to $from)
 *   search      array   column expressions to LIKE-match
 *   options     array   raw $_GET-derived options
 *   filters     array   list of [value, SQL fragment, params]
 *   sort        array   whitelist of sortable column => SQL expression
 *   sort_key    string  key read from the request (default 'sort')
 *   sort_default string  whitelisted column used when the request is absent
 *   order_by    string  extra tie-breaker, e.g. 'c.id ASC'
 *   per_page    int     rows per page override
 *   soft_delete array   table aliases the deleted_at predicate applies to
 *   tenant      array   table aliases the tenant_id predicate applies to
 *                       (defaults to ['t']). A caller listing several tenants
 *                       in one query - the recycle bin, an ops view - passes
 *                       [] to opt out deliberately.
 *   only_deleted bool    restrict to deleted rows instead of live ones (the
 *                       recycle bin); without it, live rows are returned
 *   where_extra array   raw SQL fragments that always apply and take no
 *                       parameters, for conditions the caller owns (e.g.
 *                       "hide rows whose parent is deleted")
 *
 * @return array ['rows' => [], 'total' => int, 'offset' => int]
 */
function list_query(array $config): array
{
    $options = $config['options'] ?? [];
    $search  = trim((string) ($options['search'] ?? ''));

    $page    = max(1, (int) ($options['page'] ?? 1));
    $perPage = (int) ($config['per_page'] ?? ($options['per_page'] ?? ROWS_PER_PAGE));
    $perPage = max(1, min(100, $perPage));
    $offset  = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    // Soft delete. Applied here rather than in fifty WHERE clauses, because
    // list_query is the single choke point every listing goes through and a
    // forgotten filter would silently show deleted records.
    // An alias listed in soft_delete with no deleted_at column (users) simply
    // opts out by passing an empty array.
    $deletedTest = !empty($config['only_deleted']) ? 'IS NOT NULL' : 'IS NULL';
    foreach ($config['soft_delete'] ?? ['t'] as $alias) {
        $where[] = "$alias.deleted_at $deletedTest";
    }

    // Tenant scoping. Applied here for the same reason as soft delete: it is the
    // single choke point every listing goes through, and a forgotten filter
    // would show one business another business's customers rather than fail
    // loudly. The id comes from the session, never from the request.
    //
    // The parameter is prepended rather than appended so it binds before any
    // filter parameters the caller adds later.
    if (!empty($_SESSION['tenant_id']) && !array_key_exists('tenant', $config)) {
        $config['tenant'] = ['t'];
    }
    foreach ($config['tenant'] ?? [] as $alias) {
        array_unshift($where, "$alias.tenant_id = ?");
        array_unshift($params, tenant_id());
    }

    // Only meaningful when listing live rows; the bin has no parent visibility
    // rules of its own.
    if (empty($config['only_deleted'])) {
        foreach ($config['where_extra'] ?? [] as $fragment) {
            $where[] = $fragment;
        }
    }

    // Multi-field search. The wildcards come from user input but are bound as
    // parameters, so they cannot alter the SQL.
    $searchColumns = $config['search'] ?? [];
    if ($search !== '' && $searchColumns) {
        $clauses = [];
        foreach ($searchColumns as $column) {
            $clauses[] = "$column LIKE ?";
            $params[] = '%' . $search . '%';
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    // Each filter is [value, sql, params]. The caller decides what makes a
    // value meaningful - an enum check, a positive id, a non-empty string.
    foreach ($config['filters'] ?? [] as $filter) {
        [$value, $sql, $filterParams] = $filter;
        if ($value === null || $value === '' || $value === false || $value === 0) {
            continue;
        }
        $where[] = $sql;
        foreach ((array) $filterParams as $param) {
            $params[] = $param;
        }
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from     = $config['from'];
    $countFrom = $config['count_from'] ?? $from;

    // Total for pagination.
    $countStmt = db()->prepare('SELECT COUNT(*) ' . $countFrom . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // ORDER BY comes only from the whitelist.
    $sortKey = $options[$config['sort_key'] ?? 'sort'] ?? '';
    $sortDir = strtolower((string) ($options['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $sort    = $config['sort'];
    $column  = is_string($sortKey) && isset($sort[$sortKey]) ? $sortKey : ($config['sort_default'] ?? array_key_first($sort));
    $orderSql = $sort[$column] . ' ' . $sortDir;
    if (!empty($config['order_by'])) {
        $orderSql .= ', ' . $config['order_by'];
    }

    // LIMIT and OFFSET are cast to integers above, so interpolation is safe.
    $sql = 'SELECT ' . $config['select'] . ' ' . $from . $whereSql
        . " ORDER BY $orderSql LIMIT $perPage OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return [
        'rows'   => $stmt->fetchAll(),
        'total'  => $total,
        'offset' => $offset,
    ];
}

/**
 * Build a filter that only applies when the value is one of the allowed enum
 * values. Returns null (meaning "skip") otherwise, which list_query ignores.
 *
 * @return array|null [value, sql, [value]]
 */
function enum_filter(string $optionKey, string $sqlColumn, array $allowed, array $options): ?array
{
    $value = (string) ($options[$optionKey] ?? '');
    if ($value === '' || !is_valid_option($value, $allowed)) {
        return null;
    }
    return [$value, "$sqlColumn = ?", [$value]];
}

/**
 * Build a filter that only applies for a positive id.
 *
 * @return array|null [value, sql, [value]]
 */
function id_filter(string $optionKey, string $sqlColumn, array $options): ?array
{
    $value = (int) ($options[$optionKey] ?? 0);
    if ($value <= 0) {
        return null;
    }
    return [$value, "$sqlColumn = ?", [$value]];
}

/**
 * Rows from tasks or activities that belong to one client or lead.
 *
 * client_tasks/lead_tasks and client_activities/lead_activities were
 * near-identical. The two tables differ in which user column they join on
 * (tasks belong to an assignee, activities to whoever logged them) and in
 * how they sort, so those are passed in rather than hard-coded.
 *
 * $table and the column names are interpolated, so this must only ever be
 * called with literals. All four call sites pass fixed strings; the recycle
 * bin additionally passes $ownerId = 0 to mean "no limit".
 *
 * @param string $table      'tasks' or 'activities'
 * @param string $userColumn 'assigned_to' or 'created_by'
 * @param string $fkColumn   'client_id' or 'lead_id'
 * @param string $orderBy    ORDER BY expression
 */
function related_list(
    string $table,
    string $userColumn,
    string $fkColumn,
    string $orderBy,
    int $ownerId,
    int $limit
): array {
    // Not routed through list_query: it builds two different queries and binds
    // positionally, so the tenant filter is added by hand here. r.tenant_id is
    // mandatory rather than conditional - a listing that skipped it would leak
    // another business's rows, and there is no case where omitting it is right.
    $sql = "SELECT r.*, u.name AS owner_name
            FROM $table r
            LEFT JOIN users u ON u.id = r.$userColumn AND u.tenant_id = r.tenant_id
            WHERE r.deleted_at IS NULL AND r.tenant_id = ?";

    $params = [tenant_id()];
    if ($ownerId > 0) {
        $sql .= " AND r.$fkColumn = ?";
        $params[] = $ownerId;
    }
    $sql .= " ORDER BY $orderBy";
    if ($limit > 0) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
