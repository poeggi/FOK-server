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
# curl reads the login from stdin (-K -), so the password is on no command
# line; a curl config value in double quotes escapes backslash and quote.
$login = 'user = "' + ("$($cred.user):$($cred.pass)" -replace '\\', '\\' -replace '"', '\"') + '"'

$root = Join-Path $PSScriptRoot '..\public' | Resolve-Path
$base = if ($Only) { Join-Path $root $Only | Resolve-Path } else { $root }

$prefix = if ($Staging) { 'staging/' } else { '' }
# api/version.txt names the target, so it is written before the file walk
# below sees it. The generator is the same shell script CI uses - two of them
# would drift the moment a field is added - and bash is on this box already
# (the pre-commit hook runs test/checks.sh through it).
$envName = if ($Staging) { 'staging' } else { 'live' }
& bash (Join-Path $PSScriptRoot 'make-version.sh') $envName
if ($LASTEXITCODE -ne 0) { Write-Error 'could not write public/api/version.txt' }
# Upload order: src/ (classes + migrations before consumers), then
# assets/ (immutable ?v= files before HTML referencing them), then rest.
$srcDir = Join-Path $root 'src'
$assetDir = Join-Path $root 'assets'
$files = if ($Only) {
    # api/version.txt rides EVERY upload, whatever -Only names. It is what the
    # deploy verify reads, so it must never lag the code beside it: an
    # emergency '-Only src' that left it behind would report the release
    # before this one on a webroot already running this one.
    @(Get-ChildItem -Path $base -Recurse -File) +
    @(Get-ChildItem -Path (Join-Path $root 'api/version.txt') -File |
        Where-Object { $_.FullName -notlike "$base*" })
} else {
    @(Get-ChildItem -Path $srcDir -Recurse -File) +
    @(Get-ChildItem -Path $assetDir -Recurse -File) +
    @(Get-ChildItem -Path $base -Recurse -File |
        Where-Object { $_.FullName -notlike "$srcDir*" -and $_.FullName -notlike "$assetDir*" })
}
# The CI deploy uploads only what differs from the manifest its last run
# left in the webroot (tools/deploy.sh). This upload does not keep that
# manifest, so it deletes it first: the next CI deploy then uploads the
# whole tree instead of trusting hashes this upload made wrong. The leading
# '*' lets the DELE fail when there is no manifest; the path is from the
# login directory, where curl sends a command before the transfer.
$null = $login | & curl.exe -K - -sS --ssl-reqd --list-only "ftp://$($cred.host)/" `
    -Q "*DELE $prefix.htdeploy-manifest"
if ($LASTEXITCODE -ne 0) { Write-Error 'FTP host unreachable or login refused, nothing uploaded' }
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
    $login | & curl.exe -K - -sS --ssl-reqd --ftp-create-dirs -T $f.FullName $url `
        -Q "-RNFR $leaf.tmp" -Q "-RNTO $leaf"
    if ($LASTEXITCODE -ne 0) { Write-Error "Upload failed: $rel" }
    $done++
    Write-Host "  $prefix$rel"
}
$target = if ($Staging) { 'STAGING' } else { 'LIVE' }
Write-Host "Deployed $done file(s) to $($cred.host) [$target]"
