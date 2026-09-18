<?php
/**
 * Teacher Performance Analysis Backend Controller
 * Path: app/Controllers/Teacher/AnalysisController.php
 */

// Prevent browser caching for secure pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

error_reporting(E_ALL);
ini_set('display_errors', 0); 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../Config/database.php';

function handleGracefulExit() {
    session_unset();
    session_destroy();
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Location: " . BASE_URL . "/auth/school_login");
    exit;
}

/* =======================
   AUTH & SESSION SETUP
======================= */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    handleGracefulExit();
}

$teacher_id = $_SESSION['user_id'];

try {
    $db = Database::getConnection();

    // Fetch teacher details
    $stmt = $db->prepare("SELECT id, full_name, school_id FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch();

    if (!$teacher) handleGracefulExit();

    $full_name = $teacher['full_name'];
    $school_id = (int)$teacher['school_id'];
    $_SESSION['school_id'] = $school_id;

    // Fetch school record
    $stmt = $db->prepare("SELECT school_name, school_level, district FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $schoolData = $stmt->fetch();
    $school_name = $schoolData['school_name'] ?? 'EduLinCore Institution';
    $parent_school_level = strtolower(trim($schoolData['school_level'] ?? 'primary'));
    $district_name = $schoolData['district'] ?? '';
    
    // Operating level logic matching navigation expectations
    $operating_level = $_SESSION['operating_level'] ?? $parent_school_level;

    /* =======================
       FILTERS & SETTINGS
    ======================= */
    $grade = $_GET['grade_level'] ?? '';
    $term  = $_GET['term'] ?? 'Term 1';
    $year  = $_GET['academic_year'] ?? date('Y');
    $type  = $_GET['assessment_type'] ?? 'End of Term Test';

    $standard_assessments = ['Test 1', 'Test 2', 'End of Term Test'];
    $termsList = ['Term 1', 'Term 2', 'Term 3'];

    function getList($db, $sql, $params = []) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $gradesList = getList($db, "SELECT DISTINCT grade_level FROM teacher_assignments WHERE teacher_id=? AND school_id=? ORDER BY grade_level", [$teacher_id, $school_id]);
    $classesList = getList($db, "SELECT DISTINCT class_name FROM teacher_assignments WHERE teacher_id=? AND school_id=? " . ($grade ? " AND grade_level=?" : "") . " ORDER BY class_name", $grade ? [$teacher_id, $school_id, $grade] : [$teacher_id, $school_id]);
    
    // Default class to the first item in classesList if not set or invalid
    $class = $_GET['class_name'] ?? ($classesList[0] ?? '');

    $yearsList  = getList($db, "SELECT DISTINCT academic_year FROM marks WHERE school_id=? ORDER BY academic_year DESC", [$school_id]);
    if(empty($yearsList)) { $yearsList = [date('Y')]; }

    function getScheme($grade_level) {
        $primary = ['GRADE 1','GRADE 2','GRADE 3','GRADE 4','GRADE 5','GRADE 6','GRADE 7', '1','2','3','4','5','6','7'];
        $g = strtoupper(trim($grade_level));
        if (in_array($g, $primary)) {
            return ['Div 1'=>[75,100],'Div 2'=>[60,74],'Div 3'=>[50,59],'Div 4'=>[40,49],'Fail'=>[0,39]];
        }
        return [
            '1 Dist'=>[75,100],'2 Dist'=>[70,74],'3 Merit'=>[65,69],'4 Merit'=>[60,64],
            '5 Cred'=>[55,59],'6 Cred'=>[50,54],'7 Pass'=>[45,49],'8 Sat'=>[40,44],'9 Unsat'=>[0,39]
        ];
    }
} catch (Exception $e) {
    error_log("Database Error: " . $e->getMessage());
    handleGracefulExit();
}