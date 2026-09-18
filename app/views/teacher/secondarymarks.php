<?php
$is_post_request = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

if (!class_exists('Database')) {
    $databaseClassPath = __DIR__ . '/app/core/Database.php';
    if (is_file($databaseClassPath)) {
        require_once $databaseClassPath;
    }
}

$batch_save_threshold = 5;
$valid_assessments = [
    'Test 1',
    'Test 2',
    'End of Term Test'
];

function secondaryMarksJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function secondaryMarksValidateContext(
    int $subjectId,
    string $className,
    string $term,
    string $academicYear,
    string $assessment,
    array $validAssessments
): void {
    if (
        $subjectId <= 0 ||
        $className === '' ||
        $academicYear === '' ||
        $term === '' ||
        !preg_match('/^\d{4}$/', $academicYear) ||
        !in_array($term, ['Term 1', 'Term 2', 'Term 3'], true) ||
        !in_array($assessment, $validAssessments, true)
    ) {
        throw new InvalidArgumentException('Invalid mark entry details.');
    }
}

function secondaryMarksScore(mixed $rawScore, bool $absent): float
{
    if ($absent) {
        return 0.0;
    }

    $score = trim((string)$rawScore);
    if ($score === '' || !preg_match('/^\d+(?:\.\d+)?$/', $score)) {
        throw new InvalidArgumentException('Marks only between 0-100.');
    }

    $numericScore = (float)$score;
    if (!is_finite($numericScore) || $numericScore < 0 || $numericScore > 100) {
        throw new InvalidArgumentException('Marks only between 0-100.');
    }

    return $numericScore;
}

if (
    !isset($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'teacher'
) {
    if ($is_post_request) {
        secondaryMarksJson([
            'success' => false,
            'message' => 'Your session has expired. Please sign in again.',
            'retryable' => false
        ], 401);
    } else {
        header("Location: " . BASE_URL . "/auth/school_login");
    }
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

$teacher_id = (int)($_SESSION['user_id'] ?? 0);
$school_id  = $_SESSION['school_id'] ?? null;

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    error_log('Secondary marks database connection failed: ' . $e->getMessage());

    if ($is_post_request) {
        secondaryMarksJson([
            'success' => false,
            'message' => 'The server is temporarily unavailable. Please try again.',
            'retryable' => true
        ], 503);
    }

    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again.');
}

if (!$school_id) {
    $stmt = $db->prepare(
        "SELECT school_id
         FROM teachers
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$teacher_id]);
    $school_id = $stmt->fetchColumn();

    if ($school_id) {
        $_SESSION['school_id'] = $school_id;
    }
}

if (!$school_id) {
    if ($is_post_request) {
        secondaryMarksJson([
            'success' => false,
            'message' => 'School information could not be determined.',
            'retryable' => false
        ], 400);
    } else {
        exit('School information could not be determined.');
    }
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($is_post_request) {
    $submitted_csrf_token = (string)($_POST['csrf_token'] ?? '');

    if ($submitted_csrf_token === '' || !hash_equals($csrf_token, $submitted_csrf_token)) {
        secondaryMarksJson([
            'success' => false,
            'message' => 'Invalid request token. Please refresh the page and try again.',
            'retryable' => false
        ], 403);
        exit;
    }
}

if (
    $is_post_request &&
    ($_POST['action'] ?? '') === 'save_batch'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $payload = trim((string)($_POST['marks'] ?? ''));

        if ($payload === '') {
            throw new InvalidArgumentException('No marks were submitted.');
        }

        $batch = json_decode($payload, true);

        if (!is_array($batch) || empty($batch)) {
            throw new InvalidArgumentException('No valid marks were submitted.');
        }

        if (count($batch) > 500) {
            throw new InvalidArgumentException('Too many marks in one batch. Please save the class in smaller batches.');
        }

        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $class_name = trim((string)($_POST['class_name'] ?? ''));
        $term_val = trim((string)($_POST['term'] ?? ''));
        $year_val = trim((string)($_POST['academic_year'] ?? ''));
        $assessment_val = trim((string)($_POST['assessment_type'] ?? ''));

        if (!$school_id) {
            throw new InvalidArgumentException('School information could not be determined.');
        }

        secondaryMarksValidateContext(
            $subject_id,
            $class_name,
            $term_val,
            $year_val,
            $assessment_val,
            $valid_assessments
        );

        $valid = [];
        $invalid = [];
        $enrollment_ids = [];

        foreach ($batch as $item) {
            if (!is_array($item)) {
                $invalid[] = ['enrollment_id' => 0, 'message' => 'Invalid mark entry.'];
                continue;
            }

            $eid = (int)($item['enrollment_id'] ?? 0);
            $score_raw = trim((string)($item['score'] ?? ''));
            $absent = !empty($item['absent']) ? 1 : 0;

            if ($eid <= 0) {
                $invalid[] = ['enrollment_id' => 0, 'message' => 'Invalid student enrollment.'];
                continue;
            }

            try {
                $final_score = secondaryMarksScore($score_raw, $absent === 1);
            } catch (InvalidArgumentException $e) {
                $invalid[] = ['enrollment_id' => $eid, 'message' => $e->getMessage()];
                continue;
            }

            if (isset($valid[$eid])) {
                $invalid[] = ['enrollment_id' => $eid, 'message' => 'Duplicate student entry in batch.'];
                unset($valid[$eid]);
                continue;
            }

            $valid[$eid] = [
                'enrollment_id' => $eid,
                'score' => $final_score,
                'absent' => $absent
            ];
            $enrollment_ids[] = $eid;
        }

        if (empty($valid)) {
            secondaryMarksJson([
                'success' => false,
                'message' => 'No valid marks were available to save.',
                'saved_ids' => [],
                'errors' => $invalid,
                'retryable' => false
            ], 422);
        }
        $placeholders = implode(',', array_fill(0, count($enrollment_ids), '?'));
        $verify = $db->prepare(
            "SELECT se.id AS enrollment_id, s.id AS student_id, s.student_id_number
             FROM student_enrollments se
             JOIN students s ON s.id = se.student_id
             WHERE se.school_id = ?
             AND se.id IN ($placeholders)
             AND se.class_name = ?
             AND se.academic_year = ?
             AND EXISTS (
                 SELECT 1
                 FROM teacher_assignments ta
                 WHERE ta.teacher_id = ?
                 AND ta.school_id = se.school_id
                 AND ta.subject_id = ?
                 AND ta.grade_level = se.grade_level
                 AND ta.class_name = se.class_name
             )"
        );

        $verifyParams = array_merge(
            [$school_id],
            $enrollment_ids,
            [$class_name, $year_val, $teacher_id, $subject_id]
        );
        $verify->execute($verifyParams);
        $verifiedRows = $verify->fetchAll(PDO::FETCH_ASSOC);

        $verified = [];
        foreach ($verifiedRows as $row) {
            $verified[(int)$row['enrollment_id']] = $row;
        }

        foreach (array_keys($valid) as $eid) {
            if (!isset($verified[$eid])) {
                $invalid[] = [
                    'enrollment_id' => $eid,
                    'message' => 'Student enrollment record not found.'
                ];
                unset($valid[$eid]);
            }
        }

        if (empty($valid)) {
            secondaryMarksJson([
                'success' => false,
                'message' => 'No valid student records were available to save.',
                'saved_ids' => [],
                'errors' => $invalid,
                'retryable' => false
            ], 422);
        }
        $db->beginTransaction();

        $values = [];
        $params = [];

        foreach ($valid as $eid => $entry) {
            $student = $verified[$eid];

            $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)';

            $params[] = $eid;
            $params[] = $student['student_id'];
            $params[] = $student['student_id_number'];
            $params[] = $teacher_id;
            $params[] = $subject_id;
            $params[] = $class_name;
            $params[] = $school_id;
            $params[] = $year_val;
            $params[] = $term_val;
            $params[] = $assessment_val;
            $params[] = $entry['score'];
            $params[] = $entry['absent'];
            $params[] = $teacher_id;
        }

        $sql =
            "INSERT INTO marks
            (
                enrollment_id,
                student_id,
                student_id_number,
                teacher_id,
                subject_id,
                class_name,
                school_id,
                academic_year,
                term,
                assessment_type,
                score,
                absent,
                created_at,
                entered_by
            ) VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                absent = VALUES(absent),
                entered_by = VALUES(entered_by),
                teacher_id = VALUES(teacher_id),
                class_name = VALUES(class_name)";

        $saveBatch = $db->prepare($sql);
        $saveBatch->execute($params);
        $db->commit();

        $savedIds = array_map('intval', array_keys($valid));

        secondaryMarksJson([
            'success' => true,
            'saved_ids' => $savedIds,
            'saved_count' => count($savedIds),
            'error_count' => count($invalid),
            'errors' => $invalid,
            'message' => count($savedIds) . ' mark' . (count($savedIds) === 1 ? '' : 's') . ' saved successfully.'
        ]);
    } catch (InvalidArgumentException $e) {
        secondaryMarksJson([
            'success' => false,
            'message' => $e->getMessage(),
            'retryable' => false
        ], 422);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('Secondary marks batch save failed: ' . $e->getMessage());
        secondaryMarksJson([
            'success' => false,
            'message' => 'The marks could not be saved right now. Please try again.',
            'retryable' => true
        ], 503);
    }

    exit;
}

if (
    $is_post_request &&
    ($_POST['action'] ?? '') === 'save_mark'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        $input_val = trim((string)($_POST['score'] ?? ''));
        $absent = !empty($_POST['absent']) ? 1 : 0;
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $class_name = trim((string)($_POST['class_name'] ?? ''));
        $term_val = trim((string)($_POST['term'] ?? ''));
        $year_val = trim((string)($_POST['academic_year'] ?? ''));
        $assessment_val = trim((string)($_POST['assessment_type'] ?? ''));

        if ($enrollment_id <= 0 || !$school_id) {
            throw new InvalidArgumentException('Invalid mark entry details.');
        }

        secondaryMarksValidateContext(
            $subject_id,
            $class_name,
            $term_val,
            $year_val,
            $assessment_val,
            $valid_assessments
        );

        $s = $db->prepare(
            "SELECT
                s.id AS sid,
                s.student_id_number
             FROM student_enrollments se
             JOIN students s ON s.id = se.student_id
             WHERE se.id = ?
             AND se.school_id = ?
             AND se.class_name = ?
             AND se.academic_year = ?
             AND EXISTS (
                 SELECT 1
                 FROM teacher_assignments ta
                 WHERE ta.teacher_id = ?
                 AND ta.school_id = se.school_id
                 AND ta.subject_id = ?
                 AND ta.grade_level = se.grade_level
                 AND ta.class_name = se.class_name
             )
             LIMIT 1"
        );

        $s->execute([
            $enrollment_id,
            $school_id,
            $class_name,
            $year_val,
            $teacher_id,
            $subject_id
        ]);

        $stu = $s->fetch(PDO::FETCH_ASSOC);

        if (!$stu) {
            throw new Exception('Student enrollment is outside your assigned class or subject.');
        }

        if ($absent === 1) {
            $final_score = 0.0;
        } else {
            if ($input_val === '') {
                $del = $db->prepare(
                    "DELETE FROM marks
                     WHERE enrollment_id = ?
                     AND subject_id = ?
                     AND term = ?
                     AND academic_year = ?
                     AND assessment_type = ?
                     AND school_id = ?"
                );

                $del->execute([
                    $enrollment_id,
                    $subject_id,
                    $term_val,
                    $year_val,
                    $assessment_val,
                    $school_id
                ]);

                secondaryMarksJson([
                    'success' => true,
                    'deleted' => true,
                    'stored_score' => ''
                ]);
            }

            $final_score = secondaryMarksScore($input_val, false);
        }

        $upsert = $db->prepare(
            "INSERT INTO marks
            (
                enrollment_id,
                student_id,
                student_id_number,
                teacher_id,
                subject_id,
                class_name,
                school_id,
                academic_year,
                term,
                assessment_type,
                score,
                absent,
                created_at,
                entered_by
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?
            )
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                absent = VALUES(absent),
                entered_by = VALUES(entered_by),
                teacher_id = VALUES(teacher_id),
                class_name = VALUES(class_name)"
        );

        $upsert->execute([
            $enrollment_id,
            $stu['sid'],
            $stu['student_id_number'],
            $teacher_id,
            $subject_id,
            $class_name,
            $school_id,
            $year_val,
            $term_val,
            $assessment_val,
            $final_score,
            $absent,
            $teacher_id
        ]);

        $affected_rows = $upsert->rowCount();
        $operation = ($affected_rows === 1) ? 'saved' : 'updated';

        secondaryMarksJson([
            'success' => true,
            'deleted' => false,
            'operation' => $operation,
            'stored_score' => $absent ? '' : $final_score
        ]);
    } catch (InvalidArgumentException $e) {
        secondaryMarksJson([
            'success' => false,
            'message' => $e->getMessage(),
            'retryable' => false
        ], 422);
    } catch (Throwable $e) {
        error_log('Secondary marks save failed: ' . $e->getMessage());
        secondaryMarksJson([
            'success' => false,
            'message' => 'The mark could not be saved right now. Please try again.',
            'retryable' => true
        ], 503);
    }

    exit;
}

if (
    $is_post_request &&
    ($_POST['action'] ?? '') === 'reset_marks'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $reset_subject_id = (int)($_POST['subject_id'] ?? 0);
        $reset_class = trim((string)($_POST['class_name'] ?? ''));
        $reset_year = trim((string)($_POST['academic_year'] ?? ''));
        $reset_term = trim((string)($_POST['term'] ?? ''));
        $reset_assessment = trim((string)($_POST['assessment_type'] ?? ''));

        secondaryMarksValidateContext(
            $reset_subject_id,
            $reset_class,
            $reset_term,
            $reset_year,
            $reset_assessment,
            $valid_assessments
        );

        $assignmentCheck = $db->prepare(
            "SELECT 1
             FROM teacher_assignments
             WHERE teacher_id = ?
             AND school_id = ?
             AND subject_id = ?
             AND class_name = ?
             LIMIT 1"
        );
        $assignmentCheck->execute([
            $teacher_id,
            $school_id,
            $reset_subject_id,
            $reset_class
        ]);

        if (!$assignmentCheck->fetchColumn()) {
            throw new InvalidArgumentException('You are not assigned to reset marks for this class or subject.');
        }

        $del = $db->prepare(
            "DELETE FROM marks
             WHERE school_id = ?
             AND academic_year = ?
             AND term = ?
             AND class_name = ?
             AND subject_id = ?
             AND assessment_type = ?"
        );

        $del->execute([
            $school_id,
            $reset_year,
            $reset_term,
            $reset_class,
            $reset_subject_id,
            $reset_assessment
        ]);

        secondaryMarksJson([
            'success' => true
        ]);
    } catch (InvalidArgumentException $e) {
        secondaryMarksJson([
            'success' => false,
            'message' => $e->getMessage(),
            'retryable' => false
        ], 422);
    } catch (Throwable $e) {
        error_log('Secondary marks reset failed: ' . $e->getMessage());
        secondaryMarksJson([
            'success' => false,
            'message' => 'Marks could not be reset right now. Please try again.',
            'retryable' => true
        ], 503);
    }

    exit;
}
$t_stmt = $db->prepare(
    "SELECT full_name, level
     FROM teachers
     WHERE id = ?
     LIMIT 1"
);
$t_stmt->execute([$teacher_id]);
$teacher_info = $t_stmt->fetch(PDO::FETCH_ASSOC);

$full_name = $teacher_info['full_name'] ?? 'Teacher Panel';
$operating_level = $teacher_info['level'] ?? 'secondary';

$markEntryUrl =
    (strtolower($operating_level) === 'secondary')
        ? BASE_URL . '/teacher/secondary_marks'
        : BASE_URL . '/teacher/primary_marks';
$school_name = 'EduLinCore Model Institution';
$district_name = 'Lusaka District';

$sch_stmt = $db->prepare(
    "SELECT school_name, District
     FROM schools
     WHERE id = ?
     LIMIT 1"
);
$sch_stmt->execute([$school_id]);
$school_data = $sch_stmt->fetch(PDO::FETCH_ASSOC);

if ($school_data) {
    $school_name = $school_data['school_name'] ?? $school_name;
    $district_name = $school_data['District'] ?? $district_name;
}

$year = $_GET['academic_year'] ?? '';
$term = $_GET['term'] ?? '';
$grade = $_GET['grade_level'] ?? '';
$class = $_GET['class_name'] ?? '';
$subject = $_GET['subject_id'] ?? '';
$assessment = $_GET['assessment_type'] ?? '';

$a = $db->prepare(
    "SELECT
        ta.grade_level,
        ta.class_name,
        ta.subject_id,
        sub.subject_name
     FROM teacher_assignments ta
     JOIN subjects sub ON ta.subject_id = sub.id
     WHERE ta.teacher_id = ?
     AND ta.school_id = ?"
);

$a->execute([
    $teacher_id,
    $school_id
]);

$assignments = $a->fetchAll(PDO::FETCH_ASSOC);

$ready = (
    $year &&
    $term &&
    $grade &&
    $class &&
    $subject &&
    $assessment
);

$students = [];
$marks = [];
$missing_students_js = [];

$total_students_count = 0;
$entered_marks_count = 0;
$has_started = false;
$missing_count = 0;

$page = 1;
$total_pages = 1;

if ($ready) {

    $st = $db->prepare(
        "SELECT
            se.id AS enrollment_id,
            s.full_name,
            s.student_id_number
         FROM student_enrollments se
         JOIN students s ON s.id = se.student_id
         WHERE se.school_id = ?
         AND se.grade_level = ?
         AND se.class_name = ?
         AND se.academic_year = ?
         AND EXISTS (
             SELECT 1
             FROM teacher_assignments ta
             WHERE ta.teacher_id = ?
             AND ta.school_id = se.school_id
             AND ta.subject_id = ?
             AND ta.grade_level = se.grade_level
             AND ta.class_name = se.class_name
         )
         ORDER BY s.full_name"
    );

    $st->execute([
        $school_id,
        $grade,
        $class,
        $year,
        $teacher_id,
        (int)$subject
    ]);

    $students = $st->fetchAll(PDO::FETCH_ASSOC);

    $mk = $db->prepare(
        "SELECT
            enrollment_id,
            score,
            absent
         FROM marks
         WHERE subject_id = ?
         AND class_name = ?
         AND academic_year = ?
         AND term = ?
         AND assessment_type = ?
         AND school_id = ?"
    );

    $mk->execute([
        $subject,
        $class,
        $year,
        $term,
        $assessment,
        $school_id
    ]);

    $marks = $mk->fetchAll(
        PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC
    );

    $total_students_count = count($students);
    $entered_marks_count = 0;
    $missing_students_js = [];

    foreach ($students as $stu_item) {
        $eid = (int)$stu_item['enrollment_id'];

        if (isset($marks[$eid])) {
            $entered_marks_count++;
        } else {
            $missing_students_js[] = [
                'enrollment_id' => $eid,
                'full_name' => $stu_item['full_name']
            ];
        }
    }

    $missing_count = count($missing_students_js);

    $has_started = (
        $entered_marks_count > 0 &&
        $missing_count > 0
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Secondary Mark Entry -
        <?= htmlspecialchars($school_name) ?>
    </title>

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        @keyframes yellowRowBlinkUltra {

            0% {
                background-color: #fef3c7 !important;
                box-shadow:
                    inset 0 0 0 2px #f59e0b;
            }

            50% {
                background-color: #fde68a !important;
                box-shadow:
                    inset 0 0 0 2px #d97706;
            }

            100% {
                background-color: #fef3c7 !important;
                box-shadow:
                    inset 0 0 0 2px #f59e0b;
            }

        }

        .blink-highlight {
            animation:
                yellowRowBlinkUltra
                0.6s
                ease-in-out
                infinite !important;
        }

        .marks-table {
            table-layout: fixed;
            color: #334155;
        }

        .marks-table thead th {
            color: #334155;
            background: #e2e8f0;
            border-bottom: 2px solid #cbd5e1;
            letter-spacing: 0.04em;
        }

        .marks-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
        }

        .marks-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .marks-table tbody tr:hover {
            background: #eef2ff;
        }

        .marks-table td {
            color: #334155;
            vertical-align: middle;
        }

        .marks-table td:first-child {
            color: #475569;
            font-weight: 700;
        }

        .marks-table td:nth-child(2) {
            color: #0f172a;
            font-weight: 700;
        }

        .operation-button {
            min-height: 42px;
            border-width: 1px;
            border-radius: 0.75rem;
            padding: 0.65rem 0.9rem;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.08);
        }

        .operation-button:not(:disabled):hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgb(15 23 42 / 0.12);
        }

        #sidebar-backdrop {
            transition: opacity 180ms ease;
        }

        @media (min-width: 768px) {
            body.sidebar-collapsed #sidebar {
                width: 0;
                min-width: 0;
                overflow: hidden;
                border-right-width: 0;
                transform: none;
            }

            body.sidebar-collapsed #main-content-wrapper {
                width: 100%;
            }
        }

        @media (min-width: 768px) {
            #sidebar-backdrop {
                display: none !important;
            }
        }

    </style>

</head>

<body class="bg-slate-50 font-sans antialiased text-slate-700">
<div
        id="success-toast"
        class="fixed bottom-6 left-1/2 -translate-x-1/2 bg-emerald-600 text-white px-4 py-2 rounded-xl shadow-lg font-semibold text-xs hidden z-50 transition">

        Saved Successfully

    </div>
<div
        id="search-overlay"
        class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 hidden p-3 flex items-center justify-center">

        <div
            class="bg-white rounded-2xl p-4 sm:p-6 max-w-md w-full shadow-xl relative space-y-3">

            <button
                class="absolute top-3 right-3 text-slate-400 hover:text-slate-600 flex items-center space-x-1 text-xs font-semibold py-1 px-2 bg-slate-100 rounded-lg"
                onclick="closeSearch()">

                <i class="fa-solid fa-xmark text-sm"></i>

                <span>
                    Close Window
                </span>

            </button>

            <h5
                class="font-bold text-slate-800 text-sm sm:text-base">

                Quick Search Student

            </h5>

            <input
                type="text"
                id="student-search-input"
                placeholder="Type student name or ID number..."
                onkeyup="filterStudents()"
                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">

            <div
                id="search-results"
                class="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs">
            </div>

        </div>

    </div>
<div class="min-h-screen flex flex-col">

        <div class="flex-1 flex min-h-0">
<aside
                id="sidebar"
                class="fixed md:static inset-y-0 left-0 z-40 w-64 bg-white border-r border-slate-200 transform -translate-x-full md:translate-x-0 transition-all duration-300 ease-in-out flex flex-col shadow-sm md:shadow-none flex-shrink-0">

                <div
                    class="h-14 sm:h-16 flex items-center justify-between px-4 border-b border-slate-100">

                    <div
                        class="flex items-center space-x-2 truncate">

                        <div
                            class="w-7 h-7 sm:w-8 sm:h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0">

                            <i class="fa-solid fa-graduation-cap"></i>

                        </div>

                        <span
                            class="font-bold text-slate-800 text-xs tracking-tight truncate"
                            title="<?= htmlspecialchars($school_name) ?>">

                            <?= htmlspecialchars($school_name) ?>

                        </span>

                    </div>

                    <button
                        id="sidebar-close"
                        class="md:hidden text-slate-400 hover:text-slate-600 p-1.5 flex items-center space-x-1 text-[11px] bg-slate-100 rounded-md">

                        <span>
                            Close Menu
                        </span>

                        <i class="fa-solid fa-xmark text-base"></i>

                    </button>

                </div>

                <div
                    class="px-3 py-4 flex-1 space-y-1 overflow-y-auto">

                    <p
                        class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">

                        Main Menu

                    </p>

                    <a
                        href="<?= BASE_URL ?>/teacher/dashboard"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">

                        <i class="fa-solid fa-house w-4 text-center"></i>

                        <span>
                            Dashboard
                        </span>

                    </a>

                    <a
                        href="<?= $markEntryUrl ?>"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded-xl bg-indigo-50 text-indigo-600 font-semibold text-xs transition">

                        <i class="fa-solid fa-pen-to-square w-4 text-center"></i>

                        <span>
                            Mark Entry Portal
                        </span>

                    </a>

                    <a
                        href="<?= BASE_URL ?>/teacher_view_report"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">

                        <i class="fa-solid fa-file-lines w-4 text-center"></i>

                        <span>
                            Progress Sheets
                        </span>

                    </a>

                    <a
                        href="<?= BASE_URL ?>/teacher_analysis_report"
                        class="flex items-center space-x-2.5 px-2.5 py-2 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">

                        <i class="fa-solid fa-chart-pie w-4 text-center"></i>

                        <span>
                            Performance Analysis
                        </span>

                    </a>

                </div>

                <div
                    class="p-3 border-t border-slate-100 bg-slate-50/50">

                    <div
                        class="flex items-center justify-between">

                        <div
                            class="truncate pr-2">

                            <p
                                class="text-xs font-bold text-slate-800 truncate">

                                <?= htmlspecialchars($full_name) ?>

                            </p>

                            <p
                                class="text-[9px] text-slate-400 uppercase font-semibold">

                                <?= htmlspecialchars($operating_level) ?>
                                Level

                            </p>

                        </div>

                        <a
                            href="<?= BASE_URL ?>/auth/logout"
                            title="Sign Out"
                            class="text-rose-500 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition flex-shrink-0 flex items-center space-x-1 text-[11px]">

                            <i class="fa-solid fa-right-from-bracket text-xs"></i>

                            <span>
                                Sign Out
                            </span>

                        </a>

                    </div>

                </div>

            </aside>
<button
                id="sidebar-backdrop"
                type="button"
                aria-label="Close menu"
                class="fixed inset-0 z-30 hidden bg-slate-950/40 opacity-0 md:hidden">
</button>
<div
                id="main-content-wrapper"
                class="flex-1 flex flex-col min-w-0">
<header
                    class="bg-white border-b border-slate-200 sticky top-0 z-30">

                    <div
                        class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 h-14 sm:h-16 flex items-center justify-between gap-2">

                        <div
                            class="flex items-center space-x-2 truncate">

                            <button
                                id="sidebar-toggle"
                                class="operation-button bg-slate-900 text-white hover:bg-slate-800 focus:outline-none flex items-center space-x-2 text-xs font-bold flex-shrink-0"
                                title="Show or hide menu"
                                aria-controls="sidebar"
                                aria-expanded="false">

                                <i class="fa-solid fa-bars text-sm"></i>

                                <span>
                                    Menu
                                </span>

                            </button>

                            <div
                                class="truncate flex items-center space-x-2">

                                <h1
                                    class="font-bold text-slate-800 text-xs sm:text-base leading-tight truncate">

                                    Secondary Mark Entry

                                </h1>

                                <span
                                    id="save-status-text"
                                    class="text-[10px] text-slate-400 font-medium hidden sm:inline">
                                </span>

                            </div>

                        </div>

                        <div
                            class="flex items-center space-x-1.5 sm:space-x-2 flex-shrink-0">

                            <button
                                onclick="window.location.reload();"
                                class="operation-button bg-white text-slate-700 border-slate-300 hover:bg-slate-50 text-[11px] sm:text-xs font-bold transition flex items-center space-x-2"
                                title="Refresh Page">

                                <i class="fa-solid fa-rotate text-indigo-600"></i>

                                <span class="hidden sm:inline">
                                    Refresh
                                </span>

                            </button>

                            <?php if($ready): ?>
<button
                                    onclick="openSearch()"
                                    class="operation-button bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 text-[11px] sm:text-xs font-bold transition flex items-center space-x-2">

                                    <i class="fa-solid fa-search"></i>

                                    <span class="hidden sm:inline">
                                        Search
                                    </span>

                                </button>
<button
                                    onclick="resetAllMarks()"
                                    class="operation-button bg-rose-600 hover:bg-rose-700 text-white border-rose-600 text-[11px] sm:text-xs font-bold transition flex items-center space-x-2"
                                    title="Reset All Marks">

                                    <i class="fa-solid fa-rotate-left"></i>

                                    <span class="hidden sm:inline">
                                        Reset All
                                    </span>

                                </button>

                            <?php endif; ?>

                            <a
                                href="<?= BASE_URL ?>/auth/logout"
                                class="bg-white hover:bg-slate-50 text-slate-600 hover:text-rose-600 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs font-medium transition flex items-center space-x-1 border border-slate-200">

                                <i class="fa-solid fa-right-from-bracket"></i>

                                <span class="hidden sm:inline">
                                    Sign Out
                                </span>

                            </a>

                        </div>

                    </div>

                </header>
<main
                    class="flex-1 max-w-7xl w-full mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-6 space-y-4 sm:space-y-6">
<div
                        class="bg-white rounded-2xl shadow-xs border border-slate-200 p-4 sm:p-6">

                        <div
                            class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100 cursor-pointer"
                            onclick="toggleFilters()">

                            <div
                                class="flex items-center space-x-2 truncate">

                                <div
                                    class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs flex-shrink-0">

                                    <i class="fa-solid fa-filter"></i>

                                </div>

                                <div class="truncate">

                                    <h3
                                        class="font-bold text-slate-800 text-xs sm:text-base truncate">

                                        Assessment Filters

                                    </h3>

                                    <?php if($ready): ?>

                                        <p
                                            class="text-[10px] sm:text-[11px] font-semibold text-indigo-600 truncate">

                                            Grade
                                            <?= htmlspecialchars($grade) ?>

                                            -

                                            Class
                                            <?= htmlspecialchars($class) ?>

                                            |

                                            <?= htmlspecialchars($assessment) ?>

                                        </p>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div
                                class="flex items-center space-x-1 text-slate-400 text-[11px] sm:text-xs font-semibold flex-shrink-0">

                                <span>
                                    Expand / Collapse
                                </span>

                                <i
                                    class="fa-solid fa-chevron-down transition"
                                    id="chevron-icon">
                                </i>

                            </div>

                        </div>

                        <div
                            id="filter-body"
                            class="<?= $ready ? 'hidden' : '' ?> grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 pt-1">
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Academic Year

                                </label>

                                <select
                                    id="filter-year"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Year...
                                    </option>

                                    <?php for(
                                        $i = date('Y');
                                        $i >= date('Y') - 1;
                                        $i--
                                    ): ?>

                                        <option
                                            value="<?= $i ?>"
                                            <?= $year == $i ? 'selected' : '' ?>>

                                            <?= $i ?>

                                        </option>

                                    <?php endfor; ?>

                                </select>

                            </div>
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Term

                                </label>

                                <select
                                    id="filter-term"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Term...
                                    </option>

                                    <option
                                        value="Term 1"
                                        <?= $term === 'Term 1' ? 'selected' : '' ?>>

                                        Term 1

                                    </option>

                                    <option
                                        value="Term 2"
                                        <?= $term === 'Term 2' ? 'selected' : '' ?>>

                                        Term 2

                                    </option>

                                    <option
                                        value="Term 3"
                                        <?= $term === 'Term 3' ? 'selected' : '' ?>>

                                        Term 3

                                    </option>

                                </select>

                            </div>
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Grade Level

                                </label>

                                <select
                                    id="filter-grade"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Grade...
                                    </option>

                                    <?php foreach(
                                        array_unique(
                                            array_column(
                                                $assignments,
                                                'grade_level'
                                            )
                                        )
                                        as $g
                                    ): ?>

                                        <option
                                            value="<?= htmlspecialchars($g) ?>"
                                            <?= $grade == $g ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($g) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Class Stream

                                </label>

                                <select
                                    id="filter-class"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Class...
                                    </option>

                                    <?php foreach(
                                        $assignments as $a
                                    ):

                                        if(
                                            $a['grade_level'] ==
                                            $grade
                                        ):

                                    ?>

                                        <option
                                            value="<?= htmlspecialchars($a['class_name']) ?>"
                                            <?= $class === $a['class_name'] ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($a['class_name']) ?>

                                        </option>

                                    <?php
                                        endif;
                                    endforeach;
                                    ?>

                                </select>

                            </div>
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Subject

                                </label>

                                <select
                                    id="filter-subject"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Subject...
                                    </option>

                                    <?php foreach(
                                        $assignments as $a
                                    ):

                                        if(
                                            $a['grade_level'] ==
                                            $grade &&
                                            $a['class_name'] ==
                                            $class
                                        ):

                                    ?>

                                        <option
                                            value="<?= htmlspecialchars($a['subject_id']) ?>"
                                            <?= (string)$subject === (string)$a['subject_id'] ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($a['subject_name']) ?>

                                        </option>

                                    <?php
                                        endif;
                                    endforeach;
                                    ?>

                                </select>

                            </div>
<div>

                                <label
                                    class="block text-[11px] sm:text-xs font-bold text-slate-600 uppercase mb-1">

                                    Assessment Type

                                </label>

                                <select
                                    id="filter-assessment"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    onchange="applyFilter()">

                                    <option value="">
                                        Choose Assessment...
                                    </option>

                                    <?php foreach(
                                        $valid_assessments
                                        as $va
                                    ): ?>

                                        <option
                                            value="<?= htmlspecialchars($va) ?>"
                                            <?= $assessment === $va ? 'selected' : '' ?>>

                                            <?= htmlspecialchars($va) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                        </div>

                    </div>
<?php if($ready): ?>
<div class="sticky top-14 sm:top-16 z-20 bg-white/95 backdrop-blur-sm rounded-2xl border border-slate-300 shadow-md p-3 sm:p-4 mb-3">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-bold text-slate-800">Mark Entry</span>
                                        <span id="pending-count-badge" class="bg-amber-50 text-amber-700 border border-amber-200 rounded-full px-2 py-0.5 text-[10px] font-bold">Pending: <?= (int)$missing_count ?></span>
                                        <span id="saved-count-badge" class="bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full px-2 py-0.5 text-[10px] font-bold">Saved: <?= (int)$entered_marks_count ?></span>
                                    </div>
                                    <p id="entry-mode-help" class="text-[10px] sm:text-xs text-slate-500 mt-1">Choose one submission mode. Only the selected mode is active at a time.</p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" id="batch-mode-btn" onclick="setEntryMode('batch')" class="operation-button bg-white text-slate-700 border-slate-300 text-[11px] font-bold transition inline-flex items-center gap-2">
                                        <i class="fa-solid fa-layer-group"></i> Batch Submission
                                    </button>
                                    <button type="button" id="individual-mode-btn" onclick="setEntryMode('individual')" class="operation-button bg-white text-slate-700 border-slate-300 text-[11px] font-bold transition inline-flex items-center gap-2">
                                        <i class="fa-solid fa-user"></i> Individual Student
                                    </button>
                                    <button type="button" id="pending-view-btn" onclick="showMarkView('pending')" class="operation-button bg-amber-100 text-amber-800 border-amber-300 text-[11px] font-bold transition">
                                        <i class="fa-solid fa-clock mr-1"></i> Pending
                                    </button>
                                    <button type="button" id="saved-view-btn" onclick="showMarkView('saved')" class="operation-button bg-white text-slate-700 border-slate-300 text-[11px] font-bold transition">
                                        <i class="fa-solid fa-circle-check mr-1"></i> Saved
                                    </button>
                                    <button type="button" id="batch-save-btn" onclick="saveBatch()" disabled class="operation-button bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-2">
                                        <i class="fa-solid fa-layer-group"></i>
                                        <span>Save Batch</span>
                                    </button>
                                    <button type="button" id="individual-save-btn" onclick="saveSelectedIndividualMark()" disabled class="operation-button hidden bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600 text-[11px] font-bold transition disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-2">
                                        <i class="fa-solid fa-user-check"></i>
                                        <span>Save Student Mark</span>
                                    </button>
                                </div>
                            </div>

                            <div id="batch-selection-status" class="mt-3 hidden text-[10px] font-semibold"></div>
                        </div>

                        <div
                            class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden space-y-3">

                            <div
                                class="overflow-x-auto">

                                <table
                                    class="marks-table w-full text-left border-collapse min-w-[540px]">

                                    <colgroup>
                                        <col class="w-[19%]">
                                        <col class="w-[36%]">
                                        <col class="w-[22%]">
                                        <col class="w-[23%]">
                                    </colgroup>

                                    <thead>

                                        <tr
                                            class="bg-slate-50 text-slate-400 text-[10px] sm:text-[11px] uppercase tracking-wider font-bold border-b border-slate-200">

                                            <th
                                                class="px-2 sm:px-3 py-3">

                                                ID Number

                                            </th>

                                            <th
                                                class="px-2 sm:px-3 py-3">

                                                Student Name

                                            </th>

                                            <th
                                                class="px-2 sm:px-3 py-3 text-center">

                                                Score Entry

                                            </th>

                                            <th
                                                class="px-2 sm:px-3 py-3 text-center">

                                                Mark Absent

                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody
                                        class="divide-y divide-slate-100 text-xs sm:text-sm text-slate-600">

                                        <?php if(empty($students)): ?>

                                            <tr>

                                                <td
                                                    colspan="4"
                                                    class="px-4 py-6 text-center text-slate-400">

                                                    No enrollment records found matching these criteria.

                                                </td>

                                            </tr>

                                        <?php else: ?>

                                            <?php foreach(
                                                $students as $s
                                            ):

                                                $eid =
                                                    (int)$s['enrollment_id'];

                                                $m =
                                                    $marks[$eid] ?? null;

                                                $exists =
                                                    !is_null($m);

                                                $is_absent =
                                                    $exists
                                                        ? (($m['absent'] ?? 0) == 1)
                                                        : false;

                                                $display_score =
                                                    $exists
                                                        ? ($m['score'] ?? '')
                                                        : '';

                                            ?>

                                                <tr
                                                    id="row-<?= $eid ?>"
                                                    data-mark-status="<?= $exists ? 'saved' : 'pending' ?>"
                                                    class="student-row <?= $exists ? 'bg-slate-50/60' : '' ?> hover:bg-slate-50/50 transition">

                                                    <td
                                                        class="px-2 sm:px-3 py-3 text-[11px] sm:text-xs truncate">

                                                        <?= htmlspecialchars(
                                                            $s['student_id_number']
                                                        ) ?>

                                                    </td>

                                                    <td
                                                        class="px-2 sm:px-3 py-3 text-xs sm:text-sm truncate"
                                                        title="<?= htmlspecialchars($s['full_name']) ?>">

                                                        <?= htmlspecialchars(
                                                            $s['full_name']
                                                        ) ?>

                                                    </td>
<td
                                                        class="px-2 sm:px-3 py-3 text-center">

                                                        <input
                                                            type="number"
                                                            step="0.1"
                                                            min="0"
                                                            max="100"
                                                            inputmode="decimal"
                                                            class="w-20 text-center font-bold bg-slate-50 border border-slate-200 rounded-lg py-1.5 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50"
                                                            id="sc-<?= $eid ?>"
                                                            value="<?= htmlspecialchars($display_score) ?>"
                                                            <?= $exists ? 'disabled' : '' ?>
                                                            oninput="handleScoreInput(this, <?= $eid ?>)"
                                                            onblur="enforceMaximum(this, <?= $eid ?>)">

                                                    </td>
<td
                                                        class="px-2 sm:px-3 py-3 text-center">

                                                        <label
                                                            class="inline-flex items-center space-x-1.5 cursor-pointer text-[11px] font-medium text-slate-600">

                                                            <input
                                                                type="checkbox"
                                                                class="w-4 h-4 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500"
                                                                id="abs-<?= $eid ?>"
                                                                <?= $is_absent ? 'checked' : '' ?>
                                                                <?= $exists ? 'disabled' : '' ?>
                                                                onchange="toggleAbsent(<?= $eid ?>)">

                                                            <span
                                                                class="text-[10px] text-slate-500">

                                                                Absent

                                                            </span>

                                                        </label>

                                                    </td>
                                                </tr>

                                            <?php endforeach; ?>

                                        <?php endif; ?>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    <?php endif; ?>

                </main>
<footer
                    class="bg-white border-t border-slate-200 py-3 px-4 text-center text-[11px] text-slate-500">

                    <span>

                        Edulincore Technologies &reg;
                        <?= date('Y'); ?>.
                        All Rights Reserved.

                    </span>

                </footer>

            </div>

        </div>

    </div>
<script>

        const BATCH_SAVE_THRESHOLD = <?= (int)$batch_save_threshold ?>;

        const studentList =
            <?= json_encode(
            $students ?? [],
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;

        const missingStudentsList =
            <?= json_encode(
                $missing_students_js ?? [],
                JSON_HEX_TAG |
                JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT
            ) ?>;
        let currentMissingIndex = 0;

        const saveRequests = new Map();

        async function requestJsonWithRetry(url, options, attempts = 2) {
            let lastError;

            for (let attempt = 0; attempt < attempts; attempt++) {
                try {
                    const requestOptions = {
                        ...options,
                        signal: AbortSignal.timeout(attempt === 0 ? 30000 : 15000)
                    };
                    const response = await fetch(url, requestOptions);
                    const responseText = await response.text();
                    let json;

                    try {
                        json = JSON.parse(responseText);
                    } catch (parseError) {
                        throw new Error('The server returned an unexpected response.');
                    }

                    if (response.ok && json.success) {
                        return json;
                    }

                    const error = new Error(json.message || 'Unable to save the marks.');
                    error.retryable = json.retryable === true || response.status >= 500;
                    error.responseStatus = response.status;

                    if (!error.retryable || attempt === attempts - 1) {
                        throw error;
                    }

                    lastError = error;
                } catch (error) {
                    lastError = error;

                    if (error.name === 'AbortError' || attempt === attempts - 1) {
                        throw error;
                    }

                    if (error.retryable === false) {
                        throw error;
                    }
                }

                await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)));
            }

            throw lastError || new Error('Unable to save the marks.');
        }

        window.addEventListener('DOMContentLoaded', () => {
            if (window.location.hash) {
                const targetRow = document.querySelector(window.location.hash);

                if (targetRow) {
                    targetRow.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                        inline: 'nearest'
                    });

                    targetRow.classList.add('blink-highlight');

                    setTimeout(() => {
                        targetRow.classList.remove('blink-highlight');
                    }, 4000);
                }
            }
        });

        function jumpToNextMissing() {
            if (missingStudentsList.length === 0) {
                alert("All student marks have been fully completed or not started!");
                return;
            }

            const target = missingStudentsList[currentMissingIndex];

            currentMissingIndex =
                (currentMissingIndex + 1) % missingStudentsList.length;

            const row = document.getElementById(
                `row-${target.enrollment_id}`
            );

            if (row) {
                row.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                    inline: 'nearest'
                });

                row.classList.add('blink-highlight');

                setTimeout(() => {
                    row.classList.remove('blink-highlight');
                }, 4000);
            }
        }

        const sidebar =
            document.getElementById(
                'sidebar'
            );

        const sidebarToggle =
            document.getElementById(
                'sidebar-toggle'
            );

        const sidebarClose =
            document.getElementById(
                'sidebar-close'
            );

        const sidebarBackdrop =
            document.getElementById(
                'sidebar-backdrop'
            );

        function setSidebarOpen(isOpen) {
            if (!sidebar) return;

            const isDesktop = window.matchMedia('(min-width: 768px)').matches;

            if (isDesktop) {
                document.body.classList.toggle('sidebar-collapsed', !isOpen);
                sidebar.classList.remove('-translate-x-full');
            } else {
                sidebar.classList.toggle('-translate-x-full', !isOpen);
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.classList.toggle('hidden', !isOpen);
                sidebarBackdrop.classList.toggle('opacity-0', !isOpen);

                if (isOpen) {
                    requestAnimationFrame(() => sidebarBackdrop.classList.remove('opacity-0'));
                }
            }

            if (sidebarToggle) {
                sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        }

        function toggleSidebar(e) {

            if (e) {
                e.stopPropagation();
            }

            const isDesktop = window.matchMedia('(min-width: 768px)').matches;
            const currentlyOpen = isDesktop
                ? !document.body.classList.contains('sidebar-collapsed')
                : !sidebar.classList.contains('-translate-x-full');
            setSidebarOpen(!currentlyOpen);
        }

        if (sidebarToggle) {

            sidebarToggle.addEventListener(
                'click',
                toggleSidebar
            );
        }

        if (sidebarClose) {

            sidebarClose.addEventListener(
                'click',
                toggleSidebar
            );
        }

        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', () => setSidebarOpen(false));
        }

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                setSidebarOpen(false);
            }
        });

        function toggleFilters() {

            const body =
                document.getElementById(
                    'filter-body'
                );

            const icon =
                document.getElementById(
                    'chevron-icon'
                );

            body.classList.toggle(
                'hidden'
            );

            icon.classList.toggle(
                'rotate-180'
            );
        }

        function openSearch() {

            if (entryMode !== 'individual') {
                alert('Choose Individual Student before searching for a student.');
                return;
            }

            document
                .getElementById(
                    'search-overlay'
                )
                .classList.remove(
                    'hidden'
                );

            document
                .getElementById(
                    'student-search-input'
                )
                .focus();
        }

        function closeSearch() {

            document
                .getElementById(
                    'search-overlay'
                )
                .classList.add(
                    'hidden'
                );
        }

        function applyFilter() {

            const year =
                document.getElementById(
                    'filter-year'
                ).value;

            const term =
                document.getElementById(
                    'filter-term'
                ).value;

            const grade =
                document.getElementById(
                    'filter-grade'
                ).value;

            const classVal =
                document.getElementById(
                    'filter-class'
                ).value;

            const subject =
                document.getElementById(
                    'filter-subject'
                ).value;

            const assessment =
                document.getElementById(
                    'filter-assessment'
                ).value;

            const params =
                new URLSearchParams();

            if (year) {

                params.set(
                    'academic_year',
                    year
                );
            }

            if (term) {

                params.set(
                    'term',
                    term
                );
            }

            if (grade) {

                params.set(
                    'grade_level',
                    grade
                );
            }

            if (classVal) {

                params.set(
                    'class_name',
                    classVal
                );
            }

            if (subject) {

                params.set(
                    'subject_id',
                    subject
                );
            }

            if (assessment) {

                params.set(
                    'assessment_type',
                    assessment
                );
            }

            window.location.href =
                '?' +
                params.toString();
        }

        function handleScoreInput(
            input,
            eid
        ) {

            let val =
                input.value;

            let cleaned =
                val.replace(
                    /[^0-9.]/g,
                    ''
                );

            const firstDot =
                cleaned.indexOf('.');

            if (firstDot !== -1) {

                cleaned =
                    cleaned.substring(
                        0,
                        firstDot + 1
                    ) +

                    cleaned
                        .substring(
                            firstDot + 1
                        )
                        .replace(
                            /\./g,
                            ''
                        );
            }

            input.value =
                cleaned;

            input.setCustomValidity('');
        }

        function enforceMaximum(
            input,
            eid
        ) {

            const value =
                input.value.trim();

            if (value === '') {

                input.setCustomValidity('');

                return true;
            }

            const numericValue =
                Number(value);

            if (
                !Number.isFinite(
                    numericValue
                ) ||
                numericValue < 0 ||
                numericValue > 100
            ) {

                input.setCustomValidity(
                    'Marks only between 0-100.'
                );

                input.reportValidity();

                return false;
            }

            input.setCustomValidity('');

            return true;
        }

        function toggleAbsent(eid) {

            const absCheckbox =
                document.getElementById(
                    `abs-${eid}`
                );

            const scoreInput =
                document.getElementById(
                    `sc-${eid}`
                );

            if (absCheckbox.checked) {

                scoreInput.value =
                    '';

                scoreInput.disabled =
                    true;

            } else {

                scoreInput.disabled =
                    false;
            }
        }

        function filterStudents() {
            const input = document
                .getElementById('student-search-input')
                .value
                .toLowerCase()
                .trim();

            const resultsDiv = document.getElementById('search-results');
            resultsDiv.innerHTML = '';

            if (input.length < 2) {
                return;
            }

            const matches = studentList.filter(stu =>
                String(stu.full_name)
                    .toLowerCase()
                    .includes(input) ||
                String(stu.student_id_number)
                    .toLowerCase()
                    .includes(input)
            );

            if (matches.length === 0) {
                resultsDiv.innerHTML =
                    '<div class="p-3 text-slate-400 text-center">No students found</div>';
                return;
            }

            matches.forEach(stu => {
                const item = document.createElement('div');

                item.className =
                    'p-3 hover:bg-slate-50 cursor-pointer flex items-center justify-between transition';

                item.innerHTML =
                    `<div>
                        <strong class="text-slate-800">
                            ${escapeHtml(stu.full_name)}
                        </strong>
                        <span class="text-slate-400 block text-[10px]">
                            ${escapeHtml(stu.student_id_number)}
                        </span>
                    </div>
                    <span class="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded-md">
                        Save individual mark
                    </span>`;

                item.onclick = () => {
                    closeSearch();

                    enableIndividualSaveForRow(stu.enrollment_id);

                    const row = document.getElementById(
                        `row-${stu.enrollment_id}`
                    );

                    if (row) {
                        row.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center',
                            inline: 'nearest'
                        });

                        row.classList.add('blink-highlight');

                        setTimeout(() => {
                            row.classList.remove('blink-highlight');
                        }, 4000);
                    }
                };

                resultsDiv.appendChild(item);
            });
        }

        function escapeHtml(value) {

            return String(value)
                .replace(
                    /&/g,
                    '&amp;'
                )
                .replace(
                    /</g,
                    '&lt;'
                )
                .replace(
                    />/g,
                    '&gt;'
                )
                .replace(
                    /"/g,
                    '&quot;'
                )
                .replace(
                    /'/g,
                    '&#039;'
                );
        }

        let activeMarkView = 'pending';
        let entryMode = '';
        let selectedIndividualEid = null;
        let batchSaving = false;

        function getRow(eid) {
            return document.getElementById(`row-${eid}`);
        }

        function setEntryMode(mode) {
            entryMode = mode;
            selectedIndividualEid = null;

            const batchModeBtn = document.getElementById('batch-mode-btn');
            const individualModeBtn = document.getElementById('individual-mode-btn');
            const batchBtn = document.getElementById('batch-save-btn');
            const individualBtn = document.getElementById('individual-save-btn');
            const pendingViewBtn = document.getElementById('pending-view-btn');
            const savedViewBtn = document.getElementById('saved-view-btn');
            const help = document.getElementById('entry-mode-help');

            if (batchModeBtn && individualModeBtn) {
                const activeClass = 'bg-indigo-600 text-white border-indigo-600';
                const inactiveClass = 'bg-white text-slate-700 border-slate-300';
                const sharedClass = 'operation-button text-[11px] font-bold transition inline-flex items-center gap-2';
                batchModeBtn.className = `${sharedClass} ${mode === 'batch' ? activeClass : inactiveClass}`;
                individualModeBtn.className = `${sharedClass} ${mode === 'individual' ? activeClass : inactiveClass}`;
            }

            if (batchBtn) {
                batchBtn.classList.toggle('hidden', mode !== 'batch');
            }
            if (pendingViewBtn) pendingViewBtn.classList.toggle('hidden', mode !== 'batch');
            if (savedViewBtn) savedViewBtn.classList.toggle('hidden', mode !== 'batch');
            if (individualBtn) {
                individualBtn.classList.add('hidden');
                individualBtn.disabled = true;
            }

            if (mode === 'batch') {
                if (help) help.textContent = `Enter marks below and save at least ${BATCH_SAVE_THRESHOLD} students together.`;
                document.querySelectorAll('.student-row').forEach(row => {
                    row.style.display = row.dataset.markStatus === 'pending' ? '' : 'none';
                    const eid = row.id.replace('row-', '');
                    const scoreInput = document.getElementById(`sc-${eid}`);
                    const absentBox = document.getElementById(`abs-${eid}`);
                    const saved = row.dataset.markStatus === 'saved';
                    if (scoreInput) scoreInput.disabled = saved;
                    if (absentBox) absentBox.disabled = saved;
                });
                updateWorkflowCounts();
            } else if (mode === 'individual') {
                if (help) help.textContent = 'Search for one student, enter or edit the mark, then save that student.';
                document.querySelectorAll('.student-row').forEach(row => {
                    row.style.display = 'none';
                    const eid = row.id.replace('row-', '');
                    const scoreInput = document.getElementById(`sc-${eid}`);
                    const absentBox = document.getElementById(`abs-${eid}`);
                    if (scoreInput) scoreInput.disabled = true;
                    if (absentBox) absentBox.disabled = true;
                });
                openSearch();
            } else {
                if (help) help.textContent = 'Choose Batch Submission or Individual Student to begin.';
                document.querySelectorAll('.student-row').forEach(row => {
                    row.style.display = 'none';
                    const eid = row.id.replace('row-', '');
                    const scoreInput = document.getElementById(`sc-${eid}`);
                    const absentBox = document.getElementById(`abs-${eid}`);
                    if (scoreInput) scoreInput.disabled = true;
                    if (absentBox) absentBox.disabled = true;
                });
            }
        }

        function enableIndividualSaveForRow(eid) {
            const row = getRow(eid);
            const saveBtn = document.getElementById('individual-save-btn');

            if (!row || !saveBtn) return;

            selectedIndividualEid = Number(eid);
            row.style.display = '';
            const scoreInput = document.getElementById(`sc-${eid}`);
            const absentBox = document.getElementById(`abs-${eid}`);
            const canEdit = row.dataset.markStatus === 'saved';

            if (scoreInput) scoreInput.disabled = false;
            if (absentBox) absentBox.disabled = false;

            saveBtn.classList.remove('hidden');
            saveBtn.disabled = false;
            saveBtn.querySelector('span').textContent = canEdit ? 'Update Student Mark' : 'Save Student Mark';
        }

        function saveSelectedIndividualMark() {
            if (entryMode !== 'individual' || selectedIndividualEid === null) return;
            saveMark(selectedIndividualEid);
        }

        function getPendingBatchEntries() {
            const entries = [];

            document.querySelectorAll('.student-row[data-mark-status="pending"]').forEach(row => {
                const eid = row.id.replace('row-', '');
                const scoreInput = document.getElementById(`sc-${eid}`);
                const absentBox = document.getElementById(`abs-${eid}`);

                if (!scoreInput || !absentBox) return;

                const score = scoreInput.value.trim();
                const absent = absentBox.checked ? 1 : 0;
                if (absent || score !== '') {
                    entries.push({
                        enrollment_id: Number(eid),
                        score: absent ? '' : score,
                        absent: absent
                    });
                }
            });

            return entries;
        }

        function updateWorkflowCounts() {
            const pendingRows = document.querySelectorAll('.student-row[data-mark-status="pending"]').length;
            const savedRows = document.querySelectorAll('.student-row[data-mark-status="saved"]').length;
            const batchEntries = getPendingBatchEntries();

            const pendingBadge = document.getElementById('pending-count-badge');
            const savedBadge = document.getElementById('saved-count-badge');
            const batchBtn = document.getElementById('batch-save-btn');
            const status = document.getElementById('batch-selection-status');

            if (pendingBadge) pendingBadge.textContent = `Pending: ${pendingRows}`;
            if (savedBadge) savedBadge.textContent = `Saved: ${savedRows}`;

            if (batchBtn) {
                batchBtn.disabled = batchEntries.length < BATCH_SAVE_THRESHOLD || batchSaving;
                const label = batchBtn.querySelector('span');
                if (label && !batchSaving) {
                    label.textContent = batchEntries.length >= BATCH_SAVE_THRESHOLD
                        ? `Save Batch (${batchEntries.length})`
                        : 'Save Batch';
                }
            }

            if (status) {
                if (batchEntries.length >= BATCH_SAVE_THRESHOLD) {
                    status.className = 'mt-3 text-[10px] font-semibold text-amber-600';
                    status.textContent = `${batchEntries.length} students ready for batch saving.`;
                } else if (batchEntries.length > 0) {
                    status.className = 'mt-3 text-[10px] font-semibold text-indigo-600';
                    status.textContent = `${batchEntries.length} student${batchEntries.length === 1 ? '' : 's'} entered; add ${BATCH_SAVE_THRESHOLD - batchEntries.length} more for batch saving.`;
                } else {
                    status.className = 'mt-3 hidden text-[10px] font-semibold';
                    status.textContent = '';
                }
            }
        }

        function showMarkView(view) {
            if (entryMode !== 'batch') return;

            activeMarkView = view;

            document.querySelectorAll('.student-row').forEach(row => {
                row.style.display = row.dataset.markStatus === view ? '' : 'none';
            });

            const pendingBtn = document.getElementById('pending-view-btn');
            const savedBtn = document.getElementById('saved-view-btn');

            if (pendingBtn && savedBtn) {
                if (view === 'pending') {
                    pendingBtn.className = 'operation-button bg-amber-100 text-amber-800 border-amber-300 text-[11px] font-bold transition';
                    savedBtn.className = 'operation-button bg-white text-slate-700 border-slate-300 text-[11px] font-bold transition';
                } else {
                    pendingBtn.className = 'operation-button bg-white text-slate-700 border-slate-300 text-[11px] font-bold transition';
                    savedBtn.className = 'operation-button bg-emerald-100 text-emerald-800 border-emerald-300 text-[11px] font-bold transition';
                }
            }
        }

        function setRowSaved(eid, storedScore, absent) {
            const row = getRow(eid);
            const scoreInput = document.getElementById(`sc-${eid}`);
            const absentBox = document.getElementById(`abs-${eid}`);

            if (!row || !scoreInput || !absentBox) return;

            row.dataset.markStatus = 'saved';
            row.classList.add('bg-slate-50/60');

            if (!absent) scoreInput.value = storedScore ?? scoreInput.value;
            scoreInput.disabled = true;
            absentBox.disabled = true;

            if (selectedIndividualEid === Number(eid)) {
                const individualBtn = document.getElementById('individual-save-btn');
                if (individualBtn) {
                    individualBtn.disabled = false;
                    individualBtn.querySelector('span').textContent = 'Update Student Mark';
                }
            }
        }

        function showBatchErrors(errors) {
            if (!Array.isArray(errors) || errors.length === 0) return;

            const messages = errors.slice(0, 10).map(item => {
                const eid = item.enrollment_id ? `Student ${item.enrollment_id}` : 'Student';
                return `${eid}: ${item.message || 'Unable to save.'}`;
            });

            if (errors.length > 10) {
                messages.push(`...and ${errors.length - 10} more.`);
            }

            alert('Some marks were not saved:\n\n' + messages.join('\n'));
        }

        async function saveBatch() {
            if (entryMode !== 'batch' || batchSaving) return;

            const entries = getPendingBatchEntries();

            if (entries.length < BATCH_SAVE_THRESHOLD) {
                return;
            }
            const confirmed = confirm(
                `Confirm Marks Submission\n\nYou are about to save marks for ${entries.length} students.\n\nPlease confirm that the marks entered are correct.\n\nClick OK to save these marks or Cancel to review them.`
            );

            if (!confirmed) return;
            for (const entry of entries) {
                if (!entry.absent) {
                    const n = Number(entry.score);
                    if (!Number.isFinite(n) || n < 0 || n > 100) {
                        const input = document.getElementById(`sc-${entry.enrollment_id}`);
                        if (input) {
                            input.setCustomValidity('Marks only between 0-100.');
                            input.reportValidity();
                            input.focus();
                        }
                        alert('Marks only between 0-100.');
                        return;
                    }
                }
            }

            const btn = document.getElementById('batch-save-btn');
            const originalLabel = btn ? btn.innerHTML : '';
            batchSaving = true;

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner animate-spin"></i><span>Saving Batch...</span>';
            }

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
                const json = await requestJsonWithRetry(window.location.href, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const savedIds = Array.isArray(json.saved_ids) ? json.saved_ids : [];

                savedIds.forEach(eid => {
                    const scoreInput = document.getElementById(`sc-${eid}`);
                    const absentBox = document.getElementById(`abs-${eid}`);
                    const absent = absentBox && absentBox.checked;
                    setRowSaved(eid, scoreInput ? scoreInput.value : '', absent);
                });

                showBatchErrors(json.errors);

                const toast = document.getElementById('success-toast');
                if (toast) {
                    toast.innerText = json.error_count > 0
                        ? `${json.saved_count} saved; ${json.error_count} need attention`
                        : `${json.saved_count} Marks Saved Successfully`;
                    toast.classList.remove('hidden');
                    setTimeout(() => toast.classList.add('hidden'), 3000);
                }

                updateWorkflowCounts();
                showMarkView('pending');

            } catch (e) {
                if (e.name === 'AbortError') {
                    alert('The server took too long to respond. Please try saving the batch again.');
                } else {
                    alert(e.message || 'Unable to save the batch. Please try again.');
                }
            } finally {
                batchSaving = false;
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalLabel || '<i class="fa-solid fa-layer-group"></i><span>Save Batch</span>';
                }
                updateWorkflowCounts();
            }
        }

        async function saveMark(eid) {
            if (saveRequests.has(eid)) {
                return;
            }

            const row = getRow(eid);
            if (entryMode !== 'individual' || selectedIndividualEid !== Number(eid) || !row) {
                return;
            }

            const btn = document.getElementById('individual-save-btn');
            const scoreInput = document.getElementById(`sc-${eid}`);
            const absentBox = document.getElementById(`abs-${eid}`);

            if (!btn || !scoreInput || !absentBox) {
                return;
            }

            const absent = absentBox.checked ? 1 : 0;
            const score = absent ? '' : scoreInput.value.trim();
            if (!absent && score === '') {
                alert('Please enter a mark or select Absent.');
                scoreInput.focus();
                return;
            }
            if (!absent) {
                const numericScore = Number(score);

                if (
                    !Number.isFinite(numericScore) ||
                    numericScore < 0 ||
                    numericScore > 100
                ) {
                    scoreInput.setCustomValidity('Marks only between 0-100.');
                    scoreInput.reportValidity();
                    alert('Marks only between 0-100.');
                    scoreInput.focus();
                    return;
                }

                scoreInput.setCustomValidity('');
            }

            const fd = new FormData();

            fd.append('action', 'save_mark');
            fd.append('csrf_token', <?= json_encode($csrf_token) ?>);
            fd.append('enrollment_id', String(eid));
            fd.append('score', score);
            fd.append('absent', String(absent));
            fd.append('subject_id', <?= json_encode((string)$subject) ?>);
            fd.append('class_name', <?= json_encode((string)$class) ?>);
            fd.append('academic_year', <?= json_encode((string)$year) ?>);
            fd.append('term', <?= json_encode((string)$term) ?>);
            fd.append('assessment_type', <?= json_encode((string)$assessment) ?>);

            saveRequests.set(eid, true);

            const originalText = btn.innerHTML;

            btn.innerHTML =
                '<i class="fa-solid fa-spinner animate-spin"></i><span>Saving...</span>';
            btn.disabled = true;

            try {
                const json = await requestJsonWithRetry(window.location.href, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const toast =
                    document.getElementById('success-toast');

                if (json.deleted) {
                    toast.innerText =
                        'Mark Removed Successfully';

                    btn.innerHTML = '<i class="fa-solid fa-user-check"></i><span>Save Student Mark</span>';
                    btn.disabled = false;

                    scoreInput.value = '';
                    scoreInput.disabled = false;
                    absentBox.disabled = false;

                    const deletedRow = document.getElementById(`row-${eid}`);
                    if (deletedRow) {
                        deletedRow.dataset.markStatus = 'pending';
                        deletedRow.classList.remove('bg-slate-50/60');
                    }

                } else {
                    toast.innerText =
                        json.operation === 'updated'
                            ? 'Mark Updated Successfully'
                            : 'Mark Saved Successfully';

                    if (
                        json.stored_score !== undefined &&
                        !absent
                    ) {
                        scoreInput.value = json.stored_score;
                    }

                    btn.innerHTML =
                        '<i class="fa-solid fa-user-check"></i><span>Update Student Mark</span>';

                    btn.disabled = false;

                    document
                        .getElementById(`row-${eid}`)
                        .classList.add('bg-slate-50/60');

                    const savedRow = document.getElementById(`row-${eid}`);
                    if (savedRow) savedRow.dataset.markStatus = 'saved';

                    scoreInput.disabled = true;
                    absentBox.disabled = true;
                }

                toast.classList.remove('hidden');

                setTimeout(() => {
                    toast.classList.add('hidden');
                }, 2000);

            } catch (e) {
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (e.name === 'AbortError') {
                    alert(
                        'The server took too long to respond. Please try saving this mark again.'
                    );
                } else {
                    alert(
                        e.message ||
                        'Unable to save mark. Please try again.'
                    );
                }

            } finally {
                saveRequests.delete(eid);
                updateWorkflowCounts();
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            setEntryMode('');
            updateWorkflowCounts();
        });
        document.addEventListener('input', event => {
            if (event.target && event.target.id && event.target.id.startsWith('sc-')) {
                updateWorkflowCounts();
            }
        });

        document.addEventListener('change', event => {
            if (event.target && event.target.id && event.target.id.startsWith('abs-')) {
                updateWorkflowCounts();
            }
        });

        async function resetAllMarks() {

            if (
                !confirm(
                    "Are you sure you want to reset and delete ALL entered marks for this current filter view?"
                )
            ) {
                return;
            }

            const fd =
                new FormData();

            fd.append(
                'action',
                'reset_marks'
            );

            fd.append(
                'csrf_token',
                <?= json_encode($csrf_token) ?>
            );

            fd.append(
                'subject_id',
                <?= json_encode((string)$subject) ?>
            );

            fd.append(
                'class_name',
                <?= json_encode((string)$class) ?>
            );

            fd.append(
                'academic_year',
                <?= json_encode((string)$year) ?>
            );

            fd.append(
                'term',
                <?= json_encode((string)$term) ?>
            );

            fd.append(
                'assessment_type',
                <?= json_encode((string)$assessment) ?>
            );

            try {

                const res =
                    await fetch(
                        window.location.href,
                        {
                            method: 'POST',
                            body: fd,
                            credentials:
                                'same-origin',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',
                                'Accept':
                                    'application/json'
                            }
                        }
                    );

                const responseText =
                    await res.text();

                let json;

                try {

                    json =
                        JSON.parse(
                            responseText
                        );

                } catch (parseError) {

                    console.error(
                        'Reset returned non-JSON response:',
                        responseText
                    );

                    throw new Error(
                        'The server returned an unexpected response.'
                    );
                }

                if (
                    json.success
                ) {

                    window.location.reload();

                } else {

                    alert(
                        json.message ||
                        "Error resetting marks."
                    );
                }

            } catch(e) {

                alert(
                    e.message ||
                    "Server Error"
                );
            }
        }

    </script>

</body>

</html>