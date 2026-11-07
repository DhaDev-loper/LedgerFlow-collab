$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$powerShell = Join-Path $PSHOME 'powershell.exe'
$backupScript = Join-Path $PSScriptRoot 'backup-daily.ps1'
$restoreScript = Join-Path $PSScriptRoot 'restore-test.ps1'
$account = "$env:USERDOMAIN\$env:USERNAME"
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2)
$principal = New-ScheduledTaskPrincipal `
    -UserId $account `
    -LogonType Interactive `
    -RunLevel Limited

$backupAction = New-ScheduledTaskAction `
    -Execute $powerShell `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$backupScript`""
$backupTrigger = New-ScheduledTaskTrigger -Daily -At 1:00AM
$backupTask = New-ScheduledTask `
    -Action $backupAction `
    -Trigger $backupTrigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Encrypted LedgerFlow database and receipt backup to private S3 storage.'
Register-ScheduledTask `
    -TaskName 'LedgerFlow Daily Off-site Backup' `
    -InputObject $backupTask `
    -Force | Out-Null

$restoreAction = New-ScheduledTaskAction `
    -Execute $powerShell `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$restoreScript`""
$restoreTrigger = New-ScheduledTaskTrigger -Monthly -DaysOfMonth 1 -At 3:00AM
$restoreTask = New-ScheduledTask `
    -Action $restoreAction `
    -Trigger $restoreTrigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Restore the latest LedgerFlow backup into a temporary database and verify receipts.'
Register-ScheduledTask `
    -TaskName 'LedgerFlow Monthly Restore Test' `
    -InputObject $restoreTask `
    -Force | Out-Null

Write-Output 'Registered daily backup at 1:00 AM and monthly restore test on day 1 at 3:00 AM.'
Write-Output 'Tasks run only while this Windows user is logged on. Configure Task Scheduler to run while logged off if needed.'
