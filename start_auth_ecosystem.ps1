# ============================================================
# Enterprise Authentication Ecosystem — Production Startup
# ============================================================
# Usage: .\start_auth_ecosystem.ps1
# Starts: auth-service (:8001), user-service (:8002), api-gateway (:8000)
# ============================================================

param(
    [switch]$Stop,
    [switch]$Status,
    [switch]$Migrate,
    [switch]$Seed
)

$ROOT = Split-Path -Parent $MyInvocation.MyCommand.Path
$SERVICES = @(
    @{ Name="auth-service";  Port=8001; Path="$ROOT\auth-service"  },
    @{ Name="user-service";  Port=8002; Path="$ROOT\user-service"  },
    @{ Name="api-gateway";   Port=8000; Path="$ROOT\api-gateway"   }
)

function Write-Header {
    Write-Host ""
    Write-Host "  ╔══════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "  ║   Enterprise Auth Ecosystem — Laravel 12         ║" -ForegroundColor Cyan
    Write-Host "  ║   Auth:8001 | User:8002 | Gateway:8000           ║" -ForegroundColor Cyan
    Write-Host "  ╚══════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""
}

function Test-Port($port) {
    try {
        $tcp = New-Object System.Net.Sockets.TcpClient
        $tcp.Connect("127.0.0.1", $port)
        $tcp.Close()
        return $true
    } catch { return $false }
}

function Get-ServiceStatus {
    Write-Header
    Write-Host "  SERVICE STATUS" -ForegroundColor Yellow
    Write-Host "  ─────────────────────────────────────" -ForegroundColor DarkGray
    foreach ($svc in $SERVICES) {
        $alive = Test-Port $svc.Port
        $icon  = if ($alive) { "●" } else { "○" }
        $color = if ($alive) { "Green" } else { "DarkGray" }
        $state = if ($alive) { "RUNNING" } else { "STOPPED" }
        Write-Host "  $icon  $($svc.Name.PadRight(15)) :$($svc.Port)  $state" -ForegroundColor $color
    }
    Write-Host ""

    if (Test-Port 6379) {
        Write-Host "  ● Redis                 :6379  RUNNING" -ForegroundColor Green
    } else {
        Write-Host "  ○ Redis                 :6379  NOT RUNNING (optional — falls back to database cache)" -ForegroundColor Yellow
    }
    Write-Host ""
}

function Stop-Services {
    Write-Host "  Stopping services..." -ForegroundColor Yellow
    Get-Process -Name "php" -ErrorAction SilentlyContinue | Where-Object {
        $_.CommandLine -match "artisan serve"
    } | Stop-Process -Force
    Write-Host "  Done." -ForegroundColor Green
}

function Run-Migrations {
    Write-Host "`n  Running migrations..." -ForegroundColor Cyan
    foreach ($svc in $SERVICES | Where-Object { $_.Name -ne "api-gateway" }) {
        Write-Host "  → $($svc.Name)" -ForegroundColor DarkGray
        Push-Location $svc.Path
        php artisan migrate --force 2>&1 | Where-Object { $_ -match "(DONE|FAIL|Nothing)" } | ForEach-Object { Write-Host "    $_" }
        Pop-Location
    }
}

function Run-Seeders {
    Write-Host "`n  Running seeders..." -ForegroundColor Cyan
    foreach ($svc in $SERVICES | Where-Object { $_.Name -ne "api-gateway" }) {
        Write-Host "  → $($svc.Name)" -ForegroundColor DarkGray
        Push-Location $svc.Path
        php artisan db:seed --force 2>&1 | ForEach-Object { Write-Host "    $_" }
        Pop-Location
    }
}

function Start-Services {
    Write-Header
    Write-Host "  Starting services..." -ForegroundColor Cyan
    Write-Host ""

    foreach ($svc in $SERVICES) {
        if (Test-Port $svc.Port) {
            Write-Host "  ● $($svc.Name) already running on :$($svc.Port)" -ForegroundColor Yellow
            continue
        }

        $logFile = "$($svc.Path)\storage\logs\serve.log"
        Start-Process powershell -ArgumentList "-NoExit", "-Command",
            "cd '$($svc.Path)'; php artisan config:clear 2>`$null; php artisan serve --port=$($svc.Port) 2>&1 | Tee-Object -FilePath '$logFile'" `
            -WindowStyle Minimized

        # Wait up to 8 seconds for service to come up
        $attempts = 0
        do { Start-Sleep -Milliseconds 500; $attempts++ } while (-not (Test-Port $svc.Port) -and $attempts -lt 16)

        if (Test-Port $svc.Port) {
            Write-Host "  ● $($svc.Name) started on :$($svc.Port)" -ForegroundColor Green
        } else {
            Write-Host "  ✗ $($svc.Name) FAILED to start — check $logFile" -ForegroundColor Red
        }
    }

    Write-Host ""

    # Health check via gateway
    Start-Sleep -Milliseconds 1000
    try {
        $health = Invoke-RestMethod -Uri "http://localhost:8000/api/health" -Method GET -TimeoutSec 5
        Write-Host "  ✓ Gateway health: $($health.status)" -ForegroundColor Green
    } catch {
        Write-Host "  ✗ Gateway health check failed" -ForegroundColor Red
    }

    Write-Host ""
    Write-Host "  ─────────────────────────────────────────────────" -ForegroundColor DarkGray
    Write-Host "  API Gateway:   http://localhost:8000/api/v1" -ForegroundColor White
    Write-Host "  Auth Service:  http://localhost:8001/api/v1/auth" -ForegroundColor White
    Write-Host "  User Service:  http://localhost:8002/api/v1/users" -ForegroundColor White
    Write-Host "  Health Check:  http://localhost:8000/api/health" -ForegroundColor White
    Write-Host "  ─────────────────────────────────────────────────" -ForegroundColor DarkGray
    Write-Host ""
    Write-Host "  Test credentials:" -ForegroundColor Yellow
    Write-Host "  admin@example.com    / Admin@123456    (super-admin)" -ForegroundColor DarkGray
    Write-Host "  manager@example.com  / Manager@123     (manager)" -ForegroundColor DarkGray
    Write-Host "  viewer@example.com   / Viewer@1234     (viewer)" -ForegroundColor DarkGray
    Write-Host ""
}

# ── Main ──────────────────────────────────────────────────────────────────────
if ($Stop)    { Stop-Services; exit 0 }
if ($Status)  { Get-ServiceStatus; exit 0 }
if ($Migrate) { Run-Migrations; exit 0 }
if ($Seed)    { Run-Seeders; exit 0 }

# Default: start all services
if ($Migrate -or (Read-Host "Run migrations first? [y/N]") -eq 'y') { Run-Migrations }
Start-Services
