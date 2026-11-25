[CmdletBinding(DefaultParameterSetName = "FTPS")]
param(
    [Parameter(ParameterSetName = "FTPS")]
    [switch]$Apply,
    [Parameter(Mandatory, ParameterSetName = "Git")]
    [switch]$PrepareGitRelease,
    [Parameter(ParameterSetName = "Git")]
    [switch]$PublishGitRelease,
    [Parameter(ParameterSetName = "Git")]
    [switch]$BootstrapGitRelease,
    [Parameter(Mandatory, ParameterSetName = "Migrate")]
    [switch]$MigrateGitLayout,
    [Parameter(ParameterSetName = "Migrate")]
    [ValidateSet("prepare", "finish", "cancel", "status", "stage-init", "stage-check")]
    [string]$MigrationAction = "status",
    [Parameter(Mandatory, ParameterSetName = "Migrate")]
    [string]$EnvironmentArchive,
    [Parameter(Mandatory, ParameterSetName = "Verify")]
    [switch]$VerifyProtection,
    [string]$SecretsFile = "$env:USERPROFILE\.yesenterpries-secret\passwords.txt",
    [string]$Url = "https://fair-sandybrown-kangaroo-55.hostingersite.com"
)

$ErrorActionPreference = "Stop"
$arguments = @("-B", "$PSScriptRoot\deploy_website.py", "--secrets-file", $SecretsFile, "--url", $Url)
if ($Apply) {
    $arguments += "--apply"
}
if ($PrepareGitRelease) {
    $arguments += "--git-release"
}
if ($PublishGitRelease) {
    $arguments += "--publish-git-release"
}
if ($BootstrapGitRelease) {
    $arguments += "--bootstrap-git-release"
}
if ($MigrateGitLayout) {
    $arguments += @("--git-migrate", "--migration-action", $MigrationAction, "--environment-archive", $EnvironmentArchive)
}
if ($VerifyProtection) {
    $arguments += "--verify-protection"
}
& python @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Deployment did not complete. Review the reported blocker and private deployment log."
}
