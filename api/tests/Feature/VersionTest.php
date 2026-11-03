<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The release number lives in VERSION; everything else must agree with it. */
class VersionTest extends TestCase
{
    private function root(string $file): string
    {
        return (string) file_get_contents(base_path('../'.$file));
    }

    public function test_version_files_and_changelog_agree(): void
    {
        $v = trim($this->root('VERSION'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $v, 'VERSION is semantic: major.minor.patch');
        $this->assertSame($v, json_decode($this->root('web/package.json'), true)['version'], 'web/package.json matches VERSION (use scripts/release.sh)');
        $changelog = $this->root('CHANGELOG.md');
        $this->assertStringContainsString('## [Unreleased]', $changelog);
        $this->assertMatchesRegularExpression('/^## \['.preg_quote($v, '/').'\] — \d{4}-\d{2}-\d{2}$/m', $changelog, "CHANGELOG.md has a dated section for {$v}");
        $this->assertSame($v, config('platform.version'));
        $this->getJson('/api/v1/auth/config')->assertOk()->assertJsonPath('data.version', $v);
    }
}
