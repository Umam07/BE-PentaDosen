<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$apiKey = config('services.scopus.key');
$res = Illuminate\Support\Facades\Http::withHeaders([
    'X-ELS-APIKey' => $apiKey,
    'Accept' => 'application/json'
])->get('https://api.elsevier.com/content/search/scopus', [
    'query' => 'AU-ID(57221764654)',
    'count' => 1,
    'view' => 'COMPLETE'
]);
file_put_contents('test-complete.json', json_encode([
    'status' => $res->status(),
    'body' => $res->json()
], JSON_PRETTY_PRINT));
echo "Done\n";
