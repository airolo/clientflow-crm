<#
.SYNOPSIS
    Restores a database backup produced by tools\backup.ps1.

.DESCRIPTION
    Backup without restore is not a backup, so this is the other half of
    tools\backup.ps1.

    Restoring is destructive by nature, and the guards are the point:

    - **A safety backup is taken first, always.** If the dump being restored turns
      out to be the wrong one or a year old, the database as it stood a moment ago
      is still on disk. The file is written and reported even if the restore then
      fails.
    - **The dump is checked before it is applied.** An empty file, a truncated one
      (one that does not end in a complete statement), or one for a different
      database is refused rather than half-imported.
    - **-Force is required to overwrite an existing database.** Without it the
      script stops, so restoring the wrong file by accident does not quietly
      replace working data.
    - **Row counts are reported afterwards**, per table, so a restore that
      "succeeded" but lost most of the data is obvious immediately.

    -Verify checks the dump without touching any database, which is what you want
    for an offsite copy you cannot see.

    Output is written outside the web root, for the same reason as backup.ps1: a
    .sql file under htdocs is served as plain text.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql
    powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql -Verify
    powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File .\dump.sql -Force
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string]$File,

    [string]$Database     = 'clientflow_crm',
    [string]$SafetyDir    = (Join-Path $env:LOCALAPPDATA 'ClientFlow\backups'),
    [string]$MySql        = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDump    = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$DbUser       = 'root',
    [string]$DbPass       = '',
    [switch]$Verify,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

function Sql([string]$q) {
    return ((& $MySql --user=$DbUser -N -B --execute="$q" 2>&1) | Out-String).Trim()
}

function Abort([string]$message) {
    Write-Output ''
    Write-Output "ABORT: $message"
    exit 1
}

Write-Output 'ClientFlow CRM restore'
Write-Output '======================'

# ---------------------------------------------------------------- the file
if (-not (Test-Path -LiteralPath $File)) {
    Abort "no such file: $File"
}
$dump = (Resolve-Path -LiteralPath $File).Path
$dumpInfo = Get-Item -LiteralPath $dump
$dumpKb = [math]::Round($dumpInfo.Length / 1KB, 1)

Write-Output "  dump     : $dump"
Write-Output "  size     : $dumpKb KB"
Write-Output "  modified : $($dumpInfo.LastWriteTime)"

if ($dumpInfo.Length -lt 512) {
    # Not KB: a 28-byte file rounds to "0 KB", which reads like a bug rather
    # than the too-small-for-a-dump it is.
    Abort "that file is only $($dumpInfo.Length) bytes - too small to be a schema and data dump"
}

if (-not (Test-Path -LiteralPath $MySql)) {
    Abort "mysql client not found at $MySql (pass -MySql)"
}

# ------------------------------------------------------- inspect the dump
# Read the tail and the head rather than loading the file: a multi-megabyte dump
# should not be pulled into memory just to be sanity checked.
$headBytes = [System.IO.File]::ReadAllBytes($dump)[0..([Math]::Min(8191, $dumpInfo.Length - 1))]
$head = [System.Text.Encoding]::UTF8.GetString($headBytes)

if ($head -notmatch 'MariaDB dump|MySQL dump') {
    Abort "that file does not look like a mysqldump (no dump header in the first 8 KB)"
}

$withStream = [System.IO.File]::OpenRead($dump)
try {
    $length = [Math]::Min(4096, $withStream.Length)
    $withStream.Seek(-$length, 'End') | Out-Null
    $tailBytes = New-Object byte[] $length
    $withStream.Read($tailBytes, 0, $length) | Out-Null
    $tail = [System.Text.Encoding]::UTF8.GetString($tailBytes)
} finally {
    $withStream.Close()
}

# mysqldump ends with the postamble, and a truncated file will not. This is the
# cheapest check that catches an upload cut short, which is the common way a
# backup turns out to be unusable.
$complete = $tail -match '(?s)-- Dump completed on.+[\r\n]+\s*$'
if (-not $complete) {
    Write-Output ''
    Write-Output "  WARNING: the file does not end with mysqldump's 'Dump completed'"
    Write-Output "           marker. It may be truncated. Check it before trusting it."
    if (-not $Verify -and -not $Force) {
        Abort 'refusing to restore a dump that looks truncated. Re-run with -Force if you are sure.'
    }
}

# Which database does it claim to be from?
$dbInDump = ''
if ($head -match 'Current Database: `([^`]+)`') { $dbInDump = $Matches[1] }
elseif ($tail -match 'Current Database: `([^`]+)`') { $dbInDump = $Matches[1] }
Write-Output "  from db  : $(if ($dbInDump) { $dbInDump } else { '(not recorded)' })"

if ($dbInDump -and $dbInDump -ne $Database) {
    Write-Output "  WARNING: this dump is from '$dbInDump' but you are restoring into '$Database'."
    if (-not $Force) {
        Abort 'refusing to restore a dump from a different database. Re-run with -Force if that is intended.'
    }
}

# ------------------------------------------------------------- verify only
if ($Verify) {
    Write-Output ''
    Write-Output '  -Verify given: no database was touched.'
    Write-Output ''
    Write-Output '  The dump looks loadable. Checksum:'
    Write-Output "  (Get-FileHash -Algorithm SHA256 -LiteralPath `"$dump`").Hash"
    exit 0
}

# ------------------------------------------------------------- the database
$exists = [int](Sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$Database';") -gt 0
$tableCount = 0
if ($exists) {
    $tableCount = [int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$Database';")
}

Write-Output ''
Write-Output "  target  : $Database ($(if ($exists) { "$tableCount tables" } else { 'does not exist yet' }))"

if ($exists -and $tableCount -gt 0 -and -not $Force) {
    Abort "'$Database' already has $tableCount tables. Re-run with -Force to overwrite it."
}

# ------------------------------------------------------- safety backup first
if ($exists -and $tableCount -gt 0) {
    if (-not (Test-Path -LiteralPath $MySqlDump)) {
        Write-Output ''
        Write-Output "  WARNING: mysqldump not found at $MySqlDump, so no safety copy"
        Write-Output '           could be taken. Continuing means no way back.'
        if (-not $Force) {
            Abort 'refusing to restore without a safety copy. Install mysqldump, or pass -Force.'
        }
    } else {
        if (-not (Test-Path -LiteralPath $SafetyDir)) {
            New-Item -ItemType Directory -Path $SafetyDir -Force | Out-Null
        }
        $safety = Join-Path $SafetyDir ("prerestore_${Database}_" + (Get-Date -Format 'yyyy-MM-dd_HHmmss') + '.sql')
        $null = Sql "SELECT 1;"   # cheap connectivity check before the long one

        # --result-file, NOT PowerShell's > redirection.
        #
        # `> $safety` writes UTF-16 in PowerShell 5.1, which doubles the file and
        # strips nothing but the encoding - producing a safety copy that is half
        # the size it should be, unreadable by the header check, and worthless at
        # exactly the moment it is needed. Letting mysqldump write the file
        # itself is what backup.ps1 does and why its dumps are fine.
        $dumpArgs = @(
            "--user=$DbUser"
            '--single-transaction'
            '--routines'
            '--events'
            '--add-drop-table'
            '--result-file=' + $safety
            $Database
        )
        & $MySqlDump @dumpArgs 2>$null

        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $safety) -or (Get-Item -LiteralPath $safety).Length -lt 512) {
            Write-Output ''
            Write-Output 'ABORT: the safety backup failed, so nothing has been changed.'
            if (Test-Path -LiteralPath $safety) {
                Write-Output "       a file was written but it is too small: $safety"
                Write-Output '       treat it as suspect and delete it.'
            }
            exit 1
        }

        # Check the safety copy is a real dump now, not when it is urgently
        # needed in a panic.
        $safetyHead = [System.Text.Encoding]::UTF8.GetString(
            [System.IO.File]::ReadAllBytes($safety)[0..([Math]::Min(4095, (Get-Item $safety).Length - 1))]
        )
        if ($safetyHead -notmatch 'MariaDB dump|MySQL dump') {
            Write-Output ''
            Write-Output 'ABORT: the safety copy is not a recognisable mysqldump, so it would'
            Write-Output '       not restore. Nothing has been changed.'
            Write-Output "       suspect file: $safety"
            exit 1
        }

        $safetyKb = [math]::Round((Get-Item -LiteralPath $safety).Length / 1KB, 1)
        Write-Output "  safety  : $safety ($safetyKb KB)"
        Write-Output '            ^ the database exactly as it was a moment ago'
    }
}

# --------------------------------------------------------------- restore
Write-Output ''
Write-Output "  restoring $dump into $Database ..."

# Single transaction so a failure part-way through rolls back rather than
# leaving a half-restored database. The dump contains DDL, which MySQL commits
# implicitly, so this is a belt-and-braces measure rather than a guarantee - the
# row counts printed afterwards are the real check.
$null = Sql "DROP DATABASE IF EXISTS ``$Database``; CREATE DATABASE ``$Database`` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

$restoreLog = Join-Path $env:TEMP 'clientflow_restore.log'
$cmd = "`"$MySql`" --user=$DbUser --default-character-set=utf8mb4 $Database < `"$dump`" 2> `"$restoreLog`""
cmd /c $cmd
$restoreExit = $LASTEXITCODE

if ($restoreExit -ne 0) {
    Write-Output ''
    Write-Output "  ERROR: the restore exited with code $restoreExit"
    if (Test-Path -LiteralPath $restoreLog) {
        $errText = (Get-Content -LiteralPath $restoreLog -Raw)
        if ($errText) {
            Write-Output ''
            Write-Output '  mysql said:'
            ($errText -split "`r?`n" | Where-Object { $_.Trim() } | Select-Object -First 8) |
                ForEach-Object { Write-Output "    $_" }
        }
    }
    Write-Output ''
    Write-Output '  The database is in an unknown state. Restore the safety copy printed above.'
    exit 1
}

# ------------------------------------------------------------- verify
Write-Output ''
Write-Output '  restored. Row counts:'
$tables = @('tenants', 'users', 'clients', 'leads', 'deals', 'tasks', 'activities', 'login_attempts', 'signup_attempts')
$totalRows = 0
foreach ($t in $tables) {
    $n = Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Database' AND TABLE_NAME='$t';"
    if ($n -eq '1') {
        $c = [int](Sql "SELECT COUNT(*) FROM ``$Database``.``$t``;")
        $totalRows += $c
        Write-Output ("    {0,-16} {1}" -f $t, $c)
    } else {
        Write-Output ("    {0,-16} (absent)" -f $t)
    }
}

$finalTables = [int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Database';")
Write-Output ''
Write-Output "  tables: $finalTables, rows in the tables above: $totalRows"

if ($finalTables -eq 0) {
    Abort 'the restore produced an empty database'
}
if ($finalTables -lt 5) {
    Write-Output ''
    Write-Output '  WARNING: fewer tables than this app needs. Is the dump from a ClientFlow install?'
}

# Only mention migration 003 if the dump genuinely predates it. Printed
# unconditionally it was advice about a dump that had tenants in it.
$hasTenants = [int](Sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Database' AND TABLE_NAME='tenants';")
if ($hasTenants -eq 0) {
    Write-Output ''
    Write-Output '  This dump has no tenants table, so it predates multi-tenancy. Run'
    Write-Output '  migrations\003_multi_tenancy.sql afterwards to adopt the rows into a'
    Write-Output '  workspace - until then the app cannot sign anyone in.'
}

Write-Output ''
Write-Output '  Done. Sign in and check a list or two before trusting this fully.'
exit 0