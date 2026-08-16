<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . match ($extension) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        default => 'application/octet-stream',
    });
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    return;
}
if (str_starts_with($path, '/api')) require __DIR__ . '/public/api.php';
else require __DIR__ . '/public/index.php';
