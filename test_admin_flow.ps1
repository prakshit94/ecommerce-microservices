$ErrorActionPreference = "Stop"
$gatewayUrl = "http://127.0.0.1:8000/api"
$testEmail = "admin@example.com"
$testPassword = "admin123"

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
        $streamReader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $errBody = $streamReader.ReadToEnd()
        Write-Host "Error Response Body: $errBody" -ForegroundColor Yellow
        throw $_
    }
}

Write-Host "Starting Admin API Flow Tests...`n" -ForegroundColor Cyan

# --- 1. LOGIN ---
Write-Host "--- TEST 1: Login as Super Admin ---"
$loginResp = Request "Post" "$gatewayUrl/auth/login" $null @{
    email = $testEmail
    password = $testPassword
}
$token = $loginResp.token
$headers = @{ "Authorization" = "Bearer $token" }
Write-Host "Login successful. Admin Token acquired."

# --- 2. LIST USERS ---
Write-Host "`n--- TEST 2: List Users (Requires user-list permission) ---"
$users = Request "Get" "$gatewayUrl/users" $headers $null
Write-Host "Success: Found $($users.Count) users."

# --- 3. CREATE ROLE ---
Write-Host "`n--- TEST 3: Create NEW Role (Requires role-create permission) ---"
$newRoleName = "Moderator-$(Get-Random)"
$role = Request "Post" "$gatewayUrl/roles" $headers @{ name = $newRoleName }
Write-Host "Success: Created new role: $($role.name) (ID: $($role.id))"

# --- 4. LIST PERMISSIONS ---
Write-Host "`n--- TEST 4: List Permissions (Requires permission-list permission) ---"
$perms = Request "Get" "$gatewayUrl/permissions" $headers $null
Write-Host "Success: Found $($perms.Count) permissions."

Write-Host "`nADMIN FLOW TESTS COMPLETED SUCCESSFULLY!" -ForegroundColor Green
