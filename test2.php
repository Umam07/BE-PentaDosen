<?php
$str = file_get_contents('test-scopus3.json');
$str = mb_convert_encoding($str, 'UTF-8', 'UTF-16LE');
$json = json_decode($str, true);
print_r($json['search-results']['entry'][0] ?? null);
