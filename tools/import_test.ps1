<#
.SYNOPSIS
    Tests CSV import (Phase C).

.DESCRIPTION
    Import is the only path in the app that writes many rows from data the app
    did not create, so this is where the correctness work lives.

    The properties that matter, in the order they would hurt:

    1. Nothing is written before the user confirms. An import that fires on
       upload turns "I picked the wrong file" into four hundred wrong records.
    2. A plan cannot be reused. A reload of the confirm step must not import
       twice.
    3. One transaction, so a failure cannot leave half a list behind.
    4. Rows with errors are skipped and reported, not silently dropped and not
       fatal to the whole file.
    5. Real-world files parse. Excel writes semicolons for European locales,
       prepends a UTF-8 BOM, and formats money as "GBP1,234.56".
    6. Cross-tenant: an import can only ever land in the signed-in workspace.

    Self-cleaning. Two throwaway workspaces, removed afterwards.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\import_test.ps1
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

function Sign-In([string]$Slug, [string]$Email, [string]$Password) {
    $ctx = New-Session
    try {
        $body = @{ _token = $ctx.Token; workspace = $Slug; email = $Email; password = $Password }
        $r = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
        return @{ Ctx = $ctx; Ok = ($r.BaseResponse.ResponseUri.AbsoluteUri -match 'dashboard\.php') }
    } catch { return @{ Ctx = $ctx; Ok = $false } }
}

# The app issues a fresh CSRF token per rendered form, so a token captured at
# sign-in is stale by the time we POST. Every mutating helper therefore reads the
# token off the page it is about to post to.
#
# The full URL including its query string matters here. import.php stages a plan
# per record type, and loading the page with a different ?type= throws the staged
# plan away. Stripping the query string therefore looked up the token on the
# *client* page while posting a *lead* import, silently discarding the plan and
# making the confirm step do nothing.
function Fresh-Token($ctx, [string]$url) {
    try {
        $page = Invoke-WebRequest "$BaseUrl/$url" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 20
        return [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    } catch {
        return $ctx.Token
    }
}

# Posts a CSV file. -Form was only added in PowerShell 6, and these suites have
# to run on 5.1, so the multipart body is built by hand.
function Post-Csv($ctx, [string]$url, [string]$fileName, [string]$content, [hashtable]$extra = @{}) {
    $token = Fresh-Token $ctx $url
    $boundary = '----cfboundary' + [Guid]::NewGuid().ToString('N')
    $nl = "`r`n"
    $parts = New-Object System.Collections.Generic.List[byte]

    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes(
        "--$boundary$nl" +
        "Content-Disposition: form-data; name=`"_token`"$nl$nl" +
        "$token$nl"))

    foreach ($k in $extra.Keys) {
        $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes(
            "--$boundary$nl" +
            "Content-Disposition: form-data; name=`"$k`"$nl$nl" +
            "$($extra[$k])$nl"))
    }

    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes(
        "--$boundary$nl" +
        "Content-Disposition: form-data; name=`"csv`"; filename=`"$fileName`"$nl" +
        "Content-Type: text/csv$nl$nl"))
    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes($content))
    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes("$nl--$boundary--$nl"))

    try {
        $r = Invoke-WebRequest "$BaseUrl/$url" -Method POST -WebSession $ctx.Session `
            -Body $parts.ToArray() -ContentType "multipart/form-data; boundary=$boundary" `
            -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
        return @{ Body = $r.Content; Final = $r.BaseResponse.ResponseUri.AbsoluteUri }
    } catch {
        $script:pageErrors += @{ Path = "$url (csv upload)"; Message = $_.Exception.Message }
        return @{ Body = ''; Final = '(error)' }
    }
}

function Get-Safe($ctx, [string]$url) {
    try { return (Invoke-WebRequest "$BaseUrl/$url" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 25).Content }
    catch {
        $script:pageErrors += @{ Path = "$url (GET)"; Message = $_.Exception.Message }
        return ''
    }
}

function Post-Form($ctx, [string]$url, [hashtable]$fields) {
    $token = Fresh-Token $ctx $url
    try {
        $body = @{ _token = $token } + $fields
        $r = Invoke-WebRequest "$BaseUrl/$url" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
        return @{ Body = $r.Content; Final = $r.BaseResponse.ResponseUri.AbsoluteUri }
    } catch {
        $script:pageErrors += @{ Path = "$url (POST)"; Message = $_.Exception.Message }
        return @{ Body = ''; Final = '(error)' }
    }
}

function Count-Where([string]$sql) {
    return [int](Sql $sql)
}

Write-Output 'ClientFlow CRM CSV import test'
Write-Output "Target: $BaseUrl"

$stamp    = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$slugA    = "imp-a-$stamp"
$slugB    = "imp-b-$stamp"
$emailA   = "imp-admin-a-$stamp@import.test"
$emailB   = "imp-admin-b-$stamp@import.test"
$Password = 'Import!Test1'

$hash = (& $Php -r "echo password_hash('$Password', PASSWORD_DEFAULT);").Trim()
if ([string]::IsNullOrWhiteSpace($hash)) { Write-Output 'ABORT: could not hash the test password'; exit 1 }

function Remove-Fixtures {
    $null = Sql @"
DELETE FROM $DbName.activities WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.tasks      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.deals      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.leads      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.clients    WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.login_attempts WHERE email LIKE '%@import.test';
DELETE FROM $DbName.users   WHERE email LIKE '%@import.test';
-- audit_log first: fk_audit_tenant is ON DELETE RESTRICT, so a log row left
-- behind by an audited operation would block the tenant delete (error 1451).
DELETE FROM $DbName.audit_log   WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'imp-%');
DELETE FROM $DbName.tenants WHERE slug LIKE 'imp-%';
"@
}

Remove-Fixtures
$err = Sql @"
INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone)
     VALUES ('Import A', '$slugA', 'pro', 'active', 'GBP', 'Europe/London'),
            ('Import B', '$slugB', 'pro', 'active', 'USD', 'America/New_York');
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Imp Admin A', '$emailA', '$hash', 'admin', NULL, 1, 0),
            (@b, 'Imp Admin B', '$emailB', '$hash', 'admin', NULL, 1, 0);
"@
if ($err -match 'ERROR') { Write-Output "ABORT: fixture insert failed: $err"; exit 1 }

try {
    $a = Sign-In $slugA $emailA $Password
    $b = Sign-In $slugB $emailB $Password
    if (-not ($a.Ok -and $b.Ok)) { Write-Output 'ABORT: could not sign in'; exit 1 }

    $countA = { param($extra = '') Count-Where "SELECT COUNT(*) FROM $DbName.clients c JOIN $DbName.tenants t ON t.id=c.tenant_id WHERE t.slug='$slugA' $extra;" }
    $countB = { param($extra = '') Count-Where "SELECT COUNT(*) FROM $DbName.clients c JOIN $DbName.tenants t ON t.id=c.tenant_id WHERE t.slug='$slugB' $extra;" }

    # -----------------------------------------------------------------------
    Section '1. Upload shows a plan and writes nothing'
    $csv = @"
Company,Contact,Email,Phone,Status
Acme Import1,Jo One,jo1@import.test,111,active
Beta Import2,Sam Two,sam2@import.test,222,prospect
"@
    $before = & $countA
    $up = Post-Csv $a.Ctx 'import.php?type=client' 'clients.csv' $csv
    Check 'upload landed on the review step' ($up.Final -match 'import\.php') $up.Final
    Check 'nothing has been written yet'      ((& $countA) -eq $before)
    Check 'the review counts the rows'       ($up.Body -match '2 data row')
    Check 'it says nothing is saved yet'      ($up.Body -match 'Nothing has been saved yet')
    Check 'it offers to import 2'             ($up.Body -match 'Import 2 record')

    # -----------------------------------------------------------------------
    Section '2. Confirming writes exactly what was previewed'
    $done = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'the import finished page is shown' ($done.Body -match 'Import finished')
    Check '2 created'                          ($done.Body -match '>2</strong>\s*created')
    Check 'the rows are in the database'       ((& $countA) -eq ($before + 2))
    Check 'Acme landed'                        (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name LIKE 'Acme Import%';")) -eq 1)
    Check 'Beta landed'                        (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name LIKE 'Beta Import%';")) -eq 1)

    # -----------------------------------------------------------------------
    Section '3. A plan cannot be replayed'
    $afterFirst = & $countA
    $replay = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'a second confirm imports nothing' ((& $countA) -eq $afterFirst) "$( & $countA ) vs $afterFirst"
    Check 'the second confirm shows the upload form' ($replay.Body -match 'Choose a CSV')

    # -----------------------------------------------------------------------
    Section '4. Real-world file shapes'
    # Excel for a European locale: semicolon delimiter, CRLF, a UTF-8 BOM.
    # No stray quote before "Name" - one there would start a quoted field that
    # swallowed the rest of the file, and the failure looks like the app
    # rejecting a perfectly good CSV.
    $bom = [char]0xFEFF
    $euro = @"
$bom" + "Name;Company;Email;Source;Status;Value
Rolf Semicolon;Semicolon Ltd;rolf@import.test;referral;contacted;GBP1,234.56
"@ -replace "`n", "`r`n"
    $upE = Post-Csv $a.Ctx 'import.php?type=lead' 'leads.csv' $euro
    Check 'a semicolon file with a BOM is accepted' ($upE.Body -match 'Import 1 record')
    Check 'the BOM did not hide the Name column'     ($upE.Body -notmatch 'no &quot;Name&quot; column' -and $upE.Body -notmatch 'file has no')
    $doneE = Post-Form $a.Ctx 'import.php?type=lead' @{ action = 'commit' }
    Check 'the semicolon lead was created' (((Count-Where "SELECT COUNT(*) FROM $DbName.leads WHERE lead_name = 'Rolf Semicolon';")) -eq 1)
    Check 'the money was read as 1234.56'  (((Count-Where "SELECT COUNT(*) FROM $DbName.leads WHERE lead_name = 'Rolf Semicolon' AND estimated_value = 1234.56;")) -eq 1)
    Check 'the source was normalised'      (((Count-Where "SELECT COUNT(*) FROM $DbName.leads WHERE lead_name = 'Rolf Semicolon' AND lead_source = 'referral';")) -eq 1)

    # -----------------------------------------------------------------------
    Section '5. Loose header matching'
    $loose = @"
COMPANY NAME,Contact Person,E-Mail Address,Assigned To,Notes
Loose Header Co,Loose Contact,loose@import.test,Imp Admin A,note text
"@
    $upL = Post-Csv $a.Ctx 'import.php?type=client' 'loose.csv' $loose
    Check 'spaced and punctuated headings are matched' ($upL.Body -match 'Import 1 record')
    $doneL = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'the row was created'      (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Loose Header Co';")) -eq 1)
    Check 'the notes landed'         (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Loose Header Co' AND notes = 'note text';")) -eq 1)
    Check 'the owner was resolved'   (((Count-Where "SELECT COUNT(*) FROM $DbName.clients c JOIN $DbName.users u ON u.id=c.assigned_to WHERE c.company_name = 'Loose Header Co' AND u.email = '$emailA';")) -eq 1)

    Section '6. An unknown owner is a warning, not a failure'
    $badOwner = @"
Company,Contact,Assigned To
Unknown Owner Co,Some One,Nobody At All
"@
    $upO = Post-Csv $a.Ctx 'import.php?type=client' 'owner.csv' $badOwner
    Check 'the row still imports'          ($upO.Body -match 'Import 1 record')
    Check 'the unknown owner is flagged'   ($upO.Body -match 'left unassigned')
    $doneO = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'it was created unassigned' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Unknown Owner Co' AND assigned_to IS NULL;")) -eq 1)

    # -----------------------------------------------------------------------
    Section '7. Bad rows are skipped and reported, not fatal'
    $mixed = @"
Company,Contact,Email,Status
Good One,G1,g1@import.test,active
,Missing Company,g2@import.test,active
Bad Status Co,G3,g3@import.test,teleported
Bad Email Co,G4,not-an-email,active
Also Good,G5,g5@import.test,prospect
"@
    $upM = Post-Csv $a.Ctx 'import.php?type=client' 'mixed.csv' $mixed
    Check '5 rows counted'    ($upM.Body -match '5 data row')
    Check '2 will be created' ($upM.Body -match 'Import 2 record')
    Check 'the blank company is named'   ($upM.Body -match 'Company is blank')
    Check 'the unknown status is named'  ($upM.Body -match 'Unknown status')
    Check 'the bad email is named'       ($upM.Body -match 'valid email address')
    $goodBefore = Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('Good One','Also Good');"
    $doneM = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'the two good rows landed' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('Good One','Also Good');")) -eq 2)
    Check 'no row was written twice' (Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('Good One','Also Good');") -eq ($goodBefore + 2)
    Check 'the bad rows were not written' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('','Bad Status Co','Bad Email Co');")) -eq 0)

    # -----------------------------------------------------------------------
    Section '8. Duplicates are detected and skipped by default'
    $dup = @"
Company,Contact,Email
Acme Import1,Jo One,jo1@import.test
Totally New,Tot New,new@import.test
"@
    $upD = Post-Csv $a.Ctx 'import.php?type=client' 'dup.csv' $dup
    Check 'the duplicate is counted'      ($upD.Body -match '1</div>\s*<div class="small text-secondary">look like existing')
    Check 'duplicates are skipped by default' ($upD.Body -match 'checked in the form for the checkbox|also import matches')
    $countBeforeDup = & $countA
    $doneD = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'only the new row landed' ((& $countA) -eq ($countBeforeDup + 1)) "$( & $countA ) vs $($countBeforeDup + 1)"
    Check 'the duplicate was not added' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Acme Import1';")) -eq 1)

    Section '9. Duplicates can be imported deliberately'
    $dup2 = @"
Company,Contact,Email
Acme Import1,Jo One,jo1@import.test
"@
    $null = Post-Csv $a.Ctx 'import.php?type=client' 'dup2.csv' $dup2
    $beforeWith = Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Acme Import1';"
    $null = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit'; include_duplicates = '1' }
    Check 'ticking the box imports it too' (Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Acme Import1';") -eq ($beforeWith + 1)

    # -----------------------------------------------------------------------
    Section '10. Whole-file failures'
    $noCompany = @"
Contact,Email
Only A Contact,a@import.test
"@
    $upN = Post-Csv $a.Ctx 'import.php?type=client' 'nocompany.csv' $noCompany
    Check 'a file with no Company column is refused' ($upN.Body -match 'no Company column')
    Check 'and nothing was written'                 (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE contact_person = 'Only A Contact';")) -eq 0)

    $headersOnly = "Company,Contact`n"
    $upH = Post-Csv $a.Ctx 'import.php?type=client' 'headers.csv' $headersOnly
    Check 'a headers-only file is refused' ($upH.Body -match 'no data rows')

    $empty = ''
    $upE2 = Post-Csv $a.Ctx 'import.php?type=client' 'empty.csv' $empty
    Check 'an empty file is refused' ($upE2.Body -match 'empty')

    # -----------------------------------------------------------------------
    Section '11. Unrecognised columns are reported, not silently dropped'
    $unknown = @"
Company,Contact,Nickname,Favourite Colour
Extra Cols Co,Extra Contact,Rock,Blue
"@
    $upX = Post-Csv $a.Ctx 'import.php?type=client' 'extra.csv' $unknown
    Check 'the row still imports'      ($upX.Body -match 'Import 1 record')
    Check 'the ignored columns are named' ($upX.Body -match 'Nickname')
    Check 'and so is the other one'       ($upX.Body -match 'Favourite Colour')

    # -----------------------------------------------------------------------
    Section '12. Imports cannot cross workspaces'
    $crossCsv = @"
Company,Contact
Cross Check Co,Cross Contact
"@
    $countBeforeCross = & $countB
    $null = Post-Csv $a.Ctx 'import.php?type=client' 'cross.csv' $crossCsv
    $null = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'B gained nothing from A''s import' ((& $countB) -eq $countBeforeCross)

    # A plan built by A, then confirmed by B, must be refused. This is the same
    # class of bug as trusting a tenant id from a form.
    $planCsv = @"
Company,Contact
Plan Steal Co,Plan Steal
"@
    $null = Post-Csv $a.Ctx 'import.php?type=client' 'plan.csv' $planCsv
    $countBeforeReplay = & $countA
    $null = Post-Form $b.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'A''s plan cannot be confirmed by B' ((& $countA) -eq $countBeforeReplay) "$( & $countA ) vs $countBeforeReplay"
    Check 'and B did not gain A''s row'        (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Plan Steal Co';")) -eq 0)

    # A plan built for clients must not be committable as leads.
    # The CSV is built first: passing 'a' + "b" as arguments makes PowerShell
    # read the + as a separate argument, which aborts the script mid-run and
    # silently skips every section after it.
    $typeClashCsv = "Name,Company`nType Clash,TypeClash Ltd`n"
    $null = Post-Csv $a.Ctx 'import.php?type=client' 'typeclash.csv' $typeClashCsv
    $countBeforeType = & $countA
    $null = Post-Form $a.Ctx 'import.php?type=lead' @{ action = 'commit' }
    Check 'a client plan cannot be committed as leads' ((& $countA) -eq $countBeforeType)
    Check 'nothing landed as a lead either' (((Count-Where "SELECT COUNT(*) FROM $DbName.leads WHERE lead_name = 'Type Clash';")) -eq 0)

    # -----------------------------------------------------------------------
    Section '13. Staff can import into their own workspace'
    $staffEmail = "imp-staff-$stamp@import.test"
    $null = Sql @"
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Imp Staff', '$staffEmail', '$hash', 'staff', NULL, 1, 0);
"@
    $staff = Sign-In $slugA $staffEmail $Password
    Check 'staff signed in' ($staff.Ok)
    $staffCsv = "Company,Contact`nStaff Import Co,Staff Contact`n"
    $countBeforeStaff = & $countA
    $null = Post-Csv $staff.Ctx 'import.php?type=client' 'staff.csv' $staffCsv
    $null = Post-Form $staff.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'the staff import landed' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients c JOIN $DbName.users u ON u.id=c.created_by WHERE c.company_name = 'Staff Import Co' AND u.email = '$staffEmail';")) -eq 1)
    Check 'it landed in the right workspace' ((& $countA) -eq ($countBeforeStaff + 1))

    Section '14. Imported rows are ordinary records'
    # An import must not create records that behave differently from typed ones.
    $view = Get-Safe $a.Ctx ("clients/view.php?id=" + (Sql "SELECT id FROM $DbName.clients WHERE company_name = 'Staff Import Co';"))
    Check 'the imported client opens'  ($view -match 'Staff Import Co')
    Check 'it can be edited like any other' ($view -notmatch 'Something went wrong')
    $dupPage = Get-Safe $a.Ctx 'clients/index.php'
    Check 'it appears in the list' ($dupPage -match 'Staff Import Co')

    # -----------------------------------------------------------------------
    Section '15. Round-trip: export then import'
    $exportBody = Get-Safe $a.Ctx 'export.php?type=client'
    Check 'the export produced a header we can re-import' ($exportBody -match '^Company,Contact')
    # Feed the export's own header row back in with two new records.
    $headerLine = ($exportBody -split "`r?`n")[0]
    $reimport = $headerLine + "`n" + "Round Trip One,RT Contact,rt1@import.test,555,Address,active,,,note`n" + "Round Trip Two,RT Contact2,rt2@import.test,556,Address,prospect,,,note`n"
    $upR = Post-Csv $a.Ctx 'import.php?type=client' 'roundtrip.csv' $reimport
    Check 'our own export headers are importable' ($upR.Body -match 'Import 2 record') (($upR.Body -replace '<[^>]+>', ' ') -replace '\s+', ' ').Substring(0, [Math]::Min(200, (($upR.Body -replace '<[^>]+>', ' ') -replace '\s+', ' ').Length))
    $null = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    Check 'both round-tripped rows landed' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('Round Trip One','Round Trip Two');")) -eq 2)

    # -----------------------------------------------------------------------
    Section '16. Signed out cannot import'
    $anon = New-Session
    $anonUp = Post-Csv $anon 'import.php?type=client' 'anon.csv' "Company,Contact`nAnon Co,Anon`n"
    Check 'an anonymous upload does not write' (((Count-Where "SELECT COUNT(*) FROM $DbName.clients WHERE company_name = 'Anon Co';")) -eq 0)
}
finally {
    Write-Output ''
    Write-Output '== cleaning up =='
    Remove-Fixtures
    Check 'workspaces removed' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE slug LIKE 'imp-%';") -eq 0)
    Check 'no test users left' ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE '%@import.test';") -eq 0)
    Check 'no test clients left' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name LIKE '%Import%';") -eq 0)

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
