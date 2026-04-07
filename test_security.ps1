$ErrorActionPreference = "Stop"
$gatewayUrl = "http://127.0.0.1:8000/api"
$userServiceUrl = "http://127.0.0.1:8002/api"
$testEmail = "sec_test_$(Get-Random)@example.com"
$testPassword = "password123"

# Helper for JSON requests
function Request($method, $url, $headers, $body) {
    $params = @{
        Uri = $url
        Method = $method
        ContentType = "application/json"
    }
    if ($headers) { $params.Headers = $headers }
    if ($body) { $params.Body = ($body | ConvertTo-Json) }
    
    try {
        return Invoke-RestMethod @params
    } catch {
        return $_.Exception.Response
    }
}

Write-Host "Starting Security Analysis Tests...`n" -ForegroundColor Cyan

# --- 1. DIRECT ACCESS TEST ---
Write-Host "--- TEST 1: Direct access to User Service (Bypass Gateway) ---"
$resp = Request "Get" "$userServiceUrl/users" $null $null
if ($resp.StatusCode -eq "Forbidden") {
    Write-Host "Success: Direct access blocked (403 Forbidden)." -ForegroundColor Green
} else {
    Write-Host "FAILURE: Direct access allowed! Status: $($resp.StatusCode)" -ForegroundColor Red
}

# --- 2. REGISTRATION & LOGIN ---
Write-Host "`n--- TEST 2: Registration & Login ---"
Request "Post" "$gatewayUrl/auth/register" $null @{
    name = "Security Test User"
    email = $testEmail
    password = $testPassword
} | Out-Null

$loginResp = Request "Post" "$gatewayUrl/auth/login" $null @{
    email = $testEmail
    password = $testPassword
}
$token = $loginResp.token
$headers = @{ "Authorization" = "Bearer $token" }
Write-Host "User logged in. Token acquired."

# --- 3. UNAUTHORIZED ROLE ACCESS ---
Write-Host "`n--- TEST 3: Accessing Roles without Permission ---"
$resp = Request "Get" "$gatewayUrl/roles" $headers $null
if ($resp.StatusCode -eq "Forbidden") {
    Write-Host "Success: Access to /roles blocked for regular user (403 Forbidden)." -ForegroundColor Green
} else {
    Write-Host "FAILURE: Regular user can access /roles! Status: $($resp.StatusCode)" -ForegroundColor Red
}

# --- 4. UNAUTHORIZED USER LIST ACCESS ---
Write-Host "`n--- TEST 4: Accessing User List without Permission ---"
$resp = Request "Get" "$gatewayUrl/users" $headers $null
if ($resp.StatusCode -eq "Forbidden") {
    Write-Host "Success: Access to /users blocked for regular user (403 Forbidden)." -ForegroundColor Green
} else {
    Write-Host "FAILURE: Regular user can access /users! Status: $($resp.StatusCode)" -ForegroundColor Red
}

Write-Host "`nSECURITY ANALYSIS COMPLETED." -ForegroundColor Cyan
