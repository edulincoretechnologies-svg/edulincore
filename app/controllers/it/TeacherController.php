<?php
/**
 * School IT: Teacher Controller | EduLinCore School Management Framework
 * Role: School IT Officer (school_it)
 * Path: app/controllers/it/TeacherController.php
 */

if (!defined('APP_PATH')) {
    exit('Direct script access denied.');
}

class TeacherController {
    
    public static function handleManageRequest() {
        try {
            $db = Database::getConnection();
        } catch (Exception $e) {
            die("Database initialization error: " . $e->getMessage());
        }

        // Retrieve school_id from session
        $school_id = $_SESSION['school_id'] ?? null;

        // EMERGENCY FALLBACK: If your session school_id is missing or empty, 
        // uncomment the line below and set it to match your database data (e.g., 7 or 16)
        // if (!$school_id) { $school_id = 7; }

        $error_message = '';
        $success_message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
                $error_message = 'Security validation failed (Invalid CSRF Token).';
            } else {
                $action = $_POST['action'] ?? '';

                if ($action === 'add_teacher') {
                    $surname = strtoupper(trim($_POST['surname'] ?? ''));
                    $other_names = ucwords(trim($_POST['other_names'] ?? ''));
                    $full_name = $surname . ' ' . $other_names;
                    $phone_number = trim($_POST['phone_number'] ?? '');
                    $gender = $_POST['gender'] ?? '';
                    $level = $_POST['level'] ?? '';
                    $default_password = password_hash('123456', PASSWORD_DEFAULT);

                    if (empty($surname) || empty($other_names) || empty($phone_number) || empty($gender) || empty($level)) {
                        $error_message = 'All required fields must be filled out.';
                    } else {
                        try {
                            $stmt = $db->prepare("INSERT INTO teachers (school_id, full_name, phone_number, gender, level, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
                            if ($stmt->execute([$school_id, $full_name, $phone_number, $gender, $level, $default_password])) {
                                $success_message = 'Teacher record successfully added. Default password is set to 123456.';
                            } else {
                                $error_message = 'Failed to save teacher record to the database.';
                            }
                        } catch (PDOException $e) {
                            $error_message = 'Database error: ' . $e->getMessage();
                        }
                    }
                } elseif ($action === 'update_teacher') {
                    $teacher_id = $_POST['teacher_id'] ?? null;
                    $surname = strtoupper(trim($_POST['surname'] ?? ''));
                    $other_names = ucwords(trim($_POST['other_names'] ?? ''));
                    $full_name = $surname . ' ' . $other_names;
                    $phone_number = trim($_POST['phone_number'] ?? '');
                    $gender = $_POST['gender'] ?? '';
                    $level = $_POST['level'] ?? '';
                    $new_password = $_POST['password'] ?? '';

                    if (empty($teacher_id) || empty($surname) || empty($other_names) || empty($phone_number)) {
                        $error_message = 'Required fields for updating cannot be empty.';
                    } else {
                        try {
                            if (!empty($new_password)) {
                                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                                $stmt = $db->prepare("UPDATE teachers SET full_name = ?, phone_number = ?, gender = ?, level = ?, password_hash = ? WHERE id = ? AND school_id = ?");
                                $stmt->execute([$full_name, $phone_number, $gender, $level, $hashed_password, $teacher_id, $school_id]);
                            } else {
                                $stmt = $db->prepare("UPDATE teachers SET full_name = ?, phone_number = ?, gender = ?, level = ? WHERE id = ? AND school_id = ?");
                                $stmt->execute([$full_name, $phone_number, $gender, $level, $teacher_id, $school_id]);
                            }
                            $success_message = 'Teacher record successfully updated.';
                        } catch (PDOException $e) {
                            $error_message = 'Database update error: ' . $e->getMessage();
                        }
                    }
                } elseif ($action === 'delete_teacher') {
                    $teacher_id = $_POST['teacher_id'] ?? null;
                    if (!empty($teacher_id)) {
                        try {
                            $stmt = $db->prepare("DELETE FROM teachers WHERE id = ? AND school_id = ?");
                            $stmt->execute([$teacher_id, $school_id]);
                            $success_message = 'Teacher record successfully deleted.';
                        } catch (PDOException $e) {
                            $error_message = 'Database deletion error: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        $teachers = [];
        if ($school_id) {
            try {
                $stmt = $db->prepare("SELECT * FROM teachers WHERE school_id = ? ORDER BY full_name ASC");
                $stmt->execute([$school_id]);
                $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error_message = 'Could not retrieve teacher lists: ' . $e->getMessage();
            }
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf_token = $_SESSION['csrf_token'];

        return [
            'teachers' => $teachers,
            'error_message' => $error_message,
            'success_message' => $success_message,
            'csrf_token' => $csrf_token
        ];
    }
}