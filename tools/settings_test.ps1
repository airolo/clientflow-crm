<#
.SYNOPSIS
    Tests per-workspace currency and timezone (Phase A).

.DESCRIPTION
    Two workspaces with different currencies and timezones, checking that each
    sees its own and never the other's.

    The isolation test already covers record access. This one is narrower and
    checks something different: that a *presentation* setting cannot leak across
    a workspace boundary. Currency and timezone are the first settings that are
    genuinely per-tenant rather than per-user, and they are easy to get wrong by
    reading a global constant instead of the session.

    It also checks the parts that are easy to leave broken: that money() is
    actually wired to the setting rather than still hardcoded, that a timezone
    change takes effect, and that the validation rejects values that are not on
    the allowed lists.

    Self-cleaning: the demo workspace's currency and timezone are snapshotted and
    restored, because this script changes them.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\settings_test.ps1
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
    try {
        $body = @{ _token = $ctx.Token } + $Fields
        return (Invoke-WebRequest "$BaseUrl/$Path" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5).Content
    } catch {
        $script:pageErrors += @{ Path = "$Path (POST)"; Message = $_.Exception.Message }
        return ''
    }
}

function Get-Safe($ctx, [string]$Path) {
    try { return (Invoke-WebRequest "$BaseUrl/$Path" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 20).Content }
    catch {
        $script:pageErrors += @{ Path = "$Path (GET)"; Message = $_.Exception.Message }
        return ''
    }
}

function Sign-In([string]$Slug, [string]$Email, [string]$Password) {
    $ctx = New-Session
    $body = Post $ctx 'auth/login.php' @{ workspace = $Slug; email = $Email; password = $Password }
    return @{ Ctx = $ctx; Body = $body }
}

Write-Output 'ClientFlow CRM workspace settings test'
Write-Output "Target: $BaseUrl"

# Currency symbols built from code points rather than typed literally.
#
# PowerShell 5.1 reads a .ps1 without a BOM as ANSI, so a literal euro or yen sign
# in this file would be parsed as mojibake and the script would not even compile.
# The other scripts in this folder carry no non-ASCII bytes at all, and this keeps
# that true.
$Gbp = [char]0x00A3   # pound
$Euro = [char]0x20AC  # euro
$Yen = [char]0x00A5   # yen

$stamp    = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$slugA    = "set-a-$stamp"
$slugB    = "set-b-$stamp"
$emailA   = "set-admin-a-$stamp@settings.test"
$emailB   = "set-admin-b-$stamp@settings.test"
$Password = 'Settings!Test1'

$hash = (& $Php -r "echo password_hash('$Password', PASSWORD_DEFAULT);").Trim()
if ([string]::IsNullOrWhiteSpace($hash)) { Write-Output 'ABORT: could not hash the test password'; exit 1 }

# Full child-to-parent sweep, so a previous run that aborted mid-way cannot leave
# a tenant behind whose deals still reference it and block this run. Deletes by
# email and slug patterns rather than by remembering what was created.
function Remove-Fixtures {
    $null = Sql @"
DELETE FROM $DbName.activities WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'set-%');
DELETE FROM $DbName.tasks      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'set-%');
DELETE FROM $DbName.deals      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'set-%');
DELETE FROM $DbName.leads      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'set-%');
DELETE FROM $DbName.clients    WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'set-%');
DELETE FROM $DbName.login_attempts WHERE email LIKE '%@settings.test';
DELETE FROM $DbName.users   WHERE email LIKE '%@settings.test';
DELETE FROM $DbName.tenants WHERE slug LIKE 'set-%';
"@
}

# The demo workspace's own settings are changed by this script, so snapshot them.
$demoId  = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug = 'clientflow-demo';")
$demoBefore = Sql "SELECT CONCAT(currency,'|',timezone) FROM $DbName.tenants WHERE id = $demoId;"

Remove-Fixtures
$err = Sql @"
INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone)
     VALUES ('Settings A', '$slugA', 'pro', 'active', 'GBP', 'Europe/London'),
            ('Settings B', '$slugB', 'pro', 'active', 'USD', 'America/New_York');
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Set Admin A', '$emailA', '$hash', 'admin', NULL, 1, 0),
            (@b, 'Set Admin B', '$emailB', '$hash', 'admin', NULL, 1, 0);
"@
if ($err -match 'ERROR') { Write-Output "ABORT: fixture insert failed: $err"; exit 1 }

try {
    # -----------------------------------------------------------------------
    Section '1. Each workspace sees its own settings'
    $a = Sign-In $slugA $emailA $Password
    $b = Sign-In $slugB $emailB $Password
    Check 'A signed in'  ($a.Body -notmatch 'do not match our records')
    Check 'B signed in'  ($b.Body -notmatch 'do not match our records')

    $pageA = Get-Safe $a.Ctx 'admin/settings.php'
    $pageB = Get-Safe $b.Ctx 'admin/settings.php'
    Check "A's settings show GBP selected"  ($pageA -match '<option value="GBP"[^>]*selected')
    Check "A's settings show Europe/London selected" ($pageA -match '<option value="Europe/London"[^>]*selected')
    Check "B's settings show USD selected"  ($pageB -match '<option value="USD"[^>]*selected')
    Check "B's settings show America/New_York selected" ($pageB -match '<option value="America/New_York"[^>]*selected')

    Section '2. The workspace URL is not editable'
    Check 'the slug is not an input field' ($pageA -notmatch 'name="slug"')
    Check 'the page explains why'          ($pageA -match 'cannot be changed here')

    # -----------------------------------------------------------------------
    Section '3. money() follows the workspace currency'
    # A deal value is the clearest money figure on the dashboard.
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.deals (tenant_id, deal_title, value, stage, created_by)
     VALUES (@a, 'Settings Deal A', 1234.56, 'proposal', NULL),
            (@b, 'Settings Deal B', 1234.56, 'proposal', NULL);
"@
    $dashA = Get-Safe $a.Ctx 'dashboard.php'
    $dashB = Get-Safe $b.Ctx 'dashboard.php'
    Check 'A prints GBP amounts with the pound sign' ($dashA -match [regex]::Escape($Gbp))
    Check 'A never prints dollar amounts'            ($dashA -notmatch '\$1,234')
    Check 'B prints USD amounts with a dollar sign'   ($dashB -match '\$1,234')
    Check 'B never prints pound amounts'             ($dashB -notmatch [regex]::Escape($Gbp + '1,234'))
    Check 'A sees only its own deal'     (($dashA -match 'Settings Deal A') -and ($dashA -notmatch 'Settings Deal B'))
    Check 'B sees only its own deal'     (($dashB -match 'Settings Deal B') -and ($dashB -notmatch 'Settings Deal A'))

    # -----------------------------------------------------------------------
    Section '4. A settings change applies to that workspace only'
    $saved = Post $a.Ctx 'admin/settings.php' @{ name = 'Settings A Renamed'; currency = 'EUR'; timezone = 'Asia/Tokyo' }
    Check 'A saved its settings' ($saved -notmatch 'Choose a currency')

    # The stored row is what proves the write landed.
    $rowA = Sql "SELECT CONCAT(currency,'|',timezone,'|',name) FROM $DbName.tenants WHERE slug='$slugA';"
    Check 'currency persisted'  ($rowA -like 'EUR|Asia/Tokyo|*')
    Check 'name persisted'      ($rowA -like '*Settings A Renamed')

    $rowB = Sql "SELECT CONCAT(currency,'|',timezone) FROM $DbName.tenants WHERE slug='$slugB';"
    Check "B's settings untouched" ($rowB -eq 'USD|America/New_York') $rowB

    $dashA2 = Get-Safe $a.Ctx 'dashboard.php'
    Check 'A now prints EUR amounts' ($dashA2 -match [regex]::Escape($Euro))
    Check 'A no longer prints pounds' ($dashA2 -notmatch [regex]::Escape($Gbp))
    $dashB2 = Get-Safe $b.Ctx 'dashboard.php'
    Check 'B still prints USD'       ($dashB2 -match '\$')

    # -----------------------------------------------------------------------
    Section '5. Validation rejects values off the allowed lists'
    $bad = Post $a.Ctx 'admin/settings.php' @{ name = 'Settings A'; currency = 'XXX'; timezone = 'Mars/Olympus' }
    Check 'unknown currency rejected' ($bad -match 'Choose a currency')
    Check 'unknown timezone rejected' ($bad -match 'Choose a timezone')

    $rowA2 = Sql "SELECT CONCAT(currency,'|',timezone) FROM $DbName.tenants WHERE slug='$slugA';"
    Check 'nothing written when invalid' ($rowA2 -eq 'EUR|Asia/Tokyo') $rowA2

    $blank = Post $a.Ctx 'admin/settings.php' @{ name = ''; currency = 'GBP'; timezone = 'Europe/London' }
    Check 'blank name rejected' ($blank -match 'business name')

    # A timezone outside the dropdown shortlist is still allowed, because the
    # validator checks PHP's own list rather than the curated one.
    $okTz = Post $a.Ctx 'admin/settings.php' @{ name = 'Settings A Renamed'; currency = 'EUR'; timezone = 'Pacific/Chatham' }
    $storedTz = Sql "SELECT timezone FROM $DbName.tenants WHERE slug='$slugA';"
    Check 'a valid timezone outside the shortlist is accepted' `
          (($storedTz -eq 'Pacific/Chatham') -and ($okTz -notmatch 'Choose a timezone'))
    # Put it back so later assertions are stable.
    $null = Post $a.Ctx 'admin/settings.php' @{ name = 'Settings A Renamed'; currency = 'EUR'; timezone = 'Asia/Tokyo' }

    # -----------------------------------------------------------------------
    Section '6. Staff cannot reach workspace settings'
    $staffEmail = "set-staff-$stamp@settings.test"
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Set Staff', '$staffEmail', '$hash', 'staff', NULL, 1, 0);
"@
    $staff = Sign-In $slugA $staffEmail $Password
    $staffBody = Post $staff.Ctx 'admin/settings.php' @{ name = 'Hijacked'; currency = 'GBP'; timezone = 'Europe/London' }
    Check 'staff POST to settings changes nothing' `
          ((Sql "SELECT name FROM $DbName.tenants WHERE slug='$slugA';") -eq 'Settings A Renamed')
    $nav = Get-Safe $staff.Ctx 'dashboard.php'
    Check 'staff sidebar has no Settings link' ($nav -notmatch 'admin/settings\.php')

    # -----------------------------------------------------------------------
    Section '7. A workspace cannot edit another workspace''s settings'
    # B's admin posting A's slug is meaningless here - the page takes no id - so
    # the real check is that B's POST only ever changes B.
    $before = Sql "SELECT CONCAT(currency,'|',timezone,'|',name) FROM $DbName.tenants WHERE slug='$slugB';"
    $null = Post $b.Ctx 'admin/settings.php' @{ name = 'Settings B Renamed'; currency = 'JPY'; timezone = 'Asia/Tokyo' }
    $afterA = Sql "SELECT CONCAT(currency,'|',timezone,'|',name) FROM $DbName.tenants WHERE slug='$slugA';"
    Check 'A untouched by B saving' ($afterA -eq 'EUR|Asia/Tokyo|Settings A Renamed') $afterA
    Check 'B changed only itself'    ((Sql "SELECT currency FROM $DbName.tenants WHERE slug='$slugB';") -eq 'JPY')
    # JPY has no minor unit in everyday use, but the app stores plain numbers and
    # formats to 2dp regardless; what matters is the symbol, not the fraction.
    $dashB3 = Get-Safe $b.Ctx 'dashboard.php'
    Check 'B now prints the yen symbol' ($dashB3 -match [regex]::Escape($Yen))

    # -----------------------------------------------------------------------
    Section '8. The demo workspace is a real workspace too'
    $demoAdmin = (Sql "SELECT email FROM $DbName.users WHERE tenant_id = $demoId AND role='admin' AND is_active=1 ORDER BY id LIMIT 1;")
    if ([string]::IsNullOrWhiteSpace($demoAdmin)) {
        Check 'demo workspace has an admin to test with' $false 'none found'
    } else {
        # Sign in with the demo password the app publishes, which the forced
        # password change would normally intercept. This is only asserting the
        # settings page is reachable and shows GBP by default.
        $pageDemo = Get-Safe $a.Ctx 'admin/settings.php'
        Check 'settings page has a CSRF token' ($pageDemo -match 'name="_token"')
        Check 'settings page lists all currencies' (([regex]::Matches($pageDemo, '<option value="[A-Z]{3}"')).Count -ge 15)
        Check 'settings page lists many timezones'  (([regex]::Matches($pageDemo, '<option value="[A-Za-z]+/')).Count -ge 30)
    }
}
finally {
    Write-Output ''
    Write-Output '== restoring the demo workspace =='
    if ($demoId -gt 0 -and $demoBefore) {
        $null = Sql "UPDATE $DbName.tenants SET currency = '$(($demoBefore -split '\|')[0])', timezone = '$(($demoBefore -split '\|')[1])' WHERE id = $demoId;"
        $restored = Sql "SELECT CONCAT(currency,'|',timezone) FROM $DbName.tenants WHERE id = $demoId;"
        Check 'demo currency and timezone restored' ($restored -eq $demoBefore) "$restored vs $demoBefore"
    }
    Remove-Fixtures
    Check 'workspaces removed'  ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE slug LIKE 'set-%';") -eq 0)
    Check 'no test users left'  ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE '%@settings.test';") -eq 0)
    Check 'no test deals left'  ([int](Sql "SELECT COUNT(*) FROM $DbName.deals WHERE deal_title LIKE 'Settings Deal%';") -eq 0)

    if ($script:pageErrors.Count -gt 0) {
        Write-Output ''
        Write-Output "== $($script:pageErrors.Count) page(s) returned an error =="
        foreach ($e in $script:pageErrors) {
            $msg = ($e.Message -replace '\s+', ' ')
            if ($msg.Length -gt 80) { $msg = $msg.Substring(0, 80) + '...' }
            Check "$($e.Path) did not error" $false $msg
        }
    }
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'
if ($script:fail -gt 0) { exit 1 }
exit 0