<?php
/**
 * EduLinCore National Portal - Teacher Performance Analysis Module
 * Path: app/Views/teacher/analysis_report.php
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// ==========================================
// 1. UNIFIED SESSION & DATABASE INITIALIZATION
// ==========================================
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: " . BASE_URL . "/auth/school_login");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$school_id  = $_SESSION['school_id'] ?? null;
$db = Database::getConnection();

// Fetch school ID dynamically if missing from session[cite: 7]
if (!$school_id) {
    $stmt = $db->prepare("SELECT school_id FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $school_id = $stmt->fetchColumn();
    $_SESSION['school_id'] = $school_id;
}

// Fetch teacher profile details including the 'level' column[cite: 7]
$t_stmt = $db->prepare("SELECT full_name, level FROM teachers WHERE id = ?");
$t_stmt->execute([$teacher_id]);
$teacher_info = $t_stmt->fetch(PDO::FETCH_ASSOC);

$full_name       = $teacher_info['full_name'] ?? 'Teacher Panel';
$operating_level = $teacher_info['level'] ?? ($_SESSION['operating_level'] ?? 'primary');
$_SESSION['operating_level'] = $operating_level;

// Determine mark entry route using exact dashboard logic[cite: 7]
$markEntryUrl = (strtolower($operating_level) === 'secondary') 
    ? BASE_URL . '/teacher/secondary_marks' 
    : BASE_URL . '/teacher/primary_marks';

// Fetch School Name and District dynamically from the schools table[cite: 7]
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

// ==========================================
// 2. FETCH TEACHER ASSIGNMENTS & FILTER OPTIONS
// ==========================================
$a = $db->prepare("SELECT ta.grade_level, ta.class_name, ta.subject_id, sub.subject_name 
                   FROM teacher_assignments ta 
                   JOIN subjects sub ON ta.subject_id = sub.id
                   WHERE ta.teacher_id = ? AND ta.school_id = ?");
$a->execute([$teacher_id, $school_id]);
$assignments = $a->fetchAll(PDO::FETCH_ASSOC);

$yearsList            = ['2025', '2026', '2027'];
$gradesList           = array_values(array_unique(array_column($assignments, 'grade_level')));
$classesList          = array_values(array_unique(array_column($assignments, 'class_name')));
$termsList            = ['Term 1', 'Term 2', 'Term 3'];
$standard_assessments = ['Test 1', 'Test 2', 'End of Term Test'];

$year   = $_GET['academic_year'] ?? '2026';
$grade  = $_GET['grade_level'] ?? '';
$class  = $_GET['class_name'] ?? '';
$term   = $_GET['term'] ?? 'Term 2';
$type   = $_GET['assessment_type'] ?? 'Test 1';

// Grading Schemes Helper Function
function getScheme($grade_level) {
    if (in_array($grade_level, ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', '1', '2', '3', '4', '5', '6'])) {
        return [
            '1' => [75, 100],
            '2' => [60, 74],
            '3' => [50, 59],
            '4' => [0, 49]
        ];
    } else {
        return [
            '1' => [75, 100],
            '2' => [70, 74],
            '3' => [65, 69],
            '4' => [60, 64],
            '5' => [55, 59],
            '6' => [50, 54],
            '7' => [40, 49],
            '8' => [0, 39]
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Analysis Report | EduLinCore</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-750: #334155;
            --border-color: #cbd5e1;
        }
        body.app-body {
            background-color: var(--slate-50);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            margin: 0;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        /* Sidebar Styling & Responsiveness */
        .app-sidebar {
            width: 260px;
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; bottom: 0; left: 0;
            z-index: 1050;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }
        @media (min-width: 992px) {
            .app-sidebar {
                transform: translateX(0);
            }
            .app-main-area {
                margin-left: 260px;
            }
        }
        .app-sidebar.show {
            transform: translateX(0);
        }
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1045;
        }
        .sidebar-backdrop.show {
            display: block;
        }
        .app-sidebar-header {
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 800;
            font-size: 1.1rem;
        }
        .profile-container {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            background: #fafafa;
        }
        .nav-menu {
            list-style: none;
            padding: 1rem 0.75rem;
            margin: 0;
            flex: 1;
            overflow-y: auto;
        }
        .nav-menu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.65rem 1rem;
            color: #475569;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            border-radius: 8px;
            margin-bottom: 4px;
            transition: background 0.2s, color 0.2s;
        }
        .nav-menu li a:hover, .nav-menu li a.active {
            background: #eff6ff;
            color: var(--primary);
            font-weight: 600;
        }
        /* Main Layout Area */
        .app-main-area {
            flex: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .mockup-header {
            height: 64px;
            background: #1e293b;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
        }
        @media (min-width: 768px) {
            .mockup-header { padding: 0 1.5rem; }
        }
        .dashboard-main-content {
            padding: 1rem;
            flex: 1;
        }
        @media (min-width: 768px) {
            .dashboard-main-content { padding: 1.5rem; }
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            overflow: hidden;
        }
        .dashboard-card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
        }
        .dashboard-card-body {
            padding: 1.25rem;
        }
        /* Table Styling for Analysis & Mobile Scrollability */
        .table-responsive-custom {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-analysis {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
            font-size: 11px;
            background: #fff;
        }
        .table-analysis th, .table-analysis td {
            border: 1px solid var(--border-color);
            padding: 6px 8px;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .table-analysis th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            text-transform: uppercase;
        }
        .table-analysis td.subject-col {
            text-align: left;
            font-weight: 600;
            min-width: 130px;
        }
        /* Print Styles */
        @media print {
            .no-print { display: none !important; }
            .app-sidebar, .mockup-header, .sidebar-backdrop { display: none !important; }
            .app-main-area { margin-left: 0 !important; width: 100% !important; }
            body { background: white !important; color: black !important; }
            .printable-sheet { padding: 0 !important; border: none !important; box-shadow: none !important; }
            .print-only-header { display: block !important; }
            .table-analysis { font-size: 10px !important; width: 100% !important; min-width: auto !important; }
        }
        .print-only-header { display: none; }
        .print-footer-info {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            justify-content: space-between;
            font-size: 9px;
            border-top: 1px solid #ccc;
            padding: 4px 10px;
        }
        @media print {
            .print-footer-info { display: flex !important; }
        }
        @page { size: landscape; margin: 10mm; }
    </style>
</head>
<body class="app-body">

<!-- Sidebar Backdrop for Mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar Component -->
<aside class="app-sidebar" id="sidebarMenu">
    <div class="app-sidebar-header">
        <span>EduLin<span style="color: var(--primary);">Core</span></span>
        <button class="btn btn-sm text-muted d-lg-none border-0" id="sidebarCloseBtn"><i class="fas fa-xmark fa-lg"></i></button>
    </div>
    
    <div class="profile-container">
        <h6 class="fw-bold mb-1 text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($full_name) ?></h6>
        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.75rem;">
            <i class="fas fa-chalkboard-user me-1 text-primary"></i> Operating: 
            <strong><?= ucfirst($operating_level) ?></strong>
        </span>
    </div>

    <ul class="nav-menu">
        <li><a href="<?= BASE_URL ?>/teacher/dashboard"><i class="fas fa-th-large fa-fw"></i> Dashboard</a></li>
        <li><a href="<?= $markEntryUrl ?>"><i class="fas fa-edit fa-fw"></i> Mark Entry Portal</a></li>
        <li><a href="<?= BASE_URL ?>/teacher_view_report"><i class="fas fa-file-lines fa-fw"></i> Progress Sheets</a></li>
        <li><a href="<?= BASE_URL ?>/teacher_analysis_report" class="active"><i class="fas fa-chart-pie fa-fw"></i> Performance Analysis</a></li>
        <li class="mt-3"><a href="<?= BASE_URL ?>/auth/logout" class="text-danger"><i class="fas fa-sign-out-alt fa-fw"></i> Logout</a></li>
    </ul>
</aside>

<!-- Main Area Layout -->
<div class="app-main-area">
    <header class="mockup-header no-print">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm text-white d-lg-none border-0 p-1" id="sidebarToggleBtn"><i class="fas fa-bars fa-lg"></i></button>
            <span class="text-white fw-bold fs-6 text-truncate">EduLinCore Analysis</span>
        </div>
        <div>
            <span class="text-light small fw-medium text-truncate d-none d-sm-inline"><?= htmlspecialchars($school_name) ?></span>
        </div>
    </header>

    <main class="dashboard-main-content">

        <!-- FILTER CARD -->
        <div class="dashboard-card mb-4 no-print shadow-sm">
            <div class="dashboard-card-header">
                <h5 class="card-title m-0 fw-bold text-dark" style="font-size: 0.95rem;"><i class="fas fa-filter text-primary me-2"></i>Analysis Filter Parameters</h5>
            </div>
            <div class="dashboard-card-body">
                <form class="row g-3" method="GET">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="fw-bold small">ACADEMIC YEAR</label>
                        <select name="academic_year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach(($yearsList ?? []) as $v) echo "<option ".($v==($year ?? '')?'selected':'').">$v</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="fw-bold small">GRADE LEVEL</label>
                        <select name="grade_level" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Select Grade</option>
                            <?php foreach(($gradesList ?? []) as $v) echo "<option ".($v==($grade ?? '')?'selected':'').">$v</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="fw-bold small">CLASS STREAM</label>
                        <select name="class_name" class="form-select form-select-sm">
                            <?php 
                            if(empty($classesList)) {
                                echo '<option value="">No Classes</option>';
                            } else {
                                foreach($classesList as $v) {
                                    $sel = ($v == ($class ?? '')) ? 'selected' : '';
                                    echo "<option value=\"$v\" $sel>$v</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="fw-bold small">TERM</label>
                        <select name="term" class="form-select form-select-sm">
                            <?php foreach(($termsList ?? []) as $tOption) echo "<option ".($tOption==($term ?? '')?'selected':'').">$tOption</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="fw-bold small">ASSESSMENT</label>
                        <select name="assessment_type" class="form-select form-select-sm">
                            <?php foreach(($standard_assessments ?? []) as $st) echo "<option ".($st==($type ?? '')?'selected':'').">$st</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 d-flex align-items-end">
                        <button class="btn btn-primary btn-sm w-100 shadow-sm"><i class="fas fa-cogs me-1"></i> GENERATE</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 no-print gap-2">
            <span class="text-muted small">Swipe horizontally on tables on mobile screens to view full details.</span>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/teacher_view_report" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-lines me-1"></i> Progress Sheet</a>
                <button onclick="window.print()" class="btn btn-sm btn-success"><i class="fas fa-print me-1"></i> Print Report</button>
            </div>
        </div>

        <!-- PRINTABLE SHEET CONTAINER -->
        <div class="printable-sheet bg-white p-3 p-md-4 rounded shadow-sm border">
            <!-- PRINT HEADER -->
            <div class="print-only-header text-center mb-4">
                <h5 class="fw-bold mb-1">MINISTRY OF EDUCATION - <?= strtoupper($operating_level) ?> SECTION</h5>
                <h6 class="fw-bold mb-1 text-uppercase"><?= htmlspecialchars($school_name) ?></h6>
                <h4 class="fw-bold text-decoration-underline mb-3">TEACHER PERFORMANCE ANALYSIS REPORT</h4>
                
                <div class="metadata-grid mb-3" style="display: flex; flex-wrap: wrap; justify-content: space-between; font-size: 11px; border-bottom: 1px solid #000; padding-bottom: 8px;">
                    <div><strong>DISTRICT:</strong> <?= htmlspecialchars($district_name) ?></div>
                    <div><strong>SCHOOL NAME:</strong> <?= htmlspecialchars($school_name) ?></div>
                    <div><strong>GRADE LEVEL:</strong> <?= htmlspecialchars($grade ?: 'All') ?></div>
                    <div><strong>CLASS:</strong> <?= htmlspecialchars($class ?: 'None') ?></div>
                    <div><strong>TERM:</strong> <?= htmlspecialchars($term) ?></div>
                    <div><strong>ASSESSMENT TYPE:</strong> <?= htmlspecialchars($type) ?></div>
                    <div><strong>YEAR:</strong> <?= htmlspecialchars($year) ?></div>
                </div>

                <div style="display: flex; justify-content: space-between; font-size: 11px; margin-top: 5px;">
                    <div></div>
                    <div><strong>Generated By:</strong> <?= htmlspecialchars($full_name) ?></div>
                </div>
            </div>

            <?php 
            if (!empty($grade) && !empty($class)) {
                $scheme = getScheme($grade);
                $is_primary = (count($scheme) <= 4); 
                $q_label = $is_primary ? "Qual (1-3)" : "Qual (1-6)";
                $o_label = $is_primary ? "Ovr (1-4)" : "Cred (1-8)";
                $limit_1 = $is_primary ? 3 : 6;
                $limit_2 = $is_primary ? 4 : 8;

                $targetClasses = [$class];

                foreach ($targetClasses as $cName) {
                    $enrolledStmt = $db->prepare("SELECT s.gender FROM student_enrollments se JOIN students s ON se.student_id = s.id WHERE se.grade_level = ? AND se.class_name = ? AND se.academic_year = ? AND se.school_id = ?");
                    $enrolledStmt->execute([$grade, $cName, $year, $school_id]);
                    $classStudents = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

                    $class_m_enrolled = 0;
                    $class_f_enrolled = 0;
                    foreach($classStudents as $cs) {
                        $g_char = strtolower($cs['gender'][0] ?? 'm');
                        if($g_char === 'f') {
                            $class_f_enrolled++;
                        } else {
                            $class_m_enrolled++;
                        }
                    }

                    $sStmt = $db->prepare("SELECT sub.id, sub.subject_name FROM subjects sub 
                                            JOIN teacher_assignments ta ON ta.subject_id = sub.id 
                                            WHERE ta.teacher_id = ? AND ta.school_id = ? AND ta.grade_level = ? AND ta.class_name = ?");
                    $sStmt->execute([$teacher_id, $school_id, $grade, $cName]);
                    $subjects = $sStmt->fetchAll(PDO::FETCH_ASSOC);

                    if ($subjects): ?>
                        <h6 class="fw-bold mt-4 mb-2 text-uppercase text-dark" style="font-size: 0.9rem;"><i class="fas fa-layer-group me-1 text-primary"></i> GRADE: <?= htmlspecialchars($grade) ?> | CLASS STREAM: <?= htmlspecialchars($cName) ?></h6>
                        <div class="table-responsive-custom">
                            <table class="table-analysis">
                                <thead>
                                    <tr>
                                        <th rowspan="2">SUBJECT</th>
                                        <th colspan="3">ENTERED</th>
                                        <th colspan="3">SAT</th>
                                        <th colspan="3">ABSENT</th>
                                        <?php foreach($scheme as $l=>$r) echo "<th colspan='3'>$l</th>"; ?>
                                        <th colspan="2">PASS RATES %</th>
                                    </tr>
                                    <tr>
                                        <th>M</th><th>F</th><th>T</th>
                                        <th>M</th><th>F</th><th>T</th>
                                        <th>M</th><th>F</th><th>T</th>
                                        <?php foreach($scheme as $l=>$r) echo "<th>M</th><th>F</th><th>T</th>"; ?>
                                        <th><?= $q_label ?></th><th><?= $o_label ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjects as $sub): 
                                        $mSql = "SELECT s.gender, m.score, m.absent FROM marks m 
                                                 JOIN student_enrollments se ON m.enrollment_id = se.id 
                                                 JOIN students s ON se.student_id = s.id 
                                                 WHERE m.subject_id = ? AND se.grade_level = ? AND se.class_name = ? 
                                                 AND m.assessment_type = ? AND m.academic_year = ? AND m.school_id = ? AND m.term = ?";
                                        $params = [$sub['id'], $grade, $cName, $type, $year, $school_id, $term];
                                        
                                        $stmt = $db->prepare($mSql);
                                        $stmt->execute($params);
                                        $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                        $res = ['m_sat'=>0,'f_sat'=>0,'dist'=>[]];
                                        foreach($scheme as $k=>$v) $res['dist'][$k] = ['m'=>0, 'f'=>0];

                                        foreach ($marks as $m) {
                                            $gen = (strtolower($m['gender'][0] ?? 'm') == 'f') ? 'f' : 'm';
                                            if (($m['absent'] ?? 0) == 1) {
                                                continue;
                                            }
                                            if ($m['score'] !== null) {
                                                $res[$gen.'_sat']++;
                                                $scoreVal = (float)$m['score'];
                                                foreach ($scheme as $lbl => $rng) {
                                                    if ($scoreVal >= $rng[0] && $scoreVal <= $rng[1]) {
                                                        $res['dist'][$lbl][$gen]++;
                                                        break;
                                                    }
                                                }
                                            }
                                        }

                                        $mEntered = $class_m_enrolled;
                                        $fEntered = $class_f_enrolled;
                                        $totalEntered = $mEntered + $fEntered;

                                        $mSat = $res['m_sat'];
                                        $fSat = $res['f_sat'];
                                        $totalSat = $mSat + $fSat;

                                        $mAbs = max(0, $mEntered - $mSat);
                                        $fAbs = max(0, $fEntered - $fSat);
                                        $totalAbs = $mAbs + $fAbs;

                                        $p1 = 0; $p2 = 0; $counter = 0;
                                        foreach($res['dist'] as $b) {
                                            $sum = $b['m']+$b['f'];
                                            if($counter < $limit_1) $p1 += $sum;
                                            if($counter < $limit_2) $p2 += $sum;
                                           $counter++;
                                        }
                                    ?>
                                    <tr>
                                        <td class="subject-col"><?= htmlspecialchars($sub['subject_name']) ?></td>
                                        <td><?= $mEntered ?></td><td><?= $fEntered ?></td><td><?= $totalEntered ?></td>
                                        <td><?= $mSat ?></td><td><?= $fSat ?></td><td><?= $totalSat ?></td>
                                        <td><?= $mAbs ?></td><td><?= $fAbs ?></td><td><?= $totalAbs ?></td>
                                        <?php foreach($res['dist'] as $b): ?>
                                            <td><?= $b['m'] ?></td><td><?= $b['f'] ?></td><td><?= $b['m']+$b['f'] ?></td>
                                        <?php endforeach; ?>
                                        <td><?= $totalSat > 0 ? round(($p1/$totalSat)*100, 1) : 0 ?>%</td>
                                        <td><?= $totalSat > 0 ? round(($p2/$totalSat)*100, 1) : 0 ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif;
                }
            } else {
                echo "<div class='alert alert-info no-print shadow-sm m-0'><i class='fas fa-info-circle me-2'></i>Please select both a Grade Level and a Class Stream above to generate the EduLinCore performance analysis report.</div>";
            }
            ?>

            <!-- Print Footer Info -->
            <div class="print-footer-info">
                <span>Platform: edulincore.site</span>
                <span>Module: Teacher Performance Analysis</span>
            </div>
        </div>
    </main>

    <footer class="mockup-header no-print">
        <div class="small">&copy; <?= date('Y'); ?> EduLinCore Technologies. All rights reserved.</div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Responsive Mobile Sidebar Toggle Logic
    const sidebar = document.getElementById('sidebarMenu');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    const closeBtn = document.getElementById('sidebarCloseBtn');

    function toggleSidebar() {
        sidebar.classList.toggle('show');
        backdrop.classList.toggle('show');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
    if (closeBtn) closeBtn.addEventListener('click', toggleSidebar);
    if (backdrop) backdrop.addEventListener('click', toggleSidebar);
</script>
</body>
</html>