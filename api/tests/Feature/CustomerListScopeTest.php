<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Hosting customers are a staff list: a customer's owner must not see other customers. */
class CustomerListScopeTest extends TestCase
{
    public function test_customer_owners_cannot_list_or_change_customers(): void
    {
        [, $other] = $this->org('owner', 'Other Co');
        [, , $owner] = $this->org('owner', 'Mine Ltd');
        $this->api($owner)->getJson('/api/v1/customers')->assertForbidden();
        $this->api($owner)->patchJson("/api/v1/customers/{$other->id}", ['name' => 'Taken'])->assertForbidden();
        [, , $staff] = $this->org('super_admin');
        $this->assertContains('Other Co', collect($this->api($staff)->getJson('/api/v1/customers')->json('data'))->pluck('name')->all());
    }
}
