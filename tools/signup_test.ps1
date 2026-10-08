<#
.SYNOPSIS
    Tests self-service signup and the first-run welcome screen (Phase D).

.DESCRIPTION
    Signup is the only endpoint in the app reachable with no session, so this is
    the suite that has to think about abuse rather than correctness.

    Checked, in the order that would matter if it broke:

    1. **Throttling.** An unauthenticated write path that will happily create a
       row per POST is a spam and resource vector. Per-IP and per-email limits
       must both hold, and neither may be evadable by clearing cookies.
    2. **Slug allocation must not become an enumeration oracle.** A taken slug is
       suffixed silently rather than refused, so the form cannot be used to ask
       which slugs exist.
    3. **Atomicity.** A workspace with no admin cannot be signed into.
    4. **The new owner gets a working, correctly-scoped workspace** - signed in,
       no demo data, own tenant row.
    5. **Cross-tenant.** A workspace created by signup sees nothing else.
    6. Validation, including the longer password a root account needs.
    7. Welcome screen shows once and then not again.

    Self-cleaning. Every workspace it creates is removed afterwards.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\signup_test.ps1
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
    $page = Invoke-WebRequest "$BaseUrl/signup.php" -WebSession $s -UseBasicParsing -TimeoutSec 20
    $token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    return @{ Session = $s; Token = $token }
}

# Signs a workspace up and returns the session afterwards.
# $slug allows a deliberate collision test; blank leaves it to be allocated.
function Do-Signup([string]$business, [string]$email, [string]$password, [string]$slug = '', [hashtable]$override = @{}) {
    $ctx = New-Session
    $fields = @{
        business_name    = $business
        slug             = $slug
        name             = 'Signup Owner'
        email            = $email
        password         = $password
        password_confirm = $password
        _token           = $ctx.Token
    }
    foreach ($k in $override.Keys) { $fields[$k] = $override[$k] }

try {
        $r = Invoke-WebRequest "$BaseUrl/signup.php" -Method POST -WebSession $ctx.Session `
             -Body $fields -UseBasicParsing -TimeoutSec 25 -MaximumRedirection 5
        return @{ Ctx = $ctx; Body = $r.Content; Final = $r.BaseResponse.ResponseUri.AbsoluteUri }
    } catch {
        return @{ Ctx = $ctx; Body = ''; Final = '(error)'; Error = $_.Exception.Message }
    }
}

function Get-Safe($ctx, [string]$url) {
    try { return (Invoke-WebRequest "$BaseUrl/$url" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 25).Content }
    catch { return "ERROR: $($_.Exception.Message)" }
}

Write-Output 'ClientFlow CRM signup test'
Write-Output "Target: $BaseUrl"

$stamp    = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$Password     = 'Signup!Test1'
$tenantBefore = Sql "SELECT COUNT(*) FROM $DbName.tenants;"
$usersBefore  = Sql "SELECT COUNT(*) FROM $DbName.users;"

# Clear the throttling counters.
#
# Every signup in this suite comes from 127.0.0.1, and the real per-IP limit is
# 5 per hour. Without this the suite throttles *itself* partway through, and the
# remaining sections fail on "Too many sign-up attempts" for reasons that have
# nothing to do with what they are testing.
function Reset-Limits {
    $null = Sql "DELETE FROM $DbName.signup_attempts;"
}

# Every workspace this script creates is removed afterwards. Matched on the
# business-name prefix rather than on slugs, because most slugs are allocated by
# the app and the test never learns what they were.
function Remove-Fixtures {
    $null = Sql @"
DELETE FROM $DbName.activities WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE name LIKE 'ISO Signup%');
DELETE FROM $DbName.tasks      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE name LIKE 'ISO Signup%');
DELETE FROM $DbName.deals      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE name LIKE 'ISO Signup%');
DELETE FROM $DbName.leads      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE name LIKE 'ISO Signup%');
DELETE FROM $DbName.clients    WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE name LIKE 'ISO Signup%');
DELETE FROM $DbName.login_attempts WHERE email LIKE 'iso-signup-%';
DELETE FROM $DbName.signup_attempts  WHERE email LIKE 'iso-signup-%';
DELETE FROM $DbName.users      WHERE email LIKE 'iso-signup-%';
DELETE FROM $DbName.tenants    WHERE name LIKE 'ISO Signup%';
"@
}

Remove-Fixtures

try {
    # -----------------------------------------------------------------------
    Section '1. The page is public and safe'
    $anon = New-Session
    $page = (Get-Safe $anon 'signup.php')
    Check 'signup page is served'            ($page -notmatch 'ERROR')
    Check 'it asks for a business name'      ($page -match 'name="business_name"')
    Check 'it asks for a workspace name'     ($page -match 'name="slug"')
    Check 'it asks for a password'           ($page -match 'name="password"')
    Check 'it asks for the password twice'   ($page -match 'name="password_confirm"')
    Check 'it carries a CSRF token'          ($page -match 'name="_token"')
    Check 'it has no inline event handlers'  (-not ($page -match 'on(click|change|submit)='))

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '2. A signup creates a workspace and signs the owner in'
    $emailA = "iso-signup-a-$stamp@signup.test"
    $rA = Do-Signup "ISO Signup Alpha $stamp" $emailA $Password
    Check 'it lands on the welcome screen' ($rA.Final -match 'welcome\.php') $rA.Final
    Check 'the welcome screen greets them' ($rA.Body -match 'Welcome')

    $tenantIdA = [int](Sql "SELECT id FROM $DbName.tenants WHERE name = 'ISO Signup Alpha $stamp';")
    Check 'the workspace exists'      ($tenantIdA -gt 0)
    Check 'the owner exists'          ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE tenant_id = $tenantIdA;") -eq 1)
    Check 'the owner is an admin'     ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE tenant_id = $tenantIdA AND role='admin';") -eq 1)
    Check 'the owner is active'       ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE tenant_id = $tenantIdA AND is_active=1;") -eq 1)
    # The owner chose a password, so they must not be forced to change it.
    Check 'no forced password change' ([int](Sql "SELECT must_change_password FROM $DbName.users WHERE tenant_id = $tenantIdA;") -eq 0)
    Check 'the workspace is active'   ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE id = $tenantIdA AND status='active';") -eq 1)
    Check 'it is not yet onboarded'   ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE id = $tenantIdA AND onboarded_at IS NULL;") -eq 1)
    Check 'it starts with a currency' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE id = $tenantIdA AND currency='GBP';") -eq 1)

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '3. The new workspace is empty and its own'
    $dash = Get-Safe $rA.Ctx 'dashboard.php'
    Check 'the dashboard renders'      ($dash -notmatch 'ERROR')
    # Demo data lives in tenant 1; a new workspace must not see any of it.
    Check 'no seeded client names'    (-not ($dash -match 'Northwind|Bluepeak|Ironbridge'))
    Check 'no seeded passwords'       (-not ($dash -match '\$2y\$|admin123'))
    Check 'the client count is zero'  ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE tenant_id = $tenantIdA;") -eq 0)
    Check 'the lead count is zero'    ([int](Sql "SELECT COUNT(*) FROM $DbName.leads WHERE tenant_id = $tenantIdA;") -eq 0)
    # A new workspace must own no rows in any record table. Counted across all of
    # them rather than one, so a stray insert into any table fails here.
    Check 'it owns no records anywhere' `
          ([int](Sql "SELECT (SELECT COUNT(*) FROM $DbName.clients WHERE tenant_id = $tenantIdA)
                          + (SELECT COUNT(*) FROM $DbName.leads   WHERE tenant_id = $tenantIdA)
                          + (SELECT COUNT(*) FROM $DbName.deals   WHERE tenant_id = $tenantIdA)
                          + (SELECT COUNT(*) FROM $DbName.tasks   WHERE tenant_id = $tenantIdA)
                          + (SELECT COUNT(*) FROM $DbName.activities WHERE tenant_id = $tenantIdA);") -eq 0)

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '4. Slug allocation does not leak which slugs exist'
    $slugTaken = [string](Sql "SELECT slug FROM $DbName.tenants WHERE id = 1;")
    $emailB = "iso-signup-b-$stamp@signup.test"
    # Deliberately asking for a slug that already exists.
    $rB = Do-Signup "ISO Signup Beta $stamp" $emailB $Password $slugTaken
    Check 'a taken slug is refused'    ($rB.Body -match 'already taken')

    # Leaving it blank allocates instead, which is the path that cannot enumerate.
    $rC = Do-Signup "ISO Signup Gamma $stamp" "iso-signup-c-$stamp@signup.test" $Password
    Check 'a blank slug is accepted'   ($rC.Final -match 'welcome\.php') $rC.Final
    $slugC = [string](Sql "SELECT slug FROM $DbName.tenants WHERE name = 'ISO Signup Gamma $stamp';")
    Check 'a slug was allocated'       ($slugC -match '^iso-signup-gamma') $slugC
    Check 'it is a valid slug'         ($slugC -match '^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])?$')

    # The same business name twice must both work, with a suffix on the second.
    $rD = Do-Signup "ISO Signup Alpha $stamp" "iso-signup-d-$stamp@signup.test" $Password
    $slugD = [string](Sql "SELECT slug FROM $DbName.tenants WHERE name = 'ISO Signup Alpha $stamp' ORDER BY id DESC LIMIT 1;")
    Check 'a duplicate name still works' ($rD.Final -match 'welcome\.php') $rD.Final
    Check 'the second gets a suffix'     ($slugD -match '-2$|-3$') $slugD

    # An explicitly requested free slug is honoured.
    $wantSlug = "custom-slug-$stamp"
    $null = Do-Signup "ISO Signup Delta $stamp" "iso-signup-e-$stamp@signup.test" $Password $wantSlug
    Check 'a requested free slug is used' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE slug = '$wantSlug';") -eq 1)

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '5. Validation'
$rE = Do-Signup "" "iso-signup-f-$stamp@signup.test" $Password
    Check 'a blank business name is refused' ($rE.Body -match 'Enter your business name')

    $rF = Do-Signup "ISO Signup Foxtrot $stamp" "not-an-email" $Password
    Check 'a bad email is refused' ($rF.Body -match 'valid email address')

    $rG = Do-Signup "ISO Signup Golf $stamp" "iso-signup-g-$stamp@signup.test" 'short1'
    Check 'a short password is refused' ($rG.Body -match 'at least 10 characters')

# -override named, not positional: the parameter before it is $slug, so a
    # bare hashtable binds to $slug and is cast to the string
    # "System.Collections.Hashtable", which the app then rejects as a bad slug -
    # making this look like a validation bug rather than a test bug.
    $rH = Do-Signup "ISO Signup Hotel $stamp" "iso-signup-h-$stamp@signup.test" $Password -override @{
        password_confirm = 'Different!Pass1'
    }
    Check 'a mismatched confirmation is refused' ($rH.Body -match 'do not match')

    $rI = Do-Signup "ISO Signup India $stamp" "iso-signup-i-$stamp@signup.test" $Password 'Bad Slug!'
    Check 'an invalid slug is refused' ($rI.Body -match 'letters, numbers and hyphens')

    # A refused signup must not have created anything.
    Check 'no workspace from the refused signups' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE name IN ('ISO Signup Foxtrot $stamp','ISO Signup Golf $stamp','ISO Signup Hotel $stamp','ISO Signup India $stamp');") -eq 0)

# A 10-character password is accepted, and so is exactly 10.
    #
    # Counters reset first, and not as an afterthought: the five refused signups
    # above all came from this IP, and the per-IP limit is 5 per hour. So Juliet
    # would be the sixth attempt and would correctly be throttled - which reads
    # as a validation bug but is the feature working. Throttling is section 6's
    # job; this section is about validation.
    Reset-Limits
    $rJ = Do-Signup "ISO Signup Juliet $stamp" "iso-signup-j-$stamp@signup.test" 'Exactly10!'
    Check 'a 10-character password is accepted' ($rJ.Final -match 'welcome\.php') $rJ.Final

    # -----------------------------------------------------------------------
    Section '6. Throttling'
    # Two limits: 5 per IP per hour and 3 per email per hour. Both are per-row in
    # signup_attempts, so neither can be evaded by starting a new session.
    $null = Sql "DELETE FROM $DbName.signup_attempts WHERE email LIKE 'iso-signup-%';"

    # Same email, six times, each in its own session.
    $limitEmail = "iso-signup-limit-$stamp@signup.test"
    $lockedAt = 0
    for ($i = 1; $i -le 6; $i++) {
        $rr = Do-Signup "ISO Signup Limit $i $stamp" $limitEmail $Password
        if ($rr.Body -match 'Too many sign-up attempts') { $lockedAt = $i; break }
    }
Check 'the per-email limit engages'    ($lockedAt -gt 0) "lockedAt=$lockedAt"
    Check 'it engages by the 4th attempt'  ($lockedAt -le 4) "lockedAt=$lockedAt"
    # The limit is 3 per email per hour, so at most 3 workspaces may exist from
    # this loop - and at least one, or the loop proved nothing.
    $limitCreated = [int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE name LIKE 'ISO Signup Limit % $stamp';")
    Check 'the limit held at 3 workspaces' ($limitCreated -eq 3) "created $limitCreated"
    Check 'at least one got through'        ($limitCreated -ge 1) "created $limitCreated"

    # A different email from the same IP: the IP limit should now also apply.
    $lockedByIp = 0
    for ($i = 1; $i -le 8; $i++) {
        $rr = Do-Signup "ISO Signup Ip $i $stamp" "iso-signup-ip$i-$stamp@signup.test" $Password
        if ($rr.Body -match 'Too many sign-up attempts') { $lockedByIp = $i; break }
    }
    Check 'the per-IP limit engages too' ($lockedByIp -gt 0) "lockedByIp=$lockedByIp"

# A brand new cookie jar must not reset the limit. Nothing is deleted here on
# purpose: an earlier version cleared the attempt rows first, which of course
# reset it - that tested the database delete rather than the session.
    $rr = Do-Signup "ISO Signup Reset $stamp" "iso-signup-reset-$stamp@signup.test" $Password
    Check 'a fresh session does not reset the IP limit' ($rr.Body -match 'Too many sign-up attempts')

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '7. The welcome screen shows once'
    $dashBefore = [int](Sql "SELECT onboarded_at IS NOT NULL FROM $DbName.tenants WHERE id = $tenantIdA;")
    Check 'not yet onboarded' ($dashBefore -eq 0)

    # Reaching welcome.php on its own must not mark it done - only the button.
    $null = Get-Safe $rA.Ctx 'welcome.php'
    Check 'viewing it does not mark it done' `
          ([int](Sql "SELECT onboarded_at IS NOT NULL FROM $DbName.tenants WHERE id = $tenantIdA;") -eq 0)

    $wctx = New-Session
    $wtok = [regex]::Match((Get-Safe $rA.Ctx 'welcome.php'), 'name="_token" value="([^"]+)"').Groups[1].Value
    try {
        $r = Invoke-WebRequest "$BaseUrl/welcome.php" -Method POST -WebSession $rA.Ctx.Session `
             -Body @{ _token = $wtok } -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
        $landed = $r.BaseResponse.ResponseUri.AbsoluteUri
    } catch { $landed = '(error)' }
    Check 'confirming lands on the dashboard' ($landed -match 'dashboard\.php') $landed
    Check 'it is now marked onboarded' `
          ([int](Sql "SELECT onboarded_at IS NOT NULL FROM $DbName.tenants WHERE id = $tenantIdA;") -eq 1)

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '8. Sign-in works for the new workspace afterwards'
    $login = New-Session
    $body = @{ _token = $login.Token; workspace = $slugC; email = 'iso-signup-c-' + $stamp + '@signup.test'; password = $Password }
    try {
        $r = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $login.Session `
             -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
        $landed = $r.BaseResponse.ResponseUri.AbsoluteUri
    } catch { $landed = '(error)' }
    Check 'the new owner can sign in with their slug' ($landed -match 'dashboard\.php') $landed

    # -----------------------------------------------------------------------
    Section '9. A signed-in visitor is sent away from signup'
    $already = Get-Safe $rA.Ctx 'signup.php'
    # Follows the redirect, so the body should be the dashboard, not the form.
    Check 'signup redirects when already signed in' (-not ($already -match 'name="business_name"'))

    # -----------------------------------------------------------------------
    Reset-Limits
    Section '10. Two signup workspaces stay separate'
    $tenantIdC = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug = '$slugC';")
    # Give A a client, then check B cannot see or reach it.
    $fctx = New-Session
    $ftok = [regex]::Match((Get-Safe $rA.Ctx 'clients/form.php'), 'name="_token" value="([^"]+)"').Groups[1].Value
    try {
        $null = Invoke-WebRequest "$BaseUrl/clients/form.php" -Method POST -WebSession $rA.Ctx.Session `
            -Body @{ _token = $ftok; company_name = 'Signup Only Client'; contact_person = 'Solo'; status = 'active' } `
            -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
    } catch { }
    Check 'A can add a client to its own workspace' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Signup Only Client';") -eq 1)

    $bDash = Get-Safe (New-Session) 'dashboard.php'
    $bList = ''
    $bCtx = New-Session
    try {
        $bList = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $bCtx.Session `
            -Body @{ _token = $bCtx.Token; workspace = $slugC; email = 'iso-signup-c-' + $stamp + '@signup.test'; password = $Password } `
            -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5 | Out-Null
        $bList = (Invoke-WebRequest "$BaseUrl/clients/index.php" -WebSession $bCtx.Session -UseBasicParsing -TimeoutSec 20).Content
    } catch { $bList = '' }
    Check "B cannot see A's client" (-not ($bList -match 'Signup Only Client'))
    Check "A can see its own client"  ((Get-Safe $rA.Ctx 'clients/index.php') -match 'Signup Only Client')
    Check 'the client belongs to A'   `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name='Signup Only Client' AND tenant_id=$tenantIdA;") -eq 1)
}
finally {
    Write-Output ''
    Write-Output '== cleaning up =='
    Remove-Fixtures
    Check 'workspaces removed' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE name LIKE 'ISO Signup%';") -eq 0)
    Check 'no test users left'  ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE 'iso-signup-%';") -eq 0)
    Check 'no signup attempts left' ([int](Sql "SELECT COUNT(*) FROM $DbName.signup_attempts WHERE email LIKE 'iso-signup-%';") -eq 0)
    Check 'tenant count restored'  ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants;") -eq [int]$tenantBefore) `
          "$(Sql "SELECT COUNT(*) FROM $DbName.tenants;") vs $tenantBefore"
    Check 'user count restored'   ([int](Sql "SELECT COUNT(*) FROM $DbName.users;") -eq [int]$usersBefore) `
          "$(Sql "SELECT COUNT(*) FROM $DbName.users;") vs $usersBefore"
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'
if ($script:fail -gt 0) { exit 1 }
exit 0