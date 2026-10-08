<#
.SYNOPSIS
    Tests the audit log: field-level, keep-forever.

.DESCRIPTION
    An audit log is only worth having if it is accurate, so this suite checks the
    things that are easy to get wrong and hard to notice by eye:

      - that an edit records the values before AND after, per field, rather than
        a bare "row 42 was updated"
      - that a save with nothing changed writes nothing, since a log full of
        no-op entries is a log nobody reads
      - that a purged record leaves a readable trail, though the row it described
        is gone
      - that one workspace can never see another's log, through the page, the
        filters, or a hand-edited parameter
      - that deletes, restores, sign-ins and imports are all recorded
      - that a deleted user's history survives them, with the name still readable

    Runs against real fixtures in the live database and removes them afterwards.
    The demo workspace's own entries are never touched.

.NOTES
    ASCII only. PowerShell 5.1 reads these files as ANSI unless they carry a BOM,
    so a smart quote or em dash silently becomes mojibake.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\audit_test.ps1
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

$RepoRoot = Split-Path -Parent $PSScriptRoot

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

function Sign-In([string]$slug, [string]$email, [string]$password) {
    $ctx = New-Session
    try {
        $body = @{ _token = $ctx.Token; workspace = $slug; email = $email; password = $password }
        $r = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
        return @{ Ctx = $ctx; Ok = ($r.BaseResponse.ResponseUri.AbsoluteUri -match 'dashboard\.php') }
    } catch { return @{ Ctx = $ctx; Ok = $false } }
}

# The app issues a fresh CSRF token per rendered form, so a token captured from
# an earlier page is rejected. Every post re-reads it from the page it targets,
# query string included.
function Fresh-Token($ctx, [string]$url) {
    try {
        $page = Invoke-WebRequest "$BaseUrl/$url" -WebSession $ctx.Session -UseBasicParsing -TimeoutSec 20
        $t = [regex]::Match($page.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
        if ($t -ne '') { return $t }
    } catch { }
    return $ctx.Token
}

function Post-Form($ctx, [string]$url, [hashtable]$fields) {
    $token = Fresh-Token $ctx $url
    $body = @{ _token = $token } + $fields
    try {
        $r = Invoke-WebRequest "$BaseUrl/$url" -Method POST -WebSession $ctx.Session `
            -Body $body -UseBasicParsing -TimeoutSec 25 -MaximumRedirection 5
        return @{ Body = $r.Content; Final = $r.BaseResponse.ResponseUri.AbsoluteUri; Ok = $true }
    } catch {
        $script:pageErrors += @{ Path = "$url (POST)"; Message = $_.Exception.Message }
        return @{ Body = ''; Final = '(error)'; Ok = $false }
    }
}

function Post-Csv($ctx, [string]$url, [string]$fileName, [string]$content) {
    $token = Fresh-Token $ctx $url
    $boundary = '----cfboundary' + [Guid]::NewGuid().ToString('N')
    $nl = "`r`n"
    $parts = New-Object System.Collections.Generic.List[byte]

    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes(
        "--$boundary$nl" + "Content-Disposition: form-data; name=`"_token`"$nl`n" + "$token$nl"))

    $parts.AddRange([System.Text.Encoding]::UTF8.GetBytes(
        "--$boundary$nl" + "Content-Disposition: form-data; name=`"csv`"; filename=`"$fileName`"$nl" +
        "Content-Type: text/csv$nl`n"))
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

# Status code for a path, unauthenticated. Used for the exposure checks, where
# what matters is that the server refuses, not what it says. .NET discards the
# response object for a 403, so the status has to be read from the exception.
function Status-Of([string]$path) {
    try {
        $r = Invoke-WebRequest "$BaseUrl/$path" -UseBasicParsing -TimeoutSec 15
        return [int]$r.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode.value__ }
        return 0
    }
}

# The change list for the newest entry matching a tenant and action, decoded.
# Returns '' when there is no such row, so a missing entry reads as a string
# comparison failure rather than an empty array that looks like a pass.
function ChangesFor([int]$tenantId, [string]$action, [int]$entityId = 0) {
    $entityClause = if ($entityId -gt 0) { " AND entity_id=$entityId" } else { '' }
    return (Sql "SELECT changes FROM $DbName.audit_log
                WHERE tenant_id=$tenantId AND action='$action'$entityClause
                ORDER BY id DESC LIMIT 1;")
}

function CountFor([int]$tenantId, [string]$action, [int]$entityId = 0) {
    $entityClause = if ($entityId -gt 0) { " AND entity_id=$entityId" } else { '' }
    return [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log
                     WHERE tenant_id=$tenantId AND action='$action'$entityClause;")
}

function Remove-Fixtures {
    $null = Sql @"
DELETE FROM $DbName.audit_log       WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.activities      WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.tasks           WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.deals           WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.leads           WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.clients         WHERE tenant_id IN (SELECT id FROM $DbName.tenants WHERE slug LIKE 'aud-%');
DELETE FROM $DbName.users           WHERE email LIKE '%@audit.test';
DELETE FROM $DbName.login_attempts  WHERE email LIKE '%@audit.test';
DELETE FROM $DbName.tenants         WHERE slug LIKE 'aud-%';
"@
}

Write-Output 'ClientFlow CRM audit log test'
Write-Output "Target: $BaseUrl"

# Stamped so a parallel run cannot collide, and so fixtures from an aborted run
# are still recognisable to the cleanup above.
$stamp    = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$slugA    = "aud-a-$stamp"
$slugB    = "aud-b-$stamp"
$emailA   = "aud-admin-a-$stamp@audit.test"
$emailB   = "aud-admin-b-$stamp@audit.test"
$staffMail = "aud-staff-$stamp@audit.test"
$Password = 'Audit!Test1'

$hash = (& $Php -r "echo password_hash('$Password', PASSWORD_DEFAULT);").Trim()
if ([string]::IsNullOrWhiteSpace($hash)) { Write-Output 'ABORT: could not hash the test password'; exit 1 }

Remove-Fixtures

$err = Sql @"
INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone, onboarded_at)
     VALUES ('Audit A', '$slugA', 'pro', 'active', 'GBP', 'Europe/London', NOW()),
            ('Audit B', '$slugB', 'pro', 'active', 'USD', 'America/New_York', NOW());
SET @a = (SELECT id FROM $DbName.tenants WHERE slug='$slugA');
SET @b = (SELECT id FROM $DbName.tenants WHERE slug='$slugB');
INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
     VALUES (@a, 'Audit Admin A', '$emailA', '$hash', 'admin', NULL, 1, 0),
            (@b, 'Audit Admin B', '$emailB', '$hash', 'admin', NULL, 1, 0),
            (@a, 'Audit Staff',    '$staffMail', '$hash', 'staff', NULL, 1, 0);
"@
if ($err -match 'ERROR') { Write-Output "ABORT: fixture insert failed: $err"; exit 1 }

$tenantA = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug='$slugA';")
$tenantB = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug='$slugB';")

# Entries the demo workspace already had, so the cleanup check can prove this run
# added nothing there.
$demoBefore = [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log a
                        JOIN $DbName.tenants t ON t.id=a.tenant_id
                       WHERE t.slug='clientflow-demo';")

try {
    $a = Sign-In $slugA $emailA $Password
    $b = Sign-In $slugB $emailB $Password
    if (-not ($a.Ok -and $b.Ok)) { Write-Output 'ABORT: could not sign in'; exit 1 }

    # -----------------------------------------------------------------------
    Section '1. Two workspaces, so isolation can be tested properly'
    Check 'two test workspaces exist' (($tenantA -gt 0) -and ($tenantB -gt 0)) "a=$tenantA b=$tenantB"
    Check 'signing in was recorded' ((CountFor $tenantA 'login') -ge 1)

    # -----------------------------------------------------------------------
    Section '2. A create is recorded with what it was created with'
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        company_name   = 'Audit Co'
        contact_person = 'Dana Reed'
        email          = 'dana@audit-co.test'
        status         = 'prospect'
    }
    $clientA = [int](Sql "SELECT id FROM $DbName.clients WHERE tenant_id=$tenantA AND company_name='Audit Co' LIMIT 1;")
    Check 'the client was created' ($clientA -gt 0) "id=$clientA"

    $create = ChangesFor $tenantA 'create' $clientA
    Check 'creating a record writes a create entry' ($create -ne '') "tenant=$tenantA id=$clientA"
    Check 'the entry names the company'  (($create -match 'Audit Co') -and ($create -match 'Company'))
    Check 'the entry records the new value, not the old' ($create -match '"from":null')
    Check 'the entry is attributed to the person who did it' `
          ((Sql "SELECT user_name FROM $DbName.audit_log WHERE tenant_id=$tenantA AND action='create' AND entity_id=$clientA ORDER BY id DESC LIMIT 1;") -eq 'Audit Admin A')

    # -----------------------------------------------------------------------
    Section '3. An edit records before and after, field by field'
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        id             = $clientA
        company_name   = 'Audit Co'
        contact_person = 'Dana Reed'
        email          = 'dana@audit-co.test'
        phone          = '555-0100'
        status         = 'active'
        notes          = 'first note'
    }
    $update = ChangesFor $tenantA 'update' $clientA
    Check 'editing a record writes an update entry' ($update -ne '') "id=$clientA"
    Check 'it shows the value before' ($update -match '"from":"prospect"')
    Check 'it shows the value after'  ($update -match '"to":"active"')
    Check 'it shows the new phone'    (($update -match '555-0100') -and ($update -match '"from":null'))
    Check 'the values are labelled for a human, not by column name' (($update -match '"label":"Status"') -and ($update -match '"label":"Phone"'))

    $changes = $update | ConvertFrom-Json
    $fields  = @($changes | ForEach-Object { $_.field })
    Check 'only the fields that changed are listed' `
          (($fields -contains 'status') -and ($fields -contains 'phone'))
    # The unchanged ones must not appear, or every save logs the whole record
    # and the real edits are impossible to find.
    Check 'unchanged fields are not listed' `
          (($fields -notcontains 'company_name') -and ($fields -notcontains 'contact_person')) ($fields -join ',')

    # -----------------------------------------------------------------------
    Section '4. A save with nothing changed writes nothing'
    $before = CountFor $tenantA 'update' $clientA
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        id             = $clientA
        company_name   = 'Audit Co'
        contact_person = 'Dana Reed'
        email          = 'dana@audit-co.test'
        phone          = '555-0100'
        status         = 'active'
        notes          = 'first note'
    }
    $after = CountFor $tenantA 'update' $clientA
    Check 'resaving an unchanged form adds no entry' ($after -eq $before) "before=$before after=$after"

    # Clearing a field is a real change, unlike resaving the same value.
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        id             = $clientA
        company_name   = 'Audit Co'
        contact_person = 'Dana Reed'
        email          = 'dana@audit-co.test'
        phone          = ''
        status         = 'active'
        notes          = 'first note'
    }
    $cleared = ChangesFor $tenantA 'update' $clientA
    Check 'clearing a field is recorded' (($cleared -match '"from":"555-0100"') -and ($cleared -match '"to":null'))
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        id             = $clientA
        company_name   = 'Audit Co'
        contact_person = 'Dana Reed'
        email          = 'dana@audit-co.test'
        phone          = '555-0100'
        status         = 'active'
        notes          = 'first note'
    }

    # -----------------------------------------------------------------------
    Section '5. Delete, restore and purge'
    $null = Post-Form $a.Ctx 'clients/action.php' @{ action = 'delete'; id = $clientA }
    Check 'deleting a record writes a delete entry' ((CountFor $tenantA 'delete' $clientA) -ge 1) "id=$clientA"
    Check 'the entry still names the record' `
          ((Sql "SELECT entity_label FROM $DbName.audit_log WHERE tenant_id=$tenantA AND action='delete' AND entity_id=$clientA ORDER BY id DESC LIMIT 1;") -eq 'Audit Co')
    Check 'the row is soft-deleted, not gone' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE id=$clientA AND deleted_at IS NOT NULL;") -eq 1)

    $null = Post-Form $a.Ctx 'admin/recycle_action.php' @{ action = 'restore'; type = 'client'; id = $clientA }
    Check 'restoring writes a restore entry' ((CountFor $tenantA 'restore' $clientA) -ge 1)
    Check 'the record is live again' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE id=$clientA AND deleted_at IS NULL;") -eq 1)

    # Straight back to the bin, through the same route the list page uses.
    # recycle_action.php only handles restore and purge, so deleting again has to
    # go via the record's own action page.
    $null = Post-Form $a.Ctx 'clients/action.php' @{ action = 'delete'; id = $clientA }
    Check 'it is back in the bin' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE id=$clientA AND deleted_at IS NOT NULL;") -eq 1)

    # Purge is confirmed by typing the record's name, so it needs the real one.
    $null = Post-Form $a.Ctx 'admin/recycle_action.php' @{ action = 'purge'; type = 'client'; id = $clientA; confirm = 'Audit Co' }
    Check 'purging writes a purge entry' ((CountFor $tenantA 'purge' $clientA) -ge 1)
    Check 'the record is really gone' ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE id=$clientA;") -eq 0)
    # This is the whole reason entity_id carries no foreign key.
    Check 'the audit row survived its record' ((CountFor $tenantA 'purge' $clientA) -ge 1)
    Check 'and it still carries the name' `
          ((Sql "SELECT entity_label FROM $DbName.audit_log WHERE tenant_id=$tenantA AND action='purge' ORDER BY id DESC LIMIT 1;") -eq 'Audit Co')

    # -----------------------------------------------------------------------
    Section '6. Sign-in history'
    Check 'a successful sign-in is recorded' ((CountFor $tenantA 'login') -ge 1)
    $null = Sign-In $slugA $emailA 'definitely-not-the-password'
    Check 'a failed sign-in is recorded' ((CountFor $tenantA 'login_failed') -ge 1)
    $failRow = Sql "SELECT entity_label, user_name FROM $DbName.audit_log
                     WHERE tenant_id=$tenantA AND action='login_failed' ORDER BY id DESC LIMIT 1;"
    Check 'the failure names the attempted address' ($failRow -match [regex]::Escape($emailA)) $failRow

    # A workspace slug that does not exist has no tenant to file the attempt
    # under, and audit_log.tenant_id is NOT NULL by design. login_attempts
    # already records those, so the audit log must hold no orphan row.
    $null = Sign-In 'aud-nosuchworkspace' 'nobody@audit.test' 'x'
    Check 'a failed sign-in to an unknown workspace writes no orphan audit row' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.audit_log a LEFT JOIN $DbName.tenants t ON t.id=a.tenant_id WHERE t.id IS NULL;") -eq 0)
    Check 'but it is still recorded for throttling' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.login_attempts WHERE email='nobody@audit.test';") -ge 1)

    # -----------------------------------------------------------------------
    Section '7. Other record types'
    $null = Post-Form $a.Ctx 'leads/form.php' @{
        lead_name = 'Robin Vale'; company = 'Vale Ltd'; lead_source = 'referral'; status = 'new'
    }
    $leadA = [int](Sql "SELECT id FROM $DbName.leads WHERE tenant_id=$tenantA AND lead_name='Robin Vale' LIMIT 1;")
    Check 'a lead create is recorded' ((CountFor $tenantA 'create' $leadA) -ge 1)
    Check 'it is filed as a lead, not a client' `
          ((Sql "SELECT entity_type FROM $DbName.audit_log WHERE tenant_id=$tenantA AND entity_id=$leadA AND action='create' ORDER BY id DESC LIMIT 1;") -eq 'lead')

    $null = Post-Form $a.Ctx 'tasks/form.php' @{
        title = 'Call Robin'; priority = 'medium'; status = 'pending'
    }
    $taskA = [int](Sql "SELECT id FROM $DbName.tasks WHERE tenant_id=$tenantA AND title='Call Robin' LIMIT 1;")
    Check 'a task create is recorded' ((CountFor $tenantA 'create' $taskA) -ge 1)

    # Completing from the list is a different code path from editing the form, so
    # it needs its own check.
    $null = Post-Form $a.Ctx 'tasks/action.php' @{ action = 'complete'; id = $taskA }
    $taskChanges = ChangesFor $tenantA 'update' $taskA
    Check 'completing a task is recorded' (($taskChanges -match 'completed') -and ($taskChanges -match '"label":"Status"'))
    # completed_at is derived from status. Logging it separately would present one
    # user action as two changes.
    Check 'the derived completed_at is not logged separately' ($taskChanges -notmatch 'completed_at')

    # -----------------------------------------------------------------------
    Section '8. Import summary'
    $csv = "Company,Contact,Email,Status`nImported Co,Pat Poole,pat@imported.test,prospect`nSecond Co,Sam Vale,sam@imported.test,prospect`n"
    $null = Post-Csv $a.Ctx 'import.php?type=client' 'clients.csv' $csv
    $null = Post-Form $a.Ctx 'import.php?type=client' @{ action = 'commit' }
    $imported = [int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE tenant_id=$tenantA AND company_name IN ('Imported Co','Second Co');")
    Check 'the CSV was imported' ($imported -eq 2) "rows=$imported"

    $importRow = Sql "SELECT changes FROM $DbName.audit_log WHERE tenant_id=$tenantA AND action='import' ORDER BY id DESC LIMIT 1;"
    Check 'the import is recorded as one summary' (($importRow -ne '') -and ($importRow -match 'imported'))
    Check 'the summary states the totals' ($importRow -match '2 imported')
    # Each row was already logged as a create by the model. The summary is about
    # the action, not a second copy of them.
    Check 'individual rows are not logged twice' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.audit_log WHERE tenant_id=$tenantA AND action='import';") -eq 1)

    # -----------------------------------------------------------------------
    Section '9. One workspace cannot read another log'
    $bravoSees = Get-Safe $b.Ctx 'admin/audit_log.php'
    Check 'the audit page renders for the other workspace' ($bravoSees -match 'Audit log')
    Check 'the other workspace company name is not visible' ($bravoSees -notmatch 'Audit Co')
    Check 'the other workspace owner name is not visible' ($bravoSees -notmatch 'Audit Admin A')

    # A filter naming a real entry must still not surface it elsewhere, even
    # with the right search text. The search term itself is echoed back into the
    # input, so the assertion is on the result count rather than on the page not
    # containing the words.
    $crossSearch = Get-Safe $b.Ctx 'admin/audit_log.php?search=Audit+Co'
    Check 'searching for another workspace record finds nothing' ($crossSearch -match 'No matching entries')

    # The page takes no id parameter. Confirming that a hand-edited one changes
    # nothing, rather than trusting that it is simply ignored.
    $crossId = Get-Safe $b.Ctx "admin/audit_log.php?entity_id=$tenantA"
    Check 'a hand-edited id parameter reaches no rows' ($crossId -notmatch 'Audit Co')

    # And at the data layer, which is where it would actually matter.
    Check 'no client rows are visible in the other workspace log' `
          ([int](Sql "SELECT COUNT(*) FROM $DbName.audit_log WHERE tenant_id=$tenantB AND entity_type='client';") -eq 0)

    # -----------------------------------------------------------------------
    Section '10. Per-record history on the detail pages'
    # A fresh record, because section 5 purged the earlier one and its trail is
    # no longer reachable from a page that requires the record to exist.
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        company_name = 'History Co'; contact_person = 'Ida Marsh'; status = 'prospect'
    }
    $histClient = [int](Sql "SELECT id FROM $DbName.clients WHERE tenant_id=$tenantA AND company_name='History Co' LIMIT 1;")
    Check 'the fixture client exists' ($histClient -gt 0) "id=$histClient"

    $detail = Get-Safe $a.Ctx "client_view.php?id=$histClient"
    Check 'the detail page renders a change history card' ($detail -match 'Change history')
    Check 'and shows the create entry'                  ($detail -match 'History Co')

    # Give it an edit, so the card has a before-and-after to render.
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        id = $histClient; company_name = 'History Co'; contact_person = 'Ida Marsh'
        status = 'active'; phone = '555-4242'
    }
    $detail = Get-Safe $a.Ctx "client_view.php?id=$histClient"
    Check 'the card shows the value before the edit' ($detail -match 'prospect')
    Check 'the card shows the value after the edit'  ($detail -match 'active')
    Check 'the old value is struck through'          ($detail -match 'line-through')
    Check 'the card names who made the change'       ($detail -match 'Audit Admin A')

    # The create entry lists every field it was created with, so "Contact"
    # legitimately appears once. If the update diff also listed it - which it
    # must not, since contact_person did not change - it would appear twice.
    # Counting is therefore the assertion; a -notmatch on 'Contact' would fail
    # against correct behaviour.
    $contactRows = @([regex]::Matches($detail, '>Contact:<')).Count
    Check 'the update did not list an unchanged field' ($contactRows -eq 1) "occurrences=$contactRows"

    # The same for a lead.
    $leadDetail = Get-Safe $a.Ctx "lead_view.php?id=$leadA"
    Check 'the lead page renders a change history card' ($leadDetail -match 'Change history')
    Check 'and shows that lead history'                 ($leadDetail -match 'Robin Vale')

    # A record nobody has edited: the card shows the create and nothing else.
    $null = Post-Form $a.Ctx 'clients/form.php' @{
        company_name = 'Untouched Co'; contact_person = 'Ida Marsh'; status = 'prospect'
    }
    $quiet = [int](Sql "SELECT id FROM $DbName.clients WHERE tenant_id=$tenantA AND company_name='Untouched Co' LIMIT 1;")
    Check 'the second fixture client exists' ($quiet -gt 0) "id=$quiet"
    $quietPage = Get-Safe $a.Ctx "client_view.php?id=$quiet"
    Check 'a record with only a create still renders the card' ($quietPage -match 'Change history')
    # Count the list items the history card renders. Not the badges: text-bg- is
    # the shared badge class and the status badges use it too. This record has no
    # deals, tasks or activities, so the only list items on the page are the
    # history card's.
    Check 'and lists exactly one entry' `
          (([regex]::Matches($quietPage, '<li class="list-group-item')).Count -eq 1) `
          "items=$(([regex]::Matches($quietPage, '<li class="list-group-item')).Count)"
    Check 'with no field-change detail' ($quietPage -notmatch 'line-through')

    # Another workspace must not see this record's history, even by walking ?id=
    # with a real id from the first workspace. client_find() is tenant-scoped, so
    # the page refuses rather than rendering an empty card - which is the
    # behaviour worth asserting, since an empty card would look like "no history".
    $cross = Get-Safe $b.Ctx "client_view.php?id=$histClient"
    Check 'another workspace cannot open the record' ($cross -notmatch 'Change history')
    Check 'and is not shown its name'                ($cross -notmatch 'History Co')

    # The partial is only ever rendered from a page; fetching it directly must not
    # work, because it reads the entity from the caller's scope and would render
    # a history card for whatever the caller's scope happened to hold. The
    # .htaccess rule blocks all of views/, and this proves it covers the newest
    # file added there.
    $partialStatus = Status-Of 'views/audit_history.php'
    Check 'the history partial is not web-accessible' `
          ($partialStatus -in 403, 404) "status=$partialStatus"

    # -----------------------------------------------------------------------
    Section '11. Access control'
    $staff = Sign-In $slugA $staffMail $Password
    if (-not $staff.Ok) {
        Check 'the staff account can sign in' $false
    } else {
        # require_admin() redirects rather than returning 403, and
        # Invoke-WebRequest follows redirects, so the assertion is on where the
        # request ended up. Looking for a non-200 would pass even if the page
        # rendered for the wrong reason.
        try {
            $r = Invoke-WebRequest "$BaseUrl/admin/audit_log.php" -WebSession $staff.Ctx.Session `
                -UseBasicParsing -TimeoutSec 20 -MaximumRedirection 5
            $landed = $r.BaseResponse.ResponseUri.AbsoluteUri
            Check 'a non-admin is bounced off the audit page' ($landed -notmatch 'audit_log\.php') $landed
            Check 'and lands somewhere they are allowed'          ($landed -match 'dashboard\.php') $landed
        } catch {
            Check 'a non-admin is bounced off the audit page' `
                  ($_.Exception.Response.StatusCode.value__ -in 302, 403) $_.Exception.Message
        }
    }

    # -----------------------------------------------------------------------
    Section '12. Filtering and paging'
    # Assertions below look for record names rather than badge text. The action
    # dropdown on the page lists every action, so matching on 'Purge' or 'Create'
    # would pass whether or not the filter worked.
    $all = Get-Safe $a.Ctx 'admin/audit_log.php'
    Check 'the page shows entries for this workspace' ($all -match 'Audit Admin A')

    $onlyUpdates = Get-Safe $a.Ctx 'admin/audit_log.php?action=update'
    Check 'an update row shows the value it changed from' ($onlyUpdates -match 'text-decoration-line-through')
    Check 'and the value it changed to' ($onlyUpdates -match '555-0100')

    $onlyPurge = Get-Safe $a.Ctx 'admin/audit_log.php?action=purge'
    Check 'filtering by action keeps the matching rows' ($onlyPurge -match 'Audit Co')
    Check 'filtering by action drops the others' (($onlyPurge -notmatch 'Robin Vale') -and ($onlyPurge -notmatch 'Call Robin'))

    $onlyType = Get-Safe $a.Ctx 'admin/audit_log.php?entity_type=lead'
    Check 'filtering by record type keeps the matching rows' ($onlyType -match 'Robin Vale')
    Check 'filtering by record type drops the others' ($onlyType -notmatch 'Audit Co')

    $today = Get-Date -Format 'yyyy-MM-dd'
    $onlyToday = Get-Safe $a.Ctx "admin/audit_log.php?date_from=$today"
    Check 'a date filter starting today still shows today activity' ($onlyToday -match 'Audit Admin A')
    $future = (Get-Date).AddDays(2).ToString('yyyy-MM-dd')
    $onlyFuture = Get-Safe $a.Ctx "admin/audit_log.php?date_from=$future"
    Check 'a date range in the future matches nothing' ($onlyFuture -match 'No matching entries')

    # Both of these come straight from the URL, so neither may error the page or
    # change the tenant scoping.
    $bogus = Get-Safe $a.Ctx 'admin/audit_log.php?action=%27+OR+1%3D1--'
    Check 'a malformed action filter does not break the page' ($bogus -match 'Audit log')
    Check 'and it matches everything rather than everything at once' ($bogus -notmatch 'No matching entries')
    $bogusType = Get-Safe $a.Ctx 'admin/audit_log.php?entity_type=not_a_type'
    Check 'a malformed type filter does not break the page' ($bogusType -match 'Audit log')

    # -----------------------------------------------------------------------
    Section '13. A deleted user keeps their history'
    $staffId = [int](Sql "SELECT id FROM $DbName.users WHERE email='$staffMail';")
    # Give the staff account some history of its own before removing it.
    $null = Post-Form $staff.Ctx 'clients/form.php' @{ company_name = 'Staff Made Co'; status = 'prospect' }
    $staffEntry = [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log WHERE tenant_id=$tenantA AND user_id=$staffId;")
    Check 'the staff account has its own entries' ($staffEntry -ge 1) "rows=$staffEntry"

    $null = Post-Form $a.Ctx 'admin/user_action.php' @{ action = 'delete'; id = $staffId }
    Check 'the user is gone' ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE id=$staffId;") -eq 0)
    # users.audit_log.user_id is ON DELETE SET NULL, so the row is kept and merely
    # orphaned. user_name survives on it, which is the point: a departed
    # colleague's actions still have to be attributable.
    $kept = [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log
                      WHERE tenant_id=$tenantA AND user_id IS NULL AND user_name='Audit Staff';")
    Check 'the deleted user keeps their log rows' ($kept -ge 1) "rows=$kept"
    Check 'and the rows still name them' ((Sql "SELECT user_name FROM $DbName.audit_log WHERE tenant_id=$tenantA AND user_name='Audit Staff' ORDER BY id DESC LIMIT 1;") -eq 'Audit Staff')

    # -----------------------------------------------------------------------
    Section '14. The log is append-only and never trimmed'
    $appPhp = @(Get-ChildItem -Path (Join-Path $RepoRoot 'app') -Recurse -Filter *.php |
                ForEach-Object { $_.FullName })
    $deleteHits = @($appPhp | Where-Object {
        (Select-String -LiteralPath $_ -Pattern 'DELETE\s+FROM\s+audit_log' -Quiet)
    })
    Check 'no code path deletes audit rows' ($deleteHits.Count -eq 0) ($deleteHits -join ', ')

    $updateHits = @($appPhp | Where-Object {
        (Select-String -LiteralPath $_ -Pattern 'UPDATE\s+audit_log' -Quiet)
    })
    Check 'no code path updates audit rows' ($updateHits.Count -eq 0) ($updateHits -join ', ')

    $truncateHits = @($appPhp | Where-Object {
        (Select-String -LiteralPath $_ -Pattern 'TRUNCATE\s+(TABLE\s+)?audit_log' -Quiet)
    })
    Check 'no code path truncates the log' ($truncateHits.Count -eq 0) ($truncateHits -join ', ')

    # No retention window and no scheduled prune. A log that silently ages out
    # stops being evidence, which is the failure nobody notices until it matters.
    # Scoped to app/ and to the operational scripts: the other test suites delete
    # audit_log deliberately, to remove their own fixtures, and that is not a
    # prune of anybody's real history.
    # Statements only, with comments and doc blocks stripped first. A bare TRUNCATE
    # search matched the word "truncated" in prose about dump files.
$appPhp = @(Get-ChildItem -Path (Join-Path $RepoRoot 'app') -Recurse -Filter *.php |
            ForEach-Object {
                $code = Get-Content -LiteralPath $_.FullName |
                        Where-Object { $_ -notmatch '^\s*(//|\*|/\*|#)' }
                if (($code -join "`n") -match 'DELETE\s+FROM\s+\$?[\w.]*audit_log|TRUNCATE(\s+TABLE)?\s+\$?[\w.]*audit_log') {
                    $_.FullName
                }
            })
    Check 'no code path deletes or truncates the log' ($appPhp.Count -eq 0) ($appPhp -join ', ')

    # The operational scripts, checked the same way.
    $opHits = @()
    foreach ($op in @('backup.ps1', 'restore.ps1', 'regression.ps1', 'check_tenancy.ps1')) {
        $p2 = Join-Path $RepoRoot "tools\$op"
        if (-not (Test-Path $p2)) { continue }
        $code = Get-Content -LiteralPath $p2 | Where-Object { $_ -notmatch '^\s*(#|<#)' }
        if (($code -join "`n") -match 'DELETE\s+FROM\s+\S*audit_log|TRUNCATE(\s+TABLE)?\s+\S*audit_log') {
            $opHits += $op
        }
    }
    Check 'no operational script prunes the log' ($opHits.Count -eq 0) ($opHits -join ', ')

    # -----------------------------------------------------------------------
    Section '15. No page errors'
    Check 'no page raised an error' ($script:pageErrors.Count -eq 0) ($script:pageErrors | Out-String)
}
finally {
    Remove-Fixtures

    # -----------------------------------------------------------------------
    Section 'Cleanup'
    $demoAfter = [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log a
                            JOIN $DbName.tenants t ON t.id=a.tenant_id
                           WHERE t.slug='clientflow-demo';")
    Check 'test workspaces removed'  ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants WHERE slug LIKE 'aud-%';") -eq 0)
    Check 'no test users left'       ([int](Sql "SELECT COUNT(*) FROM $DbName.users WHERE email LIKE '%@audit.test';") -eq 0)
    Check 'no test clients left'     ([int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE company_name IN ('Audit Co','Imported Co','Second Co','Staff Made Co','History Co','Untouched Co');") -eq 0)
    Check 'no test leads left'       ([int](Sql "SELECT COUNT(*) FROM $DbName.leads WHERE lead_name='Robin Vale';") -eq 0)
    Check 'no test tasks left'       ([int](Sql "SELECT COUNT(*) FROM $DbName.tasks WHERE title='Call Robin';") -eq 0)
    Check 'no orphaned audit rows'   ([int](Sql "SELECT COUNT(*) FROM $DbName.audit_log a LEFT JOIN $DbName.tenants t ON t.id=a.tenant_id WHERE t.id IS NULL;") -eq 0)
    Check 'the demo workspace log is left alone' ($demoAfter -ge $demoBefore) "before=$demoBefore after=$demoAfter"
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'

if ($script:fail -gt 0) { exit 1 }
exit 0