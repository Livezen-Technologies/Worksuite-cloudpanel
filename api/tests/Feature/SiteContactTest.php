<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use Tests\TestCase;

/** SITE: the marketing website's "Contact sales" form. */
class SiteContactTest extends TestCase
{
    public function test_a_visitor_contacts_sales_and_staff_see_the_lead(): void
    {
        $this->postJson('/api/v1/site/contact', ['name' => 'Nimal Perera', 'email' => 'nimal@acme.example', 'company' => 'Acme Lanka', 'budget' => '$1,000 – $5,000', 'message' => 'Moving 40 sites.'])
            ->assertCreated()->assertJsonPath('data.received', true);
        $this->assertSame('Acme Lanka', SalesLead::sole()->company);

        // Bots that fill the hidden field are refused; bad input too.
        $this->postJson('/api/v1/site/contact', ['name' => 'Bot', 'email' => 'bot@spam.example', 'website' => 'http://spam'])->assertStatus(422);
        $this->postJson('/api/v1/site/contact', ['name' => 'X', 'email' => 'not-an-email'])->assertStatus(422);
        $this->assertSame(1, SalesLead::count());

        [, , $staff] = $this->org('super_admin');
        $leads = $this->api($staff)->getJson('/api/v1/sales-leads')->assertOk()->json('data');
        $this->assertSame('nimal@acme.example', $leads[0]['email']);
        $this->api($staff)->postJson("/api/v1/sales-leads/{$leads[0]['id']}/handled")->assertNoContent();
        $this->assertNotNull(SalesLead::sole()->handled_at);

        [, , $owner] = $this->org();
        $this->api($owner)->getJson('/api/v1/sales-leads')->assertForbidden();
    }

    public function test_the_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/site/contact', ['name' => "Person {$i}", 'email' => "p{$i}@acme.example"])->assertCreated();
        }
        $this->postJson('/api/v1/site/contact', ['name' => 'Person 6', 'email' => 'p6@acme.example'])->assertStatus(429);
    }
}
