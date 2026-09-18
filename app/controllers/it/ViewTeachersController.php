<?php
/**
 * EduLinCore | IT Officer Portal
 * Staff & Workloads Hub Controller (Path: app/Controllers/IT/ViewTeachersController.php)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Safely extract session variables with fallbacks to avoid undefined warnings
$school_id = $_SESSION['school_id'] ?? null;
$username = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'IT Officer');

if (!$school_id) {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

// Ensure database connection is present ($pdo)
if (!isset($pdo)) {
    require_once BASE_PATH . '/app/config/database.php';
    try {
        $pdo = Database::getConnection();
    } catch (Exception $e) {
        die("Database Connection Error: " . $e->getMessage());
    }
}

// Fetch all teachers along with their assigned workloads counted
$stmt = $pdo->prepare("
    SELECT t.id, t.full_name, t.phone_number, 
           COUNT(ta.id) as assignment_count 
    FROM teachers t 
    LEFT JOIN teacher_assignments ta ON t.id = ta.teacher_id 
    WHERE t.school_id = ? 
    GROUP BY t.id, t.full_name, t.phone_number 
    ORDER BY t.full_name ASC
");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);