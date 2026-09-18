<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'school_it'
) {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;

if (!$school_id) {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$db = Database::getConnection();


/* =========================================================
   HELPERS
   ========================================================= */

function isPrimary($grade_level)
{
    $value = trim((string)$grade_level);

    if ($value === '' || $value === '77') {
        return false;
    }

    if (preg_match('/^Grade\s*([1-7])$/i', $value, $m)) {
        return true;
    }

    if (ctype_digit($value)) {
        $number = (int)$value;
        return $number >= 1 && $number <= 7;
    }

    return false;
}


function isAbsentMark($score)
{
    return (
        $score !== null &&
        $score !== '' &&
        is_numeric($score) &&
        (float)$score <= 0
    );
}


/*
 * Returns only the pupil's actual numeric/present marks.
 *
 * IMPORTANT:
 * These marks are used for the NUMERATOR only.
 * The denominator is handled separately using the
 * total number of assessments administered.
 */
function getPresentScores($sub_marks, $active_assessments)
{
    $present = [];

    foreach ($active_assessments as $assessment) {

        if (!array_key_exists($assessment, $sub_marks)) {
            continue;
        }

        $score = $sub_marks[$assessment];

        if (
            $score === null ||
            $score === '' ||
            !is_numeric($score)
        ) {
            continue;
        }

        if ((float)$score <= 0) {
            continue;
        }

        $present[] = (float)$score;
    }

    return $present;
}


/*
 * IMPORTANT AVERAGE RULE
 *
 * The denominator is NOT the number of marks the pupil sat.
 *
 * It is the total number of assessments that were administered
 * for the subject/class.
 *
 * Example:
 *
 * Test 1 = 94
 * Test 2 = X
 * End of Term Test = X
 *
 * 94 / 3 = 31.33 => 31
 *
 * Therefore X contributes zero to the numerator, but the
 * assessment still remains in the denominator.
 */
function calculateSubjectAverage($sub_marks, $active_assessments)
{
    $scores = getPresentScores(
        $sub_marks,
        $active_assessments
    );

    /*
     * No numeric mark at all.
     * Keep X so the report can identify that no assessment
     * result has been recorded for the pupil.
     */
    if (empty($scores)) {
        return 'X';
    }

    /*
     * CRITICAL:
     * Divide by the number of assessments administered,
     * NOT by the number of assessments the pupil sat.
     */
    $assessment_count = count($active_assessments);

    if ($assessment_count <= 0) {
        return 'X';
    }

    return round(
        array_sum($scores) / $assessment_count
    );
}


function assessmentDisplayMark($sub_marks, $assessment)
{
    if (!array_key_exists($assessment, $sub_marks)) {
        return '-';
    }

    $score = $sub_marks[$assessment];

    if ($score === null || $score === '') {
        return '-';
    }

    if (isAbsentMark($score)) {
        return 'X';
    }

    return htmlspecialchars(
        (string)$score,
        ENT_QUOTES,
        'UTF-8'
    );
}


function getGradeAndPoint($average, $isPrimary)
{
    if ($average === 'X') {
        return [
            'grade' => 'ABSENT',
            'point' => 'X'
        ];
    }

    if ($isPrimary) {

        if ($average >= 80) {
            return [
                'grade' => 'Excellent',
                'point' => ''
            ];
        }

        if ($average >= 70) {
            return [
                'grade' => 'Very Good',
                'point' => ''
            ];
        }

        if ($average >= 60) {
            return [
                'grade' => 'Good',
                'point' => ''
            ];
        }

        if ($average >= 50) {
            return [
                'grade' => 'Satisfactory',
                'point' => ''
            ];
        }

        if ($average >= 40) {
            return [
                'grade' => 'Fair',
                'point' => ''
            ];
        }

        return [
            'grade' => 'Unsatisfactory',
            'point' => ''
        ];
    }

    if ($average >= 75) {
        return [
            'grade' => 'DISTINCTION',
            'point' => '1'
        ];
    }

    if ($average >= 70) {
        return [
            'grade' => 'DISTINCTION',
            'point' => '2'
        ];
    }

    if ($average >= 65) {
        return [
            'grade' => 'MERIT',
            'point' => '3'
        ];
    }

    if ($average >= 60) {
        return [
            'grade' => 'MERIT',
            'point' => '4'
        ];
    }

    if ($average >= 55) {
        return [
            'grade' => 'CREDIT',
            'point' => '5'
        ];
    }

    if ($average >= 50) {
        return [
            'grade' => 'CREDIT',
            'point' => '6'
        ];
    }

    if ($average >= 45) {
        return [
            'grade' => 'SATISFACTORY',
            'point' => '7'
        ];
    }

    if ($average >= 40) {
        return [
            'grade' => 'SATISFACTORY',
            'point' => '8'
        ];
    }

    return [
        'grade' => 'UNSATISFACTORY',
        'point' => '9'
    ];
}


function overallRemark(
    $avg,
    $student_name,
    $grade_level,
    $gender
) {
    $g = strtoupper(trim((string)$gender));

    $possessive =
        (
            $g === 'FEMALE' ||
            $g === 'F'
        )
        ? 'Her'
        : 'His';

    $subjective =
        (
            $g === 'FEMALE' ||
            $g === 'F'
        )
        ? 'She'
        : 'He';

    if ($avg === 'X') {
        return "$student_name has no valid assessment mark recorded for this report. The pupil should be assessed and the relevant marks entered.";
    }

    if (isPrimary($grade_level)) {

        if ($avg >= 80) {
            return "$student_name has demonstrated exceptional academic capability this term. $possessive outstanding performance reflects strong discipline, focus and understanding of concepts. Keep up this excellent standard.";
        }

        if ($avg >= 70) {
            return "$student_name has produced very good and commendable work throughout the term. $subjective shows a clear understanding of the subject matter and is making steady academic progress.";
        }

        if ($avg >= 60) {
            return "$student_name has achieved a good and solid performance this term. $subjective should continue working consistently and participate actively in lessons to improve further.";
        }

        if ($avg >= 50) {
            return "$student_name has recorded a satisfactory performance. More focused study, regular revision and consistent classroom participation should help improve the results next term.";
        }

        if ($avg >= 40) {
            return "$student_name's performance is fair but remains close to the borderline level. More effort, regular attendance and thorough revision are strongly encouraged.";
        }

        return "$student_name has recorded an unsatisfactory result this term. $possessive performance is below the required standard and requires immediate academic support and increased revision.";
    }

    if ($avg >= 75) {
        return "An excellent performance from $student_name. $subjective has demonstrated strong analytical ability and consistently completed academic tasks to a very high standard.";
    }

    if ($avg >= 70) {
        return "$student_name has produced a very good and commendable performance. $subjective has demonstrated dedication to school work and should continue strengthening weaker areas.";
    }

    if ($avg >= 65) {
        return "$student_name has recorded a good and steady academic performance. Continued revision, group study and focused preparation should help maximize $possessive potential.";
    }

    if ($avg >= 60) {
        return "$student_name has maintained a solid and respectable standard across the assessed subjects. $subjective should continue working consistently to raise $possessive aggregate.";
    }

    if ($avg >= 55) {
        return "$student_name is performing reasonably well but should work on consistency and strengthen understanding of difficult concepts.";
    }

    if ($avg >= 50) {
        return "$student_name has achieved an acceptable baseline result. $subjective should develop stronger study habits and remain focused on academic work.";
    }

    if ($avg >= 45) {
        return "$student_name's performance is below $possessive potential. A structured revision programme and closer academic attention are recommended.";
    }

    if ($avg >= 40) {
        return "$student_name has obtained a weak borderline pass. $possessive result indicates insufficient preparation and gaps in subject understanding. Immediate improvement is required.";
    }

    return "$student_name has performed at an unsatisfactory level. The result does not meet the required passing standard and intensive academic support is recommended.";
}


/* =========================================================
   SCHOOL INFORMATION
   ========================================================= */

$stmt = $db->prepare(
    "SELECT *
     FROM schools
     WHERE id = ?
     LIMIT 1"
);

$stmt->execute([$school_id]);

$ctx = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];


/* =========================================================
   FILTERS
   ========================================================= */

$grade       = $_GET['grade'] ?? '';
$class       = $_GET['class'] ?? '';
$term        = $_GET['term'] ?? 'Term 1';
$year        = $_GET['year'] ?? date('Y');
$term_closes = $_GET['term_closes'] ?? '';
$next_term   = $_GET['next_term'] ?? '';
$search      = trim($_GET['search'] ?? '');


/*
 * Never allow grade 77 into the report-card page.
 */
if ((string)$grade === '77') {
    $grade = '';
    $class = '';
}


/* =========================================================
   GRADES
   ========================================================= */

$stmt = $db->prepare(
    "SELECT DISTINCT grade_level
     FROM student_enrollments
     WHERE school_id = ?
       AND TRIM(CAST(grade_level AS CHAR)) <> '77'
     ORDER BY grade_level"
);

$stmt->execute([$school_id]);

$grades = $stmt->fetchAll(PDO::FETCH_COLUMN);


/* =========================================================
   CLASSES
   ========================================================= */

$classes = [];

if ($grade !== '') {

    $stmt = $db->prepare(
        "SELECT DISTINCT class_name
         FROM student_enrollments
         WHERE school_id = ?
           AND grade_level = ?
           AND TRIM(CAST(grade_level AS CHAR)) <> '77'
         ORDER BY class_name"
    );

    $stmt->execute([
        $school_id,
        $grade
    ]);

    $classes =
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );
}


/* =========================================================
   YEARS
   ========================================================= */

$stmt = $db->prepare(
    "SELECT DISTINCT academic_year
     FROM student_enrollments
     WHERE school_id = ?
     ORDER BY academic_year DESC"
);

$stmt->execute([$school_id]);

$years =
    $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );


/* =========================================================
   STUDENTS IN SELECTED CLASS
   ========================================================= */

$students = [];

if (
    $grade !== '' &&
    $class !== '' &&
    $year !== ''
) {

    $sql = "
        SELECT
            s.id,
            s.full_name,
            s.gender,
            se.grade_level,
            se.class_name
        FROM students s
        JOIN student_enrollments se
            ON se.student_id = s.id
        WHERE se.school_id = ?
          AND se.grade_level = ?
          AND se.class_name = ?
          AND se.academic_year = ?
          AND TRIM(CAST(se.grade_level AS CHAR)) <> '77'
    ";

    $params = [
        $school_id,
        $grade,
        $class,
        $year
    ];

    if ($search !== '') {

        $sql .= "
            AND s.full_name LIKE ?
        ";

        $params[] =
            "%{$search}%";
    }

    $sql .= "
        ORDER BY s.full_name
    ";

    $stmt =
        $db->prepare($sql);

    $stmt->execute($params);

    $students =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/* =========================================================
   SUBJECTS
   ========================================================= */

$subjects = [];
$teacher_names = [];

if (
    $grade !== '' &&
    $class !== ''
) {

    $stmt = $db->prepare(
        "SELECT
            s.id AS subject_id,
            s.subject_name,
            ta.teacher_id
         FROM teacher_assignments ta
         JOIN subjects s
            ON s.id = ta.subject_id
         WHERE ta.school_id = ?
           AND ta.grade_level = ?
           AND ta.class_name = ?
         ORDER BY s.subject_name"
    );

    $stmt->execute([
        $school_id,
        $grade,
        $class
    ]);

    foreach (
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) as $r
    ) {

        $subject_id =
            (int)$r['subject_id'];

        $subjects[$subject_id] =
            $r['subject_name'];

        $t = $db->prepare(
            "SELECT full_name
             FROM teachers
             WHERE id = ?
             LIMIT 1"
        );

        $t->execute([
            $r['teacher_id']
        ]);

        $fname =
            $t->fetchColumn();

        if ($fname) {

            $parts =
                preg_split(
                    '/\s+/',
                    trim($fname)
                );

            $first =
                $parts[0] ?? '';

            $initial = '';

            if (!empty($parts[1])) {

                $initial =
                    strtoupper(
                        substr(
                            $parts[1],
                            0,
                            1
                        )
                    ) . '.';
            }

            $teacher_names[
                $subject_id
            ] =
                trim(
                    $first .
                    ' ' .
                    $initial
                );
        }
    }
}


/* =========================================================
   ASSESSMENTS
   ========================================================= */

$assessment_order = [
    'Test 1',
    'Test 2',
    'End of Term Test'
];

$marks = [];

$active_assessments = [
    'Test 1' => false,
    'Test 2' => false,
    'End of Term Test' => false
];


if (
    !empty($students) &&
    !empty($subjects)
) {

    $student_ids =
        array_column(
            $students,
            'id'
        );

    $subject_ids =
        array_keys($subjects);

    if (
        !empty($student_ids) &&
        !empty($subject_ids)
    ) {

        $student_placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($student_ids),
                    '?'
                )
            );

        $subject_placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($subject_ids),
                    '?'
                )
            );

        $sql = "
            SELECT
                student_id,
                subject_id,
                assessment_type,
                score
            FROM marks
            WHERE school_id = ?
              AND student_id IN (
                  $student_placeholders
              )
              AND subject_id IN (
                  $subject_placeholders
              )
              AND term = ?
              AND academic_year = ?
        ";

        $params =
            array_merge(
                [$school_id],
                $student_ids,
                $subject_ids,
                [$term, $year]
            );

        $stmt =
            $db->prepare($sql);

        $stmt->execute($params);

        foreach (
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) as $r
        ) {

            $assessment =
                trim(
                    (string)
                    $r['assessment_type']
                );

            if (
                !isset(
                    $active_assessments[
                        $assessment
                    ]
                )
            ) {
                continue;
            }

            $marks[
                $r['student_id']
            ][
                $r['subject_id']
            ][
                $assessment
            ] =
                $r['score'];

            $active_assessments[
                $assessment
            ] = true;
        }
    }
}


$display_assessments = [];

foreach (
    $assessment_order
    as $assessment
) {

    if (
        !empty(
            $active_assessments[
                $assessment
            ]
        )
    ) {
        $display_assessments[] =
            $assessment;
    }
}


$assessment_heading =
    !empty($display_assessments)
        ? implode(
            ', ',
            $display_assessments
        )
        : 'NO ASSESSMENT RECORDED';


/* =========================================================
   CLASS POSITION
   ========================================================= */

$student_totals = [];
$all_class_students = [];

if (
    $grade !== '' &&
    $class !== '' &&
    $year !== ''
) {

    $stmt = $db->prepare(
        "SELECT
            s.id,
            s.full_name,
            s.gender,
            se.grade_level,
            se.class_name
         FROM students s
         JOIN student_enrollments se
            ON se.student_id = s.id
         WHERE se.school_id = ?
           AND se.grade_level = ?
           AND se.class_name = ?
           AND se.academic_year = ?
           AND TRIM(CAST(se.grade_level AS CHAR)) <> '77'
         ORDER BY s.full_name"
    );

    $stmt->execute([
        $school_id,
        $grade,
        $class,
        $year
    ]);

    $all_class_students =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


$all_student_ids =
    array_column(
        $all_class_students,
        'id'
    );

$all_marks = [];


if (
    !empty($all_student_ids) &&
    !empty($subjects)
) {

    $student_placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($all_student_ids),
                '?'
            )
        );

    $subject_ids =
        array_keys($subjects);

    $subject_placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($subject_ids),
                '?'
            )
        );

    $sql = "
        SELECT
            student_id,
            subject_id,
            assessment_type,
            score
        FROM marks
        WHERE school_id = ?
          AND student_id IN (
              $student_placeholders
          )
          AND subject_id IN (
              $subject_placeholders
          )
          AND term = ?
          AND academic_year = ?
    ";

    $stmt =
        $db->prepare($sql);

    $stmt->execute(
        array_merge(
            [$school_id],
            $all_student_ids,
            $subject_ids,
            [$term, $year]
        )
    );

    foreach (
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) as $r
    ) {

        $assessment =
            trim(
                (string)
                $r['assessment_type']
            );

        if (
            !in_array(
                $assessment,
                $assessment_order,
                true
            )
        ) {
            continue;
        }

        $all_marks[
            $r['student_id']
        ][
            $r['subject_id']
        ][
            $assessment
        ] =
            $r['score'];
    }
}


foreach (
    $all_class_students as $stu
) {

    $sid =
        $stu['id'];

    $total_marks = 0;

    $count_assessed_subjects = 0;

    $count_subjects =
        count($subjects);

    $subjects_passed = 0;

    $is_pri =
        isPrimary(
            $stu['grade_level']
        );

    $compulsory_points = [];
    $science_points = [];
    $other_points = [];


    foreach (
        $subjects as
        $sub_id =>
        $sub_name
    ) {

        $sub_marks =
            $all_marks[
                $sid
            ][
                $sub_id
            ] ?? [];


        /*
         * IMPORTANT:
         * calculateSubjectAverage() now divides the
         * pupil's numeric marks by the TOTAL NUMBER
         * OF ADMINISTERED ASSESSMENTS.
         */
        $avg =
            calculateSubjectAverage(
                $sub_marks,
                $display_assessments
            );


        if ($avg !== 'X') {

            $total_marks +=
                $avg;

            $count_assessed_subjects++;


            if ($is_pri) {

                if ($avg >= 40) {
                    $subjects_passed++;
                }

            } else {

                if ($avg >= 40) {
                    $subjects_passed++;
                }

                $clean_name =
                    strtoupper(
                        trim($sub_name)
                    );


                if (
                    strpos(
                        $clean_name,
                        'MATH'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'MATHEMATICS'
                    ) !== false
                ) {

                    $point =
                        getGradeAndPoint(
                            $avg,
                            false
                        )['point'];

                    $compulsory_points[
                        'math'
                    ] =
                        (int)$point;

                } elseif (
                    strpos(
                        $clean_name,
                        'ENGLISH'
                    ) !== false
                ) {

                    $point =
                        getGradeAndPoint(
                            $avg,
                            false
                        )['point'];

                    $compulsory_points[
                        'english'
                    ] =
                        (int)$point;

                } elseif (
                    strpos(
                        $clean_name,
                        'BIOLOGY'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'BIO'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'PHYSICS'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'PHY'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'CHEMISTRY'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'CHEM'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'AGRICULTURAL SCIENCE'
                    ) !== false ||
                    strpos(
                        $clean_name,
                        'AGRIC'
                    ) !== false
                ) {

                    $point =
                        getGradeAndPoint(
                            $avg,
                            false
                        )['point'];

                    $science_points[] =
                        (int)$point;

                } else {

                    $point =
                        getGradeAndPoint(
                            $avg,
                            false
                        )['point'];

                    $other_points[] =
                        (int)$point;
                }
            }
        }
    }


    if (!$is_pri) {

        $best_six_pool = [];

        $best_six_pool[] =
            $compulsory_points[
                'math'
            ] ?? 9;

        $best_six_pool[] =
            $compulsory_points[
                'english'
            ] ?? 9;


        if (!empty($science_points)) {

            sort($science_points);

            $best_six_pool[] =
                array_shift(
                    $science_points
                );

            $other_points =
                array_merge(
                    $other_points,
                    $science_points
                );

        } else {

            $best_six_pool[] =
                9;
        }


        sort($other_points);


        while (
            count(
                $best_six_pool
            ) < 6
        ) {

            if (
                !empty($other_points)
            ) {

                $best_six_pool[] =
                    array_shift(
                        $other_points
                    );

            } else {

                $best_six_pool[] =
                    9;
            }
        }


        $final_points =
            array_sum(
                $best_six_pool
            );

    } else {

        $final_points =
            'N/A';
    }


    $overall_average =
        $count_assessed_subjects > 0
            ? round(
                $total_marks /
                $count_assessed_subjects
            )
            : 'X';


    $student_totals[$sid] = [

        'total_marks' =>
            round($total_marks),

        'avg_marks' =>
            $overall_average,

        'subjects_assessed' =>
            $count_assessed_subjects,

        'points' =>
            $final_points,

        'subjects_recorded' =>
            $count_subjects,

        'subjects_passed' =>
            $subjects_passed,

        'certification' =>
            $subjects_passed >= 6
                ? 'Certificate'
                : 'Statement'
    ];
}


/* =========================================================
   CLASS RANKING
   ========================================================= */

$positions =
    $student_totals;

uasort(
    $positions,
    function ($a, $b) {
        return $b['total_marks']
            <=> $a['total_marks'];
    }
);

$rank = 1;
$sid_ranks = [];

foreach (
    $positions
    as $sid =>
    $vals
) {

    $sid_ranks[$sid] =
        $rank++;
}


/* =========================================================
   SCHOOL-WIDE GRADE POSITION
   ========================================================= */

$school_grade_totals = [];

if (
    $grade !== '' &&
    $year !== ''
) {

    $stmt = $db->prepare(
        "SELECT
            s.id,
            s.full_name,
            s.gender,
            se.grade_level,
            se.class_name
         FROM students s
         JOIN student_enrollments se
            ON se.student_id = s.id
         WHERE se.school_id = ?
           AND se.grade_level = ?
           AND se.academic_year = ?
           AND TRIM(CAST(se.grade_level AS CHAR)) <> '77'
         ORDER BY s.full_name"
    );

    $stmt->execute([
        $school_id,
        $grade,
        $year
    ]);

    $school_grade_students =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $school_student_ids =
        array_column(
            $school_grade_students,
            'id'
        );


    $school_subjects = [];


    if (
        !empty(
            $school_grade_students
        )
    ) {

        $stmt = $db->prepare(
            "SELECT DISTINCT
                s.id AS subject_id,
                s.subject_name
             FROM teacher_assignments ta
             JOIN subjects s
                ON s.id = ta.subject_id
             WHERE ta.school_id = ?
               AND ta.grade_level = ?"
        );

        $stmt->execute([
            $school_id,
            $grade
        ]);

        foreach (
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) as $r
        ) {

            $school_subjects[
                (int)$r['subject_id']
            ] =
                $r['subject_name'];
        }
    }


    $school_marks = [];


    if (
        !empty($school_student_ids) &&
        !empty($school_subjects)
    ) {

        $student_placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($school_student_ids),
                    '?'
                )
            );

        $school_subject_ids =
            array_keys(
                $school_subjects
            );

        $subject_placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($school_subject_ids),
                    '?'
                )
            );


        $sql = "
            SELECT
                student_id,
                subject_id,
                assessment_type,
                score
            FROM marks
            WHERE school_id = ?
              AND student_id IN (
                  $student_placeholders
              )
              AND subject_id IN (
                  $subject_placeholders
              )
              AND term = ?
              AND academic_year = ?
        ";


        $stmt =
            $db->prepare($sql);

        $stmt->execute(
            array_merge(
                [$school_id],
                $school_student_ids,
                $school_subject_ids,
                [$term, $year]
            )
        );


        foreach (
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) as $r
        ) {

            $assessment =
                trim(
                    (string)
                    $r['assessment_type']
                );

            if (
                !in_array(
                    $assessment,
                    $assessment_order,
                    true
                )
            ) {
                continue;
            }


            $school_marks[
                $r['student_id']
            ][
                $r['subject_id']
            ][
                $assessment
            ] =
                $r['score'];
        }
    }


    foreach (
        $school_grade_students
        as $school_stu
    ) {

        $school_sid =
            $school_stu['id'];

        $school_total = 0;


        foreach (
            $school_subjects
            as $subject_id =>
            $subject_name
        ) {

            $sub_marks =
                $school_marks[
                    $school_sid
                ][
                    $subject_id
                ] ?? [];


            /*
             * IMPORTANT:
             * Use the same administered-assessment
             * denominator for school-wide ranking.
             */
            $subject_avg =
                calculateSubjectAverage(
                    $sub_marks,
                    $display_assessments
                );


            if ($subject_avg !== 'X') {

                $school_total +=
                    $subject_avg;
            }
        }


        $school_grade_totals[
            $school_sid
        ] = [

            'total_marks' =>
                round($school_total)
        ];
    }
}


$school_positions =
    $school_grade_totals;

uasort(
    $school_positions,
    function ($a, $b) {
        return $b['total_marks']
            <=> $a['total_marks'];
    }
);

$school_rank = 1;
$sid_school_ranks = [];

foreach (
    $school_positions
    as $school_sid =>
    $school_vals
) {

    $sid_school_ranks[
        $school_sid
    ] =
        $school_rank++;
}


$school_grade_count =
    count(
        $school_grade_totals
    );

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Report Cards | EduLinCore
</title>

<script>

window.addEventListener(
    'pageshow',
    function(event) {

        if (
            event.persisted ||
            (
                performance.navigation &&
                performance.navigation.type === 2
            )
        ) {
            window.location.reload();
        }
    }
);

</script>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
>

<style>

/* =========================================================
   BASE
   ========================================================= */

:root {
    --sidebar-width: 260px;
}

* {
    box-sizing: border-box;
}

html,
body,
button,
input,
select,
textarea,
table {
    font-family:
        "Times New Roman",
        Times,
        serif !important;

    color: #000 !important;
}

body {
    background: #fff;
    font-size: 10px;
    overflow-x: hidden;
    color: #000;
}


/* =========================================================
   SIDEBAR BASE
   ========================================================= */

.sidebar {
    width: var(--sidebar-width);
    height: 100vh;
    height: 100dvh;

    background: #fff;

    border-right: 1px solid #000;

    position: fixed;

    left: 0;
    top: 0;

    color: #000;

    padding: 20px;

    z-index: 1050;

    display: flex;
    flex-direction: column;
}

.app-sidebar-header {
    padding: .5rem 0 1.25rem;

    font-size: 1.15rem;

    font-weight: 800;

    color: #000 !important;

    border-bottom: 1px solid #000;
}

.sidebar .nav-link {
    color: #000 !important;

    padding: 10px 12px;

    border-radius: 6px;

    margin-bottom: 4px;

    text-decoration: none;

    display: flex;

    align-items: center;

    font-size: 14px;

    font-weight: 600;
}

.sidebar .nav-link:hover,
.sidebar .nav-link.active {
    background: #eee;

    color: #000 !important;

    font-weight: 700;
}

.close-sidebar {
    display: none;

    position: absolute;

    top: 15px;
    right: 20px;

    background: none;

    border: none;

    color: #000 !important;

    font-size: 24px;

    cursor: pointer;
}


/* =========================================================
   MAIN CONTENT
   ========================================================= */

.main-content {
    margin-left: var(--sidebar-width);

    padding: 25px;
}


/* =========================================================
   OVERLAY
   ========================================================= */

.sidebar-overlay {
    display: none;

    position: fixed;

    inset: 0;

    background: rgba(0,0,0,.4);

    z-index: 1040;
}


/* =========================================================
   MOBILE MENU BUTTON
   ========================================================= */

.menu-toggle {
    display: none;

    position: fixed;

    top: 15px;
    left: 15px;

    z-index: 1000;

    background: #fff;

    color: #000 !important;

    border: 1px solid #000;

    padding: 8px 12px;

    border-radius: 0;

    align-items: center;

    gap: 6px;
}


/* =========================================================
   FILTER CARD
   ========================================================= */

.filter-card {
    background: #fff;

    padding: 15px;

    border: 1px solid #000;

    margin-bottom: 20px;

    color: #000 !important;
}


/* =========================================================
   REPORT PAPER
   ========================================================= */

.card-paper {

    width: 270mm;

    height: 204mm;

    max-width: 270mm;

    max-height: 204mm;

    background: #fff;

    margin: 5mm auto;

    padding: 6mm 5mm 5mm 5mm;

    page-break-after: always;

    page-break-inside: avoid;

    break-after: page;

    break-inside: avoid;

    color: #000 !important;

    border: 1px solid #000;

    overflow: hidden;

    display: flex;

    flex-direction: column;

    justify-content: flex-start;

    transform-origin: top left;

    font-family:
        "Times New Roman",
        Times,
        serif !important;

    font-weight: 600;

    -webkit-print-color-adjust: exact;

    print-color-adjust: exact;
}


.card-paper * {

    font-family:
        "Times New Roman",
        Times,
        serif !important;

    color: #000 !important;
}


/* =========================================================
   REPORT HEADER
   ========================================================= */

.report-header {

    text-align: center;

    padding-bottom: 1px;

    margin-bottom: 2px;

    line-height: 1.05;
}

.report-header h6 {

    font-size: 14px !important;

    margin: 0 !important;

    line-height: 1.05;
}

.report-header small {

    font-size: 12px !important;

    line-height: 1.05;
}

.report-title {

    font-size: 13px;

    font-weight: 700;

    margin: 1px 0;

    line-height: 1.05;
}

.report-notice {

    width: 60%;

    margin: 2px auto 3px;

    border: 1px solid #000;

    padding: 1px 4px;

    font-size: 10px;

    font-weight: 700;

    line-height: 1.05;

    text-transform: uppercase;
}


/* =========================================================
   SCHOOL LINE
   ========================================================= */

.school-line {

    display: flex;

    justify-content: space-between;

    align-items: center;

    font-size: 11px;

    font-weight: 700;

    margin: 0 4px 3mm;

    line-height: 1.05;
}


/* =========================================================
   TABLES
   ========================================================= */

table {

    width: 100%;

    border-collapse: collapse;

    margin-bottom: 5px;

    table-layout: fixed;
}

th,
td {

    border: 1px solid #000 !important;

    padding: 3.5px 4px;

    text-align: center;

    font-size: 9px !important;

    line-height: 1.15;

    vertical-align: middle;

    color: #000 !important;

    background: #fff !important;
}

th {

    font-weight: 700 !important;
}

.text-left {

    text-align: left !important;

    padding-left: 5px;
}


/* =========================================================
   STUDENT INFO TABLE
   ========================================================= */

.student-info-table {

    margin-top: 4mm;

    margin-bottom: 4mm;
}

.student-info-table td {

    font-size: 9px !important;

    padding-top: 4px;

    padding-bottom: 4px;
}


/* =========================================================
   MARKS TABLE
   ========================================================= */

.marks-table {

    margin-top: 2mm;

    margin-bottom: 5mm;
}

.marks-table th,
.marks-table td {

    font-size: 8.5px !important;

    padding: 5px 3px;

    line-height: 1.2;

    vertical-align: middle;
}

.marks-table tbody tr {

    height: 8mm;
}

.marks-table tbody td {

    height: 8mm;
}

.marks-table th {

    white-space: normal;

    overflow-wrap: break-word;
}


/* =========================================================
   SUMMARY
   ========================================================= */

.summary-table {

    margin-top: 3mm;

    margin-bottom: 5mm;
}

.summary-table th,
.summary-table td {

    font-size: 9px !important;

    padding-top: 4px;

    padding-bottom: 4px;
}


/* =========================================================
   REMARKS
   ========================================================= */

.remarks-container {

    border: 1px solid #000 !important;

    padding: 4px 7px;

    margin-top: 4mm;

    margin-bottom: 3mm;

    background: #fff !important;

    line-height: 1.3;
}

.remarks-heading {

    font-size: 10px !important;

    font-weight: 700 !important;

    display: inline;

    margin-right: 4px;
}

.remarks-body {

    font-size: 10px !important;

    font-weight: 600 !important;

    line-height: 1.3;
}


/* =========================================================
   FOOTER
   ========================================================= */

.footer-area {

    margin-top: 5mm;

    position: relative;

    top: 0;

    color: #000 !important;

    font-weight: 600 !important;

    font-size: 9px !important;
}

.signature-row {

    display: flex;

    justify-content: space-between;

    margin-top: 6px;
}

.sig-box {

    width: 47%;

    text-align: center;

    border-top: 1px solid #000;

    padding-top: 2px;

    font-size: 9px !important;
}

.term-dates {

    display: flex;

    justify-content: space-between;

    margin-bottom: 5px;

    font-size: 9px !important;

    font-weight: 700;
}


/* =========================================================
   FORCE REPORT TEXT DARK
   ========================================================= */

.card-paper,
.card-paper p,
.card-paper span,
.card-paper div,
.card-paper td,
.card-paper th,
.card-paper label,
.card-paper small,
.card-paper strong,
.card-paper h5,
.card-paper h6 {

    color: #000 !important;
}

.card-paper td,
.card-paper th {

    font-weight: 600 !important;
}

.card-paper > div,
.card-paper table,
.card-paper tr,
.remarks-container,
.signature-row {

    page-break-inside: avoid !important;

    break-inside: avoid !important;
}

.card-paper .table-responsive {

    overflow: visible !important;
}


/* =========================================================
   SCREEN ONLY
   LIGHT SIDEBAR
   ========================================================= */

@media screen {

    body {

        background: #f4f7fb !important;

        color: #1f2937 !important;
    }


    .sidebar {

        background: #f5f7fa !important;

        border-right: 1px solid #dbe3ea !important;

        color: #1f2937 !important;

        box-shadow:
            2px 0 8px rgba(0,0,0,.04);
    }


    .app-sidebar-header {

        color: #1f2937 !important;

        border-bottom-color:
            #dbe3ea !important;
    }


    .sidebar .nav-link {

        color: #334155 !important;

        background: transparent !important;
    }


    .sidebar .nav-link:hover {

        background: #eaf4fb !important;

        color: #0369a1 !important;
    }


    .sidebar .nav-link.active {

        background: #e0f2fe !important;

        color: #0369a1 !important;

        font-weight: 700 !important;

        border-left: 3px solid #0ea5e9 !important;
    }


    .close-sidebar {

        color: #334155 !important;
    }


    .sidebar > div:nth-child(3) {

        border-bottom-color:
            #dbe3ea !important;
    }


    .sidebar > div:nth-child(3) > div:first-child {

        border-color:
            #94a3b8 !important;

        color:
            #334155 !important;
    }


    .sidebar > div:nth-child(3) > div:last-child div:first-child {

        color:
            #1f2937 !important;
    }


    .sidebar > div:nth-child(3) > div:last-child div:last-child {

        color:
            #64748b !important;
    }


    .main-content {

        color: #1f2937 !important;
    }


    .filter-card {

        background: #fff !important;

        border: 1px solid #d5dee8 !important;

        border-radius: 8px !important;

        box-shadow:
            0 2px 8px rgba(0,0,0,.06);

        color: #1f2937 !important;
    }


    .filter-card h5,
    .filter-card label {

        color: #0369a1 !important;
    }


    .filter-card .form-control,
    .filter-card .form-select {

        background: #fff !important;

        color: #111827 !important;

        border: 1px solid #b8c5d3 !important;
    }


    .filter-card .form-control:focus,
    .filter-card .form-select:focus {

        border-color:
            #0ea5e9 !important;

        box-shadow:
            0 0 0 .15rem
            rgba(14,165,233,.15) !important;
    }


    .filter-card .btn-dark {

        background: #0ea5e9 !important;

        color: #fff !important;

        border-color: #0ea5e9 !important;
    }


    .filter-card .btn-dark:hover,
    .filter-card .btn-dark:focus,
    .filter-card .btn-dark:active {

        background: #0284c7 !important;

        color: #fff !important;

        border-color: #0284c7 !important;
    }


    .filter-card .col-md-4 .btn-dark {

        background: #198754 !important;

        color: #fff !important;

        border-color: #198754 !important;
    }


    .filter-card .col-md-4 .btn-dark:hover,
    .filter-card .col-md-4 .btn-dark:focus,
    .filter-card .col-md-4 .btn-dark:active {

        background: #146c43 !important;

        color: #fff !important;

        border-color: #146c43 !important;
    }


    .filter-card .btn-outline-dark {

        color: #0284c7 !important;

        background: #fff !important;

        border-color: #0ea5e9 !important;
    }


    .filter-card .btn-outline-dark:hover,
    .filter-card .btn-outline-dark:focus,
    .filter-card .btn-outline-dark:active {

        color: #fff !important;

        background: #0ea5e9 !important;

        border-color: #0ea5e9 !important;
    }


    .menu-toggle {

        background: #0ea5e9 !important;

        color: #fff !important;

        border-color: #0ea5e9 !important;
    }


    .menu-toggle:hover,
    .menu-toggle:focus,
    .menu-toggle:active {

        background: #0284c7 !important;

        color: #fff !important;

        border-color: #0284c7 !important;
    }


    .card-paper {

        background: #fff !important;

        border: 1px solid #b9c4cf !important;

        box-shadow:
            0 3px 12px rgba(0,0,0,.08);
    }


    .card-paper .btn-outline-dark {

        color: #0284c7 !important;

        background: #fff !important;

        border-color: #0ea5e9 !important;
    }


    .card-paper .btn-outline-dark:hover,
    .card-paper .btn-outline-dark:focus,
    .card-paper .btn-outline-dark:active {

        color: #fff !important;

        background: #0ea5e9 !important;

        border-color: #0ea5e9 !important;
    }


    .card-paper table th {

        background: #e0f2fe !important;
    }


    .card-paper table td {

        background: #fff !important;
    }


    .card-paper .remarks-container {

        background: #f8fafc !important;
    }

}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 992px) {

    .sidebar {

        left: -100%;

        width: 280px;

        box-shadow:
            4px 0 15px rgba(0,0,0,.1);
    }


    .sidebar.active {

        left: 0;
    }


    .main-content {

        margin-left: 0;

        padding: 15px;
    }


    .close-sidebar {

        display: block;
    }


    .menu-toggle {

        display: inline-flex !important;
    }


    body.menu-open
    .sidebar-overlay {

        display: block;
    }
}


@media (max-width: 1200px) {

    .card-paper {

        width: 100%;

        max-width: 100%;

        height: auto;

        min-height: 204mm;

        max-height: none;

        padding: 10px;
    }


    .card-paper th,
    .card-paper td {

        font-size: 8px !important;

        padding: 3px;
    }


    .marks-table tbody tr,
    .marks-table tbody td {

        height: auto;
    }
}


/* =========================================================
   PRINT
   EVERYTHING BLACK AND WHITE
   ========================================================= */

@media print {

    html,
    body {

        width: 297mm;

        height: 210mm;

        margin: 0 !important;

        padding: 0 !important;

        font-family:
            "Times New Roman",
            Times,
            serif !important;

        background: #fff !important;

        color: #000 !important;

        overflow: visible !important;
    }


    .sidebar,
    .filter-card,
    .no-print,
    .menu-toggle,
    .sidebar-overlay,
    .print-controls {

        display: none !important;
    }


    .main-content {

        margin-left: 0 !important;

        padding: 0 !important;

        width: 297mm !important;
    }


    .card-paper {

        width: 270mm !important;

        height: 204mm !important;

        max-width: 270mm !important;

        max-height: 204mm !important;

        margin: 3mm auto !important;

        padding: 4mm 5mm !important;

        box-shadow: none !important;

        border: 1px solid #000 !important;

        page-break-after: always;

        page-break-inside: avoid;

        break-after: page;

        break-inside: avoid;

        display: flex !important;

        overflow: hidden !important;

        background: #fff !important;

        color: #000 !important;
    }


    .card-paper * {

        color: #000 !important;

        background: #fff !important;
    }


    /* FOOTER — PUSHED DOWN SLIGHTLY FOR PRINT */

    .footer-area {

        margin-top: 5mm !important;

        position: relative !important;

        top: 0 !important;

        color: #000 !important;

        font-weight: 600 !important;

        font-size: 9px !important;
    }


    body {

        background: #fff !important;

        overflow: visible !important;
    }


    @page {

        size: A4 landscape;

        margin: 0;
    }


    body.print-single
    .card-paper:not(.target-print) {

        display: none !important;
    }
}

</style>

</head>


<body>


<!-- =====================================================
     SIDEBAR OVERLAY
     ===================================================== -->

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
    onclick="toggleSidebar()"
></div>


<!-- =====================================================
     MOBILE MENU
     ===================================================== -->

<button
    class="menu-toggle no-print"
    onclick="toggleSidebar()"
>

    <i class="fa fa-bars"></i>

    Menu

</button>


<!-- =====================================================
     SIDEBAR
     ===================================================== -->

<div
    class="sidebar no-print"
    id="sidebar"
>

    <button
        class="close-sidebar"
        onclick="toggleSidebar()"
    >
        &times;
    </button>


    <div class="app-sidebar-header">

        EduLin<span>C</span>ore

    </div>


    <div
        style="
            padding:12px 0;
            border-bottom:1px solid #dbe3ea;
            display:flex;
            align-items:center;
            gap:.75rem;
            margin-bottom:15px;
        "
    >

        <div
            style="
                width:38px;
                height:38px;
                border:1px solid #94a3b8;
                border-radius:50%;
                display:flex;
                align-items:center;
                justify-content:center;
                font-weight:700;
                font-size:15px;
                color:#334155;
            "
        >

            <?php

            $sidebar_name =
                $_SESSION['user_name']
                ?? 'IT Officer';

            $sidebar_parts =
                preg_split(
                    '/\s+/',
                    trim(
                        $sidebar_name
                    )
                );

            $sidebar_initials =
                strtoupper(
                    substr(
                        $sidebar_parts[0]
                        ?? 'I',
                        0,
                        1
                    ) .
                    substr(
                        $sidebar_parts[1]
                        ?? '',
                        0,
                        1
                    )
                );

            echo htmlspecialchars(
                $sidebar_initials,
                ENT_QUOTES,
                'UTF-8'
            );

            ?>

        </div>


        <div
            style="
                overflow:hidden;
            "
        >

            <div
                style="
                    font-weight:700;
                    font-size:.85rem;
                    color:#1f2937;
                "
            >

                <?=htmlspecialchars(
                    $sidebar_name,
                    ENT_QUOTES,
                    'UTF-8'
                )?>

            </div>


            <div
                style="
                    font-size:.7rem;
                    font-weight:700;
                    text-transform:uppercase;
                    color:#64748b;
                "
            >

                School IT Officer

            </div>

        </div>

    </div>


    <nav
        style="
            display:flex;
            flex-direction:column;
            gap:.25rem;
            flex:1;
            overflow-y:auto;
        "
    >

        <a
            href="<?=BASE_URL?>/it/dashboard"
            class="nav-link"
        >

            <i
                class="fa fa-home"
                style="width:20px;"
            ></i>

            Dashboard

        </a>


        <a
            href="<?=BASE_URL?>/it/reports/cards"
            class="nav-link active"
        >

            <i
                class="fa fa-file-invoice"
                style="width:20px;"
            ></i>

            Report Cards

        </a>


        <a
            href="<?=BASE_URL?>/auth/logout"
            class="nav-link mt-auto"
        >

            <i
                class="fa fa-sign-out-alt"
                style="width:20px;"
            ></i>

            Logout

        </a>

    </nav>

</div>


<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->

<div class="main-content">


<!-- =====================================================
     FILTER CARD
     ===================================================== -->

<div class="filter-card no-print mt-4 mt-lg-0">

    <div
        class="
            d-flex
            flex-column
            flex-md-row
            justify-content-between
            align-items-md-center
            gap-3
            mb-3
        "
    >

        <h5 class="fw-bold mb-0">

            Report Card Configuration

        </h5>


        <div class="d-flex gap-2">

            <button
                onclick="printAllCards()"
                class="
                    btn
                    btn-dark
                    fw-bold
                    px-3
                    btn-sm
                "
            >

                <i
                    class="fa fa-print me-2"
                ></i>

                PRINT ALL

            </button>

        </div>

    </div>


    <form
        method="get"
        class="row g-2"
        id="filterForm"
    >

        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                GRADE
            </label>

            <select
                name="grade"
                class="form-select form-select-sm"
                onchange="this.form.submit()"
            >

                <option value="">
                    Select Grade
                </option>


                <?php foreach (
                    $grades as $g
                ): ?>

                    <option
                        value="<?=htmlspecialchars($g)?>"
                        <?=$grade == $g
                            ? 'selected'
                            : ''?>
                    >

                        <?=htmlspecialchars($g)?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                CLASS
            </label>

            <select
                name="class"
                class="form-select form-select-sm"
                onchange="this.form.submit()"
            >

                <option value="">
                    Select Class
                </option>


                <?php foreach (
                    $classes as $c
                ): ?>

                    <option
                        value="<?=htmlspecialchars($c)?>"
                        <?=$class == $c
                            ? 'selected'
                            : ''?>
                    >

                        <?=htmlspecialchars($c)?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                TERM
            </label>

            <select
                name="term"
                class="form-select form-select-sm"
                onchange="this.form.submit()"
            >

                <?php foreach (
                    [
                        'Term 1',
                        'Term 2',
                        'Term 3'
                    ] as $t
                ): ?>

                    <option
                        value="<?=htmlspecialchars($t)?>"
                        <?=$term == $t
                            ? 'selected'
                            : ''?>
                    >

                        <?=htmlspecialchars($t)?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                YEAR
            </label>

            <select
                name="year"
                class="form-select form-select-sm"
                onchange="this.form.submit()"
            >

                <?php foreach (
                    $years as $y
                ): ?>

                    <option
                        value="<?=htmlspecialchars($y)?>"
                        <?=$year == $y
                            ? 'selected'
                            : ''?>
                    >

                        <?=htmlspecialchars($y)?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                TERM CLOSES
            </label>

            <input
                type="date"
                name="term_closes"
                class="form-control form-control-sm"
                value="<?=htmlspecialchars(
                    $term_closes
                )?>"
            >

        </div>


        <div class="col-6 col-md-2">

            <label class="small fw-bold">
                NEXT TERM
            </label>

            <input
                type="date"
                name="next_term"
                class="form-control form-control-sm"
                value="<?=htmlspecialchars(
                    $next_term
                )?>"
            >

        </div>


        <div class="col-12 col-md-8">

            <label class="small fw-bold">
                SEARCH PUPIL NAME
            </label>


            <div
                class="
                    input-group
                    input-group-sm
                "
            >

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Type student full name..."
                    value="<?=htmlspecialchars(
                        $search
                    )?>"
                >


                <button
                    class="btn btn-outline-dark"
                    type="submit"
                >

                    <i
                        class="fa fa-search"
                    ></i>

                </button>


                <?php if (
                    $search !== ''
                ): ?>

                    <a
                        href="?grade=<?=urlencode($grade)?>&class=<?=urlencode($class)?>&term=<?=urlencode($term)?>&year=<?=urlencode($year)?>&term_closes=<?=urlencode($term_closes)?>&next_term=<?=urlencode($next_term)?>"
                        class="btn btn-outline-dark"
                    >

                        Reset

                    </a>

                <?php endif; ?>

            </div>

        </div>


        <div
            class="
                col-12
                col-md-4
                text-end
                mt-4
            "
        >

            <button
                type="submit"
                class="
                    btn
                    btn-dark
                    btn-sm
                    px-4
                    fw-bold
                    w-100
                "
            >

                Apply Settings

            </button>

        </div>

    </form>

</div>


<?php if (
    empty($students) &&
    $grade &&
    $class
): ?>

    <div
        class="
            alert
            alert-warning
            text-center
            fw-bold
        "
    >

        No students found matching your criteria.

    </div>


<?php elseif (
    !$grade ||
    !$class
): ?>

    <div
        class="
            alert
            alert-info
            text-center
            fw-bold
        "
    >

        Please select both a Grade and a Class to view report cards.

    </div>

<?php endif; ?>


<!-- =====================================================
     REPORT CARDS
     ===================================================== -->

<?php foreach (
    $students as $stu
): ?>

<?php

$sid =
    $stu['id'];

$is_pri =
    isPrimary(
        $stu['grade_level']
    );

$student_summary =
    $student_totals[$sid]
    ?? [
        'subjects_recorded' =>
            count($subjects),

        'subjects_assessed' =>
            0,

        'subjects_passed' =>
            0,

        'total_marks' =>
            0,

        'avg_marks' =>
            'X',

        'points' =>
            'N/A',

        'certification' =>
            'Statement'
    ];


$overall_total_out_of =
    ((int)
        $student_summary[
            'subjects_recorded'
        ]
    ) * 100;


$overall_percentage =
    $overall_total_out_of > 0
        ? round(
            (
                (float)
                $student_summary[
                    'total_marks'
                ] /
                $overall_total_out_of
            ) * 100
        )
        : 'X';

?>


<div
    class="card-paper"
    id="card-<?=$sid?>"
>


<div>


<!-- HEADER -->

<div
    class="
        report-header
        position-relative
        text-center
    "
>

    <div
        class="
            no-print
            position-absolute
            top-0
            end-0
        "
    >

        <button
            onclick="printSingleCard('<?=$sid?>')"
            class="
                btn
                btn-sm
                btn-outline-dark
                fw-bold
                py-0
                px-2
            "
            style="font-size:9px;"
        >

            <i
                class="fa fa-print"
            ></i>

            Print Card

        </button>

    </div>


    <h6 class="fw-bold mb-0">
        REPUBLIC OF ZAMBIA
    </h6>


    <small>
        MINISTRY OF EDUCATION
    </small>


    <div class="report-title">
        PROGRESS REPORT
    </div>


    <?php if (!$is_pri): ?>

        <div class="report-notice">
            THIS IS NOT THE OFFICIAL ECZ FINAL CERTIFICATE
        </div>

    <?php else: ?>

        <div class="report-notice">
            OFFICIAL PERFORMANCE REPORT CARD
        </div>

    <?php endif; ?>

</div>


<!-- SCHOOL INFORMATION -->

<div class="school-line">

    <span>

        SCHOOL NAME:

        <strong>

            <?=htmlspecialchars(
                $ctx['school_name']
                ?? 'SCHOOL PORTAL'
            )?>

        </strong>

    </span>


    <span>

        DISTRICT:

        <strong>

            <?=htmlspecialchars(
                $ctx['district']
                ??
                (
                    $ctx['District']
                    ?? ''
                )
            )?>

        </strong>

    </span>

</div>


<!-- STUDENT INFORMATION -->

<table class="student-info-table">

<tr>

<td
    class="text-left"
    colspan="3"
>

    STUDENT:

    <strong>

        <?=strtoupper(
            htmlspecialchars(
                $stu['full_name']
            )
        )?>

    </strong>

</td>


<td>

    GENDER:

    <strong>

        <?=htmlspecialchars(
            $stu['gender']
        )?>

    </strong>

</td>


<td>

    GRADE:

    <strong>

        <?=htmlspecialchars(
            $stu['grade_level']
        )?>

    </strong>

</td>


<td>

    CLASS:

    <strong>

        <?=htmlspecialchars(
            $stu['class_name']
        )?>

    </strong>

</td>

</tr>


<tr>

<td colspan="2">

    TERM:

    <strong>

        <?=htmlspecialchars(
            $term
        )?>

    </strong>

</td>


<td colspan="2">

    TYPE OF ASSESSMENTS:

    <strong>

        <?=htmlspecialchars(
            $assessment_heading
        )?>

    </strong>

</td>


<td>

    YEAR:

    <strong>

        <?=htmlspecialchars(
            $year
        )?>

    </strong>

</td>


<td>

    POSITION IN CLASS:

    <strong>

        <?=$sid_ranks[$sid] ?? '-'?>

    </strong>

    of

    <?=count($student_totals)?>

    &nbsp;

    POSITION AT SCHOOL:

    <strong>

        <?=$sid_school_ranks[$sid] ?? '-'?>

    </strong>

    of

    <?=$school_grade_count?>

</td>

</tr>

</table>


<!-- MARKS TABLE -->

<table class="marks-table">

<thead>

<tr>

<th style="width:4%;">
    SN
</th>


<th
    class="text-left"
    style="width:20%;"
>
    SUBJECT
</th>


<?php foreach (
    $display_assessments
    as $assessment
): ?>

<th style="width:9%;">

    <?=htmlspecialchars(
        $assessment
    )?>

</th>

<?php endforeach; ?>


<th style="width:8%;">
    AVERAGE
</th>


<th style="width:8%;">
    OUT OF
</th>


<th style="width:16%;">
    REMARK
</th>


<?php if (!$is_pri): ?>

<th style="width:8%;">

    GRADE/<br>
    POINT

</th>

<?php endif; ?>


<th style="width:10%;">
    TEACHER
</th>

</tr>

</thead>


<tbody>

<?php

$sn = 1;

foreach (
    $subjects as
    $sub_id =>
    $sub_name
):

    $sub_marks =
        $marks[$sid][$sub_id]
        ?? [];


    /*
     * The average now uses:
     *
     * SUM OF PRESENT MARKS
     * --------------------
     * NUMBER OF ADMINISTERED ASSESSMENTS
     *
     * Example:
     * 94 + 0 + 0
     * -----------
     *     3
     *
     * = 31
     */
    $subject_avg =
        calculateSubjectAverage(
            $sub_marks,
            $display_assessments
        );


    $has_absent_mark = false;


    foreach (
        $display_assessments
        as $assessment
    ) {

        if (
            array_key_exists(
                $assessment,
                $sub_marks
            ) &&
            isAbsentMark(
                $sub_marks[$assessment]
            )
        ) {

            $has_absent_mark =
                true;

            break;
        }
    }


    $grade_data =
        getGradeAndPoint(
            $subject_avg,
            $is_pri
        );


    $grade_label =
        $grade_data['grade'];

    $pt_label =
        $grade_data['point'];


    if (
        $subject_avg === 'X' &&
        !$has_absent_mark
    ) {

        $grade_label = '-';

        $pt_label = 'X';
    }

?>

<tr>

<td>

    <?=$sn++?>

</td>


<td class="text-left">

    <?=htmlspecialchars(
        $sub_name
    )?>

</td>


<?php foreach (
    $display_assessments
    as $assessment
): ?>

<td class="fw-bold">

    <?=assessmentDisplayMark(
        $sub_marks,
        $assessment
    )?>

</td>

<?php endforeach; ?>


<td class="fw-bold">

    <?=$subject_avg?>

</td>


<td class="fw-bold">

    100

</td>


<td class="fw-bold">

    <?=htmlspecialchars(
        $grade_label
    )?>

</td>


<?php if (!$is_pri): ?>

<td class="fw-bold">

    <?=$pt_label?>

</td>

<?php endif; ?>


<td>

    <?=htmlspecialchars(
        $teacher_names[
            $sub_id
        ] ?? ''
    )?>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>


<!-- SUMMARY -->

<table class="summary-table">

<tr>

<th>
    SUBJECTS RECORDED
</th>

<th>
    SUBJECTS ASSESSED
</th>

<th>
    SUBJECTS PASSED
</th>

<th>
    TOTAL MARKS
</th>

<th>
    OVERALL %
</th>


<?php if (!$is_pri): ?>

<th>
    BEST 6 POINTS
</th>

<th>
    CERTIFICATION
</th>

<?php endif; ?>

</tr>


<tr>

<td>

    <?=$student_summary[
        'subjects_recorded'
    ]?>

</td>


<td>

    <?=$student_summary[
        'subjects_assessed'
    ]?>

</td>


<td>

    <?=$student_summary[
        'subjects_passed'
    ]?>

</td>


<td>

    <?=$student_summary[
        'total_marks'
    ]?>

    /

    <?=$overall_total_out_of?>

</td>


<td class="fw-bold">

    <?=$overall_percentage === 'X'
        ? 'X'
        : $overall_percentage . '%'
    ?>

</td>


<?php if (!$is_pri): ?>

<td class="fw-bold">

    <?=$student_summary[
        'points'
    ]?>

    pts

</td>


<td>

    <?=$student_summary[
        'certification'
    ]?>

</td>

<?php endif; ?>

</tr>

</table>


<!-- REMARK -->

<div class="remarks-container">

<span class="remarks-heading">

    OVERALL REMARKS:

</span>


<span class="remarks-body">

<?=htmlspecialchars(
    overallRemark(
        $student_summary[
            'avg_marks'
        ],
        $stu['full_name'],
        $stu['grade_level'],
        $stu['gender']
    )
)?>

</span>

</div>

</div>


<!-- FOOTER -->

<div class="footer-area">

<div class="term-dates">

<span>

    TERM CLOSES:

    <strong>

        <?=!empty($term_closes)
            ? date(
                'd M Y',
                strtotime(
                    $term_closes
                )
            )
            : '____'
        ?>

    </strong>

</span>


<span>

    NEXT TERM OPENS:

    <strong>

        <?=!empty($next_term)
            ? date(
                'd M Y',
                strtotime(
                    $next_term
                )
            )
            : '____'
        ?>

    </strong>

</span>

</div>


<div class="signature-row">

<div class="sig-box">

    Head Teacher / Stamp

</div>


<div class="sig-box">

    Class Teacher

</div>

</div>


<div
    class="text-center mt-1 small"
    style="font-size:7px;"
>

    Generated via EduLinCore Portal |

    Verify ID:

    ECORE-<?=strtoupper(
        substr(
            md5(
                $sid . $year
            ),
            0,
            8
        )
    )?>

</div>

</div>

</div>


<?php endforeach; ?>


</div>


<!-- =====================================================
     JAVASCRIPT
     ===================================================== -->

<script>


function fitReportCards() {

    document
        .querySelectorAll('.card-paper')
        .forEach(function(card) {

            card.style.zoom = '1';

            const available =
                card.clientHeight - 8;

            const required =
                card.scrollHeight;


            if (
                required > available &&
                required > 0
            ) {

                const scale =
                    Math.max(
                        0.78,
                        Math.min(
                            1,
                            available /
                            required
                        )
                    );


                card.style.zoom =
                    scale.toFixed(3);
            }

        });

}


window.addEventListener(
    'load',
    fitReportCards
);


window.addEventListener(
    'resize',
    fitReportCards
);


window.addEventListener(
    'beforeprint',
    fitReportCards
);


/* =========================================================
   SIDEBAR
   ========================================================= */

function toggleSidebar() {

    document
        .getElementById('sidebar')
        .classList
        .toggle('active');


    document.body
        .classList
        .toggle('menu-open');
}


/* =========================================================
   PRINT ONE CARD
   ========================================================= */

function printSingleCard(studentId) {

    document
        .querySelectorAll('.card-paper')
        .forEach(function(el) {

            el.classList.remove(
                'target-print'
            );

        });


    const card =
        document.getElementById(
            'card-' + studentId
        );


    if (card) {

        card.classList.add(
            'target-print'
        );


        document.body.classList.add(
            'print-single'
        );


        window.print();


        document.body.classList.remove(
            'print-single'
        );
    }
}


/* =========================================================
   PRINT ALL
   ========================================================= */

function printAllCards() {

    document.body.classList.remove(
        'print-single'
    );

    window.print();
}


/* =========================================================
   AUTO LOGOUT
   ========================================================= */

let inactivityTimer;

const logoutRedirectUrl =
    '<?=BASE_URL?>/auth/logout';

const idleLimitMs =
    15 * 60 * 1000;


function resetIdleTimer() {

    clearTimeout(
        inactivityTimer
    );


    inactivityTimer =
        setTimeout(
            function() {

                window.location.href =
                    logoutRedirectUrl;

            },
            idleLimitMs
        );
}


[
    'mousemove',
    'keydown',
    'mousedown',
    'touchstart',
    'scroll'
].forEach(
    function(activityEvent) {

        window.addEventListener(
            activityEvent,
            resetIdleTimer,
            true
        );

    }
);


resetIdleTimer();

</script>


</body>

</html>