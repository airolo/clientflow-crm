<#
    ClientFlow CRM - regression suite
    ---------------------------------------------------------------
    Drives the running site over HTTP and asserts on real behaviour.

    Prerequisites:
      - Apache and MySQL running in the XAMPP Control Panel
      - project copied to C:\xampp\htdocs\ClientFlow
      - database.sql imported

    Usage:
      powershell -ExecutionPolicy Bypass -File tools\regression.ps1
      powershell -ExecutionPolicy Bypass -File tools\regression.ps1 -BaseUrl http://localhost/clientflow

    Checks the PHP error log after each page load rather than trusting the
    HTTP response, because display_errors can be On and still hide warnings
    from a body-level scan.
#>
[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost/clientflow',
    [string]$LogPath = 'C:\xampp\apache\logs\error.log',
    [string]$DbName  = 'clientflow_crm',
    # Source tree, for the checks that read files rather than the running app.
    # Resolved in the body, not here: $PSScriptRoot is not yet populated when a
    # param default is evaluated under PowerShell 5.1.
    [string]$ProjectRoot = ''
)

# $PSScriptRoot is reliably set once the script body starts.
if ($ProjectRoot -eq '') { $ProjectRoot = Split-Path -Parent $PSScriptRoot }

$ErrorActionPreference = 'Continue'
$script:Passed = 0
$script:Failed = 0
$script:Failures = @()

# ---------------------------------------------------------------- helpers

function Invoke-App([string]$Method, [string]$Path, $Session, [hashtable]$Fields = $null) {
    # Accepts a project-relative path ('clients/index.php') or a Location header
    # straight from a redirect, which the app emits as an absolute path
    # prefixed with APP_URL ('/ClientFlow/clients/index.php').
    $uri = if ($Path -match '^https?://') { $Path }
           elseif ($Path.StartsWith('/')) { "http://localhost$Path" }
           else { "$BaseUrl/$Path" }
    $req = [System.Net.HttpWebRequest]::Create($uri)
    $req.Method = $Method
    $req.AllowAutoRedirect = $false
    $req.Timeout = 25000
    if ($Session) { $req.CookieContainer = $Session }
    if ($Fields) {
        $body = ''
        foreach ($k in $Fields.Keys) {
            $body += [uri]::EscapeDataString("$k") + '=' + [uri]::EscapeDataString("$($Fields[$k])") + '&'
        }
        $body = $body.TrimEnd('&')
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
        $req.ContentType = 'application/x-www-form-urlencoded'
        $req.ContentLength = $bytes.Length
        $stream = $req.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
    }
    try {
        $resp = $req.GetResponse()
        $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $text = $reader.ReadToEnd(); $reader.Close()
        return @{ Code = [int]$resp.StatusCode; Location = [string]$resp.Headers['Location']; Body = $text }
    } catch {
        $resp = $_.Exception.Response
        if (-not $resp) { return @{ Code = -1; Location = 'NO-RESPONSE'; Body = '' } }
        $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $text = $reader.ReadToEnd(); $reader.Close()
        return @{ Code = [int]$resp.StatusCode; Location = [string]$resp.Headers['Location']; Body = $text }
    }
}

function Invoke-Sql([string]$Sql) {
    $wrapped = "USE $DbName; $Sql"
    return ((& "C:\xampp\mysql\bin\mysql.exe" --user=root -N -B --execute="$wrapped" 2>&1) | Out-String).Trim()
}

function Get-Token([string]$Path, $Session) {
    $page = Invoke-App 'GET' $Path $Session
    return [regex]::Match($page.Body, 'name="_token" value="([a-f0-9]+)"').Groups[1].Value
}

function Get-LogErrors {
    if (-not (Test-Path $LogPath)) { return @() }
    return @(Get-Content $LogPath | Where-Object {
        $_ -match 'PHP (Warning|Notice|Fatal|Deprecated|Parse)' -or $_ -match 'File does not exist'
    })
}

function Get-Status([string]$Path) {
    # Invoke-WebRequest is used rather than the raw WebRequest helper because
    # .NET discards the response object for 403, which hides the status code.
    try {
        $r = Invoke-WebRequest "$BaseUrl/$Path" -UseBasicParsing -TimeoutSec 15
        return [int]$r.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode.value__ }
        return 0
    }
}

function Assert([string]$Label, [bool]$Condition, [string]$Detail = '') {
    if ($Condition) {
        $script:Passed++
        Write-Output "  PASS  $Label"
    } else {
        $script:Failed++
        $script:Failures += $Label
        Write-Output "  FAIL  $Label   [$Detail]"
    }
}

function Section([string]$Title) {
    Write-Output ""
    Write-Output "== $Title =="
}

function Clear-Log {
    if (Test-Path $LogPath) { Clear-Content $LogPath -ErrorAction SilentlyContinue }
}

# ---------------------------------------------------------------- preflight

Write-Output "ClientFlow CRM regression suite"
Write-Output "Target: $BaseUrl"

try {
    $probe = Invoke-App 'GET' 'auth/login.php' $null
} catch {
    Write-Output ""
    Write-Output "ABORT: cannot reach $BaseUrl - is Apache running?"
    exit 1
}
if ($probe.Code -ne 200) {
    Write-Output "ABORT: login.php returned $($probe.Code)"
    exit 1
}

# ---------------------------------------------------------------------------
# Throwaway accounts
#
# The suite signs in as accounts it creates itself rather than the seeded demo
# ones. That matters because the app forces every seeded account to change its
# password: using them meant that following the app's own instructions broke the
# tests with "ABORT: admin login failed", and the suite had to reach into the
# seeded accounts (clearing must_change_password, then restoring it) to get in.
#
# The suite also deliberately never resets a password. Overwriting the admin
# password of whatever database it is pointed at would be far worse than failing.
# Everything it touches, it created first.
# ---------------------------------------------------------------------------
$TestPass = 'Regression!Test1'
# bcrypt of $TestPass, generated with PHP password_hash(PASSWORD_DEFAULT).
$TestHash = '$2y$10$aTWww8OncS5sYUl7TAqNe.hdpKGyghs8CdZ1y242gPsvwz/iyBXju'
$AdminEmail = 'regression-admin@clientflow.test'
$StaffEmail = 'regression-staff@clientflow.test'

# Sign-in is workspace + email, so the suite has to know which workspace it is
# signing in to. Tenant 1 is the demo workspace in database.sql.
$TenantId   = [int](Invoke-Sql "SELECT id FROM tenants ORDER BY id LIMIT 1;")
$TenantSlug = [string](Invoke-Sql "SELECT slug FROM tenants ORDER BY id LIMIT 1;")
if ($TenantId -le 0 -or [string]::IsNullOrWhiteSpace($TenantSlug)) {
    Write-Output 'ABORT: no tenant exists. Import database.sql or run migrations/003_multi_tenancy.sql first.'
    exit 1
}
Write-Output "  workspace under test: $TenantSlug (tenant $TenantId)"

# Sweep anything a previous aborted run left behind, BEFORE the baseline is
# captured. Otherwise a leftover row is counted as part of the baseline, and
# the cleanup at the end removes it - which fails the "counts restored" check
# and makes the suite unable to run twice in a row.
Invoke-Sql "DELETE FROM clients WHERE company_name LIKE 'Regression%'
                        OR company_name LIKE 'Redirect%'
                        OR company_name LIKE 'Xss%'
                        OR company_name LIKE 'Flash%'
                        OR company_name LIKE 'Probe%';
            DELETE FROM leads      WHERE lead_name   LIKE 'Regression%' OR lead_name LIKE 'Flash%';
            DELETE FROM tasks      WHERE title       LIKE 'Regression%' OR title LIKE 'Flash%';
            DELETE FROM deals      WHERE deal_title  LIKE 'Regression%' OR deal_title LIKE 'Flash%';
            DELETE FROM activities WHERE title       LIKE 'Regression%' OR title LIKE 'Flash%';
            DELETE FROM users      WHERE email LIKE '%regression%' OR email LIKE '%@regression.test';
            DELETE FROM login_attempts;" | Out-Null

# Baseline is captured BEFORE the throwaway accounts are created, so cleanup can
# assert the database is back to exactly the state it found.
$baseline = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM users),    '/',
    (SELECT COUNT(*) FROM clients),  '/',
    (SELECT COUNT(*) FROM leads),    '/',
    (SELECT COUNT(*) FROM deals),    '/',
    (SELECT COUNT(*) FROM tasks),    '/',
    (SELECT COUNT(*) FROM activities))"

# Snapshot the seeded accounts so cleanup can both restore and prove they were
# untouched. The last-admin test below attempts a demotion; if that attempt ever
# wrongly succeeds it must not be able to leave the demo admin as a staff user.
$seededUsersBefore = Invoke-Sql "SELECT GROUP_CONCAT(CONCAT(id,':',role,':',is_active,':',must_change_password) ORDER BY id SEPARATOR '|')
                                  FROM users WHERE id IN (1,2,3,4);"

Invoke-Sql "DELETE FROM users WHERE email IN ('$AdminEmail', '$StaffEmail');
            INSERT INTO users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
            VALUES ($TenantId, 'Regression Admin', '$AdminEmail', '$TestHash', 'admin', NULL, 1, 0),
                   ($TenantId, 'Regression Staff', '$StaffEmail', '$TestHash', 'staff', NULL, 1, 0);" | Out-Null

$AdminId = [int](Invoke-Sql "SELECT id FROM users WHERE email = '$AdminEmail';")
$StaffId = [int](Invoke-Sql "SELECT id FROM users WHERE email = '$StaffEmail';")
if ($AdminId -le 0 -or $StaffId -le 0) {
    Write-Output 'ABORT: could not create the throwaway test accounts'
    exit 1
}

# One client and one lead assigned to the suite's staff user. Created here
# rather than in the read-access section because the staff page-render sweep
# earlier on has to open a detail page the staff user actually owns - can_view()
# now blocks seeded detail pages, so it cannot borrow client 1 any more.
Invoke-Sql "DELETE FROM clients WHERE company_name = 'Regression OwnedByStaff';
            DELETE FROM leads   WHERE lead_name    = 'Regression LeadForStaff';
            INSERT INTO clients (tenant_id, company_name, contact_person, status, assigned_to, created_by)
             VALUES ($TenantId, 'Regression OwnedByStaff', 'Staff Contact', 'prospect', $StaffId, $AdminId);
            INSERT INTO leads (tenant_id, lead_name, lead_source, status, estimated_value, assigned_to, created_by)
            VALUES ($TenantId, 'Regression LeadForStaff', 'website', 'new', 10, $StaffId, $AdminId);" | Out-Null

$StaffFixtureClient = [int](Invoke-Sql "SELECT id FROM clients WHERE company_name='Regression OwnedByStaff' LIMIT 1;")
$StaffFixtureLead   = [int](Invoke-Sql "SELECT id FROM leads WHERE lead_name='Regression LeadForStaff' LIMIT 1;")
if ($StaffFixtureClient -le 0 -or $StaffFixtureLead -le 0) {
    Write-Output 'ABORT: could not create the staff fixtures'
    exit 1
}

$admin = New-Object System.Net.CookieContainer
$adminLogin = Invoke-App 'POST' 'auth/login.php' $admin @{
    _token = (Get-Token 'auth/login.php' $admin)
    workspace = $TenantSlug; email = $AdminEmail; password = $TestPass
}
if ($adminLogin.Location -notmatch 'dashboard\.php$') {
    Write-Output "ABORT: admin login failed (got '$($adminLogin.Location)')"
    exit 1
}

$staff = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $staff @{
    _token = (Get-Token 'auth/login.php' $staff)
    workspace = $TenantSlug; email = $StaffEmail; password = $TestPass
} | Out-Null

# ---------------------------------------------------------------- 1. assets

Section 'Static assets'
foreach ($asset in @(
    @{ p = 'assets/vendor/css/bootstrap.min.css';      min = 200000 },
    @{ p = 'assets/vendor/css/bootstrap-icons.min.css'; min = 80000 },
    @{ p = 'assets/vendor/js/bootstrap.bundle.min.js'; min = 70000 },
    @{ p = 'assets/vendor/fonts/bootstrap-icons.woff2'; min = 100000 },
    @{ p = 'assets/vendor/fonts/bootstrap-icons.woff';  min = 150000 },
    @{ p = 'assets/css/style.css';                      min = 5000 },
    @{ p = 'assets/js/app.js';                          min = 500 }
)) {
    $r = Invoke-App 'GET' $asset.p $null
    Assert "serves $($asset.p)" ($r.Code -eq 200 -and $r.Body.Length -ge $asset.min) "code=$($r.Code) len=$($r.Body.Length)"
}
Assert 'no CDN references' (-not ($probe.Body -match 'cdn\.jsdelivr|unpkg\.com|cdnjs'))

# A Bootstrap Icon class that does not exist renders as an empty <i> - no error,
# no warning, just a blank space. That is exactly how the sidebar's "Users" item
# lost its icon without anything failing. So every bi-* class the app references
# is checked against the vendored icon font.
$iconCss = Get-Content (Join-Path $ProjectRoot 'assets\vendor\css\bootstrap-icons.min.css') -Raw

# Strip comments first: a prose mention of a bad icon name (for instance the
# note in views/sidebar.php recording this very regression) is documentation,
# not usage, and must not fail the check.
function Remove-Comments([string]$text) {
    $text = [regex]::Replace($text, '(?s)/\*.*?\*/', ' ')          # /* ... */
    $text = [regex]::Replace($text, '(?m)^\s*//.*$', ' ')           # // ...
    $text = [regex]::Replace($text, '(?m)^\s*#(?!\!).*$', ' ')      # # ... (php)
    $text = [regex]::Replace($text, '(?s)<!--.*?-->', ' ')          # <!-- ... -->
    return $text
}

$iconClasses = Get-ChildItem $ProjectRoot -Recurse -Include *.php, *.js |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object { Remove-Comments (Get-Content $_.FullName -Raw) } |
    ForEach-Object { [regex]::Matches($_, 'bi-[a-z0-9-]+') } |
    ForEach-Object { $_.Value } |
    Sort-Object -Unique

$missingIcons = @()
foreach ($ic in $iconClasses) {
    # Skip names that are only a prefix, built up by concatenation such as
    # 'bi-caret-' . ($dir) . '-fill'; those are checked as their final values.
    if ($ic -match '-$') { continue }
    # Selectors are written as .bi-name::before or .bi-name,.bi-other{...}
    if (-not [regex]::IsMatch($iconCss, '\.' + [regex]::Escape($ic) + '(?=::|[,\s{])')) {
        $missingIcons += $ic
    }
}
Assert 'every referenced icon exists in the vendored font' ($missingIcons.Count -eq 0) `
    "missing: $($missingIcons -join ', ')"

# The concatenated caret icons used by the sort links are resolved at runtime,
# so check the values they actually produce.
foreach ($caret in @('bi-caret-up-fill', 'bi-caret-down-fill')) {
    Assert "$caret exists" ([regex]::IsMatch($iconCss, '\.' + [regex]::Escape($caret) + '(?=::|[,\s{])'))
}

# Guard the specific regression: the Users nav entry must carry a real icon.
$sidebar = Get-Content (Join-Path $ProjectRoot 'views\sidebar.php') -Raw
$usersIcon = [regex]::Match($sidebar, "'Users',\s*'icon'\s*=>\s*'([^']+)'").Groups[1].Value
Assert 'sidebar Users entry has an icon' ($usersIcon -ne '') "icon='$usersIcon'"
Assert 'sidebar Users icon is a real one' (
    $usersIcon -ne '' -and [regex]::IsMatch($iconCss, '\.' + [regex]::Escape($usersIcon) + '(?=::|[,\s{])')
) "icon='$usersIcon'"

# Raw Bootstrap Icons codepoints, used by landing.css for list markers because
# they cannot be expressed as a class. A wrong codepoint renders the wrong
# glyph and nothing complains: \f3e1 is bi-funnel, not a dash.
#
# Resolving the codepoints the page *actually uses* against the font is the only
# version of this check that bites - verifying the font's idea of \f2ea says
# nothing about whether the page uses \f2ea.
$landingCssForIcons = Get-Content (Join-Path $ProjectRoot 'assets\css\landing.css') -Raw
$allowedGlyphs = @('bi-check2', 'bi-dash')
$usedCodepoints = [regex]::Matches($landingCssForIcons, 'content:\s*"\\([0-9a-fA-F]{4})"')
Assert 'landing.css declares the expected list markers' ($usedCodepoints.Count -eq 2) "found $($usedCodepoints.Count)"
foreach ($m in $usedCodepoints) {
    $cp = $m.Groups[1].Value.ToLower()
    $hit = [regex]::Match($iconCss, '\.(bi-[a-z0-9-]+)::before\{content:"\\' + $cp + '"\}')
    $glyph = if ($hit.Success) { $hit.Groups[1].Value } else { 'not in the font' }
    # Concatenated rather than interpolated: "\$$cp" would expand PowerShell's
    # $$ automatic variable instead of printing the codepoint.
    Assert ('landing.css codepoint ' + $cp + ' is a list-marker glyph') `
        ($allowedGlyphs -contains $glyph) "resolved to $glyph"
}

# Every CSS custom property landing.css reads must be defined somewhere it can
# see. An undefined var() invalidates the whole declaration, which is how the
# hero headline rendered as invisible text: color:transparent on top of a
# gradient that did not exist.
$landingCss = Get-Content (Join-Path $ProjectRoot 'assets\css\landing.css') -Raw
$appCss     = Get-Content (Join-Path $ProjectRoot 'assets\css\style.css') -Raw
$definedVars = @{}
foreach ($css in @($landingCss, $appCss)) {
    foreach ($m in [regex]::Matches($css, '(--[a-z0-9-]+)\s*:')) { $definedVars[$m.Groups[1].Value] = $true }
}
$undefinedVars = @()
foreach ($m in [regex]::Matches($landingCss, 'var\((--[a-z0-9-]+)')) {
    if (-not $definedVars.ContainsKey($m.Groups[1].Value)) { $undefinedVars += $m.Groups[1].Value }
}
Assert 'every CSS variable landing.css uses is defined' ($undefinedVars.Count -eq 0) `
    "undefined: $(($undefinedVars | Sort-Object -Unique) -join ', ')"

# ---------------------------------------------------------------- 2. landing page

Section 'Landing page'
$anon = New-Object System.Net.CookieContainer
$root = Invoke-App 'GET' 'index.php' $anon
Assert 'site root serves the landing page' ($root.Code -eq 200) "code=$($root.Code)"
Assert 'landing page needs no sign-in' ($root.Location -notmatch 'login\.php')
Assert 'landing page is not the dashboard' ($root.Body -notmatch 'Welcome back')

$marketing = $root.Body
Assert 'has a hero headline' ($marketing -match 'lp-h1')
Assert 'offers Sign in' ($marketing -match 'auth/login\.php')
Assert 'offers Get started' ($marketing -match 'href="#setup"')
Assert 'has an about section' ($marketing -match 'id="about"')
Assert 'has a setup section' ($marketing -match 'id="setup"')
Assert 'has a skip link' ($marketing -match 'lp-skip')

# style.css owns the brand tokens, so it has to load first.
Assert 'landing page loads style.css before landing.css' (
    $marketing.IndexOf('css/style.css') -lt $marketing.IndexOf('css/landing.css')
)

foreach ($t in @('features', 'preview', 'workflow', 'integrations', 'faq')) {
    Assert "tab present: $t" (($marketing -match "id=`"tab-$t`"") -and ($marketing -match "id=`"panel-$t`""))
}

# CSP allows script-src 'self' only because nothing inline was added.
Assert 'landing page has no inline event handlers' (-not ($marketing -match 'on(click|change|submit|load|input)='))
Assert 'landing page has no inline script blocks' (-not ($marketing -match '<script>'))
Assert 'landing page makes no off-origin requests' (-not ($marketing -match '(?:src|href)="https?://'))

# A public page must not leak business data by being indexed or cached.
Assert 'landing page renders no client records' (-not ($marketing -match 'Northwind|Bluepeak|Ironbridge'))
Assert 'landing page renders no seeded passwords' (-not ($marketing -match 'admin123|staff123|\$2y\$'))
Assert 'landing page does not claim an open-source licence' (-not ($marketing -match '(?i)ClientFlow.{0,40}(MIT|open.?source) licen'))
Assert 'landing page admits the missing audit log' ($marketing -match 'audit log')
# CSV import and export now exist, so the landing page must no longer claim they
# are unbuilt. Asserting the absence of a stale claim, because the old assertion
# here was "admits CSV export is unbuilt" and it kept passing while the page was
# simply wrong.
Assert 'landing page no longer claims CSV export is missing' (-not ($marketing -match 'no CSV export|Not built yet.*CSV|CSV import and export.*No export button'))
Assert 'landing page offers workspace signup' ($marketing -match 'signup\.php')

# Screenshots must actually resolve, not fall back to the placeholder.
foreach ($shot in @('dashboard', 'clients', 'client', 'pipeline', 'reports', 'recycle')) {
    $r = Invoke-App 'GET' "assets/img/$shot.png" $anon
    Assert "screenshot served: $shot.png" ($r.Code -eq 200 -and $r.Body.Length -gt 20000) "code=$($r.Code) len=$($r.Body.Length)"
}

# A signed-in visitor should be offered the dashboard, not a second sign-in.
# Scoped to the nav/hero call to action: the Get started section keeps its
# "Sign in" button on purpose, because that section explains how to sign in.
$signedIn = Invoke-App 'GET' 'index.php' $admin
Assert 'signed-in visitor is offered the dashboard' ($signedIn.Body -match 'Open dashboard')
Assert 'signed-in nav swaps the sign-in button' (-not ($signedIn.Body -match 'btn-outline-secondary btn-sm'))
Assert 'anonymous visitor gets the sign-in button' ((Invoke-App 'GET' 'index.php' $anon).Body -match 'btn-outline-secondary btn-sm')

# The app itself must still be guarded now that it does not live at the root.
$guestRoot = New-Object System.Net.CookieContainer
$d = Invoke-App 'GET' 'dashboard.php' $guestRoot
Assert 'dashboard.php redirects when signed out' ($d.Location -match 'login\.php') "location=$($d.Location)"

# ---------------------------------------------------------------- 2. exposure

Section 'Sensitive files are not web-accessible'
foreach ($secret in @('database.sql', 'README.md', 'app/config/config.php',
                      'app/models/DealModel.php', 'views/header.php', '.vscode/settings.json',
                      '.git/HEAD', '.git/config', '.gitignore', '.gitattributes')) {
    $status = Get-Status $secret
    Assert "blocked: /$secret" ($status -eq 403 -or $status -eq 404 -or $status -eq 404) "status=$status"
}
foreach ($dir in @('config/', 'models/', 'includes/', '.vscode/', '.git/')) {
    $status = Get-Status $dir
    Assert "no listing: /$dir" ($status -eq 403 -or $status -eq 404) "status=$status"
}
# The app itself must still be reachable.
foreach ($open in @('dashboard.php', 'auth/login.php', 'assets/css/style.css', 'assets/vendor/css/bootstrap.min.css')) {
    $status = Get-Status $open
    Assert "still served: /$open" ($status -eq 200) "status=$status"
}

# ---------------------------------------------------------------- 3. auth

Section 'Authentication'
Assert 'admin reaches dashboard' ((Invoke-App 'GET' 'dashboard.php' $admin).Code -eq 200)
Assert 'staff blocked from /users' ((Invoke-App 'GET' 'admin/users.php' $staff).Location -match 'dashboard\.php$')
Assert 'staff nav hides Users' (-not ((Invoke-App 'GET' 'dashboard.php' $staff).Body -match 'users\.php'))
foreach ($page in @('dashboard.php', 'clients/index.php', 'leads/index.php', 'pipeline/index.php', 'tasks/index.php',
                    'activities/index.php', 'reports/index.php', 'admin/users.php', 'auth/profile.php')) {
    $guest = New-Object System.Net.CookieContainer
    $r = Invoke-App 'GET' $page $guest
    Assert "guest blocked: $page" ($r.Code -eq 302 -and $r.Location -match 'login\.php') "code=$($r.Code)"
}
$bad = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $bad @{
    _token = (Get-Token 'auth/login.php' $bad); workspace = $TenantSlug; email = $AdminEmail; password = 'nope'
} | Out-Null
Assert 'wrong password generic error' ((Invoke-App 'GET' 'auth/login.php' $bad).Body -match 'do not match our records')
$unknown = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $unknown @{
    _token = (Get-Token 'auth/login.php' $unknown); workspace = $TenantSlug; email = 'nobody@nowhere.test'; password = 'x'
} | Out-Null
Assert 'unknown email same error (no enumeration)' ((Invoke-App 'GET' 'auth/login.php' $unknown).Body -match 'do not match our records')

# ---------------------------------------------------------------- 3b. forced password change

Section 'Forced password change'
# Its own throwaway account, flagged so it is held at the change-password screen.
# The hash is the suite's test hash, so nothing here depends on a seeded password.
Invoke-Sql "DELETE FROM users WHERE email = 'forced@regression.test';
INSERT INTO users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
             VALUES ($TenantId, 'Forced Regression', 'forced@regression.test', '$TestHash', 'staff', NULL, 1, 1);" | Out-Null

$forced = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $forced @{
    _token = (Get-Token 'auth/login.php' $forced)
    workspace = $TenantSlug; email = 'forced@regression.test'; password = $TestPass
} | Out-Null

Assert 'flagged account lands on change_password' (
    (Invoke-App 'GET' 'dashboard.php' $forced).Location -match 'change_password\.php$')
Assert 'flagged account held off the client list' (
    (Invoke-App 'GET' 'clients/index.php' $forced).Location -match 'change_password\.php$')
Assert 'flagged account held off reports' (
    (Invoke-App 'GET' 'reports/index.php' $forced).Location -match 'change_password\.php$')

$cp = Invoke-App 'GET' 'auth/change_password.php' $forced
Assert 'change_password page renders' ($cp.Code -eq 200)
Assert 'change_password rejects a wrong current password' (
    (Invoke-App 'POST' 'auth/change_password.php' $forced @{
        _token = (Get-Token 'auth/change_password.php' $forced)
        current_password = 'wrong'; new_password = 'BrandNewPass1!'; confirm_password = 'BrandNewPass1!'
    }).Location -match 'change_password\.php$')
Assert 'flag still set after a failed change' (
    (Invoke-Sql "SELECT must_change_password FROM users WHERE email='forced@regression.test';") -eq '1')

$newPw = 'Regression' + (Get-Random -Minimum 10000 -Maximum 99999) + '!'
$done = Invoke-App 'POST' 'auth/change_password.php' $forced @{
    _token = (Get-Token 'auth/change_password.php' $forced)
    current_password = $TestPass; new_password = $newPw; confirm_password = $newPw
}
Assert 'successful change redirects away from change_password' ($done.Location -match 'dashboard\.php$')
Assert 'flag cleared in the database' (
    (Invoke-Sql "SELECT must_change_password FROM users WHERE email='forced@regression.test';") -eq '0')
Assert 'dashboard now reachable' ((Invoke-App 'GET' 'dashboard.php' $forced).Code -eq 200)

$oldPw = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $oldPw @{
    _token = (Get-Token 'auth/login.php' $oldPw)
    workspace = $TenantSlug; email = 'forced@regression.test'; password = $TestPass
} | Out-Null
Assert 'the old password no longer signs in' (
    (Invoke-App 'GET' 'auth/login.php' $oldPw).Body -match 'do not match our records')

# ---------------------------------------------------------------- 3c. throttling

Section 'Sign-in throttling'
Invoke-Sql "DELETE FROM login_attempts;" | Out-Null

# A failed sign-in redirects back to the login page with the message in the
# flash, which renders exactly once - so each attempt needs a fresh session and
# a follow-up GET.
function Try-SignIn([string]$Email, [string]$Password) {
    $s = New-Object System.Net.CookieContainer
    Invoke-App 'POST' 'auth/login.php' $s @{
        _token = (Get-Token 'auth/login.php' $s); workspace = $TenantSlug; email = $Email; password = $Password
    } | Out-Null
    return (Invoke-App 'GET' 'auth/login.php' $s).Body
}

$lockedOutAt = 0
for ($i = 1; $i -le 8; $i++) {
    if ((Try-SignIn 'throttle@regression.test' "guess-$i") -match 'Too many failed sign-in attempts') {
        $lockedOutAt = $i; break
    }
}
Assert 'repeated failures are throttled' ($lockedOutAt -gt 0) "locked out at attempt $lockedOutAt"
Assert 'failures were recorded for auditing' (
    [int](Invoke-Sql "SELECT COUNT(*) FROM login_attempts WHERE email='throttle@regression.test' AND succeeded=0;") -ge 5)
Assert 'throttle still refuses the next attempt' (
    (Try-SignIn 'throttle@regression.test' 'another-guess') -match 'Too many failed sign-in attempts')

Invoke-Sql "DELETE FROM login_attempts;" | Out-Null
$good = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'auth/login.php' $good @{
    _token = (Get-Token 'auth/login.php' $good)
    workspace = $TenantSlug; email = $AdminEmail; password = $TestPass
} | Out-Null
Assert 'successful sign-in is logged' (
    [int](Invoke-Sql "SELECT COUNT(*) FROM login_attempts WHERE email='$AdminEmail' AND succeeded=1;") -ge 1)

# ---------------------------------------------------------------- 4. pages

Section 'Every page renders without PHP errors (admin + staff)'
$adminPages = @(
    'dashboard.php','clients/index.php','clients.php?page=2','clients.php?status=active','clients.php?status=inactive',
    'clients.php?assigned_to=2','clients.php?sort=company&dir=desc','clients.php?sort=created&dir=asc',
    'clients.php?search=north','clients.php?search=zzzznope','clients.php?sort=BOGUS&dir=sideways',
    'client_view.php?id=1','client_view.php?id=8','client_view.php?id=12','client_view.php?id=999',
    'clients/form.php','client_form.php?id=1','client_form.php?id=6','client_form.php?id=999',
    'leads/index.php','leads.php?page=2','leads.php?status=won','leads.php?source=event','leads.php?source=referral',
    'leads.php?assigned_to=3','leads.php?sort=value&dir=desc','leads.php?search=zzzznope',
    'lead_view.php?id=1','lead_view.php?id=9','lead_view.php?id=14','lead_view.php?id=999',
    'leads/form.php','lead_form.php?id=5','lead_form.php?id=7','lead_form.php?id=999',
    'pipeline/form.php','deal_form.php?id=1','deal_form.php?id=7','deal_form.php?id=11',
    'deal_form.php?lead_id=8','deal_form.php?client_id=3',
    'pipeline/index.php','pipeline.php?stage=won','pipeline.php?stage=negotiation','pipeline.php?assigned_to=2','pipeline.php?assigned_to=3',
    'tasks/index.php','tasks.php?page=2','tasks.php?status=pending','tasks.php?status=completed','tasks.php?status=in_progress',
    'tasks.php?priority=high','tasks.php?overdue=1','tasks.php?assigned_to=4','tasks.php?search=zzzznope',
    'tasks/form.php','task_form.php?id=1','task_form.php?id=8','task_form.php?id=999',
    'task_form.php?client_id=1','task_form.php?lead_id=5',
    'activities/index.php','activities.php?page=2','activities.php?type=call','activities.php?type=meeting',
    'activities.php?user_id=2','activities.php?date_from=2026-09-01&date_to=2026-09-30',
    'activities.php?client_id=1','activities.php?lead_id=1',
    'activities/form.php','activity_form.php?id=1','activity_form.php?id=30','activity_form.php?id=999',
    'reports/index.php','admin/users.php','users.php?role=staff','users.php?search=sarah',
    'admin/recycle_bin.php','admin/recycle_bin.php?type=client','admin/recycle_bin.php?type=lead',
    'admin/recycle_bin.php?type=deal','admin/recycle_bin.php?type=task','admin/recycle_bin.php?type=activity',
    'admin/recycle_bin.php?type=client&search=zzz',
    'admin/user_form.php','user_form.php?id=2','user_form.php?id=999','auth/profile.php'
)
foreach ($page in $adminPages) {
    Clear-Log
    $r = Invoke-App 'GET' $page $admin
    $errors = Get-LogErrors
    # A missing id or an authenticated hit on login.php correctly redirects.
    $expectRedirect = ($page -match 'id=999') -or ($page -eq 'auth/login.php')
    $ok = if ($expectRedirect) { $r.Code -eq 302 -and $r.Location -ne '' }
          else { $r.Code -eq 200 -and $r.Body.Length -gt 3000 }
    Assert "admin $page" ($ok -and $errors.Count -eq 0) "code=$($r.Code) len=$($r.Body.Length) errs=$($errors.Count) $($errors -join ' | ')"
}
foreach ($page in @('dashboard.php','clients/index.php','leads/index.php','pipeline/index.php','tasks/index.php',
                    'activities/index.php','reports/index.php','auth/profile.php',
                    "client_view.php?id=$StaffFixtureClient", "lead_view.php?id=$StaffFixtureLead")) {
    Clear-Log
    $r = Invoke-App 'GET' $page $staff
    Assert "staff $page" ($r.Code -eq 200 -and $r.Body.Length -gt 3000 -and (Get-LogErrors).Count -eq 0) "code=$($r.Code)"
}
# A record the staff user does not own must redirect, not render. Checked here
# too so the staff list cannot quietly stop covering the detail pages.
foreach ($page in @('client_view.php?id=1', 'lead_view.php?id=1')) {
    Clear-Log
    $r = Invoke-App 'GET' $page $staff
    Assert "staff redirected from $page" ($r.Code -eq 302) "code=$($r.Code)"
}

# ---------------------------------------------------------------- 5. CRUD

Section 'CRUD lifecycle'
$t = Get-Token 'clients/form.php' $admin
$r = Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; company_name = 'Regression Co'; contact_person = 'RC'; email = 'rc@x.test'
    phone = '+44 161 000 0001'; address = '1 Test Way'; status = 'prospect'; assigned_to = 2; notes = 'regression'
}
$cid = [int]([regex]::Match($r.Location, 'id=(\d+)').Groups[1].Value)
Assert 'create client' ($cid -gt 0) "location=$($r.Location)"
Assert 'flash message on create' ((Invoke-App 'GET' $r.Location $admin).Body -match 'was created')
Assert 'flash consumed once' (-not ((Invoke-App 'GET' $r.Location $admin).Body -match 'alert-success'))
Assert 'search finds it' ((Invoke-App 'GET' 'clients.php?search=Regression' $admin).Body -match 'Regression Co')
Assert 'status filter works' ((Invoke-App 'GET' 'clients.php?status=prospect' $admin).Body -match 'Regression Co')
Assert 'owner filter works' ((Invoke-App 'GET' 'clients.php?assigned_to=2' $admin).Body -match 'Regression Co')
Assert 'sort works' ((Invoke-App 'GET' 'clients.php?sort=company&dir=asc' $admin).Body -match 'Northwind')

$t = Get-Token "client_form.php?id=$cid" $admin
$r = Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; id = $cid; company_name = 'Regression Co 2'; contact_person = 'RC'
    email = 'rc@x.test'; status = 'active'; assigned_to = 3
}
Assert 'update client' ($r.Location -match "client_view\.php\?id=$cid$") "location=$($r.Location)"
Assert 'update persisted' ((Invoke-App 'GET' "client_view.php?id=$cid" $admin).Body -match 'Regression Co 2')

$t = Get-Token 'pipeline/form.php' $admin
$r = Invoke-App 'POST' 'pipeline/form.php' $admin @{
    _token = $t; deal_title = 'Regression Deal'; client_id = $cid; value = '5000'
    stage = 'proposal'; expected_close_date = '2026-12-01'; assigned_to = 2
}
Assert 'create deal' ($r.Location -match 'pipeline/index\.php$') "location=$($r.Location)"
Assert 'deal on pipeline' ((Invoke-App 'GET' 'pipeline/index.php' $admin).Body -match 'Regression Deal')
$did = [int](Invoke-Sql "SELECT id FROM deals WHERE deal_title='Regression Deal' LIMIT 1;")
$t = Get-Token 'pipeline/index.php' $admin
Invoke-App 'POST' 'pipeline/action.php' $admin @{ _token = $t; action = 'move'; id = $did; stage = 'won'; return = 'pipeline/index.php' } | Out-Null
Assert 'move deal to won' ((Invoke-Sql "SELECT stage FROM deals WHERE id=$did;") -eq 'won')
$t = Get-Token 'pipeline/index.php' $admin
Invoke-App 'POST' 'pipeline/action.php' $admin @{ _token = $t; action = 'move'; id = $did; stage = 'BOGUS'; return = 'pipeline/index.php' } | Out-Null
Assert 'invalid stage rejected' ((Invoke-Sql "SELECT stage FROM deals WHERE id=$did;") -eq 'won')

$t = Get-Token 'tasks/form.php' $admin
$r = Invoke-App 'POST' 'tasks/form.php' $admin @{
    _token = $t; title = 'Regression Task'; client_id = $cid; due_date = '2026-11-15'
    priority = 'high'; status = 'pending'; assigned_to = 2
}
Assert 'create task' ($r.Location -match 'tasks/index\.php$') "location=$($r.Location)"
$tid = [int](Invoke-Sql "SELECT id FROM tasks WHERE title='Regression Task' LIMIT 1;")
$t = Get-Token 'tasks/index.php' $admin
Invoke-App 'POST' 'tasks/action.php' $admin @{ _token = $t; action = 'complete'; id = $tid; return = 'tasks/index.php' } | Out-Null
Assert 'task completed + timestamp' (
    (Invoke-Sql "SELECT status FROM tasks WHERE id=$tid;") -eq 'completed' -and
    (Invoke-Sql "SELECT IFNULL(completed_at,'NULL') FROM tasks WHERE id=$tid;") -match '^20')
$t = Get-Token 'tasks/index.php' $admin
Invoke-App 'POST' 'tasks/action.php' $admin @{ _token = $t; action = 'reopen'; id = $tid; return = 'tasks/index.php' } | Out-Null
Assert 'task reopened, timestamp cleared' (
    (Invoke-Sql "SELECT status FROM tasks WHERE id=$tid;") -eq 'pending' -and
    (Invoke-Sql "SELECT IFNULL(completed_at,'NULL') FROM tasks WHERE id=$tid;") -eq 'NULL')

$t = Get-Token 'activities/form.php' $admin
$r = Invoke-App 'POST' 'activities/form.php' $admin @{
    _token = $t; client_id = $cid; type = 'call'; title = 'Regression Call'; details = 'Tested'
}
Assert 'create activity returns to client' ($r.Location -match "client_view\.php\?id=$cid$") "location=$($r.Location)"
$detail = Invoke-App 'GET' "client_view.php?id=$cid" $admin
Assert 'client detail aggregates deal+task+activity' (
    ($detail.Body -match 'Regression Deal') -and
    ($detail.Body -match 'Regression Task') -and
    ($detail.Body -match 'Regression Call'))

$t = Get-Token 'leads/form.php' $admin
$r = Invoke-App 'POST' 'leads/form.php' $admin @{
    _token = $t; lead_name = 'Regression Lead'; company = 'Regression Ltd'
    email = 'rl@x.test'; lead_source = 'event'; status = 'new'; estimated_value = '7000'; assigned_to = 4
}
$lid = [int]([regex]::Match($r.Location, 'id=(\d+)').Groups[1].Value)
Assert 'create lead' ($lid -gt 0) "location=$($r.Location)"
$t = Get-Token 'pipeline/form.php' $admin
Invoke-App 'POST' 'pipeline/form.php' $admin @{
    _token = $t; deal_title = 'Converted Deal'; client_id = $cid; lead_id = $lid
    value = '7000'; stage = 'negotiation'
} | Out-Null
Assert 'lead conversion advances to proposal' ((Invoke-Sql "SELECT status FROM leads WHERE id=$lid;") -eq 'proposal') (Invoke-Sql "SELECT status FROM leads WHERE id=$lid;")

# ---------------------------------------------------------------- 6. validation

Section 'Validation rejects bad input'
# Remember the high-water mark so anything this section manages to create
# (it should create nothing) can be swept up during cleanup.
$clientFloor = [int](Invoke-Sql 'SELECT COALESCE(MAX(id),0) FROM clients;')
$leadFloor   = [int](Invoke-Sql 'SELECT COALESCE(MAX(id),0) FROM leads;')
$badInputs = @(
    @{ n = 'client empty company';  p = 'clients/form.php';   f = @{ company_name = ''; contact_person = 'x'; status = 'active' } },
    @{ n = 'client bad email';     p = 'clients/form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; email = 'nope' } },
    @{ n = 'client bad status';    p = 'clients/form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'BOGUS' } },
    @{ n = 'client long contact';  p = 'clients/form.php';   f = @{ company_name = 'x'; contact_person = ('A' * 121); status = 'active' } },
    @{ n = 'client long phone';    p = 'clients/form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; phone = ('1' * 41) } },
    @{ n = 'client long address';  p = 'clients/form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; address = ('a' * 256) } },
    @{ n = 'lead empty name';      p = 'leads/form.php';     f = @{ lead_name = ''; lead_source = 'website'; status = 'new' } },
    @{ n = 'lead bad source';      p = 'leads/form.php';     f = @{ lead_name = 'x'; lead_source = 'BOGUS'; status = 'new' } },
    @{ n = 'lead negative value';  p = 'leads/form.php';     f = @{ lead_name = 'x'; lead_source = 'website'; status = 'new'; estimated_value = '-5' } },
    @{ n = 'lead long company';    p = 'leads/form.php';     f = @{ lead_name = 'x'; lead_source = 'website'; status = 'new'; company = ('c' * 151) } },
    @{ n = 'task bad priority';    p = 'tasks/form.php';     f = @{ title = 'x'; priority = 'BOGUS'; status = 'pending' } },
    @{ n = 'task bad date';        p = 'tasks/form.php';     f = @{ title = 'x'; priority = 'low'; status = 'pending'; due_date = '2026-99-99' } },
    @{ n = 'task long title';      p = 'tasks/form.php';     f = @{ title = ('t' * 181); priority = 'low'; status = 'pending' } },
    @{ n = 'deal no link';         p = 'pipeline/form.php';     f = @{ deal_title = 'x'; client_id = 0; lead_id = 0; stage = 'won'; value = '1' } },
    @{ n = 'deal bad stage';       p = 'pipeline/form.php';     f = @{ deal_title = 'x'; client_id = 1; stage = 'BOGUS'; value = '1' } },
    @{ n = 'deal bad close date';  p = 'pipeline/form.php';     f = @{ deal_title = 'x'; client_id = 1; stage = 'won'; value = '1'; expected_close_date = '2026-13-45' } },
    @{ n = 'activity orphan';      p = 'activities/form.php'; f = @{ client_id = 0; lead_id = 0; type = 'call'; title = 'x' } },
    @{ n = 'activity bad type';    p = 'activities/form.php'; f = @{ client_id = 1; type = 'BOGUS'; title = 'x' } },
    @{ n = 'activity long title';  p = 'activities/form.php'; f = @{ client_id = 1; type = 'note'; title = ('a' * 181) } }
)
foreach ($case in $badInputs) {
    Clear-Log
    $fields = $case.f.Clone()
    $fields['_token'] = Get-Token $case.p $admin
    $r = Invoke-App 'POST' $case.p $admin $fields
    $errors = Get-LogErrors
    Assert "$($case.n) rejected, no PHP error" ($r.Location -match '\.php(\?[^#]*)?$' -and $errors.Count -eq 0) "location=$($r.Location) errs=$($errors.Count) $($errors -join ' | ')"
}
Assert 'validation section created no clients' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id > $clientFloor;") -eq '0') "clients above $clientFloor"
Assert 'validation section created no leads' ((Invoke-Sql "SELECT COUNT(*) FROM leads WHERE id > $leadFloor;") -eq '0') "leads above $leadFloor"

# ---------------------------------------------------------------- 7. security

Section 'Security'
# .NET's HttpWebRequest discards the response object for a 403, so a blocked
# request cannot be asserted by status code here. Assert the side effect
# instead: the record must still exist.
$beforeCsrf = Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cid;"
Assert 'POST without CSRF token changes nothing' (
    (Invoke-App 'POST' 'clients/action.php' $admin @{ action = 'delete'; id = $cid; return = 'clients/index.php' }) -and
    (Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cid;") -eq $beforeCsrf)
Assert 'POST with bad CSRF token changes nothing' (
    (Invoke-App 'POST' 'clients/action.php' $admin @{ _token = 'deadbeef'; action = 'delete'; id = $cid; return = 'clients/index.php' }) -and
    (Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cid;") -eq $beforeCsrf)

# The return parameter must be ignored as a redirect target. Use a throwaway
# record so this does not disturb the CRUD fixture above.
$t = Get-Token 'clients/form.php' $admin
$throwaway = [int]([regex]::Match(
    (Invoke-App 'POST' 'clients/form.php' $admin @{
        _token = $t; company_name = 'Redirect Probe'; contact_person = 'RP'; status = 'prospect'
    }).Location, 'id=(\d+)').Groups[1].Value)
$t = Get-Token 'clients/index.php' $admin
$redirect = Invoke-App 'POST' 'clients/action.php' $admin @{
    _token = $t; action = 'delete'; id = $throwaway; return = 'http://evil.test/'
}
Assert 'external return path not honoured' ($redirect.Location -notmatch 'evil\.test') "location=$($redirect.Location)"
Invoke-Sql "DELETE FROM clients WHERE id=$throwaway;" | Out-Null

$r = Invoke-App 'GET' ('clients.php?sort=' + [uri]::EscapeDataString('DROP TABLE users') + '&dir=asc') $admin
Assert 'ORDER BY injection ignored' (($r.Body -match 'Northwind') -and ((Invoke-Sql 'SELECT COUNT(*) FROM users;') -ge '4'))
$r = Invoke-App 'GET' ('clients.php?search=' + [uri]::EscapeDataString("' OR 1=1--")) $admin
Assert 'SQL injection in search safe' (-not ($r.Body -match 'SQLSTATE|Fatal error'))
$r = Invoke-App 'GET' ('clients.php?search=' + [uri]::EscapeDataString('<script>alert(1)</script>')) $admin
Assert 'reflected XSS escaped' (-not ($r.Body.Contains('<script>alert(1)</script>')))

$t = Get-Token 'clients/form.php' $admin
$r = Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; company_name = '<script>alert(1)</script>XssRegression'
    contact_person = '"><img src=x onerror=alert(2)>'; status = 'active'
}
$xid = [int]([regex]::Match($r.Location, 'id=(\d+)').Groups[1].Value)
$xbody = (Invoke-App 'GET' "client_view.php?id=$xid" $admin).Body
Assert 'stored XSS escaped (script)' (-not ($xbody.Contains('<script>alert(1)</script>')))
Assert 'stored XSS escaped (attribute)' (-not ($xbody -match '<img[^>]*onerror'))
Invoke-Sql "DELETE FROM clients WHERE id=$xid;" | Out-Null

# Last-admin protection.
#
# The suite now runs as its own admin, so there are two admins and demoting the
# seeded one would legitimately succeed. Demote the suite's OWN admin first -
# which touches only a row it created - and then attempt to demote the seeded
# admin, who is by then genuinely the last one. The session still carries
# role=admin, so the request that follows is still made by an administrator.
Invoke-Sql "UPDATE users SET role = 'staff' WHERE id = $AdminId;" | Out-Null

$t = Get-Token 'admin/user_form.php' $admin
Invoke-App 'POST' 'admin/user_form.php' $admin @{
    _token = $t; id = 1; name = 'Alex Morgan'; email = 'admin@clientflow.test'
    role = 'staff'; password = ''; is_active = '1'
} | Out-Null
Assert 'cannot demote the last admin' ((Invoke-Sql 'SELECT role FROM users WHERE id=1;') -eq 'admin')
Assert 'last-admin error surfaced' ((Invoke-App 'GET' 'user_form.php?id=1' $admin).Body -match 'only active admin')

# Put the suite's admin back, so later sections still have one.
Invoke-Sql "UPDATE users SET role = 'admin' WHERE id = $AdminId;" | Out-Null
$t = Get-Token 'admin/users.php' $admin
Invoke-App 'POST' 'admin/user_action.php' $admin @{
    _token = $t; action = 'create'; name = 'Regression Temp Admin'
    email = 'regression-temp-admin@clientflow.test'; password = $TestPass; role = 'admin'
} | Out-Null
$tempAdminId = [int](Invoke-Sql "SELECT id FROM users WHERE email = 'regression-temp-admin@clientflow.test';")
Assert 'admin can create another admin' ($tempAdminId -gt 0)

# Two admins now, so deleting the seeded one would be allowed - which is exactly
# why the self-delete guard is what needs testing, and it must target the account
# that is actually signed in.
$t = Get-Token 'admin/users.php' $admin
Invoke-App 'POST' 'admin/user_action.php' $admin @{ _token = $t; action = 'delete'; id = $AdminId } | Out-Null
Assert 'admin cannot delete own account' ((Invoke-Sql "SELECT COUNT(*) FROM users WHERE id=$AdminId;") -eq '1')
Assert 'self-delete error surfaced' ((Invoke-App 'GET' 'admin/users.php' $admin).Body -match 'cannot delete your own account')

# And the seeded admin is still there, untouched.
Assert 'seeded admin survived the run' ((Invoke-Sql 'SELECT COUNT(*) FROM users WHERE id=1;') -eq '1')
Invoke-Sql "DELETE FROM users WHERE id = $tempAdminId;" | Out-Null

# ------------------------------------------------------------ read security

Section 'Read-level access'

# The suite's staff account owns the two fixtures created in setup, which is
# what makes the positive cases here meaningful: can_view() allows a record you
# created or are assigned to, and this account owns nothing in the seed data.
Assert 'staff can view a client assigned to them' ((Invoke-App 'GET' "client_view.php?id=$StaffFixtureClient" $staff).Code -eq 200)
Assert 'staff can view a lead assigned to them' ((Invoke-App 'GET' "lead_view.php?id=$StaffFixtureLead" $staff).Code -eq 200)

# can_manage() only ever gated writes, so these used to render in full.
$r = Invoke-App 'GET' 'client_view.php?id=1' $staff
Assert 'staff blocked from another user\'s client detail' ($r.Location -match 'clients/index\.php$') "location=$($r.Location)"
Assert 'blocked client detail leaks no contact details' (-not ($r.Body -match 'Northwind|daniel@'))

$r = Invoke-App 'GET' 'lead_view.php?id=1' $staff
Assert 'staff blocked from another user\'s lead detail' ($r.Location -match 'leads/index\.php$') "location=$($r.Location)"
Assert 'blocked lead detail leaks no contact details' (-not ($r.Body -match 'Whitfield|whitfield@'))

# Admins are unaffected.
Assert 'admin can view any client' ((Invoke-App 'GET' 'client_view.php?id=1' $admin).Code -eq 200)
Assert 'admin can view any lead' ((Invoke-App 'GET' 'lead_view.php?id=1' $admin).Code -eq 200)

# A record the staff user created is theirs even if assigned elsewhere.
$t = Get-Token 'clients/form.php' $staff
Invoke-App 'POST' 'clients/form.php' $staff @{
    _token = $t; company_name = 'Regression StaffOwned'; contact_person = 'Mine'; status = 'prospect'
} | Out-Null
$mine = [int](Invoke-Sql "SELECT id FROM clients WHERE company_name='Regression StaffOwned' LIMIT 1;")
Assert 'a staff user can view a record they created' ($mine -gt 0 -and (Invoke-App 'GET' "client_view.php?id=$mine" $staff).Code -eq 200)

# Lists and reports stay org-wide on purpose - scoping them would break team
# performance reporting. Asserted against the seed data, which nobody owns.
Assert 'client list stays org-wide for staff' ((Invoke-App 'GET' 'clients.php?search=Bluepeak' $staff).Body -match 'Bluepeak')
Assert 'reports stay org-wide for staff' ((Invoke-App 'GET' 'reports/index.php' $staff).Body -match 'Team performance')

# The UI must not offer an action the server will refuse. The seed client is
# owned by someone else, so no Edit or Delete button should appear for it - while
# the staff user's own client must still show both.
$notMine = (Invoke-App 'GET' 'clients.php?search=Bluepeak' $staff).Body
Assert 'staff are not offered Edit on a record they cannot manage' ($notMine -notmatch 'clients/form\.php\?id=2')
Assert 'staff are not offered Delete on a record they cannot manage' ($notMine -notmatch 'name="action"\s+value="delete"')

$isMine = (Invoke-App 'GET' "clients.php?search=OwnedByStaff" $staff).Body
Assert 'staff ARE offered Edit on their own record' ($isMine -match ('clients/form\.php\?id=' + $StaffFixtureClient))
Assert 'staff ARE offered Delete on their own record' ($isMine -match 'name="action"\s+value="delete"')

Invoke-Sql "DELETE FROM clients WHERE company_name='Regression StaffOwned';" | Out-Null

# ------------------------------------------------------------ soft delete

Section 'Soft delete and the recycle bin'

# A client with one of everything hanging off it, so the cascade behaviour
# that used to destroy the lot can be checked directly.
$t = Get-Token 'clients/form.php' $admin
$cId = [int]([regex]::Match((Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; company_name = 'Regression SoftDelete'; contact_person = 'Soft Person'; status = 'active'
}).Location, 'id=(\d+)').Groups[1].Value)

$t = Get-Token "client_form.php?id=$cId" $admin
Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; id = $cId; company_name = 'Regression SoftDelete'
    contact_person = 'Soft Person'; status = 'active'
} | Out-Null

$t = Get-Token 'pipeline/form.php' $admin
Invoke-App 'POST' 'pipeline/form.php' $admin @{
    _token = $t; deal_title = 'Regression SD Deal'; client_id = $cId
    value = '5000'; stage = 'new_lead'
} | Out-Null
$t = Get-Token 'task_form.php' $admin
Invoke-App 'POST' 'tasks/form.php' $admin @{
    _token = $t; title = 'Regression SD Task'; client_id = $cId; status = 'pending'; priority = 'medium'
} | Out-Null
$t = Get-Token 'activity_form.php' $admin
Invoke-App 'POST' 'activities/form.php' $admin @{
    _token = $t; client_id = $cId; type = 'note'; title = 'Regression SD Activity'
} | Out-Null

$childCount = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM deals WHERE client_id=$cId), '/',
    (SELECT COUNT(*) FROM tasks WHERE client_id=$cId), '/',
    (SELECT COUNT(*) FROM activities WHERE client_id=$cId));"
Assert 'fixture has one deal, task and activity' ($childCount -eq '1/1/1') "got $childCount"

# --- delete ---
$t = Get-Token 'clients/index.php' $admin
Invoke-App 'POST' 'clients/action.php' $admin @{
    _token = $t; action = 'delete'; id = $cId; return = 'clients/index.php'
} | Out-Null

Assert 'client row survives the delete' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cId;") -eq '1')
Assert 'client is stamped, not removed' ((Invoke-Sql "SELECT deleted_at IS NOT NULL FROM clients WHERE id=$cId;") -eq '1')
Assert 'deleter is recorded' ((Invoke-Sql "SELECT deleted_by FROM clients WHERE id=$cId;") -eq $AdminId)

# The whole point: the children are untouched.
$afterDelete = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM deals WHERE client_id=$cId), '/',
    (SELECT COUNT(*) FROM tasks WHERE client_id=$cId), '/',
    (SELECT COUNT(*) FROM activities WHERE client_id=$cId));"
Assert 'deals, tasks and activities are NOT destroyed' ($afterDelete -eq '1/1/1') "got $afterDelete"

# --- hidden everywhere ---
# Every listing is asserted through ?search=, never the bare page. The list
# screens default to oldest-first, so a record created seconds ago lands on the
# LAST page - asserting on page 1 would pass vacuously.
Invoke-App 'GET' 'clients/index.php' $admin | Out-Null   # drain the success flash

Assert 'deleted client is gone from the list' (-not ((Invoke-App 'GET' 'clients.php?search=SoftDelete' $admin).Body -match 'Regression SoftDelete'))
Assert 'deleted client 404s by direct URL' ((Invoke-App 'GET' "client_view.php?id=$cId" $admin).Location -notmatch 'client_view')
Assert 'its deal is hidden from the pipeline' (-not ((Invoke-App 'GET' 'pipeline.php?search=SD+Deal' $admin).Body -match 'Regression SD Deal'))
Assert 'its task is hidden from the task board' (-not ((Invoke-App 'GET' 'tasks.php?search=SD+Task' $admin).Body -match 'Regression SD Task'))
Assert 'its activity is hidden from the feed' (-not ((Invoke-App 'GET' 'activities.php?search=SD+Activity' $admin).Body -match 'Regression SD Activity'))
Assert 'reports ignore it' (-not ((Invoke-App 'GET' 'reports/index.php' $admin).Body -match 'Regression SD'))

# --- recycle bin ---
$bin = (Invoke-App 'GET' 'admin/recycle_bin.php?type=client&search=SoftDelete' $admin).Body
Assert 'recycle bin lists the deleted client' ($bin -match 'Regression SoftDelete')
Assert 'recycle bin shows who deleted it' ($bin -match 'Regression Admin')
Assert 'recycle bin counts the linked records' ($bin -match '3 attached records')

Assert 'staff cannot open the recycle bin' ((Invoke-App 'GET' 'admin/recycle_bin.php' $staff).Location -match 'dashboard\.php$')
$t = Get-Token 'admin/users.php' $staff
Invoke-App 'POST' 'admin/recycle_action.php' $staff @{
    _token = $t; action = 'restore'; type = 'client'; id = $cId
} | Out-Null
Assert 'staff cannot restore via a direct POST' ((Invoke-Sql "SELECT deleted_at IS NOT NULL FROM clients WHERE id=$cId;") -eq '1')

# --- restore ---
$t = Get-Token 'admin/recycle_bin.php' $admin
Invoke-App 'POST' 'admin/recycle_action.php' $admin @{
    _token = $t; action = 'restore'; type = 'client'; id = $cId
} | Out-Null
Assert 'restore clears the stamp' ((Invoke-Sql "SELECT deleted_at IS NULL FROM clients WHERE id=$cId;") -eq '1')

# Drain the restore flash before looking for the records in listings.
Invoke-App 'GET' 'admin/recycle_bin.php' $admin | Out-Null

Assert 'restored client is back in the list' ((Invoke-App 'GET' 'clients.php?search=SoftDelete' $admin).Body -match 'Regression SoftDelete')
Assert 'restored client opens again' ((Invoke-App 'GET' "client_view.php?id=$cId" $admin).Code -eq 200)
Assert 'its deal came back too' ((Invoke-App 'GET' 'pipeline.php?search=SD+Deal' $admin).Body -match 'Regression SD Deal')
Assert 'its task came back too' ((Invoke-App 'GET' 'tasks.php?search=SD+Task' $admin).Body -match 'Regression SD Task')
Assert 'its activity came back too' ((Invoke-App 'GET' 'activities.php?search=SD+Activity' $admin).Body -match 'Regression SD Activity')
Assert 'the bin no longer lists it' (-not ((Invoke-App 'GET' 'admin/recycle_bin.php?type=client&search=SoftDelete' $admin).Body -match 'Regression SoftDelete'))

# --- delete forever requires typing the name ---
$t = Get-Token 'clients/index.php' $admin
Invoke-App 'POST' 'clients/action.php' $admin @{
    _token = $t; action = 'delete'; id = $cId; return = 'clients/index.php'
} | Out-Null

$t = Get-Token 'admin/recycle_bin.php' $admin
Invoke-App 'POST' 'admin/recycle_action.php' $admin @{
    _token = $t; action = 'purge'; type = 'client'; id = $cId; confirm = 'wrong name'
} | Out-Null
Assert 'purge refused when the name does not match' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cId;") -eq '1')

$t = Get-Token 'admin/recycle_bin.php' $admin
Invoke-App 'POST' 'admin/recycle_action.php' $admin @{
    _token = $t; action = 'purge'; type = 'client'; id = $cId; confirm = 'Regression SoftDelete'
} | Out-Null
Assert 'purge removes the row when confirmed' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cId;") -eq '0')
Assert 'purge cascades to the children, as documented' (
    (Invoke-Sql "SELECT COUNT(*) FROM deals WHERE client_id=$cId;") -eq '0' -and
    (Invoke-Sql "SELECT COUNT(*) FROM tasks WHERE client_id=$cId;") -eq '0' -and
    (Invoke-Sql "SELECT COUNT(*) FROM activities WHERE client_id=$cId;") -eq '0')

# --- purge cannot be aimed at a live row ---
$t = Get-Token 'clients/form.php' $admin
$liveId = [int]([regex]::Match((Invoke-App 'POST' 'clients/form.php' $admin @{
    _token = $t; company_name = 'Regression LiveGuard'; contact_person = 'Live'; status = 'prospect'
}).Location, 'id=(\d+)').Groups[1].Value)
$t = Get-Token 'admin/recycle_bin.php' $admin
Invoke-App 'POST' 'admin/recycle_action.php' $admin @{
    _token = $t; action = 'purge'; type = 'client'; id = $liveId; confirm = 'Regression LiveGuard'
} | Out-Null
Assert 'purge ignores a record that is not deleted' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$liveId;") -eq '1')

# --- CSRF on the recycle bin ---
$t = Get-Token 'admin/recycle_bin.php' $admin
Invoke-App 'POST' 'admin/recycle_action.php' $admin @{
    _token = 'deadbeef'; action = 'restore'; type = 'client'; id = $liveId
} | Out-Null
Assert 'recycle bin rejects a bad CSRF token' ((Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$liveId;") -eq '1')

Invoke-Sql "DELETE FROM clients WHERE id IN ($cId, $liveId);" | Out-Null

$t = Get-Token 'auth/profile.php' $admin
Invoke-App 'POST' 'auth/profile.php' $admin @{
    _token = $t; action = 'password'; current_password = 'wrongpass'
    new_password = 'abcdefgh123'; confirm_password = 'abcdefgh123'
} | Out-Null
Assert 'wrong current password rejected' ((Invoke-App 'GET' 'auth/profile.php' $admin).Body -match 'current password is not correct')
$t = Get-Token 'auth/profile.php' $admin
# Editing the profile to an email another account already uses must be refused.
# The suite signs in as its own account, so the assertion is about that account's
# row rather than a seeded one - and posting its own email back must work, which
# also covers the happy path.
$t = Get-Token 'auth/profile.php' $admin
Invoke-App 'POST' 'auth/profile.php' $admin @{
    _token = $t; action = 'details'; name = 'Regression Admin'; email = $AdminEmail
} | Out-Null
Assert 'profile can save its own details' (
    (Invoke-Sql "SELECT CONCAT(name,'|',email) FROM users WHERE id=$AdminId;") -eq "Regression Admin|$AdminEmail")

$t = Get-Token 'auth/profile.php' $admin
Invoke-App 'POST' 'auth/profile.php' $admin @{
    _token = $t; action = 'details'; name = 'Regression Admin'; email = 'sarah@clientflow.test'
} | Out-Null
Assert 'profile duplicate email rejected' ((Invoke-Sql "SELECT email FROM users WHERE id=$AdminId;") -eq $AdminEmail)
Assert 'duplicate-email error surfaced' ((Invoke-App 'GET' 'auth/profile.php' $admin).Body -match 'already uses that email')

# ---------------------------------------------------------------- 8. reports

Section 'Reports content'
$reports = Invoke-App 'GET' 'reports/index.php' $admin
foreach ($block in @('Monthly activity','Deal values by stage','Team performance','Won vs lost',
                     'Lead sources','Activity mix','Clients by owner','Recent wins')) {
    Assert "reports: $block" ($reports.Body -match [regex]::Escape($block))
}

# ---------------------------------------------------------------- 9. accessibility

Section 'Accessibility spot checks'
foreach ($page in @('clients/index.php','leads/index.php','tasks/index.php','activities/index.php','admin/users.php')) {
    $html = (Invoke-App 'GET' $page $admin).Body
    # <th\b so the opening <thead> tag is not counted as a header cell.
    $th = ([regex]::Matches($html, '<th\b')).Count
    $thScoped = ([regex]::Matches($html, '<th\b[^>]*scope=')).Count
    Assert "${page}: all <th> carry scope" ($th -gt 0 -and $th -eq $thScoped) "$thScoped of $th"
    # Every icon-only control needs an accessible name.
    $labelled = ([regex]::Matches($html, 'aria-label=')).Count
    Assert "${page}: icon controls labelled" ($labelled -gt 0) "aria-labels=$labelled"
}

# ---------------------------------------------------------------- 10. flash

Section 'Flash messages render once'
foreach ($case in @(
    @{ n = 'create client'; p = 'clients/form.php';   f = @{ company_name = 'Flash Co'; contact_person = 'F'; status = 'prospect' }; loc = 'clients/index.php'; expect = 'was created' },
    @{ n = 'delete client'; p = 'clients/action.php'; f = @{ action = 'delete'; return = 'clients/index.php' }; loc = 'clients/index.php'; expect = 'moved to the recycle bin' },
    @{ n = 'create lead';   p = 'leads/form.php';     f = @{ lead_name = 'FlashLead'; lead_source = 'website'; status = 'new'; estimated_value = '10' }; loc = 'leads/index.php'; expect = 'was created' },
    @{ n = 'create task';   p = 'tasks/form.php';     f = @{ title = 'FlashTask'; priority = 'low'; status = 'pending'; due_date = '2026-12-01' }; loc = 'tasks/index.php'; expect = 'was created' },
    @{ n = 'create deal';   p = 'pipeline/form.php';     f = @{ deal_title = 'FlashDeal'; client_id = 1; stage = 'contacted'; value = '42' }; loc = 'pipeline/index.php'; expect = 'added to the pipeline' }
)) {
    # The token must come from a GET page. *_action.php endpoints are POST-only
    # and return an empty 302, so they carry no usable token.
    $fields = $case.f.Clone()
    $fields['_token'] = Get-Token $case.loc $admin
    if ($case.p -like '*action.php') {
        $table = 'clients'; $col = 'company_name'
        if ($case.p -like 'leads*')    { $table = 'leads'; $col = 'lead_name' }
        if ($case.p -like 'tasks*')    { $table = 'tasks'; $col = 'title' }
        $target = Invoke-Sql "SELECT id FROM $table WHERE $col LIKE 'Flash%' LIMIT 1;"
        Assert "$($case.n): fixture row exists" ($target -match '^\d+$') "id='$target'"
        $fields['id'] = $target
    }
    Invoke-App 'POST' $case.p $admin $fields | Out-Null
    $first = Invoke-App 'GET' $case.loc $admin
    Assert "flash shows: $($case.n)" (($first.Body -match 'alert-success') -and ($first.Body -match [regex]::Escape($case.expect)))
    $second = Invoke-App 'GET' $case.loc $admin
    Assert "flash consumed: $($case.n)" (-not ($second.Body -match 'alert-success'))
}

# ---------------------------------------------------------------- 11. user modal

Section 'Create-user modal reopens on validation failure'
$t = Get-Token 'admin/users.php' $admin
Invoke-App 'POST' 'admin/user_action.php' $admin @{
    _token = $t; action = 'create'; name = 'Modal Test'; email = 'sarah@clientflow.test'
    password = 'password123'; role = 'staff'
} | Out-Null
$usersPage = (Invoke-App 'GET' 'admin/users.php' $admin).Body
Assert 'duplicate-email error reaches the page' ($usersPage -match 'already uses that email')
Assert 'page instructs the modal to reopen' ($usersPage -match 'data-reopen-modal|bootstrap\.Modal')

# ---------------------------------------------------------------- cleanup

Section 'Cleanup and integrity'
# Sweep anything the suite created. Deleting a client cascades to its deals,
# tasks and activities, so one statement clears all of it.
Invoke-Sql "DELETE FROM clients WHERE company_name LIKE 'Regression%'
                        OR company_name LIKE 'Redirect%'
                        OR company_name LIKE 'Xss%'
                        OR company_name LIKE 'Flash%';
            DELETE FROM leads   WHERE lead_name LIKE 'Regression%'
                        OR lead_name LIKE 'Flash%';
            DELETE FROM tasks   WHERE title LIKE 'Regression%'
                        OR title LIKE 'Flash%';
            DELETE FROM deals   WHERE deal_title LIKE 'Regression%'
                        OR deal_title LIKE 'Flash%';
            DELETE FROM activities WHERE title LIKE 'Regression%'
                        OR title LIKE 'Flash%';
            DELETE FROM users   WHERE email LIKE '%Regression%'
                        OR email IN ('forced@regression.test', 'throttle@regression.test',
                                     'regression-admin@clientflow.test',
                                     'regression-staff@clientflow.test',
                                     'regression-temp-admin@clientflow.test');
            DELETE FROM login_attempts;" | Out-Null

$after = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM users),    '/',
    (SELECT COUNT(*) FROM clients),  '/',
    (SELECT COUNT(*) FROM leads),    '/',
    (SELECT COUNT(*) FROM deals),    '/',
    (SELECT COUNT(*) FROM tasks),    '/',
    (SELECT COUNT(*) FROM activities))"
Assert 'row counts restored to baseline' ($after -eq $baseline) "before=$baseline after=$after"

# Restore the seeded accounts from the snapshot taken at startup, then prove the
# restore worked. Asserting alone is not enough: a test that tries to demote the
# demo admin and unexpectedly succeeds would otherwise leave it as a staff user
# for every future run - which is exactly what happened before this snapshot
# existed.
foreach ($row in ($seededUsersBefore -split '\|')) {
    $p = $row -split ':'
    Invoke-Sql ("UPDATE users SET role = '{0}', is_active = {1}, must_change_password = {2} WHERE id = {3};" -f $p[1], $p[2], $p[3], $p[0]) | Out-Null
}
$seededUsersAfter = Invoke-Sql "SELECT GROUP_CONCAT(CONCAT(id,':',role,':',is_active,':',must_change_password) ORDER BY id SEPARATOR '|')
                                 FROM users WHERE id IN (1,2,3,4);"
Assert 'seeded accounts restored' ($seededUsersAfter -eq $seededUsersBefore) "before=$seededUsersBefore after=$seededUsersAfter"
Assert 'no test accounts left behind' (
    [int](Invoke-Sql "SELECT COUNT(*) FROM users WHERE email LIKE '%regression%' OR email LIKE '%@regression.test';") -eq 0)

$orphans = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM deals d WHERE d.client_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM clients c WHERE c.id=d.client_id)), '/',
    (SELECT COUNT(*) FROM tasks t WHERE t.client_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM clients c WHERE c.id=t.client_id)), '/',
    (SELECT COUNT(*) FROM activities a WHERE a.client_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM clients c WHERE c.id=a.client_id)))"
Assert 'no orphaned rows' ($orphans -eq '0/0/0') "orphans=$orphans"

# ---------------------------------------------------------------- summary

Clear-Log
$finalErrors = Get-LogErrors
Write-Output ""
Write-Output "PHP errors/warnings logged: $($finalErrors.Count)"
if ($finalErrors.Count -gt 0) { $finalErrors | Select-Object -Unique | Select-Object -First 10 | ForEach-Object { "  $_" } }

Write-Output ""
Write-Output "================================"
Write-Output "PASSED: $script:Passed   FAILED: $script:Failed"
if ($script:Failures.Count -gt 0) {
    Write-Output ""
    Write-Output "Failures:"
    $script:Failures | ForEach-Object { "  - $_" }
}
Write-Output "================================"

exit ($(if ($script:Failed -gt 0) { 1 } else { 0 }))