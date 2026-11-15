<#
.SYNOPSIS
  Sobe o ambiente de desenvolvimento: containers, API PHP (porta 8080) e frontend Vite (porta 5173).

.DESCRIPTION
  A API e o frontend abrem cada um em sua propria janela do PowerShell.
  Feche as janelas (ou Ctrl+C nelas) para parar. Os containers continuam
  rodando; para para-los: docker compose stop
#>
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
# The frontend is a separate repo in a sibling folder; override with $env:PO_FRONTEND
$frontend = if ($env:PO_FRONTEND) { $env:PO_FRONTEND } else { Join-Path (Split-Path $root -Parent) 'PurchasingOrganization-frontend' }

cmd /c "docker info >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Docker nao esta rodando. Abra o Docker Desktop e tente de novo.' -ForegroundColor Red
    exit 1
}

Write-Host '==> Containers (db, redis)' -ForegroundColor Cyan
Push-Location $root
try { docker compose --env-file .env up -d } finally { Pop-Location }

Write-Host '==> API em http://localhost:8080' -ForegroundColor Cyan
Start-Process powershell -WorkingDirectory $root -ArgumentList @(
    '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = 'API :8080'; php -S localhost:8080 -t public"
)

if (Test-Path (Join-Path $frontend 'package.json')) {
    Write-Host '==> Frontend em http://localhost:5173' -ForegroundColor Cyan
    Start-Process powershell -WorkingDirectory $frontend -ArgumentList @(
        '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = 'Frontend :5173'; npm run dev"
    )
    Write-Host "`nPronto. Abra http://localhost:5173" -ForegroundColor Green
} else {
    Write-Host "`nFrontend nao encontrado em $frontend (defina `$env:PO_FRONTEND). Somente a API foi iniciada." -ForegroundColor Yellow
}
