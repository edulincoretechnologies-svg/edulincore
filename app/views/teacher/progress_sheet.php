<?php
/**
 * EduLinCore National Portal - Progress Sheet Module
 * Path: app/views/teacher/progress_sheet.php
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

// Fetch school ID dynamically if missing from session
if (!$school_id) {
    $stmt = $db->prepare("SELECT school_id FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $school_id = $stmt->fetchColumn();
    $_SESSION['school_id'] = $school_id;
}

// Fetch teacher profile details including the 'level' column
$t_stmt = $db->prepare("SELECT full_name, level FROM teachers WHERE id = ?");
$t_stmt->execute([$teacher_id]);
$teacher_info = $t_stmt->fetch(PDO::FETCH_ASSOC);

$full_name       = $teacher_info['full_name'] ?? 'Teacher Panel';
$operating_level = $teacher_info['level'] ?? ($_SESSION['operating_level'] ?? 'primary');
$_SESSION['operating_level'] = $operating_level;

// Determine mark entry route using exact dashboard logic
$markEntryUrl = (strtolower($operating_level) === 'secondary') 
    ? BASE_URL . '/teacher/secondary_marks' 
    : BASE_URL . '/teacher/primary_marks';

// Fetch School Name and District dynamically from the schools table
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
// 2. FETCH TEACHER ASSIGNMENTS FROM DATABASE
// ==========================================
$a = $db->prepare("SELECT ta.grade_level, ta.class_name, ta.subject_id, sub.subject_name 
                   FROM teacher_assignments ta 
                   JOIN subjects sub ON ta.subject_id = sub.id
                   WHERE ta.teacher_id = ? AND ta.school_id = ?");
$a->execute([$teacher_id, $school_id]);
$assignments = $a->fetchAll(PDO::FETCH_ASSOC);

$totalSubjects = count($assignments);

// Extract distinct dropdown filters from teacher's actual assignments
$years         = ['2025', '2026', '2027'];
$term          = $_GET['term'] ?? 'Term 2';
$academic_year = $_GET['academic_year'] ?? '2026';
$grade_level   = $_GET['grade_level'] ?? '';
$class_name    = $_GET['class_name'] ?? '';
$subject_id    = $_GET['subject_id'] ?? '';
$assessment_type = $_GET['assessment_type'] ?? 'Test 1';

$ready = ($academic_year && $term && $grade_level && $class_name && $subject_id && $assessment_type);

// Resolve selected subject name for display
$subject_name_display = 'General Subject';
foreach ($assignments as $asn) {
    if ((string)$asn['subject_id'] === (string)$subject_id) {
        $subject_name_display = $asn['subject_name'];
        break;
    }
}

// ==========================================
// 3. FETCH STUDENTS & RECORDED MARKS
// ==========================================
$students = [];
$marks = [];

if ($ready) {
    $st = $db->prepare("SELECT se.id enrollment_id, s.full_name, s.student_id_number, s.gender 
                        FROM student_enrollments se 
                        JOIN students s ON s.id = se.student_id 
                        WHERE se.school_id = ? AND se.grade_level = ? AND se.class_name = ? AND se.academic_year = ? 
                        ORDER BY s.full_name ASC");
    $st->execute([$school_id, $grade_level, $class_name, $academic_year]);
    $students = $st->fetchAll(PDO::FETCH_ASSOC);

    $mk = $db->prepare("SELECT enrollment_id, score, absent FROM marks 
                        WHERE subject_id = ? AND class_name = ? AND academic_year = ? AND term = ? 
                        AND assessment_type = ? AND school_id = ?");
    $mk->execute([$subject_id, $class_name, $academic_year, $term, $assessment_type, $school_id]);
    $marks = $mk->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Sheets - <?= htmlspecialchars($school_name) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-700">

    <!-- App Layout Container -->
    <div class="min-h-screen flex flex-col">

        <!-- Inner Wrapper for Sidebar and Main Content -->
        <div class="flex-1 flex min-h-0">

            <!-- Sidebar Navigation (Balanced & Collapsible) -->
            <aside id="sidebar" class="fixed md:static inset-y-0 left-0 z-50 w-64 bg-white border-r border-slate-200 transform -translate-x-full md:translate-x-0 transition-all duration-300 ease-in-out flex flex-col shadow-sm md:shadow-none flex-shrink-0 no-print">
                <!-- Sidebar Header / School Branding -->
                <div class="h-16 flex items-center justify-between px-5 border-b border-slate-100">
                    <div class="flex items-center space-x-2.5 truncate">
                        <div class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-sm font-bold flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <span class="font-bold text-slate-800 text-xs tracking-tight truncate" title="<?= htmlspecialchars($school_name) ?>">
                            <?= htmlspecialchars($school_name) ?>
                        </span>
                    </div>
                    <!-- Close Button for Mobile -->
                    <button id="sidebar-close" class="md:hidden text-slate-400 hover:text-slate-600 p-1">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                <!-- Sidebar Menu Links -->
                <div class="px-3 py-5 flex-1 space-y-1 overflow-y-auto">
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Main Menu</p>
                    
                    <a href="<?= BASE_URL ?>/teacher/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-house w-4 text-center"></i>
                        <span>Dashboard</span>
                    </a>

                    <a href="<?= $markEntryUrl ?>" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                        <span>Mark Entry Portal</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_view_report" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 font-semibold text-xs transition">
                        <i class="fa-solid fa-file-lines w-4 text-center"></i>
                        <span>Progress Sheets</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_analysis_report" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-chart-pie w-4 text-center"></i>
                        <span>Performance Analysis</span>
                    </a>
                </div>

                <!-- Sidebar User Profile Footer -->
                <div class="p-3.5 border-t border-slate-100 bg-slate-50/50">
                    <div class="flex items-center justify-between">
                        <div class="truncate pr-2">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($full_name) ?></p>
                            <p class="text-[9px] text-slate-400 uppercase font-semibold"><?= htmlspecialchars($operating_level) ?> Level</p>
                        </div>
                        <a href="<?= BASE_URL ?>/auth/logout" title="Sign Out" class="text-rose-500 hover:text-rose-600 p-2 rounded-lg hover:bg-rose-50 transition flex-shrink-0">
                            <i class="fa-solid fa-right-from-bracket text-xs"></i>
                        </a>
                    </div>
                </div>
            </aside>

            <!-- Main Wrapper Container -->
            <div id="main-content-wrapper" class="flex-1 flex flex-col min-w-0">
                
                <!-- Top Navigation Bar -->
                <header class="bg-white border-b border-slate-200 sticky top-0 z-30 no-print">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <button id="sidebar-toggle" class="text-slate-600 hover:text-slate-900 focus:outline-none p-2 rounded-lg hover:bg-slate-100 transition" title="Toggle Sidebar">
                                <i class="fa-solid fa-bars text-base"></i>
                            </button>
                            <div>
                                <h1 class="font-bold text-slate-800 text-sm sm:text-base leading-tight">Progress Sheets Portal</h1>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <a href="<?= $markEntryUrl ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition shadow-xs flex items-center space-x-2">
                                <i class="fa-solid fa-pen-to-square"></i>
                                <span>Enter Marks</span>
                            </a>
                            <a href="<?= BASE_URL ?>/auth/logout" class="bg-white hover:bg-slate-50 text-slate-600 hover:text-rose-600 px-3 py-2 rounded-xl text-xs sm:text-sm font-medium transition flex items-center space-x-1.5 border border-slate-200">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span class="hidden sm:inline">Sign Out</span>
                            </a>
                        </div>
                    </div>
                </header>

                <!-- Main Content Container -->
                <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                    
                    <!-- Filter Controls Card -->
                    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 no-print">
                        <div class="flex items-center space-x-2.5 mb-4 pb-3 border-b border-slate-100">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">
                                <i class="fa-solid fa-filter"></i>
                            </div>
                            <h3 class="font-bold text-slate-800 text-base">Select Progress Sheet Parameters</h3>
                        </div>

                        <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Academic Year</label>
                                <select name="academic_year" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="">Choose Year...</option>
                                    <?php foreach($years as $yr): ?>
                                        <option value="<?= htmlspecialchars($yr) ?>" <?= (string)$academic_year === (string)$yr ? 'selected' : '' ?>><?= htmlspecialchars($yr) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Term</label>
                                <select name="term" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="">Choose Term...</option>
                                    <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                                    <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                                    <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Grade Level</label>
                                <select name="grade_level" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="">Choose Grade...</option>
                                    <?php 
                                    $unique_grades = array_unique(array_column($assignments, 'grade_level'));
                                    foreach($unique_grades as $gl): 
                                    ?>
                                        <option value="<?= htmlspecialchars($gl) ?>" <?= $grade_level === $gl ? 'selected' : '' ?>><?= htmlspecialchars($gl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Class Stream</label>
                                <select name="class_name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="">Choose Class...</option>
                                    <?php 
                                    foreach($assignments as $asn): 
                                        if(!$grade_level || $asn['grade_level'] === $grade_level):
                                    ?>
                                        <option value="<?= htmlspecialchars($asn['class_name']) ?>" <?= $class_name === $asn['class_name'] ? 'selected' : '' ?>><?= htmlspecialchars($asn['class_name']) ?></option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Assigned Subject</label>
                                <select name="subject_id" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="">Choose Subject...</option>
                                    <?php 
                                    foreach($assignments as $asn): 
                                        if(!$grade_level || $asn['grade_level'] === $grade_level):
                                    ?>
                                        <option value="<?= htmlspecialchars($asn['subject_id']) ?>" <?= (string)$subject_id === (string)$asn['subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars($asn['subject_name']) ?></option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase mb-1">Assessment Type</label>
                                <select name="assessment_type" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs sm:text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" required onchange="this.form.submit()">
                                    <option value="Test 1" <?= $assessment_type === 'Test 1' ? 'selected' : '' ?>>Test 1</option>
                                    <option value="Test 2" <?= $assessment_type === 'Test 2' ? 'selected' : '' ?>>Test 2</option>
                                    <option value="End of Term Test" <?= $assessment_type === 'End of Term Test' ? 'selected' : '' ?>>End of Term Test</option>
                                </select>
                            </div>
                        </form>
                    </div>

                    <!-- Progress Sheet Output View -->
                    <?php if ($ready): ?>
                        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 sm:p-8 space-y-6">
                            <div class="flex justify-end gap-2 no-print">
                                <a href="<?= BASE_URL ?>/teacher_analysis_report" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-3.5 py-2 rounded-xl text-xs font-semibold transition flex items-center space-x-1.5">
                                    <i class="fa-solid fa-chart-pie"></i>
                                    <span>Analysis View</span>
                                </a>
                                <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-900 text-white px-3.5 py-2 rounded-xl text-xs font-semibold transition flex items-center space-x-1.5">
                                    <i class="fa-solid fa-print"></i>
                                    <span>Print Sheet</span>
                                </button>
                            </div>

                            <div class="text-center space-y-1 pb-4 border-b border-slate-200">
                                <h4 class="text-xs sm:text-sm font-bold uppercase text-slate-500 tracking-wider">MINISTRY OF EDUCATION - <?= strtoupper($operating_level) ?> SECTION</h4>
                                <h3 class="text-lg sm:text-xl font-extrabold text-slate-800 uppercase tracking-tight">PROGRESS SHEET</h3>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-xl text-xs border border-slate-100">
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">District</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($district_name) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">School Name</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($school_name) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">Grade Level</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($grade_level) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">Class Stream</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($class_name) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">Term</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($term) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">Assessment Type</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($assessment_type) ?></span></div>
                                <div><span class="font-bold text-slate-400 block uppercase text-[10px]">Academic Year</span><span class="font-semibold text-slate-800"><?= htmlspecialchars($academic_year) ?></span></div>
                            </div>

                            <div class="text-sm font-bold text-slate-800">
                                SUBJECT: <span class="font-normal underline decoration-indigo-300 underline-offset-4 ml-1"><?= htmlspecialchars($subject_name_display) ?></span>
                            </div>

                            <div class="overflow-x-auto border border-slate-200 rounded-xl">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 text-slate-400 text-[11px] uppercase tracking-wider font-bold border-b border-slate-200">
                                            <th class="px-4 py-3 text-center w-16">S/N</th>
                                            <th class="px-4 py-3">Pupil's Full Name</th>
                                            <th class="px-4 py-3 text-center w-24">Gender</th>
                                            <th class="px-4 py-3 text-center w-28">Score</th>
                                            <th class="px-4 py-3 text-center w-24">Grade</th>
                                            <th class="px-4 py-3 text-center">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-xs sm:text-sm text-slate-600">
                                        <?php if(empty($students)): ?>
                                            <tr>
                                                <td colspan="6" class="px-6 py-8 text-center text-slate-400">No student enrollment records found matching these parameters.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $sn = 1; 
                                            foreach($students as $row): 
                                                $enrollment_id = $row['enrollment_id'];
                                                $m_data = $marks[$enrollment_id] ?? null;
                                                $score = $m_data['score'] ?? null;
                                                $absent = ($m_data['absent'] ?? 0) == 1;
                                                
                                                $grade_letter = '-';
                                                $remarks = '-';

                                                if ($absent) {
                                                    $score_display = 'ABS';
                                                    $remarks = 'Absent';
                                                } elseif ($score !== null) {
                                                    $score_display = $score;
                                                    
                                                    // Level-based automated grade calculation
                                                    if (strtolower($operating_level) === 'primary') {
                                                        if ($score >= 75) { $grade_letter = '1'; $remarks = 'Exceeding Expectations'; }
                                                        elseif ($score >= 60) { $grade_letter = '2'; $remarks = 'Meeting Expectations'; }
                                                        elseif ($score >= 40) { $grade_letter = '4'; $remarks = 'Approaching Expectations'; }
                                                        else { $grade_letter = '5'; $remarks = 'Below Expectations'; }
                                                    } else {
                                                        if ($score >= 75) { $grade_letter = 'A'; $remarks = 'Distinction'; }
                                                        elseif ($score >= 65) { $grade_letter = 'B'; $remarks = 'Merit'; }
                                                        elseif ($score >= 50) { $grade_letter = 'C'; $remarks = 'Credit'; }
                                                        elseif ($score >= 40) { $grade_letter = 'P'; $remarks = 'Pass'; }
                                                        else { $grade_letter = 'F'; $remarks = 'Fail'; }
                                                    }
                                                } else {
                                                    $score_display = '-';
                                                }
                                            ?>
                                                <tr class="hover:bg-slate-50/50 transition">
                                                    <td class="px-4 py-3 text-center text-slate-400 font-medium"><?= $sn++ ?></td>
                                                    <td class="px-4 py-3 font-medium text-slate-800"><?= htmlspecialchars($row['full_name']) ?></td>
                                                    <td class="px-4 py-3 text-center uppercase"><?= htmlspecialchars($row['gender'] ?? '') ?></td>
                                                    <td class="px-4 py-3 text-center font-bold text-slate-800"><?= htmlspecialchars($score_display) ?></td>
                                                    <td class="px-4 py-3 text-center font-semibold text-indigo-600"><?= htmlspecialchars($grade_letter) ?></td>
                                                    <td class="px-4 py-3 text-center text-slate-500"><?= htmlspecialchars($remarks) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pt-4 border-t border-slate-100 flex justify-between text-xs text-slate-400">
                                <span>Platform: edulincore.site</span>
                                <span>Module: <?= ucfirst($operating_level) ?> Progress Sheet</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>

                <!-- Page Footer -->
                <footer class="bg-white border-t border-slate-200 py-4 px-6 text-center text-xs text-slate-500 flex flex-wrap items-center justify-center gap-2 no-print">
                    <span>Edulincore Technologies &reg; <?= date('Y'); ?>. All Rights Reserved.</span>
                    <span style="color: #334155;">|</span>
                    <span>Hotline: 0977323918</span>
                </footer>

            </div>
        </div>
    </div>

    <!-- Sidebar Toggle Script -->
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('sidebar-close');
        const mainContentWrapper = document.getElementById('main-content-wrapper');

        function toggleSidebar(e) {
            if (e) e.stopPropagation();
            if (window.innerWidth >= 768) {
                sidebar.classList.toggle('md:-ml-64');
                sidebar.classList.toggle('-translate-x-full');
            } else {
                sidebar.classList.toggle('-translate-x-full');
            }
        }

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarClose.addEventListener('click', toggleSidebar);

        mainContentWrapper.addEventListener('click', () => {
            if (window.innerWidth < 768) {
                if (!sidebar.classList.contains('-translate-x-full')) {
                    sidebar.classList.add('-translate-x-full');
                }
            }
        });
    </script>
</body>
</html>