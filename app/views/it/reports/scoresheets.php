<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

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

/* --- SCHOOL DATA --- */
$stmt = $db->prepare("SELECT school_name FROM schools WHERE id = ? LIMIT 1");
$stmt->execute([$school_id]);
$school = $stmt->fetch(PDO::FETCH_ASSOC);
$display_school_name = $school['school_name'] ?? 'UNKNOWN SCHOOL';

/* ================= DATA FETCHING FOR INITIAL DEFAULTS ================= */
$grades = $db->query("SELECT DISTINCT grade_level FROM student_enrollments WHERE school_id=$school_id ORDER BY grade_level")->fetchAll(PDO::FETCH_COLUMN);
$years  = $db->query("SELECT DISTINCT academic_year FROM student_enrollments WHERE school_id=$school_id ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$terms_list  = ['Term 1','Term 2','Term 3'];

/* ================= HELPERS & LOGIC ================= */
function f($k,$d=''){ return $_GET[$k]??$_POST[$k]??$d; }

$grade = f('grade', $grades[0] ?? '');

// Fetch available classes for the selected grade
$classes = [];
if($grade){
    $stmt=$db->prepare("SELECT DISTINCT class_name FROM student_enrollments WHERE school_id=? AND grade_level=? ORDER BY class_name");
    $stmt->execute([$school_id,$grade]);
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$class = f('class', $classes[0] ?? '');
$term  = f('term', 'Term 1');
$year  = f('year', date('Y'));

$is_ready = (!empty($year) && !empty($term) && !empty($grade) && !empty($class));

// Section Logic
$section_label = "General Record";
$isPrimary = false;
if (!empty($grade)) {
    $numericGrade = (int)filter_var($grade, FILTER_SANITIZE_NUMBER_INT);
    $isSecondary = (stripos($grade, 'Form') !== false) || ($numericGrade >= 8);
    $section_label = $isSecondary ? "Secondary Section" : "Primary Section";
    $isPrimary = !$isSecondary;
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

$students_data = []; $subjects = []; $marks = [];
$active_columns = ['T1' => false, 'T2' => false, 'E' => false];
$class_sum_averages = 0;
$class_mean_average = 0;
$any_marks_recorded = false;

if($is_ready) {
    $sub_sql = "SELECT DISTINCT s.id, s.subject_name, s.short_name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.school_id=? AND ta.grade_level=? AND ta.class_name=?";
    $stmt = $db->prepare($sub_sql);
    $stmt->execute([$school_id, $grade, $class]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!empty($subjects)) {
        $stu_sql = "SELECT st.id, st.full_name, st.gender, se.grade_level, se.class_name FROM students st JOIN student_enrollments se ON se.student_id=st.id WHERE se.school_id=? AND se.academic_year=? AND se.grade_level=? AND se.class_name=?";
        $stmt = $db->prepare($stu_sql);
        $stmt->execute([$school_id, $year, $grade, $class]);
        $raw_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(!empty($raw_students)) {
            $sids = array_column($raw_students,'id'); $sub_ids = array_column($subjects,'id');
            $inS = implode(',',array_fill(0,count($sids),'?')); $inSub = implode(',',array_fill(0,count($sub_ids),'?'));
            $mark_sql = "SELECT m.student_id, m.subject_id, m.assessment_type, m.score FROM marks m WHERE m.student_id IN ($inS) AND m.subject_id IN ($inSub) AND m.term=? AND m.academic_year=?";
            $stmt = $db->prepare($mark_sql);
            $stmt->execute(array_merge($sids, $sub_ids, [$term, $year]));
            
            foreach($stmt as $r){
                $aType = $r['assessment_type'];
                $type = '';
                if($aType === 'Test 1') { $type = 'T1'; }
                elseif($aType === 'Test 2') { $type = 'T2'; }
                elseif($aType === 'End of Term Test') { $type = 'E'; }
                
                if($type !== '' && $r['score'] !== null && $r['score'] !== '') {
                    $marks[$r['student_id']][$r['subject_id']][$type] = $r['score'];
                    $active_columns[$type] = true;
                    $any_marks_recorded = true;
                }
            }

            foreach($raw_students as $st){
                $sid=$st['id']; $total_avg_sum=0; $sub_count=0;
                foreach($subjects as $sub){
                    $m = $marks[$sid][$sub['id']]??[]; $sVal=0; $sExists=0;
                    foreach(['T1','T2','E'] as $k){ 
                        if(isset($m[$k]) && $m[$k] !== ''){ $sVal+=$m[$k]; $sExists++; } 
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
$col_span = max(1, count(array_filter($active_columns)));
$max_possible_total = count($subjects) * 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Schedule | EduLinCore</title>
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8;
            --accent: #ff9800; 
            --sidebar-width: 260px;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body { background: var(--bg-body); font-family: 'Times New Roman', Times, serif; font-size: 12px; overflow-x: hidden; color: var(--text-main); }
        .sidebar { width: var(--sidebar-width); height: 100vh; height: 100dvh; background: var(--bg-card); border-right: 1px solid var(--border-light); position: fixed; left: 0; top: 0; color: var(--text-main); padding: 20px; z-index: 1050; transition: left 0.3s ease-in-out; display: flex; flex-direction: column; }
        .app-sidebar-header { padding: 0.5rem 0 1.25rem 0; font-size: 1.15rem; font-weight: 800; color: var(--primary); border-bottom: 1px solid var(--border-light); }
        .app-sidebar-header span span { color: var(--text-main); }
        .sidebar .nav-link { color: var(--text-muted); padding: 10px 12px; border-radius: 8px; margin-bottom: 4px; transition: 0.3s; text-decoration: none; display: flex; align-items: center; font-size: 14px; font-weight: 600; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(37,99,235,0.08); color: var(--primary); font-weight: 700; }
        .close-sidebar { display: none; position: absolute; top: 15px; right: 20px; background: none; border: none; color: var(--text-main); font-size: 24px; cursor: pointer; }
        .main-content { margin-left: var(--sidebar-width); padding: 25px; transition: all 0.3s ease; }
        
        @media (max-width: 992px) {
            .sidebar { left: -100%; width: 280px; height: 100vh; height: 100dvh; box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1); }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; padding: 15px; }
            .close-sidebar { display: block; }
            .menu-toggle { display: inline-flex !important; }
            body.menu-open .sidebar-overlay { display: block; }
        }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.4); z-index: 1040; }
        .menu-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1000; background: var(--primary); color: white; border: none; padding: 8px 12px; border-radius: 5px; align-items: center; gap: 6px; }
        
        .filter-card { background: white; padding: 15px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        
        @keyframes pulse-red { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.02); } 100% { opacity: 1; transform: scale(1); } }
        .blink-alert { animation: pulse-red 1s infinite; }

        .performance-gauge {
            border: 2px solid #000; padding: 15px; border-radius: 10px;
            display: inline-block; min-width: 320px; background: #fff; margin-bottom: 20px;
        }
        .gauge-bar-container { background: #e0e0e0; height: 24px; border-radius: 12px; overflow: hidden; margin: 8px 0; border: 1px solid #999; }
        .gauge-fill { height: 100%; transition: width 0.8s ease; }
        .bg-poor { background: #ff1744 !important; }
        .bg-fair { background: #ff9100 !important; }
        .bg-excellent { background: #00e676 !important; }

        .table-responsive-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 2px solid #000;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            margin-top: 15px;
        }
        .table-report { border: none !important; width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 0; }
        .table-report th, .table-report td { border: 1px solid #000 !important; text-align: center; padding: 5px 3px !important; color: #000; white-space: nowrap; font-size: 11px; }
        .table-report th { background: #f2f2f2 !important; font-weight: bold; }

        .name-cell { text-align: left !important; padding-left: 8px !important; font-weight: bold; width: 220px; min-width: 220px; max-width: 250px; overflow: hidden; text-overflow: ellipsis; }
        .avg-cell { background: #f8fafc !important; font-weight: bold; }
        
        @media print {
            .sidebar, .filter-card, .no-print, .menu-toggle, .sidebar-overlay, .performance-gauge { display: none !important; }
            .main-content { margin-left: 0; padding: 0; }
            .table-responsive-wrapper { border: none !important; box-shadow: none !important; overflow: visible !important; }
            @page { size: A4 landscape; margin: 0.4cm; }
            .table-report { width: 100% !important; }
            body { background: #fff; overflow: hidden; }
        }
    </style>
</head>
<body class="<?= isset($_GET['menu_open']) ? 'menu-open' : '' ?>">
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<button class="menu-toggle no-print" onclick="toggleSidebar()"><i class="fa fa-bars"></i> Menu</button>
<div class="sidebar no-print" id="sidebar">
    <button class="close-sidebar" onclick="toggleSidebar()">&times;</button>
    <div class="app-sidebar-header"><span>EduLin<span>C</span>ore</span></div>
    <div style="padding: 12px 0; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; margin-bottom: 15px;">
        <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%;">
        <div style="overflow: hidden;">
            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);"><?= htmlspecialchars($_SESSION['user_name'] ?? 'IT Officer') ?></div>
            <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">School IT Officer</div>
        </div>
    </div>
    <nav style="display: flex; flex-direction: column; gap: 0.25rem; flex: 1; overflow-y: auto;">
        <a href="<?= BASE_URL ?>/it/dashboard" class="nav-link"><i class="fa fa-home" style="width: 20px;"></i> Dashboard</a>
        <a href="<?= BASE_URL ?>/it/reports/cards" class="nav-link"><i class="fa fa-file-invoice" style="width: 20px;"></i> Report Cards</a>
        <a href="<?= BASE_URL ?>/it/reports/progress" class="nav-link active"><i class="fa fa-table" style="width: 20px;"></i> Mark Schedule</a>
        <a href="<?= BASE_URL ?>/auth/logout" class="nav-link text-danger mt-auto" style="color: #dc2626 !important;"><i class="fa fa-sign-out-alt" style="width: 20px;"></i> Logout</a>
    </nav>
</div>

<div class="main-content">
    <div class="filter-card no-print mt-4 mt-lg-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <h5 class="fw-bold mb-0 text-primary">Mark Schedule Configuration</h5>
            <div class="d-flex gap-2">
                <?php if($any_marks_recorded): ?>
                    <button class="btn btn-primary btn-sm fw-bold px-3" onclick="window.print()"><i class="fa fa-print me-2"></i> PRINT SCHEDULE</button>
                <?php endif; ?>
            </div>
        </div>
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <div class="col-6 col-md-2">
                <label class="small fw-bold text-muted">GRADE LEVEL</label>
                <select name="grade" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach($grades as $g) echo "<option ".($grade==$g?'selected':'').">$g</option>"; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="small fw-bold text-muted">CLASS</label>
                <select name="class" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php 
                    if(empty($classes)) {
                        echo '<option value="">No classes available</option>';
                    } else {
                        foreach($classes as $c) {
                            $sel = ($class == $c) ? 'selected' : '';
                            echo "<option $sel>$c</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="small fw-bold text-muted">YEAR</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach($years as $y) echo "<option ".($year==$y?'selected':'').">$y</option>"; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="small fw-bold text-muted">TERM</label>
                <select name="term" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach($terms_list as $t) echo "<option ".($term==$t?'selected':'').">$t</option>"; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-sm btn-dark w-100 fw-bold">GENERATE SCHEDULE</button>
            </div>
        </form>
    </div>

    <?php if($is_ready && !empty($students_data)): ?>
        
        <?php if(!$any_marks_recorded): ?>
            <!-- Alert shown when no marks exist for the class to prevent confusion -->
            <div class="alert alert-warning text-center p-4 mt-4 shadow-sm" style="border: 2px dashed #f59e0b;">
                <h5 class="fw-bold mb-2"><i class="fa fa-triangle-exclamation me-2"></i> No Marks Recorded Yet</h5>
                <p class="mb-0 text-muted">No assessment marks have been entered for <strong><?= htmlspecialchars($grade) ?> - <?= htmlspecialchars($class) ?></strong> for <strong><?= htmlspecialchars($term) ?> (<?= htmlspecialchars($year) ?>)</strong>. The mark schedule table is hidden until teachers submit marks.</p>
            </div>
        <?php else: 
            $gauge_class = ($class_mean_average < 50) ? 'bg-poor' : (($class_mean_average < 70) ? 'bg-fair' : 'bg-excellent');
        ?>
            <div class="text-center">
                <!-- Performance Gauge (Visible only on screen) -->
                <div class="performance-gauge shadow-sm no-print">
                    <div class="fw-bold text-muted small">OVERALL CLASS PERFORMANCE MEAN AVERAGE</div>
                    <div class="gauge-bar-container">
                        <div class="gauge-fill <?= $gauge_class ?>" style="width: <?= $class_mean_average ?>%;"></div>
                    </div>
                    <div class="h3 fw-bold mb-0"><?= $class_mean_average ?>%</div>
                    
                    <?php if($class_mean_average < 50): ?>
                        <div class="bg-danger p-1 mt-2 blink-alert rounded text-white fw-bold" style="font-size: 12px;">
                            🚨 PERFORMANCE WARNING: CLASS AVERAGE IS BELOW 50%!
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Official Template Header Layout -->
                <div class="fw-bold fs-6 mb-1">MINISTRY OF EDUCATION</div>
                <h4 class="fw-bold text-uppercase mb-1" style="letter-spacing: 0.5px;">MARK SCHEDULE</h4>
                <div class="fw-bold mb-3 fs-6">SCHOOL NAME: <?= strtoupper($display_school_name) ?></div>
                
                <div class="row g-0 fw-bold border border-dark p-2 mb-2 bg-light text-dark" style="font-size: 13px;">
                    <div class="col text-start">GRADE: <?= htmlspecialchars($grade) ?></div>
                    <div class="col text-start">CLASS: <?= htmlspecialchars($class) ?></div>
                    <div class="col text-center">TERM: <?= htmlspecialchars($term) ?></div>
                    <div class="col text-center">TYPE: <?= strtoupper($section_label) ?></div>
                    <div class="col text-end">YEAR: <?= htmlspecialchars($year) ?></div>
                </div>
            </div>

            <div class="table-responsive-wrapper">
                <table class="table-report">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width: 35px;">SN</th>
                            <th rowspan="2" class="name-cell">PUPILS DETAILS<br>PUPIL'S NAME</th>
                            <th rowspan="2" style="width: 40px;">GDR</th>
                            <?php foreach($subjects as $sub): ?>
                                <th colspan="<?= $col_span ?>"><?= strtoupper($sub['short_name'] ?: $sub['subject_name']) ?></th>
                            <?php endforeach; ?>
                            <th colspan="5" style="background: #e2e8f0 !important;">PERFORMANCE</th>
                        </tr>
                        <tr>
                            <?php foreach($subjects as $sub): ?>
                                <?php if($active_columns['T1']): ?><th style="width: 35px;">T1</th><?php endif; ?>
                                <?php if($active_columns['T2']): ?><th style="width: 35px;">T2</th><?php endif; ?>
                                <?php if($active_columns['E']): ?><th style="width: 45px;">END</th><?php endif; ?>
                            <?php endforeach; ?>
                            <th style="background: #e2e8f0 !important; width: 55px;">TOTAL OBTAINED</th>
                            <th style="background: #e2e8f0 !important; width: 50px;">OUT OF</th>
                            <th style="background: #e2e8f0 !important; width: 55px;">AV %</th>
                            <th style="background: #e2e8f0 !important; width: 45px;">RANK</th>
                            <th style="background: #e2e8f0 !important; width: 110px;">REMARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sn = 1;
                        $rank_counter = 1;
                        foreach($students_data as $st): 
                            $sid = $st['id']; 
                            $has_student_marks = ($st['total_points'] > 0);
                        ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td class="name-cell"><?= strtoupper($st['full_name']) ?></td>
                            <td><?= strtoupper($st['gender'][0] ?? '-') ?></td>
                            <?php foreach($subjects as $sub): $m = $marks[$sid][$sub['id']]??[]; ?>
                                <?php if($active_columns['T1']): ?><td><?= $m['T1'] ?? '-' ?></td><?php endif; ?>
                                <?php if($active_columns['T2']): ?><td><?= $m['T2'] ?? '-' ?></td><?php endif; ?>
                                <?php if($active_columns['E']): ?><td><?= $m['E'] ?? '-' ?></td><?php endif; ?>
                            <?php endforeach; ?>
                            <td class="fw-bold"><?= $has_student_marks ? $st['total_points'] : '-' ?></td>
                            <td class="text-muted"><?= $has_student_marks ? $max_possible_total : '-' ?></td>
                            <td class="avg-cell"><?= $has_student_marks ? $st['final_avg'] . '%' : '-' ?></td>
                            <td class="fw-bold"><?= $has_student_marks ? $rank_counter++ : '-' ?></td>
                            <td style="font-size: 10px; font-weight: bold;"><?= getRemarks($st['final_avg'], $isPrimary) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-5 text-center">
                <div class="row">
                    <div class="col-4" style="border-top: 1.5px solid #000; padding-top: 5px;"><strong>Class Teacher's Signature</strong></div>
                    <div class="col-4"></div>
                    <div class="col-4" style="border-top: 1.5px solid #000; padding-top: 5px;"><strong>HEAD TEACHER / STAMP</strong></div>
                </div>
                <div class="text-start mt-4 text-muted small">Generated on: <?= date('d/m/Y H:i') ?> | EduLinCore Management System</div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info text-center mt-5">Please select a valid Grade, Class, Academic Year, and Term configuration to generate the official mark schedule.</div>
    <?php endif; ?>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
        document.body.classList.toggle('menu-open');
    }

    let inactivityTimer;
    const logoutRedirectUrl = '<?= BASE_URL ?>/auth/logout';
    const idleLimitMs = 15 * 60 * 1000;
    function resetIdleTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(() => {
            alert('Your session has timed out due to inactivity for security purposes. You are being logged out.');
            window.location.href = logoutRedirectUrl;
        }, idleLimitMs);
    }
    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(activityEvent => {
        window.addEventListener(activityEvent, resetIdleTimer, true);
    });
    resetIdleTimer();
</script>
</body>
</html>