<?php
/**
 * Student Enrollment Controller | EduLinCore School Management Framework
 * Path: app/Controllers/IT/StudentEnrollController.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id) {
    $_SESSION['enroll_error'] = "Critical Error: School context missing.";
    header('Location: ' . BASE_URL . '/it/students/enroll');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name     = strtoupper(trim($_POST['full_name'] ?? ''));
    $gender        = trim($_POST['gender'] ?? '');
    $dob           = !empty($_POST['dob']) ? trim($_POST['dob']) : null;
    $grade_level   = trim($_POST['grade_level'] ?? '');
    $class_name    = trim($_POST['class_name'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? date('Y'));

    if (empty($full_name) || empty($gender) || empty($grade_level) || empty($class_name)) {
        $_SESSION['enroll_error'] = "All required fields must be filled out.";
        header('Location: ' . BASE_URL . '/it/students/enroll');
        exit;
    }

    try {
        $db = Database::getConnection();
        
        // Begin transaction to ensure both tables are successfully updated together
        $db->beginTransaction();

        // 1. Insert core student record into `students` table
        $studentStmt = $db->prepare("
            INSERT INTO students (school_id, full_name, gender, dob, status, created_at) 
            VALUES (?, ?, ?, ?, 'active', NOW())
        ");
        $studentStmt->execute([$school_id, $full_name, $gender, $dob]);
        
        // Get the auto-incremented student ID
        $student_id = $db->lastInsertId();

        // 2. Insert enrollment details into `student_enrollments` table
        $enrollStmt = $db->prepare("
            INSERT INTO student_enrollments (student_id, school_id, academic_year, grade_level, class_name, enrolled_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $enrollStmt->execute([$student_id, $school_id, $academic_year, $grade_level, $class_name]);

        // Commit transaction
        $db->commit();

        $_SESSION['enroll_success'] = "Student " . htmlspecialchars($full_name) . " has been successfully enrolled.";

    } catch (Exception $e) {
        // Rollback transaction if any query fails
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['enroll_error'] = "Database Exception: " . $e->getMessage();
    }

    header('Location: ' . BASE_URL . '/it/students/enroll');
    exit;
} else {
    header('Location: ' . BASE_URL . '/it/students/enroll');
    exit;
}