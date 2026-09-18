<?php
/**
 * School IT: Classes Setup View | EduLinCore School Management Framework
 * Path: app/views/it/setup/classes.php
 */

if (!defined('APP_PATH')) {
    exit('Direct script access denied.');
}

// Foolproof relative path using __DIR__ to find the controller
$controller_path = __DIR__ . '/../../../controllers/it/ClassLevelSetupController.php';

if (!class_exists('ClassLevelSetupController') && file_exists($controller_path)) {
    require_once $controller_path;
}

// Invoke the controller data handler safely
if (class_exists('ClassLevelSetupController')) {
    $data = ClassLevelSetupController::handleManageRequest();
    $classes = $data['classes'] ?? [];
    $error_message = $data['error_message'] ?? '';
    $success_message = $data['success_message'] ?? '';
    $csrf_token = $data['csrf_token'] ?? '';
    $school_name = $data['school_name'] ?? 'Institution Setup';
} else {
    $classes = [];
    $error_message = 'Critical Error: ClassLevelSetupController file could not be found.';
    $success_message = '';
    $csrf_token = '';
    $school_name = 'Institution Setup';
}

$edit_mode = false;
$edit_class_id = '';
$edit_class_name = '';

if (isset($_GET['edit']) && !empty($classes)) {
    $target_id = $_GET['edit'];
    foreach ($classes as $c) {
        if ($c['id'] == $target_id) {
            $edit_mode = true;
            $edit_class_id = $c['id'];
            $edit_class_name = $c['class_name'];
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Classes - EduLinCore IT Portal</title>
    
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <!-- FORCE LOAD BOOTSTRAP 5 & ICONS DIRECTLY -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
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
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-body);
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

        /* Close Button inside Mobile Sidebar Header */
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
            background-color: var(--primary);
            color: #ffffff;
            padding: 0.85rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .brand-logo {
            font-weight: 700;
            font-size: 0.95rem;
            color: #ffffff;
            text-decoration: none;
            white-space: nowrap;
        }

        .dashboard-main-content {
            padding: 1.25rem;
            flex: 1;
        }

        /* --- Custom Cards & Form Elements for Mobile Layout --- */
        .custom-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.04);
            background: #ffffff;
        }

        .form-control, .form-select {
            font-size: 0.95rem;
            padding: 0.65rem 0.75rem;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }

        .auto-dismiss-toast {
            transition: opacity 0.6s ease-in-out, transform 0.6s ease-in-out;
        }

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
            padding: 0.4rem 0.6rem;
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

        /* Responsive Breakpoints for Mobile Drawer Overlays */
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
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Navigation Menu Aligned with web.php Routes -->
    <aside class="app-sidebar" id="appSidebar">
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
            <a href="<?= BASE_URL ?>/it/dashboard" class="nav-main-link" data-path="/it/dashboard" style="padding: 0.6rem 0.75rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-house" style="width: 16px;"></i> Dashboard
            </a>

            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Students</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/students/enroll" data-path="/it/students/enroll" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-plus" style="width: 14px; font-size: 0.75rem;"></i> Enroll New Student
                    </a>
                    <a href="<?= BASE_URL ?>/it/students/management" data-path="/it/students/management" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-users-gear" style="width: 14px; font-size: 0.75rem;"></i> Manage Students
                    </a>
                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/teachers/register" data-path="/it/teachers/register" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-tie" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" data-path="/it/teachers/manage" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chalkboard-user" style="width: 14px; font-size: 0.75rem;"></i> Manage Teachers
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/view" data-path="/it/teachers/view" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-address-book" style="width: 14px; font-size: 0.75rem;"></i> View Teachers
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/assignments" data-path="/it/teachers/assignments" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book-bookmark" style="width: 14px; font-size: 0.75rem;"></i> Teacher Assignments
                    </a>
                </div>
            </div>

            <div class="nav-group active" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>School Setup</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/setup/grades" data-path="/it/setup/grades" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-layer-group" style="width: 14px; font-size: 0.75rem;"></i> Manage Grade Levels
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/classes" class="active-link" data-path="/it/setup/classes" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-school-flag" style="width: 14px; font-size: 0.75rem;"></i> Manage Classes
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/subjects" data-path="/it/setup/subjects" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book" style="width: 14px; font-size: 0.75rem;"></i> Manage Subjects
                    </a>
                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.35rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.5rem 0.75rem; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Exams & Reports</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/reports/cards" data-path="/it/reports/cards" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-file-invoice" style="width: 14px; font-size: 0.75rem;"></i> Generate Report Cards
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/scoresheets" data-path="/it/reports/scoresheets" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-table-cells" style="width: 14px; font-size: 0.75rem;"></i> Generate Score Sheets
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/analysis" data-path="/it/reports/analysis" style="padding: 0.5rem 0.75rem 0.5rem 1.25rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
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
        <header class="mockup-header">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <button class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <a href="<?= BASE_URL ?>/it/dashboard" class="brand-logo">EduLinCore IT Portal</a>
            </div>
            <nav class="mockup-nav" style="display: flex; align-items: center; gap: 1rem;"></nav>
        </header>

        <div class="dashboard-main-content">
            
            <!-- Feedback Notifications (Auto-Disappearing Toast) -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 border-0 bg-success text-white mb-3 auto-dismiss-toast" role="alert" id="auto-alert">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 border-0 bg-danger text-white mb-3 auto-dismiss-toast" role="alert" id="auto-alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                
                <!-- Left Column: Add / Edit Form Card -->
                <div class="col-12 col-lg-4">
                    <div class="custom-card card h-100">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary bg-opacity-15 text-primary p-2 rounded-3 me-2 fs-5">
                                    <i class="bi <?php echo $edit_mode ? 'bi-pencil-square' : 'bi-plus-lg'; ?>"></i>
                                </div>
                                <h4 class="card-title fw-bold text-dark fs-5 mb-0"><?php echo $edit_mode ? "Edit Class Name" : "Add New Class"; ?></h4>
                            </div>
                            <p class="text-muted small mb-3">Enter the title of the class you wish to configure in the system.</p>
                            
                            <form method="POST" action="<?= BASE_URL ?>/it/setup/classes" id="classForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="<?php echo $edit_mode ? "update_class" : "add_class"; ?>">
                                
                                <?php if ($edit_mode): ?>
                                    <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($edit_class_id); ?>">
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="class_name" class="form-label fw-semibold text-secondary small text-uppercase">Class Name</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-tag"></i></span>
                                        <input type="text" class="form-control bg-light border-start-0 py-2" id="class_name" name="class_name" placeholder="e.g., A, B, BLUE" value="<?php echo htmlspecialchars($edit_class_name); ?>" required>
                                    </div>
                                </div>

                                <div class="d-grid gap-2 mt-3">
                                    <button type="submit" class="btn btn-<?php echo $edit_mode ? "warning text-dark" : "primary"; ?> py-2 fw-semibold shadow-sm">
                                        <i class="bi <?php echo $edit_mode ? 'bi-check2-circle' : 'bi-plus-circle'; ?> me-1"></i> 
                                        <?php echo $edit_mode ? "Update Class Name" : "Save Class Name"; ?>
                                    </button>
                                    
                                    <?php if ($edit_mode): ?>
                                        <a href="<?= BASE_URL ?>/it/setup/classes" class="btn btn-outline-secondary py-2 fw-semibold">
                                            <i class="bi bi-x-circle me-1"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Existing Classes View Table -->
                <div class="col-12 col-lg-8">
                    <div class="custom-card card h-100">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-3 gap-2">
                                <div class="d-flex align-items-center">
                                    <div class="bg-success bg-opacity-15 text-success p-2 rounded-3 me-2 fs-5">
                                        <i class="bi bi-list-columns-reverse"></i>
                                    </div>
                                    <h4 class="card-title fw-bold text-dark fs-5 mb-0">Existing Classes</h4>
                                </div>
                                
                                <!-- Live Search Input -->
                                <div class="input-group input-group-sm w-100 w-sm-auto" style="min-width: 200px;">
                                    <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-search"></i></span>
                                    <input type="text" id="classSearch" class="form-control bg-light border-start-0" placeholder="Filter classes...">
                                </div>
                            </div>
                            <p class="text-muted small mb-3">View or modify configured classes associated with your institution.</p>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle border-light mb-0" id="classesTable">
                                    <thead class="table-light text-uppercase fs-7 text-secondary">
                                        <tr>
                                            <th class="py-2.5 ps-3 rounded-start" style="width: 50px;">#</th>
                                            <th class="py-2.5">Class Name</th>
                                            <th class="py-2.5 text-end pe-3 rounded-end" style="width: 120px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($classes)): ?>
                                            <?php foreach ($classes as $index => $cls): ?>
                                                <tr class="class-row">
                                                    <td class="ps-3 text-muted fw-semibold"><?php echo $index + 1; ?></td>
                                                    <td class="class-name-cell">
                                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($cls['class_name']); ?></div>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <a href="<?= BASE_URL ?>/it/setup/classes?edit=<?php echo $cls['id']; ?>" class="btn btn-sm btn-light text-primary border border-primary-subtle px-2 py-1 me-1 shadow-sm" title="Edit Class">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </a>
                                                        
                                                        <form method="POST" action="<?= BASE_URL ?>/it/setup/classes" class="d-inline delete-form">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                            <input type="hidden" name="action" value="delete_class">
                                                            <input type="hidden" name="class_id" value="<?php echo $cls['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-light text-danger border border-danger-subtle px-2 py-1 shadow-sm delete-btn" title="Delete Class">
                                                                <i class="bi bi-trash-fill"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr id="emptyRow">
                                                <td colspan="3" class="text-center text-muted py-4">
                                                    <div class="mb-2"><i class="bi bi-folder2-open display-6 text-secondary opacity-50"></i></div>
                                                    <p class="mb-1 fw-semibold text-secondary">No classes configured yet.</p>
                                                    <span class="small text-muted">Use the form to add your school's classes.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <footer style="padding: 0.85rem; background-color: var(--bg-card); border-top: 1px solid var(--border-light); text-align: center; font-size: 0.75rem; color: var(--text-muted);">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <!-- EMBEDDED JAVASCRIPT -->
    <script>
        // Sidebar Submenu Toggle
        function toggleSubMenu(element) {
            const group = element.closest('.nav-group');
            group.classList.toggle('active');
        }

        document.addEventListener("DOMContentLoaded", function() {
            // Mobile Sidebar Drawer Controls
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const appSidebar = document.getElementById('appSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            function toggleMobileMenu() {
                document.body.classList.toggle('menu-open');
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleMobileMenu);
            }

            if (sidebarClose) {
                sidebarClose.addEventListener('click', toggleMobileMenu);
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', toggleMobileMenu);
            }

            // Close sidebar when clicking links inside it on mobile screens
            const sidebarLinks = appSidebar.querySelectorAll('a');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 992 && !this.classList.contains('nav-dropdown-toggle')) {
                        document.body.classList.remove('menu-open');
                    }
                });
            });

            // 1. Live Filter / Search Functionality for Existing Classes Table
            const searchInput = document.getElementById('classSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    let filter = searchInput.value.toLowerCase();
                    let rows = document.querySelectorAll('.class-row');
                    
                    rows.forEach(row => {
                        let text = row.querySelector('.class-name-cell').textContent.toLowerCase();
                        if (text.indexOf(filter) > -1) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    });
                });
            }

            // 2. Custom Confirmation Prompt for Deletions
            const deleteForms = document.querySelectorAll('.delete-form');
            deleteForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to delete this class? This database action is permanent.')) {
                        form.submit();
                    }
                });
            });

            // 3. Auto-Disappearing Toast / Alert Logic (Disappears automatically after 4 seconds)
            const alertBox = document.getElementById('auto-alert');
            if (alertBox) {
                setTimeout(() => {
                    alertBox.style.opacity = '0';
                    alertBox.style.transform = 'translateY(-10px)';
                    setTimeout(() => {
                        alertBox.remove();
                    }, 600);
                }, 4000);
            }
        });
    </script>
</body>
</html>