<?php
declare(strict_types=1);

$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$storagePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage');
$requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));

if (preg_match('~(?:^|[\\\\/])storage(?:[\\\\/]|$)~i', $requestPath) === 1
    || ($storagePath !== false && $requestedFile !== false
        && str_starts_with(strtolower($requestedFile), strtolower($storagePath . DIRECTORY_SEPARATOR)))) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$publicFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));
if ($publicFile !== false && str_starts_with($publicFile, __DIR__ . DIRECTORY_SEPARATOR) && is_file($publicFile)) {
    return false;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
