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
$logFile = Join-Path $scriptDir 'google_fit_daily_job.log'
$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
$reportDate = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')

try {
    "[$timestamp] Starting Google Fit daily email job for $reportDate" | Out-File -FilePath $logFile -Append -Encoding utf8

    "[$timestamp] Running load" | Out-File -FilePath $logFile -Append -Encoding utf8
    & php $connector load *>> $logFile
    if ($LASTEXITCODE -ne 0) {
        throw "Load process failed with exit code $LASTEXITCODE"
    }

    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Running daily" | Out-File -FilePath $logFile -Append -Encoding utf8
    $dailyOutput = [string](& php $connector daily $reportDate | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) {
        throw "Daily process failed with exit code $LASTEXITCODE"
    }

    if ([string]::IsNullOrWhiteSpace($dailyOutput)) {
        $dailyOutput = "No daily output returned for $reportDate."
    }

    $dailyOutput | Out-File -FilePath $logFile -Append -Encoding utf8

    if ($emailEnabled) {
        $securePassword = ConvertTo-SecureString $smtpPassword -AsPlainText -Force
        $credential = New-Object System.Management.Automation.PSCredential($smtpUsername, $securePassword)
        $subject = "Google Fit Daily Measurements - $reportDate"
        $body = $dailyOutput

        $mailParams = @{
            To = $emailTo
            From = $emailFrom
            Subject = $subject
            Body = $body
            SmtpServer = $smtpServer
            Port = $smtpPort
            Credential = $credential
        }

        if ($smtpUseSsl) {
            $mailParams['UseSsl'] = $true
        }

        Send-MailMessage @mailParams

        "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Daily measurements emailed to $emailTo" | Out-File -FilePath $logFile -Append -Encoding utf8
    } else {
        "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Email disabled; report generated but not sent" | Out-File -FilePath $logFile -Append -Encoding utf8
    }

    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Daily email job completed successfully" | Out-File -FilePath $logFile -Append -Encoding utf8
}
catch {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Daily email job failed: $($_.Exception.Message)" | Out-File -FilePath $logFile -Append -Encoding utf8
    throw
}
