param(
    [switch]$SkipSyntax,
    [switch]$SkipServiceHealth,
    [switch]$SkipProxmox,
    [switch]$SkipLifecycleDryRun
)

$ErrorActionPreference = 'Stop'

$script:Results = @()

function Add-Result {
    param(
        [string]$Name,
        [string]$Status,
        [string]$Message
    )

    $script:Results += [PSCustomObject]@{
        Check   = $Name
        Status  = $Status
        Message = $Message
    }
}

function Read-EnvFile {
    param([string]$Path)

    $map = @{}
    if (-not (Test-Path $Path)) {
        return $map
    }

    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '' -or $line.StartsWith('#')) { return }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { return }
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim()
        $map[$key] = $val
    }

    return $map
}

function Get-ConfigValue {
    param(
        [hashtable]$Map,
        [string]$Key
    )

    $envVal = [Environment]::GetEnvironmentVariable($Key)
    if ($envVal) { return $envVal }
    if ($Map.ContainsKey($Key)) { return $Map[$Key] }
    return ''
}

function Assert-Keys {
    param(
        [string]$Name,
        [hashtable]$Map,
        [string[]]$Keys
    )

    $missing = @()
    foreach ($k in $Keys) {
        if (-not (Get-ConfigValue -Map $Map -Key $k)) {
            $missing += $k
        }
    }

    if ($missing.Count -eq 0) {
        Add-Result -Name $Name -Status 'PASS' -Message 'All required values present.'
    } else {
        Add-Result -Name $Name -Status 'FAIL' -Message ("Missing: " + ($missing -join ', '))
    }
}

function Invoke-JsonGet {
    param(
        [string]$Url,
        [hashtable]$Headers
    )

    $response = Invoke-WebRequest -Uri $Url -Headers $Headers -Method GET -TimeoutSec 20 -UseBasicParsing
    return ConvertFrom-Json $response.Content
}

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$moduleDir = Join-Path $root 'modules\servers\makeproxmox'
$provisionerDir = Join-Path $root 'provisioner'
$hookDir = Join-Path $provisionerDir 'deploy-hook'

Write-Host "Running Make provisioning preflight..." -ForegroundColor Cyan
Write-Host "Root: $root"

if ((Test-Path $moduleDir) -and (Test-Path $provisionerDir) -and (Test-Path $hookDir)) {
    Add-Result -Name 'Repository structure' -Status 'PASS' -Message 'Module + provisioner + deploy-hook directories found.'
} else {
    Add-Result -Name 'Repository structure' -Status 'FAIL' -Message 'Expected directories missing.'
}

$requiredFiles = @(
    (Join-Path $moduleDir 'makeproxmox.php'),
    (Join-Path $moduleDir 'callback.php'),
    (Join-Path $provisionerDir 'src\server.js'),
    (Join-Path $hookDir 'src\server.js')
)

$missingFiles = @($requiredFiles | Where-Object { -not (Test-Path $_) })
if ($missingFiles.Count -eq 0) {
    Add-Result -Name 'Core files present' -Status 'PASS' -Message 'All core module/service files found.'
} else {
    Add-Result -Name 'Core files present' -Status 'FAIL' -Message ("Missing files: " + ($missingFiles -join '; '))
}

$provEnv = Read-EnvFile -Path (Join-Path $provisionerDir '.env')
$hookEnv = Read-EnvFile -Path (Join-Path $hookDir '.env')

Assert-Keys -Name 'Provisioner required env' -Map $provEnv -Keys @(
    'API_BEARER_TOKEN',
    'WHMCS_CALLBACK_URL',
    'WHMCS_CALLBACK_BEARER_TOKEN',
    'PROXMOX_API_URL',
    'PROXMOX_API_TOKEN_ID',
    'PROXMOX_API_TOKEN_SECRET',
    'PROXMOX_LXC_TEMPLATE_VMID',
    'MAKE_PUBLIC_BASE_DOMAIN'
)

Assert-Keys -Name 'Deploy-hook required env' -Map $hookEnv -Keys @('HOOK_BEARER_TOKEN')

if (-not $SkipSyntax) {
    try {
        Get-ChildItem -Path $provisionerDir -Recurse -Filter *.js |
            Where-Object { $_.FullName -notmatch '\\node_modules\\' } |
            ForEach-Object { node --check $_.FullName }
        node --check (Join-Path $provisionerDir 'scripts\staging-lifecycle.mjs')
        Add-Result -Name 'JavaScript syntax' -Status 'PASS' -Message 'node --check passed.'
    } catch {
        Add-Result -Name 'JavaScript syntax' -Status 'FAIL' -Message $_.Exception.Message
    }

    try {
        Get-ChildItem -Path $moduleDir -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName | Out-Null }
        Add-Result -Name 'PHP lint' -Status 'PASS' -Message 'php -l passed.'
    } catch {
        Add-Result -Name 'PHP lint' -Status 'FAIL' -Message $_.Exception.Message
    }
}

if (-not $SkipServiceHealth) {
    $provApi = Get-ConfigValue -Map $provEnv -Key 'PROVISIONER_API_URL'
    if (-not $provApi) {
        $provHost = Get-ConfigValue -Map $provEnv -Key 'HOST'
        $provPort = Get-ConfigValue -Map $provEnv -Key 'PORT'
        if (-not $provHost) { $provHost = '127.0.0.1' }
        if (-not $provPort) { $provPort = '8080' }
        if ($provHost -eq '0.0.0.0') { $provHost = '127.0.0.1' }
        $provApi = "http://$provHost`:$provPort"
    }

    try {
        $ping = Invoke-WebRequest -Uri ("$($provApi.TrimEnd('/'))/v1/ping") -Method GET -TimeoutSec 10 -UseBasicParsing
        if ($ping.StatusCode -ge 200 -and $ping.StatusCode -lt 300) {
            Add-Result -Name 'Provisioner health' -Status 'PASS' -Message "Reachable at $provApi"
        } else {
            Add-Result -Name 'Provisioner health' -Status 'WARN' -Message "Unexpected status code: $($ping.StatusCode)"
        }
    } catch {
        Add-Result -Name 'Provisioner health' -Status 'WARN' -Message 'Provisioner not reachable. Start service to run live checks.'
    }

    $hookHost = Get-ConfigValue -Map $hookEnv -Key 'HOST'
    $hookPort = Get-ConfigValue -Map $hookEnv -Key 'PORT'
    if (-not $hookHost) { $hookHost = '127.0.0.1' }
    if (-not $hookPort) { $hookPort = '8090' }
    if ($hookHost -eq '0.0.0.0') { $hookHost = '127.0.0.1' }
    $hookUrl = "http://$hookHost`:$hookPort"

    try {
        $h = Invoke-WebRequest -Uri ("$($hookUrl.TrimEnd('/'))/health") -Method GET -TimeoutSec 10 -UseBasicParsing
        if ($h.StatusCode -ge 200 -and $h.StatusCode -lt 300) {
            Add-Result -Name 'Deploy-hook health' -Status 'PASS' -Message "Reachable at $hookUrl"
        } else {
            Add-Result -Name 'Deploy-hook health' -Status 'WARN' -Message "Unexpected status code: $($h.StatusCode)"
        }
    } catch {
        Add-Result -Name 'Deploy-hook health' -Status 'WARN' -Message 'Deploy-hook not reachable. Start service to run live checks.'
    }
}

if (-not $SkipProxmox) {
    try {
        $proxmoxUrl = (Get-ConfigValue -Map $provEnv -Key 'PROXMOX_API_URL').TrimEnd('/')
        $tokenId = Get-ConfigValue -Map $provEnv -Key 'PROXMOX_API_TOKEN_ID'
        $tokenSecret = Get-ConfigValue -Map $provEnv -Key 'PROXMOX_API_TOKEN_SECRET'
        $defaultNode = Get-ConfigValue -Map $provEnv -Key 'PROXMOX_NODE_DEFAULT'
        if (-not $defaultNode) { $defaultNode = 'pve-node-01' }

        $tlsInsecure = (Get-ConfigValue -Map $provEnv -Key 'PROXMOX_TLS_INSECURE')
        if ($tlsInsecure -and $tlsInsecure.ToLower() -eq 'true') {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        }

        if (-not $proxmoxUrl -or -not $tokenId -or -not $tokenSecret) {
            Add-Result -Name 'Proxmox API auth' -Status 'FAIL' -Message 'PROXMOX_API_URL/TOKEN_ID/TOKEN_SECRET missing.'
        } else {
            $headers = @{ Authorization = "PVEAPIToken=$tokenId=$tokenSecret" }
            $version = Invoke-JsonGet -Url "$proxmoxUrl/version" -Headers $headers
            Add-Result -Name 'Proxmox API auth' -Status 'PASS' -Message ("Connected. Version: " + $version.data.version)

            $lxcTemplate = Get-ConfigValue -Map $provEnv -Key 'PROXMOX_LXC_TEMPLATE_VMID'
            if ($lxcTemplate) {
                try {
                    $null = Invoke-JsonGet -Url "$proxmoxUrl/nodes/$defaultNode/lxc/$lxcTemplate/config" -Headers $headers
                    Add-Result -Name 'LXC template check' -Status 'PASS' -Message "Found template VMID $lxcTemplate on node $defaultNode"
                } catch {
                    Add-Result -Name 'LXC template check' -Status 'FAIL' -Message "Cannot read LXC template VMID $lxcTemplate on node $defaultNode"
                }
            }

            $kvmTemplate = Get-ConfigValue -Map $provEnv -Key 'PROXMOX_KVM_TEMPLATE_VMID'
            if ($kvmTemplate) {
                try {
                    $null = Invoke-JsonGet -Url "$proxmoxUrl/nodes/$defaultNode/qemu/$kvmTemplate/config" -Headers $headers
                    Add-Result -Name 'KVM template check' -Status 'PASS' -Message "Found template VMID $kvmTemplate on node $defaultNode"
                } catch {
                    Add-Result -Name 'KVM template check' -Status 'FAIL' -Message "Cannot read KVM template VMID $kvmTemplate on node $defaultNode"
                }
            } else {
                Add-Result -Name 'KVM template check' -Status 'WARN' -Message 'PROXMOX_KVM_TEMPLATE_VMID not set (required for enterprise/qemu).'
            }
        }
    } catch {
        Add-Result -Name 'Proxmox checks' -Status 'FAIL' -Message $_.Exception.Message
    }
}

if (-not $SkipLifecycleDryRun) {
    try {
        $old = $env:TEST_DRY_RUN
        $env:TEST_DRY_RUN = 'true'
        Push-Location $provisionerDir
        node scripts/staging-lifecycle.mjs | Out-Null
        Pop-Location
        $env:TEST_DRY_RUN = $old
        Add-Result -Name 'Lifecycle dry-run script' -Status 'PASS' -Message 'staging-lifecycle.mjs dry-run passed.'
    } catch {
        if (Get-Location) { Pop-Location }
        Add-Result -Name 'Lifecycle dry-run script' -Status 'FAIL' -Message $_.Exception.Message
    }
}

$resultsTable = $script:Results | Select-Object Check, Status, Message
$resultsTable | Format-Table -AutoSize

$pass = ($script:Results | Where-Object { $_.Status -eq 'PASS' }).Count
$warn = ($script:Results | Where-Object { $_.Status -eq 'WARN' }).Count
$fail = ($script:Results | Where-Object { $_.Status -eq 'FAIL' }).Count

Write-Host "`nSummary: PASS=$pass WARN=$warn FAIL=$fail"

if ($fail -gt 0) {
    exit 1
}

exit 0
