<h2>Proxmox Service Manager</h2>

{if $errorMessage}
    <div class="alert alert-danger">{$errorMessage}</div>
{/if}

{if $successMessage}
    <div class="alert alert-success">{$successMessage}</div>
{/if}

{if $serviceFound}
    <p>Service ID: <strong>{$serviceId}</strong></p>
    <p>Type: <strong>{$meta.resource_type|default:'n/a'|escape}</strong> | Node: <strong>{$meta.node|default:'n/a'|escape}</strong> | VMID: <strong>{$meta.vmid|default:'0'|escape}</strong></p>
    <p>Current Status: <strong>{$statusText|default:'unknown'|escape}</strong></p>

    <form method="post" action="{$moduleLink|escape}&amp;serviceid={$serviceId|escape}">
        <input type="hidden" name="token" value="{$csrfToken|escape}">
        <button type="submit" name="pm_do" value="start" class="btn btn-success">Start</button>
        <button type="submit" name="pm_do" value="stop" class="btn btn-warning">Stop</button>
        <button type="submit" name="pm_do" value="reboot" class="btn btn-danger">Reboot</button>
    </form>

    <h3>Recent Activity</h3>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Action</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            {if $tasks|@count gt 0}
                {foreach from=$tasks item=task}
                    <tr>
                        <td>{$task->id}</td>
                        <td>{$task->action|escape}</td>
                        <td>{$task->status|escape}</td>
                        <td>{$task->created_at|escape}</td>
                    </tr>
                {/foreach}
            {else}
                <tr>
                    <td colspan="4">No activity logged yet.</td>
                </tr>
            {/if}
        </tbody>
    </table>
{else}
    <p>Select a valid service to view Proxmox details.</p>
    <p>Example: <code>{$moduleLink}&amp;serviceid=123</code></p>
{/if}
