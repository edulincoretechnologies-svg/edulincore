<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ================= AUTH & DATABASE ================= */
if (empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it') {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;
$db = Database::getConnection();

/* --- 1. SETTINGS & FILTERS --- */
$stmt = $db->prepare("SELECT * FROM schools WHERE id=? LIMIT 1");
$stmt->execute([$school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);

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
    
    $cStmt = $db->prepare("SELECT DISTINCT class_name FROM student_enrollments WHERE school_id = ? AND grade_level = ? ORDER BY class_name");
    $cStmt->execute([$school['id'], $grade]);
    $classNames = $class ? [$class] : $cStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($classNames as $cName) {
        $tableData = ['className' => $cName, 'rows' => []];

        $eStmt = $db->prepare("SELECT COUNT(CASE WHEN gender='Male' OR gender='M' THEN 1 END) as m, COUNT(CASE WHEN gender='Female' OR gender='F' THEN 1 END) as f FROM students s JOIN student_enrollments se ON s.id = se.student_id WHERE se.grade_level = ? AND se.class_name = ? AND se.school_id = ?");
        $eStmt->execute([$grade, $cName, $school['id']]);
        $classEnrollment = $eStmt->fetch(PDO::FETCH_ASSOC);

        $sql = "SELECT DISTINCT sub.id, sub.subject_name as short_name FROM subjects sub 
                JOIN marks m ON m.subject_id = sub.id 
                JOIN student_enrollments se ON m.enrollment_id = se.id
                WHERE se.grade_level = ? AND se.class_name = ? AND se.school_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$grade, $cName, $school['id']]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subjects as $sub) {
            $mSql = "SELECT s.gender, m.score, m.absent, m.assessment_type FROM marks m 
                     JOIN student_enrollments se ON m.enrollment_id = se.id 
                     JOIN students s ON se.student_id = s.id 
                     WHERE m.subject_id = ? AND se.grade_level = ? AND se.class_name = ? AND m.school_id = ?";
            $params = [$sub['id'], $grade, $cName, $school['id']];
            
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

            $stmt = $db->prepare($mSql);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Analysis | EdulinCore</title>
    
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root { 
            --db: #0f172a; 
            --brand-blue: #2563eb; 
            --brand-purple: #7c3aed;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --success: #16a34a;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.app-body { 
            background: var(--bg-body); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            display: flex;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }

        /* --- Sidebar & Offcanvas Mobile Drawer Styling --- */
        .app-sidebar {
            width: 260px;
            background-color: var(--bg-card);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            height: 100%;
            flex-shrink: 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1050;
        }

        .app-sidebar-header {
            padding: 1.25rem 1rem;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .app-sidebar-header span span {
            color: var(--text-main);
        }

        .mobile-sidebar-close {
            display: none;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        /* --- Main Content Area --- */
        .app-main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow-y: auto;
            min-width: 0;
            -webkit-overflow-scrolling: touch;
        }

        .mockup-header {
            background-color: var(--db);
            color: #ffffff;
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 1020;
            border-bottom: 3px solid var(--brand-purple);
        }

        .brand-text { font-weight: 800; font-size: 1.05rem; margin: 0; color: #fff; text-decoration: none; white-space: nowrap; }
        .brand-text span.core-c {
            background: linear-gradient(135deg, var(--brand-blue), var(--brand-purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .dashboard-main-content {
            padding: 1.25rem;
            flex: 1;
        }

        .report-header { background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 2rem; }
        .analytics-box { background: #fff; padding: 20px; border-radius: 12px; border-left: 5px solid var(--brand-blue); border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .table-analysis { font-size: 9px; border-collapse: collapse; width: 100%; }
        .table-analysis th { background: var(--db) !important; color: white !important; text-align: center; border: 1px solid #000; padding: 4px; }
        .table-analysis td { text-align: center; border: 1px solid #cbd5e1; padding: 3px; font-weight: 600; }
        .subject-col { text-align: left !important; font-weight: bold; background: #f1f5f9 !important; }
        .pass-cell { font-weight: bold; color: var(--brand-blue); background: #f0f7ff !important; }
        .abs-col { background: #fff1f2 !important; color: #be123c; }
        .dialogue-card { border: 2px solid #000; background: #fff; padding: 30px; margin-top: 40px; }
        .dialogue-title { font-weight: 900; color: #000; border-bottom: 2px solid #000; display: block; margin-bottom: 20px; text-transform: uppercase; }
        .question-label { font-weight: 800; color: #000; font-size: 12px; margin-bottom: 4px; display: block; }
        .answer-box { border: 1px solid #e2e8f0; min-height: 50px; margin-bottom: 15px; background: #fcfcfc; }
        #print-header { display: none; text-align: center; }
        #print-header h1 { font-size: 24px; font-weight: 900; text-decoration: underline; margin-bottom: 5px; }

        /* --- Navigation Submenus --- */
        .nav-dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .nav-dropdown-toggle .fa-chevron-down {
            transition: transform 0.3s ease;
            font-size: 0.7rem;
        }

        .nav-submenu {
            display: none;
            flex-direction: column;
            gap: 0.25rem;
            margin-top: 0.25rem;
            padding-left: 0.5rem;
        }

        .nav-group.active .nav-submenu {
            display: flex;
        }

        .nav-group.active .nav-dropdown-toggle .fa-chevron-down {
            transform: rotate(180deg);
        }

        .nav-submenu a.active-link {
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary) !important;
            font-weight: 700 !important;
        }

        /* --- Mobile Navigation & Hamburger Drawer Styles --- */
        .mobile-nav-toggle {
            display: none;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 0.35rem 0.55rem;
            border-radius: 0.375rem;
            transition: background 0.2s;
        }

        .mobile-nav-toggle:active {
            background: rgba(255, 255, 255, 0.3);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(2px);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }

        @media (max-width: 991.98px) {
            .mobile-nav-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .mobile-sidebar-close {
                display: block;
            }

            .app-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                width: 280px;
                height: 100vh;
                height: 100dvh;
                transform: translateX(-100%);
                box-shadow: 5px 0 25px rgba(0, 0, 0, 0.15);
            }

            body.menu-open .app-sidebar {
                transform: translateX(0);
            }

            body.menu-open .sidebar-overlay {
                display: block;
                opacity: 1;
            }

            .dashboard-main-content {
                padding: 1rem 0.75rem;
            }
        }

        @media print {
            .no-print, .app-sidebar, .mockup-header, .report-header, .btn, footer { display: none !important; }
            #print-header { display: block !important; }
            .analytics-box { border: 1px solid #ccc; }
            body.app-body { background: white; height: auto; overflow: visible; display: block; }
            .app-main-area { height: auto; overflow: visible; }
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Navigation Menu -->
    <aside class="app-sidebar no-print" id="appSidebar">
        <div class="app-sidebar-header">
            <span>EduLin<span>C</span>ore</span>
            <button class="mobile-sidebar-close" id="sidebarClose" aria-label="Close Navigation">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 0.85rem 1rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; background: var(--bg-body);">
            <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Profile" style="width: 36px; height: 36px; border-radius: 50%;">
            <div style="overflow: hidden;">
                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($_SESSION['user_name'] ?? 'IT Officer') ?></div>
                <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">School IT Officer</div>
            </div>
        </div>
        
        <nav style="padding: 0.85rem; display: flex; flex-direction: column; gap: 0.3rem; flex: 1; overflow-y: auto;">
            <a href="<?= BASE_URL ?>/it/dashboard" class="nav-main-link" style="padding: 0.6rem 0.75rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-house" style="width: 16px;"></i> Dashboard
            </a>

            <!-- Students Menu Group -->
            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Students</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/students/enroll" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-plus" style="width: 14px; font-size: 0.75rem;"></i> Enroll New Student
                    </a>
                    <a href="<?= BASE_URL ?>/it/students/management" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-users-gear" style="width: 14px; font-size: 0.75rem;"></i> Manage Students
                    </a>
                </div>
            </div>

            <!-- Teachers Menu Group -->
            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/teachers/register" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-tie" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chalkboard-user" style="width: 14px; font-size: 0.75rem;"></i> Manage Teachers
                    </a>
                </div>
            </div>

            <!-- School Setup Menu Group -->
            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>School Setup</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/setup/grades" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-layer-group" style="width: 14px; font-size: 0.75rem;"></i> Manage Grade Levels
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/classes" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-school-flag" style="width: 14px; font-size: 0.75rem;"></i> Manage Classes
                    </a>
                </div>
            </div>

            <!-- Exams & Reports Menu Group (Active for Analysis) -->
            <div class="nav-group active" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Exams & Reports</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu" style="display: flex;">
                    <a href="<?= BASE_URL ?>/it/reports/cards" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-file-invoice" style="width: 14px; font-size: 0.75rem;"></i> Generate Report Cards
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/scoresheets" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-table-cells" style="width: 14px; font-size: 0.75rem;"></i> Generate Score Sheets
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/analysis" class="active-link" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; background: rgba(37, 99, 235, 0.08);">
                        <i class="fa-solid fa-chart-pie" style="width: 14px; font-size: 0.75rem;"></i> Generate Class Analysis
                    </a>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/auth/logout" style="padding: 0.6rem 0.75rem; border-radius: 0.5rem; color: #dc2626; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem;">
                <i class="fa-solid fa-power-off" style="width: 16px;"></i> Secure Logout
            </a>
        </nav>
    </aside>

    <div class="app-main-area">
        <header class="mockup-header no-print">
            <div style="display: flex; align-items: center; gap: 0.65rem; min-width: 0;">
                <button class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <a class="brand-text text-truncate" href="#">Edulin<span class="core-c">C</span>ore | ANALYSIS</a>
            </div>
        </header>

        <div class="dashboard-main-content">
            <div class="container-fluid px-lg-3">
    
                <div class="report-header no-print">
                    <form class="row g-2">
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">GRADE LEVEL</label>
                            <select name="grade_level" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Select Grade</option>
                                <?php 
                                $gStmt = $db->prepare("SELECT DISTINCT grade_level FROM student_enrollments WHERE school_id = ?");
                                $gStmt->execute([$school['id']]);
                                foreach($gStmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
                                    echo "<option ".($v==$grade?'selected':'').">$v</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">CLASS</label>
                            <select name="class_name" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">All Classes</option>
                                <?php 
                                if($grade){
                                    $stmt = $db->prepare("SELECT DISTINCT class_name FROM student_enrollments WHERE school_id=? AND grade_level=?");
                                    $stmt->execute([$school['id'], $grade]);
                                    while($c = $stmt->fetchColumn()) echo "<option ".($c==$class?'selected':'').">$c</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">YEAR</label>
                            <select name="academic_year" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php 
                                $yStmt = $db->prepare("SELECT DISTINCT academic_year FROM marks WHERE school_id = ?");
                                $yStmt->execute([$school['id']]);
                                foreach($yStmt->fetchAll(PDO::FETCH_COLUMN) as $y) {
                                    echo "<option ".($y==$year?'selected':'').">$y</option>";
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">TERM</label>
                            <select name="term" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">All Terms</option>
                                <?php 
                                foreach($standard_terms as $t) {
                                    echo "<option ".($t==$term?'selected':'').">$t</option>";
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted">ASSESSMENT</label>
                            <select name="assessment_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php 
                                foreach($standard_assessments as $st_type) {
                                    echo "<option ".($st_type==$type?'selected':'').">$st_type</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-dark btn-sm w-50 fw-bold">REFRESH</button>
                            <button type="button" class="btn btn-primary btn-sm w-50 fw-bold d-flex align-items-center justify-content-center gap-1" onclick="window.print()">
                                <i class="fa-solid fa-print"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <?php if($grade): ?>
                
                <div id="print-header">
                    <h1>MINISTRY OF EDUCATION</h1>
                    <h4 class="fw-bold">Learner Performance Analysis Report</h4>
                    <p class="text-uppercase fw-bold"><?= $school['school_name'] ?? 'School' ?> | <?= $grade ?> | <?= $year ?> <?= $term ?></p>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="analytics-box h-100 shadow-sm">
                            <h6 class="text-muted small fw-bold uppercase">Overall Pass Rate (<?= $pass_label_2 ?>)</h6>
                            <h2 class="fw-bold" style="color:var(--brand-blue)" id="overallRate">...</h2>
                            <div class="progress" style="height: 8px;"><div id="overallProgress" class="progress-bar" style="background: var(--brand-purple)" role="progressbar"></div></div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="card shadow-sm p-3 border-0">
                            <canvas id="subjectChart" style="height: 150px; width: 100%;"></canvas>
                        </div>
                    </div>
                </div>

                <?php 
                $totalPass1 = 0; $totalPass2 = 0; $grandTotalSat = 0;
                foreach($allTables as $table): ?>
                <div class="card shadow-sm mb-4 p-3 border-0">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">CLASS: <?= strtoupper($table['className']) ?> PERFORMANCE SUMMARY</h6>
                    <div class="table-responsive">
                        <table class="table table-analysis">
                            <thead>
                                <tr>
                                    <th rowspan="2">SN</th><th rowspan="2">SUBJECT</th><th colspan="3">ENTERED</th><th colspan="3">SAT</th><th colspan="3" class="abs-col">ABSENT</th>
                                    <?php foreach($scheme as $l=>$r) echo "<th colspan='3'>$l</th>"; ?>
                                    <th colspan="2">PASS %</th>
                                </tr>
                                <tr>
                                    <th>M</th><th>F</th><th>T</th><th>M</th><th>F</th><th>T</th><th class="abs-col">M</th><th class="abs-col">F</th><th class="abs-col">T</th>
                                    <?php foreach($scheme as $l=>$r) echo "<th>M</th><th>F</th><th>T</th>"; ?>
                                    <th><?= $pass_label_1 ?></th><th><?= $pass_label_2 ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($table['rows'] as $idx => $r): 
                                    $tSat = $r['m_sat'] + $r['f_sat'];
                                    $tAbs = $r['m_abs'] + $r['f_abs'];
                                    $p_l1 = 0; $p_l2 = 0; $counter = 0;
                                    foreach($r['dist'] as $b) {
                                        $sum = $b['m']+$b['f'];
                                        if($counter < $limit_1) $p_l1 += $sum;
                                        if($counter < $limit_2) $p_l2 += $sum;
                                        $counter++;
                                    }
                                    $perc1 = $tSat > 0 ? round(($p_l1/$tSat)*100, 1) : 0;
                                    $perc2 = $tSat > 0 ? round(($p_l2/$tSat)*100, 1) : 0;
                                    $grandTotalSat += $tSat; $totalPass1 += $p_l1; $totalPass2 += $p_l2;
                                ?>
                                <tr>
                                    <td><?= $idx+1 ?></td><td class="subject-col"><?= $r['name'] ?></td>
                                    <td><?= $r['m_ent'] ?></td><td><?= $r['f_ent'] ?></td><td><?= $r['m_ent']+$r['f_ent'] ?></td>
                                    <td><?= $r['m_sat'] ?></td><td><?= $r['f_sat'] ?></td><td><?= $tSat ?></td>
                                    <td class="abs-col"><?= $r['m_abs'] ?></td><td class="abs-col"><?= $r['f_abs'] ?></td><td class="abs-col"><?= $tAbs ?></td>
                                    <?php foreach($r['dist'] as $bucket): ?><td><?= $bucket['m'] ?></td><td><?= $bucket['f'] ?></td><td><?= $bucket['m']+$bucket['f'] ?></td><?php endforeach; ?>
                                    <td class="pass-cell"><?= $perc1 ?>%</td><td class="pass-cell"><?= $perc2 ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="dialogue-card">
                    <h5 class="dialogue-title">EdulinCore | Dialogue & Intervention Track</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <span class="question-label">1. Syllabus Coverage:</span>
                            <p class="small text-muted mb-1">Status of scheme of work completion and assessment frequency.</p>
                            <div class="answer-box"></div>
                            <span class="question-label">2. Quality Control:</span>
                            <p class="small text-muted mb-1">Standard of test (Bloom's Taxonomy) and marking reliability.</p>
                            <div class="answer-box"></div>
                        </div>
                        <div class="col-md-6">
                            <span class="question-label">3. Success Evidence:</span>
                            <p class="small text-muted mb-1">Methodology used in subjects with high pass rates.</p>
                            <div class="answer-box"></div>
                            <span class="question-label">4. Remedial Strategy:</span>
                            <p class="small text-muted mb-1">Specific interventions for the upcoming term.</p>
                            <div class="answer-box"></div>
                        </div>
                    </div>
                    <div class="mt-5 d-flex justify-content-around">
                        <div class="text-center" style="width:250px; border-top:2px solid #000; font-size:10px; padding-top:5px; font-weight:bold;">CLASS TEACHER / GUIDANCE</div>
                        <div class="text-center" style="width:250px; border-top:2px solid #000; font-size:10px; padding-top:5px; font-weight:bold;">HEAD TEACHER / STAMP</div>
                    </div>
                </div>

                <script>
                    const overallRateValue = '<?= $grandTotalSat > 0 ? round(($totalPass2/$grandTotalSat)*100, 1) : 0 ?>%';
                    document.getElementById('overallRate').innerText = overallRateValue;
                    document.getElementById('overallProgress').style.width = overallRateValue;

                    new Chart(document.getElementById('subjectChart'), {
                        type: 'bar',
                        data: {
                            labels: <?= json_encode(array_keys($chartData)) ?>,
                            datasets: [{
                                label: 'Quality Index (<?= $pass_label_1 ?>) %',
                                data: <?= json_encode(array_map(function($v){ return $v['sat'] > 0 ? round(($v['p1']/$v['sat'])*100, 1) : 0; }, $chartData)) ?>,
                                backgroundColor: '#2563eb',
                                borderRadius: 4
                            }]
                        },
                        options: { 
                            maintainAspectRatio: false, 
                            plugins: { legend: { display: false } },
                            scales: { 
                                y: { beginAtZero: true, max: 100 },
                                x: { ticks: { font: { size: 9, weight: 'bold' } } } 
                            } 
                        }
                    });
                </script>
                <?php endif; ?>
            </div>
        </div>

        <footer class="no-print" style="padding: 0.85rem; background-color: var(--bg-card); border-top: 1px solid var(--border-light); text-align: center; font-size: 0.75rem; color: var(--text-muted);">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <!-- UI Script for Mobile Sidebar Drawer -->
    <script>
        function toggleSubMenu(element) {
            const group = element.closest('.nav-group');
            group.classList.toggle('active');
        }

        document.addEventListener("DOMContentLoaded", function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const appSidebar = document.getElementById('appSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            function toggleMobileMenu() {
                document.body.classList.toggle('menu-open');
            }

            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleMobileMenu);
            if (sidebarClose) sidebarClose.addEventListener('click', toggleMobileMenu);
            if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleMobileMenu);

            const sidebarLinks = appSidebar.querySelectorAll('a');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 992 && !this.classList.contains('nav-dropdown-toggle')) {
                        document.body.classList.remove('menu-open');
                    }
                });
            });
        });
    </script>
</body>
</html>