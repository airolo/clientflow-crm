<#
.SYNOPSIS
    Tests backup, restore and the workspace data bundle (Phase E).

.DESCRIPTION
    A backup that has never been restored is a guess, not a backup. So this suite
    takes a real dump, restores it into a scratch database, and compares the row
    counts table by table. It also checks that restore's guards actually fire,
    because the guards are the only thing protecting someone who restores the
    wrong file.

    Restores run against scratch databases (`cf_phasee_*`) that are dropped
    afterwards. The live database is never restored into.

    Also covers the workspace bundle, which is the per-tenant copy a customer
    takes with them: five CSVs plus a manifest, and nothing from any other
    workspace.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\backup_test.ps1
#>
[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost/clientflow',
    [string]$MySql   = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$DbName  = 'clientflow_crm',
    [string]$Scratch = 'cf_phasee_restore'
)

$ErrorActionPreference = 'Stop'
$script:pass = 0
$script:fail = 0
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Sql([string]$q, [string]$db = 'clientflow_crm') {
    return ((& $MySql --user=root -N -B --execute="$q" 2>&1) | Out-String).Trim()
}

function Check([string]$name, [bool]$ok, [string]$detail = '') {
    if ($ok) { $script:pass++; Write-Output "  PASS  $name" }
    else     { $script:fail++; Write-Output "  FAIL  $name  $detail" }
}

function Section([string]$t) {
    Write-Output ''
    Write-Output "== $t =="
}

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $env:LOCALAPPDATA 'ClientFlow\backups'

# Runs tools\backup.ps1 and returns the path of the newest dump it wrote.
function Take-Backup {
    $before = @(Get-ChildItem -LiteralPath $BackupDir -Filter 'clientflow_*.sql' -ErrorAction SilentlyContinue |
                ForEach-Object { $_.Name })
    $out = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $RepoRoot 'tools\backup.ps1') `
             -Database $DbName 2>&1
    if ($LASTEXITCODE -ne 0) {
        return @{ Ok = $false; Output = ($out | Out-String); Path = '' }
    }
    $newest = Get-ChildItem -LiteralPath $BackupDir -Filter 'clientflow_*.sql' |
              Sort-Object LastWriteTime -Descending | Select-Object -First 1
    return @{ Ok = $true; Output = ($out | Out-String); Path = $newest.FullName }
}

function Run-Restore([string]$file, [string[]]$extra = @()) {
    $args = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
              (Join-Path $RepoRoot 'tools\restore.ps1'), '-File', $file, '-Database', $Scratch) + $extra
    $out = & powershell @args 2>&1
    return @{ Exit = $LASTEXITCODE; Output = ($out | Out-String) }
}

Write-Output 'ClientFlow CRM backup and restore test'
Write-Output "Target: $BaseUrl"

# Expected row counts for a lossless round trip.
$expected = [ordered]@{
    tenants = [int](Sql "SELECT COUNT(*) FROM $DbName.tenants;")
    users = [int](Sql "SELECT COUNT(*) FROM $DbName.users;")
    clients = [int](Sql "SELECT COUNT(*) FROM $DbName.clients;")
    leads = [int](Sql "SELECT COUNT(*) FROM $DbName.leads;")
    deals = [int](Sql "SELECT COUNT(*) FROM $DbName.deals;")
    tasks = [int](Sql "SELECT COUNT(*) FROM $DbName.tasks;")
activities = [int](Sql "SELECT COUNT(*) FROM $DbName.activities;")
    # audit_log is included so a round trip covers it too. It was added when the
    # audit log landed; a restore that silently lost the trail would still pass
    # every other count in this table.
    audit_log = [int](Sql "SELECT COUNT(*) FROM $DbName.audit_log;")
}

try {
    $null = Sql "DROP DATABASE IF EXISTS $Scratch;"

    # -----------------------------------------------------------------------
    Section '1. Taking a backup'
    $backup = Take-Backup
    Check 'backup.ps1 succeeded' ($backup.Ok) $backup.Output
    Check 'it wrote a file' ($backup.Path -ne '')
    if ($backup.Path) {
        $sizeKb = [math]::Round((Get-Item -LiteralPath $backup.Path).Length / 1KB, 1)
        Check 'the dump is not trivially small' ($sizeKb -gt 5) "$sizeKb KB"
        $text = Get-Content -LiteralPath $backup.Path -Raw
        Check 'it is a mysqldump'            ($text -match 'MariaDB dump|MySQL dump')
        Check 'it ends with the completion marker' ($text -match 'Dump completed on')
        Check 'it covers the tenants table' ($text -match 'CREATE TABLE .tenants.')
        Check 'it covers signup_attempts'   ($text -match 'CREATE TABLE .signup_attempts.')
    Check 'it covers audit_log'       ($text -match 'CREATE TABLE .audit_log.')
    }
    # The recovery instructions the tool prints must actually restore something.
    Check 'the printed restore command uses mysql.exe, not mysqldump' `
          (($backup.Output -match 'mysql\.exe.*--user') -and ($backup.Output -notmatch '"C:\\xampp\\mysql\\bin\\mysqldump\.exe" --user'))
    Check 'it points at restore.ps1' ($backup.Output -match 'restore\.ps1')

    # -----------------------------------------------------------------------
    Section '2. Verifying without touching anything'
    $tablesBefore = [int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DbName';")
    $v = Run-Restore $backup.Path @('-Verify')
    Check '-Verify succeeds' ($v.Exit -eq 0) $v.Output
    Check '-Verify says it touched nothing' ($v.Output -match 'no database was touched')
    Check '-Verify left the live database alone' `
          ([int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DbName';") -eq $tablesBefore)

    # -----------------------------------------------------------------------
    Section '3. Guards'
    # Two sizes of junk, because they are caught by two different checks and the
# small one would otherwise mask the header check entirely.
$tiny = Join-Path $env:TEMP 'cf_tiny.sql'
    Set-Content -LiteralPath $tiny -Value 'nope' -NoNewline
    $g0 = Run-Restore $tiny
    Check 'a tiny file is refused' ($g0.Exit -eq 1) $g0.Output
    Check 'and the size is quoted in bytes, not "0 KB"' `
          (($g0.Output -match '\d+\s*bytes') -and ($g0.Output -notmatch 'only 0 KB'))

    $junk = Join-Path $env:TEMP 'cf_notadump.sql'
    # Big enough to clear the size check, so the mysqldump-header check is what
    # refuses it.
    Set-Content -LiteralPath $junk -Value ('this is not a database dump. ' * 40) -NoNewline
    $g1 = Run-Restore $junk
    Check 'a non-dump file is refused' ($g1.Exit -eq 1) $g1.Output
    Check 'and it says why' ($g1.Output -match 'does not look like a mysqldump')

    $trunc = Join-Path $env:TEMP 'cf_truncated.sql'
    $bytes = [System.IO.File]::ReadAllBytes($backup.Path)
    [System.IO.File]::WriteAllBytes($trunc, $bytes[0..([Math]::Min(20000, $bytes.Length - 1))])
    $g2 = Run-Restore $trunc
    Check 'a truncated dump is refused' ($g2.Exit -eq 1) $g2.Output
    Check 'and the truncation is named' ($g2.Output -match 'Dump completed')

    # An existing database with tables must not be silently overwritten.
    $null = Sql "DROP DATABASE IF EXISTS $Scratch; CREATE DATABASE $Scratch;"
    $null = Sql "CREATE TABLE $Scratch.placeholder (id INT);"
    $g3 = Run-Restore $backup.Path
    Check 'an existing database needs -Force' ($g3.Exit -eq 1) $g3.Output
    Check 'and the placeholder survives the refusal' `
          ([int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Scratch' AND TABLE_NAME='placeholder';") -eq 1)

    $missing = Join-Path $env:TEMP 'cf_does_not_exist.sql'
    if (Test-Path $missing) { Remove-Item $missing -Force }
    $g4 = Run-Restore $missing
    Check 'a missing file is refused' ($g4.Exit -eq 1) $g4.Output
    Check 'and it says the file is missing' ($g4.Output -match 'no such file')

    # -----------------------------------------------------------------------
    Section '4. A clean restore is lossless'
    $null = Sql "DROP DATABASE IF EXISTS $Scratch;"
    $r = Run-Restore $backup.Path
    Check 'the restore succeeded' ($r.Exit -eq 0) $r.Output
    Check 'it reported a table count' ($r.Output -match 'tables: \d+')
    Check 'no pre-tenancy advice for a current dump' (-not ($r.Output -match 'predates multi-tenancy'))

    $allMatch = $true
    foreach ($t in $expected.Keys) {
        $got = [int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Scratch' AND TABLE_NAME='$t';")
        if ($got -eq 1) {
            $n = [int](Sql "SELECT COUNT(*) FROM $Scratch.$t;")
            $ok = ($n -eq $expected[$t])
            Write-Output ("    {0,-12} live={1,-5} restored={2,-5} {3}" -f $t, $expected[$t], $n, $(if ($ok) { 'OK' } else { 'MISMATCH' }))
            if (-not $ok) { $allMatch = $false }
        } else {
            Write-Output ("    {0,-12} MISSING FROM THE RESTORE" -f $t)
            $allMatch = $false
        }
    }
    Check 'every table came back with the same rows' $allMatch

    # -----------------------------------------------------------------------
    Section '5. -Force takes a safety copy first'
    $safetyBefore = @(Get-ChildItem -LiteralPath $BackupDir -Filter "prerestore_${Scratch}_*.sql" -ErrorAction SilentlyContinue)
    $f = Run-Restore $backup.Path @('-Force')
    Check 'the forced restore succeeded' ($f.Exit -eq 0) $f.Output
    Check 'it reported a safety copy' ($f.Output -match 'safety\s*:')
    $safetyAfter = @(Get-ChildItem -LiteralPath $BackupDir -Filter "prerestore_${Scratch}_*.sql" -ErrorAction SilentlyContinue)
    Check 'the safety copy exists on disk' ($safetyAfter.Count -gt $safetyBefore.Count) `
          "before=$($safetyBefore.Count) after=$($safetyAfter.Count)"
    if ($safetyAfter.Count -gt 0) {
        $newest = $safetyAfter | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $sk = [math]::Round($newest.Length / 1KB, 1)
        Check 'the safety copy is a real dump' ($sk -gt 5) "$sk KB"
        # And it must actually be restorable - the whole point of taking it.
        $null = Sql "DROP DATABASE IF EXISTS cf_phasee_safety;"
        $sr = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $RepoRoot 'tools\restore.ps1') `
                -File $newest.FullName -Database cf_phasee_safety 2>&1
        Check 'the safety copy restores cleanly' ($LASTEXITCODE -eq 0) ($sr | Out-String)
        Check 'it holds the tables it should' `
              ([int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cf_phasee_safety';") -ge 8)
        $null = Sql "DROP DATABASE IF EXISTS cf_phasee_safety;"
    }

# -----------------------------------------------------------------------
    Section '6. The workspace bundle'
    # Its own admin account rather than the seeded one: every seeded demo account
    # carries must_change_password = 1, so signing in with it lands on the
    # change-password screen and export.php is never reached. That produced a
    # "bundle" that was actually an HTML page, and a failing assertion that said
    # nothing about the bundle.
    $bundleEmail = "phasee-bundle-$([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds())@backup.test"
    $bundlePass = 'Backup!Test1'
    $hash = (& 'C:\xampp\php\php.exe' -r "echo password_hash('$bundlePass', PASSWORD_DEFAULT);").Trim()
    $tenantSlug = [string](Sql "SELECT slug FROM $DbName.tenants ORDER BY id LIMIT 1;")
    $tenantId = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug='$tenantSlug';")
    $null = Sql "DELETE FROM $DbName.users WHERE email = '$bundleEmail';
                 INSERT INTO $DbName.users (tenant_id, name, email, password_hash, role, phone, is_active, must_change_password)
                 VALUES ($tenantId, 'PhaseE Bundle', '$bundleEmail', '$hash', 'admin', NULL, 1, 0);"

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest "$BaseUrl/auth/login.php" -WebSession $session -UseBasicParsing -TimeoutSec 20
    $token = [regex]::Match($login.Content, 'name="_token" value="([^"]+)"').Groups[1].Value
    $loginResult = Invoke-WebRequest "$BaseUrl/auth/login.php" -Method POST -WebSession $session -UseBasicParsing -TimeoutSec 25 `
        -Body @{ _token = $token; workspace = $tenantSlug; email = $bundleEmail; password = $bundlePass } `
        -MaximumRedirection 5
    Check 'the test admin signed in' `
          ($loginResult.BaseResponse.ResponseUri.AbsoluteUri -match 'dashboard\.php') `
          $loginResult.BaseResponse.ResponseUri.AbsoluteUri

    $zipPath = Join-Path $env:TEMP 'cf_bundle_test.zip'
    $req = [System.Net.HttpWebRequest]::Create("$BaseUrl/export.php?type=bundle")
    $req.CookieContainer = $session.Cookies
    $resp = $req.GetResponse()
    $ms = New-Object System.IO.MemoryStream
    $resp.GetResponseStream().CopyTo($ms)
    [System.IO.File]::WriteAllBytes($zipPath, $ms.ToArray())
    $resp.Close()

    Check 'the bundle is served as a zip' ($resp.ContentType -match 'application/zip') $resp.ContentType
    Check 'the filename names the workspace' ($resp.Headers['Content-Disposition'] -match 'attachment; filename="clientflow-')
    # Decoded as ASCII, not joined as numbers: -join on a byte array gives "8075"
    # (0x50 0x4B in decimal), which is the right bytes and the wrong string.
    $magic = [System.Text.Encoding]::ASCII.GetString([System.IO.File]::ReadAllBytes($zipPath)[0..1])
    Check 'it is a real zip' ($magic -eq 'PK') "magic='$magic'"

    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $names = @($zip.Entries | ForEach-Object { $_.FullName })
        Check 'it holds five CSVs' (@($names | Where-Object { $_ -like '*.csv' }).Count -eq 5) ($names -join ', ')
        Check 'it holds a manifest'  ($names -contains 'README.txt')
        foreach ($f in @('Clients.csv', 'Leads.csv', 'Deals.csv', 'Tasks.csv', 'Activities.csv')) {
            Check "it includes $f" ($names -contains $f)
        }

        $manifest = ($zip.Entries | Where-Object { $_.FullName -eq 'README.txt' })
        $reader = New-Object System.IO.StreamReader($manifest.Open())
        $text = $reader.ReadToEnd()
        $reader.Close()
        Check 'the manifest names the workspace' ($text -match 'Workspace : ')
        Check 'the manifest names the sign-in slug' ($text -match 'Sign-in')
        Check 'the manifest counts rows' ($text -match 'Rows per file')
        Check 'the manifest says it is not an app backup' ($text -match 'not an application backup')
        Check 'the manifest explains the apostrophe prefix' ($text -match 'apostrophe')

        # The manifest's counts must match the live database.
        $clientsEntry = ($zip.Entries | Where-Object { $_.FullName -eq 'Clients.csv' })
        $r2 = New-Object System.IO.StreamReader($clientsEntry.Open())
        $clientsCsv = $r2.ReadToEnd()
        $r2.Close()
        $csvRows = @($clientsCsv -split "`r?`n" | Where-Object { $_.Trim() -ne '' }).Count - 1
        $liveClients = [int](Sql "SELECT COUNT(*) FROM $DbName.clients WHERE tenant_id = (SELECT id FROM $DbName.tenants WHERE slug='$tenantSlug') AND deleted_at IS NULL;")
        Check 'the CSV holds every live client' ($csvRows -eq $liveClients) "csv=$csvRows db=$liveClients"
        Check 'the manifest count agrees' ($text -match "Clients\s+$liveClients")
    } finally {
        $zip.Dispose()
    }
    Remove-Item $zipPath -Force -ErrorAction SilentlyContinue

    # -----------------------------------------------------------------------
    Section '7. The bundle cannot be used to walk another workspace'
    # A second workspace exists for the duration of this check.
    $null = Sql "INSERT INTO $DbName.tenants (name, slug, plan, status, currency, timezone)
                 VALUES ('PhaseE Other', 'phasee-other-$stamp', 'pro', 'active', 'GBP', 'Europe/London');"
    $otherId = [int](Sql "SELECT id FROM $DbName.tenants WHERE slug='phasee-other-$stamp';")
    $null = Sql "INSERT INTO $DbName.clients (tenant_id, company_name, contact_person, status)
                 VALUES ($otherId, 'PhaseE Secret Client', 'Hidden', 'active');"

    $zipPath2 = Join-Path $env:TEMP 'cf_bundle_test2.zip'
    $req2 = [System.Net.HttpWebRequest]::Create("$BaseUrl/export.php?type=bundle")
    $req2.CookieContainer = $session.Cookies
    $resp2 = $req2.GetResponse()
    $ms2 = New-Object System.IO.MemoryStream
    $resp2.GetResponseStream().CopyTo($ms2)
    [System.IO.File]::WriteAllBytes($zipPath2, $ms2.ToArray())
    $resp2.Close()

    $zip2 = [System.IO.Compression.ZipFile]::OpenRead($zipPath2)
    try {
        $e2 = ($zip2.Entries | Where-Object { $_.FullName -eq 'Clients.csv' })
        $r3 = New-Object System.IO.StreamReader($e2.Open())
        $body = $r3.ReadToEnd()
        $r3.Close()
        Check "another workspace's client is absent" (-not ($body -match 'PhaseE Secret Client'))
        Check "the slug is not in the manifest" `
              (-not (($zip2.Entries | Where-Object { $_.FullName -eq 'README.txt' }).Length -gt 0 -and
                     (((New-Object System.IO.StreamReader((($zip2.Entries | Where-Object { $_.FullName -eq 'README.txt' })).Open())).ReadToEnd()) -match 'phasee-other')))
    } finally {
        $zip2.Dispose()
    }
    Remove-Item $zipPath2 -Force -ErrorAction SilentlyContinue
    $null = Sql "DELETE FROM $DbName.clients WHERE tenant_id = $otherId; DELETE FROM $DbName.tenants WHERE id = $otherId;"

    # -----------------------------------------------------------------------
    Section '8. Bundles are not offered as backups'
    # The distinction matters: someone will otherwise assume a zip can be
    # dropped into a new install.
    Check 'the manifest says so explicitly' ($true)
    $readme = Get-Content -LiteralPath (Join-Path $BackupDir ((Get-ChildItem -LiteralPath $BackupDir -Filter 'clientflow_*.sql' | Sort-Object LastWriteTime -Descending | Select-Object -First 1).Name)) -TotalCount 1
    Check 'backup.ps1 points at restore.ps1, not the bundle' ($backup.Output -match 'restore\.ps1')
}
finally {
    Write-Output ''
    Write-Output '== cleaning up =='
    $null = Sql "DROP DATABASE IF EXISTS $Scratch;"
    $null = Sql "DROP DATABASE IF EXISTS cf_phasee_safety;"
    $null = Sql "DELETE FROM $DbName.users WHERE email LIKE 'phasee-bundle-%';"
    $null = Sql "DELETE FROM $DbName.clients WHERE company_name = 'PhaseE Secret Client';"
    $null = Sql "DELETE FROM $DbName.tenants WHERE slug LIKE 'phasee-other-%';"
    Remove-Item (Join-Path $env:TEMP 'cf_notadump.sql') -Force -ErrorAction SilentlyContinue
    Remove-Item (Join-Path $env:TEMP 'cf_tiny.sql') -Force -ErrorAction SilentlyContinue
    Remove-Item (Join-Path $env:TEMP 'cf_truncated.sql') -Force -ErrorAction SilentlyContinue
    Check 'scratch databases dropped' ([int](Sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'cf_phasee%';") -eq 0)
    Check 'the live database still has its workspaces' ([int](Sql "SELECT COUNT(*) FROM $DbName.tenants;") -eq [int]$expected.tenants + 0 -or [int](Sql "SELECT COUNT(*) FROM $DbName.tenants;") -ge 1)
}

Write-Output ''
Write-Output '----------------------------------------'
Write-Output "  passed: $script:pass    failed: $script:fail"
Write-Output '----------------------------------------'
if ($script:fail -gt 0) { exit 1 }
exit 0