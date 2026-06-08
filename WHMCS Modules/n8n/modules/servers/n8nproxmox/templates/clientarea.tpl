<div class="panel panel-default">
    <div class="panel-heading">n8n Instance</div>
    <div class="panel-body">
        {if $instanceUrl}
            <p><strong>Instance URL:</strong> <a href="{$instanceUrl}" target="_blank" rel="noopener">{$instanceUrl}</a></p>
        {else}
            <p>Your instance is being prepared. If this takes too long, contact support.</p>
        {/if}

        {if $externalId}
            <p><strong>External ID:</strong> {$externalId}</p>
        {/if}

        {if $provisioningStatus}
            <p><strong>Status:</strong> {$provisioningStatus}</p>
        {/if}

        {if $lastJobId}
            <p><strong>Last Job ID:</strong> {$lastJobId}</p>
        {/if}

        {if $usage.executions_used !== null}
            <hr>
            <p><strong>Usage</strong></p>
            <p>Executions this month: {$usage.executions_used}{if $usage.executions_limit} / {$usage.executions_limit}{/if}</p>
            <p>Active workflows: {$usage.active_workflows}{if $usage.active_workflow_limit} / {$usage.active_workflow_limit}{/if}</p>
            {if $usage.storage_used_gb !== null}
                <p>Storage used: {$usage.storage_used_gb} GB{if $usage.storage_limit_gb} / {$usage.storage_limit_gb} GB{/if}</p>
            {/if}
        {/if}

        {if $errorMessage}
            <hr>
            <p class="text-warning">{$errorMessage}</p>
        {/if}

        {if $lastError}
            <hr>
            <p class="text-warning">{$lastError}</p>
        {/if}
    </div>
</div>
