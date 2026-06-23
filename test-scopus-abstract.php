<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$apiKey = config('services.scopus.key');
$res = Illuminate\Support\Facades\Http::withHeaders([
    'X-ELS-APIKey' => $apiKey,
    'Accept' => 'application/json'
])->get('https://api.elsevier.com/content/abstract/scopus_id/85194411681');
file_put_contents('test-abstract.json', json_encode([
    'status' => $res->status(),
    'body' => $res->json()
], JSON_PRETTY_PRINT));
echo "Done\n";
