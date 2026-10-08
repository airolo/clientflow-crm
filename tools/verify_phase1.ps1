# Requires: powershell -ExecutionPolicy Bypass -File tools\verify_phase1.ps1
<#
    Ad-hoc verification for the sign-in hardening workstream.
    Lives in the repo so the checks are reproducible, and is not part of
    tools\regression.ps1 - that suite is the canonical pass/fail gate.
#>
[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost/clientflow',
    [string]$MySql   = 'C:\xampp\mysql\bin\mysql.exe'
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

# --- A throwaway session helper -------------------------------------
#
# Redirects are always followed rather than suppressed. PowerShell's
# WebRequestSession ends up in an unusable state if a response is taken with
# MaximumRedirection 0 and the same session is then reused, which produces a
# misleading "current state of the object" error on the next call. The final
# URL is read from BaseResponse.ResponseUri instead of the status code.
function New-Session {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest "$BaseUrl/auth/login.php" -WebSession $s -UseBasicParsing -TimeoutSec 15
    $token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    return @{ Session = $s; Token = $token }
}

function Post($ctx, [string]$Path, [hashtable]$Fields) {
    $Fields['_token'] = $ctx.Token
    Invoke-WebRequest "$BaseUrl/$Path" -WebSession $ctx.Session -Method POST `
        -Body $Fields -UseBasicParsing -TimeoutSec 15
}

# Final path of a request, e.g. "auth/change_password.php".
function LandedOn($response) {
    $uri = $response.BaseResponse.ResponseUri
    if (-not $uri) { return '' }
    $p = $uri.AbsolutePath
    if ($p -match '(?i)clientflow') { $p = $p -replace '(?i)^/clientflow/?', '' }
    return $p.TrimStart('/')
}

Write-Output "`n=== 1. DEMO_MODE gating ==="
$anon = Invoke-WebRequest "$BaseUrl/auth/login.php" -UseBasicParsing -TimeoutSec 15
$showsCreds = $anon.Content -match 'admin123'
Check "login page on localhost still shows demo creds (DEMO_MODE=true)" $showsCreds

# ---------------------------------------------------------------------------
# Throwaway accounts
#
# Like regression.ps1, this script creates the accounts it signs in with rather
# than using the seeded demo ones. The forced-change check genuinely has to set
# a new password, and it must never do that to an account a developer is using.
# ---------------------------------------------------------------------------
# Snapshot the seeded accounts' hashes. Compared at the end rather than against
# the demo values, because a developer following the app's own instructions will
# legitimately have changed them - the assertion is "this script did not", not
# "they still look like the demo".
$seededHashesBefore = (Sql "USE clientflow_crm;
    SELECT GROUP_CONCAT(CONCAT(id,':',password_hash) ORDER BY id SEPARATOR '|') FROM users WHERE id IN (1,2,3,4);")

$V1Pass   = 'Regression!Test1'
# bcrypt of $V1Pass, generated with PHP password_hash(PASSWORD_DEFAULT).
$V1Hash   = '$2y$10$aTWww8OncS5sYUl7TAqNe.hdpKGyghs8CdZ1y242gPsvwz/iyBXju'
$V1Plain  = 'verify-admin@regression.test'    # not flagged, so it reaches the app
$V1Forced = 'verify-forced@regression.test'  # flagged, so it is held at the change screen

# Sign-in is workspace + email, so these accounts have to live in a workspace and
# every sign-in below has to name it.
$V1TenantId = Sql "USE clientflow_crm; SELECT id FROM tenants ORDER BY id LIMIT 1;"
$V1Slug     = Sql "USE clientflow_crm; SELECT slug FROM tenants ORDER BY id LIMIT 1;"
if ([string]::IsNullOrWhiteSpace($V1Slug)) {
    Write-Output 'ABORT: no tenant exists. Import database.sql or run migrations/003_multi_tenancy.sql first.'
    exit 1
}

Sql "USE clientflow_crm;
     DELETE FROM users WHERE email IN ('$V1Plain', '$V1Forced');
     INSERT INTO users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES ($V1TenantId, 'Verify Plain', '$V1Plain', '$V1Hash', 'staff', NULL, 1, 0),
            ($V1TenantId, 'Verify Forced', '$V1Forced', '$V1Hash', 'staff', NULL, 1, 1);" | Out-Null
Check 'throwaway accounts created' (
    (Sql "USE clientflow_crm; SELECT COUNT(*) FROM users WHERE email IN ('$V1Plain','$V1Forced');") -eq '2')

# Non-loopback Host header must resolve DEMO_MODE to false.
$remote = Invoke-WebRequest "$BaseUrl/auth/login.php" -UseBasicParsing -TimeoutSec 15 -Headers @{ Host = 'crm.example.test' }
Check "same page with a non-loopback Host hides demo creds" (-not ($remote.Content -match 'admin123'))

Write-Output "`n=== 2. Forced password change ==="
$ctx = New-Session
$r = Post $ctx 'auth/login.php' @{ workspace = $V1Slug; email = $V1Forced; password = $V1Pass }
# login.php redirects to dashboard.php, and dashboard.php then bounces the flagged
# account to change_password.php - so landing there IS the success path.
Check "a flagged account is held at change_password" ((LandedOn $r) -eq 'auth/change_password.php') "landed=$(LandedOn $r)"

# Any guarded page must bounce to change_password.
$dash = Invoke-WebRequest "$BaseUrl/dashboard.php" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 15
Check "dashboard redirects a flagged account to change_password" ((LandedOn $dash) -eq 'auth/change_password.php') "landed=$(LandedOn $dash)"

# And the same for a non-dashboard guarded page.
$cl = Invoke-WebRequest "$BaseUrl/clients/index.php" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 15
Check "clients list also bounces to change_password" ((LandedOn $cl) -eq 'auth/change_password.php') "landed=$(LandedOn $cl)"

$flag = (Sql "USE clientflow_crm; SELECT must_change_password FROM users WHERE email='$V1Forced';")
Check "the throwaway row is flagged must_change_password=1" ($flag -eq '1')

Write-Output "`n=== 3. Throttling and lockout ==="
Sql "DELETE FROM clientflow_crm.login_attempts;" | Out-Null

$lockoutSeen = $false
for ($i = 1; $i -le 7; $i++) {
    $c = New-Session
    $resp = Post $c 'auth/login.php' @{ workspace = $V1Slug; email = $V1Plain; password = "wrong-guess-$i" }
    $body = $resp.Content
    if ($body -match 'Too many failed sign-in attempts') { $lockoutSeen = $true; break }
}
Check "lockout engages by attempt 7" $lockoutSeen

$counted = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='$V1Plain' AND succeeded=0;")
Check "failed attempts were recorded" ($counted -ge 5) "counted=$counted"

# The correct password must be refused while locked out.
$c = New-Session
$locked = Post $c 'auth/login.php' @{ workspace = $V1Slug; email = $V1Plain; password = $V1Pass }
Check "correct password is refused while locked out" ($locked.Content -match 'Too many failed sign-in attempts')

Write-Output "`n=== 4. Successful sign-in clears the counter ==="
Sql "DELETE FROM clientflow_crm.login_attempts;" | Out-Null
$c = New-Session
$c2 = New-Session
Post $c  'auth/login.php' @{ workspace = $V1Slug; email = 'nobody@nowhere.test'; password = 'x' } | Out-Null
Post $c  'auth/login.php' @{ workspace = $V1Slug; email = 'nobody@nowhere.test'; password = 'x' } | Out-Null
$before = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='nobody@nowhere.test';")
Check "failures counted before sign-in" ($before -ge 2)

Write-Output "`n=== 5. Successful sign-in records a success row ==="
$ctx3 = New-Session
Post $ctx3 'auth/login.php' @{ workspace = $V1Slug; email = $V1Plain; password = $V1Pass } | Out-Null
$okRow = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='$V1Plain' AND succeeded=1;")
Check "successful attempt is logged" ($okRow -ge 1) "count=$okRow"

Write-Output "`n=== 6. Completing the forced change ==="
$ctx4 = New-Session
Post $ctx4 'auth/login.php' @{ workspace = $V1Slug; email = $V1Forced; password = $V1Pass } | Out-Null
$cp = Invoke-WebRequest "$BaseUrl/auth/change_password.php" -WebSession $ctx4.Session -UseBasicParsing -TimeoutSec 15
Check "change-password page reachable" ($cp.StatusCode -eq 200)

# Wrong current password must be rejected.
$bad = Post $ctx4 'auth/change_password.php' @{ current_password = 'nope'; new_password = 'NewPassw0rd!'; confirm_password = 'NewPassw0rd!' }
Check "wrong current password rejected" ($bad.Content -match 'not your current password')

# Reusing the same password must be rejected.
$same = Post $ctx4 'auth/change_password.php' @{ current_password = $V1Pass; new_password = $V1Pass; confirm_password = $V1Pass }
Check "reusing the current password rejected" ($same.Content -match 'different from the current one')

# Mismatched confirmation must be rejected.
$mism = Post $ctx4 'auth/change_password.php' @{ current_password = $V1Pass; new_password = 'NewPassw0rd!'; confirm_password = 'Different1!' }
Check "mismatched confirmation rejected" ($mism.Content -match 'do not match')

# The real thing: set a new password and confirm the flag clears.
$newPass = 'Regression' + (Get-Random -Minimum 10000 -Maximum 99999) + '!'
$good = Post $ctx4 'auth/change_password.php' @{ current_password = $V1Pass; new_password = $newPass; confirm_password = $newPass }
$flagAfter = (Sql "USE clientflow_crm; SELECT must_change_password FROM users WHERE email='$V1Forced';")
Check "flag cleared after a successful change" ($flagAfter -eq '0')

$dashAfter = Invoke-WebRequest "$BaseUrl/dashboard.php" -WebSession $ctx4.Session -UseBasicParsing -TimeoutSec 15
Check "dashboard reachable after the change" ($dashAfter.Content -notmatch 'change_password')

# Old password must no longer work; new one must.
$cOld = New-Session
$rOld = Post $cOld 'auth/login.php' @{ workspace = $V1Slug; email = $V1Forced; password = $V1Pass }
Check "old password no longer works" ($rOld.Content -match 'do not match')

$cNew = New-Session
$rNew = Post $cNew 'auth/login.php' @{ workspace = $V1Slug; email = $V1Forced; password = $newPass }
Check "new password works" ((LandedOn $rNew) -eq 'dashboard.php') "landed=$(LandedOn $rNew)"

Write-Output "`n=== 7. Error page serves no CDN request ==="
$errPage = Get-Content 'C:\Users\Bradley\ClientFlow\app\bootstrap.php' -Raw
Check "bootstrap.php has no jsdelivr reference" (-not ($errPage -match 'jsdelivr'))

Write-Output "`n=== 8. No inline script blocks remain (for CSP) ==="
$inline = (Get-ChildItem 'C:\Users\Bradley\ClientFlow' -Recurse -Filter *.php |
    ForEach-Object { Select-String -Path $_.FullName -Pattern '<script>' } | Measure-Object).Count
Check "zero inline <script> blocks" ($inline -eq 0) "found=$inline"

Write-Output "`n=== 9. Password toggle moved to app.js ==="
$loginSrc = Get-Content 'C:\Users\Bradley\ClientFlow\auth\login.php' -Raw
Check "login.php loads app.js" ($loginSrc -match 'assets/js/app.js')
$appJs = Get-Content 'C:\Users\Bradley\ClientFlow\assets\js\app.js' -Raw
Check "app.js contains the toggle handler" ($appJs -match 'togglePassword')

# --- clean up ---------------------------------------------------------
Write-Output "`n=== cleaning up ==="
# The seeded accounts were never touched: this script signs in as accounts it
# created, so there is nothing to put back. Only its own rows are removed.
Sql "USE clientflow_crm;
     DELETE FROM users WHERE email IN ('$V1Plain', '$V1Forced');
     DELETE FROM login_attempts;" | Out-Null
$leftovers = (Sql "USE clientflow_crm; SELECT COUNT(*) FROM users WHERE email LIKE '%@regression.test';")
Check "throwaway accounts removed" ($leftovers -eq '0') "left=$leftovers"
$seededUntouched = (Sql "USE clientflow_crm;
    SELECT GROUP_CONCAT(CONCAT(id,':',password_hash) ORDER BY id SEPARATOR '|') FROM users WHERE id IN (1,2,3,4);")
Check "seeded demo passwords untouched" ($seededUntouched -eq $seededHashesBefore)

Write-Output "`n----------------------------------------"
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output "----------------------------------------"
if ($script:fail -gt 0) { exit 1 }
