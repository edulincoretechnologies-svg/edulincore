<?php
/**
 * EdulinCore | IT Officer Portal - Student Management & Transitions
 * Version: 2.2.2 (Self-Contained & Fully Responsive - All-In-One Code)
 */

if(session_status() === PHP_SESSION_NONE) session_start();

// -----------------------------------------------------------------
// STRICT BROWSER CACHE PREVENTION (Stops Back/Forward button exploits)
// -----------------------------------------------------------------
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Ensure proper authentication for the IT officer portal
if(empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it'){
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;

if(!$school_id) {
    die("Critical Error: School context missing.");
}

// CRITICAL FIX: Close the session write lock early. 
// This prevents concurrent AJAX POST requests from blocking and timing out.
session_write_close();

try {
    $pdo = Database::getConnection();

    // Fetch school name for display header
    $school_name_stmt = $pdo->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_name_stmt->execute([$school_id]);
    $school_data = $school_name_stmt->fetch();
    $school_name = $school_data['school_name'] ?? 'Your School';

    $grades_q = $pdo->prepare("SELECT grade_name FROM grade_levels WHERE school_id=? ORDER BY grade_name");
    $grades_q->execute([$school_id]);
    $grades_list = $grades_q->fetchAll(PDO::FETCH_COLUMN);

    $classes_q = $pdo->prepare("SELECT class_name FROM class_names WHERE school_id=? ORDER BY class_name");
    $classes_q->execute([$school_id]);
    $classes_list = $classes_q->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    die("Database initialization error: " . $e->getMessage());
}

// 1. BACKEND API ROUTER
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean(); 
    try {
        switch($_POST['action']){
            case 'fetch_students':
                $limit = 20;
                $page = (int)($_POST['page'] ?? 1);
                $offset = ($page - 1) * $limit;
                $f_grade = $_POST['grade'] ?? '';
                $f_class = $_POST['class'] ?? '';
                $f_search = trim($_POST['search'] ?? '');

                $where = ["s.school_id = ?"];
                $params = [$school_id];
                if($f_grade) { $where[] = "se.grade_level = ?"; $params[] = $f_grade; }
                if($f_class) { $where[] = "se.class_name = ?"; $params[] = $f_class; }
                if($f_search) { 
                    $where[] = "(s.full_name LIKE ? OR s.student_id_number LIKE ?)"; 
                    $params[] = "%$f_search%"; $params[] = "%$f_search%"; 
                }

                $where_clause = implode(" AND ", $where);
                $sql_core = "FROM students s 
                            JOIN student_enrollments se ON se.student_id = s.id 
                            WHERE $where_clause AND se.id = (SELECT MAX(id) FROM student_enrollments WHERE student_id = s.id)";

                $total = $pdo->prepare("SELECT COUNT(*) $sql_core");
                $total->execute($params);
                $total_records = $total->fetchColumn();

                $query = $pdo->prepare("SELECT s.id, s.student_id_number, s.full_name, s.gender, se.grade_level, se.class_name, se.academic_year $sql_core ORDER BY s.full_name ASC LIMIT $limit OFFSET $offset");
                $query->execute($params);

                echo json_encode([
                    'status' => 'success',
                    'data' => $query->fetchAll(PDO::FETCH_ASSOC),
                    'pagination' => ['total_pages' => ceil($total_records / $limit), 'current_page' => $page]
                ]);
                break;

            case 'promote_student':
                $check = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND academic_year = ?");
                $check->execute([$_POST['student_id'], $_POST['next_year']]);
                if($check->fetch()){
                    echo json_encode(['status' => 'warning', 'message' => "Entry Blocked: Student already has a record for " . $_POST['next_year']]);
                    exit;
                }

                $pdo->beginTransaction();
                $cur = $pdo->prepare("SELECT id FROM student_enrollments WHERE student_id=? ORDER BY id DESC LIMIT 1");
                $cur->execute([$_POST['student_id']]);
                $last_id = $cur->fetchColumn();
                $status = ($_POST['type'] === 'repeat') ? 'repeated' : 'promoted';
                
                $pdo->prepare("UPDATE student_enrollments SET promotion_status=? WHERE id=?")->execute([$status, $last_id]);
                $pdo->prepare("INSERT INTO student_enrollments (student_id, school_id, academic_year, grade_level, class_name, promotion_status) VALUES (?, ?, ?, ?, ?, 'pending')")
                    ->execute([$_POST['student_id'], $school_id, $_POST['next_year'], $_POST['next_grade'], $_POST['next_class']]);
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Transition posted successfully!']);
                break;

            case 'update_student':
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE students SET full_name = ?, gender = ? WHERE id = ?")->execute([$_POST['name'], $_POST['gender'], $_POST['student_id']]);
                $pdo->prepare("UPDATE student_enrollments SET grade_level = ?, class_name = ?, academic_year = ? WHERE student_id = ? ORDER BY id DESC LIMIT 1")->execute([$_POST['grade'], $_POST['class'], $_POST['year'], $_POST['student_id']]);
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully!']);
                break;

            case 'rollback':
                $pdo->prepare("DELETE FROM student_enrollments WHERE student_id = ? ORDER BY id DESC LIMIT 1")->execute([$_POST['student_id']]);
                echo json_encode(['status' => 'success', 'message' => 'Action rolled back.']);
                break;

            case 'delete_student':
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM student_enrollments WHERE student_id = ?")->execute([$_POST['student_id']]);
                $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$_POST['student_id']]);
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Student record permanently removed.']);
                break;
        }
    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Administrative error: System could not update database.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management | IT Officer Portal - EduLinCore</title>
    
    <!-- Force a hard reload if loaded from the browser's Back/Forward cache (bfcache) -->
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- EMBEDDED COMPLETE STANDALONE STYLESHEET -->
    <style>
        :root { 
            --primary: #2563eb; 
            --primary-dark: #1d4ed8;
            --accent: #3b82f6; 
            --success: #16a34a; 
            --danger: #dc2626; 
            --warning: #d97706; 
            --bg-body: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body.app-body { background-color: var(--bg-body); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }

        /* Sidebar Layout */
        .app-sidebar { width: 260px; background: #ffffff; border-right: 1px solid var(--border-light); display: flex; flex-direction: column; z-index: 100; flex-shrink: 0; }
        .app-sidebar-header { padding: 1.25rem; font-size: 1.15rem; font-weight: 800; color: var(--primary); border-bottom: 1px solid var(--border-light); display: flex; align-items: center; }
        .app-sidebar-header span span { color: var(--text-main); }

        /* Main Workspace Content Area */
        .app-main-area { flex: 1; display: flex; flex-direction: column; overflow-y: auto; background: var(--bg-body); min-width: 0; }
        .mockup-header { height: 60px; background: #0f172a; color: white; display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; flex-shrink: 0; }
        .mockup-header .brand-logo { color: white; font-weight: 700; text-decoration: none; font-size: 0.95rem; }
        .mockup-footer { padding: 1rem 1.5rem; background: #ffffff; border-top: 1px solid var(--border-light); font-size: 0.75rem; color: var(--text-muted); text-align: center; margin-top: auto; }
        .dashboard-main-content { padding: 1.5rem; flex: 1; }

        /* Collapsible Sub-menu Styling */
        .nav-dropdown-toggle { cursor: pointer; display: flex; align-items: center; justify-content: space-between; width: 100%; }
        .nav-dropdown-toggle .fa-chevron-down { transition: transform 0.3s ease; font-size: 0.7rem; }
        .nav-submenu { display: none; flex-direction: column; gap: 0.25rem; margin-top: 0.25rem; padding-left: 0.5rem; }
        .nav-group.active .nav-submenu { display: flex; }
        .nav-group.active .nav-dropdown-toggle .fa-chevron-down { transform: rotate(180deg); }

        /* Glass Panel / Filters */
        .glass-panel { 
            background: white; 
            padding: 1.5rem; 
            border-radius: 0.75rem; 
            border: 1px solid var(--border-light); 
            display: grid; 
            grid-template-columns: 1fr 1fr 1.5fr auto; 
            gap: 1.5rem; 
            margin-bottom: 1.5rem; 
            align-items: end; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .field-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .field-group label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        
        .glass-panel input, .glass-panel select, .modal-content input, .modal-content select { 
            height: 40px; 
            padding: 0 0.75rem; 
            border-radius: 0.5rem; 
            border: 1px solid var(--border-light); 
            font-family: inherit; 
            font-size: 0.85rem;
            color: var(--text-main);
            background: #fff;
            width: 100%;
        }
        .glass-panel input:focus, .glass-panel select:focus, .modal-content input:focus, .modal-content select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        .table-actions { display: flex; justify-content: flex-end; margin-bottom: 1rem; }
        .btn-toggle-promo { 
            background: white; 
            border: 1px solid var(--border-light); 
            padding: 8px 16px; 
            border-radius: 0.5rem; 
            font-weight: 700; 
            font-size: 0.85rem;
            color: var(--text-main); 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .btn-toggle-promo.active { background: var(--primary); color: white; border-color: var(--primary); }

        /* Data Card & Table */
        .data-card { 
            background: white; 
            border-radius: 0.75rem; 
            border: 1px solid var(--border-light); 
            overflow: hidden; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); 
            transition: 0.3s; 
        }
        .data-card.promo-active { border: 1.5px solid var(--primary); }

        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .app-main-area table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .app-main-area th { 
            background: #f8fafc; 
            padding: 0.85rem 1rem; 
            text-align: left; 
            font-size: 0.75rem; 
            color: var(--text-muted); 
            font-weight: 700; 
            text-transform: uppercase;
            border-bottom: 1px solid var(--border-light); 
        }
        .app-main-area td { padding: 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; color: var(--text-main); }

        .col-promo { display: none; }

        /* Buttons */
        .btn { 
            padding: 0.5rem 1rem; 
            border: none; 
            border-radius: 0.5rem; 
            font-weight: 600; 
            cursor: pointer; 
            font-size: 0.8rem; 
            transition: 0.2s; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-ghost { background: #f1f5f9; color: var(--text-muted); border: 1px solid var(--border-light); }
        .btn-ghost:hover { background: #e2e8f0; color: var(--text-main); }
        .btn-danger-soft { background: #fef2f2; color: var(--danger); }
        .btn-danger-soft:hover { background: #fee2e2; }

        /* Modals */
        .modal-overlay { 
            display: none; 
            position: fixed; 
            inset: 0; 
            background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(4px); 
            z-index: 2000; 
            align-items: center; 
            justify-content: center; 
            padding: 1rem;
        }
        .modal-content { 
            background: white; 
            width: 100%;
            max-width: 450px; 
            border-radius: 1rem; 
            padding: 1.75rem; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); 
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-footer { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 1.5rem; }

        /* Pagination */
        .paginator { padding: 1rem; display: flex; justify-content: center; gap: 5px; background: #f8fafc; border-top: 1px solid var(--border-light); flex-wrap: wrap; }
        .page-btn { padding: 6px 12px; border: 1px solid var(--border-light); background: white; cursor: pointer; border-radius: 0.375rem; font-weight: 600; font-size: 0.8rem; color: var(--text-muted); }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

        #toast { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            background: var(--text-main); 
            color: white; 
            padding: 10px 20px; 
            border-radius: 0.5rem; 
            font-weight: 600; 
            font-size: 0.85rem;
            display: none; 
            z-index: 3000; 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); 
        }

        .ui-warning-box { 
            background: #fffbeb; 
            border-left: 4px solid var(--warning); 
            padding: 10px 12px; 
            margin-bottom: 1.25rem; 
            border-radius: 0.375rem; 
        }

        /* Responsive Media Queries for All Gadget Screens */
        @media (max-width: 992px) {
            body.app-body {
                flex-direction: column;
                height: auto;
                overflow: auto;
            }
            .app-sidebar {
                width: 100%;
                height: auto;
                max-height: 220px;
                overflow-y: auto;
                border-right: none;
                border-bottom: 1px solid var(--border-light);
            }
            .app-main-area {
                height: auto;
                overflow: visible;
            }
            .glass-panel {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 576px) {
            .dashboard-main-content {
                padding: 1rem 0.75rem;
            }
            .modal-content {
                padding: 1.25rem;
            }
            .modal-footer {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="app-body">

<div id="toast"></div>

    <!-- Sidebar with Collapsible Sub-Navigation Structure -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-header">
            <span>EduLin<span>C</span>ore</span>
        </div>
        <div style="padding: 1.25rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; background: var(--bg-body);">
            <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%;">
            <div style="overflow: hidden;">
                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);"><?= htmlspecialchars($_SESSION['user_name'] ?? 'IT Officer') ?></div>
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">School IT Officer</div>
            </div>
        </div>
        
        <nav style="padding: 1rem; display: flex; flex-direction: column; gap: 0.35rem; flex: 1; overflow-y: auto;">
            <!-- Dashboard Main Link -->
            <a href="<?= BASE_URL ?>/it/dashboard" style="padding: 0.65rem 0.85rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-house" style="width: 16px;"></i> Dashboard
            </a>

            <!-- Students Group (Collapsible - Active State Set Here) -->
            <div class="nav-group active" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Students</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu" style="display: flex;">
                    <a href="<?= BASE_URL ?>/it/students/enroll" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-plus" style="width: 14px; font-size: 0.75rem;"></i> Enroll New Student
                    </a>
                    <a href="<?= BASE_URL ?>/it/students" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; background: rgba(37,99,235,0.1); color: var(--primary); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-users-gear" style="width: 14px; font-size: 0.75rem;"></i> Manage Existing Students
                    </a>
                </div>
            </div>

            <!-- Teachers Group (Collapsible) -->
            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/teachers/register" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-tie" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chalkboard-user" style="width: 14px; font-size: 0.75rem;"></i> Manage Existing Teachers
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/assignments" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book-bookmark" style="width: 14px; font-size: 0.75rem;"></i> Define Teacher Assignment
                    </a>
                </div>
            </div>

            <!-- School setup Group (Collapsible) -->
            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>School Setup</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/setup/grades/add" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-layer-group" style="width: 14px; font-size: 0.75rem;"></i> Add New Grade Levels
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/grades" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-layer-group" style="width: 14px; font-size: 0.75rem;"></i> Manage Existing Grade Levels
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/classes/add" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-school-flag" style="width: 14px; font-size: 0.75rem;"></i> Add New Class
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/classes" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-school-flag" style="width: 14px; font-size: 0.75rem;"></i> Manage Existing Classes
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/subjects/add" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book" style="width: 14px; font-size: 0.75rem;"></i> Add New Subject
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/subjects" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book" style="width: 14px; font-size: 0.75rem;"></i> Manage Existing Subjects
                    </a>
                </div>
            </div>

            <!-- Exams Group (Collapsible) -->
            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Exams & Reports</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/reports/cards" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-file-invoice" style="width: 14px; font-size: 0.75rem;"></i> Generate Report Cards
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/scoresheets" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-table-cells" style="width: 14px; font-size: 0.75rem;"></i> Generate Score Sheets
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/analytics" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chart-pie" style="width: 14px; font-size: 0.75rem;"></i> Generate Class Analysis
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/performance" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chart-line" style="width: 14px; font-size: 0.75rem;"></i> School Performance Report
                    </a>
                </div>
            </div>

            <!-- Logout -->
            <a href="<?= BASE_URL ?>/auth/logout" style="padding: 0.65rem 0.85rem; border-radius: 0.5rem; color: #dc2626; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem;">
                <i class="fa-solid fa-power-off" style="width: 16px;"></i> Secure Logout
            </a>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <div class="app-main-area">
        <header class="mockup-header">
            <a href="<?= BASE_URL ?>/it/dashboard" class="brand-logo">EduLinCore IT Portal</a>
            <nav class="mockup-nav">
                <span style="color: #ffffff; font-weight: 600;"><i class="fa-solid fa-school"></i> <?= htmlspecialchars($school_name) ?></span>
            </nav>
        </header>

        <div class="dashboard-main-content">
            <div class="app-container" style="max-width: 100%; margin: 0; padding: 0;">
                <div class="glass-panel">
                    <div class="field-group"><label>Grade Level</label><select id="f_grade"><option value="">Select Grade</option><?php foreach($grades_list as $g) echo "<option>$g</option>"; ?></select></div>
                    <div class="field-group"><label>Class Room</label><select id="f_class"><option value="">All Classes</option><?php foreach($classes_list as $c) echo "<option>$c</option>"; ?></select></div>
                    <div class="field-group"><label>Search</label><input type="text" id="f_search" placeholder="Enter name or ID..."></div>
                    <button class="btn btn-primary" id="btnFilter" style="height:40px;"><i class="fa-solid fa-rotate"></i> &nbsp; Load Records</button>
                </div>

                <div class="table-actions">
                    <button class="btn-toggle-promo" id="togglePromoMode">
                        <i class="fa-solid fa-lock"></i> Enable Transition Mode
                    </button>
                </div>

                <div class="data-card" id="mainCard">
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th width="25%">Student Profile</th>
                                    <th width="15%">Current Enrolment</th>
                                    <th class="col-promo" width="40%">Academic Transition</th>
                                    <th width="20%">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentBody">
                                <tr><td colspan="4" style="text-align:center; padding: 80px; color: var(--text-muted);">Load records to start.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="pagination" class="paginator"></div>
                </div>
            </div>
        </div>

        <footer class="mockup-footer">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

<!-- MODAL: TRANSITION CONFIRM -->
<div class="modal-overlay" id="modalPlacement">
    <div class="modal-content">
        <div class="ui-warning-box">
            <small style="color: #92400e; font-weight: 800;"><i class="fa-solid fa-circle-exclamation"></i> VERIFICATION REQUIRED</small>
            <p style="font-size: 0.8rem; color: #92400e; margin: 4px 0 0;">Please ensure the academic year and grade level are correct. Placement errors create duplicate history records.</p>
        </div>
        <h3>Confirm Transition</h3>
        <p id="placementText" style="font-size:0.9rem; color:var(--text-muted); line-height: 1.5;"></p>
        
        <div class="field-group" style="margin-top: 1.25rem;">
            <label>Set Academic Year</label>
            <input type="number" id="trans_year_input" placeholder="e.g. 2027">
            <small style="color:var(--text-muted);">Manual override for the target year.</small>
        </div>

        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="$('.modal-overlay').hide()">Cancel</button>
            <button class="btn btn-primary" id="confirmPlacement">Confirm & Post</button>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <h3>Correct Student Profile</h3>
        <input type="hidden" id="edit_id">
        <div class="field-group" style="margin-bottom:1rem;"><label>Full Name</label><input type="text" id="edit_name"></div>
        <div class="field-group" style="margin-bottom:1rem;"><label>Gender</label><select id="edit_gender"><option>Male</option><option>Female</option></select></div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:1rem;">
            <div class="field-group"><label>Grade</label><select id="edit_grade"><?php foreach($grades_list as $g) echo "<option>$g</option>"; ?></select></div>
            <div class="field-group"><label>Class</label><select id="edit_class"><?php foreach($classes_list as $c) echo "<option>$c</option>"; ?></select></div>
        </div>
        <div class="field-group"><label>Academic Year</label><input type="number" id="edit_year"></div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="$('.modal-overlay').hide()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveEdit">Save Changes</button>
        </div>
    </div>
</div>

<!-- FIX / ROLLBACK MODAL -->
<div class="modal-overlay" id="fixModal">
    <div class="modal-content">
        <h3 style="color:var(--danger);">Administrative Actions</h3>
        <input type="hidden" id="fix_id">
        <button class="btn btn-ghost" id="btnRollback" style="width:100%; margin-bottom:10px; justify-content: flex-start;">
            <i class="fa-solid fa-undo"></i> &nbsp; Undo Last Placement Change
        </button>
        <button class="btn btn-danger-soft" id="btnDelete" style="width:100%; justify-content: flex-start;">
            <i class="fa-solid fa-trash"></i> &nbsp; Delete Student Record
        </button>
        <div class="modal-footer">
            <button class="btn btn-primary" style="grid-column: span 2;" onclick="$('.modal-overlay').hide()">Cancel</button>
        </div>
    </div>
</div>

<!-- EMBEDDED COMPLETE JAVASCRIPT LOGIC -->
<script>
function toggleSubMenu(element) {
    const parentGroup = element.closest('.nav-group');
    parentGroup.classList.toggle('active');
}

let currentPage = 1;
let transData = {};
let isPromoMode = false;
const ajaxUrl = window.location.href; // Explicit URL path reference to avoid timeout drops

function showToast(msg, type='success') {
    const t = $('#toast');
    let color = 'var(--text-main)';
    if(type === 'error') color = 'var(--danger)';
    if(type === 'warning') color = 'var(--warning)';
    if(type === 'success') color = 'var(--success)';
    
    t.text(msg).css('background', color).fadeIn().delay(4000).fadeOut();
}

$('#togglePromoMode').click(function() {
    isPromoMode = !isPromoMode;
    if(isPromoMode) {
        $(this).addClass('active').html('<i class="fa-solid fa-lock-open"></i> Disable Transition Mode');
        $('#mainCard').addClass('promo-active');
        $('.col-promo').fadeIn(200);
    } else {$(this).removeClass('active').html('<i class="fa-solid fa-lock"></i> Enable Transition Mode');
        $('#mainCard').removeClass('promo-active');
        $('.col-promo').fadeOut(200);
    }
});

function loadData(page = 1) {
    currentPage = page;
    const g = $('#f_grade').val();
    const s = $('#f_search').val();
    if(!g && !s) return alert("Select grade or search student.");

    $.post(ajaxUrl, { action:'fetch_students', grade:g, class:$('#f_class').val(), search:s, page:page }, function(res) {
        let rows = '';
        res.data.forEach(i => {
            rows += `<tr>
                <td><b>${i.full_name}</b><br><small style="color:var(--text-muted);">ID: ${i.student_id_number}</small></td>
                <td><span style="background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:4px; font-weight:700; font-size:0.75rem;">${i.academic_year}</span><br><small>${i.grade_level} - ${i.class_name}</small></td>
                <td class="col-promo" style="${isPromoMode ? 'display:table-cell' : 'display:none'}">
                    <div style="display:flex; gap:5px; background:#f8fafc; padding:5px; border-radius:8px; border:1px dashed var(--border-light); flex-wrap: wrap;">
                        <select class="ng" style="height:32px; font-size:0.8rem; flex:1; min-width:90px;"><?php foreach($grades_list as $g) echo "<option>$g</option>"; ?></select>
                        <select class="nc" style="height:32px; font-size:0.8rem; flex:1; min-width:90px;"><?php foreach($classes_list as $c) echo "<option>$c</option>"; ?></select>
                        <select class="nt" style="height:32px; font-size:0.8rem; border-color:var(--accent); flex:1; min-width:90px;"><option value="promote">PROMOTE</option><option value="repeat">REPEAT</option></select>
                        <button class="btn btn-success tr-promote" style="height:32px; padding:0 10px;" data-id="${i.id}" data-name="${i.full_name}" data-year="${i.academic_year}">Post</button>
                    </div>
                </td>
                <td>
                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                        <button class="btn btn-ghost tr-edit" data-id="${i.id}" data-name="${i.full_name}" data-gender="${i.gender}" data-grade="${i.grade_level}" data-class="${i.class_name}" data-year="${i.academic_year}">Edit</button>
                        <button class="btn btn-danger-soft tr-fix" data-id="${i.id}">Fix</button>
                    </div>
                </td>
            </tr>`;
        });
        $('#studentBody').html(rows || '<tr><td colspan="4" style="text-align:center; padding:40px; color:var(--text-muted);">No results found.</td></tr>');
        renderPaging(res.pagination.current_page, res.pagination.total_pages);
    }, 'json');
}

function renderPaging(curr, total) {
    if(total <= 1) return $('#pagination').empty();
    let h = `<button class="page-btn" ${curr==1?'disabled':''} onclick="loadData(${curr-1})">Previous</button>`;
    for(let i=1; i<=total; i++) h += `<button class="page-btn ${i==curr?'active':''}" onclick="loadData(${i})">${i}</button>`;
    h += `<button class="page-btn" ${curr==total?'disabled':''} onclick="loadData(${curr+1})">Next</button>`;
    $('#pagination').html(h);
}

$('#btnFilter').click(() => loadData(1));

// TRANSITION HANDLERS
$(document).on('click', '.tr-promote', function() {
    const row = $(this).closest('tr');
    const currentYear = parseInt($(this).data('year'));$('#trans_year_input').val(currentYear + 1);

    transData = { 
        action: 'promote_student', 
        student_id: $(this).data('id'), 
        next_grade: row.find('.ng').val(), 
        next_class: row.find('.nc').val(), 
        type: row.find('.nt').val() 
    };

    $('#placementText').html(`Confirm moving <b>${$(this).data('name')}</b> to <b>${transData.next_grade}</b>. Review the target year:`);
    $('#modalPlacement').css('display', 'flex');
});

$('#confirmPlacement').click(() => {
    transData.next_year = $('#trans_year_input').val(); 
    $.post(ajaxUrl, transData, (res) => { 
        if(res.status === 'success') {
            $('.modal-overlay').hide(); 
            showToast(res.message); 
            loadData(currentPage); 
        } else if(res.status === 'warning') {
            showToast(res.message, 'warning');
        } else {
            showToast(res.message, 'error');
        }
    }, 'json');
});

$('#btnSaveEdit').click(() => {
    const d = { action:'update_student', student_id:$('#edit_id').val(), name:$('#edit_name').val(), gender:$('#edit_gender').val(), grade:$('#edit_grade').val(), class:$('#edit_class').val(), year:$('#edit_year').val() };
    $.post(ajaxUrl, d, (res) => { 
        $('.modal-overlay').hide(); 
        showToast(res.message); 
        loadData(currentPage); 
    }, 'json');
});

$('#btnRollback').click(() => { 
    if(confirm("Undo last change? This deletes the newest enrollment record.")) {
        $.post(ajaxUrl, {action:'rollback', student_id:$('#fix_id').val()}, (res) => { 
            $('.modal-overlay').hide(); 
            showToast(res.message); 
            loadData(currentPage); 
        }, 'json'); 
    }
});

$('#btnDelete').click(() => { 
    if(confirm("CRITICAL: Delete student? This is permanent.")) {
        $.post(ajaxUrl, {action:'delete_student', student_id:$('#fix_id').val()}, (res) => { 
            $('.modal-overlay').hide(); 
            showToast(res.message); 
            loadData(currentPage); 
        }, 'json'); 
    }
});

$(document).on('click', '.tr-edit', function() {
    const d = $(this).data();$('#edit_id').val(d.id); $('#edit_name').val(d.name); $('#edit_gender').val(d.gender);
    $('#edit_grade').val(d.grade); $('#edit_class').val(d.class); $('#edit_year').val(d.year);
    $('#editModal').css('display', 'flex');
});

$(document).on('click', '.tr-fix', function() {$('#fix_id').val($(this).data('id'));$('#fixModal').css('display', 'flex');
});

window.onclick = (e) => { if($(e.target).hasClass('modal-overlay'))$('.modal-overlay').hide(); }
</script>
</body>
</html>