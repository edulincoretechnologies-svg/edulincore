<?php
/**
 * Controller Name: StudentManagementController
 * Path: app/Controllers/IT/StudentManagementController.php
 */

class StudentManagementController extends BaseController {

    private function getDb() {
        return Database::getConnection();
    }

    private function ensureITAuthorized() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;
        }
    }

    /**
     * Main index method to load view data (filters, students, school profile)
     */
    public function index() {
        $this->ensureITAuthorized();
        $school_id = $_SESSION['school_id'] ?? null;

        $db = $this->getDb();

        // Fetch School Profile
        $schoolStmt = $db->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
        $schoolStmt->execute([$school_id]);
        $school = $schoolStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch Filter parameters
        $academic_year = $_GET['academic_year'] ?? date('Y');
        $grade_level = $_GET['grade_level'] ?? '';
        $class_name = $_GET['class_name'] ?? '';
        $search = trim($_GET['search'] ?? '');

        // Fetch distinct academic years for filter dropdown
        $yearsStmt = $db->prepare("SELECT DISTINCT academic_year FROM student_enrollments WHERE school_id = ? ORDER BY academic_year DESC");
        $yearsStmt->execute([$school_id]);
        $academic_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($academic_years)) {
            $academic_years = [date('Y'), date('Y')-1, date('Y')-2];
        }

        // Fetch grade levels
        $gradesStmt = $db->prepare("SELECT DISTINCT grade_name FROM grade_levels WHERE school_id = ? ORDER BY id ASC");
        $gradesStmt->execute([$school_id]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch classes
        $classesStmt = $db->prepare("SELECT DISTINCT class_name FROM class_names WHERE school_id = ? ORDER BY id ASC");
        $classesStmt->execute([$school_id]);
        $classes = $classesStmt->fetchAll(PDO::FETCH_COLUMN);

        // Build dynamic query for students joined with active enrollments
        $query = "
            SELECT s.*, e.academic_year, e.grade_level, e.class_name, e.promotion_status, e.verification_status, e.id as enrollment_id 
            FROM students s
            JOIN student_enrollments e ON s.id = e.student_id
            WHERE s.school_id = ?
        ";
        $params = [$school_id];

        if (!empty($academic_year)) {
            $query .= " AND e.academic_year = ?";
            $params[] = $academic_year;
        }
        if (!empty($grade_level)) {
            $query .= " AND e.grade_level = ?";
            $params[] = $grade_level;
        }
        if (!empty($class_name)) {
            $query .= " AND e.class_name = ?";
            $params[] = $class_name;
        }
        if (!empty($search)) {
            $query .= " AND (s.full_name LIKE ? OR s.student_id_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $query .= " ORDER BY s.full_name ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pass data to view file
        require_once APP_PATH . '/Views/it/students/student_management.php';
    }

    /**
     * Handle Update Student and Enrollment Details (AJAX or POST)
     */
    public function update() {
        $this->ensureITAuthorized();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $school_id = $_SESSION['school_id'] ?? null;
            $student_id = (int)($_POST['student_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $dob = trim($_POST['dob'] ?? '');
            $grade_level = trim($_POST['grade_level'] ?? '');
            $class_name = trim($_POST['class_name'] ?? '');
            $academic_year = trim($_POST['academic_year'] ?? date('Y'));

            try {
                $db = $this->getDb();
                $db->beginTransaction();

                // Update students table
                $stmt = $db->prepare("UPDATE students SET full_name = ?, gender = ?, dob = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$full_name, $gender, $dob, $student_id, $school_id]);

                // Update or Insert student_enrollments table safely without record duplication conflicts
                $chk = $db->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year = ? LIMIT 1");
                $chk->execute([$student_id, $academic_year]);
                $enrollment = $chk->fetch(PDO::FETCH_ASSOC);

                if ($enrollment) {
                    $upd = $db->prepare("UPDATE student_enrollments SET grade_level = ?, class_name = ? WHERE id = ? AND school_id = ?");
                    $upd->execute([$grade_level, $class_name, $enrollment['id'], $school_id]);
                } else {
                    $ins = $db->prepare("INSERT INTO student_enrollments (student_id, school_id, academic_year, grade_level, class_name, verification_status) VALUES (?, ?, ?, ?, ?, 'Verified')");
                    $ins->execute([$student_id, $school_id, $academic_year, $grade_level, $class_name]);
                }

                $db->commit();
                $_SESSION['success_msg'] = "Student record updated successfully.";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error_msg'] = "Failed to update record: " . $e->getMessage();
            }

            header('Location: ' . BASE_URL . '/it/students');
            exit;
        }
    }

    /**
     * Handle Academic Progression / Regression transition mode safely
     */
    public function transition() {
        $this->ensureITAuthorized();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $school_id = $_SESSION['school_id'] ?? null;
            $student_id = (int)($_POST['student_id'] ?? 0);
            $target_academic_year = trim($_POST['target_academic_year'] ?? '');
            $target_grade = trim($_POST['target_grade'] ?? '');
            $target_class = trim($_POST['target_class'] ?? '');
            $transition_type = trim($_POST['transition_type'] ?? 'promotion'); // promotion or regression

            try {
                $db = $this->getDb();
                
                // Record progression status update
                $stmt = $db->prepare("UPDATE student_enrollments SET promotion_status = ? WHERE student_id = ? AND school_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([($transition_type === 'promotion' ? 'Promoted' : 'Regressed'), $student_id, $school_id]);

                // Create new enrollment context for the new academic cycle
                if (!empty($target_academic_year) && !empty($target_grade)) {
                    $chk = $db->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year = ? LIMIT 1");
                    $chk->execute([$student_id, $target_academic_year]);
                    if (!$chk->fetch()) {
                        $ins = $db->prepare("INSERT INTO student_enrollments (student_id, school_id, academic_year, grade_level, class_name, promotion_status, verification_status) VALUES (?, ?, ?, ?, ?, 'Pending', 'Verified')");
                        $ins->execute([$student_id, $school_id, $target_academic_year, $target_grade, $target_class]);
                    }
                }

                $_SESSION['success_msg'] = "Student transition mode executed successfully.";
            } catch (Exception $e) {
                $_SESSION['error_msg'] = "Transition error: " . $e->getMessage();
            }

            header('Location: ' . BASE_URL . '/it/students');
            exit;
        }
    }

    /**
     * Delete student and associated enrollments cleanly
     */
    public function delete() {
        $this->ensureITAuthorized();
        $student_id = (int)($_GET['id'] ?? $_POST['student_id'] ?? 0);
        $school_id = $_SESSION['school_id'] ?? null;

        if ($student_id && $school_id) {
            try {
                $db = $this->getDb();
                $db->beginTransaction();

                $db->prepare("DELETE FROM student_enrollments WHERE student_id = ? AND school_id = ?")->execute([$student_id, $school_id]);
                $db->prepare("DELETE FROM students WHERE id = ? AND school_id = ?")->execute([$student_id, $school_id]);

                $db->commit();
                $_SESSION['success_msg'] = "Student record deleted successfully.";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error_msg'] = "Deletion error: " . $e->getMessage();
            }
        }
        header('Location: ' . BASE_URL . '/it/students');
        exit;
    }
}