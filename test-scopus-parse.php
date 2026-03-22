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
    'query' => 'AU-ID(57190013898)',
    'count' => 10
]);

$data = $response->json();
$entries = $data['search-results']['entry'] ?? [];
$totalResults = $data['search-results']['opensearch:totalResults'] ?? 0;

echo "Total Results: $totalResults \n";
if(!empty($entries)) {
    $firstDoc = $entries[0];
    echo "Title: " . ($firstDoc['dc:title'] ?? '') . "\n";
    echo "Cited By: " . ($firstDoc['citedby-count'] ?? '') . "\n";
    echo "Author info in document: \n";
    print_r($firstDoc['dc:creator'] ?? 'null');
    echo "\n";
    print_r($firstDoc['author'] ?? 'null');
}
