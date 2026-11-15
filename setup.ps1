<#
.SYNOPSIS
  Prepara o ambiente local: banco (Docker), backend (.env, extensao pgsql) e frontend (npm install).

.DESCRIPTION
  Pode ser executado varias vezes: cada passo verifica o estado antes de agir.

.PARAMETER FixPhpIni
  Ativa as extensoes pdo_pgsql e pgsql no php.ini (cria um backup .bak antes).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\setup.ps1
  powershell -ExecutionPolicy Bypass -File .\setup.ps1 -FixPhpIni
#>
param(
    [switch]$FixPhpIni
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$failed = $false

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Ok($msg)   { Write-Host "    [ok] $msg" -ForegroundColor Green }
function Warn($msg) { Write-Host "    [!]  $msg" -ForegroundColor Yellow }
function Fail($msg) { Write-Host "    [x]  $msg" -ForegroundColor Red; $script:failed = $true }

function Test-Command($name) { [bool](Get-Command $name -ErrorAction SilentlyContinue) }

# Runs via cmd so PowerShell 5.1 doesn't turn docker's stderr into a terminating error
function Test-DockerRunning { cmd /c "docker info >nul 2>&1"; return ($LASTEXITCODE -eq 0) }

# Minimal .env parser: KEY=VALUE, ignores comments/blank lines, strips surrounding quotes
function Read-DotEnv($path) {
    $vars = @{}
    foreach ($line in Get-Content $path) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') {
            $value = $Matches[2]
            if ($value -match '^"(.*)"$' -or $value -match "^'(.*)'$") { $value = $Matches[1] }
            $vars[$Matches[1]] = $value
        }
    }
    return $vars
}

$container = 'PurchasingOrganization-db'

# Runs SQL inside the db container over the local socket (trusted, no password needed)
function Invoke-Psql([string]$user, [string]$db, [string]$sql, [string[]]$psqlVars = @()) {
    $dockerArgs = @('exec', '-i', $container, 'psql', '-U', $user, '-d', $db, '-v', 'ON_ERROR_STOP=1', '-tAq')
    foreach ($v in $psqlVars) { $dockerArgs += @('-v', $v) }
    $sql | & docker @dockerArgs
}

# ---------------------------------------------------------------------------
Step 'Verificando ferramentas'
foreach ($cmd in 'docker', 'php', 'node', 'npm') {
    if (Test-Command $cmd) { Ok "$cmd encontrado" } else { Fail "$cmd nao encontrado no PATH" }
}
if ($failed) { Write-Host "`nInstale as ferramentas acima e rode o script novamente." -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------------------
# .env is the single source of truth: docker compose and the PHP API both read it
Step 'Configurando .env'
$envFile = Join-Path $root '.env'
if (Test-Path $envFile) {
    Ok '.env ja existe (mantido como esta)'
} else {
    Copy-Item (Join-Path $root '.env.example') $envFile
    Ok '.env criado a partir do .env.example'
}
$envVars = Read-DotEnv $envFile
$dbName = $envVars['DB_NAME']; $dbUser = $envVars['DB_USER']; $dbPass = $envVars['DB_PASS']
foreach ($k in 'DB_NAME', 'DB_USER', 'DB_PASS') {
    if (-not $envVars[$k]) { Fail "$k nao definido em .env" }
}
if ($failed) { exit 1 }
Ok "Banco: $dbName  |  Usuario: $dbUser"

# ---------------------------------------------------------------------------
Step 'Subindo PostgreSQL e Redis (docker compose)'
if (-not (Test-DockerRunning)) {
    Fail 'Docker nao esta rodando. Abra o Docker Desktop, aguarde "Engine running" e rode o script de novo.'
    exit 1
}

Push-Location $root
try {
    docker compose --env-file .env up -d
    if ($LASTEXITCODE -ne 0) { throw 'docker compose up falhou' }
} finally { Pop-Location }

Write-Host '    Aguardando o banco ficar pronto' -NoNewline
$healthy = $false
for ($i = 0; $i -lt 40; $i++) {
    $status = cmd /c "docker inspect -f {{.State.Health.Status}} $container 2>nul"
    if ($status -eq 'healthy') { $healthy = $true; break }
    Write-Host '.' -NoNewline
    Start-Sleep -Seconds 2
}
Write-Host ''
if (-not $healthy) { Fail 'O banco nao ficou pronto a tempo. Veja: docker compose logs db'; exit 1 }
Ok 'PostgreSQL pronto'

# POSTGRES_DB/USER/PASSWORD only apply when the volume is first created. If the volume
# already existed (e.g. after renaming the database), bring it in line with .env.
$existingUsers = (cmd /c "docker exec $container psql -U postgres -d postgres -tAc ""select 1"" 2>nul")
$admin = if ("$existingUsers".Trim() -eq '1') { 'postgres' } else { $dbUser }

Invoke-Psql $admin 'postgres' @"
SELECT format('CREATE ROLE %I LOGIN', :'user') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'user') \gexec
ALTER ROLE :"user" WITH LOGIN PASSWORD :'pw';
SELECT format('CREATE DATABASE %I OWNER %I', :'db', :'user') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
"@ @("user=$dbUser", "pw=$dbPass", "db=$dbName")
if ($LASTEXITCODE -ne 0) { Fail 'Falha ao criar/ajustar usuario e banco'; exit 1 }
Ok "Usuario '$dbUser' e banco '$dbName' prontos (senha sincronizada com .env)"

$tables = Invoke-Psql $dbUser $dbName "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('batches','items','offers');"
if ("$tables".Trim() -eq '3') {
    Ok 'Tabelas batches, items e offers existem'
} else {
    Warn 'Tabelas nao encontradas; aplicando migration'
    $migration = Get-Content (Join-Path $root 'database/migrations/001_create_tables.sql') -Raw
    Invoke-Psql $dbUser $dbName $migration | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail 'Falha ao aplicar a migration' } else { Ok 'Migration aplicada' }
}

# ---------------------------------------------------------------------------
Step 'Verificando PHP'

$iniPath = (php -r 'echo php_ini_loaded_file();')
$modules = php -m
if ($modules -contains 'pdo_pgsql') {
    Ok 'Extensao pdo_pgsql ativa'
} elseif ($FixPhpIni -and $iniPath) {
    Copy-Item $iniPath "$iniPath.bak" -Force
    $content = Get-Content $iniPath
    $content = $content -replace '^\s*;\s*extension\s*=\s*(pdo_pgsql|pgsql)\s*$', 'extension=$1'
    Set-Content -Path $iniPath -Value $content -Encoding ASCII
    if ((php -m) -contains 'pdo_pgsql') {
        Ok "Extensoes ativadas em $iniPath (backup em $iniPath.bak)"
    } else {
        Fail "Nao foi possivel ativar pdo_pgsql em $iniPath. Ative manualmente."
    }
} else {
    Fail "Extensao pdo_pgsql desativada. Rode com -FixPhpIni ou remova o ';' de 'extension=pdo_pgsql' e 'extension=pgsql' em: $iniPath"
}

if (Test-Path (Join-Path $root 'vendor/autoload.php')) {
    Ok 'Dependencias PHP (vendor) presentes'
} elseif (Test-Command 'composer') {
    Push-Location $root
    try { composer install } finally { Pop-Location }
    Ok 'composer install executado'
} else {
    Fail 'vendor ausente e composer nao encontrado. Instale o Composer e rode: composer install'
}

# End-to-end check: connect exactly like the API does (phpdotenv + PDO over TCP)
if (-not $failed) {
    $probe = Join-Path $env:TEMP 'po-db-probe.php'
    @'
<?php
require $argv[1] . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($argv[1])->safeLoad();
echo strlen($_ENV['DB_PASS'] ?? ''), "\n";
try {
    new PDO("pgsql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
    echo 'OK';
} catch (Throwable $e) {
    echo $e->getMessage();
}
'@ | Set-Content -Path $probe -Encoding ASCII
    $output = @(php $probe $root)
    Remove-Item $probe -ErrorAction SilentlyContinue
    $phpPass = $output[0]
    $result = ($output | Select-Object -Skip 1) -join ' '
    if ($result -eq 'OK') {
        Ok 'API consegue conectar no banco com as credenciais de .env'
    } else {
        Fail "API nao conecta no banco: $result"
        if ("$phpPass" -ne "$($dbPass.Length)") {
            Warn "O PHP le DB_PASS com $phpPass caracteres, mas o valor tem $($dbPass.Length)."
            Warn "Senhas com '#', '`$' ou espacos precisam de aspas simples em .env, ex.: DB_PASS='s#nh`$a'"
        }
    }
}

# ---------------------------------------------------------------------------
Step 'Configurando frontend'
# The frontend is a separate repo in a sibling folder; override with $env:PO_FRONTEND
$frontend = if ($env:PO_FRONTEND) { $env:PO_FRONTEND } else { Join-Path (Split-Path $root -Parent) 'PurchasingOrganization-frontend' }
if (-not (Test-Path (Join-Path $frontend 'package.json'))) {
    Warn "Frontend nao encontrado em $frontend (defina `$env:PO_FRONTEND); passo ignorado"
} elseif (Test-Path (Join-Path $frontend 'node_modules')) {
    Ok "node_modules presente em $frontend"
} else {
    Push-Location $frontend
    try { npm install } finally { Pop-Location }
    Ok 'npm install executado'
}

# ---------------------------------------------------------------------------
if ($failed) {
    Write-Host "`nSetup terminou com pendencias (veja os itens [x] acima)." -ForegroundColor Red
    exit 1
}
Write-Host "`nTudo pronto! Para subir API e frontend rode:" -ForegroundColor Green
Write-Host '    powershell -ExecutionPolicy Bypass -File .\dev.ps1'
