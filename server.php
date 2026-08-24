<?php

/**
 * SIPETA Built-in Server Router Script
 *
 * This router script allows the built-in PHP web server (used by Tauri / Desktop)
 * to serve static assets from public/ and user storage with proper MIME types,
 * while routing all application requests to public/index.php.
 */
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = urldecode(parse_url($rawUri, PHP_URL_PATH) ?? '/');

$publicPath = __DIR__.'/public';

// 1. Handle user uploaded storage files (/storage/...)
if (str_starts_with($uri, '/storage/')) {
    $storagePath = getenv('LARAVEL_STORAGE_PATH') ?: __DIR__.'/storage';
    $relativeStorageFile = substr($uri, strlen('/storage/'));
    $publicStorageFile = rtrim($storagePath, '/').'/app/public/'.$relativeStorageFile;
    if (file_exists($publicStorageFile) && is_file($publicStorageFile)) {
        $realPublic = realpath(rtrim($storagePath, '/').'/app/public');
        $realFile = realpath($publicStorageFile);
        if ($realPublic && $realFile && str_starts_with($realFile, $realPublic)) {
            $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
            $mimes = [
                'css' => 'text/css; charset=UTF-8',
                'js' => 'application/javascript; charset=UTF-8',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'json' => 'application/json',
                'pdf' => 'application/pdf',
            ];
            $contentType = $mimes[$ext] ?? (mime_content_type($realFile) ?: 'application/octet-stream');
            header('Content-Type: '.$contentType);
            header('Content-Length: '.filesize($realFile));
            readfile($realFile);
            exit;
        }
    }
}

// 2. Handle static files in public/ with explicit MIME types if served via router
$staticFile = $publicPath.$uri;
if ($uri !== '/' && file_exists($staticFile) && is_file($staticFile)) {
    // If PHP server document root is public/, return false to let built-in server handle it
    if (isset($_SERVER['DOCUMENT_ROOT']) && realpath($_SERVER['DOCUMENT_ROOT']) === realpath($publicPath)) {
        return false;
    }

    // Otherwise, serve directly with accurate MIME types
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
    ];
    $contentType = $mimes[$ext] ?? (mime_content_type($staticFile) ?: 'application/octet-stream');
    header('Content-Type: '.$contentType);
    header('Content-Length: '.filesize($staticFile));
    readfile($staticFile);
    exit;
}

// 3. Dynamic Application Route: Pass to Laravel index.php
require_once $publicPath.'/index.php';
