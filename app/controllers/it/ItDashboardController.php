<?php
namespace App\Controllers\IT;

class ItDashboardController {
    public function index($pdo) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check user session & authorization roles
        if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it' || empty($_SESSION['school_id'])) {
            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;
        }

        $school_id = $_SESSION['school_id'];

        // Render the clean View and inject data context
        require_once APP_PATH . '/views/it/dashboard.php';
    }
}