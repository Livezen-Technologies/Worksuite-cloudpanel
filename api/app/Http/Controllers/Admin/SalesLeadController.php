<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesLead;
use Illuminate\Http\Request;

/** SITE: staff read and close "Contact sales" requests. */
class SalesLeadController extends Controller
{
    private function staff(Request $request): void
    {
        if (! $this->seesAllTenants($request)) {
            throw \App\Exceptions\ApiException::forbidden('Only platform staff can see sales leads.');
        }
    }

    public function index(Request $request)
    {
        $this->staff($request);

        return $this->data(SalesLead::latest()->limit(200)->get()->map(fn ($l) => $l->only(['id', 'name', 'email', 'company', 'budget', 'message']) + [
            'handled_at' => $l->handled_at?->toIso8601ZuluString(), 'created_at' => $l->created_at?->toIso8601ZuluString(),
        ])->values());
    }

    public function handled(Request $request, string $id)
    {
        $this->staff($request);
        SalesLead::findOrFail($id)->forceFill(['handled_at' => $request->boolean('handled', true) ? now() : null])->save();

        return response()->noContent();
    }
}
