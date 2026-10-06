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
    [string]$DbName  = 'clientflow_crm'
)

$ErrorActionPreference = 'Continue'
$script:Passed = 0
$script:Failed = 0
$script:Failures = @()

# ---------------------------------------------------------------- helpers

function Invoke-App([string]$Method, [string]$Path, $Session, [hashtable]$Fields = $null) {
    $req = [System.Net.HttpWebRequest]::Create("$BaseUrl/$Path")
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
    $probe = Invoke-App 'GET' 'login.php' $null
} catch {
    Write-Output ""
    Write-Output "ABORT: cannot reach $BaseUrl - is Apache running?"
    exit 1
}
if ($probe.Code -ne 200) {
    Write-Output "ABORT: login.php returned $($probe.Code)"
    exit 1
}

$admin = New-Object System.Net.CookieContainer
$adminLogin = Invoke-App 'POST' 'login.php' $admin @{
    _token = (Get-Token 'login.php' $admin)
    email = 'admin@clientflow.test'; password = 'admin123'
}
if ($adminLogin.Location -ne 'index.php') {
    Write-Output "ABORT: admin login failed (got '$($adminLogin.Location)')"
    exit 1
}

$staff = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'login.php' $staff @{
    _token = (Get-Token 'login.php' $staff)
    email = 'sarah@clientflow.test'; password = 'staff123'
} | Out-Null

$baseline = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM users),    '/',
    (SELECT COUNT(*) FROM clients),  '/',
    (SELECT COUNT(*) FROM leads),    '/',
    (SELECT COUNT(*) FROM deals),    '/',
    (SELECT COUNT(*) FROM tasks),    '/',
    (SELECT COUNT(*) FROM activities))"

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

# ---------------------------------------------------------------- 2. exposure

Section 'Sensitive files are not web-accessible'
foreach ($secret in @('database.sql', 'README.md', 'config/config.php',
                      'models/DealModel.php', 'includes/header.php', '.vscode/settings.json')) {
    $status = Get-Status $secret
    Assert "blocked: /$secret" ($status -eq 403 -or $status -eq 404) "status=$status"
}
foreach ($dir in @('config/', 'models/', 'includes/', '.vscode/')) {
    $status = Get-Status $dir
    Assert "no listing: /$dir" ($status -eq 403 -or $status -eq 404) "status=$status"
}
# The app itself must still be reachable.
foreach ($open in @('index.php', 'login.php', 'assets/css/style.css', 'assets/vendor/css/bootstrap.min.css')) {
    $status = Get-Status $open
    Assert "still served: /$open" ($status -eq 200) "status=$status"
}

# ---------------------------------------------------------------- 3. auth

Section 'Authentication'
Assert 'admin reaches dashboard' ((Invoke-App 'GET' 'index.php' $admin).Code -eq 200)
Assert 'staff blocked from /users' ((Invoke-App 'GET' 'users.php' $staff).Location -eq 'index.php')
Assert 'staff nav hides Users' (-not ((Invoke-App 'GET' 'index.php' $staff).Body -match 'users\.php'))
foreach ($page in @('index.php', 'clients.php', 'leads.php', 'pipeline.php', 'tasks.php',
                    'activities.php', 'reports.php', 'users.php', 'profile.php')) {
    $guest = New-Object System.Net.CookieContainer
    $r = Invoke-App 'GET' $page $guest
    Assert "guest blocked: $page" ($r.Code -eq 302 -and $r.Location -match 'login\.php') "code=$($r.Code)"
}
$bad = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'login.php' $bad @{
    _token = (Get-Token 'login.php' $bad); email = 'admin@clientflow.test'; password = 'nope'
} | Out-Null
Assert 'wrong password generic error' ((Invoke-App 'GET' 'login.php' $bad).Body -match 'do not match our records')
$unknown = New-Object System.Net.CookieContainer
Invoke-App 'POST' 'login.php' $unknown @{
    _token = (Get-Token 'login.php' $unknown); email = 'nobody@nowhere.test'; password = 'x'
} | Out-Null
Assert 'unknown email same error (no enumeration)' ((Invoke-App 'GET' 'login.php' $unknown).Body -match 'do not match our records')

# ---------------------------------------------------------------- 4. pages

Section 'Every page renders without PHP errors (admin + staff)'
$adminPages = @(
    'index.php','clients.php','clients.php?page=2','clients.php?status=active','clients.php?status=inactive',
    'clients.php?assigned_to=2','clients.php?sort=company&dir=desc','clients.php?sort=created&dir=asc',
    'clients.php?search=north','clients.php?search=zzzznope','clients.php?sort=BOGUS&dir=sideways',
    'client_view.php?id=1','client_view.php?id=8','client_view.php?id=12','client_view.php?id=999',
    'client_form.php','client_form.php?id=1','client_form.php?id=6','client_form.php?id=999',
    'leads.php','leads.php?page=2','leads.php?status=won','leads.php?source=event','leads.php?source=referral',
    'leads.php?assigned_to=3','leads.php?sort=value&dir=desc','leads.php?search=zzzznope',
    'lead_view.php?id=1','lead_view.php?id=9','lead_view.php?id=14','lead_view.php?id=999',
    'lead_form.php','lead_form.php?id=5','lead_form.php?id=7','lead_form.php?id=999',
    'deal_form.php','deal_form.php?id=1','deal_form.php?id=7','deal_form.php?id=11',
    'deal_form.php?lead_id=8','deal_form.php?client_id=3',
    'pipeline.php','pipeline.php?stage=won','pipeline.php?stage=negotiation','pipeline.php?assigned_to=2','pipeline.php?assigned_to=3',
    'tasks.php','tasks.php?page=2','tasks.php?status=pending','tasks.php?status=completed','tasks.php?status=in_progress',
    'tasks.php?priority=high','tasks.php?overdue=1','tasks.php?assigned_to=4','tasks.php?search=zzzznope',
    'task_form.php','task_form.php?id=1','task_form.php?id=8','task_form.php?id=999',
    'task_form.php?client_id=1','task_form.php?lead_id=5',
    'activities.php','activities.php?page=2','activities.php?type=call','activities.php?type=meeting',
    'activities.php?user_id=2','activities.php?date_from=2026-09-01&date_to=2026-09-30',
    'activities.php?client_id=1','activities.php?lead_id=1',
    'activity_form.php','activity_form.php?id=1','activity_form.php?id=30','activity_form.php?id=999',
    'reports.php','users.php','users.php?role=staff','users.php?search=sarah',
    'user_form.php','user_form.php?id=2','user_form.php?id=999','profile.php'
)
foreach ($page in $adminPages) {
    Clear-Log
    $r = Invoke-App 'GET' $page $admin
    $errors = Get-LogErrors
    # A missing id or an authenticated hit on login.php correctly redirects.
    $expectRedirect = ($page -match 'id=999') -or ($page -eq 'login.php')
    $ok = if ($expectRedirect) { $r.Code -eq 302 -and $r.Location -ne '' }
          else { $r.Code -eq 200 -and $r.Body.Length -gt 3000 }
    Assert "admin $page" ($ok -and $errors.Count -eq 0) "code=$($r.Code) len=$($r.Body.Length) errs=$($errors.Count) $($errors -join ' | ')"
}
foreach ($page in @('index.php','clients.php','leads.php','pipeline.php','tasks.php',
                    'activities.php','reports.php','profile.php','client_view.php?id=1','lead_view.php?id=1')) {
    Clear-Log
    $r = Invoke-App 'GET' $page $staff
    Assert "staff $page" ($r.Code -eq 200 -and $r.Body.Length -gt 3000 -and (Get-LogErrors).Count -eq 0) "code=$($r.Code)"
}

# ---------------------------------------------------------------- 5. CRUD

Section 'CRUD lifecycle'
$t = Get-Token 'client_form.php' $admin
$r = Invoke-App 'POST' 'client_form.php' $admin @{
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
$r = Invoke-App 'POST' 'client_form.php' $admin @{
    _token = $t; id = $cid; company_name = 'Regression Co 2'; contact_person = 'RC'
    email = 'rc@x.test'; status = 'active'; assigned_to = 3
}
Assert 'update client' ($r.Location -match "^client_view\.php\?id=$cid$") "location=$($r.Location)"
Assert 'update persisted' ((Invoke-App 'GET' "client_view.php?id=$cid" $admin).Body -match 'Regression Co 2')

$t = Get-Token 'deal_form.php' $admin
$r = Invoke-App 'POST' 'deal_form.php' $admin @{
    _token = $t; deal_title = 'Regression Deal'; client_id = $cid; value = '5000'
    stage = 'proposal'; expected_close_date = '2026-12-01'; assigned_to = 2
}
Assert 'create deal' ($r.Location -eq 'pipeline.php') "location=$($r.Location)"
Assert 'deal on pipeline' ((Invoke-App 'GET' 'pipeline.php' $admin).Body -match 'Regression Deal')
$did = [int](Invoke-Sql "SELECT id FROM deals WHERE deal_title='Regression Deal' LIMIT 1;")
$t = Get-Token 'pipeline.php' $admin
Invoke-App 'POST' 'deal_action.php' $admin @{ _token = $t; action = 'move'; id = $did; stage = 'won'; return = 'pipeline.php' } | Out-Null
Assert 'move deal to won' ((Invoke-Sql "SELECT stage FROM deals WHERE id=$did;") -eq 'won')
$t = Get-Token 'pipeline.php' $admin
Invoke-App 'POST' 'deal_action.php' $admin @{ _token = $t; action = 'move'; id = $did; stage = 'BOGUS'; return = 'pipeline.php' } | Out-Null
Assert 'invalid stage rejected' ((Invoke-Sql "SELECT stage FROM deals WHERE id=$did;") -eq 'won')

$t = Get-Token 'task_form.php' $admin
$r = Invoke-App 'POST' 'task_form.php' $admin @{
    _token = $t; title = 'Regression Task'; client_id = $cid; due_date = '2026-11-15'
    priority = 'high'; status = 'pending'; assigned_to = 2
}
Assert 'create task' ($r.Location -eq 'tasks.php') "location=$($r.Location)"
$tid = [int](Invoke-Sql "SELECT id FROM tasks WHERE title='Regression Task' LIMIT 1;")
$t = Get-Token 'tasks.php' $admin
Invoke-App 'POST' 'task_action.php' $admin @{ _token = $t; action = 'complete'; id = $tid; return = 'tasks.php' } | Out-Null
Assert 'task completed + timestamp' (
    (Invoke-Sql "SELECT status FROM tasks WHERE id=$tid;") -eq 'completed' -and
    (Invoke-Sql "SELECT IFNULL(completed_at,'NULL') FROM tasks WHERE id=$tid;") -match '^20')
$t = Get-Token 'tasks.php' $admin
Invoke-App 'POST' 'task_action.php' $admin @{ _token = $t; action = 'reopen'; id = $tid; return = 'tasks.php' } | Out-Null
Assert 'task reopened, timestamp cleared' (
    (Invoke-Sql "SELECT status FROM tasks WHERE id=$tid;") -eq 'pending' -and
    (Invoke-Sql "SELECT IFNULL(completed_at,'NULL') FROM tasks WHERE id=$tid;") -eq 'NULL')

$t = Get-Token 'activity_form.php' $admin
$r = Invoke-App 'POST' 'activity_form.php' $admin @{
    _token = $t; client_id = $cid; type = 'call'; title = 'Regression Call'; details = 'Tested'
}
Assert 'create activity returns to client' ($r.Location -match "^client_view\.php\?id=$cid$") "location=$($r.Location)"
$detail = Invoke-App 'GET' "client_view.php?id=$cid" $admin
Assert 'client detail aggregates deal+task+activity' (
    ($detail.Body -match 'Regression Deal') -and
    ($detail.Body -match 'Regression Task') -and
    ($detail.Body -match 'Regression Call'))

$t = Get-Token 'lead_form.php' $admin
$r = Invoke-App 'POST' 'lead_form.php' $admin @{
    _token = $t; lead_name = 'Regression Lead'; company = 'Regression Ltd'
    email = 'rl@x.test'; lead_source = 'event'; status = 'new'; estimated_value = '7000'; assigned_to = 4
}
$lid = [int]([regex]::Match($r.Location, 'id=(\d+)').Groups[1].Value)
Assert 'create lead' ($lid -gt 0) "location=$($r.Location)"
$t = Get-Token 'deal_form.php' $admin
Invoke-App 'POST' 'deal_form.php' $admin @{
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
    @{ n = 'client empty company';  p = 'client_form.php';   f = @{ company_name = ''; contact_person = 'x'; status = 'active' } },
    @{ n = 'client bad email';     p = 'client_form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; email = 'nope' } },
    @{ n = 'client bad status';    p = 'client_form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'BOGUS' } },
    @{ n = 'client long contact';  p = 'client_form.php';   f = @{ company_name = 'x'; contact_person = ('A' * 121); status = 'active' } },
    @{ n = 'client long phone';    p = 'client_form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; phone = ('1' * 41) } },
    @{ n = 'client long address';  p = 'client_form.php';   f = @{ company_name = 'x'; contact_person = 'y'; status = 'active'; address = ('a' * 256) } },
    @{ n = 'lead empty name';      p = 'lead_form.php';     f = @{ lead_name = ''; lead_source = 'website'; status = 'new' } },
    @{ n = 'lead bad source';      p = 'lead_form.php';     f = @{ lead_name = 'x'; lead_source = 'BOGUS'; status = 'new' } },
    @{ n = 'lead negative value';  p = 'lead_form.php';     f = @{ lead_name = 'x'; lead_source = 'website'; status = 'new'; estimated_value = '-5' } },
    @{ n = 'lead long company';    p = 'lead_form.php';     f = @{ lead_name = 'x'; lead_source = 'website'; status = 'new'; company = ('c' * 151) } },
    @{ n = 'task bad priority';    p = 'task_form.php';     f = @{ title = 'x'; priority = 'BOGUS'; status = 'pending' } },
    @{ n = 'task bad date';        p = 'task_form.php';     f = @{ title = 'x'; priority = 'low'; status = 'pending'; due_date = '2026-99-99' } },
    @{ n = 'task long title';      p = 'task_form.php';     f = @{ title = ('t' * 181); priority = 'low'; status = 'pending' } },
    @{ n = 'deal no link';         p = 'deal_form.php';     f = @{ deal_title = 'x'; client_id = 0; lead_id = 0; stage = 'won'; value = '1' } },
    @{ n = 'deal bad stage';       p = 'deal_form.php';     f = @{ deal_title = 'x'; client_id = 1; stage = 'BOGUS'; value = '1' } },
    @{ n = 'deal bad close date';  p = 'deal_form.php';     f = @{ deal_title = 'x'; client_id = 1; stage = 'won'; value = '1'; expected_close_date = '2026-13-45' } },
    @{ n = 'activity orphan';      p = 'activity_form.php'; f = @{ client_id = 0; lead_id = 0; type = 'call'; title = 'x' } },
    @{ n = 'activity bad type';    p = 'activity_form.php'; f = @{ client_id = 1; type = 'BOGUS'; title = 'x' } },
    @{ n = 'activity long title';  p = 'activity_form.php'; f = @{ client_id = 1; type = 'note'; title = ('a' * 181) } }
)
foreach ($case in $badInputs) {
    Clear-Log
    $fields = $case.f.Clone()
    $fields['_token'] = Get-Token $case.p $admin
    $r = Invoke-App 'POST' $case.p $admin $fields
    $errors = Get-LogErrors
    Assert "$($case.n) rejected, no PHP error" ($r.Location -match '\.php$' -and $errors.Count -eq 0) "location=$($r.Location) errs=$($errors.Count) $($errors -join ' | ')"
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
    (Invoke-App 'POST' 'client_action.php' $admin @{ action = 'delete'; id = $cid; return = 'clients.php' }) -and
    (Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cid;") -eq $beforeCsrf)
Assert 'POST with bad CSRF token changes nothing' (
    (Invoke-App 'POST' 'client_action.php' $admin @{ _token = 'deadbeef'; action = 'delete'; id = $cid; return = 'clients.php' }) -and
    (Invoke-Sql "SELECT COUNT(*) FROM clients WHERE id=$cid;") -eq $beforeCsrf)

# The return parameter must be ignored as a redirect target. Use a throwaway
# record so this does not disturb the CRUD fixture above.
$t = Get-Token 'client_form.php' $admin
$throwaway = [int]([regex]::Match(
    (Invoke-App 'POST' 'client_form.php' $admin @{
        _token = $t; company_name = 'Redirect Probe'; contact_person = 'RP'; status = 'prospect'
    }).Location, 'id=(\d+)').Groups[1].Value)
$t = Get-Token 'clients.php' $admin
$redirect = Invoke-App 'POST' 'client_action.php' $admin @{
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

$t = Get-Token 'client_form.php' $admin
$r = Invoke-App 'POST' 'client_form.php' $admin @{
    _token = $t; company_name = '<script>alert(1)</script>XssRegression'
    contact_person = '"><img src=x onerror=alert(2)>'; status = 'active'
}
$xid = [int]([regex]::Match($r.Location, 'id=(\d+)').Groups[1].Value)
$xbody = (Invoke-App 'GET' "client_view.php?id=$xid" $admin).Body
Assert 'stored XSS escaped (script)' (-not ($xbody.Contains('<script>alert(1)</script>')))
Assert 'stored XSS escaped (attribute)' (-not ($xbody -match '<img[^>]*onerror'))
Invoke-Sql "DELETE FROM clients WHERE id=$xid;" | Out-Null

$t = Get-Token 'user_form.php' $admin
Invoke-App 'POST' 'user_form.php' $admin @{
    _token = $t; id = 1; name = 'Alex Morgan'; email = 'admin@clientflow.test'
    role = 'staff'; password = ''; is_active = '1'
} | Out-Null
Assert 'cannot demote the only admin' ((Invoke-Sql 'SELECT role FROM users WHERE id=1;') -eq 'admin')
Assert 'last-admin error surfaced' ((Invoke-App 'GET' 'user_form.php?id=1' $admin).Body -match 'only active admin')
$t = Get-Token 'users.php' $admin
Invoke-App 'POST' 'user_action.php' $admin @{ _token = $t; action = 'delete'; id = 1 } | Out-Null
Assert 'admin cannot delete own account' ((Invoke-Sql 'SELECT COUNT(*) FROM users WHERE id=1;') -eq '1')

$t = Get-Token 'profile.php' $admin
Invoke-App 'POST' 'profile.php' $admin @{
    _token = $t; action = 'password'; current_password = 'wrongpass'
    new_password = 'abcdefgh123'; confirm_password = 'abcdefgh123'
} | Out-Null
Assert 'wrong current password rejected' ((Invoke-App 'GET' 'profile.php' $admin).Body -match 'current password is not correct')
$t = Get-Token 'profile.php' $admin
Invoke-App 'POST' 'profile.php' $admin @{
    _token = $t; action = 'details'; name = 'Alex Morgan'; email = 'sarah@clientflow.test'
} | Out-Null
Assert 'profile duplicate email rejected' ((Invoke-Sql 'SELECT email FROM users WHERE id=1;') -eq 'admin@clientflow.test')

# ---------------------------------------------------------------- 8. reports

Section 'Reports content'
$reports = Invoke-App 'GET' 'reports.php' $admin
foreach ($block in @('Monthly activity','Deal values by stage','Team performance','Won vs lost',
                     'Lead sources','Activity mix','Clients by owner','Recent wins')) {
    Assert "reports: $block" ($reports.Body -match [regex]::Escape($block))
}

# ---------------------------------------------------------------- 9. accessibility

Section 'Accessibility spot checks'
foreach ($page in @('clients.php','leads.php','tasks.php','activities.php','users.php')) {
    $html = (Invoke-App 'GET' $page $admin).Body
    $th = ([regex]::Matches($html, '<th')).Count
    $thScoped = ([regex]::Matches($html, '<th[^>]*scope=')).Count
    Assert "${page}: all <th> carry scope" ($th -gt 0 -and $th -eq $thScoped) "$thScoped of $th"
    $iconBtns = [regex]::Matches($html, '<(?:a|button)[^>]*>\s*<i class="bi')
    $labelled = ([regex]::Matches($html, 'aria-label=')).Count
    Assert "${page}: icon buttons labelled" ($labelled -gt 0) "aria-labels=$labelled iconBtns=$($iconBtns.Count)"
}

# ---------------------------------------------------------------- 10. flash

Section 'Flash messages render once'
foreach ($case in @(
    @{ n = 'create client'; p = 'client_form.php';   f = @{ company_name = 'Flash Co'; contact_person = 'F'; status = 'prospect' }; loc = 'clients.php'; expect = 'was created' },
    @{ n = 'delete client'; p = 'client_action.php'; f = @{ action = 'delete'; return = 'clients.php' }; loc = 'clients.php'; expect = 'was deleted' },
    @{ n = 'create lead';   p = 'lead_form.php';     f = @{ lead_name = 'FlashLead'; lead_source = 'website'; status = 'new'; estimated_value = '10' }; loc = 'leads.php'; expect = 'was created' },
    @{ n = 'create task';   p = 'task_form.php';     f = @{ title = 'FlashTask'; priority = 'low'; status = 'pending'; due_date = '2026-12-01' }; loc = 'tasks.php'; expect = 'was created' },
    @{ n = 'create deal';   p = 'deal_form.php';     f = @{ deal_title = 'FlashDeal'; client_id = 1; stage = 'contacted'; value = '42' }; loc = 'pipeline.php'; expect = 'added to the pipeline' }
)) {
    # The token must come from a GET page. *_action.php endpoints are POST-only
    # and return an empty 302, so they carry no usable token.
    $fields = $case.f.Clone()
    $fields['_token'] = Get-Token $case.loc $admin
    if ($case.p -like '*_action.php') {
        $table = 'clients'; $col = 'company_name'
        if ($case.p -like 'lead*') { $table = 'leads'; $col = 'lead_name' }
        if ($case.p -like 'task*') { $table = 'tasks'; $col = 'title' }
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
$t = Get-Token 'users.php' $admin
Invoke-App 'POST' 'user_action.php' $admin @{
    _token = $t; action = 'create'; name = 'Modal Test'; email = 'sarah@clientflow.test'
    password = 'password123'; role = 'staff'
} | Out-Null
$usersPage = (Invoke-App 'GET' 'users.php' $admin).Body
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
            DELETE FROM users   WHERE email LIKE '%Regression%';" | Out-Null

$after = Invoke-Sql "SELECT CONCAT(
    (SELECT COUNT(*) FROM users),    '/',
    (SELECT COUNT(*) FROM clients),  '/',
    (SELECT COUNT(*) FROM leads),    '/',
    (SELECT COUNT(*) FROM deals),    '/',
    (SELECT COUNT(*) FROM tasks),    '/',
    (SELECT COUNT(*) FROM activities))"
Assert 'row counts restored to baseline' ($after -eq $baseline) "before=$baseline after=$after"

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