<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;

echo "Testing Redis Cache...\n";
Cache::put('antigravity_check', 'CONNECTED', 10);
$value = Cache::get('antigravity_check');

if ($value === 'CONNECTED') {
    echo "SUCCESS: Redis is working and connected to Laravel!\n";
} else {
    echo "FAILURE: Could not retrieve value from Redis.\n";
}
