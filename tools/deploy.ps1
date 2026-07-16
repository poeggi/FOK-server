# Deploys public/ to the fok-server webroot via FTPS.
#
# Credentials are read from a JSON file OUTSIDE the repo (never commit them):
#   ~/.fok-server-deploy.json  ->  { "host": "...", "user": "...", "pass": "..." }
#
# Usage:
#   .\deploy.ps1 -Staging     upload everything to the staging subdirectory
#   .\deploy.ps1              upload everything to the LIVE webroot
#   .\deploy.ps1 -Only api    upload only public/api/
#
# Workflow: ALWAYS deploy to staging first and run the remote smoke test
# against it (see README "Staging and deploy"); deploy live only after
# staging passes.

param(
    [string]$Only = '',
    [switch]$Staging
)

$ErrorActionPreference = 'Stop'

$credFile = Join-Path $HOME '.fok-server-deploy.json'
if (-not (Test-Path $credFile)) {
    Write-Error "Missing $credFile - create it with { host, user, pass }"
}
$cred = Get-Content $credFile -Raw | ConvertFrom-Json

$root = Join-Path $PSScriptRoot '..\public' | Resolve-Path
$base = if ($Only) { Join-Path $root $Only | Resolve-Path } else { $root }

$prefix = if ($Staging) { 'staging/' } else { '' }
# src/ uploads first: shared classes and schema migrations must land
# before the endpoints that depend on them (mid-deploy consistency).
$srcDir = Join-Path $root 'src'
$files = if ($Only) {
    Get-ChildItem -Path $base -Recurse -File
} else {
    @(Get-ChildItem -Path $srcDir -Recurse -File) +
    @(Get-ChildItem -Path $base -Recurse -File | Where-Object { $_.FullName -notlike "$srcDir*" })
}
$done = 0
foreach ($f in $files) {
    $rel = $f.FullName.Substring($root.Path.Length + 1) -replace '\\', '/'
    $url = "ftp://$($cred.host)/$prefix$rel"
    & curl.exe -sS --ssl-reqd --user "$($cred.user):$($cred.pass)" --ftp-create-dirs -T $f.FullName $url
    if ($LASTEXITCODE -ne 0) { Write-Error "Upload failed: $rel" }
    $done++
    Write-Host "  $prefix$rel"
}
$target = if ($Staging) { 'STAGING' } else { 'LIVE' }
Write-Host "Deployed $done file(s) to $($cred.host) [$target]"
