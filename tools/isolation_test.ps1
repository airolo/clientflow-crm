<#
.SYNOPSIS
    Proves one workspace cannot see or touch another's records.

.DESCRIPTION
    Tenant isolation is the property this whole phase exists to provide, and it
    is the one property that cannot be verified by a single-tenant test: with one
    workspace every page renders correctly whether or not the query is scoped.

    So this creates two real workspaces, signs in to each in its own browser
    session, and asserts that neither can reach the other's data. It covers the
    three ways a leak actually happens:

      1. Listings - a query missing its tenant_id predicate, which puts every
         workspace's rows in one list.
      2. Detail by id - the URL-walking case. If client_find() is unscoped,
         ?id=N still opens another workspace's client.
      3. Writes - delete, restore and purge address rows by bare id from the
         query string, so without a tenant predicate one admin can destroy
         another's data even while every list looks correctly filtered.

    Section 3 is deliberately the one most likely to catch a real regression,
    because a single missing predicate in one of the 96 queries shows up nowhere
    else.

    Everything runs against real fixtures in the live database and is removed
    afterwards. The demo workspace is left exactly as it was found.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\isolation_test.ps1
#>
[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost/clientflow',
    [string]$MySql   = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$Php     = 'C:\xampp\php\php.exe',
    [string]$DbName  = 'clientflow_crm'
)

$ErrorActionPreference = 'Stop'
$script:pass = 0
$script:fail = 0
$script:pageErrors = @()

function Sql([string]$q) {
    ((& $MySql --user=root -N -B --execute="$q" 2>&1) | Out-String).Trim()
}

function Check([string]$name, [bool]$ok, [string]$detail = '') {
    if ($ok) { $script:pass++; Write-Output "  PASS  $name" }
    else     { $script:fail++; Write-Output "  FAIL  $name  $detail" }
}

function Section([string]$t) {
    Write-Output ''
    Write-Output "== $t =="
}

function New-Session {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest "$BaseUrl/auth/login.php" -WebSession $s -UseBasicParsing -TimeoutSec 15
    $token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    return @{ Session = $s; Token = $token }
}

function Post($ctx, [string]$Path, [hashtable]$Fields) {
    $body = @{ _token = $ctx.Token } + $Fields
    return Invoke-WebRequest "$BaseUrl/$Path" -Method POST -WebSession $ctx.Session `
        -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
}

function Get($ctx, [string]$Path) {
    return Invoke-WebRequest "$BaseUrl/$Path" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 20
}

# Page fetches that record a failure instead of aborting the run.
#
# An unscoped query does not always leak - it can also blow up. Dropping the
# 'tenant' key from a list_query caller makes the builder apply its default
# alias ('t') to a query aliased 'c', which is an unknown-column error and a 500.
# That is fail-closed and is the behaviour we want, but a script that dies on the
# first exception reports nothing about the other 120 assertions, so every fetch
# in the leak-sensitive sections goes through here.
function Try-Get($ctx, [string]$Path) {
    try {
        return (Get $ctx $Path).Content
    } catch {
        $script:pageErrors += @{ Path = $Path; Where = 'GET'; Message = $_.Exception.Message }
        return ''
    }
}

function Try-Post($ctx, [string]$Path, [hashtable]$Fields) {
    try {
        return (Post $ctx $Path $Fields).Content
    } catch {
        $script:pageErrors += @{ Path = $Path; Where = 'POST'; Message = $_.Exception.Message }
        return ''
    }
}

# Returns the final URL, which is how a redirect is detected. Records an error
# rather than throwing, so a 500 in the suspended-workspace check still lets the
# remaining assertions run.
function Where-It-Landed($ctx, [string]$Path) {
    try { return (Get $ctx $Path).BaseResponse.ResponseUri.AbsoluteUri }
    catch {
        $script:pageErrors += @{ Path = $Path; Where = 'GET'; Message = $_.Exception.Message }
        return '(error)'
    }
}

# Post follows redirects, so this lands on the dashboard. A dashboard that 500s
# therefore surfaces here rather than at whatever section happened to request it
# next, which is why this catches too: an unscoped query on a page in the
# post-login path would otherwise abort the run before a single assertion ran.
function Sign-In([string]$Slug, [string]$Email, [string]$Password) {
    $ctx = New-Session
    try {
        $r = Post $ctx 'auth/login.php' @{ workspace = $Slug; email = $Email; password = $Password }
        return @{ Ctx = $ctx; Final = $r.BaseResponse.ResponseUri.AbsoluteUri }
    } catch {
        $script:pageErrors += @{ Path = "auth/login.php (as $Slug)"; Where = 'POST'; Message = $_.Exception.Message }
        return @{ Ctx = $ctx; Final = '(error)' }
    }
}

Write-Output 'ClientFlow CRM tenant isolation test'
Write-Output "Target: $BaseUrl"

# ---------------------------------------------------------------------------
# Fixtures: two workspaces, each with an admin and one client whose name is
# unique enough that finding it in a page body is unambiguous.
# ---------------------------------------------------------------------------
$stamp   = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$slugA   = "iso-a-$stamp"
$slugB   = "iso-b-$stamp"
$emailA  = "iso-admin-a-$stamp@isolation.test"
$emailB  = "iso-admin-b-$stamp@isolation.test"
$markerA = "IsolationAlpha$stamp"
$markerB = "IsolationBeta$stamp"
# Per-type markers, so a page that lists deals but not clients is still covered.
$leadA = "IsoLeadA$stamp";  $leadB = "IsoLeadB$stamp"
$dealA = "IsoDealA$stamp";  $dealB = "IsoDealB$stamp"
$taskA = "IsoTaskA$stamp";  $taskB = "IsoTaskB$stamp"
$actA  = "IsoActA$stamp";   $actB  = "IsoActB$stamp"
$Password = 'Isolation!Test1'

$hash = (& $Php -r "echo password_hash('$Password', PASSWORD_DEFAULT);").Trim()
if ([string]::IsNullOrWhiteSpace($hash)) { Write-Output 'ABORT: could not hash the test password'; exit 1 }

function Remove-Fixtures {
    # Deleted by email pattern rather than by joining through tenants.
    #
    # Sign-in attempts against an unknown workspace are recorded with tenant_id
    # NULL on purpose, so a tenant-join sweep misses them. They accumulate here,
    # and because the IP failure limit is deliberately global (20 per window, to
    # stop a distributed run of guesses) enough of them eventually lock the test
    # itself out. Cleaning by email removes every row this script could have made.
    $null = Sql @"
DELETE FROM $DbName.login_attempts WHERE email LIKE '%@isolation.test';
DELETE FROM $DbName.activities WHERE created_by IS NULL AND tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'iso-%');
DELETE FROM $DbName.tasks      WHERE created_by IS NULL AND tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'iso-%');
DELETE FROM $DbName.deals      WHERE created_by IS NULL AND tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'iso-%');
DELETE FROM $DbName.leads      WHERE created_by IS NULL AND tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'iso-%');
DELETE FROM $DbName.clients    WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'iso-%');
DELETE FROM $DbName.users      WHERE email LIKE '%@isolation.test';
DELETE FROM $DbName.tenants    WHERE slug LIKE 'iso-%';
"@
}

# Sweep first, then snapshot. A previous run that aborted mid-way leaves its
# workspaces behind, and capturing the baseline before removing them makes the
# cleanup assertion fail for a reason that has nothing to do with this run.
Remove-Fixtures
$tenantsBefore = Sql "SELECT COUNT(*) FROM $DbName.tenants;"
$clientsBefore = Sql "SELECT COUNT(*) FROM $DbName.clients;"

$err = Sql @"
INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone)
     VALUES ('Isolation A', '$slugA', 'pro', 'active', 'GBP', 'Europe/London'),
            ('Isolation B', '$slugB', 'pro', 'active', 'GBP', 'Europe/London');
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'ISO Admin A', '$emailA', '$hash', 'admin', NULL, 1, 0),
            (@b, 'ISO Admin B', '$emailB', '$hash', 'admin', NULL, 1, 0);
INSERT INTO $DbName.clients (tenant_id, company_name, contact_person, status, assigned_to, created_by)
     VALUES (@a, '$markerA', 'Contact A', 'prospect', NULL, NULL),
            (@b, '$markerB', 'Contact B', 'prospect', NULL, NULL);
-- One of every record type per workspace. A lead, deal, task and activity each
-- surface on different pages, so client fixtures alone would only ever prove
-- the client queries are scoped.
INSERT INTO $DbName.leads (tenant_id, lead_name, company, lead_source, status, estimated_value, created_by)
     VALUES (@a, '$leadA', 'LA Co', 'website', 'new', 100, NULL),
            (@b, '$leadB', 'LB Co', 'website', 'new', 200, NULL);
INSERT INTO $DbName.deals (tenant_id, deal_title, client_id, value, stage, created_by)
     VALUES (@a, '$dealA', (SELECT id FROM $DbName.clients WHERE company_name='$markerA'), 500, 'new_lead', NULL),
            (@b, '$dealB', (SELECT id FROM $DbName.clients WHERE company_name='$markerB'), 600, 'new_lead', NULL);
INSERT INTO $DbName.tasks (tenant_id, title, priority, status, due_date, created_by)
     VALUES (@a, '$taskA', 'normal', 'pending', CURDATE(), NULL),
            (@b, '$taskB', 'normal', 'pending', CURDATE(), NULL);
INSERT INTO $DbName.activities (tenant_id, type, title, created_by)
     VALUES (@a, 'note', '$actA', NULL),
            (@b, 'note', '$actB', NULL);
"@
if ($err -match 'ERROR') { Write-Output "ABORT: fixture insert failed: $err"; exit 1 }

$clientA = [int](Sql "SELECT id FROM $DbName.clients WHERE company_name='$markerA';")
$clientB = [int](Sql "SELECT id FROM $DbName.clients WHERE company_name='$markerB';")
Write-Output "  workspace A: $slugA  client #$clientA  $markerA"
Write-Output "  workspace B: $slugB  client #$clientB  $markerB"

try {
    # -----------------------------------------------------------------------
    Section '1. Both workspaces sign in independently'
    $a = Sign-In $slugA $emailA $Password
    $b = Sign-In $slugB $emailB $Password
    Check 'A reaches its dashboard' ($a.Final -match 'dashboard\.php') $a.Final
    Check 'B reaches its dashboard' ($b.Final -match 'dashboard\.php') $b.Final

    # -----------------------------------------------------------------------
    # Users are unique per tenant, not globally, so this must work.
    Section '2. The same email address in two workspaces stays separate'
    $shared = "shared-$stamp@isolation.test"
    $null = Sql @"
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES ((SELECT id FROM $DbName.tenants WHERE slug='$slugA'), 'Shared A', '$shared', '$hash', 'admin', NULL, 1, 0),
            ((SELECT id FROM $DbName.tenants WHERE slug='$slugB'), 'Shared B', '$shared', '$hash', 'admin', NULL, 1, 0);
"@
    $a2 = Sign-In $slugA $shared $Password
    $b2 = Sign-In $slugB $shared $Password
    Check 'shared email signs in to A' ($a2.Final -match 'dashboard\.php') $a2.Final
    Check 'shared email signs in to B' ($b2.Final -match 'dashboard\.php') $b2.Final
    $usersA = Try-Get $a2.Ctx 'admin/users.php'
    $usersB = Try-Get $b2.Ctx 'admin/users.php'
    Check 'A sees only its own Shared user' (($usersA -match 'Shared A') -and ($usersA -notmatch 'Shared B'))
    Check 'B sees only its own Shared user' (($usersB -match 'Shared B') -and ($usersB -notmatch 'Shared A'))

    # -----------------------------------------------------------------------
    # The section most likely to catch a real regression: any one of the queries
    # losing its predicate puts both workspaces' rows onto one page. Each page is
    # checked for the marker it can legitimately display, and - the assertion
    # that actually matters - for the absence of every other workspace marker.
    Section '3. Listings never mix workspaces'
    # ShowA/ShowB are the markers a page is expected to display. Empty for the
    # reports page, which summarises won deals and per-owner totals rather than
    # listing rows, so a live deal fixture is not expected to appear there.
    # Absence is checked against every marker either way, which is the assertion
    # that actually matters - so the reports page is the strictest in the set.
    $pages = @(
        @{ Path = 'clients/index.php';    ShowA = @($markerA); ShowB = @($markerB) }
        @{ Path = 'leads/index.php';      ShowA = @($leadA);   ShowB = @($leadB) }
        @{ Path = 'pipeline/index.php';   ShowA = @($dealA);   ShowB = @($dealB) }
        @{ Path = 'tasks/index.php';      ShowA = @($taskA);   ShowB = @($taskB) }
        @{ Path = 'activities/index.php'; ShowA = @($actA);    ShowB = @($actB) }
        @{ Path = 'reports/index.php';    ShowA = @();         ShowB = @() }
        @{ Path = 'dashboard.php';        ShowA = @($markerA, $leadA, $dealA, $taskA, $actA)
                                           ShowB = @($markerB, $leadB, $dealB, $taskB, $actB) }
    )
    $allA = @($markerA, $leadA, $dealA, $taskA, $actA)
    $allB = @($markerB, $leadB, $dealB, $taskB, $actB)

    foreach ($p in $pages) {
        $bodyA = Try-Get $a.Ctx $p.Path
        $bodyB = Try-Get $b.Ctx $p.Path

        foreach ($m in $p.ShowA) {
            Check "$($p.Path) : A sees its own $($m.Substring(0,7))" ($bodyA -match [regex]::Escape($m))
        }
        foreach ($m in $allB) {
            Check "$($p.Path) : A cannot see any of B's records ($($m.Substring(0,7)))" `
                  ($bodyA -notmatch [regex]::Escape($m))
        }
        foreach ($m in $p.ShowB) {
            Check "$($p.Path) : B sees its own $($m.Substring(0,7))" ($bodyB -match [regex]::Escape($m))
        }
        foreach ($m in $allA) {
            Check "$($p.Path) : B cannot see any of A's records ($($m.Substring(0,7)))" `
                  ($bodyB -notmatch [regex]::Escape($m))
        }
    }

    # -----------------------------------------------------------------------
    Section '4. Detail pages cannot be reached by id (URL walking)'
    $viewA = $null
    try { $viewA = Get $a.Ctx "clients/view.php?id=$clientB" } catch { $viewA = $null }
    Check 'A cannot open B''s client by id' `
          (($null -eq $viewA) -or ($viewA.Content -notmatch [regex]::Escape($markerB))) `
          $(if ($viewA) { $viewA.BaseResponse.ResponseUri } else { 'error' })
    $viewB = $null
    try { $viewB = Get $b.Ctx "clients/view.php?id=$clientA" } catch { $viewB = $null }
    Check 'B cannot open A''s client by id' `
          (($null -eq $viewB) -or ($viewB.Content -notmatch [regex]::Escape($markerA))) `
          $(if ($viewB) { $viewB.BaseResponse.ResponseUri } else { 'error' })

    # The recycle bin is a listing too, and it is where purged rows are visible.
    $binA = Try-Get $a.Ctx 'admin/recycle_bin.php?type=client'
    Check 'A''s recycle bin is empty' ($binA -notmatch [regex]::Escape($markerB))

    # -----------------------------------------------------------------------
    # The dangerous case. Every list above can look right while the writes are
    # still unscoped, because these address rows by bare id from the URL.
    Section '5. Writes cannot reach another workspace''s rows'
    $null = Try-Post $a.Ctx 'clients/action.php' @{ action = 'delete'; id = $clientB }
    Check "A cannot delete B's client" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerB';") -eq 1)
    Check "B's client is still live" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerB' AND deleted_at IS NULL;") -eq 1)

    $null = Try-Post $a.Ctx 'clients/action.php' @{ action = 'delete'; id = $clientA }
    Check 'A can delete its own client' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerA' AND deleted_at IS NOT NULL;") -eq 1)

    $null = Try-Post $a.Ctx 'admin/recycle_action.php' @{ action = 'restore'; type = 'client'; id = $clientB }
    Check "A cannot restore B's row" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerB' AND deleted_at IS NOT NULL;") -eq 0)
    Check "A's own delete survives A's restore attempt on B" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerA' AND deleted_at IS NOT NULL;") -eq 1)

    # Purge asks the admin to type the row's label, which A cannot know for B.
    $null = Try-Post $a.Ctx 'admin/recycle_action.php' @{ action = 'purge'; type = 'client'; id = $clientB; confirm = $markerB }
    Check "A cannot purge B's row" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerB';") -eq 1)

    $null = Try-Post $a.Ctx 'admin/recycle_action.php' @{ action = 'purge'; type = 'client'; id = $clientA; confirm = $markerA }
    Check 'A can purge its own row' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerA';") -eq 0)

    # Editing another workspace's row through the form.
    $null = Try-Post $a.Ctx 'clients/form.php' @{ id = $clientB; company_name = 'Hijacked'; contact_person = 'X'; status = 'active'; assigned_to = ''; notes = '' }
    Check "A cannot rename B's client" ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='$markerB';") -eq 1)

    # -----------------------------------------------------------------------
    Section '6. Dropdowns only offer this workspace''s people'
    $taskFormA = Try-Get $a.Ctx 'tasks/form.php'
    $taskFormB = Try-Get $b.Ctx 'tasks/form.php'
    Check 'A''s task form offers A''s admin only' (($taskFormA -match 'ISO Admin A') -and ($taskFormA -notmatch 'ISO Admin B'))
    Check 'B''s task form offers B''s admin only' (($taskFormB -match 'ISO Admin B') -and ($taskFormB -notmatch 'ISO Admin A'))
    $clientOptsA = Try-Get $a.Ctx 'tasks/form.php'
    Check 'A''s task form does not list B''s client' ($clientOptsA -notmatch [regex]::Escape($markerB))

    # -----------------------------------------------------------------------
    Section '7. A suspended workspace stops working'
    $null = Sql "UPDATE $DbName.tenants SET status='suspended' WHERE slug='$slugB';"
    $landed = Where-It-Landed $b.Ctx 'dashboard.php'
    Check 'B is signed out once suspended' ($landed -match 'login\.php') $landed
    $stillWorks = Where-It-Landed $a.Ctx 'dashboard.php'
    Check 'A is unaffected by B being suspended' ($stillWorks -match 'dashboard\.php') $stillWorks
    $null = Sql "UPDATE $DbName.tenants SET status='active' WHERE slug='$slugB';"

    # -----------------------------------------------------------------------
    Section '8. An unknown workspace is not distinguishable from a wrong password'
    # Read the POST's own body. It follows the redirect itself, so the flash
    # message is rendered by that response - a separate GET would find it
    # already consumed and the assertion would pass or fail for the wrong reason.
    $u1 = New-Session
    $body1 = (Post $u1 'auth/login.php' @{ workspace = 'no-such-workspace-anywhere'; email = $emailA; password = $Password }).Content
    $u2 = New-Session
    $body2 = (Post $u2 'auth/login.php' @{ workspace = $slugA; email = 'nobody@nowhere.test'; password = 'x' }).Content
    $u3 = New-Session
    $body3 = (Post $u3 'auth/login.php' @{ workspace = $slugA; email = $emailA; password = 'wrong-password' }).Content
    Check 'unknown workspace gives the generic credentials error' ($body1 -match 'do not match our records')
    Check 'unknown email gives the same error'                 ($body2 -match 'do not match our records')
    Check 'wrong password gives the same error'                 ($body3 -match 'do not match our records')
    Check 'the error does not reveal that the workspace was the problem' ($body1 -notmatch '(?i)workspace.*(not|unknown|no such)')

    # -----------------------------------------------------------------------
    Section '9. Throttling in one workspace does not lock out another'
    $throttle = "throttle-$stamp@isolation.test"
    $null = Sql @"
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES ((SELECT id FROM $DbName.tenants WHERE slug='$slugA'), 'Throttle A', '$throttle', '$hash', 'admin', NULL, 1, 0),
            ((SELECT id FROM $DbName.tenants WHERE slug='$slugB'), 'Throttle B', '$throttle', '$hash', 'admin', NULL, 1, 0);
"@
# Six failures: one past the five-per-email limit, and well under the global
    # twenty-per-IP limit. If this used more attempts it would trip the IP limit
    # too, which is global by design and would lock every workspace out - making
    # the assertion below untestable rather than wrong.
$null = Sql "DELETE FROM $DbName.login_attempts WHERE email LIKE '%@isolation.test';"
for ($i = 1; $i -le 6; $i++) {
        $s = New-Session
        $null = Post $s 'auth/login.php' @{ workspace = $slugA; email = $throttle; password = "guess-$i" }
    }
    $sA = New-Session
    $bodyLockedA = (Post $sA 'auth/login.php' @{ workspace = $slugA; email = $throttle; password = $Password }).Content
    Check 'A is locked out after repeated failures' ($bodyLockedA -match 'Too many failed sign-in attempts')

    $sB = New-Session
    $bodyB = (Post $sB 'auth/login.php' @{ workspace = $slugB; email = $throttle; password = $Password }).Content
    Check 'B is NOT locked out by A''s failures' ($bodyB -notmatch 'Too many failed sign-in attempts')
    $landedB = (Get $sB 'dashboard.php').BaseResponse.ResponseUri.AbsoluteUri
    Check 'B reaches its dashboard' ($landedB -match 'dashboard\.php') $landedB
}
finally {
    Write-Output ''
    Write-Output '== cleaning up =='
    Remove-Fixtures

    # Reported separately from the assertions: a page that 500s did not leak, but
    # it is still a failure, and silently counting it as "no marker found" would
    # turn a hard error into a misleading pass.
    if ($script:pageErrors.Count -gt 0) {
        Write-Output ''
        Write-Output "== $($script:pageErrors.Count) page(s) returned an error =="
        foreach ($e in $script:pageErrors) {
            $msg = ($e.Message -replace '\s+', ' ')
            if ($msg.Length -gt 90) { $msg = $msg.Substring(0, 90) + '...' }
            Check "$($e.Where) $($e.Path) did not error" $false $msg
        }
    }

    Check 'workspaces removed' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants;")  -eq [int]$tenantsBefore)
    Check 'clients restored'   ([int](Sql "SELECT COUNT(*) FROM $DbName.clients;") -eq [int]$clientsBefore)
    Check 'no test clients left' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name LIKE 'Isolation%';") -eq 0)
    Check 'no test users left'   ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE '%isolation.test';") -eq 0)
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'
if ($script:fail -gt 0) { exit 1 }
exit 0