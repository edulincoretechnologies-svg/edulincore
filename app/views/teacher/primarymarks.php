<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'teacher') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    } else {
        header("Location: " . BASE_URL . "/auth/school_login");
    }
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$teacher_id = $_SESSION['user_id'];
$school_id  = $_SESSION['school_id'] ?? null;
$db = Database::getConnection();

if (!$school_id) {
    $stmt = $db->prepare("SELECT school_id FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $school_id = $stmt->fetchColumn();
    $_SESSION['school_id'] = $school_id;
}

if (!$school_id) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'School information could not be determined.']);
    } else {
        exit('School information could not be determined.');
    }
    exit;
}

$t_stmt = $db->prepare("SELECT full_name, level FROM teachers WHERE id = ?");
$t_stmt->execute([$teacher_id]);
$teacher_info = $t_stmt->fetch(PDO::FETCH_ASSOC);

$full_name       = $teacher_info['full_name'] ?? 'Teacher Panel';
$operating_level = $teacher_info['level'] ?? ($_SESSION['operating_level'] ?? 'primary');
$_SESSION['operating_level'] = $operating_level;

$markEntryUrl = (strtolower($operating_level) === 'secondary') 
    ? BASE_URL . '/teacher/secondary_marks' 
    : BASE_URL . '/teacher/primary_marks';

$school_name   = "EduLinCore Model Institution";
$district_name = "Lusaka District";

if ($school_id) {
    $sch_stmt = $db->prepare("SELECT school_name, District FROM schools WHERE id = ?");
    $sch_stmt->execute([$school_id]);
    $school_data = $sch_stmt->fetch(PDO::FETCH_ASSOC);
    if ($school_data) {
        $school_name   = $school_data['school_name'] ?? $school_name;
        $district_name = $school_data['District'] ?? $district_name;
    }
}

$year       = $_GET['academic_year'] ?? '';
$term       = $_GET['term'] ?? '';
$grade      = $_GET['grade_level'] ?? '';
$class      = $_GET['class_name'] ?? '';
$subject    = $_GET['subject_id'] ?? '';
$assessment = $_GET['assessment_type'] ?? '';

$valid_assessments = ['Test 1', 'Test 2', 'End of Term Test'];
$batch_save_threshold = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf_token = (string)($_POST['csrf_token'] ?? '');
    if ($submitted_csrf_token === '' || !hash_equals($csrf_token, $submitted_csrf_token)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh the page and try again.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_batch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $batch = json_decode((string)($_POST['marks'] ?? ''), true);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $class_name = trim((string)($_POST['class_name'] ?? ''));
        $term_val = trim((string)($_POST['term'] ?? ''));
        $year_val = trim((string)($_POST['academic_year'] ?? ''));
        $assessment_val = trim((string)($_POST['assessment_type'] ?? ''));

        if (!is_array($batch) || empty($batch) || count($batch) > 500 || $subject_id <= 0 || $class_name === '' || $term_val === '' || $year_val === '' || !in_array($assessment_val, $valid_assessments, true)) {
            throw new Exception('Invalid batch mark submission.');
        }

        $entries = [];
        $ids = [];
        $errors = [];
        foreach ($batch as $item) {
            $eid = (int)($item['enrollment_id'] ?? 0);
            $score_raw = trim((string)($item['score'] ?? ''));
            $absent = !empty($item['absent']) ? 1 : 0;
            if ($eid <= 0) {
                $errors[] = ['enrollment_id' => 0, 'message' => 'Invalid pupil enrollment.'];
                continue;
            }
            if ($absent) {
                $score = 0.0;
            } elseif ($score_raw === '' || !preg_match('/^\d+(?:\.\d+)?$/', $score_raw) || (float)$score_raw < 0 || (float)$score_raw > 100) {
                $errors[] = ['enrollment_id' => $eid, 'message' => 'Marks only between 0-100.'];
                continue;
            } else {
                $score = (float)$score_raw;
            }
            if (isset($entries[$eid])) {
                $errors[] = ['enrollment_id' => $eid, 'message' => 'Duplicate pupil entry in batch.'];
                unset($entries[$eid]);
                continue;
            }
            $entries[$eid] = ['score' => $score, 'absent' => $absent];
            $ids[] = $eid;
        }

        if (!$entries) throw new Exception('No valid marks were available to save.');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $verify = $db->prepare("SELECT se.id enrollment_id, s.id student_id, s.student_id_number
            FROM student_enrollments se JOIN students s ON s.id = se.student_id
            WHERE se.school_id = ? AND se.id IN ($placeholders) AND se.class_name = ? AND se.academic_year = ?
            AND EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.teacher_id = ? AND ta.school_id = se.school_id
                AND ta.subject_id = ? AND ta.grade_level = se.grade_level AND ta.class_name = se.class_name)");
        $verify->execute(array_merge([$school_id], $ids, [$class_name, $year_val, $teacher_id, $subject_id]));
        $verified = [];
        foreach ($verify->fetchAll(PDO::FETCH_ASSOC) as $row) $verified[(int)$row['enrollment_id']] = $row;
        foreach (array_keys($entries) as $eid) {
            if (!isset($verified[$eid])) {
                $errors[] = ['enrollment_id' => $eid, 'message' => 'Pupil is outside your assigned class or subject.'];
                unset($entries[$eid]);
            }
        }
        if (!$entries) throw new Exception('No authorized pupil records were available to save.');

        $db->beginTransaction();
        $values = [];
        $params = [];
        foreach ($entries as $eid => $entry) {
            $student = $verified[$eid];
            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)';
            array_push($params, $eid, $student['student_id'], $student['student_id_number'], $teacher_id, $subject_id, $class_name, $school_id, $year_val, $term_val, $assessment_val, $entry['score'], $entry['absent'], $teacher_id);
        }
        $sql = "INSERT INTO marks (enrollment_id, student_id, student_id_number, teacher_id, subject_id, class_name, school_id, academic_year, term, assessment_type, score, absent, created_at, entered_by)
            VALUES " . implode(',', $values) . " ON DUPLICATE KEY UPDATE score=VALUES(score), absent=VALUES(absent), entered_by=VALUES(entered_by), teacher_id=VALUES(teacher_id), class_name=VALUES(class_name)";
        $db->prepare($sql)->execute($params);
        $db->commit();
        echo json_encode(['success' => true, 'saved_ids' => array_map('intval', array_keys($entries)), 'saved_count' => count($entries), 'error_count' => count($errors), 'errors' => $errors]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Unable to save the batch.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_mark') {
    header('Content-Type: application/json');
    try {
        $enrollment_id = (int)$_POST['enrollment_id'];
        $input_val     = trim($_POST['score']); 
        $absent        = (int)$_POST['absent'];
        $subject_id    = (int)$_POST['subject_id'];
        $class_name    = $_POST['class_name'];
        $term_val      = $_POST['term'];
        $year_val      = $_POST['academic_year'];
        $assessment_val= $_POST['assessment_type'];

        if ($enrollment_id <= 0 || $subject_id <= 0 || !$school_id || $class_name === '' || $term_val === '' || $year_val === '' || !in_array($assessment_val, $valid_assessments, true)) {
            throw new Exception('Invalid mark entry details.');
        }

        $s = $db->prepare("SELECT s.id sid, s.student_id_number FROM student_enrollments se
                           JOIN students s ON s.id = se.student_id
                           WHERE se.id = ? AND se.school_id = ? AND se.class_name = ? AND se.academic_year = ?
                           AND EXISTS (SELECT 1 FROM teacher_assignments ta WHERE ta.teacher_id = ? AND ta.school_id = se.school_id
                               AND ta.subject_id = ? AND ta.grade_level = se.grade_level AND ta.class_name = se.class_name)");
        $s->execute([$enrollment_id, $school_id, $class_name, $year_val, $teacher_id, $subject_id]);
        $stu = $s->fetch(PDO::FETCH_ASSOC);
        if (!$stu) throw new Exception('Pupil is outside your assigned class or subject.');

        if ($absent === 1) {
            $final_score = 0; 
        } else {
            if ($input_val === "") {
                $del = $db->prepare("DELETE FROM marks WHERE enrollment_id=? AND subject_id=? AND term=? AND academic_year=? AND assessment_type=? AND school_id=?");
                $del->execute([$enrollment_id, $subject_id, $term_val, $year_val, $assessment_val, $school_id]);
                
                echo json_encode(['success' => true, 'deleted' => true]);
                exit;
            }

            if (!preg_match('/^\d+(?:\.\d+)?$/', $input_val)) {
                throw new Exception("Invalid format of marks entered. Marks must consist only of numeric figures.");
            }

            $final_score = (float)$input_val;

            if ($final_score < 0 || $final_score > 100) {
                throw new Exception("Invalid range of marks entered. Resulting mark must be between 0 and 100.");
            }
        }

        $chk = $db->prepare("SELECT id FROM marks WHERE enrollment_id=? AND subject_id=? AND term=? AND academic_year=? AND assessment_type=? AND school_id=?");
        $chk->execute([$enrollment_id, $subject_id, $term_val, $year_val, $assessment_val, $school_id]);
        $existing_mark_id = $chk->fetchColumn();

        if ($existing_mark_id) {
            $upd = $db->prepare("UPDATE marks SET score = ?, absent = ?, entered_by = ? WHERE id = ?");
            $upd->execute([$final_score, $absent, $teacher_id, $existing_mark_id]);
        } else {
            $sql = "INSERT INTO marks 
                    (enrollment_id, student_id, student_id_number, teacher_id, subject_id, class_name, 
                     school_id, academic_year, term, assessment_type, score, absent, created_at, entered_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $db->prepare($sql)->execute([
                $enrollment_id, $stu['sid'], $stu['student_id_number'], $teacher_id, 
                $subject_id, $class_name, $school_id, $year_val, $term_val, $assessment_val, $final_score, $absent, $teacher_id
            ]);
        }

        $grade_label = '';
        if ($absent === 1) {
            $grade_label = 'ABS';
        } else {
            if ($final_score >= 75) {
                $grade_label = 'Div 1 (EXCELLENT)';
            } elseif ($final_score >= 60) {
                $grade_label = 'Div 2 (VERY GOOD)';
            } elseif ($final_score >= 50) {
                $grade_label = 'Div 3 (GOOD)';
            } elseif ($final_score >= 40) {
                $grade_label = 'Div 4 (AVERAGE)';
            } else {
                $grade_label = 'F (BELOW AVERAGE)';
            }
        }

        echo json_encode(['success' => true, 'deleted' => false, 'stored_score' => $absent ? '' : $final_score, 'grade_label' => $grade_label]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_marks') {
    header('Content-Type: application/json');
    try {
        $reset_subject = (int)($_POST['subject_id'] ?? 0);
        $reset_class = trim((string)($_POST['class_name'] ?? ''));
        $reset_year = trim((string)($_POST['academic_year'] ?? ''));
        $reset_term = trim((string)($_POST['term'] ?? ''));
        $reset_assessment = trim((string)($_POST['assessment_type'] ?? ''));
        if ($reset_subject <= 0 || $reset_class === '' || $reset_year === '' || $reset_term === '' || !in_array($reset_assessment, $valid_assessments, true)) {
            throw new Exception('Invalid reset details.');
        }
        $assignmentCheck = $db->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND school_id=? AND subject_id=? AND class_name=? LIMIT 1");
        $assignmentCheck->execute([$teacher_id, $school_id, $reset_subject, $reset_class]);
        if (!$assignmentCheck->fetchColumn()) throw new Exception('You are not assigned to reset this class or subject.');

        $del = $db->prepare("DELETE FROM marks WHERE school_id=? AND academic_year=? AND term=? AND class_name=? AND subject_id=? AND assessment_type=?");
        $del->execute([$school_id, $reset_year, $reset_term, $reset_class, $reset_subject, $reset_assessment]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$a = $db->prepare("SELECT ta.grade_level, ta.class_name, ta.subject_id, sub.subject_name 
                   FROM teacher_assignments ta 
                   JOIN subjects sub ON ta.subject_id = sub.id
                   WHERE ta.teacher_id = ? AND ta.school_id = ?");
$a->execute([$teacher_id, $school_id]);
$assignments = $a->fetchAll(PDO::FETCH_ASSOC);

$all_grades = [];
$all_classes = [];
$all_subjects = [];
$mapping_by_grade = [];
$mapping_by_subject = [];

foreach ($assignments as $row) {
    $g = trim($row['grade_level']);
    $c = trim($row['class_name']);
    $sub_id = $row['subject_id'];
    $sub_name = $row['subject_name'];

    if ($g !== '') $all_grades[$g] = "Grade " . $g;
    if ($c !== '') $all_classes[$c] = $c;
    if ($sub_id)   $all_subjects[$sub_id] = $sub_name;

    $mapping_by_grade[$g][$sub_id] = $sub_name;
    $mapping_by_grade[$g]['classes'][$c] = $c;

    $mapping_by_subject[$sub_id]['grades'][$g] = "Grade " . $g;
    $mapping_by_subject[$sub_id]['classes'][$c] = $c;
}

ksort($all_grades);
ksort($all_classes);
asort($all_subjects);

$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 15; 
$ready = ($year && $term && $grade && $class && $subject && $assessment);

$students = []; $marks = []; $total_pages = 1; $all_students_search = []; $missing_students_js = [];
$total_students_count = 0; $entered_marks_count = 0; $has_started = false; $missing_count = 0;

if ($ready) {
    $count_st = $db->prepare("SELECT COUNT(*) FROM student_enrollments se JOIN students s ON s.id=se.student_id 
                              WHERE se.school_id=? AND se.grade_level=? AND se.class_name=? AND se.academic_year=?");
    $count_st->execute([$school_id, $grade, $class, $year]);
    $total_students = $count_st->fetchColumn();
    $total_pages = max(1, ceil($total_students / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $st = $db->prepare("SELECT se.id enrollment_id, s.full_name, s.student_id_number 
                        FROM student_enrollments se JOIN students s ON s.id=se.student_id 
                        WHERE se.school_id=? AND se.grade_level=? AND se.class_name=? AND se.academic_year=? 
                        ORDER BY s.full_name LIMIT $per_page OFFSET $offset");
    $st->execute([$school_id, $grade, $class, $year]);
    $students = $st->fetchAll(PDO::FETCH_ASSOC);

    $all_st = $db->prepare("SELECT se.id enrollment_id, s.full_name, s.student_id_number 
                          FROM student_enrollments se JOIN students s ON s.id=se.student_id 
                          WHERE se.school_id=? AND se.grade_level=? AND se.class_name=? AND se.academic_year=? 
                          ORDER BY s.full_name");
    $all_st->execute([$school_id, $grade, $class, $year]);
    $all_students_search = $all_st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_students_search as $index => $stu_item) {
        $all_students_search[$index]['target_page'] = floor($index / $per_page) + 1;
    }

    $mk = $db->prepare("SELECT enrollment_id, score, absent FROM marks 
                        WHERE subject_id=? AND class_name=? AND academic_year=? AND term=? 
                        AND assessment_type=? AND school_id=?");
    $mk->execute([$subject, $class, $year, $term, $assessment, $school_id]);
    $marks = $mk->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $total_students_count = count($all_students_search);
    $entered_marks_count = count($marks);
    $has_started = ($entered_marks_count > 0 && $entered_marks_count < $total_students_count);
    $missing_count = max(0, $total_students_count - $entered_marks_count);

    foreach ($all_students_search as $stu_item) {
        $eid = $stu_item['enrollment_id'];
        if (!isset($marks[$eid])) {
            $missing_students_js[] = [
                'enrollment_id' => $eid,
                'target_page' => $stu_item['target_page'],
                'full_name' => $stu_item['full_name']
            ];
        }
    }
}

function getPrimaryGradeLabel($score, $absent) {
    if ($absent == 1) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-600">ABS</span>';
    if ($score === '' || $score === null) return '<span class="text-slate-400">-</span>';
    
    $s = (float)$score;
    if ($s >= 75) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-700" title="75-100">Div 1 (EXC)</span>';
    if ($s >= 60) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-50 text-indigo-700" title="60-74">Div 2 (V.GOOD)</span>';
    if ($s >= 50) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-sky-50 text-sky-700" title="50-59">Div 3 (GOOD)</span>';
    if ($s >= 40) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-700" title="40-49">Div 4 (AVG)</span>';
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700" title="0-39">Fail</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Primary Mark Entry - <?= htmlspecialchars($school_name) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @keyframes yellowRowBlinkUltra {
            0% { background-color: #fef3c7 !important; box-shadow: inset 0 0 0 2px #f59e0b; }
            50% { background-color: #fde68a !important; box-shadow: inset 0 0 0 2px #d97706; }
            100% { background-color: #fef3c7 !important; box-shadow: inset 0 0 0 2px #f59e0b; }
        }
        .blink-highlight { animation: yellowRowBlinkUltra 0.6s ease-in-out infinite !important; }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-700">

    <div id="success-toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-emerald-600 text-white px-4 py-2 rounded-xl shadow-lg font-semibold text-xs hidden z-50 transition">
        Saved Successfully
    </div>

    <div id="search-overlay" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 hidden p-3 flex items-center justify-center">
        <div class="bg-white rounded-2xl p-4 sm:p-6 max-w-md w-full shadow-xl relative space-y-3">
            <button class="absolute top-3 right-3 text-slate-400 hover:text-slate-600 flex items-center space-x-1 text-xs font-semibold py-1 px-2 bg-slate-100 rounded-lg" onclick="closeSearch()">
                <i class="fa-solid fa-xmark text-sm"></i>
                <span>Close Window</span>
            </button>
            <h5 class="font-bold text-slate-800 text-sm sm:text-base">Quick Search Student</h5>
            <input type="text" id="student-search-input" placeholder="Type pupil name or ID number..." onkeyup="filterStudents()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <div id="search-results" class="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs"></div>
        </div>
    </div>

    <div class="min-h-screen flex flex-col">
        <div class="flex-1 flex min-h-0">

            <aside id="sidebar" class="fixed md:static inset-y-0 left-0 z-40 w-64 bg-white border-r border-slate-200 transform -translate-x-full md:translate-x-0 transition-all duration-300 ease-in-out flex flex-col shadow-sm md:shadow-none flex-shrink-0">
                <div class="h-14 sm:h-16 flex items-center justify-between px-4 border-b border-slate-100">
                    <div class="flex items-center space-x-2 truncate">
                        <div class="w-7 h-7 sm:w-8 sm:h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <span class="font-bold text-slate-800 text-xs tracking-tight truncate" title="<?= htmlspecialchars($school_name) ?>">
                            <?= htmlspecialchars($school_name) ?>
                        </span>
                    </div>
                    <button id="sidebar-close" class="md:hidden text-slate-400 hover:text-slate-600 p-1.5 flex items-center space-x-1 text-[11px] bg-slate-100 rounded-md">
                        <span>Close Menu</span>
                        <i class="fa-solid fa-xmark text-base"></i>
                    </button>
                </div>

                <div class="px-3 py-4 flex-1 space-y-1 overflow-y-auto">
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Main Menu</p>
                    
                    <a href="<?= BASE_URL ?>/teacher/dashboard" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-house w-4 text-center"></i>
                        <span>Dashboard</span>
                    </a>

                    <a href="<?= $markEntryUrl ?>" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl bg-indigo-50 text-indigo-600 font-semibold text-xs transition">
                        <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                        <span>Mark Entry Portal</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_view_report" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-file-lines w-4 text-center"></i>
                        <span>Progress Sheets</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_analysis_report" class="flex items-center space-x-2.5 px-3 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-chart-pie w-4 text-center"></i>
                        <span>Performance Analysis</span>
                    </a>
                </div>

                <div class="p-3 border-t border-slate-100 bg-slate-50/50">
                    <div class="flex items-center justify-between">
                        <div class="truncate pr-2">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($full_name) ?></p>
                            <p class="text-[9px] text-slate-400 uppercase font-semibold"><?= htmlspecialchars($operating_level) ?> Level</p>
                        </div>
                        <a href="<?= BASE_URL ?>/auth/logout" title="Sign Out" class="text-rose-500 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition flex-shrink-0 flex items-center space-x-1 text-[11px]">
                            <i class="fa-solid fa-right-from-bracket text-xs"></i>
                            <span>Sign Out</span>
                        </a>
                    </div>
                </div>
            </aside>

            <div id="main-content-wrapper" class="flex-1 flex flex-col min-w-0">
                
                <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 h-14 sm:h-16 flex items-center justify-between gap-2">
                        <div class="flex items-center space-x-2 truncate">
                            <button id="sidebar-toggle" class="text-slate-600 hover:text-slate-900 focus:outline-none p-1.5 sm:p-2 rounded-lg hover:bg-slate-100 transition flex items-center space-x-1 text-xs font-semibold flex-shrink-0" title="Toggle Sidebar">
                                <i class="fa-solid fa-bars text-sm"></i>
                                <span>Menu</span>
                            </button>
                            <div class="truncate flex items-center space-x-2">
                                <h1 class="font-bold text-slate-800 text-xs sm:text-base leading-tight truncate">Primary Mark Entry Portal</h1>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-1.5 sm:space-x-2 flex-shrink-0">
                            <button onclick="window.location.reload();" class="bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-semibold transition flex items-center space-x-1" title="Refresh Page">
                                <i class="fa-solid fa-rotate text-indigo-600"></i>
                                <span class="hidden sm:inline">Refresh</span>
                            </button>

                            <?php if($ready): ?>
                            <button onclick="jumpToNextMissing()" id="notification-bell-btn" class="<?= $has_started ? 'bg-amber-50 text-amber-700 animate-pulse border border-amber-200' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50' ?> px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-semibold transition flex items-center space-x-1.5 relative" title="<?= $has_started ? $missing_count . ' pupils pending marks' : 'All complete or not started' ?>">
                                <div class="relative">
                                    <i class="fa-solid fa-bell text-sm"></i>
                                    <?php if($has_started): ?>
                                        <span class="absolute -top-1.5 -right-2 bg-rose-600 text-white text-[9px] font-bold px-1.5 py-0.2 rounded-full shadow-xs"><?= $missing_count ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="hidden sm:inline">Pending</span>
                            </button>

                            <button onclick="openSearch()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-semibold transition flex items-center space-x-1">
                                <i class="fa-solid fa-search"></i>
                                <span class="hidden sm:inline">Search</span>
                            </button>
                            <button onclick="resetAllMarks()" class="bg-rose-50 hover:bg-rose-100 text-rose-600 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-semibold transition flex items-center space-x-1" title="Reset All Marks">
                                <i class="fa-solid fa-rotate-left"></i>
                                <span class="hidden sm:inline">Reset All</span>
                            </button>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/auth/logout" class="bg-white hover:bg-slate-50 text-slate-600 hover:text-rose-600 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-medium transition flex items-center space-x-1 border border-slate-200">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span class="hidden sm:inline">Sign Out</span>
                            </a>
                        </div>
                    </div>
                </header>

                <main class="flex-1 max-w-7xl w-full mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-6 space-y-4 sm:space-y-6">
                    
                    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-4 sm:p-6">
                        <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100 cursor-pointer" onclick="toggleFilters()">
                            <div class="flex items-center space-x-2 truncate">
                                <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs flex-shrink-0">
                                    <i class="fa-solid fa-filter"></i>
                                </div>
                                <div class="truncate">
                                    <h3 class="font-bold text-slate-800 text-xs sm:text-base truncate">Flexible Assessment Filters</h3>
                                    <?php if($ready): ?>
                                        <p class="text-[10px] sm:text-[11px] font-semibold text-indigo-600 truncate">Grade <?= htmlspecialchars($grade) ?> - Class <?= htmlspecialchars($class) ?> | <?= htmlspecialchars($assessment) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center space-x-1 text-slate-400 text-[11px] sm:text-xs font-semibold flex-shrink-0">
                                <span>Expand / Collapse</span>
                                <i class="fa-solid fa-chevron-down transition" id="chevron-icon"></i>
                            </div>
                        </div>

                        <div id="filter-body" class="<?= $ready ? 'hidden' : '' ?> grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 pt-1">
                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Academic Year</label>
                                <select id="filter-year" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="applyFilter()">
                                    <option value="">Choose Year...</option>
                                    <?php for($i=date('Y'); $i>=date('Y')-1; $i--): ?>
                                        <option value="<?= $i ?>" <?= $year == $i ? 'selected' : '' ?>><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Term</label>
                                <select id="filter-term" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="applyFilter()">
                                    <option value="">Choose Term...</option>
                                    <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                                    <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                                    <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Grade Level</label>
                                <select id="filter-grade" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="onGradeChange()">
                                    <option value="">Choose Grade...</option>
                                    <?php foreach($all_grades as $g_val => $g_label): ?>
                                        <option value="<?= htmlspecialchars($g_val) ?>" <?= $grade == $g_val ? 'selected' : '' ?>><?= htmlspecialchars($g_label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Class Stream</label>
                                <select id="filter-class" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="applyFilter()">
                                    <option value="">Choose Class...</option>
                                    <?php 
                                    $valid_classes = $all_classes;
                                    if ($grade && isset($mapping_by_grade[$grade]['classes'])) {
                                        $valid_classes = $mapping_by_grade[$grade]['classes'];
                                    }
                                    foreach($valid_classes as $c_val): 
                                    ?>
                                        <option value="<?= htmlspecialchars($c_val) ?>" <?= $class === $c_val ? 'selected' : '' ?>><?= htmlspecialchars($c_val) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Subject</label>
                                <select id="filter-subject" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="applyFilter()">
                                    <option value="">Choose Subject...</option>
                                    <?php 
                                    $valid_subjects = $all_subjects;
                                    if ($grade && isset($mapping_by_grade[$grade])) {
                                        $valid_subjects = [];
                                        foreach($mapping_by_grade[$grade] as $sub_id => $sub_name) {
                                            if ($sub_id !== 'classes') {
                                                $valid_subjects[$sub_id] = $sub_name;
                                            }
                                        }
                                    }
                                    foreach($valid_subjects as $sub_id => $sub_name): 
                                    ?>
                                        <option value="<?= htmlspecialchars($sub_id) ?>" <?= (string)$subject === (string)$sub_id ? 'selected' : '' ?>><?= htmlspecialchars($sub_name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">Assessment Type</label>
                                <select id="filter-assessment" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="applyFilter()">
                                    <option value="">Choose Assessment...</option>
                                    <?php foreach($valid_assessments as $va): ?>
                                        <option value="<?= htmlspecialchars($va) ?>" <?= $assessment === $va ? 'selected' : '' ?>><?= htmlspecialchars($va) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if($ready): ?>
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-3 sm:p-4 mb-3">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-bold text-slate-800">Mark Entry</span>
                                        <span id="pending-count-badge" class="bg-amber-50 text-amber-700 border border-amber-200 rounded-full px-2 py-0.5 text-[10px] font-bold">Pending: <?= (int)$missing_count ?></span>
                                        <span id="saved-count-badge" class="bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-2 py-0.5 text-[10px] font-bold">Saved: <?= (int)$entered_marks_count ?></span>
                                    </div>
                                    <p id="entry-mode-help" class="text-[10px] sm:text-xs text-slate-500 mt-1">Choose Batch Submission or Individual Student. Only one mode is active at a time.</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" id="batch-mode-btn" onclick="setEntryMode('batch')" class="bg-white text-slate-600 border border-slate-200 px-3 py-2 rounded-xl text-[11px] font-bold transition inline-flex items-center gap-1"><i class="fa-solid fa-layer-group"></i> Batch Submission</button>
                                    <button type="button" id="individual-mode-btn" onclick="setEntryMode('individual')" class="bg-white text-slate-600 border border-slate-200 px-3 py-2 rounded-xl text-[11px] font-bold transition inline-flex items-center gap-1"><i class="fa-solid fa-user"></i> Individual Student</button>
                                    <button type="button" id="pending-view-btn" onclick="showMarkView('pending')" class="hidden bg-amber-50 text-amber-700 border border-amber-200 px-3 py-2 rounded-xl text-[11px] font-bold transition"><i class="fa-solid fa-clock mr-1"></i> Pending</button>
                                    <button type="button" id="saved-view-btn" onclick="showMarkView('saved')" class="hidden bg-white text-slate-600 border border-slate-200 px-3 py-2 rounded-xl text-[11px] font-bold transition"><i class="fa-solid fa-circle-check mr-1"></i> Saved</button>
                                    <button type="button" id="batch-save-btn" onclick="saveBatch()" disabled class="hidden bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-xl text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-1"><i class="fa-solid fa-layer-group"></i><span>Save Batch</span></button>
                                    <button type="button" id="individual-save-btn" onclick="saveSelectedIndividualMark()" disabled class="hidden bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-xl text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-1"><i class="fa-solid fa-user-check"></i><span>Save Student Mark</span></button>
                                </div>
                            </div>
                            <div id="batch-selection-status" class="mt-3 hidden text-[10px] font-semibold"></div>
                        </div>
                        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden space-y-3">
                            <div class="overflow-x-auto">
                                    <table class="w-full text-left border-collapse min-w-[520px]">
                                    <thead>
                                        <tr class="bg-slate-50 text-slate-400 text-[10px] sm:text-[11px] uppercase tracking-wider font-bold border-b border-slate-200">
                                            <th class="px-3 sm:px-4 py-2.5">ID Number</th>
                                            <th class="px-3 sm:px-4 py-2.5">Pupil's Full Name</th>
                                            <th class="px-3 sm:px-4 py-2.5 text-center w-28">Score Entry</th>
                                            <th class="px-3 sm:px-4 py-2.5 text-center w-36">Grade / Division</th>
                                            <th class="px-3 sm:px-4 py-2.5 text-center w-24">Mark Absent</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-xs sm:text-sm text-slate-600">
                                        <?php if(empty($students)): ?>
                                            <tr>
                                                <td colspan="5" class="px-4 py-6 text-center text-slate-400">No enrollment records found matching these criteria.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($students as $s): 
                                                $eid = $s['enrollment_id'];
                                                $m = $marks[$eid] ?? null;
                                                $exists = !is_null($m);
                                                $is_absent = $exists ? (($m['absent'] ?? 0) == 1) : false;
                                                $display_score = $exists ? ($m['score'] ?? '') : '';
                                            ?>
                                            <tr id="row-<?= $eid ?>" data-mark-status="<?= $exists ? 'saved' : 'pending' ?>" class="student-row <?= $exists ? 'bg-slate-50/60' : '' ?> hover:bg-slate-50/50 transition">
                                                <td class="px-3 sm:px-4 py-2.5 text-slate-400 font-medium text-[11px] sm:text-xs"><?= htmlspecialchars($s['student_id_number']) ?></td>
                                                <td class="px-3 sm:px-4 py-2.5 font-semibold text-slate-800 text-xs sm:text-sm">
                                                    <?= htmlspecialchars($s['full_name']) ?>
                                                </td>
                                                <td class="px-3 sm:px-4 py-2.5 text-center">
                                                    <input type="number" step="0.1" min="0" max="100" class="w-20 text-center font-bold bg-slate-50 border border-slate-200 rounded-lg py-1.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50" id="sc-<?= $eid ?>" value="<?= htmlspecialchars($display_score) ?>" <?= $exists ? 'disabled' : '' ?> oninput="handleScoreInput(this, <?= $eid ?>)">
                                                </td>
                                                <td class="px-3 sm:px-4 py-2.5 text-center" id="lbl-<?= $eid ?>">
                                                    <?= getPrimaryGradeLabel($display_score, $is_absent) ?>
                                                </td>
                                                <td class="px-3 sm:px-4 py-2.5 text-center">
                                                    <label class="inline-flex items-center space-x-1.5 cursor-pointer text-[11px] font-medium text-slate-600">
                                                        <input type="checkbox" class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500" id="abs-<?= $eid ?>" <?= $is_absent ? 'checked' : '' ?> <?= $exists ? 'disabled' : '' ?> onchange="toggleAbsent(<?= $eid ?>)">
                                                        <span class="text-[10px] text-slate-500">Absent</span>
                                                    </label>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if($total_pages > 1): ?>
                            <div class="p-3 border-t border-slate-100 flex justify-center overflow-x-auto">
                                <nav class="flex items-center space-x-1">
                                    <?php $base_params = $_GET; ?>
                                    <a class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-[11px] sm:text-xs font-semibold text-slate-600 hover:bg-slate-50 whitespace-nowrap <?= ($page <= 1) ? 'opacity-50 pointer-events-none' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($base_params, ['page' => $page - 1]))) ?>">&laquo; Previous Page</a>
                                    
                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                        <a class="px-2.5 py-1.5 rounded-lg text-[11px] sm:text-xs font-semibold whitespace-nowrap <?= ($page == $i) ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($base_params, ['page' => $i]))) ?>">Page <?= $i ?></a>
                                    <?php endfor; ?>

                                    <a class="px-2.5 py-1.5 rounded-lg border border-slate-200 text-[11px] sm:text-xs font-semibold text-slate-600 hover:bg-slate-50 whitespace-nowrap <?= ($page >= $total_pages) ? 'opacity-50 pointer-events-none' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($base_params, ['page' => $page + 1]))) ?>">Next Page &raquo;</a>
                                </nav>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </main>

                <footer class="bg-white border-t border-slate-200 py-3 px-4 text-center text-[11px] text-slate-500">
                    <span>Edulincore Technologies &reg; <?= date('Y'); ?>. All Rights Reserved.</span>
                </footer>
            </div>
        </div>
    </div>

    <script>
        const allStudentsList = <?= json_encode($all_students_search ?? []) ?>;
        const missingStudentsList = <?= json_encode($missing_students_js ?? []) ?>;
        const mappingByGrade = <?= json_encode($mapping_by_grade ?? []) ?>;
        const allClasses = <?= json_encode($all_classes ?? []) ?>;
        const allSubjects = <?= json_encode($all_subjects ?? []) ?>;
        const currentPage = <?= (int)$page ?>;
        const BATCH_SAVE_THRESHOLD = <?= (int)$batch_save_threshold ?>;
        const initialEntryMode = <?= json_encode((string)($_GET['entry_mode'] ?? '')) ?>;
        const initialStudentId = <?= json_encode((string)($_GET['student_id'] ?? '')) ?>;
        let currentMissingIndex = 0;
        let entryMode = '';
        let selectedIndividualEid = null;
        let batchSaving = false;

        window.addEventListener('DOMContentLoaded', () => {
            if (window.location.hash) {
                const targetRow = document.querySelector(window.location.hash);
                if (targetRow) {
                    targetRow.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                    targetRow.classList.add('blink-highlight');
                    setTimeout(() => targetRow.classList.remove('blink-highlight'), 4000);
                }
            }
            setEntryMode(initialEntryMode === 'individual' ? 'individual' : '');
            if (initialEntryMode === 'individual' && initialStudentId) {
                selectIndividualStudent(initialStudentId);
                const targetRow = getRow(initialStudentId);
                if (targetRow) targetRow.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            }
        });

        function playNotificationChime() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, audioCtx.currentTime);
                gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.4);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.4);
            } catch(e) {}
        }

        function jumpToNextMissing() {
            playNotificationChime();
            if (missingStudentsList.length === 0) {
                alert("All pupil marks have been fully completed or not started!");
                return;
            }
            const target = missingStudentsList[currentMissingIndex];
            currentMissingIndex = (currentMissingIndex + 1) % missingStudentsList.length;

            if (target.target_page === currentPage) {
                const row = document.getElementById(`row-${target.enrollment_id}`);
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                    row.classList.add('blink-highlight');
                    setTimeout(() => row.classList.remove('blink-highlight'), 4000);
                }
            } else {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('page', target.target_page);
                window.location.href = `?${urlParams.toString()}#row-${target.enrollment_id}`;
            }
        }

        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('sidebar-close');

        function toggleSidebar(e) {
            if (e) e.stopPropagation();
            sidebar.classList.toggle('-translate-x-full');
        }
        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarClose.addEventListener('click', toggleSidebar);

        function toggleFilters() {
            const body = document.getElementById('filter-body');
            const icon = document.getElementById('chevron-icon');
            body.classList.toggle('hidden');
            icon.classList.toggle('rotate-180');
        }

        function openSearch() {
            if (entryMode !== 'individual') {
                alert('Choose Individual Student before searching for a pupil.');
                return;
            }
            document.getElementById('search-overlay').classList.remove('hidden');
            document.getElementById('student-search-input').focus();
        }
        function closeSearch() { document.getElementById('search-overlay').classList.add('hidden'); }

        function onGradeChange() {
            const gradeVal = document.getElementById('filter-grade').value;
            const classSelect = document.getElementById('filter-class');
            const subjectSelect = document.getElementById('filter-subject');

            classSelect.innerHTML = '<option value="">Choose Class...</option>';
            let validClasses = allClasses;
            if (gradeVal && mappingByGrade[gradeVal] && mappingByGrade[gradeVal]['classes']) {
                validClasses = mappingByGrade[gradeVal]['classes'];
            }
            for (const c of Object.values(validClasses)) {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                classSelect.appendChild(opt);
            }

            subjectSelect.innerHTML = '<option value="">Choose Subject...</option>';
            let validSubs = allSubjects;
            if (gradeVal && mappingByGrade[gradeVal]) {
                validSubs = {};
                for (const [subId, subName] of Object.entries(mappingByGrade[gradeVal])) {
                    if (subId !== 'classes') validSubs[subId] = subName;
                }
            }
            for (const [id, name] of Object.entries(validSubs)) {
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = name;
                subjectSelect.appendChild(opt);
            }
            applyFilter();
        }

        function applyFilter() {
            const year = document.getElementById('filter-year').value;
            const term = document.getElementById('filter-term').value;
            const grade = document.getElementById('filter-grade').value;
            const classVal = document.getElementById('filter-class').value;
            const subject = document.getElementById('filter-subject').value;
            const assessment = document.getElementById('filter-assessment').value;

            const params = new URLSearchParams();
            if(year) params.set('academic_year', year);
            if(term) params.set('term', term);
            if(grade) params.set('grade_level', grade);
            if(classVal) params.set('class_name', classVal);
            if(subject) params.set('subject_id', subject);
            if(assessment) params.set('assessment_type', assessment);

            window.location.href = '?' + params.toString();
        }

        function calculateBadgeHtml(score, isAbsent) {
            if (isAbsent) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-600">ABS</span>';
            if (score === '' || isNaN(score)) return '<span class="text-slate-400">-</span>';
            
            const s = parseFloat(score);
            if (s >= 75) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-700" title="75-100">Div 1 (EXC)</span>';
            if (s >= 60) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-50 text-indigo-700" title="60-74">Div 2 (V.GOOD)</span>';
            if (s >= 50) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-sky-50 text-sky-700" title="50-59">Div 3 (GOOD)</span>';
            if (s >= 40) return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-700" title="40-49">Div 4 (AVG)</span>';
            return '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700" title="0-39">Fail</span>';
        }

        function handleScoreInput(input, eid) {
            let val = input.value;
            let cleaned = val.replace(/[^0-9.]/g, '');
            if (cleaned !== val) {
                input.value = cleaned;
            }
            
            document.getElementById(`lbl-${eid}`).innerHTML = calculateBadgeHtml(input.value, false);

        }

        function toggleAbsent(eid) {
            const absCheckbox = document.getElementById(`abs-${eid}`);
            const scoreInput = document.getElementById(`sc-${eid}`);
            const labelContainer = document.getElementById(`lbl-${eid}`);

            if (absCheckbox.checked) {
                scoreInput.value = '';
                scoreInput.disabled = true;
                labelContainer.innerHTML = calculateBadgeHtml('', true);
            } else {
                scoreInput.disabled = false;
                labelContainer.innerHTML = calculateBadgeHtml('', false);
            }
        }

        function filterStudents() {
            const input = document.getElementById('student-search-input').value.toLowerCase();
            const resultsDiv = document.getElementById('search-results');
            resultsDiv.innerHTML = '';
            if(input.length < 2) return;

            const matches = allStudentsList.filter(s => 
                s.full_name.toLowerCase().includes(input) || 
                s.student_id_number.toLowerCase().includes(input)
            );

            if (matches.length === 0) {
                resultsDiv.innerHTML = '<div class="p-3 text-slate-400 text-center">No pupils found</div>';
                return;
            }

            matches.forEach(stu => {
                const item = document.createElement('div');
                item.className = 'p-3 hover:bg-slate-50 cursor-pointer flex items-center justify-between transition';
                item.innerHTML = `<div><strong class="text-slate-800">${escapeHtml(stu.full_name)}</strong><span class="text-slate-400 block text-[10px]">${escapeHtml(stu.student_id_number)}</span></div><span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded-md">Page ${Number(stu.target_page)}</span>`;
                item.onclick = () => {
                    closeSearch();
                    if (stu.target_page === currentPage) {
                        const row = document.getElementById(`row-${stu.enrollment_id}`);
                        if (row) {
                            selectIndividualStudent(stu.enrollment_id);
                            row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                            row.classList.add('blink-highlight');
                            setTimeout(() => row.classList.remove('blink-highlight'), 4000);
                        }
                    } else {
                        const urlParams = new URLSearchParams(window.location.search);
                        urlParams.set('page', stu.target_page);
                        urlParams.set('entry_mode', 'individual');
                        urlParams.set('student_id', stu.enrollment_id);
                        window.location.href = `?${urlParams.toString()}#row-${stu.enrollment_id}`;
                    }
                };
                resultsDiv.appendChild(item);
            });
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getRow(eid) { return document.getElementById(`row-${eid}`); }

        function setEntryMode(mode) {
            entryMode = mode;
            selectedIndividualEid = null;
            const modeButtons = [document.getElementById('batch-mode-btn'), document.getElementById('individual-mode-btn')];
            const batchBtn = document.getElementById('batch-save-btn');
            const individualBtn = document.getElementById('individual-save-btn');
            const pendingBtn = document.getElementById('pending-view-btn');
            const savedBtn = document.getElementById('saved-view-btn');
            const help = document.getElementById('entry-mode-help');
            const active = 'bg-indigo-600 text-white border-indigo-600';
            const inactive = 'bg-white text-slate-600 border-slate-200';
            if (modeButtons[0] && modeButtons[1]) {
                modeButtons[0].className = `${mode === 'batch' ? active : inactive} px-3 py-2 rounded-xl text-[11px] font-bold transition inline-flex items-center gap-1`;
                modeButtons[1].className = `${mode === 'individual' ? active : inactive} px-3 py-2 rounded-xl text-[11px] font-bold transition inline-flex items-center gap-1`;
            }
            if (batchBtn) batchBtn.classList.toggle('hidden', mode !== 'batch');
            if (pendingBtn) pendingBtn.classList.toggle('hidden', mode !== 'batch');
            if (savedBtn) savedBtn.classList.toggle('hidden', mode !== 'batch');
            if (individualBtn) { individualBtn.classList.add('hidden'); individualBtn.disabled = true; }
            document.querySelectorAll('.student-row').forEach(row => {
                const eid = row.id.replace('row-', '');
                const score = document.getElementById(`sc-${eid}`);
                const absent = document.getElementById(`abs-${eid}`);
                const saved = row.dataset.markStatus === 'saved';
                row.style.display = mode === 'batch' && row.dataset.markStatus === 'pending' ? '' : 'none';
                if (score) score.disabled = mode !== 'batch' || saved;
                if (absent) absent.disabled = mode !== 'batch' || saved;
            });
            if (mode === 'batch') {
                if (help) help.textContent = `Enter marks below and save at least ${BATCH_SAVE_THRESHOLD} pupils together.`;
                updateWorkflowCounts();
            } else if (mode === 'individual') {
                if (help) help.textContent = 'Search for one pupil, enter or edit the mark, then save that pupil.';
                openSearch();
            } else if (help) {
                help.textContent = 'Choose Batch Submission or Individual Student to begin.';
            }
        }

        function selectIndividualStudent(eid) {
            const row = getRow(eid);
            const saveBtn = document.getElementById('individual-save-btn');
            if (!row || !saveBtn) return;
            selectedIndividualEid = Number(eid);
            row.style.display = '';
            const score = document.getElementById(`sc-${eid}`);
            const absent = document.getElementById(`abs-${eid}`);
            if (score) score.disabled = false;
            if (absent) absent.disabled = false;
            saveBtn.classList.remove('hidden');
            saveBtn.disabled = false;
            saveBtn.querySelector('span').textContent = row.dataset.markStatus === 'saved' ? 'Update Student Mark' : 'Save Student Mark';
        }

        function saveSelectedIndividualMark() {
            if (entryMode === 'individual' && selectedIndividualEid !== null) saveMark(selectedIndividualEid);
        }

        function getPendingBatchEntries() {
            return Array.from(document.querySelectorAll('.student-row[data-mark-status="pending"]')).map(row => {
                const eid = row.id.replace('row-', '');
                const score = document.getElementById(`sc-${eid}`);
                const absent = document.getElementById(`abs-${eid}`);
                return { enrollment_id: Number(eid), score: absent.checked ? '' : score.value.trim(), absent: absent.checked ? 1 : 0 };
            }).filter(entry => entry.absent || entry.score !== '');
        }

        function updateWorkflowCounts() {
            const pending = document.querySelectorAll('.student-row[data-mark-status="pending"]').length;
            const saved = document.querySelectorAll('.student-row[data-mark-status="saved"]').length;
            const entries = getPendingBatchEntries();
            const pendingBadge = document.getElementById('pending-count-badge');
            const savedBadge = document.getElementById('saved-count-badge');
            const button = document.getElementById('batch-save-btn');
            const status = document.getElementById('batch-selection-status');
            if (pendingBadge) pendingBadge.textContent = `Pending: ${pending}`;
            if (savedBadge) savedBadge.textContent = `Saved: ${saved}`;
            if (button) {
                button.disabled = entryMode !== 'batch' || entries.length < BATCH_SAVE_THRESHOLD || batchSaving;
                button.querySelector('span').textContent = entries.length >= BATCH_SAVE_THRESHOLD ? `Save Batch (${entries.length})` : 'Save Batch';
            }
            if (status) {
                status.className = entries.length ? 'mt-3 text-[10px] font-semibold text-indigo-600' : 'mt-3 hidden text-[10px] font-semibold';
                status.textContent = entries.length ? `${entries.length} pupil${entries.length === 1 ? '' : 's'} entered; ${entries.length >= BATCH_SAVE_THRESHOLD ? 'ready for batch saving.' : `add ${BATCH_SAVE_THRESHOLD - entries.length} more.`}` : '';
            }
        }

        function showMarkView(view) {
            if (entryMode !== 'batch') return;
            document.querySelectorAll('.student-row').forEach(row => row.style.display = row.dataset.markStatus === view ? '' : 'none');
        }

        function setRowSaved(eid, storedScore, absent) {
            const row = getRow(eid);
            const score = document.getElementById(`sc-${eid}`);
            const absentBox = document.getElementById(`abs-${eid}`);
            if (!row || !score || !absentBox) return;
            row.dataset.markStatus = 'saved';
            row.classList.add('bg-slate-50/60');
            if (!absent) score.value = storedScore ?? score.value;
            score.disabled = true;
            absentBox.disabled = true;
            if (selectedIndividualEid === Number(eid)) {
                const button = document.getElementById('individual-save-btn');
                if (button) { button.disabled = false; button.querySelector('span').textContent = 'Update Student Mark'; }
            }
        }

        async function saveBatch() {
            if (entryMode !== 'batch' || batchSaving) return;
            const entries = getPendingBatchEntries();
            if (entries.length < BATCH_SAVE_THRESHOLD) return;
            if (!confirm(`Submit marks for ${entries.length} pupils as a batch?`)) return;
            for (const entry of entries) {
                if (!entry.absent && (!/^\d+(?:\.\d+)?$/.test(entry.score) || Number(entry.score) < 0 || Number(entry.score) > 100)) {
                    alert('Marks only between 0-100.');
                    return;
                }
            }
            const button = document.getElementById('batch-save-btn');
            batchSaving = true;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner animate-spin"></i><span>Saving Batch...</span>';
            const fd = new FormData();
            fd.append('action', 'save_batch');
            fd.append('csrf_token', <?= json_encode($csrf_token) ?>);
            fd.append('marks', JSON.stringify(entries));
            fd.append('subject_id', <?= json_encode((string)$subject) ?>);
            fd.append('class_name', <?= json_encode((string)$class) ?>);
            fd.append('academic_year', <?= json_encode((string)$year) ?>);
            fd.append('term', <?= json_encode((string)$term) ?>);
            fd.append('assessment_type', <?= json_encode((string)$assessment) ?>);
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                const json = await response.json();
                if (!response.ok || !json.success) throw new Error(json.message || 'Unable to save the batch.');
                (json.saved_ids || []).forEach(eid => setRowSaved(eid, document.getElementById(`sc-${eid}`).value, document.getElementById(`abs-${eid}`).checked));
                document.getElementById('success-toast').classList.remove('hidden');
                setTimeout(() => document.getElementById('success-toast').classList.add('hidden'), 2500);
                showMarkView('pending');
            } catch (error) { alert(error.message || 'Unable to save the batch.'); }
            finally { batchSaving = false; button.innerHTML = '<i class="fa-solid fa-layer-group"></i><span>Save Batch</span>'; updateWorkflowCounts(); }
        }

        async function saveMark(eid) {
            if (entryMode !== 'individual' || selectedIndividualEid !== Number(eid)) return;
            const btn = document.getElementById('individual-save-btn');
            const scoreInput = document.getElementById(`sc-${eid}`);
            const absentBox = document.getElementById(`abs-${eid}`);
            
            const absent = absentBox.checked ? 1 : 0;
            const score = absent ? '' : scoreInput.value.trim();

            if (!absent && (score === '' || !/^\d+(?:\.\d+)?$/.test(score) || Number(score) < 0 || Number(score) > 100)) {
                alert('Marks only between 0-100.');
                scoreInput.focus();
                return;
            }
            const fd = new FormData();
            fd.append('action', 'save_mark');
            fd.append('csrf_token', <?= json_encode($csrf_token) ?>);
            fd.append('enrollment_id', eid);
            fd.append('score', score);
            fd.append('absent', absent);
            fd.append('subject_id', <?= json_encode((string)$subject) ?>);
            fd.append('class_name', <?= json_encode((string)$class) ?>);
            fd.append('academic_year', <?= json_encode((string)$year) ?>);
            fd.append('term', <?= json_encode((string)$term) ?>);
            fd.append('assessment_type', <?= json_encode((string)$assessment) ?>);

            btn.innerHTML = '<i class="fa-solid fa-spinner animate-spin"></i><span>Saving...</span>';
            btn.disabled = true;

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                const json = await res.json();
                if(json.success) {
                    const toast = document.getElementById('success-toast');
                    toast.classList.remove('hidden');
                    setTimeout(() => toast.classList.add('hidden'), 1500);

                    if (json.deleted) {
                        btn.innerHTML = '<i class="fa-solid fa-user-check"></i><span>Save Student Mark</span>';
                        btn.disabled = false;
                        document.getElementById(`row-${eid}`).dataset.markStatus = 'pending';
                        document.getElementById(`row-${eid}`).classList.remove('bg-slate-50/60');
                        scoreInput.disabled = false;
                        absentBox.disabled = false;
                        document.getElementById(`lbl-${eid}`).innerHTML = calculateBadgeHtml('', false);
                    } else {
                        if (json.stored_score !== undefined) scoreInput.value = json.stored_score;
                        if (json.grade_label !== undefined) document.getElementById(`lbl-${eid}`).innerHTML = `<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-slate-100 text-slate-600">${json.grade_label}</span>`;
                        btn.innerHTML = '<i class="fa-solid fa-user-check"></i><span>Update Student Mark</span>';
                        btn.disabled = false;
                        const row = document.getElementById(`row-${eid}`);
                        row.dataset.markStatus = 'saved';
                        row.classList.add('bg-slate-50/60');
                        row.classList.remove('bg-amber-50/30');

                        scoreInput.disabled = true;
                        absentBox.disabled = true;
                    }
                } else {
                    alert(json.message || "Error saving mark.");
                    btn.innerHTML = '<i class="fa-solid fa-user-check"></i><span>Save Student Mark</span>';
                    btn.disabled = false;
                }
            } catch(e) {
                alert(e.message || "Unable to save this mark.");
                btn.innerHTML = '<i class="fa-solid fa-user-check"></i><span>Save Student Mark</span>';
                btn.disabled = false; 
            }
        }

        async function resetAllMarks() {
            if (!confirm("Are you sure you want to reset and delete ALL entered marks for this current filter view?")) return;

            const fd = new FormData();
            fd.append('action', 'reset_marks');
            fd.append('csrf_token', <?= json_encode($csrf_token) ?>);
            fd.append('subject_id', <?= json_encode((string)$subject) ?>);
            fd.append('class_name', <?= json_encode((string)$class) ?>);
            fd.append('academic_year', <?= json_encode((string)$year) ?>);
            fd.append('term', <?= json_encode((string)$term) ?>);
            fd.append('assessment_type', <?= json_encode((string)$assessment) ?>);

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                const json = await res.json();
                if (json.success) {
                    window.location.reload();
                } else {
                    alert("Error resetting marks.");
                }
            } catch(e) {
                alert("Server Error");
            }
        }
    </script>
</body>
</html>