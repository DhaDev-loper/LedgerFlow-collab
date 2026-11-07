param(
    [string]$BackupUri
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$php = Get-Command php.exe -ErrorAction Stop
$aws = Get-Command aws.exe -ErrorAction Stop
$settingsScript = Join-Path $repositoryRoot 'backend\database\backup_settings.php'
$restoreScript = Join-Path $repositoryRoot 'backend\database\restore_test.php'
$environmentJson = & $php.Source $settingsScript
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to load backup settings from .env.'
}
$settings = $environmentJson | ConvertFrom-Json
$bucket = [string]$settings.bucket
$prefix = ([string]$settings.prefix).Trim('/')
$endpoint = [string]$settings.endpoint
$profile = [string]$settings.profile

if ([string]::IsNullOrWhiteSpace($bucket)) {
    throw 'Set BACKUP_S3_BUCKET in .env to the private S3-compatible bucket name.'
}

$awsCommonArguments = @()
if ($endpoint) {
    $awsCommonArguments += @('--endpoint-url', $endpoint)
}
if ($profile) {
    $awsCommonArguments += @('--profile', $profile)
}

if ([string]::IsNullOrWhiteSpace($BackupUri)) {
    $listArguments = @('s3api', 'list-objects-v2', '--bucket', $bucket)
    if ($prefix) {
        $listArguments += @('--prefix', "$prefix/")
    }
    $listArguments += @('--output', 'json')
    $listArguments += $awsCommonArguments
    $objectListText = & $aws.Source @listArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to list off-site backups.'
    }
    $listResponse = $objectListText | ConvertFrom-Json
    $contentsProperty = $listResponse.PSObject.Properties['Contents']
    $objects = if ($contentsProperty) { @($contentsProperty.Value) } else { @() }
    $latest = $objects |
        Where-Object { $_.Key -like '*.zip' } |
        Sort-Object LastModified -Descending |
        Select-Object -First 1
    if (-not $latest) {
        throw "No backup archives found in s3://$bucket/$prefix/"
    }
    $BackupUri = "s3://$bucket/$($latest.Key)"
}

if ($BackupUri -notmatch '^s3://([^/]+)/(.+)$') {
    throw 'BackupUri must be in the form s3://bucket/key.'
}
if ($Matches[1] -ne $bucket) {
    throw "BackupUri must identify an object in the configured bucket '$bucket'."
}
$keyName = $Matches[2]
if ($prefix -and -not $keyName.StartsWith("$prefix/", [StringComparison]::Ordinal)) {
    throw "BackupUri must be under the configured prefix '$prefix/'."
}

$mysqlCommand = Get-Command mysql.exe -ErrorAction SilentlyContinue
if (-not $mysqlCommand -and (Test-Path 'C:\laragon\bin\mysql')) {
    $mysqlCommand = Get-ChildItem 'C:\laragon\bin\mysql' -Filter mysql.exe -File -Recurse |
        Sort-Object FullName -Descending |
        Select-Object -First 1
}
if (-not $mysqlCommand) {
    throw 'mysql.exe was not found. Add it to PATH or install MySQL/MariaDB client tools.'
}
$mysqlPath = if ($mysqlCommand -is [System.IO.FileInfo]) {
    $mysqlCommand.FullName
} else {
    $mysqlCommand.Source
}

$temporaryArchive = Join-Path ([System.IO.Path]::GetTempPath()) (
    'ledgerflow-restore-' + [guid]::NewGuid().ToString('N') + '.zip'
)

try {
    $downloadArguments = @('s3', 'cp', $BackupUri, $temporaryArchive, '--only-show-errors')
    $downloadArguments += $awsCommonArguments
    & $aws.Source @downloadArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to download backup archive: $BackupUri"
    }

    & $php.Source $restoreScript $temporaryArchive $mysqlPath
    if ($LASTEXITCODE -ne 0) {
        throw "Restore validation failed for $BackupUri"
    }
} finally {
    if (Test-Path $temporaryArchive) {
        Remove-Item -LiteralPath $temporaryArchive -Force
    }
}
