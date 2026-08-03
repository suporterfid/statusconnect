#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

$ComposeFiles = @('-f', 'compose.yaml')

if ($env:STC_CI -eq '1' -or $env:CI -eq 'true' -or $env:GITHUB_ACTIONS -eq 'true') {
    $ComposeFiles += @('-f', 'compose.ci.yaml')
}

function Invoke-ComposeCore {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [object[]]$CommandArgs
    )
    [string[]]$flatArgs = @($CommandArgs | ForEach-Object { $_ })
    docker compose @ComposeFiles $flatArgs
    return $LASTEXITCODE
}

function Invoke-Compose {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$CommandArgs
    )
    Invoke-ComposeCore @CommandArgs
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose failed with exit code $LASTEXITCODE"
    }
}

function Write-PackagistWarning {
    if ($env:COMPOSER_PACKAGIST_URL) {
        Write-Warning "COMPOSER_PACKAGIST_URL is set ($($env:COMPOSER_PACKAGIST_URL)). Custom Packagist mirrors can cause stale or incomplete installs."
    }
}

function Get-ComposerEnvArgs {
    $envList = @()
    if ($env:COMPOSER_PACKAGIST_URL) {
        $envList += @('-e', "COMPOSER_PACKAGIST_URL=$($env:COMPOSER_PACKAGIST_URL)")
    }
    return $envList
}

function Invoke-ComposerWithRetry {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$ComposerArgs
    )

    Write-PackagistWarning
    $envArgs = Get-ComposerEnvArgs
    $maxAttempts = 3
    $attempt = 1
    $delaySeconds = 5

    while ($attempt -le $maxAttempts) {
        $exitCode = Invoke-ComposeCore (@('run', '--rm') + $envArgs + @('app', 'composer') + $ComposerArgs)
        if ($exitCode -eq 0) {
            return
        }

        if ($attempt -ge $maxAttempts) {
            throw "Composer failed after $maxAttempts attempts."
        }

        Write-Warning "Composer attempt $attempt failed; retrying in ${delaySeconds}s..."
        Start-Sleep -Seconds $delaySeconds
        $delaySeconds *= 2
        $attempt++
    }
}

function Show-Usage {
    @'
StatusConnect Docker toolchain

Usage:
  .\scripts\stc.ps1 <verb> [args...]

Verbs:
  up           Start core services (app, mysql, mailpit, target)
  down         Stop and remove containers
  bootstrap    Install dependencies, prepare env, migrate database
  composer     Run composer via app container
  artisan      Run artisan via app container
  npm          Run npm via node container (dev profile)
  test         Run PHPUnit test suite
  e2e          Run Playwright end-to-end suite
  release      Build production release zip into dist/
  deploy       Build release and publish over FTP(S)+SSH
  shell        Open shell in app container
  help         Show this help
'@ | Write-Output
}

function Invoke-Up {
    Invoke-Compose 'up' '-d' '--build' 'mysql' 'mailpit' 'target' 'app'
}

function Invoke-Down {
    param([string[]]$CommandArgs)
    Invoke-Compose (@('down') + $CommandArgs)
}

function Invoke-Bootstrap {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Output 'Created .env from .env.example'
    }

    Invoke-Compose 'up' '-d' '--build' 'mysql' 'mailpit' 'target'
    Invoke-Compose 'up' '-d' '--wait' 'mysql'

    Invoke-ComposerWithRetry 'install'

    $hasArtisan = (Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'artisan') -eq 0
    if ($hasArtisan) {
        Invoke-Compose 'run' '--rm' 'app' 'php' 'artisan' 'key:generate' '--force'
        Invoke-Compose 'run' '--rm' 'app' 'php' 'artisan' 'migrate' '--force'
    }
    else {
        Write-Output 'Laravel not scaffolded yet; skipping artisan bootstrap steps.'
    }

    if (Test-Path 'package.json') {
        $npmExit = Invoke-ComposeCore '--profile' 'dev' 'run' '--rm' 'node' 'npm' 'ci'
        if ($npmExit -ne 0) {
            Invoke-Compose '--profile' 'dev' 'run' '--rm' 'node' 'npm' 'install'
        }
    }

    Invoke-Compose 'up' '-d' '--build' 'app'
    Write-Output 'Bootstrap complete.'
}

function Invoke-Composer {
    param([string[]]$CommandArgs)

    if ($CommandArgs.Count -gt 0 -and $CommandArgs[0] -eq 'install') {
        $installArgs = if ($CommandArgs.Count -gt 1) { $CommandArgs[1..($CommandArgs.Count - 1)] } else { @() }
        $retryArgs = @('install') + $installArgs
        Invoke-ComposerWithRetry @retryArgs
        return
    }

    Write-PackagistWarning
    $envArgs = Get-ComposerEnvArgs
    $compArgs = @('run', '--rm') + $envArgs + @('app', 'composer') + $CommandArgs
    Invoke-Compose @compArgs
}

function Invoke-Artisan {
    param([string[]]$CommandArgs)
    $artArgs = @('run', '--rm', 'app', 'php', 'artisan') + $CommandArgs
    Invoke-Compose @artArgs
}

function Invoke-Npm {
    param([string[]]$CommandArgs)
    $npmArgs = @('--profile', 'dev', 'run', '--rm', '--service-ports', 'node', 'npm') + $CommandArgs
    Invoke-Compose @npmArgs
}

function Invoke-Test {
    param([string[]]$CommandArgs)

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'artisan') -eq 0) {
        $testArgs = @('run', '--rm', 'app', 'php', 'artisan', 'test') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'vendor/bin/pest') -eq 0) {
        $testArgs = @('run', '--rm', 'app', 'vendor/bin/pest') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'vendor/bin/phpunit') -eq 0) {
        $testArgs = @('run', '--rm', 'app', 'vendor/bin/phpunit') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    throw 'No test runner found. Scaffold Laravel or install dev dependencies first.'
}

function Invoke-E2E {
    param([string[]]$CommandArgs)

    if (-not (Test-Path 'package.json')) {
        throw 'No package.json found.'
    }

    $pkg = Get-Content -Raw 'package.json'
    if ($pkg -notmatch '"e2e"\s*:') {
        throw 'No e2e script defined in package.json.'
    }

    $envArgs = @(
        '-e', "E2E_EMAIL=$env:E2E_EMAIL",
        '-e', "E2E_PASSWORD=$env:E2E_PASSWORD",
        '-e', "PLAYWRIGHT_BASE_URL=$(if ($env:PLAYWRIGHT_BASE_URL) { $env:PLAYWRIGHT_BASE_URL } else { 'http://app' })"
    )
    Invoke-Compose (@('--profile', 'dev', 'run', '--rm', '--service-ports') + $envArgs + @('node', 'npm', 'run', 'e2e', '--') + $CommandArgs)
}

function Invoke-Release {
    New-Item -ItemType Directory -Force -Path 'dist' | Out-Null
    docker build -f docker/release/Dockerfile --target export --output "type=local,dest=./dist" .
    if ($LASTEXITCODE -ne 0) {
        throw "Release build failed with exit code $LASTEXITCODE"
    }
    Write-Output 'Release artifact written to dist/'
    if (Test-Path "$PSScriptRoot/validate-release.sh") {
        $dist = (Resolve-Path (Join-Path $PSScriptRoot '..\dist')).Path
        bash "$PSScriptRoot/validate-release.sh" $dist
        if ($LASTEXITCODE -ne 0) {
            throw "Release validation failed with exit code $LASTEXITCODE"
        }
    }
}

function Invoke-Deploy {
    param([string[]]$CommandArgs)

    $config = if ($CommandArgs.Count -gt 0) { $CommandArgs[0] } else { 'deploy.config.json' }

    if (-not (Test-Path $config)) {
        Write-Error "Deploy config '$config' not found."
        exit 1
    }

    Write-Output 'Building production release tree (dist/app)...'
    if (Test-Path 'dist/app') {
        Get-ChildItem 'dist/app' -Recurse -Force | ForEach-Object { $_.Attributes = 'Normal' }
        Remove-Item -Recurse -Force 'dist/app'
    }
    New-Item -ItemType Directory -Force -Path 'dist' | Out-Null
    docker build -f docker/release/Dockerfile --target export --output "type=local,dest=./dist" .
    if ($LASTEXITCODE -ne 0) { throw "Release build failed with exit code $LASTEXITCODE" }

    Write-Output 'Building deploy image...'
    docker build -f docker/deploy/Dockerfile -t statusconnect-deploy .
    if ($LASTEXITCODE -ne 0) { throw "Deploy image build failed with exit code $LASTEXITCODE" }

    Write-Output 'Publishing to remote host...'
    $remote = "tr -d '\r' < scripts/deploy.sh > /tmp/deploy.sh && bash /tmp/deploy.sh '$config'"
    docker run --rm -v "${RootDir}:/work" -w /work statusconnect-deploy -c $remote
    if ($LASTEXITCODE -ne 0) { throw "Deployment failed with exit code $LASTEXITCODE" }
}

function Invoke-Shell {
    Invoke-Compose 'run' '--rm' 'app' 'bash'
}

$Verb = if ($args.Count -gt 0) { $args[0] } else { 'help' }
$VerbArgs = if ($args.Count -gt 1) { $args[1..($args.Count - 1)] } else { @() }

switch ($Verb) {
    'up' { Invoke-Up }
    'down' { Invoke-Down -CommandArgs $VerbArgs }
    'bootstrap' { Invoke-Bootstrap }
    'composer' { Invoke-Composer -CommandArgs $VerbArgs }
    'artisan' { Invoke-Artisan -CommandArgs $VerbArgs }
    'npm' { Invoke-Npm -CommandArgs $VerbArgs }
    'test' { Invoke-Test -CommandArgs $VerbArgs }
    'e2e' { Invoke-E2E -CommandArgs $VerbArgs }
    'release' { Invoke-Release }
    'deploy' { Invoke-Deploy -CommandArgs $VerbArgs }
    'shell' { Invoke-Shell }
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default {
        Write-Error "Unknown verb: $Verb"
        Show-Usage
        exit 1
    }
}
