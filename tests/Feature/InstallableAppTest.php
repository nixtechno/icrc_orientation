<?php

namespace Tests\Feature;

use App\Models\{Programme, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_link_the_app_manifest_and_the_portal_offers_to_install(): void
    {
        app(TimetableImporter::class)->run();
        $this->get('/')->assertOk()->assertSee('rel="manifest"', false)->assertSee('apple-mobile-web-app-capable', false)->assertSee('pwa.js', false)->assertDontSee('data-install-app', false);

        $staff = User::factory()->create(['role' => 'staff', 'active' => true]); $staff->programmes()->attach(Programme::first()->id);
        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertSee('data-install-app', false);

        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['standalone', '/dashboard'], [$manifest['display'], $manifest['start_url']]);
        foreach ($manifest['icons'] as $icon) $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        $this->assertFileExists(public_path('offline.html'));
    }
}
