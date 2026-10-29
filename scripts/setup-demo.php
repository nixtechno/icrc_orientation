<?php

// Creates an isolated local demo database; never migrates or seeds the main database.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

try {
    if (! app()->environment('local')) throw new RuntimeException('Demo setup is available only in a local environment.');
    $options = getopt('', ['staff:']);
    $staff = filter_var($options['staff'] ?? 200, FILTER_VALIDATE_INT);
    if ($staff === false || $staff < 10 || $staff > 5000) throw new RuntimeException('Use --staff=10 through --staff=5000.');
    if (config('database.default') !== 'mysql') throw new RuntimeException('Demo setup requires the local MySQL connection.');
    if (app()->configurationIsCached()) throw new RuntimeException('Run php artisan config:clear before setting up the isolated demo.');
    $database = 'icrc_orientation_demo';
    DB::statement('CREATE DATABASE IF NOT EXISTS `icrc_orientation_demo` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config = config('database.connections.mysql');
    $path = base_path('.env.demo');
    if (! file_exists($path)) {
        $env = file_get_contents(base_path('.env.example'));
        $values = ['APP_NAME' => 'ICRC Demo', 'APP_ENV' => 'demo', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://127.0.0.1:8001', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => '', 'MAIL_MAILER' => 'log', 'QUEUE_CONNECTION' => 'sync', 'SESSION_COOKIE' => 'icrc_demo_session', 'CACHE_PREFIX' => 'icrc_demo_', 'ATTENDANCE_ORIGIN' => 'http://127.0.0.1:8001', 'ATTENDANCE_RP_ID' => '127.0.0.1'];
        foreach ($values as $key => $value) {
            $line = $key.'="'.str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], (string) $value).'"';
            $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env) ? preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', fn () => $line, $env) : $env."\n".$line;
        }
        file_put_contents($path, $env."\n");
    }
    $demoEnv = \Dotenv\Dotenv::parse(file_get_contents($path));
    if (($demoEnv['DB_DATABASE'] ?? null) !== $database || ($demoEnv['APP_ENV'] ?? null) !== 'demo') {
        throw new RuntimeException('.env.demo must target the local icrc_orientation_demo database.');
    }
    foreach ([['migrate', '--force'], ['icrc:seed-demo', '--staff='.(int) $staff]] as $arguments) {
        if ($arguments[0] === 'icrc:seed-demo') {
            $repairConfig = config('database.connections.mysql');
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $envKey) {
                $repairConfig[$key] = $demoEnv[$envKey] ?? $repairConfig[$key];
            }
            $repairConfig['database'] = $database;
            $repairConfig['url'] = null;
            config(['database.connections.demo_repair' => $repairConfig]);
            $decode = fn ($key) => str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            $repaired = app(\App\Services\DemoEncryptionRepair::class)->run(
                DB::connection('demo_repair'),
                new \Illuminate\Encryption\Encrypter($decode(config('app.key')), config('app.cipher')),
                new \Illuminate\Encryption\Encrypter($decode($demoEnv['APP_KEY']), config('app.cipher')),
            );
            if ($repaired) echo "Recovered {$repaired} demo profiles using the demo encryption key. Encrypted backups retained.\n";
        }
        // Symfony inherits the parent CLI's loaded dotenv values, including APP_KEY.
        // Explicitly pass this environment's values so demo writes use the same key as the demo server.
        $process = new Process([PHP_BINARY, base_path('artisan'), '--env=demo', ...$arguments], base_path(), $demoEnv);
        $process->setTimeout(600);
        $process->mustRun(fn ($type, $buffer) => print($buffer));
    }
    echo "\nStart the demo with: php artisan serve --env=demo --host=127.0.0.1 --port=8001\n";
    echo "Demo login: http://127.0.0.1:8001/login\n";
    echo "Accounts: admin@icrc-demo.test | facilitator@icrc-demo.test | staff0001@icrc-demo.test\n";
    echo "Demo-only password: ".Database\Seeders\DemoSeeder::PASSWORD."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Demo setup failed: '.$e->getMessage()."\n");
    exit(1);
}
