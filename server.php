<?php

// artisan serve otherwise serves existing public files before Laravel auth.
// Keep the framework router for all other requests; deny legacy proof URLs.
$proofUri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$proofSegments = [];
foreach (explode('/', str_replace('\\', '/', $proofUri)) as $segment) {
    if ($segment === '..') {
        array_pop($proofSegments);
    } elseif ($segment !== '' && $segment !== '.') {
        $proofSegments[] = $segment;
    }
}
$proofUri = '/' . implode('/', $proofSegments);
if (preg_match('~^/storage/proofs(?:/|$)~i', $proofUri)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit;
}
require __DIR__ . '/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
