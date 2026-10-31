<?php

namespace Tests\Feature;

use App\Services\PublicPagesService;
use Tests\TestCase;

/** PUB-5/6: the blocked page helps the visitor, and public pages never show the old product name. */
class PublicPageBrandTest extends TestCase
{
    public function test_the_blocked_page_is_branded_and_helpful(): void
    {
        config(['app.name' => 'Docker Manager Portal']);
        $html = app(PublicPagesService::class)->render('ban');

        $this->assertStringNotContainsString('Docker Manager Portal', $html);
        $this->assertStringContainsString('Hosted on <b>Livezen Cloud</b>', $html);
        $this->assertStringContainsString('What you can do', $html);
        $this->assertStringContainsString('>Try again</a>', $html);
        $this->assertStringNotContainsString('{{', $html, 'bouncer pages are Go templates; no stray template actions');
    }

    public function test_hosted_by_can_be_changed(): void
    {
        config(['platform.hosted_by' => 'Acme Hosting']);
        $this->assertStringContainsString('Hosted on <b>Acme Hosting</b>', app(PublicPagesService::class)->render('maintenance'));
    }
}
