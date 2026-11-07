$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$php = Get-Command php.exe -ErrorAction Stop
$aws = Get-Command aws.exe -ErrorAction Stop
$settingsScript = Join-Path $repositoryRoot 'backend\database\backup_settings.php'
$backupScript = Join-Path $repositoryRoot 'backend\database\create_backup.php'
$environmentJson = & $php.Source $settingsScript
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to load backup settings from .env.'
}
$settings = $environmentJson | ConvertFrom-Json
$bucket = [string]$settings.bucket
$prefix = ([string]$settings.prefix).Trim('/')
$endpoint = [string]$settings.endpoint
$serverSideEncryption = [string]$settings.server_side_encryption
$profile = [string]$settings.profile

if ([string]::IsNullOrWhiteSpace($bucket)) {
    throw 'Set BACKUP_S3_BUCKET in .env to the private S3-compatible bucket name.'
}

$mysqlDumpCommand = Get-Command mysqldump.exe -ErrorAction SilentlyContinue
if (-not $mysqlDumpCommand -and (Test-Path 'C:\laragon\bin\mysql')) {
    $mysqlDumpCommand = Get-ChildItem 'C:\laragon\bin\mysql' -Filter mysqldump.exe -File -Recurse |
        Sort-Object FullName -Descending |
        Select-Object -First 1
}
if (-not $mysqlDumpCommand) {
    throw 'mysqldump.exe was not found. Add it to PATH or install MySQL/MariaDB client tools.'
}
$mysqlDumpPath = if ($mysqlDumpCommand -is [System.IO.FileInfo]) {
    $mysqlDumpCommand.FullName
} else {
    $mysqlDumpCommand.Source
}

$temporaryArchive = Join-Path ([System.IO.Path]::GetTempPath()) (
    'ledgerflow-' + [guid]::NewGuid().ToString('N') + '.zip'
)
$timestamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss-fffZ')
$keyName = "LedgerFlow-$timestamp.zip"
if ($prefix) {
    $keyName = "$prefix/$keyName"
}

try {
    & $php.Source $backupScript $temporaryArchive $mysqlDumpPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Backup archive creation failed.'
    }

    $uploadUri = "s3://$bucket/$keyName"
    $awsArguments = @('s3', 'cp', $temporaryArchive, $uploadUri, '--only-show-errors')
    if ($serverSideEncryption) {
        $awsArguments += @('--sse', $serverSideEncryption)
    }
    if ($endpoint) {
        $awsArguments += @('--endpoint-url', $endpoint)
    }
    if ($profile) {
        $awsArguments += @('--profile', $profile)
    }
    & $aws.Source @awsArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Off-site backup upload failed: $uploadUri"
    }

    $headArguments = @(
        's3api', 'head-object', '--bucket', $bucket, '--key', $keyName,
        '--query', 'ContentLength', '--output', 'text'
    )
    if ($endpoint) {
        $headArguments += @('--endpoint-url', $endpoint)
    }
    if ($profile) {
        $headArguments += @('--profile', $profile)
    }
    $remoteSize = & $aws.Source @headArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Could not verify the uploaded backup object: $uploadUri"
    }
    if ([long]$remoteSize -ne (Get-Item $temporaryArchive).Length) {
        throw "The uploaded backup size does not match the local archive: $uploadUri"
    }

    Write-Output "Off-site backup uploaded and verified: $uploadUri"
} finally {
    if (Test-Path $temporaryArchive) {
        Remove-Item -LiteralPath $temporaryArchive -Force
    }
}
