#Requires -Version 5.1
<#
.SYNOPSIS
    Configures the OpenMRS integration endpoint and credentials for the Self Medical Record backend.
    Credentials are NEVER printed, logged, or stored in plain text anywhere.

.DESCRIPTION
    Steps performed:
      1. Authenticate as an Admin user against the Laravel API (Sanctum token).
      2. GET current OpenMRS config (display endpoint + status only, no credential).
      3. Prompt operator for confirmation before writing.
      4. PUT new config (endpoint_url + credential + is_active).
      5. Verify PUT response.
      6. GET config again to confirm persisted values.
      7. POST /test-connection and report connected/authenticated/response_time.
      8. Logout (revoke Sanctum token) in a finally block.

.PARAMETER AppBaseUrl
    Base URL of the Laravel application WITHOUT a trailing slash and WITHOUT /api.
    Default: http://self-service-medical-record.test

.PARAMETER AdminEmail
    Email address of the Admin account used to authenticate.

.PARAMETER OpenMrsEndpointUrl
    Full base URL of the OpenMRS server including context path, e.g.:
    https://openmrs.varguard.id/openmrs

.PARAMETER OpenMrsUsername
    OpenMRS integration account username (do NOT use "admin").

.NOTES
    - Credentials are accepted via Read-Host -AsSecureString and cleared from memory after use.
    - SSL certificate validation is ALWAYS enforced; -SkipCertificateCheck is NOT supported.
    - Compatible with Windows PowerShell 5.1 and PowerShell 7+.
#>
[CmdletBinding()]
param(
    [string]$AppBaseUrl = 'http://self-service-medical-record.test',
    [Parameter(Mandatory)]
    [string]$AdminEmail,
    [Parameter(Mandatory)]
    [string]$OpenMrsEndpointUrl,
    [Parameter(Mandatory)]
    [string]$OpenMrsUsername
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Helpers -----------------------------------------------------------------

function ConvertFrom-SecureStringToPlain {
    param([System.Security.SecureString]$SecureString)
    $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecureString)
    try {
        return [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}

function Clear-PlainString {
    param([ref]$Str)
    if ($null -ne $Str.Value) {
        $Str.Value = $null
    }
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Url,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )
    $params = @{
        Method      = $Method
        Uri         = $Url
        Headers     = $Headers
        ContentType = 'application/json'
        ErrorAction = 'Stop'
    }
    if ($null -ne $Body) {
        $params['Body'] = ($Body | ConvertTo-Json -Depth 10 -Compress)
    }
    return Invoke-RestMethod @params
}

# --- State -------------------------------------------------------------------

$ApiBase     = "$AppBaseUrl/api"
$Token       = $null
$AdminPass   = $null  # SecureString - cleared in finally
$OpenMrsPass = $null  # SecureString - cleared in finally

# --- Main --------------------------------------------------------------------

try {
    Write-Host ''
    Write-Host '=== OpenMRS Configuration Script ===' -ForegroundColor Cyan
    Write-Host "App API : $ApiBase"
    Write-Host "OpenMRS : $OpenMrsEndpointUrl"
    Write-Host "Account : $OpenMrsUsername (integration)"
    Write-Host ''

    # STEP 1: Collect credentials securely
    Write-Host '[Step 1] Collecting credentials (not echoed)' -ForegroundColor Yellow
    $AdminPass   = Read-Host "  Admin password for <$AdminEmail>" -AsSecureString
    $OpenMrsPass = Read-Host "  OpenMRS password for <$OpenMrsUsername>" -AsSecureString
    Write-Host ''

    # STEP 2: Login to Laravel API
    Write-Host '[Step 2] Authenticating with Laravel API...' -ForegroundColor Yellow

    $plainAdminPass = ConvertFrom-SecureStringToPlain -SecureString $AdminPass
    try {
        $loginBody = @{ email = $AdminEmail; password = $plainAdminPass }
        $loginResp = Invoke-Api -Method POST -Url "$ApiBase/login" -Body $loginBody
    } finally {
        Clear-PlainString ([ref]$plainAdminPass)
    }

    if (-not $loginResp.success) {
        Write-Error "Login failed: $($loginResp.message)"
    }

    $Token = $loginResp.data.token
    $role  = $loginResp.data.user.role.role_name

    if ($role -ne 'ADMIN') {
        Write-Error "Authenticated user is not ADMIN (role: $role). Aborting."
    }

    Write-Host "  Logged in as: $($loginResp.data.user.email) [$role]" -ForegroundColor Green
    Write-Host ''

    $authHeaders = @{ Authorization = "Bearer $Token" }

    # STEP 3: GET current config
    Write-Host '[Step 3] Fetching current OpenMRS config...' -ForegroundColor Yellow
    try {
        $currentCfg = Invoke-Api -Method GET -Url "$ApiBase/admin/openmrs/config" -Headers $authHeaders
        if ($currentCfg.success) {
            Write-Host '  Existing config found:'
            Write-Host "    config_id   : $($currentCfg.data.config_id)"
            Write-Host "    endpoint_url: $($currentCfg.data.endpoint_url)"
            Write-Host "    is_active   : $($currentCfg.data.is_active)"
            Write-Host "    updated_at  : $($currentCfg.data.updated_at)"
        }
    } catch {
        # 404 = no config yet - that is fine
        $statusCode = $_.Exception.Response.StatusCode.value__
        if ($statusCode -eq 404) {
            Write-Host '  No existing config (table is empty). Will create a new one.' -ForegroundColor DarkGray
        } else {
            throw
        }
    }
    Write-Host ''

    # STEP 4: Confirm before writing
    Write-Host '[Step 4] About to configure:' -ForegroundColor Yellow
    Write-Host "    endpoint_url : $OpenMrsEndpointUrl"
    Write-Host "    credential   : username=$OpenMrsUsername, password=<hidden>"
    Write-Host '    is_active    : true'
    Write-Host ''
    $confirm = Read-Host 'Proceed? (Y/N)'
    if ($confirm -notmatch '^[Yy]$') {
        Write-Host 'Aborted by operator.' -ForegroundColor Red
        exit 0
    }
    Write-Host ''

    # STEP 5: PUT config
    Write-Host '[Step 5] Saving OpenMRS config (PUT)...' -ForegroundColor Yellow

    $plainOpenMrsPass = ConvertFrom-SecureStringToPlain -SecureString $OpenMrsPass
    try {
        $putBody = @{
            endpoint_url = $OpenMrsEndpointUrl
            credential   = @{
                username = $OpenMrsUsername
                password = $plainOpenMrsPass
            }
            is_active = $true
        }
        $putResp = Invoke-Api -Method PUT -Url "$ApiBase/admin/openmrs/config" -Headers $authHeaders -Body $putBody
    } finally {
        Clear-PlainString ([ref]$plainOpenMrsPass)
    }

    if (-not $putResp.success) {
        Write-Error "PUT failed: $($putResp.message)"
    }

    Write-Host '  Config saved:' -ForegroundColor Green
    Write-Host "    config_id   : $($putResp.data.config_id)"
    Write-Host "    endpoint_url: $($putResp.data.endpoint_url)"
    Write-Host "    is_active   : $($putResp.data.is_active)"
    Write-Host "    updated_at  : $($putResp.data.updated_at)"
    $savedConfigId = $putResp.data.config_id
    Write-Host ''

    # STEP 6: Verify GET after PUT
    Write-Host '[Step 6] Verifying config persisted (GET)...' -ForegroundColor Yellow
    $verifyResp = Invoke-Api -Method GET -Url "$ApiBase/admin/openmrs/config" -Headers $authHeaders
    if ($verifyResp.success -and $verifyResp.data.config_id -eq $savedConfigId -and $verifyResp.data.endpoint_url -eq $OpenMrsEndpointUrl) {
        Write-Host "  Verification PASSED - config_id=$($verifyResp.data.config_id) endpoint_url matches." -ForegroundColor Green
    } else {
        Write-Warning '  Verification WARNING: GET response may not match PUT. Manual check recommended.'
        Write-Host "    GET config_id   : $($verifyResp.data.config_id)"
        Write-Host "    GET endpoint_url: $($verifyResp.data.endpoint_url)"
    }
    Write-Host ''

    # STEP 7: Test connection
    Write-Host '[Step 7] Testing OpenMRS connection...' -ForegroundColor Yellow
    $testResp  = Invoke-Api -Method POST -Url "$ApiBase/admin/openmrs/test-connection" -Headers $authHeaders
    $connColor = if ($testResp.data.connected) { 'Green' } else { 'Red' }
    $authColor = if ($testResp.data.authenticated) { 'Green' } else { 'Red' }
    Write-Host "  connected      : $($testResp.data.connected)" -ForegroundColor $connColor
    Write-Host "  authenticated  : $($testResp.data.authenticated)" -ForegroundColor $authColor
    Write-Host "  status_code    : $($testResp.data.status_code)"
    Write-Host "  response_time  : $($testResp.data.response_time_ms) ms"
    Write-Host "  message        : $($testResp.message)"

    if ($testResp.data.authenticated) {
        Write-Host ''
        Write-Host '[OK] OpenMRS integration configured and verified successfully.' -ForegroundColor Green
    } else {
        Write-Host ''
        Write-Warning '[WARN] Connection test failed. Check endpoint URL and credentials.'
    }

} catch {
    Write-Host ''
    Write-Host "ERROR: $_" -ForegroundColor Red
    exit 1
} finally {
    # Revoke token (always)
    if ($null -ne $Token) {
        try {
            $authHeaders = @{ Authorization = "Bearer $Token" }
            Invoke-Api -Method POST -Url "$ApiBase/logout" -Headers $authHeaders | Out-Null
            Write-Host ''
            Write-Host '[Cleanup] Sanctum token revoked.' -ForegroundColor DarkGray
        } catch {
            Write-Host '[Cleanup] Warning: Could not revoke token.' -ForegroundColor DarkYellow
        }
        $Token = $null
    }

    # Clear SecureStrings from memory
    if ($null -ne $AdminPass)   { $AdminPass.Dispose();   $AdminPass = $null }
    if ($null -ne $OpenMrsPass) { $OpenMrsPass.Dispose(); $OpenMrsPass = $null }

    Write-Host '[Cleanup] Credential objects cleared from memory.' -ForegroundColor DarkGray
}