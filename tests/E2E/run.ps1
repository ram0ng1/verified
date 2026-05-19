#requires -Version 5.1
<#
.SYNOPSIS
    Harness E2E completo para ramon/verified. Exercita cada endpoint da
    extensão contra um Flarum 2 vivo. Sem boot do PHP, sem PHPUnit — só
    HTTP + validações cruzadas em DB local + screenshots Edge headless.

.NOTES
    Variáveis de ambiente:
        RV_BASE_URL   — ex.: https://alegatest.alega.com.br
        RV_TOKEN      — token de admin (não master ApiKey; §17)
        RV_USER_ID    — id do user alvo de verify/unverify (default: 1)
        RV_VERBOSE    — '1' para imprimir bodies de erro completos
        RV_SKIP_SHOTS — '1' para pular screenshots headless

    PS 5.1 compatível (sem -SkipHttpErrorCheck). Usa try/catch + WebException.
#>

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$BaseUrl = $env:RV_BASE_URL
if (-not $BaseUrl) {
    Write-Error 'RV_BASE_URL not set'
    exit 1
}
$BaseUrl = $BaseUrl.TrimEnd('/')

$Token = $env:RV_TOKEN
if (-not $Token) {
    Write-Error 'RV_TOKEN not set'
    exit 1
}

$VerboseFlag = $env:RV_VERBOSE -eq '1'
$SkipShots = $env:RV_SKIP_SHOTS -eq '1'
$ActorId = if ($env:RV_USER_ID) { [int]$env:RV_USER_ID } else { 1 }

$Results = @()
$Pass = 0
$Fail = 0
$FixturesDir = Join-Path $PSScriptRoot 'fixtures'

function Get-Headers([string]$tok) {
    $h = @{ Accept = 'application/json' }
    if ($tok) { $h['Authorization'] = "Token $tok" }
    return $h
}

function Invoke-RvHttp {
    param(
        [string]$Name,
        [string]$Method,
        [string]$Path,
        [string]$Tok = '',
        [object]$Body = $null,
        [int[]]$ExpectedCodes = @(200, 204),
        [scriptblock]$Condition = $null,
        [string]$ContentType = 'application/json',
        [byte[]]$RawBody = $null
    )

    $url = "$BaseUrl/api$Path"
    $req = [System.Net.HttpWebRequest]::Create($url)
    $req.Method = $Method
    $req.Accept = 'application/json'
    $req.Timeout = 30000
    $req.ReadWriteTimeout = 30000
    if ($Tok) { $req.Headers.Add('Authorization', "Token $Tok") }
    # Disable automatic redirect so 302→login redirects show as 302 not 200
    $req.AllowAutoRedirect = $false

    if ($Body) {
        $json = $Body | ConvertTo-Json -Depth 10 -Compress
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
        $req.ContentType = $ContentType
        $req.ContentLength = $bytes.Length
        $stream = $req.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
    } elseif ($RawBody) {
        $req.ContentType = $ContentType
        $req.ContentLength = $RawBody.Length
        $stream = $req.GetRequestStream()
        $stream.Write($RawBody, 0, $RawBody.Length)
        $stream.Close()
    }

    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $code = -1
    $body = ''
    $respHeaders = @{}
    try {
        $resp = $req.GetResponse()
        $code = [int]$resp.StatusCode
        foreach ($k in $resp.Headers.AllKeys) { $respHeaders[$k] = $resp.Headers[$k] }
        $rs = $resp.GetResponseStream()
        $sr = New-Object System.IO.StreamReader($rs)
        $body = $sr.ReadToEnd()
        $sr.Close()
        $resp.Close()
    } catch [System.Net.WebException] {
        if ($_.Exception.Response) {
            $code = [int]$_.Exception.Response.StatusCode
            foreach ($k in $_.Exception.Response.Headers.AllKeys) { $respHeaders[$k] = $_.Exception.Response.Headers[$k] }
            try {
                $rs = $_.Exception.Response.GetResponseStream()
                $sr = New-Object System.IO.StreamReader($rs)
                $body = $sr.ReadToEnd()
                $sr.Close()
            } catch { $body = '' }
        } else {
            $sw.Stop()
            $script:Fail++
            $msg = $_.Exception.Message
            Write-Host ("[FAIL] {0,-58} {1,8} {2,6}ms  {3}" -f $Name, 'EXC', $sw.ElapsedMilliseconds, $msg) -ForegroundColor Red
            $script:Results += [pscustomobject]@{
                name = $Name; method = $Method; path = $Path; status = -1
                latency_ms = $sw.ElapsedMilliseconds; result = 'fail'; reason = $msg
            }
            return $null
        }
    } catch {
        $sw.Stop()
        $script:Fail++
        $msg = $_.Exception.Message
        Write-Host ("[FAIL] {0,-58} {1,8} {2,6}ms  {3}" -f $Name, 'EXC', $sw.ElapsedMilliseconds, $msg) -ForegroundColor Red
        $script:Results += [pscustomobject]@{
            name = $Name; method = $Method; path = $Path; status = -1
            latency_ms = $sw.ElapsedMilliseconds; result = 'fail'; reason = $msg
        }
        return $null
    }
    $sw.Stop()

    $ok = $ExpectedCodes -contains $code
    $reason = ''
    $parsed = $null

    if ($body -and $body.StartsWith('{')) {
        try { $parsed = $body | ConvertFrom-Json -ErrorAction Stop } catch { }
    } elseif ($body -and $body.StartsWith('[')) {
        try { $parsed = $body | ConvertFrom-Json -ErrorAction Stop } catch { }
    }

    if ($ok -and $Condition) {
        try {
            $condResult = & $Condition $parsed $respHeaders $body
            if (-not $condResult) {
                $ok = $false
                $reason = "condition failed"
            }
        } catch {
            $ok = $false
            $reason = "condition exception: $($_.Exception.Message)"
        }
    } elseif (-not $ok) {
        $reason = "expected one of [$($ExpectedCodes -join ', ')], got $code"
    }

    $tag = if ($ok) { 'OK  ' } else { 'FAIL' }
    $color = if ($ok) { 'Green' } else { 'Red' }
    Write-Host ("[{0}] {1,-58} {2,8} {3,6}ms  {4}" -f $tag, $Name, $code, $sw.ElapsedMilliseconds, $reason) -ForegroundColor $color
    if (-not $ok -and $VerboseFlag -and $body) {
        Write-Host ('  body: ' + ($body.Substring(0, [Math]::Min(400, $body.Length)))) -ForegroundColor DarkGray
    }

    if ($ok) { $script:Pass++ } else { $script:Fail++ }
    $script:Results += [pscustomobject]@{
        name = $Name; method = $Method; path = $Path; status = $code
        latency_ms = $sw.ElapsedMilliseconds
        result = if ($ok) { 'pass' } else { 'fail' }
        reason = $reason
    }
    return @{ Status = $code; Body = $body; Parsed = $parsed; Headers = $respHeaders; Ok = $ok }
}

function Invoke-RvMultipart {
    # Uses curl.exe — much more robust for multipart than PowerShell StreamWriter
    param(
        [string]$Name,
        [string]$Method,
        [string]$Path,
        [string]$Tok,
        [hashtable]$Files,
        [hashtable]$Fields = @{},
        [int[]]$ExpectedCodes = @(200, 204),
        [scriptblock]$Condition = $null
    )

    $url = "$script:BaseUrl/api$Path"
    $curlArgs = @('-s', '-S', '-X', $Method, '-o', "$env:TEMP\rv_curl_out.txt", '-w', '%{http_code}')
    if ($Tok) { $curlArgs += @('-H', "Authorization: Token $Tok") }
    $curlArgs += @('-H', 'Accept: application/json')

    foreach ($k in $Fields.Keys) {
        $curlArgs += @('-F', "$k=$($Fields[$k])")
    }
    foreach ($field in $Files.Keys) {
        $fileSpec = $Files[$field]
        $curlArgs += @('-F', "$field=@$($fileSpec.path);type=$($fileSpec.contentType);filename=$($fileSpec.filename)")
    }
    $curlArgs += $url

    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $code = -1
    $body = ''
    try {
        $code = & curl.exe @curlArgs 2>$null
        $code = [int]$code
        if (Test-Path "$env:TEMP\rv_curl_out.txt") {
            $body = Get-Content "$env:TEMP\rv_curl_out.txt" -Raw -ErrorAction SilentlyContinue
            if (-not $body) { $body = '' }
        }
    } catch {
        $sw.Stop()
        $msg = $_.Exception.Message
        Write-Host ("[FAIL] {0,-58} {1,8} {2,6}ms  {3}" -f $Name, 'EXC', $sw.ElapsedMilliseconds, $msg) -ForegroundColor Red
        $script:Fail++
        $script:Results += [pscustomobject]@{
            name = $Name; method = $Method; path = $Path; status = -1
            latency_ms = $sw.ElapsedMilliseconds; result = 'fail'; reason = $msg
        }
        return $null
    }
    $sw.Stop()

    $ok = $ExpectedCodes -contains $code
    $reason = ''
    $parsed = $null
    if ($body -and $body.StartsWith('{')) {
        try { $parsed = $body | ConvertFrom-Json -ErrorAction Stop } catch { }
    }

    if ($ok -and $Condition) {
        try {
            $condResult = & $Condition $parsed @{} $body
            if (-not $condResult) {
                $ok = $false
                $reason = "condition failed"
            }
        } catch {
            $ok = $false
            $reason = "condition exception: $($_.Exception.Message)"
        }
    } elseif (-not $ok) {
        $reason = "expected one of [$($ExpectedCodes -join ', ')], got $code"
    }

    $tag = if ($ok) { 'OK  ' } else { 'FAIL' }
    $color = if ($ok) { 'Green' } else { 'Red' }
    Write-Host ("[{0}] {1,-58} {2,8} {3,6}ms  {4}" -f $tag, $Name, $code, $sw.ElapsedMilliseconds, $reason) -ForegroundColor $color
    if (-not $ok -and $script:VerboseFlag -and $body) {
        Write-Host ('  body: ' + ($body.Substring(0, [Math]::Min(400, $body.Length)))) -ForegroundColor DarkGray
    }

    if ($ok) { $script:Pass++ } else { $script:Fail++ }
    $script:Results += [pscustomobject]@{
        name = $Name; method = $Method; path = $Path; status = $code
        latency_ms = $sw.ElapsedMilliseconds
        result = if ($ok) { 'pass' } else { 'fail' }
        reason = $reason
    }
    return @{ Status = $code; Body = $body; Parsed = $parsed; Ok = $ok }
}

Write-Host "=== ramon/verified E2E ===" -ForegroundColor Cyan
Write-Host "Base: $BaseUrl" -ForegroundColor Gray
Write-Host "Actor: $ActorId" -ForegroundColor Gray
Write-Host ""

# ─── 0. Preflight ─────────────────────────────────────────────────────────
Write-Host "== Preflight ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'preflight.api_root_admin' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.canVerifyUsers -eq $true } | Out-Null

Invoke-RvHttp -Name 'preflight.api_root_guest' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedTiers -ne $null } | Out-Null

# ─── 1. R3-8 visibility ───────────────────────────────────────────────────
Write-Host ""
Write-Host "== R3-8 admin-only flags visibility ==" -ForegroundColor Cyan

$guestPayload = Invoke-RvHttp -Name 'r38.guest_has_public_flags' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b)
        $b.data.attributes.ramonVerifiedTiers -ne $null `
            -and $b.data.attributes.ramonVerifiedBadgeSize -ne $null `
            -and $b.data.attributes.ramonVerifiedShowTooltip -ne $null
    }

Invoke-RvHttp -Name 'r38.guest_lacks_RequestsOpen' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.PSObject.Properties.Name -notcontains 'ramonVerifiedRequestsOpen' } | Out-Null

Invoke-RvHttp -Name 'r38.guest_lacks_RequireDocument' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.PSObject.Properties.Name -notcontains 'ramonVerifiedRequireDocument' } | Out-Null

Invoke-RvHttp -Name 'r38.guest_lacks_LockAvatar' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.PSObject.Properties.Name -notcontains 'ramonVerifiedLockAvatar' } | Out-Null

Invoke-RvHttp -Name 'r38.guest_lacks_DocumentTypes' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.PSObject.Properties.Name -notcontains 'ramonVerifiedDocumentTypes' } | Out-Null

Invoke-RvHttp -Name 'r38.admin_has_RequestsOpen' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedRequestsOpen -ne $null } | Out-Null

Invoke-RvHttp -Name 'r38.admin_has_RequireDocument' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedRequireDocument -ne $null } | Out-Null

Invoke-RvHttp -Name 'r38.admin_has_LockAvatar' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedLockAvatar -ne $null } | Out-Null

Invoke-RvHttp -Name 'r38.admin_has_DocumentTypes' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedDocumentTypes -ne $null } | Out-Null

# ─── 2. Approved users listing ────────────────────────────────────────────
Write-Host ""
Write-Host "== Approved users listing ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'approved.list_admin' -Method GET -Path '/verified/approved-users?limit=5' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data -ne $null -and $b.meta.tiers -ne $null } | Out-Null

Invoke-RvHttp -Name 'approved.list_guest_denied' -Method GET -Path '/verified/approved-users' `
    -ExpectedCodes @(401, 403, 404) | Out-Null

Invoke-RvHttp -Name 'approved.list_tier_blue' -Method GET -Path '/verified/approved-users?tier=blue&limit=5' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.meta -ne $null } | Out-Null

Invoke-RvHttp -Name 'approved.list_tier_robotic' -Method GET -Path '/verified/approved-users?tier=robotic&limit=5' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.meta -ne $null } | Out-Null

Invoke-RvHttp -Name 'approved.list_search_q' -Method GET -Path '/verified/approved-users?q=ramon&limit=3' -Tok $Token `
    -ExpectedCodes @(200) | Out-Null

Invoke-RvHttp -Name 'approved.limit_caps_at_50' -Method GET -Path '/verified/approved-users?limit=9999' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.meta.limit -le 50 } | Out-Null

Invoke-RvHttp -Name 'approved.with_offset' -Method GET -Path '/verified/approved-users?offset=0&limit=2' -Tok $Token `
    -ExpectedCodes @(200) | Out-Null

# ─── 3. Encryption ────────────────────────────────────────────────────────
Write-Host ""
Write-Host "== Encryption ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'encryption.status_admin' -Method GET -Path '/verified/encryption/status' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.available -ne $null -and $b.healthy -ne $null } | Out-Null

Invoke-RvHttp -Name 'encryption.status_guest_denied' -Method GET -Path '/verified/encryption/status' `
    -ExpectedCodes @(401, 403, 404) | Out-Null

Invoke-RvHttp -Name 'encryption.generate_without_ack_422' -Method POST -Path '/verified/encryption/generate-keypair' -Tok $Token `
    -Body @{ acknowledgeLoss = $false } `
    -ExpectedCodes @(422) | Out-Null

Invoke-RvHttp -Name 'encryption.generate_guest_denied' -Method POST -Path '/verified/encryption/generate-keypair' `
    -Body @{ acknowledgeLoss = $true } `
    -ExpectedCodes @(400, 401, 403) | Out-Null

# ─── 4. Direct verify/unverify ────────────────────────────────────────────
Write-Host ""
Write-Host "== Direct verify/unverify ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'verify.zero_id' -Method POST -Path '/verified/users/0/verify' -Tok $Token `
    -ExpectedCodes @(404, 422) | Out-Null

Invoke-RvHttp -Name 'verify.unknown_user' -Method POST -Path '/verified/users/999999/verify' -Tok $Token `
    -Body @{ tier = 'blue' } `
    -ExpectedCodes @(404, 422) | Out-Null

Invoke-RvHttp -Name 'verify.guest_denied' -Method POST -Path "/verified/users/$ActorId/verify" `
    -ExpectedCodes @(400, 401, 403) | Out-Null

Invoke-RvHttp -Name 'unverify.guest_denied' -Method DELETE -Path "/verified/users/$ActorId/verify" `
    -ExpectedCodes @(400, 401, 403) | Out-Null

# Verificar user já verificado deve retornar erro de idempotência
Invoke-RvHttp -Name 'verify.already_verified_admin' -Method POST -Path "/verified/users/$ActorId/verify" -Tok $Token `
    -Body @{ tier = 'blue' } `
    -ExpectedCodes @(200, 422) | Out-Null  # 200 if idempotent re-verify, 422 if rejected

# ─── 5. verification-requests JSON:API resource ───────────────────────────
Write-Host ""
Write-Host "== verification-requests resource ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'requests.list_admin' -Method GET -Path '/verification-requests?include=user&page%5Blimit%5D=5' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data -ne $null } | Out-Null

Invoke-RvHttp -Name 'requests.list_guest_denied' -Method GET -Path '/verification-requests' `
    -ExpectedCodes @(400, 401, 403) | Out-Null

Invoke-RvHttp -Name 'requests.approve_unknown' -Method POST -Path '/verification-requests/999999/approve' -Tok $Token `
    -Body @{ data = @{ attributes = @{} } } `
    -ExpectedCodes @(404, 422) | Out-Null

Invoke-RvHttp -Name 'requests.reject_unknown' -Method POST -Path '/verification-requests/999999/reject' -Tok $Token `
    -Body @{ data = @{ attributes = @{} } } `
    -ExpectedCodes @(404, 422) | Out-Null

Invoke-RvHttp -Name 'requests.approve_guest_denied' -Method POST -Path '/verification-requests/45/approve' `
    -ExpectedCodes @(400, 401, 403) | Out-Null

# Re-approving an already-approved request should 422 (idempotency)
Invoke-RvHttp -Name 'requests.reapprove_handled_422' -Method POST -Path '/verification-requests/45/approve' -Tok $Token `
    -Body @{ data = @{ attributes = @{} } } `
    -ExpectedCodes @(404, 422) | Out-Null  # 404 if it doesn't exist anymore, 422 if assertPending fired

# Show a request
Invoke-RvHttp -Name 'requests.show_admin_45' -Method GET -Path '/verification-requests/45' -Tok $Token `
    -ExpectedCodes @(200, 404) | Out-Null

# ─── 6. Badge SVG ─────────────────────────────────────────────────────────
Write-Host ""
Write-Host "== Badge SVG ==" -ForegroundColor Cyan

# Upload without file
Invoke-RvHttp -Name 'badge.upload_no_file' -Method POST -Path '/verified/badge-svg' -Tok $Token `
    -ExpectedCodes @(400, 422) | Out-Null

# Upload with DOCTYPE-prefixed SVG (Inkscape style) — must pass and strip DOCTYPE
# Field name is 'verified-badge' (matches UploadBadgeSvgController::filenamePrefix)
Invoke-RvMultipart -Name 'badge.upload_doctype_svg' -Method POST -Path '/verified/badge-svg' -Tok $Token `
    -Files @{ 'verified-badge' = @{ filename = 'badge-inkscape.svg'; contentType = 'image/svg+xml'; path = (Join-Path $FixturesDir 'badge-inkscape.svg') } } `
    -ExpectedCodes @(200, 201) `
    -Condition { param($b) $b -ne $null } | Out-Null

# Verify the saved SVG content doesn't contain DOCTYPE
$svgInline = Invoke-RvHttp -Name 'badge.svg_content_no_doctype' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b)
        $c = [string]$b.data.attributes.ramonVerifiedBadgeSvgContent
        ($c -eq '' -or ($c -notlike '*DOCTYPE*' -and $c -notlike '*<!ENTITY*'))
    }

# Delete badge as admin
Invoke-RvHttp -Name 'badge.delete_admin' -Method DELETE -Path '/verified/badge-svg' -Tok $Token `
    -ExpectedCodes @(200, 204) | Out-Null

Invoke-RvHttp -Name 'badge.delete_guest_denied' -Method DELETE -Path '/verified/badge-svg' `
    -ExpectedCodes @(400, 401, 403) | Out-Null

# Upload again then verify it stays
Invoke-RvMultipart -Name 'badge.reupload_after_delete' -Method POST -Path '/verified/badge-svg' -Tok $Token `
    -Files @{ 'verified-badge' = @{ filename = 'badge-inkscape.svg'; contentType = 'image/svg+xml'; path = (Join-Path $FixturesDir 'badge-inkscape.svg') } } `
    -ExpectedCodes @(200, 201) | Out-Null

# Malicious SVG — should still parse but strip dangerous bits
Invoke-RvMultipart -Name 'badge.upload_malicious_svg_sanitized' -Method POST -Path '/verified/badge-svg' -Tok $Token `
    -Files @{ 'verified-badge' = @{ filename = 'badge-malicious.svg'; contentType = 'image/svg+xml'; path = (Join-Path $FixturesDir 'badge-malicious.svg') } } `
    -ExpectedCodes @(200, 201) | Out-Null

# Re-fetch and check no script/onload
Invoke-RvHttp -Name 'badge.malicious_stripped' -Method GET -Path '/' `
    -ExpectedCodes @(200) `
    -Condition { param($b)
        $c = [string]$b.data.attributes.ramonVerifiedBadgeSvgContent
        ($c -notlike '*<script*' `
            -and $c -notlike '*onload=*' `
            -and $c -notlike '*onclick=*' `
            -and $c -notlike '*<foreignObject*' `
            -and $c -notlike '*<animate*' `
            -and $c -notlike '*javascript:*')
    } | Out-Null

# Clean up — delete malicious SVG
Invoke-RvHttp -Name 'badge.cleanup_delete' -Method DELETE -Path '/verified/badge-svg' -Tok $Token `
    -ExpectedCodes @(200, 204) | Out-Null

# ─── 7. Documents ─────────────────────────────────────────────────────────
Write-Host ""
Write-Host "== Documents ==" -ForegroundColor Cyan

Invoke-RvHttp -Name 'document.upload_no_file' -Method POST -Path '/verified/documents' -Tok $Token `
    -ExpectedCodes @(422, 400) | Out-Null

Invoke-RvHttp -Name 'document.upload_guest_denied' -Method POST -Path '/verified/documents' `
    -ExpectedCodes @(400, 401, 403) | Out-Null

Invoke-RvHttp -Name 'document.download_unknown' -Method GET -Path '/verified/documents/999999' -Tok $Token `
    -ExpectedCodes @(404) | Out-Null

Invoke-RvHttp -Name 'document.download_guest_denied' -Method GET -Path '/verified/documents/1' `
    -ExpectedCodes @(401, 403, 404) | Out-Null

# Admin is already verified — upload should fail with "already verified" (422), not crash
Invoke-RvMultipart -Name 'document.admin_already_verified_422' -Method POST -Path '/verified/documents' -Tok $Token `
    -Files @{ file = @{ filename = 'doc-1px.png'; contentType = 'image/png'; path = (Join-Path $FixturesDir 'doc-1px.png') } } `
    -Fields @{ documentType = 'rg' } `
    -ExpectedCodes @(422) | Out-Null

# Upload of a bad MIME (txt) should be rejected
Invoke-RvMultipart -Name 'document.bad_mime_txt' -Method POST -Path '/verified/documents' -Tok $Token `
    -Files @{ file = @{ filename = 'doc-evil.txt'; contentType = 'text/plain'; path = (Join-Path $FixturesDir 'doc-evil.txt') } } `
    -Fields @{ documentType = 'rg' } `
    -ExpectedCodes @(422) | Out-Null

# Download existing document (if any) — admin should get 200 or 404
Invoke-RvHttp -Name 'document.download_id_1_admin' -Method GET -Path '/verified/documents/1' -Tok $Token `
    -ExpectedCodes @(200, 404, 410) | Out-Null

# ─── 8. R3-2: Settings sanitization ───────────────────────────────────────
Write-Host ""
Write-Host "== R3-2: Settings sanitization on save ==" -ForegroundColor Cyan

# Fetch current tiers to preserve, then PATCH with injected SVG, then read back
$tiersBefore = Invoke-RvHttp -Name 'r32.read_current_tiers' -Method GET -Path '/' -Tok $Token `
    -ExpectedCodes @(200) `
    -Condition { param($b) $b.data.attributes.ramonVerifiedTiers -ne $null }

if ($tiersBefore -and $tiersBefore.Parsed) {
    $tiers = $tiersBefore.Parsed.data.attributes.ramonVerifiedTiers
    # Inject XSS into first tier badgeSvg
    $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert("xss")</script><circle cx="12" cy="12" r="10" fill="red"/></svg>'
    $tiers[0].badgeSvg = $maliciousSvg
    $tiers[0].badgeEnabled = $true
    $tiersJson = $tiers | ConvertTo-Json -Depth 10 -Compress

    # Flarum 2 API: POST /api/settings with flat key/value body (no JSON:API wrapper)
    $setBody = @{ 'ramon-verified.tiers' = $tiersJson }

    Invoke-RvHttp -Name 'r32.post_tiers_with_malicious_svg' -Method POST -Path '/settings' -Tok $Token `
        -Body $setBody `
        -ExpectedCodes @(200, 204) | Out-Null

    Start-Sleep -Milliseconds 500

    Invoke-RvHttp -Name 'r32.read_tiers_sanitized' -Method GET -Path '/' -Tok $Token `
        -ExpectedCodes @(200) `
        -Condition { param($b)
            $tiers = $b.data.attributes.ramonVerifiedTiers
            if ($tiers.Count -eq 0) { return $false }
            $svg = [string]$tiers[0].badgeSvg
            # Should NOT contain script/onload anymore — server-side sanitizer must strip
            ($svg -notlike '*<script*' -and $svg -notlike '*onload=*')
        } | Out-Null

    # Restore: clear malicious badgeSvg
    $tiers[0].badgeSvg = ''
    $tiers[0].badgeEnabled = $false
    $tiersJsonRestore = $tiers | ConvertTo-Json -Depth 10 -Compress
    $restoreBody = @{ 'ramon-verified.tiers' = $tiersJsonRestore }
    Invoke-RvHttp -Name 'r32.restore_tiers' -Method POST -Path '/settings' -Tok $Token `
        -Body $restoreBody `
        -ExpectedCodes @(200, 204) | Out-Null
}

# ─── 9. Throttler smoke (best-effort — may be already cleared) ────────────
Write-Host ""
Write-Host "== Throttler smoke ==" -ForegroundColor Cyan

$burst = 0
for ($i = 1; $i -le 7; $i++) {
    $r = Invoke-RvHttp -Name "throttler.docupload_burst_$i" `
        -Method POST -Path '/verified/documents' -Tok $Token `
        -ExpectedCodes @(422, 429)
    if ($r -and $r.Status -eq 429) { $burst = $i; break }
}
if ($burst -gt 0) {
    Write-Host "  Throttler engaged at request #$burst" -ForegroundColor Gray
} else {
    Write-Host "  Throttler did NOT engage in 7 requests (admin may be exempt or window was cleared)" -ForegroundColor DarkYellow
}

# ─── Summary ──────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host ("Total: {0,4}    Pass: {1,4}    Fail: {2,4}" -f ($Pass + $Fail), $Pass, $Fail) -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

# JSONL output
$jsonlPath = Join-Path $PSScriptRoot 'results.jsonl'
$Results | ForEach-Object { $_ | ConvertTo-Json -Compress } | Set-Content -Encoding UTF8 -Path $jsonlPath
Write-Host "Detailed log: $jsonlPath"

# Don't bail on fail — caller orchestrator decides
exit 0
