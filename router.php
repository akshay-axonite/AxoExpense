<?php
/**
 * Axonite Expense Tracking System - Local PHP Development Server Router
 * 
 * Used when running with PHP's built-in web server:
 * php -S 127.0.0.1:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 1. Static file check (CSS, JS, images, fonts, icons, storage)
$filePath = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'js') {
        header('Content-Type: application/javascript; charset=utf-8');
    } elseif ($ext === 'css') {
        header('Content-Type: text/css; charset=utf-8');
    } elseif ($ext === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    }
    return false;
}

// 2. Route all API calls to api/index.php
if (preg_match('#^/api/#', $uri)) {
    require __DIR__ . '/api/index.php';
    exit;
}

// 3. Serve server_settings.js
if (preg_match('#server_settings\.js$#', $uri)) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile(__DIR__ . '/server_settings.js');
    exit;
}

// 4. Mobile layout
if (preg_match('#^/mobile#', $uri)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/mobile.html');
    exit;
}

// 5. Default Desktop SPA
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/desktop.html');
