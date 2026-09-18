<?php
/**
 * School IT: Teacher Assignment Management Controller
 * Path: app/controllers/it/TeacherAssignmentController.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id || !filter_var($school_id, FILTER_VALIDATE_INT)) {
    die("Critical Error: Invalid school context.");
}
$username = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'IT Officer');

try {
    $pdo = Database::getConnection();
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$selected_teacher_id = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
$message = $_GET['msg'] ?? null;

// --- CANCEL LOGIC ---
if (isset($_GET['cancel_session'])) {
    if ($selected_teacher_id) {
        $stmt = $pdo->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ? AND school_id = ?");
        $stmt->execute([$selected_teacher_id, $school_id]);
    }
    header("Location: " . BASE_URL . "/it/dashboard");
    exit();
}

// --- DELETE LOGIC ---
if (isset($_GET['delete_id'])) {
    $assignment_id = filter_input(INPUT_GET, 'delete_id', FILTER_VALIDATE_INT);
    
    if ($assignment_id) {
        $stmt = $pdo->prepare("SELECT teacher_id, subject_id, class_name FROM teacher_assignments WHERE id = ? AND school_id = ?");
        $stmt->execute([$assignment_id, $school_id]);
        $assign = $stmt->fetch();

        if ($assign) {
            if (isset($_GET['clear_marks']) && $_GET['clear_marks'] == '1') {
                $clear = $pdo->prepare("DELETE FROM marks WHERE teacher_id = ? AND subject_id = ? AND class_name = ? AND school_id = ?");
                $clear->execute([$assign['teacher_id'], $assign['subject_id'], $assign['class_name'], $school_id]);
            }
            
            $stmt = $pdo->prepare("DELETE FROM teacher_assignments WHERE id = ? AND school_id = ?");
            $stmt->execute([$assignment_id, $school_id]);
        }
    }
    
    header("Location: " . BASE_URL . "/it/teachers/assignments?teacher_id=$selected_teacher_id&msg=Assignment Removed");
    exit();
}

// --- ADD LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add'])) {
    $t_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
    $sub_ids = $_POST['subject_ids'] ?? []; 
    $gs = $_POST['grades'] ?? [];
    $cs = $_POST['classes'] ?? [];

    if ($t_id && !empty($sub_ids) && !empty($gs) && !empty($cs)) {
        $ins = $pdo->prepare("INSERT INTO teacher_assignments (teacher_id, subject_id, grade_level, class_name, school_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($sub_ids as $s_id) {
            if (!filter_var($s_id, FILTER_VALIDATE_INT)) continue;
            foreach ($gs as $g) {
                foreach ($cs as $c) {
                    $ins->execute([$t_id, $s_id, trim($g), trim($c), $school_id]);
                }
            }
        }
        header("Location: " . BASE_URL . "/it/teachers/assignments?teacher_id=$t_id&msg=Assignments Added Successfully");
        exit();
    }
}

// Data Fetching
$stmt_t = $pdo->prepare("SELECT id, full_name FROM teachers WHERE school_id = ? ORDER BY full_name ASC");
$stmt_t->execute([$school_id]);
$teachers = $stmt_t->fetchAll();

$stmt_g = $pdo->prepare("SELECT grade_name FROM grade_levels WHERE school_id = ? ORDER BY grade_name ASC");
$stmt_g->execute([$school_id]);
$grades = $stmt_g->fetchAll();

$stmt_c = $pdo->prepare("SELECT class_name FROM class_names WHERE school_id = ? ORDER BY class_name ASC");
$stmt_c->execute([$school_id]);
$classes = $stmt_c->fetchAll();

$stmt_s = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC");
$stmt_s->execute([$school_id]);
$subjects = $stmt_s->fetchAll();

$current_summary = [];
if ($selected_teacher_id) {
    $stmt = $pdo->prepare("SELECT ta.*, s.subject_name FROM teacher_assignments ta JOIN subjects s ON ta.subject_id = s.id WHERE ta.teacher_id = ? AND ta.school_id = ? ORDER BY ta.created_at DESC");
    $stmt->execute([$selected_teacher_id, $school_id]);
    $current_summary = $stmt->fetchAll();
}