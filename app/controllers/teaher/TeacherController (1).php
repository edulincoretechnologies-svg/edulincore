<?php
/**
 * Teacher Controller
 * Path: app/controllers/teacher/TeacherController.php
 */

namespace App\Controllers\Teacher;

use Database;
use Exception;

class TeacherController {

    /**
     * Display the Teacher Dashboard
     */
    public function index() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
            $this->handleGracefulExit();
        }

        $teacher_id = $_SESSION['user_id'];
        $school_id  = $_SESSION['school_id'] ?? null;

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare("SELECT id, full_name, phone_number, gender, school_id FROM teachers WHERE id = ?");
            $stmt->execute([$teacher_id]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                $this->handleGracefulExit();
            }

            $full_name = $teacher['full_name'];
            $school_id = (int)$teacher['school_id'];

            $stmt = $db->prepare("SELECT school_name, school_level FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $schoolData         = $stmt->fetch();
            $school_name        = $schoolData['school_name'] ?? 'EduLinCore Institution';
            $parent_school_level = strtolower(trim($schoolData['school_level'] ?? 'primary'));

            $stmt = $db->prepare("
                SELECT ta.grade_level, ta.class_name, s.subject_name, ta.subject_id 
                FROM teacher_assignments ta
                JOIN subjects s ON ta.subject_id = s.id
                WHERE ta.teacher_id = ? AND ta.school_id = ?
            ");
            $stmt->execute([$teacher_id, $school_id]);
            $assignments = $stmt->fetchAll();

            $has_secondary_assignment = false;
            foreach ($assignments as $assignment) {
                $grade = strtoupper(trim($assignment['grade_level']));
                if (strpos($grade, 'FORM') !== false || preg_match('/(8|9|10|11|12)/', $grade)) {
                    $has_secondary_assignment = true;
                    break;
                }
            }

            if ($parent_school_level === 'secondary') {
                $operating_level = 'secondary';
            } elseif ($parent_school_level === 'primary') {
                $operating_level = 'primary';
            } else {
                $operating_level = $has_secondary_assignment ? 'secondary' : 'primary';
            }

            // Keep session teacher level updated
            $_SESSION['teacher_level'] = $operating_level;

            $totalSubjects = count($assignments);

            $viewFile = APP_PATH . '/views/teacher/teacher_dashboard.php';
            if (file_exists($viewFile)) {
                require_once $viewFile;
            } else {
                throw new Exception("Teacher dashboard view file not found at: " . $viewFile);
            }

        } catch (Exception $e) {
            error_log("TeacherController Database Error: " . $e->getMessage());
            $this->handleGracefulExit();
        }
    }

    /**
     * Handle Primary Marks View Entry
     */
    public function primaryMarks() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
            $this->handleGracefulExit();
        }

        $viewFile = APP_PATH . '/views/teacher/primarymarks.php';
        if (file_exists($viewFile)) {
            require_once $viewFile;
        } else {
            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Primary Marks View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/primarymarks.php</code>.</p>";
            echo "<br><a href='" . BASE_URL . "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }
    }

    /**
     * Handle Secondary Marks View Entry
     */
    public function secondaryMarks() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
            $this->handleGracefulExit();
        }

        $viewFile = APP_PATH . '/views/teacher/secondarymarks.php';
        if (file_exists($viewFile)) {
            require_once $viewFile;
        } else {
            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Secondary Marks View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/secondarymarks.php</code>.</p>";
            echo "<br><a href='" . BASE_URL . "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }
    }

    private function handleGracefulExit() {
        session_unset();
        session_destroy();
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Location: " . BASE_URL . "/auth/school_login");
        exit;
    }
}