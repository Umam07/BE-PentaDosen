<?php
$str = file_get_contents('test-scopus100.json');
$json = json_decode($str, true);
if (isset($json['service-error'])) {
    print_r($json['service-error']);
} else {
    echo "Results: " . count($json['search-results']['entry'] ?? []) . "\n";
}
