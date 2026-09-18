<?php
/**
 * School IT: Class Level Setup Controller | EduLinCore School Management Framework
 * Path: app/controllers/it/ClassLevelSetupController.php
 */

if (!defined('APP_PATH')) {
    exit('Direct script access denied.');
}

class ClassLevelSetupController {
    
    public static function handleManageRequest() {
        $error_message = '';
        $success_message = '';
        $classes = [];

        if (!class_exists('Database')) {
            return [
                'classes' => [],
                'error_message' => 'Database class framework component is missing.',
                'success_message' => '',
                'csrf_token' => $_SESSION['csrf_token'] ?? '',
                'school_name' => 'Unknown School'
            ];
        }

        try {
            $db = Database::getConnection();
        } catch (Exception $e) {
            return [
                'classes' => [],
                'error_message' => "Database initialization error: " . $e->getMessage(),
                'success_message' => '',
                'csrf_token' => $_SESSION['csrf_token'] ?? '',
                'school_name' => 'Unknown School'
            ];
        }

        // Retrieve school_id from session
        $school_id = $_SESSION['school_id'] ?? null;
        $school_name = 'Unknown Institution';

        // Fetch School Name from database
        if ($school_id) {
            try {
                $stmt = $db->prepare("SELECT school_name FROM schools WHERE id = ?");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($school && !empty($school['school_name'])) {
                    $school_name = $school['school_name'];
                } else {
                    $school_name = "School ID: " . $school_id;
                }
            } catch (Exception $e) {
                $school_name = "School ID: " . $school_id;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
                $error_message = 'Security validation failed (Invalid CSRF Token).';
            } else {
                $action = $_POST['action'] ?? '';

                if ($action === 'add_class' || $action === 'update_class') {
                    // 1. Force uppercase and trim whitespace
                    $class_name = strtoupper(trim($_POST['class_name'] ?? ''));
                    $class_id = $_POST['class_id'] ?? null;

                    if (empty($class_name)) {
                        $error_message = 'Class name cannot be empty.';
                    } else {
                        // 2. Validation Rule: Disallow explicit grade-specific keywords
                        $forbidden_keywords = ['GRADE', 'FORM', 'YEAR', 'LEVEL'];
                        foreach ($forbidden_keywords as $keyword) {
                            if (strpos($class_name, $keyword) !== false) {
                                $error_message = 'Please enter class correctly. Grade names or numbers are not allowed; use letters, colors, or places instead.';
                                break;
                            }
                        }

                        // 3. Validation Rule: Disallow shorthand grade formats (e.g., 12A, 8B, G1, G1 A, or standalone numbers)
                        if (empty($error_message)) {
                            if (
                                preg_match('/^(\d+|\d+(ST|ND|RD|TH))([\s\-]?[A-Z])?/', $class_name) || // e.g., 12A, 8 B, 1ST
                                preg_match('/^G\s*\d+/i', $class_name)                               // e.g., G1, G2, G1 A, G-1
                            ) {
                                $error_message = 'Please enter class correctly. Grade names or numbers are not allowed; use letters, colors, or places instead.';
                            }
                        }

                        // Proceed with Database Actions if validation passes
                        if (empty($error_message)) {
                            if (!$school_id) {
                                $error_message = 'Error: No active School ID found in session.';
                            } else {
                                try {
                                    if ($action === 'add_class') {
                                        $stmt = $db->prepare("INSERT INTO class_names (school_id, class_name) VALUES (?, ?)");
                                        if ($stmt->execute([$school_id, $class_name])) {
                                            $success_message = 'Class record successfully added.';
                                        } else {
                                            $error_message = 'Failed to save class record to the database.';
                                        }
                                    } elseif ($action === 'update_class') {
                                        if (empty($class_id)) {
                                            $error_message = 'Class ID is required for updating.';
                                        } else {
                                            $stmt = $db->prepare("UPDATE class_names SET class_name = ? WHERE id = ? AND school_id = ?");
                                            if ($stmt->execute([$class_name, $class_id, $school_id])) {
                                                $success_message = 'Class record successfully updated.';
                                            } else {
                                                $error_message = 'Failed to update class record.';
                                            }
                                        }
                                    }
                                } catch (PDOException $e) {
                                    $error_message = 'Database error: ' . $e->getMessage();
                                }
                            }
                        }
                    }
                } 
                elseif ($action === 'delete_class') {
                    $class_id = $_POST['class_id'] ?? null;

                    if (!empty($class_id)) {
                        try {
                            $stmt = $db->prepare("DELETE FROM class_names WHERE id = ? AND school_id = ?");
                            if ($stmt->execute([$class_id, $school_id])) {
                                $success_message = 'Class record successfully deleted.';
                            } else {
                                $error_message = 'Failed to delete class record.';
                            }
                        } catch (PDOException $e) {
                            $error_message = 'Database deletion error: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        // Fetch all classes for this school_id sorted alphabetically (A-Z)
        if ($school_id) {
            try {
                $stmt = $db->prepare("SELECT * FROM class_names WHERE school_id = ? ORDER BY class_name ASC");
                $stmt->execute([$school_id]);
                $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error_message = 'Could not retrieve class list: ' . $e->getMessage();
            }
        } else {
            $error_message = 'Warning: `school_id` is missing from the PHP session.';
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return [
            'classes' => $classes,
            'error_message' => $error_message,
            'success_message' => $success_message,
            'csrf_token' => $_SESSION['csrf_token'],
            'school_name' => $school_name
        ];
    }
}