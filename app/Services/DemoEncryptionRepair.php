<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DemoEncryptionRepair
{
    public function run(Connection $connection, Encrypter $old, Encrypter $demo): int
    {
        if (! app()->environment('local', 'demo', 'testing') || ! preg_match('/_(demo|testing)$/', $connection->getDatabaseName())) {
            throw new RuntimeException('Encryption repair is restricted to isolated demo/testing databases.');
        }
        if ($connection->table('users')->where('email', 'not like', '%@icrc-demo.test')->exists()) {
            throw new RuntimeException('Encryption repair refused: non-demo accounts exist.');
        }
        return $connection->transaction(function () use ($connection, $old, $demo) {
            $updates = []; $backup = [];
            foreach ($connection->table('users')->whereNotNull('profile')->lockForUpdate()->get(['id', 'profile']) as $user) {
                try {
                    $demo->decryptString($user->profile);
                    continue;
                } catch (DecryptException $e) {
                    // Only the known original key is tried; unreadable data is never discarded.
                }
                try {
                    $plaintext = $old->decryptString($user->profile);
                } catch (DecryptException $e) {
                    throw new RuntimeException('Profile '.$user->id.' cannot be recovered with the known keys. No profiles were changed.', 0, $e);
                }
                $backup[] = ['id' => $user->id, 'profile' => $user->profile];
                $updates[] = ['id' => $user->id, 'profile' => $demo->encryptString($plaintext)];
            }
            if (! $updates) return 0;
            $path = 'demo/backups/encrypted-profiles-'.Str::uuid().'.json';
            if (! Storage::disk('local')->put($path, json_encode($backup, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not back up encrypted profiles; repair cancelled.');
            }
            foreach ($updates as $update) {
                $connection->table('users')->where('id', $update['id'])->update(['profile' => $update['profile']]);
            }
            return count($updates);
        });
    }
}
