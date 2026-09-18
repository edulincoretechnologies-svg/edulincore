<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Grade Levels - EduLinCore IT Portal</title>
    
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        /* ================================================================= */
        /* EduLinCore IT Portal Embedded Self-Contained Stylesheet           */
        /* ================================================================= */

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

        /* --- Sidebar --- */
        .app-sidebar {
            width: 260px;
            background-color: var(--bg-card);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            height: 100%;
            flex-shrink: 0;
            transition: left 0.3s ease-in-out;
        }

        .app-sidebar-header {
            padding: 1.25rem 1rem;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary);
            border-bottom: 1px solid var(--border-light);
        }

        .app-sidebar-header span span {
            color: var(--text-main);
        }

        /* --- Main Content Area --- */
        .app-main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow-y: auto;
            min-width: 0;
        }

        .mockup-header {
            background-color: var(--primary);
            color: #ffffff;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .brand-logo {
            font-weight: 700;
            font-size: 1rem;
            color: #ffffff;
            text-decoration: none;
            white-space: nowrap;
        }

        .dashboard-main-content {
            padding: 1.5rem;
            flex: 1;
        }

        /* --- Dashboard Cards & Tables --- */
        .dashboard-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .dashboard-card-header {
            padding: 1rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            border-bottom: 1px solid var(--border-light);
            background-color: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-card-body {
            padding: 1.25rem;
        }

        /* --- Buttons & Forms --- */
        .btn-primary-custom {
            background-color: var(--primary);
            color: #ffffff;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: none;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        .btn-primary-custom:hover {
            background-color: var(--primary-dark);
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid var(--border-light);
            background: #ffffff;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
        }
        .btn-outline-primary { color: var(--primary); border-color: var(--primary); }
        .btn-outline-primary:hover { background: rgba(37,99,235,0.05); }
        .btn-outline-danger { color: var(--danger); border-color: var(--danger); }
        .btn-outline-danger:hover { background: rgba(220,38,38,0.05); }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.875rem;
        }

        .data-table th, .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-light);
        }

        .data-table th {
            background-color: #f8fafc;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        .data-table tbody tr:hover {
            background-color: rgba(0,0,0,0.01);
        }

        /* --- Alerts & Helper Hints --- */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: opacity 0.5s ease, transform 0.5s ease;
            opacity: 1;
        }
        .alert.fade-out {
            opacity: 0;
            transform: translateY(-8px);
        }
        .alert-success { background-color: rgba(22, 163, 74, 0.1); color: var(--success); border: 1px solid rgba(22, 163, 74, 0.2); }
        .alert-danger { background-color: rgba(220, 38, 38, 0.1); color: var(--danger); border: 1px solid rgba(220, 38, 38, 0.2); }
        .alert-close { background: none; border: none; cursor: pointer; font-size: 1rem; color: inherit; }

        .format-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
            line-height: 1.4;
        }

        /* --- Modals --- */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }
        .modal-backdrop.active {
            display: flex;
        }
        .modal-dialog {
            background: var(--bg-card);
            width: 100%;
            max-width: 450px;
            border-radius: 0.75rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-in-out;
            margin: 1rem;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
        }
        .modal-body {
            padding: 1.25rem;
        }
        .modal-footer {
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--border-light);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.35rem;
        }
        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--border-light);
            border-radius: 0.375rem;
            font-family: inherit;
            font-size: 0.85rem;
            color: var(--text-main);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        /* --- Footer --- */
        .mockup-footer {
            padding: 1rem;
            background-color: var(--bg-card);
            border-top: 1px solid var(--border-light);
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
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
            background: rgba(37,99,235,0.08);
            color: var(--primary) !important;
            font-weight: 700 !important;
        }

        /* --- Mobile Hamburger & Drawer Styles --- */
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

        @media (max-width: 768px) {
            .mobile-nav-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .app-sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                height: 100dvh;
                z-index: 1000;
                box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            }

            .app-sidebar.sidebar-open {
                left: 0;
            }

            body.menu-open .sidebar-overlay {
                display: block;
            }

            .dashboard-main-content {
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-header">
            <span>EduLin<span>C</span>ore</span>
        </div>
        <div style="padding: 1rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; background: var(--bg-body);">
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

            <div class="nav-group" style="margin-top: 0.5rem;">
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
                    <a href="<?= BASE_URL ?>/it/teachers/assignments" data-path="/it/teachers/assignments" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book-bookmark" style="width: 14px; font-size: 0.75rem;"></i> Teacher Assignments
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
                    <a href="<?= BASE_URL ?>/it/setup/subjects" data-path="/it/setup/subjects" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-book" style="width: 14px; font-size: 0.75rem;"></i> Manage Subjects
                    </a>
                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Exams & Reports</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/reports/cards" data-path="/it/reports/cards" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-file-invoice" style="width: 14px; font-size: 0.75rem;"></i> Generate Report Cards
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/scoresheets" data-path="/it/reports/scoresheets" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-table-cells" style="width: 14px; font-size: 0.75rem;"></i> Generate Score Sheets
                    </a>
                    <a href="<?= BASE_URL ?>/it/reports/analysis" data-path="/it/reports/analysis" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chart-pie" style="width: 14px; font-size: 0.75rem;"></i> Generate Class Analysis
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
                <span style="color: #ffffff; font-weight: 600; font-size: 0.85rem;"><i class="fa-solid fa-school"></i> <?= htmlspecialchars($schoolName ?? $_SESSION['school_name'] ?? 'School System') ?></span>
            </nav>
        </header>

        <div class="dashboard-main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1 style="font-size: 1.5rem; margin-bottom: 0.25rem;">Grade Level Setup</h1>
                    <p style="font-size: 0.85rem; color: var(--text-muted);">Manage grade levels for <strong><?= htmlspecialchars($schoolName ?? 'Unknown School'); ?></strong></p>
                </div>
                <button type="button" class="btn-primary-custom" onclick="openModal('addGradeModal')">
                    <i class="fa-solid fa-plus"></i> Add Grade Level
                </button>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success auto-dismiss">
                    <span><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger auto-dismiss">
                    <span><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
                </div>
            <?php endif; ?>

            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <span>Registered Grade Levels</span>
                </div>
                <div class="dashboard-card-body" style="padding: 0;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Grade Name</th>
                                    <th style="width: 25%; text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($grades)): ?>
                                    <?php foreach ($grades as $grade): ?>
                                        <tr>
                                            <td style="font-weight: 600;"><?= htmlspecialchars($grade['grade_name']); ?></td>
                                            <td style="text-align: center;">
                                                <button type="button" class="btn-sm btn-outline-primary" onclick="editGrade(<?= $grade['id']; ?>)">
                                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                                </button>
                                                <form action="<?= BASE_URL; ?>/it/setup/grades/delete" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this grade level?');">
                                                    <input type="hidden" name="id" value="<?= $grade['id']; ?>">
                                                    <button type="submit" class="btn-sm btn-outline-danger">
                                                        <i class="fa-solid fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" style="text-align: center; color: var(--text-muted); padding: 2rem;">No grade levels found for this school. Click "Add Grade Level" to get started.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <footer class="mockup-footer">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <!-- Add Grade Modal -->
    <div class="modal-backdrop" id="addGradeModal">
        <div class="modal-dialog">
            <form action="<?= BASE_URL; ?>/it/setup/grades/store" method="POST" onsubmit="return validateGradeForm('grade_name')">
                <div class="modal-header">
                    <span>Add New Grade Level</span>
                    <button type="button" class="alert-close" onclick="closeModal('addGradeModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="grade_name" class="form-label">Grade Name <span style="color: var(--danger);">*</span></label>
                        <input type="text" class="form-control" id="grade_name" name="grade_name" placeholder="e.g., GRADE 7, FORM 1, ECE, G7" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/  +/g, ' ');" required>
                        <div class="format-hint">
                            <i class="fa-solid fa-circle-info"></i> <strong>Accepted Formats:</strong> <code>ECE</code>, <code>GRADE 1-7</code>, <code>FORM 1-5</code>, <code>GRADE 10-12</code>, or shorthand (e.g. <code>G7</code>, <code>F1</code>).
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-sm" onclick="closeModal('addGradeModal')">Cancel</button>
                    <button type="submit" class="btn-primary-custom" style="padding: 0.4rem 0.8rem; font-size: 0.75rem;">Save Grade</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Grade Modal -->
    <div class="modal-backdrop" id="editGradeModal">
        <div class="modal-dialog">
            <form action="<?= BASE_URL; ?>/it/setup/grades/update" method="POST" onsubmit="return validateGradeForm('edit_grade_name')">
                <div class="modal-header">
                    <span>Edit Grade Level</span>
                    <button type="button" class="alert-close" onclick="closeModal('editGradeModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit_grade_id" name="id">
                    <div class="form-group">
                        <label for="edit_grade_name" class="form-label">Grade Name <span style="color: var(--danger);">*</span></label>
                        <input type="text" class="form-control" id="edit_grade_name" name="grade_name" style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/  +/g, ' ');" required>
                        <div class="format-hint">
                            <i class="fa-solid fa-circle-info"></i> <strong>Accepted Formats:</strong> <code>ECE</code>, <code>GRADE 7</code>, <code>FORM 4</code>, <code>G7</code>, <code>F1</code>, etc.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-sm" onclick="closeModal('editGradeModal')">Cancel</button>
                    <button type="submit" class="btn-primary-custom" style="padding: 0.4rem 0.8rem; font-size: 0.75rem;">Update Grade</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Zambian Curriculum Context-Aware Validation Function
        function validateGradeForm(inputId) {
            const inputField = document.getElementById(inputId);
            let val = inputField.value.trim().toUpperCase();

            // 1. Handle explicit shorthand initials like G7 -> GRADE 7, F1 -> FORM 1
            if (/^G\s*([0-9]+)$/.test(val)) {
                const num = parseInt(val.match(/^G\s*([0-9]+)$/)[1], 10);
                if ((num >= 1 && num <= 7) || (num >= 10 && num <= 12)) {
                    inputField.value = `GRADE ${num}`;
                    return true;
                }
            }
            if (/^F\s*([0-9]+)$/.test(val)) {
                const num = parseInt(val.match(/^F\s*([0-9]+)$/)[1], 10);
                if (num >= 1 && num <= 5) {
                    inputField.value = `FORM ${num}`;
                    return true;
                }
            }

            // Allow ECE directly
            if (val === 'ECE') {
                inputField.value = 'ECE';
                return true;
            }

            // 2. If user typed *only* a number (e.g., "1", "4", "11")
            if (/^[0-9]+$/.test(val)) {
                const numInt = parseInt(val, 10);

                // Numbers 10 to 12 are uniquely Senior Secondary Grades
                if (numInt >= 10 && numInt <= 12) {
                    if (confirm(`Did you mean "GRADE ${numInt}"?`)) {
                        inputField.value = `GRADE ${numInt}`;
                        return true;
                    }
                }
                // Numbers 1 to 5 can overlap (Grade 1-5 or Form 1-5)
                else if (numInt >= 1 && numInt <= 5) {
                    if (confirm(`Did you mean "GRADE ${numInt}"?`)) {
                        inputField.value = `GRADE ${numInt}`;
                        return true;
                    }
                    if (confirm(`Did you mean "FORM ${numInt}"?`)) {
                        inputField.value = `FORM ${numInt}`;
                        return true;
                    }
                }
                // Numbers 6 and 7 are uniquely Primary Grades
                else if (numInt === 6 || numInt === 7) {
                    if (confirm(`Did you mean "GRADE ${numInt}"?`)) {
                        inputField.value = `GRADE ${numInt}`;
                        return true;
                    }
                }

                // If they decline or enter invalid range, block submission and guide user
                alert(
                    `To avoid confusion in the school system, numbers alone cannot be saved.\n\n` +
                    `Accepted formatting options based on the Zambian structure:\n` +
                    `• Early Childhood: ECE\n` +
                    `• Primary/Upper: GRADE 1 to 7  (or G1 - G7)\n` +
                    `• Secondary: FORM 1 to 5      (or F1 - F5)\n` +
                    `• Senior: GRADE 10 to 12      (or G10 - G12)\n\n` +
                    `Please update your entry using one of the accepted ways.`
                );
                inputField.focus();
                return false;
            }

            return true;
        }

        function toggleSubMenu(element) {
            const parentGroup = element.closest('.nav-group');
            parentGroup.classList.toggle('active');
        }

        // Modal Helpers
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // AJAX Edit Fetcher matching Controller edit() endpoint
        function editGrade(gradeId) {
            fetch(`<?= BASE_URL; ?>/it/setup/grades/edit?id=${gradeId}`)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        document.getElementById('edit_grade_id').value = result.data.id;
                        document.getElementById('edit_grade_name').value = (result.data.grade_name || '').toUpperCase().replace(/  +/g, ' ');
                        openModal('editGradeModal');
                    } else {
                        alert(result.message || 'Failed to fetch grade details.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching grade details.');
                });
        }

        // Auto-dismiss alert toasts after 4 seconds
        document.addEventListener("DOMContentLoaded", function() {
            const alerts = document.querySelectorAll('.auto-dismiss');
            alerts.forEach(alertBox => {
                setTimeout(() => {
                    alertBox.classList.add('fade-out');
                    setTimeout(() => {
                        alertBox.style.display = 'none';
                    }, 500);
                }, 4000);
            });

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

        // Automated Idle Session Timeout (Auto-logout after 15 minutes of inactivity)
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