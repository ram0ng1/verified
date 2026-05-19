#requires -Version 5.1
<#
.SYNOPSIS
    Captura screenshots de cada page route da extensão verified contra o
    fórum vivo via Edge headless. Duas viewports: desktop (1440x900) +
    mobile (390x844).

.NOTES
    Páginas autenticadas (admin panel) exigem cookie de sessão. Como cookie
    de sessão do Flarum é complexo de injetar via Edge headless puro, o
    script:
      1. Captura todas as páginas públicas (forum guest);
      2. Para admin panel: documenta a impossibilidade e captura apenas a
         landing page de login. Validação funcional do admin foi feita via
         API tests (run.ps1).

    Variáveis de ambiente:
        RV_BASE_URL - base do fórum
#>

$ErrorActionPreference = 'Continue'  # screenshots best-effort

$BaseUrl = $env:RV_BASE_URL
if (-not $BaseUrl) { Write-Error 'RV_BASE_URL not set'; exit 1 }
$BaseUrl = $BaseUrl.TrimEnd('/')

$EdgeCandidates = @(
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe'
)
$Edge = $EdgeCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $Edge) {
    Write-Host "Edge not found - skipping screenshots" -ForegroundColor Yellow
    exit 0
}
Write-Host "Edge: $Edge" -ForegroundColor Gray

$ShotsRoot = Join-Path $PSScriptRoot 'screenshots'
New-Item -ItemType Directory -Path "$ShotsRoot\desktop" -Force | Out-Null
New-Item -ItemType Directory -Path "$ShotsRoot\mobile" -Force | Out-Null

$Pages = @(
    @{ name = '01_homepage';            path = '/' },
    @{ name = '02_all_discussions';     path = '/all' },
    @{ name = '03_tags_index';          path = '/tags' },
    @{ name = '04_profile_admin_ramon'; path = '/u/Ramon' },
    @{ name = '05_profile_casimir15';   path = '/u/casimir15' },
    @{ name = '06_signup_page';         path = '/?modal=signup' },
    @{ name = '07_admin_landing';       path = '/admin' },
    @{ name = '08_admin_ext_verified';  path = '/admin#/extension/ramon-verified' }
)

$Viewports = @(
    @{ name = 'desktop'; size = '1440,900' },
    @{ name = 'mobile';  size = '390,844' }
)

$captured = 0
$failed = 0

foreach ($vp in $Viewports) {
    foreach ($pg in $Pages) {
        $url = "$BaseUrl$($pg.path)"
        $out = Join-Path $ShotsRoot "$($vp.name)\$($pg.name).png"
        $userDir = Join-Path $env:TEMP ("rv_edge_" + [Guid]::NewGuid().ToString('N'))

        $argList = @(
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--enable-javascript',
            '--hide-scrollbars',
            "--user-data-dir=$userDir",
            "--window-size=$($vp.size)",
            '--virtual-time-budget=5000',
            "--screenshot=$out",
            $url
        )

        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            $proc = Start-Process -FilePath $Edge -ArgumentList $argList -WindowStyle Hidden -Wait -PassThru -ErrorAction Stop
            $sw.Stop()
            $exitCode = $proc.ExitCode
        } catch {
            $sw.Stop()
            Write-Host "  [FAIL] $($vp.name)/$($pg.name) - exception: $_" -ForegroundColor Red
            $failed++
            continue
        }

        if (Test-Path $out) {
            $size = (Get-Item $out).Length
            if ($size -lt 1024) {
                Write-Host "  [SMALL] $($vp.name)/$($pg.name) - only $size bytes (likely blank)" -ForegroundColor Yellow
                $failed++
            } else {
                Write-Host "  [OK   ] $($vp.name)/$($pg.name) - $($size) bytes in $($sw.ElapsedMilliseconds)ms" -ForegroundColor Green
                $captured++
            }
        } else {
            Write-Host "  [MISS ] $($vp.name)/$($pg.name) - no file" -ForegroundColor Red
            $failed++
        }

        # Cleanup user data dir
        try { Remove-Item -Recurse -Force $userDir -ErrorAction SilentlyContinue } catch { }
    }
}

Write-Host ""
Write-Host "Screenshots captured: $captured  failed/blank: $failed" -ForegroundColor Cyan
Write-Host "Output: $ShotsRoot" -ForegroundColor Gray
