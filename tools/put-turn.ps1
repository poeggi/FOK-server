# Uploads the Cloudflare TURN key file into the data dir on the host via
# FTPS, using the deploy credentials. The key never goes through the
# deploy and never into the repo; this is the one way it reaches the host.
#
# Reads two files OUTSIDE the repo (never commit either):
#   ~/.fok-server-deploy.json  ->  { "host": "...", "user": "...", "pass": "..." }
#   ~/.fok-server-turn.json    ->  { "key_id": "...", "key_token": "..." }
#
# Usage:
#   .\put-turn.ps1 -Staging   upload to fok-server-data-staging/turn.json
#   .\put-turn.ps1            upload to fok-server-data/turn.json (LIVE)
#   .\put-turn.ps1 -Remove    delete the file on the host instead (TURN off)
#
# The server reads the file on every ask, so the upload takes at once and
# the removal does too. Staging first, then read the admin Game Statistics
# card's TURN popup there: "configured" says the file landed and parsed.

param(
    [switch]$Staging,
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

$credFile = Join-Path $HOME '.fok-server-deploy.json'
if (-not (Test-Path $credFile)) {
    Write-Error "Missing $credFile - create it with { host, user, pass }"
}
$cred = Get-Content $credFile -Raw | ConvertFrom-Json

$dir = if ($Staging) { 'fok-server-data-staging' } else { 'fok-server-data' }
$target = if ($Staging) { 'STAGING' } else { 'LIVE' }

if ($Remove) {
    & curl.exe -sS --ssl-reqd --user "$($cred.user):$($cred.pass)" "ftp://$($cred.host)/$dir/" -Q "DELE turn.json"
    if ($LASTEXITCODE -ne 0) { Write-Error "Delete failed: $dir/turn.json" }
    Write-Host "Removed $dir/turn.json on $($cred.host) [$target]"
    exit 0
}

$keyFile = Join-Path $HOME '.fok-server-turn.json'
if (-not (Test-Path $keyFile)) {
    Write-Error "Missing $keyFile - create it with { key_id, key_token }"
}
$key = Get-Content $keyFile -Raw | ConvertFrom-Json
foreach ($k in 'key_id', 'key_token') {
    $v = $key.$k
    if (-not $v -or $v -like 'PASTE_*') {
        Write-Error "$keyFile still has no $k"
    }
}

# Upload to .tmp and RENAME into place, like the deploy: the server may be
# reading the file at that moment, and a rename is atomic.
& curl.exe -sS --ssl-reqd --user "$($cred.user):$($cred.pass)" -T $keyFile "ftp://$($cred.host)/$dir/turn.json.tmp" `
    -Q "-RNFR turn.json.tmp" -Q "-RNTO turn.json"
if ($LASTEXITCODE -ne 0) { Write-Error "Upload failed: $dir/turn.json" }
Write-Host "Uploaded $dir/turn.json to $($cred.host) [$target]"
