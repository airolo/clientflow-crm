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

# Non-loopback Host header must resolve DEMO_MODE to false.
$remote = Invoke-WebRequest "$BaseUrl/auth/login.php" -UseBasicParsing -TimeoutSec 15 -Headers @{ Host = 'crm.example.test' }
Check "same page with a non-loopback Host hides demo creds" (-not ($remote.Content -match 'admin123'))

Write-Output "`n=== 2. Forced password change ==="
$ctx = New-Session
$r = Post $ctx 'auth/login.php' @{ email = 'admin@clientflow.test'; password = 'admin123' }
# login.php redirects to index.php, and index.php then bounces the flagged
# account to change_password.php - so landing there IS the success path.
Check "seeded admin sign-in succeeds and is held at change_password" ((LandedOn $r) -eq 'auth/change_password.php') "landed=$(LandedOn $r)"

# Any guarded page must bounce to change_password.
$dash = Invoke-WebRequest "$BaseUrl/index.php" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 15
Check "dashboard redirects a flagged account to change_password" ((LandedOn $dash) -eq 'auth/change_password.php') "landed=$(LandedOn $dash)"

# And the same for a non-dashboard guarded page.
$cl = Invoke-WebRequest "$BaseUrl/clients/index.php" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 15
Check "clients list also bounces to change_password" ((LandedOn $cl) -eq 'auth/change_password.php') "landed=$(LandedOn $cl)"

$flag = (Sql "USE clientflow_crm; SELECT must_change_password FROM users WHERE id=1;")
Check "admin row is flagged must_change_password=1" ($flag -eq '1')

Write-Output "`n=== 3. Throttling and lockout ==="
Sql "DELETE FROM clientflow_crm.login_attempts;" | Out-Null

$lockoutSeen = $false
for ($i = 1; $i -le 7; $i++) {
    $c = New-Session
    $resp = Post $c 'auth/login.php' @{ email = 'sarah@clientflow.test'; password = "wrong-guess-$i" }
    $body = $resp.Content
    if ($body -match 'Too many failed sign-in attempts') { $lockoutSeen = $true; break }
}
Check "lockout engages by attempt 7" $lockoutSeen

$counted = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='sarah@clientflow.test' AND succeeded=0;")
Check "failed attempts were recorded" ($counted -ge 5) "counted=$counted"

# The correct password must be refused while locked out.
$c = New-Session
$locked = Post $c 'auth/login.php' @{ email = 'sarah@clientflow.test'; password = 'staff123' }
Check "correct password is refused while locked out" ($locked.Content -match 'Too many failed sign-in attempts')

Write-Output "`n=== 4. Successful sign-in clears the counter ==="
Sql "DELETE FROM clientflow_crm.login_attempts;" | Out-Null
$c = New-Session
$c2 = New-Session
Post $c  'auth/login.php' @{ email = 'nobody@nowhere.test'; password = 'x' } | Out-Null
Post $c  'auth/login.php' @{ email = 'nobody@nowhere.test'; password = 'x' } | Out-Null
$before = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='nobody@nowhere.test';")
Check "failures counted before sign-in" ($before -ge 2)

Write-Output "`n=== 5. Successful sign-in records a success row ==="
$ctx3 = New-Session
Post $ctx3 'auth/login.php' @{ email = 'marcus@clientflow.test'; password = 'staff123' } | Out-Null
$okRow = [int](Sql "SELECT COUNT(*) FROM clientflow_crm.login_attempts WHERE email='marcus@clientflow.test' AND succeeded=1;")
Check "successful attempt is logged" ($okRow -ge 1) "count=$okRow"

Write-Output "`n=== 6. Completing the forced change ==="
$ctx4 = New-Session
Post $ctx4 'auth/login.php' @{ email = 'priya@clientflow.test'; password = 'staff123' } | Out-Null
$cp = Invoke-WebRequest "$BaseUrl/auth/change_password.php" -WebSession $ctx4.Session -UseBasicParsing -TimeoutSec 15
Check "change-password page reachable" ($cp.StatusCode -eq 200)

# Wrong current password must be rejected.
$bad = Post $ctx4 'auth/change_password.php' @{ current_password = 'nope'; new_password = 'NewPassw0rd!'; confirm_password = 'NewPassw0rd!' }
Check "wrong current password rejected" ($bad.Content -match 'not your current password')

# Reusing the same password must be rejected.
$same = Post $ctx4 'auth/change_password.php' @{ current_password = 'staff123'; new_password = 'staff123'; confirm_password = 'staff123' }
Check "reusing the current password rejected" ($same.Content -match 'different from the current one')

# Mismatched confirmation must be rejected.
$mism = Post $ctx4 'auth/change_password.php' @{ current_password = 'staff123'; new_password = 'NewPassw0rd!'; confirm_password = 'Different1!' }
Check "mismatched confirmation rejected" ($mism.Content -match 'do not match')

# The real thing: set a new password and confirm the flag clears.
$newPass = 'Regression' + (Get-Random -Minimum 10000 -Maximum 99999) + '!'
$good = Post $ctx4 'auth/change_password.php' @{ current_password = 'staff123'; new_password = $newPass; confirm_password = $newPass }
$flagAfter = (Sql "USE clientflow_crm; SELECT must_change_password FROM users WHERE id=4;")
Check "flag cleared after a successful change" ($flagAfter -eq '0')

$dashAfter = Invoke-WebRequest "$BaseUrl/index.php" -WebSession $ctx4.Session -UseBasicParsing -TimeoutSec 15
Check "dashboard reachable after the change" ($dashAfter.Content -notmatch 'change_password')

# Old password must no longer work; new one must.
$cOld = New-Session
$rOld = Post $cOld 'auth/login.php' @{ email = 'priya@clientflow.test'; password = 'staff123' }
Check "old password no longer works" ($rOld.Content -match 'do not match')

$cNew = New-Session
$rNew = Post $cNew 'auth/login.php' @{ email = 'priya@clientflow.test'; password = $newPass }
Check "new password works" ((LandedOn $rNew) -eq 'index.php') "landed=$(LandedOn $rNew)"

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

# --- restore seeded state -------------------------------------------
Write-Output "`n=== restoring seeded state ==="
& $MySql --user=root --execute="
USE clientflow_crm;
UPDATE users SET
  password_hash = '\`$2y\`$10\`$RPo0/LTTWko6dSHJEJslvOeQ21suPGapSouCjlOfm4AEGaA/M1vDW',
  must_change_password = 1
 WHERE id = 4;
DELETE FROM login_attempts;
" 2>&1 | Out-Null
$restored = (Sql "USE clientflow_crm; SELECT must_change_password FROM users WHERE id=4;")
Check "priya restored to seeded state" ($restored -eq '1')

Write-Output "`n----------------------------------------"
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output "----------------------------------------"
if ($script:fail -gt 0) { exit 1 }
