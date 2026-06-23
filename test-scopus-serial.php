<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$apiKey = config('services.scopus.key');
$res = Illuminate\Support\Facades\Http::withHeaders([
    'X-ELS-APIKey' => $apiKey,
    'Accept' => 'application/json'
])->get('https://api.elsevier.com/content/serial/title', [
    'issn' => '1664462X'
]);
file_put_contents('test-serial.json', json_encode($res->json(), JSON_PRETTY_PRINT));
echo "Done\n";
