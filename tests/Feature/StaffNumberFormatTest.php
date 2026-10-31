<?php

namespace Tests\Feature;

use App\Models\{Badge, Programme, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_scodex_numbers_are_rewritten_to_the_icrc_format_and_back(): void
    {
        app(TimetableImporter::class)->run();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $staff = collect(['SCODEX2026001', 'SCODEX2026042', 'SCODEX2026120', 'TEST-0001', null])->map(function ($number) {
            $user = User::factory()->create(['role' => 'staff', 'active' => true, 'staff_number' => $number]);
            $user->programmes()->attach(Programme::first()->id);
            return $user;
        });
        $this->actingAs($admin)->post('/badges/generate', ['subjects' => ['u:'.$staff[1]->id]])->assertRedirect();
        $migration = require database_path('migrations/2026_11_01_210000_reformat_scodex_staff_numbers.php');

        $migration->up();
        $this->assertSame(['SCODEX/ICRC/2026/01', 'SCODEX/ICRC/2026/42', 'SCODEX/ICRC/2026/120', 'TEST-0001', null], $staff->map(fn ($u) => $u->fresh()->staff_number)->all());
        $badge = Badge::where('user_id', $staff[1]->id)->firstOrFail();
        $this->assertSame('SCODEX/ICRC/2026/42', $badge->snapshot['staff_number']);
        $this->get('/badges/'.$badge->slug.'/edit')->assertSee('<div class="badge-staff-id">SCODEX/ICRC/2026/42</div>', false);

        $migration->up();
        $this->assertSame('SCODEX/ICRC/2026/01', $staff[0]->fresh()->staff_number);
        $migration->down();
        $this->assertSame(['SCODEX2026001', 'SCODEX2026042', 'SCODEX2026120'], $staff->take(3)->map(fn ($u) => $u->fresh()->staff_number)->all());
    }
}
