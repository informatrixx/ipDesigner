<?php
declare(strict_types=1);

namespace IpDesigner\Http;

function detectBasePath(string $uri): string
{
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if (preg_match('#^(/codex/[^/]+)(?:/|$)#', $path, $matches)) {
        return rtrim($matches[1], '/');
    }
    return '';
}

function basePath(): string
{
    $configured = getenv('IPDESIGNER_BASE_PATH');
    if (is_string($configured) && $configured !== '') {
        return rtrim('/' . trim($configured, '/'), '/');
    }
    return detectBasePath($_SERVER['REQUEST_URI'] ?? '/');
}

function routePath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = basePath();
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base));
    }
    return $path === '' ? '/' : $path;
}

function url(string $path): string
{
    if ($path === '') $path = '/';
    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return basePath() . $path;
}
