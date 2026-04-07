$ErrorActionPreference = "Stop"
$gatewayUrl = "http://127.0.0.1:8000/api"

# 1. Login to get token
Write-Host "--- TEST 1: Login ---"
$body = @{ email = "test2@example.com"; password = "password" }
$json = $body | ConvertTo-Json
$response = Invoke-RestMethod -Uri "$gatewayUrl/auth/login" -Method Post -Body $json -ContentType "application/json"
$token = $response.token
Write-Host "Login successful. Token received."

$headers = @{ "Authorization" = "Bearer $token" }

# 2. Get Authenticated User (/auth/me)
Write-Host "`n--- TEST 2: /auth/me ---"
try {
    $me = Invoke-RestMethod -Uri "$gatewayUrl/auth/me" -Method Get -Headers $headers
    Write-Host "Success. User: $($me.name) - Email: $($me.email)"
} catch {
    Write-Host "Error accessing /auth/me: $_"
}

# 3. Test API Gateway Proxy to userservice (/users)
Write-Host "`n--- TEST 3: /users ---"
try {
    $users = Invoke-RestMethod -Uri "$gatewayUrl/users" -Method Get -Headers $headers
    Write-Host "Success. Total Users: $($users.Count)"
} catch {
    Write-Host "Error accessing /users: $_"
}

# 4. Test API Gateway Proxy to roles (/roles)
Write-Host "`n--- TEST 4: /roles ---"
try {
    $roles = Invoke-RestMethod -Uri "$gatewayUrl/roles" -Method Get -Headers $headers
    Write-Host "Success. Total Roles: $($roles.Count)"
} catch {
    Write-Host "Error accessing /roles: $_"
}

# 5. Test API Gateway Proxy to permissions (/permissions)
Write-Host "`n--- TEST 5: /permissions ---"
try {
    $permissions = Invoke-RestMethod -Uri "$gatewayUrl/permissions" -Method Get -Headers $headers
    Write-Host "Success. Total Permissions: $($permissions.Count)"
} catch {
    Write-Host "Error accessing /permissions: $_"
}

# 6. Test Logout
Write-Host "`n--- TEST 6: /auth/logout ---"
try {
    $logout = Invoke-RestMethod -Uri "$gatewayUrl/auth/logout" -Method Post -Headers $headers
    Write-Host "Success. Message: $($logout.message)"
} catch {
    Write-Host "Error logging out: $_"
}
