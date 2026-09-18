<?php
namespace App\Controllers\IT;

class GradeSetupController {
    
    public function index() {
        try {
            $db = \Database::getConnection();
            $schoolId = $_SESSION['school_id'] ?? null;

            // Fetch school name from the schools table
            $schoolName = 'Unknown School';
            if ($schoolId) {
                $schoolStmt = $db->prepare("SELECT school_name FROM schools WHERE id = ? LIMIT 1");
                $schoolStmt->execute([$schoolId]);
                $school = $schoolStmt->fetch(\PDO::FETCH_ASSOC);
                if ($school) {
                    $schoolName = $school['school_name'];
                }
            }

            // Fetch only grade levels belonging to the logged-in school
            $stmt = $db->prepare("SELECT * FROM grade_levels WHERE school_id = ? ORDER BY id DESC");
            $stmt->execute([$schoolId]);
            $grades = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Load the view file safely
            $viewFile = APP_PATH . '/views/it/setup/grades.php';
            if (file_exists($viewFile)) {
                require_once $viewFile;
            } else {
                http_response_code(404);
                echo "Grades view file not found.";
            }
        } catch (\Exception $e) {
            echo "Error: " . htmlspecialchars($e->getMessage());
        }
    }

    public function store() {
        try {
            $db = \Database::getConnection();
            $schoolId = $_SESSION['school_id'] ?? null;
            $gradeName = trim($_POST['grade_name'] ?? '');
            
            if (!$schoolId) {
                $_SESSION['error'] = "Active school session not found.";
                header('Location: ' . BASE_URL . '/it/setup/grades');
                exit;
            }

            if (empty($gradeName)) {
                $_SESSION['error'] = "Grade name cannot be empty.";
                header('Location: ' . BASE_URL . '/it/setup/grades');
                exit;
            }

            // Check if it already exists for this specific school
            $check = $db->prepare("SELECT id FROM grade_levels WHERE grade_name = ? AND school_id = ? LIMIT 1");
            $check->execute([$gradeName, $schoolId]);
            if ($check->fetch()) {
                $_SESSION['error'] = "Grade level '$gradeName' already exists for this school.";
                header('Location: ' . BASE_URL . '/it/setup/grades');
                exit;
            }

            $stmt = $db->prepare("INSERT INTO grade_levels (school_id, grade_name) VALUES (?, ?)");
            $stmt->execute([$schoolId, $gradeName]);
            
            $_SESSION['success'] = "Grade level added successfully.";
        } catch (\Exception $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/it/setup/grades');
        exit;
    }

    /**
     * Optional: Fetch a single grade level via AJAX for edit modals
     */
    public function edit() {
        header('Content-Type: application/json');
        try {
            $db = \Database::getConnection();
            $schoolId = $_SESSION['school_id'] ?? null;
            $id = $_GET['id'] ?? null;

            if (!$schoolId || !$id) {
                echo json_encode(['success' => false, 'message' => 'Invalid request.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM grade_levels WHERE id = ? AND school_id = ? LIMIT 1");
            $stmt->execute([$id, $schoolId]);
            $grade = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($grade) {
                echo json_encode(['success' => true, 'data' => $grade]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Grade level not found.']);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function update() {
        try {
            $db = \Database::getConnection();
            $schoolId = $_SESSION['school_id'] ?? null;
            $id = $_POST['id'] ?? null;
            $gradeName = trim($_POST['grade_name'] ?? '');

            if (!$schoolId || !$id || empty($gradeName)) {
                $_SESSION['error'] = "Invalid data or session provided.";
                header('Location: ' . BASE_URL . '/it/setup/grades');
                exit;
            }

            // Check if another record in the same school already has this grade name
            $check = $db->prepare("SELECT id FROM grade_levels WHERE grade_name = ? AND school_id = ? AND id != ? LIMIT 1");
            $check->execute([$gradeName, $schoolId, $id]);
            if ($check->fetch()) {
                $_SESSION['error'] = "Grade level '$gradeName' already exists.";
                header('Location: ' . BASE_URL . '/it/setup/grades');
                exit;
            }

            // Ensure we only update records belonging to this school
            $stmt = $db->prepare("UPDATE grade_levels SET grade_name = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$gradeName, $id, $schoolId]);
            
            $_SESSION['success'] = "Grade level updated successfully.";
        } catch (\Exception $e) {
            $_SESSION['error'] = "Error updating grade: " . $e->getMessage();
        }

        header('Location: ' . BASE_URL . '/it/setup/grades');
        exit;
    }

    public function delete() {
        try {
            $db = \Database::getConnection();
            $schoolId = $_SESSION['school_id'] ?? null;
            $id = $_POST['id'] ?? null;

            if ($schoolId && $id) {
                // Ensure we only delete records belonging to this school
                $stmt = $db->prepare("DELETE FROM grade_levels WHERE id = ? AND school_id = ?");
                $stmt->execute([$id, $schoolId]);
                $_SESSION['success'] = "Grade level deleted successfully.";
            } else {
                $_SESSION['error'] = "Invalid request or missing school session.";
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = "Error deleting grade: " . $e->getMessage();
        }

        header('Location: ' . BASE_URL . '/it/setup/grades');
        exit;
    }
}