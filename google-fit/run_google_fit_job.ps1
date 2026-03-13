$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

$emailConfigPath = Join-Path $scriptDir 'google_fit_email_config.ps1'
if (!(Test-Path $emailConfigPath)) {
    throw "Email config not found: $emailConfigPath. Copy google_fit_email_config.example.ps1 to google_fit_email_config.ps1 and set your values."
}

$emailConfig = . $emailConfigPath

$emailEnabled = [bool]($emailConfig.EmailEnabled)
$emailTo = [string]($emailConfig.EmailTo)
$emailFrom = [string]($emailConfig.EmailFrom)
$smtpServer = [string]($emailConfig.SmtpServer)
$smtpPort = [int]($emailConfig.SmtpPort)
$smtpUseSsl = [bool]($emailConfig.SmtpUseSsl)
$smtpUsername = [string]($emailConfig.SmtpUsername)
$smtpPassword = [string]($emailConfig.SmtpPassword)

$connector = Join-Path $scriptDir 'google_fit_connector.php'
$logFile = Join-Path $scriptDir 'google_fit_job.log'
$csvFile = Join-Path $scriptDir 'measurements_export.csv'
$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

try {
    "[$timestamp] Starting Google Fit load job" | Out-File -FilePath $logFile -Append -Encoding utf8
    & php $connector load *>> $logFile
    & php $connector csv $csvFile *>> $logFile

    if ($emailEnabled) {
        $securePassword = ConvertTo-SecureString $smtpPassword -AsPlainText -Force
        $credential = New-Object System.Management.Automation.PSCredential($smtpUsername, $securePassword)
        $subject = "Google Fit CSV Export - $(Get-Date -Format 'yyyy-MM-dd')"
        $body = "Attached is the latest Google Fit CSV export."

        Send-MailMessage `
            -To $emailTo `
            -From $emailFrom `
            -Subject $subject `
            -Body $body `
            -SmtpServer $smtpServer `
            -Port $smtpPort `
            -UseSsl:$smtpUseSsl `
            -Credential $credential `
            -Attachments $csvFile

        "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] CSV emailed to $emailTo" | Out-File -FilePath $logFile -Append -Encoding utf8
    }

    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Job completed successfully" | Out-File -FilePath $logFile -Append -Encoding utf8
}
catch {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Job failed: $($_.Exception.Message)" | Out-File -FilePath $logFile -Append -Encoding utf8
    throw
}
