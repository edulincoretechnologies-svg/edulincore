<?php
/**
 * School IT: Manage Teachers View | EduLinCore School Management Framework
 * Role: School IT Officer (school_it)
 * Path: app/views/it/teachers/manage.php
 */
require_once APP_PATH . '/controllers/it/TeacherController.php';

// Invoke controller handler to retrieve data variables
$controllerData  = TeacherController::handleManageRequest();
$teachers        = $controllerData['teachers'] ?? [];
$error_message   = $controllerData['error_message'] ?? '';
$success_message = $controllerData['success_message'] ?? '';
$csrf_token      = $controllerData['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCHOOL IT: Manage Teachers - EduLinCore</title>
    
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

        .level-badge {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            padding: 0.35em 0.65em;
            border-radius: 4px;
        }
        .badge-secondary-level {
            background-color: var(--primary-subtle);
            color: var(--primary);
            border: 1px solid #bfdbfe;
        }
        .badge-primary-level {
            background-color: var(--bg-subtle);
            color: var(--text-muted);
            border: 1px solid var(--border-light);
        }

        .teacher-name-cell { color: var(--teacher-name-color) !important; font-weight: 700; }

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

        .btn-action-save { background-color: var(--primary); color: #ffffff; border: none; font-weight: 600; }
        .btn-action-save:hover { background-color: #1e3a8a; color: #ffffff; }
        .btn-muted-edit { background-color: var(--bg-subtle); color: var(--text-main); border: 1px solid var(--border-light); }
        .btn-muted-edit:hover { background-color: var(--border-light); color: var(--text-main); }
        .btn-muted-delete { background-color: var(--danger-subtle); color: var(--danger-text); border: 1px solid #fecaca; }
        .btn-muted-delete:hover { background-color: #fee2e2; color: #7f1d1d; }
    </style>
</head>
<body class="app-body">

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

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

            <!-- Teachers Group -->
            <div class="nav-group active" style="margin-top: 0.5rem;">
                <div class="nav-dropdown-toggle" onclick="toggleSubMenu(this)" style="padding: 0.55rem 0.85rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0.5rem; background: rgba(0,0,0,0.02);">
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="nav-submenu" style="display: flex;">
                    <a href="<?= BASE_URL ?>/it/teachers/register" data-path="/it/teachers/register" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-user-tie" style="width: 14px; font-size: 0.75rem;"></i> Add New Teacher
                    </a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" data-path="/it/teachers/manage" style="padding: 0.55rem 0.85rem 0.55rem 1.5rem; border-radius: 0.5rem; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem;">
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
            <div class="dashboard-card mb-4">
                <div class="dashboard-card-body">
                    <h4 class="fw-bold mb-4" style="color: var(--text-main); font-size: 1.1rem;">
                        <i class="fa-solid fa-chalkboard-user text-primary me-2"></i> Manage Registered Teachers
                    </h4>

                    <?php if (!empty($error_message)): ?>
                      <div class="alert-custom-danger"><?= $error_message ?></div>
                    <?php elseif (!empty($success_message)): ?>
                      <div class="alert-custom-success"><?= $success_message ?></div>
                    <?php endif; ?>

                    <div class="table-responsive bg-white rounded border" style="border-color: var(--border-light) !important;">
                      <table class="table table-sm table-hover align-middle mb-0" style="border-color: var(--border-light);">
                        <thead style="background-color: var(--bg-subtle); color: var(--text-main);">
                          <tr>
                              <th style="padding: 0.75rem;">Full Name</th>
                              <th style="padding: 0.75rem;">Phone</th>
                              <th style="padding: 0.75rem;">Level</th>
                              <th class="text-end" style="padding: 0.75rem; width: 140px;">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if (empty($teachers)): ?>
                              <tr>
                                  <td colspan="4" class="text-center text-muted py-4" style="font-size: 0.875rem;">No registered teachers found for this school.</td>
                              </tr>
                          <?php else: ?>
                              <?php foreach ($teachers as $t): ?>
                                <tr>
                                  <td class="teacher-name-cell" style="padding: 0.75rem;"><?= htmlspecialchars($t['full_name']) ?></td>
                                  <td style="color: var(--text-muted); padding: 0.75rem;"><?= htmlspecialchars($t['phone_number']) ?></td>
                                  <td style="padding: 0.75rem;">
                                      <span class="badge level-badge <?= ($t['level'] ?? '') == 'secondary' ? 'badge-secondary-level' : 'badge-primary-level' ?>">
                                          <?= htmlspecialchars($t['level']) ?>
                                      </span>
                                  </td>
                                  <td class="text-end" style="padding: 0.75rem;">
                                     <div class="d-flex gap-1 justify-content-end">
                                         <button class="btn btn-sm btn-muted-edit px-2 py-1"
                                                 title="Edit Teacher"
                                                 onclick='openEditModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, "UTF-8") ?>)'>
                                          <i class="fa-solid fa-pen-to-square"></i>
                                         </button>

                                         <form method="POST" action="" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this teacher record?');">
                                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                                          <input type="hidden" name="action" value="delete_teacher">
                                          <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
                                          <button class="btn btn-sm btn-muted-delete px-2 py-1" title="Delete Teacher">
                                              <i class="fa-solid fa-trash-can"></i>
                                          </button>
                                         </form>
                                     </div>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                </div>
            </div>
        </div>
        <footer class="mockup-footer" style="background: var(--bg-surface); border-top: 1px solid var(--border-light); color: var(--neutral-gray); padding: 1rem; text-align: center; font-size: 0.75rem;">
            <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
        </footer>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editModal" tabindex="-1">
      <div class="modal-dialog">
        <form method="POST" action="" class="modal-content" style="border: 1px solid var(--border-light);">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>" />
          <input type="hidden" name="action" value="update_teacher" />
          <input type="hidden" name="teacher_id" id="edit_teacher_id" />

          <div class="modal-header" style="background: var(--bg-subtle); border-bottom: 1px solid var(--border-light);">
            <h5 class="modal-title fw-bold" style="font-size: 1rem; color: var(--text-main);">Edit Teacher Record</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body" style="background: var(--bg-surface);">
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-bold" style="font-size: 0.85rem;">Surname</label>
                    <input type="text" name="surname" id="edit_surname" class="form-control" required />
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold" style="font-size: 0.85rem;">Other Names</label>
                    <input type="text" name="other_names" id="edit_others" class="form-control" required />
                </div>
            </div>

            <div class="mb-3 mt-3">
                <label class="form-label fw-bold" style="font-size: 0.85rem;">Phone Number</label>
                <input type="tel" name="phone_number" id="edit_phone" class="form-control" required />
            </div>

            <div class="row">
                <div class="col-6">
                    <label class="form-label fw-bold" style="font-size: 0.85rem;">Gender</label>
                    <select name="gender" id="edit_gender" class="form-select">
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-bold" style="font-size: 0.85rem;">Level</label>
                    <select name="level" id="edit_level" class="form-select">
                        <option value="primary">Primary</option>
                        <option value="secondary">Secondary</option>
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label fw-bold" style="font-size: 0.85rem;">Reset Password (optional)</label>
                <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current" />
            </div>
          </div>

          <div class="modal-footer" style="background: var(--bg-subtle); border-top: 1px solid var(--border-light);">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-sm btn-action-save px-4">UPDATE RECORD</button>
          </div>
        </form>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSubMenu(element) { element.closest('.nav-group').classList.toggle('active'); }
        function toggleMobileSidebar() {
            document.getElementById('appSidebar').classList.toggle('mobile-show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        function openEditModal(teacher) {
            document.getElementById('edit_teacher_id').value = teacher.id;
            const nameParts = teacher.full_name.trim().split(' ');
            document.getElementById('edit_surname').value = nameParts.shift() || '';
            document.getElementById('edit_others').value = nameParts.join(' ');
            document.getElementById('edit_phone').value = teacher.phone_number;
            document.getElementById('edit_gender').value = teacher.gender;
            document.getElementById('edit_level').value = teacher.level;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    </script>
</body>
</html>