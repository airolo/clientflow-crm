# Requires: powershell -ExecutionPolicy Bypass -File tools\backup.ps1
<#
    .SYNOPSIS
        Dump the ClientFlow database to a timestamped SQL file.

    .DESCRIPTION
        There is no export button in the app, and database.sql starts with DROP
        TABLE - so re-importing it wipes everything. This is the safety net:
        run it before any risky change, and before you re-import.

        Backups land OUTSIDE htdocs by default, so a .sql file can never be
        served over HTTP, and outside the repository so a backup is never
        committed.

    .PARAMETER OutputDir
        Where to write the .sql file. Default: %LOCALAPPDATA%\ClientFlow\backups

    .PARAMETER Database
        Database name. Default: clientflow_crm

    .PARAMETER Keep
        Delete backups older than this many days. Default: 30. 0 keeps all.

    .PARAMETER MySql
        Path to mysqldump.exe. Default: C:\xampp\mysql\bin\mysqldump.exe

    .EXAMPLE
        powershell -ExecutionPolicy Bypass -File tools\backup.ps1

    .EXAMPLE
        powershell -ExecutionPolicy Bypass -File tools\backup.ps1 -Keep 90
#>
[CmdletBinding()]
param(
    [string]$OutputDir = (Join-Path $env:LOCALAPPDATA 'ClientFlow\backups'),
    [string]$Database  = 'clientflow_crm',
    [int]   $Keep       = 30,
    [string]$MySql      = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$DbUser     = 'root',
    [string]$DbPass     = '',
    # The MySQL *client*, used only to print correct restore instructions.
    # Confusing the two is how the script came to tell people to run mysqldump to
    # perform a restore, which creates nothing.
    [string]$MysqlClient = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $MySql)) {
    Write-Output "ABORT: mysqldump not found at $MySql"
    Write-Output "       pass -MySql with the correct path, or add it to your PATH"
    exit 1
}

# Refuse to write anywhere under the web root or inside the repository.
#
# The project .htaccess blocks .sql files, but it only applies to files inside
# the project folder. A backup written to C:\xampp\htdocs\backups is served as
# plain text, so the guard cannot rely on Apache at all.
#
# The path is normalised BEFORE the directory is created: Test-Path is false
# for a folder that does not exist yet, and checking after creating it is how
# the first version of this script ended up writing a dump into the web root.
$htdocsRoot = [System.IO.Path]::GetFullPath('C:\xampp\htdocs')
$repoRoot   = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$target     = [System.IO.Path]::GetFullPath($OutputDir)

# Compare with a trailing separator so C:\xampp\htdocs-backup is not treated as
# being inside C:\xampp\htdocs.
foreach ($forbidden in @($htdocsRoot, $repoRoot)) {
    if ($target -eq $forbidden -or $target.StartsWith($forbidden + [System.IO.Path]::DirectorySeparatorChar)) {
        Write-Output "ABORT: refusing to write backups inside $forbidden"
        Write-Output "       a .sql file there is not covered by the project .htaccess,"
        Write-Output "       and a file in the web root can be downloaded."
        exit 1
    }
}

New-Item -ItemType Directory -Path $target -Force | Out-Null

$stamp   = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$outFile = Join-Path $OutputDir "clientflow_$Database`_$stamp.sql"

Write-Output "Backing up '$Database'"
Write-Output "  to $outFile"

# --single-transaction keeps the dump consistent without locking tables, and
# --routines/--events are harmless on a schema that has neither.
$args = @(
    "--user=$DbUser"
    '--single-transaction'
    '--routines'
    '--events'
    '--add-drop-table'
    '--result-file=' + $outFile
    $Database
)
if ($DbPass -ne '') { $args = @("--user=$DbUser", "--password=$DbPass") + $args[1..($args.Count - 1)] }

& $MySql @args
if ($LASTEXITCODE -ne 0) {
    Write-Output "ABORT: mysqldump exited with code $LASTEXITCODE"
    exit 1
}

if (-not (Test-Path -LiteralPath $outFile)) {
    Write-Output "ABORT: no output file was written"
    exit 1
}

$sizeKb = [Math]::Round((Get-Item -LiteralPath $outFile).Length / 1KB, 1)

# A dump that is suspiciously small usually means an empty or wrong database.
if ($sizeKb -lt 1) {
    Write-Output "WARNING: the dump is only $sizeKb KB - is the database actually populated?"
}

# Sanity check: a real dump always contains these.
$head = Get-Content -LiteralPath $outFile -TotalCount 200 -ErrorAction SilentlyContinue | Out-String
if ($head -notmatch 'CREATE TABLE') {
    Write-Output "WARNING: no CREATE TABLE found near the top of the dump - check it before relying on it."
}

Write-Output "  done, $sizeKb KB"

if ($Keep -gt 0) {
    $cutoff = (Get-Date).AddDays(-$Keep)
    $stale  = Get-ChildItem -LiteralPath $OutputDir -Filter "clientflow_$Database`_*.sql" |
              Where-Object { $_.LastWriteTime -lt $cutoff }
    foreach ($file in $stale) {
        Remove-Item -LiteralPath $file.FullName -Force
        Write-Output "  pruned $($file.Name)"
    }
}

Write-Output ""
Write-Output "To restore it, use the restore script, which takes a safety backup first:"
Write-Output "  powershell -ExecutionPolicy Bypass -File tools\restore.ps1 -File `"$outFile`" -Database $Database"
Write-Output ""
Write-Output "Or import it by hand through phpMyAdmin: select the database, then Import."
Write-Output "From a command prompt, note it is mysql.exe and NOT mysqldump - mysqldump"
Write-Output "produces dumps and does not read them back:"
Write-Output "  `"$MysqlClient`" --user=$DbUser $Database < `"$outFile`""
Write-Output ""
Write-Output "NOTE: database.sql drops every table on import. Import a backup, never that file,"
Write-Output "unless you genuinely intend to wipe the data."
