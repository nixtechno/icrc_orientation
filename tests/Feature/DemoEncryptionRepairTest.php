<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoEncryptionRepair;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class DemoEncryptionRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_profiles_are_recovered_without_losing_data_and_repeat_repair_is_a_noop(): void
    {
        Storage::fake('local');
        $old = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $current = app('encrypter');
        $data = ['first_name' => 'Demo', 'surname' => 'Tester', 'programme_information' => 'Preserve my updated profile'];
        $user = User::factory()->create(['email' => 'repair@icrc-demo.test']);
        $ciphertext = $old->encryptString(json_encode($data));
        DB::table('users')->where('id', $user->id)->update(['profile' => $ciphertext]);
        $repair = app(DemoEncryptionRepair::class);
        $this->assertSame(1, $repair->run(DB::connection(), $old, $current));
        $this->assertSame($data, $user->fresh()->profile);
        $this->assertSame(0, $repair->run(DB::connection(), $old, $current));
        $files = Storage::disk('local')->files('demo/backups');
        $this->assertCount(1, $files);
        $backup = Storage::disk('local')->get($files[0]);
        $this->assertStringNotContainsString('Preserve my updated profile', $backup);
        $this->assertSame($ciphertext, json_decode($backup, true)[0]['profile']);
    }

    public function test_unknown_encryption_keys_do_not_erase_or_partially_modify_profiles(): void
    {
        Storage::fake('local');
        $old = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $first = User::factory()->create(['email' => 'first@icrc-demo.test']);
        $second = User::factory()->create(['email' => 'second@icrc-demo.test']);
        $original = $old->encryptString('{"first_name":"Demo"}');
        DB::table('users')->where('id', $first->id)->update(['profile' => $original]);
        DB::table('users')->where('id', $second->id)->update(['profile' => 'unknown ciphertext']);
        try {
            app(DemoEncryptionRepair::class)->run(DB::connection(), $old, app('encrypter'));
            $this->fail('Unrecoverable profiles should stop the repair.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No profiles were changed', $e->getMessage());
        }
        $this->assertSame($original, DB::table('users')->where('id', $first->id)->value('profile'));
        $this->assertSame('unknown ciphertext', DB::table('users')->where('id', $second->id)->value('profile'));
        $this->assertCount(0, Storage::disk('local')->files('demo/backups'));
    }

    public function test_repair_rejects_the_main_database(): void
    {
        $connection = DB::connection(); $original = $connection->getDatabaseName();
        $connection->setDatabaseName('icrc_orientation');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('restricted to isolated');
            app(DemoEncryptionRepair::class)->run($connection, app('encrypter'), app('encrypter'));
        } finally {
            $connection->setDatabaseName($original);
        }
    }
}
