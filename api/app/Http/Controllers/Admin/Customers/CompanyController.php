<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\CustomerService;
use App\Support\Lists;
use Illuminate\Http\Request;

/**
 * CUST-4: a company is the account's business identity, which is the billing
 * profile Finance already addresses invoices to -- not a second record that
 * could disagree with it.
 */
class CompanyController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(Request $request)
    {
        $result = Lists::paginate(
            BillingProfile::allTenants()->with('organization')->whereNotNull('company_name'),
            $request,
            ['company_name', 'tax_id', 'vat_number', 'registration_number', 'email'],
            ['company_name', 'created_at', 'country'],
            ['country' => 'country', 'organization_id' => 'organization_id'],
            fn (BillingProfile $p) => $this->shape($p),
        );

        return response()->json($result);
    }

    public function show(string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $profile = BillingProfile::allTenants()->where('organization_id', $org->id)->first();

        return $this->data([
            'company' => $profile ? $this->shape($profile) : null,
            'customer' => ['id' => $org->id, 'name' => $org->name, 'status' => $org->status],
            'contacts' => \App\Models\Contact::allTenants()->where('organization_id', $org->id)
                ->orderByDesc('is_primary')->orderBy('role')->get(),
        ]);
    }

    public function update(Request $request, string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $data = $request->validate([
            'company_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:60'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:80'],
            'website' => ['sometimes', 'nullable', 'url', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:190'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        $profile = BillingProfile::allTenants()->firstOrNew(['organization_id' => $org->id]);
        $profile->organization_id = $org->id;
        $profile->fill($data)->save();

        app(AuditLogger::class)->log('customer.company.updated', 'billing_profile', $profile->id, ['fields' => array_keys($data)]);
        $this->customers->record($org, 'company_updated', 'Company details changed.', ['fields' => array_keys($data)], $request->user()?->id);

        return $this->data($this->shape($profile->fresh()));
    }

    private function shape(BillingProfile $p): array
    {
        return [
            'id' => $p->id,
            'organization_id' => $p->organization_id,
            'customer' => $p->organization?->name,
            'company_name' => $p->company_name,
            'registration_number' => $p->registration_number,
            'tax_id' => $p->tax_id,
            'vat_number' => $p->vat_number,
            'industry' => $p->industry,
            'website' => $p->website,
            'phone' => $p->phone,
            'email' => $p->email,
            'contact_name' => $p->contact_name,
            'address_line1' => $p->address_line1,
            'address_line2' => $p->address_line2,
            'city' => $p->city,
            'region' => $p->region,
            'postal_code' => $p->postal_code,
            'country' => $p->country,
            'currency' => $p->currency,
            'balance_cents' => (int) $p->balance_cents,
        ];
    }
}
