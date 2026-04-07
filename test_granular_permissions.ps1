$ErrorActionPreference = "Stop"
$gatewayUrl = "http://127.0.0.1:8000/api"
$adminEmail = "admin@example.com"
$adminPassword = "admin123"

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

Write-Host "Starting Granular Permission Testing...`n" -ForegroundColor Cyan

# --- 1. LOGIN AS ADMIN ---
Write-Host "--- STEP 1: Login as Admin ---"
$loginResp = Request "Post" "$gatewayUrl/auth/login" $null @{
    email = $adminEmail
    password = $adminPassword
}
$adminToken = $loginResp.token
$adminHeaders = @{ "Authorization" = "Bearer $adminToken" }
Write-Host "Admin logged in.`n"

# --- 2. CREATE 'VIEWER' ROLE ---
Write-Host "--- STEP 2: Create 'Viewer' Role ---"
$roleResp = Request "Post" "$gatewayUrl/roles" $adminHeaders @{ name = "Viewer" }
$roleId = $roleResp.id
Write-Host "Role 'Viewer' created (ID: $roleId).`n"

# --- 3. ASSIGN 'user-list' PERMISSION TO 'VIEWER' ---
Write-Host "--- STEP 3: Assign 'user-list' to 'Viewer' ---"
Request "Post" "$gatewayUrl/roles/$roleId/permissions" $adminHeaders @{ permissions = @("user-list") } | Out-Null
Write-Host "Permission 'user-list' assigned.`n"

# --- 4. CREATE TEST USER ---
$viewerEmail = "viewer_$(Get-Random)@example.com"
$viewerPassword = "password123"
Write-Host "--- STEP 4: Create Test User ($viewerEmail) ---"
$userResp = Request "Post" "$gatewayUrl/auth/register" $null @{
    name = "Test Viewer"
    email = $viewerEmail
    password = $viewerPassword
} | Out-Null
Write-Host "Test user registered.`n"

# Get User ID for the new viewer
$usersList = Request "Get" "$gatewayUrl/users" $adminHeaders $null
$viewerUser = $usersList | Where-Object { $_.email -eq $viewerEmail }
$viewerId = $viewerUser.id

# --- 5. ASSIGN 'VIEWER' ROLE TO USER ---
Write-Host "--- STEP 5: Assign 'Viewer' Role to User ---"
Request "Post" "$gatewayUrl/users/$viewerId/roles" $adminHeaders @{ roles = @("Viewer") } | Out-Null
Write-Host "Role assigned to user.`n"

# --- 6. LOGIN AS TEST USER ---
Write-Host "--- STEP 6: Login as Test User ---"
$viewerLogin = Request "Post" "$gatewayUrl/auth/login" $null @{
    email = $viewerEmail
    password = $viewerPassword
}
$viewerToken = $viewerLogin.token
$viewerHeaders = @{ "Authorization" = "Bearer $viewerToken" }
Write-Host "Test user logged in.`n"

# --- 7. TEST 'user-list' (SHOULD SUCCEED) ---
Write-Host "--- STEP 7: Test 'user-list' (Expected: Success) ---"
$listResp = Request "Get" "$gatewayUrl/users" $viewerHeaders $null
if ($listResp.Count -gt 0) {
    Write-Host "SUCCESS: User list retrieved." -ForegroundColor Green
} else {
    Write-Host "FAILURE: User list empty or error: $($listResp.StatusCode)" -ForegroundColor Red
}

# --- 8. TEST 'user-create' (SHOULD FAIL) ---
Write-Host "`n--- STEP 8: Test 'user-create' (Expected: Forbidden 403) ---"
$createResp = Request "Post" "$gatewayUrl/users" $viewerHeaders @{
    name = "Hack Attempt"
    email = "hacker@example.com"
    password = "password123"
}
if ($createResp.StatusCode -eq "Forbidden") {
    Write-Host "SUCCESS: Access blocked as expected (403 Forbidden)." -ForegroundColor Green
} else {
    Write-Host "FAILURE: Access allowed! Status: $($createResp.StatusCode)" -ForegroundColor Red
}

Write-Host "`nGRANULAR PERMISSION TESTING COMPLETED." -ForegroundColor Cyan
