<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'app');
}

if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', rtrim($scheme . '://' . $host, '/'));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$databaseFile = BASE_PATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
$routesFile = BASE_PATH . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';

if (!is_file($databaseFile)) {
    http_response_code(500);
    exit('Critical Error: Database bootstrap is missing at ' . htmlspecialchars($databaseFile));
}

if (!is_file($routesFile)) {
    http_response_code(500);
    exit('Critical Error: Route file is missing at ' . htmlspecialchars($routesFile));
}

try {
    require_once $databaseFile;
    require_once $routesFile;
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Internal Server Error</h1>';
    echo '<p><strong>Exception:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ' on line ' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
