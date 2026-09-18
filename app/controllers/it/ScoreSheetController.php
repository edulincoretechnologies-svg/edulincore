<?php
/**
 * Score Sheet Controller | EduLinCore School Management Framework
 * Role: School IT Officer (school_it)
 * Path: app/Controllers/IT/ScoreSheetController.php
 */

if (session_status() === PHP_SESSION_NONE) {
    // Session Security Hardening
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Error reporting configuration for debugging if needed
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// Role and session security checks matching school_it context
if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id || !filter_var($school_id, FILTER_VALIDATE_INT)) {
    die("Critical Error: Invalid school context.");
}
$username = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'IT Officer');

// Database Connection Handling (Fixed path resolution for EduLinCore)
if (!isset($pdo)) {
    if (defined('BASE_PATH')) {
        require_once BASE_PATH . '/app/config/database.php';
    } else {
        require_once __DIR__ . '/../../../app/config/database.php';
    }
    try {
        if (class_exists('Database')) {
            $pdo = Database::getConnection();
        } elseif (!isset($pdo)) {
            die("Database Connection Error: Connection handler unavailable.");
        }
    } catch (Exception $e) {
        die("Database Connection Error.");
    }
}

// --- HELPER FUNCTIONS ---
function isPrimary($grade_level) {
    $val = (int) filter_var($grade_level, FILTER_SANITIZE_NUMBER_INT);
    $is_grade_string = (stripos($grade_level, 'Grade') !== false);
    return ($is_grade_string && $val >= 1 && $val <= 7);
}

function getRemarks($p, $isPrimary) {
    if ($isPrimary) {
        if($p >= 85) return 'EXCELLENT';
        if($p >= 70) return 'VERY GOOD';
        if($p >= 50) return 'GOOD';
        if($p >= 40) return 'FAIR';
        return 'POOR';
    } else {
        if($p >= 75) return 'DISTINCTION';
        if($p >= 60) return 'MERIT';
        if($p >= 50) return 'CREDIT';
        if($p >= 40) return 'PASS';
        return 'FAIL';
    }
}

/* ================= FETCH SCHOOL INFO ================= */
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id=? LIMIT 1");
$stmt->execute([$school_id]);
$ctx = $stmt->fetch(PDO::FETCH_ASSOC);
$display_school_name = $ctx['school_name'] ?? 'SCHOOL PORTAL';

/* ================= FILTERS ================= */
$grade = $_GET['grade'] ?? '';
$class = $_GET['class'] ?? '';
$term  = $_GET['term'] ?? 'Term 1';
$year  = $_GET['year'] ?? date('Y');

$is_ready = (!empty($year) && !empty($term) && !empty($grade));

// Section Logic
$section_label = "General Record";
$isPrimaryGrade = false;
if ($grade !== 'All' && !empty($grade)) {
    $numericGrade = (int)filter_var($grade, FILTER_SANITIZE_NUMBER_INT);
    $isSecondary = (stripos($grade, 'Form') !== false) || ($numericGrade >= 8);
    $section_label = $isSecondary ? "Secondary Section" : "Primary Section";
    $isPrimaryGrade = !$isSecondary;
}

/* ================= FETCH FILTER OPTIONS (Scoped to school_id) ================= */
$stmt = $pdo->prepare("SELECT DISTINCT grade_level FROM student_enrollments WHERE school_id=? ORDER BY grade_level");
$stmt->execute([$school_id]); 
$grades = $stmt->fetchAll(PDO::FETCH_COLUMN);

$classes = [];
if ($grade) {
    $stmt = $pdo->prepare("SELECT DISTINCT class_name FROM student_enrollments WHERE school_id=? AND grade_level=? ORDER BY class_name");
    $stmt->execute([$school_id, $grade]); 
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$stmt = $pdo->prepare("SELECT DISTINCT academic_year FROM student_enrollments WHERE school_id=? ORDER BY academic_year DESC");
$stmt->execute([$school_id]); 
$years = $stmt->fetchAll(PDO::FETCH_COLUMN);
$terms_list = ['Term 1', 'Term 2', 'Term 3'];

/* ================= DATA FETCHING ================= */
$students_data = []; 
$subjects = []; 
$marks = [];
$active_columns = ['T1' => false, 'T2' => false, 'E' => false]; 
$class_sum_averages = 0;
$class_mean_average = 0;

if($is_ready) {
    $sub_sql = "SELECT DISTINCT s.id, s.subject_name, s.short_name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.school_id=?";
    $sub_params = [$school_id];
    if($grade && $grade !== 'All'){ $sub_sql .= " AND ta.grade_level=?"; $sub_params[] = $grade; }
    if($class){ $sub_sql .= " AND ta.class_name=?"; $sub_params[] = $class; }
    $stmt = $pdo->prepare($sub_sql);
    $stmt->execute($sub_params);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!empty($subjects)) {
        $stu_sql = "SELECT st.id, st.full_name, st.gender, se.grade_level, se.class_name FROM students st JOIN student_enrollments se ON se.student_id=st.id WHERE se.school_id=? AND se.academic_year=?";
        $stu_params = [$school_id, $year];
        if($grade && $grade !== 'All'){ $stu_sql .= " AND se.grade_level=?"; $stu_params[] = $grade; }
        if($class){ $stu_sql .= " AND se.class_name=?"; $stu_params[] = $class; }
        $stmt = $pdo->prepare($stu_sql);
        $stmt->execute($stu_params);
        $raw_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(!empty($raw_students)) {
            $sids = array_column($raw_students,'id'); 
            $sub_ids = array_column($subjects,'id');
            $inS = implode(',',array_fill(0,count($sids),'?')); 
            $inSub = implode(',',array_fill(0,count($sub_ids),'?'));
            
            $mark_sql = "SELECT m.student_id, m.subject_id, m.assessment_type, m.score FROM marks m WHERE m.student_id IN ($inS) AND m.subject_id IN ($inSub) AND m.term=? AND m.academic_year=? AND m.school_id=?";
            $stmt = $pdo->prepare($mark_sql);
            $stmt->execute(array_merge($sids, $sub_ids, [$term, $year, $school_id]));
            
            foreach($stmt as $r){
                $aType = $r['assessment_type'];
                $type = '';
                if($aType === 'Test 1') { $type = 'T1'; }
                elseif($aType === 'Test 2') { $type = 'T2'; }
                elseif($aType === 'End of Term Test') { $type = 'E'; }
                
                if($type !== '' && $r['score'] !== null && $r['score'] !== '') {
                    $marks[$r['student_id']][$r['subject_id']][$type] = $r['score'];
                    $active_columns[$type] = true; 
                }
            }

            foreach($raw_students as $st){
                $sid = $st['id']; 
                $total_avg_sum = 0; 
                $sub_count = 0;
                foreach($subjects as $sub){
                    $m = $marks[$sid][$sub['id']] ?? []; 
                    $sVal = 0; 
                    $sExists = 0;
                    foreach(['T1','T2','E'] as $k){ 
                        if(isset($m[$k]) && $m[$k] !== ''){ $sVal += $m[$k]; $sExists++; } 
                    }
                    if($sExists > 0) {
                        $total_avg_sum += ($sVal / $sExists);
                        $sub_count++;
                    }
                }
                $st['final_avg'] = $sub_count ? round($total_avg_sum / $sub_count, 1) : 0;
                $st['total_points'] = round($total_avg_sum, 0);
                $class_sum_averages += $st['final_avg'];
                $students_data[] = $st;
            }
            $class_mean_average = count($students_data) ? round($class_sum_averages / count($students_data), 1) : 0;
            usort($students_data, function($a, $b) { return $b['final_avg'] <=> $a['final_avg']; });
        }
    }
}
$col_span = count(array_filter($active_columns));