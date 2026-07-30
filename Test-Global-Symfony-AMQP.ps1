param(
    [string]$BaseUrl = "http://localhost",
    [switch]$Build,
    [switch]$RunScaling,
    [switch]$RunInfection
)

$ErrorActionPreference = "Continue"
$ProjectRoot = (Get-Location).Path
$LogFile = Join-Path $ProjectRoot ("validation-globale-{0}.log" -f (Get-Date -Format "yyyyMMdd-HHmmss"))
$Results = New-Object System.Collections.Generic.List[object]

function Write-Section([string]$Title) {
    $line = "=" * 78
    Write-Host "`n$line" -ForegroundColor Cyan
    Write-Host $Title -ForegroundColor Cyan
    Write-Host $line -ForegroundColor Cyan
    Add-Content -Path $LogFile -Value "`n$line`n$Title`n$line"
}

function Invoke-ValidationStep {
    param(
        [string]$Name,
        [scriptblock]$Command,
        [switch]$Optional
    )

    Write-Host "`n[TEST] $Name" -ForegroundColor Yellow
    $start = Get-Date
    $output = & $Command 2>&1 | Out-String
    $exitCode = $LASTEXITCODE
    if ($null -eq $exitCode) { $exitCode = 0 }
    $duration = [math]::Round(((Get-Date) - $start).TotalSeconds, 2)

    $status = if ($exitCode -eq 0) { "PASS" } elseif ($Optional) { "WARN" } else { "FAIL" }
    $color = if ($status -eq "PASS") { "Green" } elseif ($status -eq "WARN") { "DarkYellow" } else { "Red" }
    Write-Host "[$status] $Name ($duration s)" -ForegroundColor $color
    if ($output.Trim()) { Write-Host $output.TrimEnd() }

    Add-Content -Path $LogFile -Value "`n[$status] $Name ($duration s)`n$output"
    $Results.Add([pscustomobject]@{
        Test = $Name
        Status = $status
        ExitCode = $exitCode
        DurationSeconds = $duration
    })
}

Write-Section "0. PREREQUIS ET CONFIGURATION"
Invoke-ValidationStep "Docker disponible" { docker version --format '{{.Server.Version}}' }
Invoke-ValidationStep "Docker Compose valide" { docker compose config --quiet }
Invoke-ValidationStep "Fichiers obligatoires présents" {
    $required = @(
        "docker-compose.yml",
        "docker/php/Dockerfile",
        "docker/worker/Dockerfile",
        "docker/entrypoint.sh",
        "app/composer.json",
        "app/config/packages/messenger.yaml",
        "app/phpunit.xml.dist",
        "app/phpstan.dist.neon",
        "app/.php-cs-fixer.dist.php",
        "app/rector.php",
        ".github/workflows/ci.yml"
    )
    $missing = $required | Where-Object { -not (Test-Path $_) }
    if ($missing.Count -gt 0) {
        $missing | ForEach-Object { Write-Error "Fichier manquant: $_" }
        exit 1
    }
}
Invoke-ValidationStep "Dockerfiles multi-stage" {
    $files = @("docker/php/Dockerfile", "docker/worker/Dockerfile")
    foreach ($file in $files) {
        $content = Get-Content $file -Raw
        if ($content -notmatch '(?im)^FROM\s+.+\s+AS\s+\S+') {
            Write-Error "$file ne contient pas de stage nommé (FROM ... AS ...)"
            exit 1
        }
    }
}

Write-Section "1. DEMARRAGE DE L'INFRASTRUCTURE"
if ($Build) {
    Invoke-ValidationStep "Build et démarrage Docker" { docker compose up -d --build }
} else {
    Invoke-ValidationStep "Démarrage Docker" { docker compose up -d }
}
Invoke-ValidationStep "Etat des services" { docker compose ps }
Invoke-ValidationStep "Services attendus présents" {
    $services = docker compose config --services
    $required = @("app", "nginx", "db", "redis", "rabbitmq", "worker-1", "worker-2", "worker-dead-letter")
    $missing = $required | Where-Object { $_ -notin $services }
    if ($missing.Count -gt 0) {
        Write-Error "Services manquants: $($missing -join ', ')"
        exit 1
    }
}
Invoke-ValidationStep "Healthchecks de tous les services" {
    $json = docker compose ps --format json | ConvertFrom-Json
    $bad = @()
    foreach ($item in $json) {
        if ($item.State -ne "running") { $bad += "$($item.Service): state=$($item.State)"; continue }
        if ([string]::IsNullOrWhiteSpace($item.Health)) { $bad += "$($item.Service): aucun healthcheck"; continue }
        if ($item.Health -ne "healthy") { $bad += "$($item.Service): health=$($item.Health)" }
    }
    if ($bad.Count -gt 0) {
        $bad | ForEach-Object { Write-Error $_ }
        exit 1
    }
}

Write-Section "2. SYMFONY, DOCTRINE ET CONFIGURATION"
Invoke-ValidationStep "Version Symfony/PHP" { docker compose exec -T app php bin/console about }
Invoke-ValidationStep "Lint du conteneur Symfony" { docker compose exec -T app php bin/console lint:container }
Invoke-ValidationStep "Lint YAML" { docker compose exec -T app php bin/console lint:yaml config }
Invoke-ValidationStep "Validation du schéma Doctrine" { docker compose exec -T app php bin/console doctrine:schema:validate --skip-sync }
Invoke-ValidationStep "Statut des migrations" { docker compose exec -T app php bin/console doctrine:migrations:status --no-interaction }
Invoke-ValidationStep "Routes API obligatoires" {
    $routes = docker compose exec -T app php bin/console debug:router
    $patterns = @(
        'POST\s+/api/jobs',
        'GET\s+/api/jobs/\{id\}',
        'GET\s+/api/jobs',
        'GET\s+/api/jobs/\{id\}/logs',
        'POST\s+/api/jobs/\{id\}/retry',
        'DELETE\s+/api/jobs/\{id\}',
        'GET\s+/api/stats',
        'GET\s+/health',
        'GET\s+/metrics'
    )
    foreach ($pattern in $patterns) {
        if ($routes -notmatch $pattern) { Write-Error "Route absente: $pattern"; exit 1 }
    }
}

Write-Section "3. RABBITMQ, MESSENGER ET WORKERS"
Invoke-ValidationStep "Création des transports Messenger" { docker compose exec -T app php bin/console messenger:setup-transports }
Invoke-ValidationStep "Ping RabbitMQ" { docker compose exec -T rabbitmq rabbitmq-diagnostics -q ping }
Invoke-ValidationStep "Queues RabbitMQ" { docker compose exec -T rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers }
Invoke-ValidationStep "Exchanges RabbitMQ" { docker compose exec -T rabbitmq rabbitmqctl list_exchanges name type }
Invoke-ValidationStep "Bindings RabbitMQ" { docker compose exec -T rabbitmq rabbitmqctl list_bindings source_name destination_name destination_kind routing_key }
Invoke-ValidationStep "Processus des workers" {
    $workers = @("worker-1", "worker-2", "worker-dead-letter")
    foreach ($worker in $workers) {
        $id = docker compose ps -q $worker
        if ([string]::IsNullOrWhiteSpace($id)) { Write-Error "$worker absent"; exit 1 }
        $running = docker inspect -f '{{.State.Running}}' $id
        if ($running -ne "true") { Write-Error "$worker non démarré"; exit 1 }
    }
}

Write-Section "4. API, CACHE, RATE LIMITING ET MONITORING"
Invoke-ValidationStep "Endpoint /health" {
    $response = Invoke-RestMethod -Uri "$BaseUrl/health" -Method Get -TimeoutSec 20
    if ($response.status -notin @("healthy", "ok")) { throw "Statut health inattendu: $($response.status)" }
}
Invoke-ValidationStep "Endpoint /metrics" {
    $text = (Invoke-WebRequest -Uri "$BaseUrl/metrics" -UseBasicParsing -TimeoutSec 20).Content
    $metrics = @("jobs_submitted_total", "jobs_processed_total", "jobs_processing_duration_seconds", "jobs_queue_size", "worker_active_jobs", "worker_memory_usage_bytes")
    foreach ($metric in $metrics) {
        if ($text -notmatch [regex]::Escape($metric)) { throw "Métrique absente: $metric" }
    }
}
Invoke-ValidationStep "Endpoint /api/stats" {
    $null = Invoke-RestMethod -Uri "$BaseUrl/api/stats" -Method Get -TimeoutSec 20
}
Invoke-ValidationStep "Soumission et lecture d'un job" {
    $body = @{ type = "report"; payload = @{ title = "Validation globale" }; priority = 0 } | ConvertTo-Json -Depth 5
    $job = Invoke-RestMethod -Uri "$BaseUrl/api/jobs" -Method Post -ContentType "application/json" -Body $body -TimeoutSec 30
    if (-not $job.id) { throw "ID du job absent" }
    Start-Sleep -Seconds 2
    $detail = Invoke-RestMethod -Uri "$BaseUrl/api/jobs/$($job.id)" -Method Get -TimeoutSec 20
    if ($detail.id -ne $job.id) { throw "Le job lu ne correspond pas au job créé" }
    $null = Invoke-RestMethod -Uri "$BaseUrl/api/jobs/$($job.id)/logs" -Method Get -TimeoutSec 20
}
Invoke-ValidationStep "Rate limiting configuré" {
    $config = docker compose exec -T app php bin/console debug:config framework rate_limiter
    if ($config -notmatch '100' -or $config -notmatch 'minute') {
        Write-Error "La limite 100 requêtes/minute n'est pas clairement détectée"
        exit 1
    }
}

Write-Section "5. TESTS AUTOMATISES"
Invoke-ValidationStep "Tests unitaires" { docker compose exec -T app vendor/bin/phpunit --testsuite Unit }
Invoke-ValidationStep "Tests d'intégration" { docker compose exec -T app vendor/bin/phpunit --testsuite Integration }
Invoke-ValidationStep "Tests fonctionnels" { docker compose exec -T app vendor/bin/phpunit --testsuite Functional }
Invoke-ValidationStep "Tests E2E" { docker compose exec -T app vendor/bin/phpunit --testsuite E2E }
Invoke-ValidationStep "Tests AMQP" { docker compose exec -T app vendor/bin/phpunit --testsuite AMQP }
Invoke-ValidationStep "Suite PHPUnit globale" { docker compose exec -T app vendor/bin/phpunit }

Write-Section "6. QUALITE DU CODE"
Invoke-ValidationStep "PHPStan niveau 9" { docker compose exec -T app vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=1G }
Invoke-ValidationStep "PHP-CS-Fixer (check)" { docker compose exec -T app vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff }
Invoke-ValidationStep "Rector (dry-run)" { docker compose exec -T app vendor/bin/rector process --dry-run --config=rector.php --no-parallel }
if ($RunInfection) {
    Invoke-ValidationStep "Infection (bonus)" { docker compose exec -T app vendor/bin/infection --configuration=infection.json5 --threads=1 --no-progress --test-framework-options="--testsuite Unit" } -Optional
}

Write-Section "7. SCALING ET CI/CD"
Invoke-ValidationStep "Fichier override de production" {
    if (-not (Test-Path "docker-compose.override.yml")) { Write-Error "docker-compose.override.yml absent"; exit 1 }
    docker compose config --quiet
}
Invoke-ValidationStep "Script d'auto-scaling présent" {
    if (-not (Test-Path "scripts/auto-scale-workers.ps1")) { Write-Error "Script auto-scale absent"; exit 1 }
    $content = Get-Content "scripts/auto-scale-workers.ps1" -Raw
    if ($content -notmatch '100' -or $content -notmatch '10') { Write-Error "Seuils 100/10 non détectés"; exit 1 }
    if ($content -notmatch '(?i)5\s*min|300|TimeSpan|Start-Sleep') {
        Write-Error "La temporisation de 5 minutes avant scale-down n'est pas détectée"
        exit 1
    }
}
Invoke-ValidationStep "Pipeline CI/CD présent" {
    $path = ".github/workflows/ci.yml"
    if (-not (Test-Path $path)) { Write-Error "$path absent"; exit 1 }
    $ci = Get-Content $path -Raw
    foreach ($token in @("php-cs-fixer", "phpstan", "rector", "phpunit", "docker", "deploy")) {
        if ($ci -notmatch [regex]::Escape($token)) { Write-Error "Etape CI/CD absente: $token"; exit 1 }
    }
}
if ($RunScaling) {
    Invoke-ValidationStep "Scaling manuel 3/2" {
        docker compose up -d --scale worker-1=3 --scale worker-2=2
        $w1 = @(docker compose ps -q worker-1).Count
        $w2 = @(docker compose ps -q worker-2).Count
        if ($w1 -ne 3 -or $w2 -ne 2) { Write-Error "Scaling incorrect: worker-1=$w1 worker-2=$w2"; exit 1 }
    }
    Invoke-ValidationStep "Retour au minimum 1/1" { docker compose up -d --scale worker-1=1 --scale worker-2=1 }
}

Write-Section "8. SECURITE ET LIVRAISON"
Invoke-ValidationStep "Aucun secret local suivi par Git" {
    if (Test-Path ".git") {
        $tracked = git ls-files
        $forbidden = @("app/.env.dev", "app/.env.local", "app/.php-cs-fixer.cache", "app/vendor")
        $bad = $forbidden | Where-Object { $_ -in $tracked }
        if ($bad.Count -gt 0) { Write-Error "Fichiers interdits suivis: $($bad -join ', ')"; exit 1 }
    }
}
Invoke-ValidationStep "Git propre" {
    if (Test-Path ".git") {
        $status = git status --porcelain
        if ($status) { Write-Error "Le dépôt contient des changements non commités:`n$status"; exit 1 }
    }
} -Optional

Write-Section "RESUME FINAL"
$Results | Format-Table -AutoSize
$pass = @($Results | Where-Object Status -eq "PASS").Count
$warn = @($Results | Where-Object Status -eq "WARN").Count
$fail = @($Results | Where-Object Status -eq "FAIL").Count
Write-Host "`nPASS=$pass  WARN=$warn  FAIL=$fail" -ForegroundColor $(if ($fail -eq 0) { "Green" } else { "Red" })
Write-Host "Rapport détaillé: $LogFile"
Add-Content -Path $LogFile -Value "`nPASS=$pass WARN=$warn FAIL=$fail"

if ($fail -gt 0) { exit 1 }
exit 0
