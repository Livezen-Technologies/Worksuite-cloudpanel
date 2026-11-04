<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;

/** Settings → Email templates (SRS EML-1..5). */
class EmailTemplateController extends Controller
{
    public function __construct(private EmailTemplateService $emails) {}

    public function index()
    {
        $groups = config('email_templates.groups');

        return $this->data([
            'brand' => $this->emails->brand(),
            'groups' => collect($groups)->map(fn ($g, $id) => [
                'id' => $id, 'name' => $g['name'], 'mandatory' => $g['mandatory'],
                'templates' => collect(config('email_templates.templates'))->filter(fn ($t) => $t['group'] === $id)
                    ->map(fn ($t, $key) => array_intersect_key($this->emails->resolved($key), array_flip(['key', 'name', 'subject', 'enabled', 'mandatory', 'customised'])))->values(),
            ])->values(),
        ]);
    }

    public function show(string $key)
    {
        return $this->data($this->emails->resolved($key) + ['sample' => $this->emails->sample($key)]);
    }

    public function update(Request $request, string $key)
    {
        $rules = collect(EmailTemplateService::FIELDS)->mapWithKeys(fn ($f) => [$f => 'sometimes|nullable|string|max:'.($f === 'body' ? 5000 : 500)])->all();
        $data = $request->validate($rules + ['enabled' => 'sometimes|boolean']);
        foreach (['subject', 'heading', 'body'] as $required) {
            if (array_key_exists($required, $data) && trim((string) $data[$required]) === '') {
                $data[$required] = null; // empty falls back to the default
            }
        }
        $request->attributes->set('audit_skip', true);

        return $this->data($this->emails->save($key, $data, $request->user()->id));
    }

    public function reset(Request $request, string $key)
    {
        $request->attributes->set('audit_skip', true);

        return $this->data($this->emails->reset($key));
    }

    /** Rendered with sample values (or the ones given). */
    public function preview(Request $request, string $key)
    {
        $data = $request->validate(['vars' => 'sometimes|array', 'vars.*' => 'nullable|string|max:2000']);

        return $this->data($this->emails->render($key, ($data['vars'] ?? []) + $this->emails->sample($key), $request->user()));
    }

    public function test(Request $request, string $key)
    {
        $data = $request->validate(['to' => 'nullable|email|max:190']);
        $to = $data['to'] ?? $request->user()->email;
        try {
            // A test always sends, even for a template that is switched off.
            $mail = $this->emails->render($key, $this->emails->sample($key), $request->user());
            \Illuminate\Support\Facades\Mail::send([], [], function ($m) use ($mail, $to) {
                $m->to($to)->subject('[Test] '.$mail['subject'])->html($mail['html']);
                $m->getSymfonyMessage()->text($mail['text']);
            });
        } catch (\Throwable $e) {
            throw \App\Exceptions\ApiException::upstream('The test email could not be sent: '.\Illuminate\Support\Str::limit($e->getMessage(), 300));
        }

        return $this->data(['sent' => true, 'to' => $to]);
    }

    public function brand(Request $request)
    {
        $data = $request->validate([
            'sender_name' => 'sometimes|nullable|string|max:120', 'logo_url' => 'sometimes|nullable|url:https|max:500',
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'], 'footer' => 'sometimes|nullable|string|max:500',
        ]);
        if (array_key_exists('footer', $data)) {
            preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', (string) $data['footer'], $m);
            if ($bad = array_diff($m[1], EmailTemplateService::GLOBALS)) {
                throw \App\Exceptions\ApiException::validation(['footer' => ['Only {{platform_name}}, {{user_name}} and {{panel_url}} can be used in the footer.']]);
            }
        }
        $request->attributes->set('audit_skip', true);

        return $this->data($this->emails->saveBrand($data));
    }

    /** EML-5: the signed-in user's own choices; security and billing cannot be opted out of. */
    public function preferences(Request $request)
    {
        $user = $request->user();
        if ($request->isMethod('patch')) {
            $data = $request->validate(['opt_out' => 'present|array', 'opt_out.*' => 'string|in:'.implode(',', array_keys(config('email_templates.templates')))]);
            $optOut = array_values(array_filter(array_unique($data['opt_out']), fn ($k) => ! $this->emails->mandatory($k)));
            $user->forceFill(['email_preferences' => ['opt_out' => $optOut]])->save();
        }
        $optOut = $user->email_preferences['opt_out'] ?? [];

        return $this->data(collect(config('email_templates.templates'))->map(fn ($t, $key) => [
            'key' => $key, 'name' => $t['name'], 'group' => $t['group'], 'mandatory' => $this->emails->mandatory($key), 'enabled' => ! in_array($key, $optOut, true),
        ])->values());
    }
}
