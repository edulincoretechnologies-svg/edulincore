<?php
if(session_status() === PHP_SESSION_NONE) session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

if(empty($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'school_it'){
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;

if(!$school_id) {
    die("Critical Error: School context missing.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $db = Database::getConnection();

    $school_name_stmt = $db->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_name_stmt->execute([$school_id]);
    $school_data = $school_name_stmt->fetch();
    $school_name = $school_data['school_name'] ?? 'Your School';

    $gradeStmt = $db->prepare("SELECT grade_name FROM grade_levels WHERE school_id = ? ORDER BY grade_name ASC");
    $gradeStmt->execute([$school_id]);
    $grades = $gradeStmt->fetchAll(PDO::FETCH_COLUMN);

    $classStmt = $db->prepare("SELECT class_name FROM class_names WHERE school_id = ? ORDER BY class_name ASC");
    $classStmt->execute([$school_id]);
    $classes = $classStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    $grades = [];
    $classes = [];
    $school_name = 'Your School';
}

$old = $_SESSION['old_input'] ?? [];
unset($_SESSION['old_input']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll New Student | IT Officer Portal - EduLinCore</title>
    
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg-body: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
        }

        body, input, button, select, textarea, label, span, h1, h2, h3, h4, h5, h6, a {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
        }

        body.app-body {
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        .app-sidebar {
            width: 260px;
            background: #ffffff;
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 1000;
            transition: left 0.3s ease-in-out;
        }

        .app-sidebar-header {
            padding: 1.25rem;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            border-bottom: 1px solid var(--border-light);
        }

        .app-sidebar-header span span {
            color: var(--text-main);
        }

        .app-main-area {
            flex: 1;
            margin-left: 260px;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        @media (max-width: 768px) {
            .app-main-area {
                margin-left: 0;
            }
            .app-sidebar {
                left: -100%;
            }
            .app-sidebar.sidebar-open {
                left: 0;
            }
            body.menu-open .sidebar-overlay {
                display: block;
            }
        }

        .mockup-header {
            height: 64px;
            background: var(--primary);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .brand-logo {
            color: #ffffff;
            font-weight: 700;
            text-decoration: none;
            font-size: 1.1rem;
        }

        .mockup-footer {
            padding: 1.25rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-light);
            background: #ffffff;
            margin-top: auto;
        }

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
            background: rgba(37,99,235,0.08);
            color: var(--primary) !important;
            font-weight: 700 !important;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1.25rem;
        }

        @media (max-width: 768px) {
            .form-grid-2, .form-grid-3 {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .data-card {
                padding: 1.5rem !important;
            }
        }

        .input-group-modern {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-group-modern .input-icon {
            position: absolute;
            left: 16px;
            color: #475569;
            font-size: 1rem;
            pointer-events: none;
        }
        .input-group-modern .form-control-modern {
            width: 100%;
            height: 52px;
            padding-left: 48px;
            padding-right: 16px;
            border: 1.5px solid #cbd5e1;
            border-radius: 0.6rem;
            font-size: 1rem;
            font-weight: 500;
            color: #0f172a;
            background-color: #fff;
            text-transform: uppercase;
            transition: all 0.2s ease;
        }
        .input-group-modern .form-control-modern:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
            outline: none;
        }
        select.form-control-modern {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 48px;
            text-transform: none; 
        }

        .mobile-nav-toggle {
            display: none;
            background: transparent;
            border: none;
            color: #ffffff;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.35rem;
            border-radius: 0.375rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.4);
            z-index: 999;
        }

        .form-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            border-top: 1.5px solid #f1f5f9;
            padding-top: 1.5rem;
        }

        .form-actions .btn {
            flex: 1;
            max-width: 220px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding-top: 0.8rem;
            padding-bottom: 0.8rem;
            font-size: 0.92rem;
            font-weight: 700;
            border-radius: 0.6rem;
        }

        @media (max-width: 576px) {
            .form-actions {
                flex-direction: column-reverse;
            }
            .form-actions .btn {
                max-width: 100% !important;
                width: 100% !important;
            }
        }

        @media (max-width: 768px) {
            .mobile-nav-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Navigation -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-header">
            <span>EduLin<span>C</span>ore</span>
        </div>
        <div style="padding: 1.25rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; background: #fff;">
            <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%;">
            <div style="overflow: hidden;">
                <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);"><?= htmlspecialchars($_SESSION['user_name'] ?? 'IT Officer') ?></div>
                <div style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">School IT Officer</div>
            </div>
        </div>
        
        <nav style="padding: 1rem; display: flex; flex-direction: column; gap: 0.35rem; flex: 1; overflow-y: auto;">
            <a href="<?= BASE_URL ?>/it/dashboard" class="nav-main-link" data-path="/it/dashboard" style="padding: 0.65rem 0.85rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-house" style="width: 16px;"></i> Dashboard
            </a>

            <div class="nav-group active" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Students</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/students/enroll" data-path="/it/students/enroll" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-plus" style="width: 14px; font-size: 0.75rem;"></i> Enroll New Student
                    </a>
                    <a href="<?= BASE_URL ?>/it/students/management" data-path="/it/students/management" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-users-gear" style="width: 14px; font-size: 0.75rem;"></i> Manage Students
                    </a>
                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/teachers/register" data-path="/it/teachers/register" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-tie" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" data-path="/it/teachers/manage" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chalkboard-user" style="width: 14px; font-size: 0.75rem;"></i> Manage Teachers
                    </a>
                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>School Setup</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/setup/grades" data-path="/it/setup/grades" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-layer-group" style="width: 14px; font-size: 0.75rem;"></i> Manage Grade Levels
                    </a>
                    <a href="<?= BASE_URL ?>/it/setup/classes" data-path="/it/setup/classes" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-school-flag" style="width: 14px; font-size: 0.75rem;"></i> Manage Classes
                    </a>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/auth/logout" style="padding: 0.65rem 0.85rem; border-radius: 0.5rem; color: #dc2626; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem;">
                <i class="fa-solid fa-power-off" style="width: 16px;"></i> Secure Logout
            </a>
        </nav>
    </aside>

    <div class="app-main-area">
        <header class="mockup-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <a href="#" class="brand-logo">EduLinCore IT Portal</a>
            </div>
            <nav class="mockup-nav" style="display: flex; align-items: center; gap: 1rem;">
                <span style="color: #ffffff; font-weight: 600;"><i class="fa-solid fa-school"></i> <?= htmlspecialchars($school_name) ?></span>
            </nav>
        </header>

        <div class="dashboard-main-content" style="padding: 1.5rem 1rem; flex: 1;">
            <div class="app-container" style="max-width: 840px; margin: 0 auto;">
                <div class="data-card" style="background: white; border-radius: 0.85rem; padding: 2.5rem; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);">
                    
                    <div style="text-align: center; margin-bottom: 2rem; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 1.5rem;">
                        <div style="display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; background: #eff6ff; color: #2563eb; border-radius: 50%; font-size: 1.25rem; margin-bottom: 0.75rem;">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin-bottom: 0.35rem; letter-spacing: -0.02em;">New Student Admission Form</h1>
                        <p style="font-size: 0.9rem; color: #64748b; max-width: 480px; margin: 0 auto; line-height: 1.5;">Register learner credentials seamlessly into the school database system.</p>
                    </div>

                    <?php if (!empty($_SESSION['enroll_error'])): ?>
                        <div class="alert-error" style="background: #fef2f2; color: #dc2626; padding: 14px 18px; margin-bottom: 1.5rem; border-radius: 0.6rem; font-size: 0.92rem; border-left: 4px solid #dc2626;">
                            <i class="fa-solid fa-circle-exclamation me-2"></i> <?php echo $_SESSION['enroll_error']; unset($_SESSION['enroll_error']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['enroll_success'])): ?>
                        <div class="alert-success" style="background: #f0fdf4; color: #16a34a; padding: 14px 18px; margin-bottom: 1.5rem; border-radius: 0.6rem; font-size: 0.92rem; border-left: 4px solid #16a34a;">
                            <i class="fa-solid fa-circle-check me-2"></i> <?php echo $_SESSION['enroll_success']; unset($_SESSION['enroll_success']); ?>
                        </div>
                    <?php endif; ?>

                    <form class="enroll-form" action="<?php echo BASE_URL; ?>/it/students/store" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="field-group" style="margin-bottom: 1.5rem;">
                            <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">FULL NAME (SURNAME, OTHER NAMES)</label>
                            <div class="input-group-modern">
                                <i class="fa-solid fa-user input-icon"></i>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($old['full_name'] ?? ''); ?>" placeholder="e.g. MWAPE JOHN." required oninput="this.value = this.value.toUpperCase();" class="form-control-modern">
                            </div>
                        </div>

                        <div class="form-grid-2" style="margin-bottom: 1.5rem;">
                            <div>
                                <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">Gender</label>
                                <div class="input-group-modern">
                                    <i class="fa-solid fa-venus-mars input-icon"></i>
                                    <select name="gender" required class="form-control-modern">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo (($old['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (($old['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">D.O.B</label>
                                <div class="input-group-modern">
                                    <i class="fa-solid fa-calendar input-icon"></i>
                                    <input type="date" name="dob" value="<?php echo htmlspecialchars($old['dob'] ?? ''); ?>" required class="form-control-modern">
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-3" style="margin-bottom: 2rem;">
                            <div>
                                <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">Grade</label>
                                <div class="input-group-modern">
                                    <i class="fa-solid fa-graduation-cap input-icon"></i>
                                    <select name="grade_level" required class="form-control-modern">
                                        <option value="">Select Grade</option>
                                        <?php foreach ($grades as $grade): ?>
                                            <option value="<?php echo htmlspecialchars($grade); ?>" <?php echo (($old['grade_level'] ?? '') === $grade) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($grade); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">Class</label>
                                <div class="input-group-modern">
                                    <i class="fa-solid fa-school input-icon"></i>
                                    <select name="class_name" required class="form-control-modern">
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo htmlspecialchars($class); ?>" <?php echo (($old['class_name'] ?? '') === $class) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label style="font-weight: 700; font-size: 0.88rem; color: #1e293b; margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.04em;">Year</label>
                                <div class="input-group-modern">
                                    <i class="fa-solid fa-calendar-days input-icon"></i>
                                    <input type="text" name="academic_year" value="<?php echo htmlspecialchars($old['academic_year'] ?? date('Y')); ?>" required oninput="this.value = this.value.toUpperCase();" class="form-control-modern">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-light" style="border: 1.5px solid #cbd5e1; color: #475569;">Reset Fields</button>
                            <button type="submit" class="btn btn-primary shadow-sm" style="background:#2563eb; border:none;"><i class="fa-solid fa-user-check me-2"></i> Enroll Student</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <footer class="mockup-footer">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <script>
        function toggleSubMenu(element) {
            const parentGroup = element.closest('.nav-group');
            parentGroup.classList.toggle('active');
        }

        document.addEventListener("DOMContentLoaded", function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const appSidebar = document.getElementById('appSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            function toggleSidebarMenu() {
                appSidebar.classList.toggle('sidebar-open');
                document.body.classList.toggle('menu-open');
            }

            if (sidebarToggle && appSidebar && sidebarOverlay) {
                sidebarToggle.addEventListener('click', toggleSidebarMenu);
                sidebarOverlay.addEventListener('click', toggleSidebarMenu);
            }

            const currentPath = window.location.pathname;
            const links = document.querySelectorAll("aside nav a[data-path]");
            
            links.forEach(link => {
                const path = link.getAttribute("data-path");
                if (currentPath === path || (path !== '/it/dashboard' && currentPath.startsWith(path))) {
                    link.classList.add("active-link");
                    const parentGroup = link.closest(".nav-group");
                    if (parentGroup) {
                        parentGroup.classList.add("active");
                    }
                }
            });
        });

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