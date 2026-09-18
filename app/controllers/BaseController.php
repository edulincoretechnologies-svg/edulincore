<?php
/**
 * Base Controller Class
 * Path: app/Controllers/BaseController.php
 */

namespace App\Controllers;

class BaseController
{
    /**
     * Render a view file with optional data extraction.
     * 
     * @param string $viewPath Relative path from app/Views/ (e.g., 'it/dashboard')
     * @param array $data Associative array of variables to extract into the view
     * @return void
     */
    protected function render(string $viewPath, array $data = []): void
    {
        // Extract data variables so they are accessible inside the view file
        extract($data);

        $fullViewPath = APP_PATH . '/Views/' . ltrim($viewPath, '/');

        if (file_exists($fullViewPath)) {
            require_once $fullViewPath;
        } else {
            http_response_code(404);
            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.75rem; margin-bottom:1rem;'>View Not Found</h1>";
            echo "<p style='color:#64748b;'>The requested view template <code>" . htmlspecialchars($viewPath) . "</code> could not be loaded.</p>";
            echo "</div>";
        }
    }

    /**
     * Redirect to another URL safely.
     * 
     * @param string $uri Relative or absolute URI to redirect to
     * @return void
     */
    protected function redirect(string $uri): void
    {
        if (!preg_match('/^https?:\/\//', $uri)) {
            $uri = BASE_URL . '/' . ltrim($uri, '/');
        }
        header('Location: ' . $uri);
        exit;
    }

    /**
     * Return a JSON response for API or AJAX calls.
     * 
     * @param array $data Data to encode as JSON
     * @param int $statusCode HTTP status code
     * @return void
     */
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}