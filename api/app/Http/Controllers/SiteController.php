<?php

namespace App\Http\Controllers;

use App\Models\SalesLead;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;

/** SITE: the public marketing website's one write: "Contact sales". */
class SiteController extends Controller
{
    public function contact(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:190',
            'company' => 'nullable|string|max:160',
            'budget' => 'nullable|string|max:60',
            'message' => 'nullable|string|max:5000',
            'website' => 'nullable|string|max:0', // honeypot: people never fill it in
            'captcha' => 'nullable|string|max:4096',
        ]);
        app(\App\Services\Auth\CaptchaService::class)->check($v['captcha'] ?? null, 'contact');
        $lead = SalesLead::create(collect($v)->only(['name', 'email', 'company', 'budget', 'message'])->all() + ['ip' => $request->ip()]);
        // Staff hear about it; a failing mail transport never loses the lead.
        rescue(fn () => app(EmailTemplateService::class)->notifyStaff('sales_lead', [
            'lead_name' => $lead->name, 'lead_email' => $lead->email, 'lead_company' => (string) $lead->company,
            'lead_budget' => (string) $lead->budget, 'lead_message' => (string) $lead->message,
            'leads_url' => rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/admin/leads',
        ]), null, report: true);

        return $this->data(['received' => true], 201);
    }
}
