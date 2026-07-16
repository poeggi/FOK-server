# Deploys public/ to the fok-server webroot via FTPS.
#
# Credentials are read from a JSON file OUTSIDE the repo (never commit them):
#   ~/.fok-server-deploy.json  ->  { "host": "...", "user": "...", "pass": "..." }
#
# Usage:
#   .\deploy.ps1              upload everything under public/
#   .\deploy.ps1 -Only api    upload only public/api/

param(
    [string]$Only = ''
)

$ErrorActionPreference = 'Stop'

$credFile = Join-Path $HOME '.fok-server-deploy.json'
if (-not (Test-Path $credFile)) {
    Write-Error "Missing $credFile - create it with { host, user, pass }"
}
$cred = Get-Content $credFile -Raw | ConvertFrom-Json

$root = Join-Path $PSScriptRoot '..\public' | Resolve-Path
$base = if ($Only) { Join-Path $root $Only | Resolve-Path } else { $root }

$files = Get-ChildItem -Path $base -Recurse -File
$done = 0
foreach ($f in $files) {
    $rel = $f.FullName.Substring($root.Path.Length + 1) -replace '\\', '/'
    $url = "ftp://$($cred.host)/$rel"
    & curl.exe -sS --ssl-reqd --user "$($cred.user):$($cred.pass)" --ftp-create-dirs -T $f.FullName $url
    if ($LASTEXITCODE -ne 0) { Write-Error "Upload failed: $rel" }
    $done++
    Write-Host "  $rel"
}
Write-Host "Deployed $done file(s) to $($cred.host)"
