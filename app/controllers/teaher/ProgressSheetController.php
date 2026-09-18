<?php
/**
 * Teacher Progress Sheet Controller
 * Path: app/Controllers/Teacher/ProgressSheetController.php
 */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

error_reporting(E_ALL);
ini_set('display_errors', 0); 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fallback or explicit database connection requirement
if (file_exists(__DIR__ . '/../../Config/database.php')) {
    require_once __DIR__ . '/../../Config/database.php';
} elseif (file_exists('db_connect.php')) {
    require_once 'db_connect.php';
}

function handleGracefulExit() {
    session_unset();
    session_destroy();
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    $login_redirect = defined('BASE_URL') ? BASE_URL . "/auth/school_login" : "sec_teacher_login.php";
    header("Location: " . $login_redirect);
    exit;
}

// Support both session naming conventions ('user_id' vs 'teacher_id')
$teacher_id = $_SESSION['user_id'] ?? $_SESSION['teacher_id'] ?? null;
$teacher_role = $_SESSION['user_role'] ?? 'teacher';

if (!$teacher_id) {
    handleGracefulExit();
}

// Database Connection Resolution
try {
    if (class_exists('Database') && method_exists('Database', 'getConnection')) {
        $db = Database::getConnection();
    } elseif (isset($pdo)) {
        $db = $pdo;
    } else {
        throw new Exception("Database connection not established.");
    }

    // Fetch teacher details and bound school_id
    $stmt = $db->prepare("SELECT id, full_name, phone_number, gender, school_id FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacher) handleGracefulExit();

    $full_name = $teacher['full_name'];
    $school_id = (int)$teacher['school_id'];
    $_SESSION['school_id'] = $school_id;

    // Dynamically retrieve school name, level, and district
    $stmt = $db->prepare("SELECT school_name, school_level, district FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $schoolData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $school_name         = $schoolData['school_name'] ?? 'EduLinCore Institution';
    $district_name       = $schoolData['district'] ?? 'Central Province';
    $parent_school_level = strtolower(trim($schoolData['school_level'] ?? 'primary'));

    // Fetch teacher assignments
    $stmt = $db->prepare("
        SELECT ta.grade_level, ta.class_name, s.subject_name, ta.subject_id 
        FROM teacher_assignments ta
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ? AND ta.school_id = ?
    ");
    $stmt->execute([$teacher_id, $school_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    handleGracefulExit(); 
}

// Request parameters (Filters)
$academic_year   = $_GET['academic_year'] ?? '';
$term            = $_GET['term'] ?? '';
$grade_level     = $_GET['grade_level'] ?? '';
$class_name      = $_GET['class_name'] ?? '';
$subject_id      = $_GET['subject_id'] ?? '';
$assessment_type = $_GET['assessment_type'] ?? 'Test 1';

/* ================= FILTER & DROPDOWN ARRAYS ================= */
// Fetch available dynamic years from database enrollments or fallback to recent years
$years = [];
try {
    $academic_years_stmt = $db->prepare("SELECT DISTINCT academic_year FROM student_enrollments WHERE school_id = ? ORDER BY academic_year DESC");
    $academic_years_stmt->execute([$school_id]);
    $years = $academic_years_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $current_year_val = (int)date('Y');
    for ($y = $current_year_val; $y >= $current_year_val - 2; $y--) {
        $years[] = (string)$y;
    }
}

// Filter allowed classes dynamically based on selected grade
$allowed_classes = [];
if ($grade_level) {
    foreach ($assignments as $a_item) {
        if ($a_item['grade_level'] === $grade_level) {
            $allowed_classes[] = $a_item['class_name'];
        }
    }
    $allowed_classes = array_unique($allowed_classes);
}

// Filter allowed subjects dynamically based on selected grade and class stream
$allowed_subjects = [];
if ($grade_level && $class_name) {
    foreach ($assignments as $a_item) {
        if ($a_item['grade_level'] === $grade_level && $a_item['class_name'] === $class_name) {
            $allowed_subjects[$a_item['subject_id']] = $a_item['subject_name'];
        }
    }
}

$ready = ($academic_year && $term && $grade_level && $class_name && $subject_id);

// Validate assignment ownership
$is_valid_assignment = false;
$subject_name_display = '';
if ($grade_level && $class_name && $subject_id) {
    foreach ($assignments as $asn) {
        if ($asn['grade_level'] === $grade_level && $asn['class_name'] === $class_name && (string)$asn['subject_id'] === (string)$subject_id) {
            $is_valid_assignment = true;
            $subject_name_display = $asn['subject_name'];
            break;
        }
    }
}

$progress_data = [];
if ($ready && $is_valid_assignment) {
    $stmt = $db->prepare("
        SELECT s.full_name, s.gender, m.score, m.absent
        FROM student_enrollments se
        JOIN students s ON s.id = se.student_id
        LEFT JOIN marks m ON m.enrollment_id = se.id AND m.subject_id = ? AND m.term = ? AND m.academic_year = ? AND m.assessment_type = ? AND m.school_id = ?
        WHERE se.class_name = ? AND se.grade_level = ? AND se.academic_year = ? AND se.school_id = ?
        ORDER BY s.full_name ASC
    ");
    $stmt->execute([$subject_id, $term, $academic_year, $assessment_type, $school_id, $class_name, $grade_level, $academic_year, $school_id]);
    $progress_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Automatically load the presentation view file matching this controller context
$viewPath = __DIR__ . '/../../Views/teacher/progress_sheet.php';
if (file_exists($viewPath)) {
    require_once $viewPath;
} else {
    echo "Error: Could not locate view template at {$viewPath}";
}