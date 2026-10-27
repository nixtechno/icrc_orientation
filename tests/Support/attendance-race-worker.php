<?php

use App\Models\User;
use App\Services\AttendanceSecurity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Test-only worker. It never connects to the application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'icrc_orientation_testing', 'attendance.origin' => 'https://orientation.example.test', 'attendance.rp_id' => 'orientation.example.test']);
DB::purge('mysql');
$fixture = json_decode(file_get_contents($argv[1]), true);
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($fixture['now'], 'Africa/Lagos'));
$user = User::findOrFail($fixture['user']);
Auth::setUser($user);
$request = Request::create('/attendance/verification/in/verify', 'POST', $fixture['payload']);
$session = new Store('race', new ArraySessionHandler(120));
$session->setId($fixture['session']);
$session->start();
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $user);
$app->instance('request', $request);
while (microtime(true) < $fixture['start']) {
    usleep(1000);
}
try {
    app(AttendanceSecurity::class)->finish($request, 'in');
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
