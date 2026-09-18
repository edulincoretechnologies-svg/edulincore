<?php
/**
 * Performance Analysis Controller | EduLinCore School Management Framework
 * Role: School IT Officer (school_it)
 * Path: app/Controllers/IT/PerformanceAnalysisController.php
 */

if (session_status() === PHP_SESSION_NONE) {
    // Session Security Hardening
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
if (!$school_id || !filter_var($school_id, FILTER_VALIDATE_INT)) {
    die("Critical Error: Invalid school context.");
}
$username = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'IT Officer');

if (!isset($pdo)) {
    require_once BASE_PATH . '/app/config/database.php';
    try {
        $pdo = Database::getConnection();
    } catch (Exception $e) {
        die("Database Connection Error.");
    }
}

// Fetch School Details
$stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

// Updated naming convention: Test 1 and Test 2
$standard_assessments = ['Test 1', 'Test 2', 'End of Term Test'];
$standard_terms = ['Term 1', 'Term 2', 'Term 3'];

$grade = $_GET['grade_level'] ?? '';
$class = $_GET['class_name'] ?? ''; 
$year  = $_GET['academic_year'] ?? date('Y');
$term  = $_GET['term'] ?? '';
$type  = $_GET['assessment_type'] ?? $standard_assessments[0]; 

/* --- 2. GRADING SCHEME --- */
function getScheme($grade_level) {
    $primary = ['GRADE 1','GRADE 2','GRADE 3','GRADE 4','GRADE 5','GRADE 6','GRADE 7'];
    $g = strtoupper(trim($grade_level));
    if (in_array($g, $primary)) {
        return ['Div1'=>[75,100],'Div2'=>[60,74],'Div3'=>[50,59],'Div4'=>[40,49],'Fail'=>[0,39]];
    }
    return [
        '1 Dist'=>[75,100],'2 Dist'=>[70,74],'3 Merit'=>[65,69],'4 Merit'=>[60,64],
        '5 Cred'=>[55,59],'6 Cred'=>[50,54],'7 Sat'=>[45,49],'8 Sat'=>[40,44],'9 Unsat'=>[0,39]
    ];
}

$primary_list = ['GRADE 1','GRADE 2','GRADE 3','GRADE 4','GRADE 5','GRADE 6','GRADE 7'];
$is_primary = in_array(strtoupper(trim($grade)), $primary_list);
$pass_label_1 = $is_primary ? '1-3' : '1-6';
$pass_label_2 = $is_primary ? '1-4' : '1-8';
$limit_1 = $is_primary ? 3 : 6;
$limit_2 = $is_primary ? 4 : 8;

/* --- 3. DATA ENGINE --- */
$allTables = [];
$chartData = [];

if ($grade) {
    $scheme = getScheme($grade);
    $classStmt = $pdo->prepare("SELECT DISTINCT class_name FROM student_enrollments WHERE school_id = ? AND grade_level = ? ORDER BY class_name");
    $classStmt->execute([$school_id, $grade]);
    $classNames = $class ? [$class] : $classStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($classNames as $cName) {
        $tableData = ['className' => $cName, 'rows' => []];

        $eStmt = $pdo->prepare("SELECT COUNT(CASE WHEN gender='Male' OR gender='M' THEN 1 END) as m, COUNT(CASE WHEN gender='Female' OR gender='F' THEN 1 END) as f FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.grade_level = ? AND se.class_name = ? AND se.school_id = ?");
        $eStmt->execute([$grade, $cName, $school_id]);
        $classEnrollment = $eStmt->fetch(PDO::FETCH_ASSOC);

        $sql = "SELECT DISTINCT sub.id, sub.subject_name AS short_name FROM subjects sub 
                JOIN teacher_assignments ta ON ta.subject_id = sub.id 
                WHERE ta.grade_level = ? AND ta.class_name = ? AND ta.school_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$grade, $cName, $school_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subjects as $sub) {
            $mSql = "SELECT s.gender, m.score, m.absent, m.assessment_type FROM marks m 
                     JOIN student_enrollments se ON m.enrollment_id = se.id 
                     JOIN students s ON se.student_id = s.id 
                     WHERE m.subject_id = ? AND se.grade_level = ? AND se.class_name = ? AND m.school_id = ?";
            $params = [$sub['id'], $grade, $cName, $school_id];
            
            if($year) { $mSql .= " AND m.academic_year = ?"; $params[] = $year; }
            if($term) { $mSql .= " AND m.term = ?"; $params[] = $term; }
            
            if ($type == "Test 1") {
                $mSql .= " AND (m.assessment_type = 'Test 1' OR m.assessment_type = 'Monthly Test' OR m.assessment_type = 'Monthly')";
            } elseif ($type == "Test 2") {
                $mSql .= " AND (m.assessment_type = 'Test 2' OR m.assessment_type = 'Mid-term Test' OR m.assessment_type = 'MidTerm')";
            } else {
                $mSql .= " AND m.assessment_type = ?";
                $params[] = $type;
            }

            $stmt = $pdo->prepare($mSql);
            $stmt->execute($params);
            $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $row = ['name'=>$sub['short_name'], 'm_ent'=>$classEnrollment['m'],'f_ent'=>$classEnrollment['f'],'m_sat'=>0,'f_sat'=>0, 'm_abs'=>0, 'f_abs'=>0, 'dist'=>[]];
            foreach($scheme as $k=>$v) $row['dist'][$k] = ['m'=>0, 'f'=>0];

            foreach ($marks as $m) {
                $gen = (strtolower($m['gender'][0] ?? 'm') == 'f') ? 'f' : 'm';
                if (($m['absent'] ?? 0) == 1) {
                    $row[$gen.'_abs']++;
                } elseif ($m['score'] !== null) {
                    $row[$gen.'_sat']++;
                    foreach ($scheme as $lbl => $rng) {
                        if ($m['score'] >= $rng[0] && $m['score'] <= $rng[1]) {
                            $row['dist'][$lbl][$gen]++;
                            break;
                        }
                    }
                }
            }
            $tableData['rows'][] = $row;
            
            if(!isset($chartData[$sub['short_name']])) $chartData[$sub['short_name']] = ['sat'=>0, 'p1'=>0];
            $chartData[$sub['short_name']]['sat'] += ($row['m_sat'] + $row['f_sat']);
            $counter = 0;
            foreach($row['dist'] as $b) { 
                if($counter < $limit_1) $chartData[$sub['short_name']]['p1'] += ($b['m'] + $b['f']); 
                $counter++; 
            }
        }
        $allTables[] = $tableData;
    }
}