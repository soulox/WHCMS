<div class="makeproxmox-client-area">
  <h3>Managed Make Instance</h3>
  {if $instanceUrl}
    <p><strong>URL:</strong> <a href="{$instanceUrl|escape:'html'}" target="_blank" rel="noopener">{$instanceUrl|escape:'html'}</a></p>
  {else}
    <p><strong>URL:</strong> Pending provisioning</p>
  {/if}

  <p><strong>Status:</strong> {$provisioningStatus|default:'pending'|escape:'html'}</p>
  <p><strong>External ID:</strong> {$externalId|default:'-'|escape:'html'}</p>
  <p><strong>Last Job ID:</strong> {$lastJobId|default:'-'|escape:'html'}</p>

  {if $usage}
    <h4>Usage</h4>
    <ul>
      <li>Operations Used: {$usage.operations_used|default:0|escape:'html'}</li>
      <li>Operations Limit: {$usage.operations_limit|default:'unlimited'|escape:'html'}</li>
      <li>Active Scenarios: {$usage.active_scenarios|default:0|escape:'html'}</li>
      <li>Scenario Limit: {$usage.active_scenario_limit|default:'unlimited'|escape:'html'}</li>
      <li>Storage Used (GB): {$usage.storage_used_gb|default:0|escape:'html'}</li>
      <li>Storage Limit (GB): {$usage.storage_limit_gb|default:'-'|escape:'html'}</li>
    </ul>
  {/if}

  {if $lastError}
    <div class="alert alert-danger" style="margin-top:12px;">
      <strong>Last Error:</strong> {$lastError|escape:'html'}
    </div>
  {/if}

  {if $errorMessage}
    <div class="alert alert-warning" style="margin-top:12px;">
      <strong>API Warning:</strong> {$errorMessage|escape:'html'}
    </div>
  {/if}
</div>
