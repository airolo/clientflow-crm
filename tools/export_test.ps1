<#
.SYNOPSIS
    Tests CSV export (Phase B).

.DESCRIPTION
    Five exports, each driven over HTTP from two throwaway workspaces.

    Three properties matter here, and only one of them is about tenants:

    1. Completeness. An export has to include every row the workspace owns, not
       the current page. A silently truncated export is worse than none at all,
       because nobody checks the row count of a file they asked to be whole.
    2. Isolation. A's export must contain nothing of B's, which the record-level
       isolation test does not cover because this path uses its own queries.
    3. Formula injection. Company names are typed by sales staff and end up in a
       file someone opens in Excel. A name starting with = becomes a live
       formula. This is the one genuinely new risk in the phase, and it is not
       caught by any other suite.

    It also checks the mechanical details that are easy to get subtly wrong: the
    download headers, the filename, values containing commas and quotes not
    shifting every later column, and soft-deleted rows staying out.

    Self-cleaning. No workspace settings are changed, so nothing needs restoring.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\export_test.ps1
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
    $page = Invoke-WebRequest "$BaseUrl/auth/login.php" -WebSession $s -UseBasicParsing -TimeoutSec 15
    $token = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    return @{ Session = $s; Token = $token }
}

function Sign-In([string]$Slug, [string]$Email, [string]$Password) {
    $ctx = New-Session
    try {
        $body = @{ _token = $ctx.Token; workspace = $Slug; email = $Email; password = $Password }
        $r = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
        return @{ Ctx = $ctx; Ok = ($r.BaseResponse.ResponseUri.AbsoluteUri -match 'dashboard\.php') }
    } catch {
        return @{ Ctx = $ctx; Ok = $false }
    }
}

# Downloads a CSV and returns the body plus the response headers.
#
# -UseBasicParsing keeps this off the IE engine, which would otherwise mangle the
# raw bytes; the body is UTF-8 text either way.
function Get-Csv($ctx, [string]$url) {
    try {
        $r = Invoke-WebRequest "$BaseUrl/$url" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 25
        return @{ Body = $r.Content; Raw = $r.RawContent; Type = $r.Headers['Content-Type']; Disp = $r.Headers['Content-Disposition'] }
    } catch {
        return @{ Body = ''; Raw = ''; Type = ''; Disp = ''; Error = $_.Exception.Message }
    }
}

# Split CSV text into rows of fields, honouring quoted fields that contain
# commas or newlines. A naive -split ',' would report a column count that is
# simply wrong, and the test would then be asserting nothing.
function ConvertFrom-CsvText([string]$text) {
    $rows = @()
    $field = ''
    $row = @()
    $inQuotes = $false
    $i = 0
    while ($i -lt $text.Length) {
        $ch = $text[$i]
        if ($inQuotes) {
            if ($ch -eq '"') {
                if (($i + 1) -lt $text.Length -and $text[$i + 1] -eq '"') { $field += '"'; $i += 2; continue }
                $inQuotes = $false; $i++; continue
            }
            $field += $ch; $i++; continue
        }
        switch ($ch) {
            '"'  { $inQuotes = $true }
            ','  { $row += $field; $field = '' }
            "`r" { }
            "`n" { $row += $field; $rows += ,$row; $row = @(); $field = '' }
            default { $field += $ch }
        }
        $i++
    }
    if ($field -ne '' -or $row.Count -gt 0) { $row += $field; $rows += ,$row }
    # Drop a trailing blank line, if any.
    return $rows | Where-Object { $_.Count -gt 1 -or ($_.Count -eq 1 -and $_[0] -ne '') }
}

Write-Output 'ClientFlow CRM CSV export test'
Write-Output "Target: $BaseUrl"

$stamp    = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$slugA    = "exp-a-$stamp"
$slugB    = "exp-b-$stamp"
$emailA   = "exp-admin-a-$stamp@export.test"
$emailB   = "exp-admin-b-$stamp@export.test"
$Password = 'Export!Test1'

# Deliberately awkward values, each of which has broken a CSV export somewhere.
# An "=" prefix is the formula-injection case and is asserted separately.
$commaName   = "Smith, Jones and Co $stamp"
$quoteName   = 'The ' + [char]0x22 + 'Big' + [char]0x22 + ' Client ' + $stamp
$newlineName = "Line One" + [char]0x0A + "Line Two $stamp"
$formulaName = '=1+1'
$formulaPadName = ' =HYPERLINK(' + [char]0x22 + 'http://example.test' + [char]0x22 + ',' + [char]0x22 + 'x' + [char]0x22 + ')'
$atName = '@SUM(1+1)'

# Turn a PowerShell string into a SQL expression that evaluates to it exactly.
#
# Two traps, both hit while writing this file:
#
#  - A double quote typed into a string passed to mysql.exe via --execute is eaten
#    by Windows argument parsing before mysql sees it. The fixture then arrives
#    without its quotes and the test "proves" the app loses them.
#  - `||` is not string concatenation in MySQL; it is logical OR unless
#    PIPES_AS_CONCAT is set. An earlier attempt joined fragments with `||` and
#    silently produced the number 0 instead of the intended text.
#
# So: emit a CONCAT() with CHAR(34) for quotes and CHAR(10)/CHAR(13) for line
# breaks, and leave the escaping to the database.
function SqlText([string]$s) {
    $parts  = $s -split '"', -1
    $chunks = @()
    for ($i = 0; $i -lt $parts.Count; $i++) {
        $literal = $parts[$i] -replace "`r", '<CR>' -replace "`n", '<LF>'
        $segments = $literal -split '<CR>|<LF>', -1
        for ($j = 0; $j -lt $segments.Count; $j++) {
            if ($segments[$j] -ne '') {
                $chunks += "'" + ($segments[$j] -replace "'", "''") + "'"
            }
            if ($j -lt $segments.Count - 1) {
                $sep = if ($literal[$literal.IndexOf($segments[$j]) + $segments[$j].Length] -eq "`r") { 'CHAR(13)' } else { 'CHAR(10)' }
                $chunks += $sep
            }
        }
        if ($i -lt $parts.Count - 1) { $chunks += 'CHAR(34)' }
    }
    if ($chunks.Count -eq 0) { return "''" }
    if ($chunks.Count -eq 1 -and $chunks[0] -notmatch '^CHAR\(') { return $chunks[0] }
    return 'CONCAT(' + ($chunks -join ',') + ')'
}

$hash = (& $Php -r "echo password_hash('$Password', PASSWORD_DEFAULT);").Trim()
if ([string]::IsNullOrWhiteSpace($hash)) { Write-Output 'ABORT: could not hash the test password'; exit 1 }

function Remove-Fixtures {
    $null = Sql @"
DELETE FROM $DbName.activities WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.tasks      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.deals      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.leads      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.clients    WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.login_attempts WHERE email LIKE '%@export.test';
DELETE FROM $DbName.users   WHERE email LIKE '%@export.test';
-- audit_log first: fk_audit_tenant is ON DELETE RESTRICT, so a log row left
-- behind by an audited operation would block the tenant delete (error 1451).
DELETE FROM $DbName.audit_log   WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'exp-%');
DELETE FROM $DbName.tenants WHERE slug LIKE 'exp-%';
"@
}

Remove-Fixtures
$err = Sql @"
INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone)
     VALUES ('Export A', '$slugA', 'pro', 'active', 'GBP', 'Europe/London'),
            ('Export B', '$slugB', 'pro', 'active', 'USD', 'America/New_York');
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Export Admin A', '$emailA', '$hash', 'admin', NULL, 1, 0),
            (@b, 'Export Admin B', '$emailB', '$hash', 'admin', NULL, 1, 0);

INSERT INTO $DbName.clients (tenant_id, company_name, contact_person, email, status, created_by) VALUES
  (@a, 'Alpha Plain $stamp',          'A One',   'a1@export.test', 'active',   NULL),
  (@a, $(SqlText $commaName),         'A Two',   'a2@export.test', 'prospect', NULL),
  (@a, $(SqlText $quoteName),         'A Three', 'a3@export.test', 'active',   NULL),
  (@a, $(SqlText $newlineName),       'A Four',  'a4@export.test', 'prospect', NULL),
  (@a, '=1+1',                        'A Five',  'a5@export.test', 'active',   NULL),
  (@a, $(SqlText $formulaPadName),    'A Six',   'a6@export.test', 'active',   NULL),
  (@a, '@SUM(1+1)',                   'A Seven', 'a7@export.test', 'active',   NULL),
  (@b, 'Beta Plain $stamp',           'B One',   'b1@export.test', 'active',   NULL);

INSERT INTO $DbName.leads (tenant_id, lead_name, lead_source, status, estimated_value, created_by) VALUES
  (@a, 'AlphaLead $stamp', 'website', 'new', 1234.56, NULL),
  (@b, 'BetaLead $stamp',  'website', 'new', 999.99, NULL);

INSERT INTO $DbName.deals (tenant_id, deal_title, value, stage, created_by) VALUES
  (@a, 'AlphaDeal $stamp', 2500, 'proposal', NULL),
  (@b, 'BetaDeal $stamp',  3500, 'proposal', NULL);

INSERT INTO $DbName.tasks (tenant_id, title, priority, status, created_by) VALUES
  (@a, 'AlphaTask $stamp', 'high', 'pending', NULL),
  (@b, 'BetaTask $stamp',  'low',  'pending', NULL);

INSERT INTO $DbName.activities (tenant_id, type, title, created_by) VALUES
  (@a, 'call', 'AlphaCall $stamp', NULL),
  (@b, 'call', 'BetaCall $stamp', NULL);
"@
if ($err -match 'ERROR') { Write-Output "ABORT: fixture insert failed: $err"; exit 1 }

try {
    $a = Sign-In $slugA $emailA $Password
    $b = Sign-In $slugB $emailB $Password
    if (-not ($a.Ok -and $b.Ok)) { Write-Output 'ABORT: could not sign in to the fixtures'; exit 1 }

    # -----------------------------------------------------------------------
    Section '1. It is a download, not a page'
    $csvA = Get-Csv $a.Ctx 'export.php?type=client'
    Check 'Content-Type is text/csv'  ($csvA.Type -match 'text/csv')
    Check 'Content-Disposition attaches' ($csvA.Disp -match 'attachment')
    Check 'the filename names the workspace' ($csvA.Disp -match "clientflow-$slugA-client-")
    Check 'the filename carries the date'     ($csvA.Disp -match '\d{4}-\d{2}-\d{2}\.csv')
    Check 'the body is not HTML'              ($csvA.Body -notmatch '^\s*<')
    Check 'the header row is present'         ($csvA.Body -match '^Company,Contact,Email')

    # -----------------------------------------------------------------------
    Section '2. Every row, not just this page'
    # list_query caps a page at 10; an export must not inherit that. 30 rows is
    # comfortably past that cap and past ROWS_PER_PAGE.
    $many = 30
    $tuples = @()
    for ($n = 1; $n -le $many; $n++) {
        $tuples += "(@a, 'Bulk $stamp - $n', 'B', 'active', NULL)"
    }
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
INSERT INTO $DbName.clients (tenant_id, company_name, contact_person, status, created_by)
     VALUES $($tuples -join ',');
"@
    $rowsA = ConvertFrom-CsvText (Get-Csv $a.Ctx 'export.php?type=client').Body
    $dataRows = $rowsA.Count - 1
    $expected = [int](Sql "SELECT COUNT(*) FROM $DbName.clients c JOIN $DbName.tenants t ON t.id=c.tenant_id
                           WHERE t.slug='$slugA' AND c.deleted_at IS NULL;")
    Check "all $expected client rows exported" ($dataRows -eq $expected) "got $dataRows, expected $expected"
    Check 'more rows than one page would hold'   ($dataRows -gt 10)

    # -----------------------------------------------------------------------
    Section '3. Values containing commas, quotes and newlines'
    $parsed = ConvertFrom-CsvText $csvA.Body
    $header = $parsed[0]
    Check 'header column count is 9' ($header.Count -eq 9) "got $($header.Count)"
    $uniform = $true
    foreach ($r in $parsed) { if ($r.Count -ne 9) { $uniform = $false } }
    Check 'every row has 9 fields, so nothing shifted' $uniform

    $names = $parsed | Select-Object -Skip 1 | ForEach-Object { $_[0] }
    Check 'a comma in a value did not split the row' ($names -contains $commaName)
    Check 'a quote in a value round-tripped'         ($names -contains $quoteName)
    Check 'a newline in a value round-tripped'       ($names -contains $newlineName)

    # -----------------------------------------------------------------------
    Section '4. Formula injection is neutralised'
    # A company name starting with = is a live formula the moment the file is
    # opened in Excel. Prefixing with an apostrophe makes it text, and Excel does
    # not display the apostrophe.
    Check '=1+1 is prefixed, not left live'      ($names -contains ("'" + $formulaName))
    Check '=1+1 is not present unprefixed'        (-not ($names -contains $formulaName))
    # Leading whitespace is trimmed before testing, because Excel trims it too.
    Check 'a padded = is prefixed as well'        ($names -contains ("'" + $formulaPadName.Trim()))
    Check '@ is prefixed too'                     ($names -contains ("'" + $atName))
    Check 'a normal name is left alone'           ($names -contains ('Alpha Plain ' + $stamp))

    # -----------------------------------------------------------------------
    Section '5. Soft-deleted rows stay out'
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
UPDATE $DbName.clients SET deleted_at = NOW() WHERE tenant_id = @a AND company_name = 'Alpha Plain $stamp';
"@
    $after = ConvertFrom-CsvText (Get-Csv $a.Ctx 'export.php?type=client').Body
    $afterNames = $after | Select-Object -Skip 1 | ForEach-Object { $_[0] }
    Check 'the deleted client is gone from the export' (-not ($afterNames -contains ('Alpha Plain ' + $stamp)))
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
UPDATE $DbName.clients SET deleted_at = NULL WHERE tenant_id = @a AND company_name = 'Alpha Plain $stamp';
"@

    # -----------------------------------------------------------------------
    Section '6. Exports do not cross workspaces'
    foreach ($t in @('client', 'lead', 'deal', 'task', 'activity')) {
        $bodyA = (Get-Csv $a.Ctx "export.php?type=$t").Body
        $bodyB = (Get-Csv $b.Ctx "export.php?type=$t").Body
        $mine  = switch ($t) {
            'client'   { 'Alpha Plain ' + $stamp }
            'lead'     { 'AlphaLead ' + $stamp }
            'deal'     { 'AlphaDeal ' + $stamp }
            'task'     { 'AlphaTask ' + $stamp }
            'activity' { 'AlphaCall ' + $stamp }
        }
        $theirs = switch ($t) {
            'client'   { 'Beta Plain ' + $stamp }
            'lead'     { 'BetaLead ' + $stamp }
            'deal'     { 'BetaDeal ' + $stamp }
            'task'     { 'BetaTask ' + $stamp }
            'activity' { 'BetaCall ' + $stamp }
        }
        Check "$t : A's export has its own row"   ($bodyA -match [regex]::Escape($mine))
        Check "$t : A's export has no B rows"     ($bodyA -notmatch [regex]::Escape($theirs))
        Check "$t : B's export has its own row"   ($bodyB -match [regex]::Escape($theirs))
        Check "$t : B's export has no A rows"     ($bodyB -notmatch [regex]::Escape($mine))
    }

    # -----------------------------------------------------------------------
    Section '7. Money uses the workspace currency'
    $leadA = (Get-Csv $a.Ctx 'export.php?type=lead').Body
    $leadB = (Get-Csv $b.Ctx 'export.php?type=lead').Body
    # GBP has no literal symbol on this keyboard path, so assert the formatted
    # shape instead: "1,234.56" for A, "$999.99" for B. Both derive from money().
    Check 'A formats its lead value' ($leadA -match '1,234\.56')
    Check 'A does not show a dollar sign' ($leadA -notmatch '\$')
    Check 'B shows a dollar sign'      ($leadB -match '\$')

    # -----------------------------------------------------------------------
    Section '8. Bad or missing type is refused'
    $anon = New-Session
    $bad = Get-Csv $anon 'export.php?type=client'
    Check 'signed out cannot export' ($bad.Type -notmatch 'text/csv')

    $sess = $a.Ctx
    $nope = Get-Csv $sess 'export.php?type=not_a_thing'
    Check 'an unknown type is not served as CSV' ($nope.Type -notmatch 'text/csv')
    Check 'an unknown type does not crash'       ($nope.Error -eq '' -or $nope.Body -notmatch 'Fatal error')

    # A crafted type must not reach an arbitrary function. The map is a literal,
    # so this is really a check that the map is a map.
    $inj = Get-Csv $sess 'export.php?type[]=client'
    Check 'an array type is not served as CSV' ($inj.Type -notmatch 'text/csv')

    # -----------------------------------------------------------------------
    Section '9. Staff can export their own workspace'
    $staffEmail = "exp-staff-$stamp@export.test"
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Export Staff', '$staffEmail', '$hash', 'staff', NULL, 1, 0);
"@
    $staff = Sign-In $slugA $staffEmail $Password
    Check 'staff signed in' ($staff.Ok)
    $staffCsv = Get-Csv $staff.Ctx 'export.php?type=client'
    Check 'staff can export' ($staffCsv.Type -match 'text/csv')
    # The list screen already shows staff every email and phone in the workspace,
    # so an export grants nothing they could not already read - it just saves them
    # paging. Asserted here so the reasoning is checked, not assumed.
    Check "staff's export is scoped to their workspace" ($staffCsv.Body -notmatch [regex]::Escape('Beta Plain ' + $stamp))
    Check "staff's export includes their own workspace's data" ($staffCsv.Body -match [regex]::Escape('Alpha Plain ' + $stamp))
}
finally {
    Write-Output ''
    Write-Output '== cleaning up =='
    Remove-Fixtures
    Check 'workspaces removed' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE slug LIKE 'exp-%';") -eq 0)
    Check 'no test users left' ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE '%@export.test';") -eq 0)
    Check 'no test clients left' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name LIKE '%$stamp%';") -eq 0)
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'
if ($script:fail -gt 0) { exit 1 }
exit 0
