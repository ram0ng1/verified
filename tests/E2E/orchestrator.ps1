#requires -Version 5.1
# Orquestrador completo: API + Screenshots + DB + Static checks.

$ErrorActionPreference = 'Continue'
$Here = $PSScriptRoot

if (-not $env:RV_BASE_URL) { Write-Error 'RV_BASE_URL not set'; exit 1 }
if (-not $env:RV_TOKEN) { Write-Error 'RV_TOKEN not set'; exit 1 }

Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  ramon/verified E2E orchestrator" -ForegroundColor Cyan
Write-Host "  Started: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "  Base: $($env:RV_BASE_URL)" -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

# Phase 1
Write-Host ">> Phase 1: API tests" -ForegroundColor Magenta
& "$Here\run.ps1"

# Phase 2
Write-Host ""
Write-Host ">> Phase 2: Screenshots" -ForegroundColor Magenta
if ($env:RV_SKIP_SHOTS -ne '1') {
    & "$Here\screenshots.ps1"
} else {
    Write-Host "  RV_SKIP_SHOTS=1, skipping" -ForegroundColor Yellow
}

# Phase 3 - DB checks via PHP (more portable than embedded SQL)
Write-Host ""
Write-Host ">> Phase 3: DB validation" -ForegroundColor Magenta
$phpExe = "D:\laragon\bin\php\php-8.3.8-nts-Win32-vs16-x64\php.exe"
$dbScript = Join-Path $Here 'db_validate.php'
if ((Test-Path $phpExe) -and (Test-Path $dbScript)) {
    & $phpExe $dbScript | Tee-Object -FilePath "$Here\db_validation.txt"
} else {
    Write-Host "  PHP or db_validate.php missing, skipping" -ForegroundColor Yellow
}

# Phase 4
Write-Host ""
Write-Host ">> Phase 4: Static regression checks" -ForegroundColor Magenta
$root = Resolve-Path "$Here\..\.."
$staticPassed = 0
$staticFailed = 0

function Check-Static($name, $pattern, $file, $expectMissing) {
    $full = Join-Path $root $file
    $hits = 0
    if (Test-Path $full) {
        if (Test-Path $full -PathType Container) {
            $items = Get-ChildItem -Recurse -Path $full -Include *.ts, *.tsx, *.js, *.jsx, *.php -ErrorAction SilentlyContinue
            foreach ($it in $items) {
                $matches = Select-String -Path $it.FullName -Pattern $pattern -ErrorAction SilentlyContinue
                if ($matches) { $hits += $matches.Count }
            }
        } else {
            $matches = Select-String -Path $full -Pattern $pattern -ErrorAction SilentlyContinue
            if ($matches) { $hits = $matches.Count }
        }
    }
    $ok = if ($expectMissing) { $hits -eq 0 } else { $hits -gt 0 }
    $tag = if ($ok) { 'PASS' } else { 'FAIL' }
    $color = if ($ok) { 'Green' } else { 'Red' }
    Write-Host ("  [{0}] {1,-60} (hits={2})" -f $tag, $name, $hits) -ForegroundColor $color
    if ($ok) { $script:staticPassed++ } else { $script:staticFailed++ }
}

Check-Static 'R4-1: TIER_BADGE_CACHE_LIMIT defined'  'TIER_BADGE_CACHE_LIMIT'        'js\src\common\utils\getBadgeSvg.ts' $false
Check-Static 'R4-1: rememberTierBadge function'      'function rememberTierBadge'    'js\src\common\utils\getBadgeSvg.ts' $false
Check-Static 'R4-2: no app.session.user! bang'       'app\.session\.user!'           'js\src' $true
Check-Static 'R4-3: eloquent.deleting listener User' 'eloquent\.deleting.*User'      'extend.php' $false
Check-Static 'R3-2: SanitizeTiersOnSave wired'       'SanitizeTiersOnSave'           'extend.php' $false
Check-Static 'DOCTYPE strip in backend SVG ctrl'     '<!DOCTYPE'                     'src\Api\Controller\UploadBadgeSvgController.php' $false
Check-Static 'DOCTYPE strip in frontend SVG util'    '<!DOCTYPE'                     'js\src\common\utils\getBadgeSvg.ts' $false
Check-Static 'R4-4: buildPlainBody plaintext stream' 'buildPlainBody'                'src\Api\Controller\DownloadDocumentController.php' $false
Check-Static 'R4-4: decryptIfEncrypted used'         'decryptIfEncrypted'            'src\Api\Controller\DownloadDocumentController.php' $false
Check-Static 'F1: companion table model'             'UserVerification'              'src\Models\UserVerification.php' $false
Check-Static 'F1: TierResolver eager-load aware'     'verification'                  'src\TierResolver.php' $false

Write-Host ""
Write-Host ("Static checks: {0} passed, {1} failed" -f $staticPassed, $staticFailed) -ForegroundColor Cyan
Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  Orchestrator complete." -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
