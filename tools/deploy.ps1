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
# Upload order: src/ (classes + migrations before consumers), then
# assets/ (immutable ?v= files before HTML referencing them), then rest.
$srcDir = Join-Path $root 'src'
$assetDir = Join-Path $root 'assets'
$files = if ($Only) {
    Get-ChildItem -Path $base -Recurse -File
} else {
    @(Get-ChildItem -Path $srcDir -Recurse -File) +
    @(Get-ChildItem -Path $assetDir -Recurse -File) +
    @(Get-ChildItem -Path $base -Recurse -File |
        Where-Object { $_.FullName -notlike "$srcDir*" -and $_.FullName -notlike "$assetDir*" })
}
$done = 0
foreach ($f in $files) {
    $rel = $f.FullName.Substring($root.Path.Length + 1) -replace '\\', '/'
    # Upload to .tmp and RENAME into place - never overwrite the live file.
    # The webroot is serving during the deploy, and an in-place write leaves
    # a window where the file is truncated (seen live as a fatal 'Class not
    # found' while src/Util.php was mid-upload). Rename is atomic. The quote
    # paths are basenames: curl changes into the target directory first.
    $url = "ftp://$($cred.host)/$prefix$rel.tmp"
    $leaf = $rel.Substring($rel.LastIndexOf('/') + 1)
    & curl.exe -sS --ssl-reqd --user "$($cred.user):$($cred.pass)" --ftp-create-dirs -T $f.FullName $url `
        -Q "-RNFR $leaf.tmp" -Q "-RNTO $leaf"
    if ($LASTEXITCODE -ne 0) { Write-Error "Upload failed: $rel" }
    $done++
    Write-Host "  $prefix$rel"
}
$target = if ($Staging) { 'STAGING' } else { 'LIVE' }
Write-Host "Deployed $done file(s) to $($cred.host) [$target]"
