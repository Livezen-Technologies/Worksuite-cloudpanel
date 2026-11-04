<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PartnerClient;
use App\Models\PartnerDeployment;
use App\Models\PartnerWebhook;
use Illuminate\Http\Request;

/**
 * Staff view of partner provisioning (WorkSuite Cloud deployments): clients,
 * deployments by state, failures and webhook delivery health. Platform staff
 * only — partner customers never see the panel.
 */
class PartnerController extends Controller
{
    public function overview(Request $request)
    {
        if (! $this->seesAllTenants($request)) {
            throw ApiException::forbidden('Partner provisioning is visible to platform staff only.');
        }
        $byStatus = PartnerDeployment::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return $this->data([
            'clients' => PartnerClient::orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'key_id' => $c->key_id, 'scopes' => $c->scopes, 'active' => $c->active,
                'webhook_url' => $c->webhook_url, 'last_used_at' => $c->last_used_at?->toIso8601ZuluString(),
                'deployments' => PartnerDeployment::where('partner_client_id', $c->id)->where('status', '<>', 'terminated')->count(),
            ]),
            'deployments' => collect(PartnerDeployment::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
            'recent' => PartnerDeployment::with(['account', 'client'])->latest('updated_at')->limit(25)->get()->map(fn ($d) => [
                'id' => $d->id, 'external_id' => $d->external_id, 'client' => $d->client?->name, 'account' => $d->account?->name, 'product' => $d->product,
                'workspace_name' => $d->workspace_name, 'subdomain' => $d->subdomain, 'custom_domain' => $d->custom_domain, 'status' => $d->status,
                'size' => $d->size, 'storage_gb' => $d->storage_gb, 'organization_id' => $d->organization_id, 'updated_at' => $d->updated_at?->toIso8601ZuluString(),
            ]),
            'failures' => PartnerDeployment::where('status', 'failed')->latest('updated_at')->limit(20)->get()->map(fn ($d) => [
                'id' => $d->id, 'external_id' => $d->external_id, 'product' => $d->product, 'subdomain' => $d->subdomain,
                'reason' => $d->status_reason, 'organization_id' => $d->organization_id, 'updated_at' => $d->updated_at?->toIso8601ZuluString(),
            ]),
            'webhooks' => [
                'pending' => PartnerWebhook::whereNull('delivered_at')->whereNull('failed_at')->count(),
                'failed' => PartnerWebhook::whereNotNull('failed_at')->where('created_at', '>=', now()->subDays(7))->count(),
                'recent_failures' => PartnerWebhook::whereNotNull('failed_at')->latest('failed_at')->limit(10)->get(['id', 'event', 'last_error', 'attempts', 'failed_at']),
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        if (! $this->seesAllTenants($request)) {
            throw ApiException::forbidden('Partner provisioning is managed by platform staff only.');
        }
        $data = $request->validate(['active' => 'sometimes|boolean', 'webhook_url' => 'sometimes|nullable|url|max:255', 'allowed_ips' => 'sometimes|nullable|array', 'allowed_ips.*' => 'ip']);
        $client = PartnerClient::findOrFail($id);
        $client->update($data);

        return $this->data(['id' => $client->id, 'active' => $client->active, 'webhook_url' => $client->webhook_url, 'allowed_ips' => $client->allowed_ips]);
    }
}
