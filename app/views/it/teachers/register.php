<?php
/**
 * Path: app/views/it/teachers/register.php
 */
require_once APP_PATH . '/controllers/it/TeacherController.php';

// Invoke controller handler to retrieve shared variables and teacher records
$controllerData  = TeacherController::handleManageRequest();
$error_message   = $controllerData['error_message'] ?? '';
$success_message = $controllerData['success_message'] ?? '';
$csrf_token      = $controllerData['csrf_token'] ?? '';
$teachers        = $controllerData['teachers'] ?? [];

// Determine which tab/view should be active based on query parameter (default to 'register')
$current_view = $_GET['tab'] ?? 'register';

// Calculate summary counts by gender
$total_teachers = count($teachers);
$male_teachers = 0;
$female_teachers = 0;
foreach ($teachers as $t) {
    $gender_val = strtolower(trim($t['gender'] ?? ''));
    if ($gender_val === 'male') {
        $male_teachers++;
    } elseif ($gender_val === 'female') {
        $female_teachers++;
    }
}

// Pagination configuration (for screen view only)
$limit = 10;
$total_pages = max(1, ceil($total_teachers / $limit));
$page = isset($_GET['p']) ? max(1, min((int)$_GET['p'], $total_pages)) : 1;
$offset = ($page - 1) * $limit;
$paginated_teachers = array_slice($teachers, $offset, $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCHOOL IT: Teachers Management - EduLinCore</title>
    
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
    
    <style>
        :root {
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-subtle: #f1f5f9;
            --border-light: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #475569;
            --teacher-name-color: #334155; 
            --primary: #1e40af;
            --primary-subtle: #eff6ff;
            --neutral-gray: #64748b;
            --danger-subtle: #fef2f2;
            --danger-text: #991b1b;
            --success-subtle: #f0fdf4;
            --success-text: #166534;
        }

        * { box-sizing: border-box; }

        body.app-body {
            margin: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: flex;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }

        .app-sidebar {
            width: 260px;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            flex-shrink: 0;
            z-index: 1050;
            transition: transform 0.3s ease;
        }

        .app-sidebar-header {
            padding: 1.25rem;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .app-sidebar-header span span { color: var(--text-main); }

        .app-main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            overflow-y: auto;
            background-color: var(--bg-body);
            min-width: 0;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.5);
            z-index: 1040;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.show { display: block; }

        @media (max-width: 991.98px) {
            .app-sidebar { position: fixed; top: 0; left: -260px; }
            .app-sidebar.mobile-show { transform: translateX(260px); }
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
        .nav-group.active .nav-submenu { display: flex; }
        .nav-group.active .nav-dropdown-toggle .fa-chevron-down { transform: rotate(180deg); }
        
        .nav-submenu a {
            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
            border-radius: 0.5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .nav-submenu a.active-link {
            background: rgba(37,99,235,0.08);
            color: var(--primary) !important;
            font-weight: 700 !important;
        }

        .dashboard-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .dashboard-card-body { padding: 1.5rem; }

        .preview-container {
            background: var(--bg-subtle);
            border: 1px dashed var(--border-light);
            padding: 12px;
            margin-top: 10px;
            border-radius: 4px;
        }
        #full_name_preview {
            font-weight: 700;
            color: var(--teacher-name-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .phone-warning {
            font-size: 0.75rem;
            color: var(--neutral-gray);
            font-weight: 500;
            margin-top: 4px;
            display: block;
        }
        .auto-pass-note {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .alert-custom-success {
            background-color: var(--success-subtle);
            color: var(--success-text);
            border: 1px solid #bbf7d0;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .alert-custom-danger {
            background-color: var(--danger-subtle);
            color: var(--danger-text);
            border: 1px solid #fecaca;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .form-control, .form-select {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-light);
            color: var(--text-main);
            font-size: 0.875rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(30, 64, 175, 0.15);
        }

        .btn-action-save {
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            font-weight: 600;
        }
        .btn-action-save:hover { background-color: #1e3a8a; color: #ffffff; }

        .table-custom th {
            background-color: var(--bg-subtle);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 700;
            border-bottom: 1px solid var(--border-light);
        }
        .table-custom td {
            font-size: 0.85rem;
            vertical-align: middle;
            color: var(--text-main);
            border-bottom: 1px solid var(--border-light);
        }

        /* Custom Pagination Bar Styles */
        .pagination-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 1.5rem;
        }
        .pagination-container a, .pagination-container span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border-light);
            background-color: var(--bg-surface);
            color: var(--text-main);
        }
        .pagination-container a:hover {
            background-color: var(--bg-subtle);
            border-color: var(--primary);
            color: var(--primary);
        }
        .pagination-container .active-page {
            background-color: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .pagination-container .disabled {
            color: var(--neutral-gray);
            pointer-events: none;
            background-color: var(--bg-subtle);
            opacity: 0.6;
        }

        /* Classes to manage view visibility during print vs screen */
        .print-only-table, .print-header, .print-stats-horizontal, .print-footer { display: none; }
        .screen-only-table { display: block; }

        /* Print Styling Rules */
        @media print {
            body.app-body { height: auto; overflow: visible; display: block; }
            .app-sidebar, .mockup-header, .mockup-footer, .no-print, .btn, form, .pagination-container, .screen-only-table { display: none !important; }
            .app-main-area { height: auto; overflow: visible; background: white !important; }
            .dashboard-main-content { padding: 0 !important; }
            .dashboard-card { border: none !important; box-shadow: none !important; margin-bottom: 0 !important; }
            .print-only-table { display: block !important; }
            .print-header { display: block !important; margin-bottom: 15px; text-align: center; }
            .print-stats-horizontal { display: flex !important; justify-content: space-around !important; margin-bottom: 20px; border-bottom: 1px solid #cbd5e1; padding-bottom: 12px; }
            .print-footer { 
                display: block !important; 
                margin-top: 30px; 
                padding-top: 15px; 
                border-top: 1px dashed #94a3b8; 
                text-align: center; 
                font-size: 0.8rem; 
                color: #475569;
            }
        }
    </style>
</head>
<body class="app-body">

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

    <!-- Sidebar Layout -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-header">
            <span>EduLin<span>C</span>ore</span>
            <button class="btn btn-sm text-muted d-lg-none" onclick="toggleMobileSidebar()" style="border: none; background: transparent;">
                <i class="fa-solid fa-xmark fs-5"></i>
            </button>
        </div>
        <div style="padding: 1.25rem; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; gap: 0.75rem; background: var(--bg-body);">
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

            <!-- Teachers Group with Toggleable Sub-menus -->
            <div class="nav-group active" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu" style="display: flex;">
                    <a href="<?= BASE_URL ?>/it/teachers/register?tab=register" class="<?= $current_view === 'register' ? 'active-link' : '' ?>">
                        <i class="fa-solid fa-user-plus" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/register?tab=list" class="<?= $current_view === 'list' ? 'active-link' : '' ?>">
                        <i class="fa-solid fa-list-ul" style="width: 14px; font-size: 0.75rem;"></i> Teacher List
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-chalkboard-user" style="width: 14px; font-size: 0.75rem;"></i> Manage Teachers
                    </a>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/auth/logout" style="padding: 0.65rem 0.85rem; border-radius: 0.5rem; color: #dc2626; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem;">
                <i class="fa-solid fa-power-off" style="width: 16px;"></i> Secure Logout
            </a>
        </nav>
    </aside>

    <div class="app-main-area">
        <header class="mockup-header" style="background: #1e3a8a; border-bottom: 1px solid #1e40af; display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.5rem;">
            <div class="d-flex align-items: center gap-3">
                <button class="btn btn-sm text-white d-lg-none p-0" onclick="toggleMobileSidebar()" style="background: transparent; border: none;">
                    <i class="fa-solid fa-bars fs-5"></i>
                </button>
                <a href="<?= BASE_URL ?>/it/dashboard" class="brand-logo text-white text-decoration-none fw-bold">EduLinCore IT Portal</a>
            </div>
            <span class="text-white fw-semibold small"><i class="fa-solid fa-school me-1"></i> <?= htmlspecialchars($school_name ?? $_SESSION['school_name'] ?? 'School System') ?></span>
        </header>

        <div class="dashboard-main-content" style="padding: 2rem;">
            
            <!-- Notifications Feedback -->
            <?php if (!empty($error_message)): ?>
              <div class="alert-custom-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php elseif (!empty($success_message)): ?>
              <div class="alert-custom-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>

            <!-- Print Header Banner (Only visible when printing) -->
            <div class="print-header">
                <h2 style="font-weight: 800; text-transform: uppercase; margin-bottom: 4px;"><?= htmlspecialchars($school_name ?? $_SESSION['school_name'] ?? 'School System') ?></h2>
                <h4 style="font-weight: 600; color: #475569; margin-bottom: 10px; font-size: 1rem;">Registered teachers on the system</h4>
                <hr style="border-top: 2px solid #0f172a;">
            </div>

            <!-- Horizontal Stats for Print Only -->
            <div class="print-stats-horizontal">
                <div><strong>Total Teachers:</strong> <?= $total_teachers ?></div>
                <div><strong>Male Teachers:</strong> <?= $male_teachers ?></div>
                <div><strong>Female Teachers:</strong> <?= $female_teachers ?></div>
            </div>

            <?php if ($current_view === 'list'): ?>
                <!-- Teacher List View -->
                <div class="dashboard-card mb-4">
                    <div class="dashboard-card-body">
                        <!-- Top Header & Print Button -->
                        <div class="d-flex justify-content-end align-items: center mb-4 no-print">
                            <div>
                                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm fw-bold px-3 d-flex align-items: center gap-1">
                                    <i class="fa-solid fa-print"></i> Print List
                                </button>
                            </div>
                        </div>

                        <!-- Short Summary by Gender (On Screen - Stacked blocks) -->
                        <div class="row g-3 mb-4 no-print">
                            <div class="col-md-4">
                                <div class="p-3 bg-subtle rounded border text-center">
                                    <span class="text-muted d-block small fw-bold text-uppercase">Total Teachers</span>
                                    <h3 class="fw-bold mb-0 text-primary"><?= $total_teachers ?></h3>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-subtle rounded border text-center">
                                    <span class="text-muted d-block small fw-bold text-uppercase">Male Teachers</span>
                                    <h3 class="fw-bold mb-0 text-dark"><?= $male_teachers ?></h3>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-subtle rounded border text-center">
                                    <span class="text-muted d-block small fw-bold text-uppercase">Female Teachers</span>
                                    <h3 class="fw-bold mb-0 text-dark"><?= $female_teachers ?></h3>
                                </div>
                            </div>
                        </div>

                        <!-- Table for Screen (Paginated) -->
                        <div class="table-responsive screen-only-table">
                            <table class="table table-custom mb-0">
                               <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Full Name</th>
                                        <th>Phone Number</th>
                                        <th>Gender</th>
                                        <th>Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($paginated_teachers)): ?>
                                        <?php foreach ($paginated_teachers as $index => $teacher): ?>
                                            <tr>
                                                <td><?= $offset + $index + 1 ?></td>
                                                <td class="fw-bold"><?= htmlspecialchars($teacher['full_name'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($teacher['phone_number'] ?? 'N/A') ?></td>
                                                <td><?= htmlspecialchars($teacher['gender'] ?? 'N/A') ?></td>
                                                <td>
                                                    <span class="badge bg-secondary-subtle text-dark text-uppercase" style="font-size: 0.7rem;">
                                                        <?= htmlspecialchars($teacher['level'] ?? 'N/A') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-5">
                                                <i class="fa-solid fa-folder-open fs-3 d-block mb-2 opacity-50"></i>
                                                <div>No registered teachers found yet.</div>
                                                <a href="<?= BASE_URL ?>/it/teachers/register?tab=register" class="btn btn-sm btn-primary mt-3">Add Teacher Now</a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Table for Print (Full List, ignores pagination) -->
                        <div class="table-responsive print-only-table">
                            <table class="table table-custom mb-0" style="width: 100%; border-collapse: collapse;">
                               <thead>
                                    <tr style="border-bottom: 2px solid #0f172a;">
                                        <th style="padding: 8px; text-align: left;">#</th>
                                        <th style="padding: 8px; text-align: left;">Full Name</th>
                                        <th style="padding: 8px; text-align: left;">Phone Number</th>
                                        <th style="padding: 8px; text-align: left;">Gender</th>
                                        <th style="padding: 8px; text-align: left;">Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($teachers)): ?>
                                        <?php foreach ($teachers as $p_index => $p_teacher): ?>
                                            <tr style="border-bottom: 1px solid #cbd5e1;">
                                                <td style="padding: 8px;"><?= $p_index + 1 ?></td>
                                                <td style="padding: 8px; font-weight: bold;"><?= htmlspecialchars($p_teacher['full_name'] ?? 'N/A') ?></td>
                                                <td style="padding: 8px;"><?= htmlspecialchars($p_teacher['phone_number'] ?? 'N/A') ?></td>
                                                <td style="padding: 8px;"><?= htmlspecialchars($p_teacher['gender'] ?? 'N/A') ?></td>
                                                <td style="padding: 8px; text-transform: uppercase;"><?= htmlspecialchars($p_teacher['level'] ?? 'N/A') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; padding: 20px;">No registered teachers found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls << 1 2 3 >> (Screen Only) -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-container no-print">
                                <!-- Previous Button (<<) -->
                                <a href="<?= BASE_URL ?>/it/teachers/register?tab=list&p=<?= max(1, $page - 1) ?>" class="<?= ($page <= 1) ? 'disabled' : '' ?>">
                                    &laquo;&laquo;
                                </a>

                                <!-- Numeric Page Buttons (1 2 3 ...) -->
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="<?= BASE_URL ?>/it/teachers/register?tab=list&p=<?= $i ?>" class="<?= ($i === $page) ? 'active-page' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>

                                <!-- Next Button (>>) -->
                                <a href="<?= BASE_URL ?>/it/teachers/register?tab=list&p=<?= min($total_pages, $page + 1) ?>" class="<?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                    &raquo;&raquo;
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Security Note Print Footer (Print Only) -->
                        <div class="print-footer">
                            <p style="margin-bottom: 4px;">This list is generated via edulincore.site. All records are kept securely.</p>
                            <p style="margin: 0;">Generated by <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'IT Officer') ?></strong> at <strong><?= date('Y-m-d H:i') ?></strong></p>
                        </div>

                    </div>
                </div>
            <?php else: ?>
                <!-- Registration Form View -->
                <div class="dashboard-card mb-4">
                    <div class="dashboard-card-body">
                        <h4 class="fw-bold mb-4" style="color: var(--text-main); font-size: 1.1rem;">
                            <i class="fa-solid fa-user-plus text-primary me-2"></i> Register New Teacher
                        </h4>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>" />
                            <input type="hidden" name="action" value="add_teacher" />

                            <div class="row g-3">
                              <div class="col-md-6">
                                <label class="form-label fw-bold" style="font-size: 0.85rem;">Surname <span class="text-danger">*</span></label>
                                <input type="text" name="surname" id="add_surname" class="form-control" placeholder="e.g. BANDA" required />
                              </div>

                              <div class="col-md-6">
                                <label class="form-label fw-bold" style="font-size: 0.85rem;">Other Names <span class="text-danger">*</span></label>
                                <input type="text" name="other_names" id="add_others" class="form-control" placeholder="e.g. John Peter" required />
                              </div>

                              <div class="col-12">
                                <div class="preview-container text-center">
                                    <small class="text-muted d-block fw-bold text-uppercase mb-1" style="font-size: 0.7rem;">Generated Full Name Preview:</small>
                                    <span id="full_name_preview">---</span>
                                </div>
                              </div>

                              <div class="col-md-4">
                                <label class="form-label fw-bold" style="font-size: 0.85rem;">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="phone_number" class="form-control" placeholder="e.g. 097..." required />
                                <span class="phone-warning">Must be a valid real phone number.</span>
                              </div>

                              <div class="col-md-4">
                                <label class="form-label fw-bold" style="font-size: 0.85rem;">Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">-- Select --</option>
                                    <option>Male</option>
                                    <option>Female</option>
                                </select>
                              </div>

                              <div class="col-md-4">
                                <label class="form-label fw-bold" style="font-size: 0.85rem;">Level <span class="text-danger">*</span></label>
                                <select name="level" class="form-select" required>
                                    <option value="">-- Select --</option>
                                    <option value="primary">Primary</option>
                                    <option value="secondary">Secondary</option>
                                </select>
                              </div>

                              <div class="col-12 text-center mt-2">
                                <p class="auto-pass-note">Default password automatically set to <strong>123456</strong></p>
                              </div>
                            </div>

                            <button type="submit" class="btn btn-action-save mt-3 w-100 py-2">SAVE TEACHER RECORD</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <footer class="mockup-footer" style="background: var(--bg-surface); border-top: 1px solid var(--border-light); color: var(--neutral-gray); padding: 1rem; text-align: center; font-size: 0.75rem;">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSubMenu(element) { element.closest('.nav-group').classList.toggle('active'); }
        function toggleMobileSidebar() {
            document.getElementById('appSidebar').classList.toggle('mobile-show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        document.addEventListener("DOMContentLoaded", function() {
            const addSurname = document.getElementById('add_surname');
            const addOthers = document.getElementById('add_others');
            const fullNamePreview = document.getElementById('full_name_preview');
            function updatePreview() {
                const s = (addSurname.value || '').trim().toUpperCase();
                const o = (addOthers.value || '').trim().toUpperCase();
                fullNamePreview.textContent = (s || o) ? s + ' ' + o : '---';
            }
            if (addSurname && addOthers) {
                addSurname.addEventListener('input', updatePreview);
                addOthers.addEventListener('input', updatePreview);
            }
        });
    </script>
</body>
</html>