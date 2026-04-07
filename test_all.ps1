$ErrorActionPreference = "Stop"
$gatewayUrl = "http://127.0.0.1:8000/api"
$testEmail = "tester_$(Get-Random)@example.com"
$testPassword = "password123"
$newPassword = "newpassword123"

# Helper for JSON requests
function Request($method, $path, $headers, $body) {
    $url = "$gatewayUrl$path"
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

Write-Host "Starting Comprehensive API Tests on $gatewayUrl`n" -ForegroundColor Cyan

# --- 1. AUTH SERVICE ---
Write-Host "--- TEST 1: Registration ---"
$regResp = Request "Post" "/auth/register" $null @{
    name = "Test User"
    email = $testEmail
    password = $testPassword
}
Write-Host "Registration successful. Message: $($regResp.message)"

Write-Host "`n--- TEST 2: Login ---"
$loginResp = Request "Post" "/auth/login" $null @{
    email = $testEmail
    password = $testPassword
}
$token = $loginResp.token
$headers = @{ "Authorization" = "Bearer $token" }
Write-Host "Login successful. Received token."

Write-Host "`n--- TEST 3: Auth Me ---"
$me = Request "Get" "/auth/me" $headers $null
Write-Host "Success. User: $($me.name) - Email: $($me.email)"
$userId = $me.id

Write-Host "`n--- TEST 4: Change Password ---"
$cpResp = Request "Post" "/auth/change-password" $headers @{
    current_password = $testPassword
    new_password = $newPassword
    new_password_confirmation = $newPassword
}
Write-Host "Success. Message: $($cpResp.message)"

# --- TEST 5: Verify New Password via Login ---
Write-Host "`n--- TEST 5: Verify Password Correctness ---"
try {
    Request "Post" "/auth/login" $null @{ email = $testEmail; password = $testPassword } | Out-Null
    Write-Host "FAILURE: Old password STILL works!" -ForegroundColor Red
} catch {
    Write-Host "Success: Old password correctly rejected."
}

try {
    $loginResp3 = Request "Post" "/auth/login" $null @{ email = $testEmail; password = $newPassword }
    $token = $loginResp3.token
    $headers = @{ "Authorization" = "Bearer $token" }
    Write-Host "Success: Login with new password works."
} catch {
    Write-Host "Error: Login with new password failed: $_" -ForegroundColor Red
    throw $_
}

# --- 2. ROLES & PERMISSIONS (REQUIRES ADMIN) ---
Write-Host "`n--- TRANSITION: Logging in as Admin for RBAC Tests ---"
$adminLoginResp = Request "Post" "/auth/login" $null @{
    email = "admin@example.com"
    password = "admin123"
}
$adminToken = $adminLoginResp.token
$adminHeaders = @{ "Authorization" = "Bearer $adminToken" }
$headers = $adminHeaders # Use admin headers for the rest of the script

Write-Host "`n--- TEST 6: Create Role ---"
$roleName = "Test-Role-$(Get-Random)"
$role = Request "Post" "/roles" $headers @{ name = $roleName }
Write-Host "Success. Created role: $($role.name) (ID: $($role.id))"
$roleId = $role.id

Write-Host "`n--- TEST 7: Get All Roles ---"
$roles = Request "Get" "/roles" $headers $null
Write-Host "Success. Found $($roles.Count) roles."

Write-Host "`n--- TEST 8: Update Role ---"
$updatedRoleName = "$roleName-Updated"
$uRole = Request "Put" "/roles/$roleId" $headers @{ name = $updatedRoleName }
Write-Host "Success. Updated role name to $($uRole.name)"

Write-Host "`n--- TEST 9: Create Permission ---"
$permName = "Test-Perm-$(Get-Random)"
$perm = Request "Post" "/permissions" $headers @{ name = $permName }
Write-Host "Success. Created permission: $($perm.name) (ID: $($perm.id))"
$permId = $perm.id

Write-Host "`n--- TEST 10: Assign Permission to Role ---"
$roleWithPerms = Request "Post" "/roles/$roleId/permissions" $headers @{ permissions = @($permName) }
Write-Host "Success. Role $($roleWithPerms.name) now has permissions: $($roleWithPerms.permissions[0].name)"

# --- 3. USER RBAC ---
Write-Host "`n--- TEST 11: Assign Role to User ---"
$userWithRoles = Request "Post" "/users/$userId/roles" $headers @{ roles = @($updatedRoleName) }
Write-Host "Success. User roles: $($userWithRoles.roles[0].name)"

Write-Host "`n--- TEST 12: Get User Roles ---"
$uRoles = Request "Get" "/users/$userId/roles" $headers $null
$uRoleNames = $uRoles | ForEach-Object { $_.name }
Write-Host "Success. Assigned Role names: $($uRoleNames -join ', ')"

Write-Host "`n--- TEST 13: Assign Permission to User ---"
$userWithPerms = Request "Post" "/users/$userId/permissions" $headers @{ permissions = @($permName) }
Write-Host "Success. User direct permissions: $($userWithPerms.permissions[0].name)"

Write-Host "`n--- TEST 14: Get User Permissions ---"
$uPerms = Request "Get" "/users/$userId/permissions" $headers $null
$uPermNames = $uPerms | ForEach-Object { $_.name }
Write-Host "Success. Assigned Permission names: $($uPermNames -join ', ')"

# --- 4. CLEANUP (OPTIONAL but good for non-destructive tests) ---
Write-Host "`n--- TEST 15: Delete Role & Permission ---"
$delRole = Request "Delete" "/roles/$roleId" $headers $null
$delPerm = Request "Delete" "/permissions/$permId" $headers $null
Write-Host "Success. Deleted test role (ID: $($delRole.id)) and permission (ID: $($delPerm.id))."

Write-Host "`n--- TEST 16: Logout ---"
$logoutResp = Request "Post" "/auth/logout" $headers $null
Write-Host "Success. Message: $($logoutResp.message)"

Write-Host "`nALL TESTS COMPLETED SUCCESSFULLY!" -ForegroundColor Green
