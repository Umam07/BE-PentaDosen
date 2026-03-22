<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$apiKey = '390a86747a9313f87609df3ce84689c0';
$response = Illuminate\Support\Facades\Http::withHeaders([
    'X-ELS-APIKey' => $apiKey,
    'Accept' => 'application/json'
])->get("https://api.elsevier.com/content/search/scopus", [
    'query' => 'AU-ID(57190013898)'
]);

echo "STATUS: " . $response->status() . "\n";
echo "BODY: \n" . substr($response->body(), 0, 1000);
