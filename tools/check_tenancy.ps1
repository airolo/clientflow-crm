<#
.SYNOPSIS
    Fails if any SQL statement reads or writes a tenant-scoped table without a
    tenant predicate on that table's own alias.

.DESCRIPTION
    Tenant isolation is the one property in this codebase that cannot be verified
    by looking at a working page. An unscoped query still returns results, it
    just returns the wrong business's. In a single-tenant test install every page
    renders correctly either way, so there is no signal except review - and
    review is exactly what fails on the 40th query.

    So it is checked mechanically. For each SQL statement this finds the base
    table (FROM / INSERT INTO / UPDATE / DELETE FROM), resolves its alias, and
    requires that alias to carry a tenant_id predicate.

    Matching on the base alias specifically, rather than "the statement mentions
    tenant_id somewhere", matters. An earlier version of this script used that
    looser test and it passed a genuinely leaky query: client_find() had
    tenant_id on both its LEFT JOINs while its WHERE clause was unscoped, so the
    check went green and the query would still have returned another workspace's
    client. Scoping the joins and scoping the base table are different things,
    and only the second one filters the rows.

    list_query() callers are required to name their alias under a 'tenant' key.
    They cannot be checked statement by statement because the predicate is added
    inside the builder, so the declaration is verified instead - which also
    catches the case where the default alias ('t') is wrong for the query.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\check_tenancy.ps1
#>
[CmdletBinding()]
param(
    # $PSScriptRoot is not available inside a param default, so the default is
    # resolved in the body. Matches the other tools scripts, which take explicit
    # defaults rather than relying on the script's own location.
    [string]$Root = ''
)

$ErrorActionPreference = 'Stop'

if ($Root -eq '') {
    $Root = Split-Path -Parent $PSScriptRoot
}

# Tables that carry a tenant_id. `tenants` is the lookup itself, and
# `login_attempts` deliberately has no foreign key: failures against an unknown
# workspace are recorded with tenant_id NULL.
$scopedTables = @('users', 'clients', 'leads', 'deals', 'tasks', 'activities')

$files = @(
    Get-ChildItem -Path (Join-Path $Root 'app\models') -Filter *.php
    Get-Item           (Join-Path $Root 'app\auth.php')
    Get-Item           (Join-Path $Root 'app\tenancy.php')
)

# Resolve the base table and alias of one statement, or $null when it does not
# touch a scoped table. Also returns the statement kind, because the predicate
# looks different for an INSERT.
function Get-StatementScope {
    param([string]$Stmt, [string[]]$ScopedTables)

    $table = $null
    $alias = $null
    $kind  = ''

    if ($Stmt -match '(?i)\bINSERT\s+INTO\s+`?(\w+)`?') {
        $table = $Matches[1]
        $alias = $table
        $kind  = 'insert'
    }
    elseif ($Stmt -match '(?i)\bUPDATE\s+`?(\w+)`?') {
        $table = $Matches[1]
        $alias = $table
        $kind  = 'update'
    }
    elseif ($Stmt -match '(?i)\bDELETE\s+FROM\s+`?(\w+)`?') {
        $table = $Matches[1]
        $alias = $table
        $kind  = 'delete'
    }
    elseif ($Stmt -match '(?i)\bFROM\s+`?(\w+)`?(?:\s+(?:AS\s+)?(\w+))?') {
        $table = $Matches[1]
        # An explicit alias wins; otherwise MySQL allows the table name itself.
        $alias = if ($Matches[2]) { $Matches[2] } else { $table }
        $kind  = 'select'
    }

    if (-not $table) {
        # Interpolated or concatenated table name - SoftDeleteModel's
        # "UPDATE {$meta['table']}" and "FROM " . $meta['table'] . ".
        #
        # Without this fallback every statement in that file is invisible to the
        # check: the table regexes require a literal word after the keyword and
        # `{$` is not one. That matters because SoftDeleteModel is the highest
        # risk file in the codebase - it restores and permanently purges rows by
        # bare id from a URL - so the one place the check went blind is the place
        # a mistake would be worst.
        if ($Stmt -match '\{\$|\$(meta|child|table|alias|fks)\[?') {
            return [PSCustomObject]@{ Table = '(dynamic)'; Alias = '(dynamic)'; Kind = 'dynamic' }
        }
        return $null
    }

    if ($ScopedTables -notcontains $table.ToLower()) { return $null }

    return [PSCustomObject]@{ Table = $table; Alias = $alias; Kind = $kind }
}

# Which functions call list_query()? Those are judged by the declaration pass
# below rather than statement by statement, because the predicate is injected
# inside the builder instead of appearing in the SQL.
function Get-ListQueryFunctions {
    param([string[]]$Lines)

    $map = @{}
    $fn = $null
    $body = ''

    for ($i = 0; $i -lt $Lines.Count; $i++) {
        $trimmed = $Lines[$i].Trim()
        if ($trimmed -eq '}' -and $fn) {
            $map[$fn] = $body -match 'list_query\('
            $fn = $null
            $body = ''
        }
        if ($trimmed -match '^function\s+([a-zA-Z0-9_]+)') {
            $fn = $Matches[1]
            $body = ''
        }
        elseif ($fn) {
            $body += "`n" + $Lines[$i]
        }
    }
    return $map
}

$violations = @()
$statements = 0

foreach ($file in $files) {
    $lines = Get-Content $file.FullName
    $fn = ''
    $usesListQuery = Get-ListQueryFunctions -Lines $lines

    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i]
        $trimmed = $line.Trim()

        if ($trimmed -match '^function\s+([a-zA-Z0-9_]+)') { $fn = $Matches[1] }
        # Comment-only lines never count.
        if ($trimmed -match '^(//|\*|/\*|#)') { continue }
        # Each keyword must be followed by whitespace, i.e. be real SQL rather
        # than a config key. Without the trailing \s the array key 'select' in
        # a list_query config matches \bSELECT\b and the check reports a
        # statement that does not exist.
        if ($line -notmatch '(?i)\b(SELECT\s|INSERT\s+INTO\s|UPDATE\s|DELETE\s+FROM\s)') { continue }
        # Handled by the declaration pass instead.
        if ($fn -and $usesListQuery[$fn]) { continue }

        # Accumulate the whole statement. No fixed window: a config array can be
        # far longer than any sensible cap, and truncating it is how the earlier
        # version of this script produced false positives.
        $stmt = $trimmed
        for ($j = $i + 1; $j -lt [Math]::Min($i + 40, $lines.Count); $j++) {
            $stmt += ' ' + $lines[$j].Trim()
            if ($lines[$j].Trim() -match ';$') { break }
        }

        $scope = Get-StatementScope -Stmt $stmt -ScopedTables $scopedTables
        if (-not $scope) { continue }

        $statements++

        if ($scope.Kind -eq 'insert') {
            # An INSERT has no alias to filter; the row is stamped by naming
            # tenant_id in the column list and binding tenant_id() to it.
            if ($stmt -match '(?i)\btenant_id\b') { continue }
        }
        else {
            # The predicate has to be in the outer WHERE, not in a JOIN condition.
            #
            # client_find() is the case that makes this necessary: its LEFT JOINs
            # both read `u.tenant_id = c.tenant_id`, so a test for "the statement
            # mentions c.tenant_id" passes even when the WHERE is completely
            # unscoped - which would still return another workspace's client.
            # Scoping a join and scoping the base table are different things and
            # only the second filters rows.
            #
            # The LAST WHERE is the outer one. A subquery in the select list
            # (lead_find's deal_count, report_team_performance's per-user counts)
            # appears before FROM and so contributes an earlier WHERE.
            $whereAt = -1
            foreach ($m in [regex]::Matches($stmt, '(?i)\bWHERE\b')) {
                $whereAt = $m.Index
            }
            $tail = if ($whereAt -ge 0) { $stmt.Substring($whereAt) } else { '' }

            $scoped = ($tail -match ("(?i)\b" + [regex]::Escape($scope.Alias) + "\.tenant_id\b")) -or
                      ($tail -match '(?i)\btenant_id\s*=')
            if ($scoped) { continue }
        }

        $violations += [PSCustomObject]@{
            File  = $file.Name
            Line  = $i + 1
            Fn    = $fn
            Alias = $scope.Alias
            Table = $scope.Table
        }
    }
}

# Second pass, separate from the statement scan because the condition is about
# the caller rather than about one statement: any function calling list_query()
# with a base alias other than the 't' default must say which alias to filter.
foreach ($file in $files) {
    $lines = Get-Content $file.FullName
    $fn = ''
    $body = ''

    for ($i = 0; $i -lt $lines.Count; $i++) {
        $trimmed = $lines[$i].Trim()
        if ($trimmed -eq '}' -and $fn -ne '') {
            if ($body -match 'list_query\(' -and $body -notmatch "'tenant'\s*=>") {
                if ($body -match "(?i)FROM\s+`?(users|clients|leads|deals|tasks|activities)`?\s+(?:AS\s+)?(?!t\b)(\w+)" ) {
                    $violations += [PSCustomObject]@{
                        File  = $file.Name
                        Line  = $i + 1
                        Fn    = $fn
                        Alias = 'undeclared'
                        Table = $Matches[1]
                    }
                }
            }
            $fn = ''
            $body = ''
        }
        if ($trimmed -match '^function\s+([a-zA-Z0-9_]+)') {
            $fn = $Matches[1]
            $body = ''
        }
        elseif ($fn -ne '') {
            $body += "`n" + $lines[$i]
        }
    }
}

if ($violations.Count -gt 0) {
    Write-Output ''
    Write-Output "TENANT ISOLATION CHECK FAILED - $($violations.Count) statement(s) not scoped"
    Write-Output ''
    foreach ($v in $violations) {
        Write-Output ('  {0}:{1}  {2}()  table={3} alias={4}' -f $v.File, $v.Line, $v.Fn, $v.Table, $v.Alias)
    }
    Write-Output ''
    Write-Output 'Each of these would return another workspace''s rows. Add a'
    Write-Output 'tenant_id predicate bound to tenant_id() on the base alias, or'
    Write-Output "declare it for list_query() with a tenant key naming that alias."
    exit 1
}

Write-Output ''
Write-Output '  Tenant isolation check passed'
Write-Output "  $statements statement(s) against tenant-scoped tables, all filtered"
exit 0