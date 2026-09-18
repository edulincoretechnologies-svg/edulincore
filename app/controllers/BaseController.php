<?php

namespace App\Controllers;

class BaseController
{
    protected function render(string $viewPath, array $data = []): void
    {
        extract($data);

        $fullViewPath = APP_PATH . '/Views/' . ltrim($viewPath, '/');

        if (file_exists($fullViewPath)) {
            require_once $fullViewPath;
            return;
        }

        http_response_code(404);
        echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
        echo "<h1 style='color:#dc2626; font-size:1.75rem; margin-bottom:1rem;'>View Not Found</h1>";
        echo "<p style='color:#64748b;'>The requested view template <code>" . htmlspecialchars($viewPath) . "</code> could not be loaded.</p>";
        echo '</div>';
    }

    protected function redirect(string $uri): void
    {
        if (!preg_match('/^https?:\/\//', $uri)) {
            $uri = BASE_URL . '/' . ltrim($uri, '/');
        }

        header('Location: ' . $uri);
        exit;
    }

    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}
