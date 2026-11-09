<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CUST-3: the people attached to an account, with or without a login. */
class ContactController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);

        return $this->data(Contact::allTenants()->where('organization_id', $org->id)
            ->orderByDesc('is_primary')->orderBy('role')->orderBy('name')->get());
    }

    public function store(Request $request, string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);
        $data = $this->rules($request);

        $contact = Contact::create($data + ['organization_id' => $org->id]);
        $this->afterWrite($org, $contact, $request, 'customer.contact.created', "Contact {$contact->name} added as {$contact->role}.");

        return $this->data($contact, 201);
    }

    public function update(Request $request, string $id)
    {
        $contact = Contact::allTenants()->findOrFail($id);
        $org = Organization::findOrFail($contact->organization_id);
        $contact->fill($this->rules($request, $contact->id))->save();
        $this->afterWrite($org, $contact, $request, 'customer.contact.updated', "Contact {$contact->name} updated.");

        return $this->data($contact);
    }

    public function destroy(Request $request, string $id)
    {
        $contact = Contact::allTenants()->findOrFail($id);
        $org = Organization::findOrFail($contact->organization_id);
        $name = $contact->name;
        $contact->delete();
        app(AuditLogger::class)->log('customer.contact.deleted', 'contact', $id, ['name' => $name]);
        $this->customers->record($org, 'contact_removed', "Contact {$name} removed.", [], $request->user()?->id);

        return response()->noContent();
    }

    private function rules(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(Contact::ROLES)],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function afterWrite(Organization $org, Contact $contact, Request $request, string $action, string $summary): void
    {
        // One primary contact per account: setting a new one clears the old,
        // rather than leaving two rows that both claim to be it.
        if ($contact->is_primary) {
            Contact::allTenants()->where('organization_id', $org->id)
                ->where('id', '!=', $contact->id)->update(['is_primary' => false]);
        }
        app(AuditLogger::class)->log($action, 'contact', $contact->id, ['organization_id' => $org->id]);
        $this->customers->record($org, 'contact_changed', $summary, ['contact_id' => $contact->id], $request->user()?->id);
    }
}
