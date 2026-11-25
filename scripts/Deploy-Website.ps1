param(
    [switch]$Apply,
    [string]$SecretsFile = "$env:USERPROFILE\.yesenterpries-secret\passwords.txt",
    [string]$Url = "https://fair-sandybrown-kangaroo-55.hostingersite.com"
)

$ErrorActionPreference = "Stop"
$arguments = @("-B", "$PSScriptRoot\deploy_website.py", "--secrets-file", $SecretsFile, "--url", $Url)
if ($Apply) {
    $arguments += "--apply"
}
& python @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Deployment did not complete. Review the reported blocker and private deployment log."
}
