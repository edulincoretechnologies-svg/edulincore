<?php

declare(strict_types=1);

// Enable error reporting for diagnostics (turn off in production if needed)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Define Base Path (Root directory of your site)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// Define App Path
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'app');
}

// Define Base URL dynamically
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        ? 'https'
        : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', rtrim($scheme . '://' . $host, '/'));
}

// Initialize Session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Correct file targets based on your exact layout
$databaseFile = BASE_PATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
$routesFile   = BASE_PATH . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';

// Validate that Database.php exists in root/core/
if (!is_file($databaseFile)) {
    http_response_code(500);
    exit('Critical Error: Database bootstrap is missing at ' . htmlspecialchars($databaseFile));
}

// Validate that web.php exists in root/routes/
if (!is_file($routesFile)) {
    http_response_code(500);
    exit('Critical Error: Route file is missing at ' . htmlspecialchars($routesFile));
}

try {
    // Require the Database class definition first
    require_once $databaseFile;
    
    // Require the routing map
    require_once $routesFile;

} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Internal Server Error</h1>';
    echo '<p><strong>Exception:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ' on line ' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}