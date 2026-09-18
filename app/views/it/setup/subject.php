<?php

declare(strict_types=1);

$appPath = dirname(__DIR__, 3);

if (!defined('APP_PATH')) {
    define('APP_PATH', $appPath);
}

if (!defined('BASE_URL')) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', rtrim($scheme . '://' . $host, '/'));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_logged_in']) || ($_SESSION['user_role'] ?? '') !== 'school_it') {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$databaseFile = APP_PATH . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Database.php';
if (!class_exists('Database') && is_file($databaseFile)) {
    require_once $databaseFile;
}

$schoolId = (int)($_SESSION['school_id'] ?? 0);
$schoolName = (string)($_SESSION['school_name'] ?? 'Institution Setup');
$userName = (string)($_SESSION['user_name'] ?? 'School IT Officer');
$subjects = [];
$successMessage = '';
$errorMessage = '';
$database = null;

if ($schoolId <= 0) {
    $errorMessage = 'Your school session is incomplete. Please sign in again.';
} elseif (!class_exists('Database')) {
    $errorMessage = 'The database service is unavailable.';
} else {
    try {
        $database = Database::getConnection();
    } catch (Throwable $exception) {
        error_log('Subject setup database connection failed: ' . $exception->getMessage());
        $errorMessage = 'The database is currently unavailable. Please try again later.';
    }
}

$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
}

$editSubject = null;
$editId = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $database instanceof PDO) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'Your session has expired. Refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $subjectName = trim((string)($_POST['subject_name'] ?? ''));
        $shortName = trim((string)($_POST['short_name'] ?? ''));

        try {
            if ($action === 'save_subject') {
                if ($subjectName === '') {
                    throw new InvalidArgumentException('Subject name is required.');
                }

                $duplicate = $database->prepare(
                    'SELECT id FROM subjects WHERE school_id = ? AND LOWER(subject_name) = LOWER(?) AND id <> ? LIMIT 1'
                );
                $duplicate->execute([$schoolId, $subjectName, $subjectId]);

                if ($duplicate->fetch()) {
                    throw new RuntimeException('This subject already exists.');
                }

                if ($subjectId > 0) {
                    $statement = $database->prepare(
                        'UPDATE subjects SET subject_name = ?, short_name = ? WHERE id = ? AND school_id = ?'
                    );
                    $statement->execute([
                        $subjectName,
                        $shortName !== '' ? $shortName : null,
                        $subjectId,
                        $schoolId,
                    ]);
                    $successMessage = 'Subject updated successfully.';
                } else {
                    $statement = $database->prepare(
                        'INSERT INTO subjects (school_id, subject_name, short_name, created_at) VALUES (?, ?, ?, NOW())'
                    );
                    $statement->execute([
                        $schoolId,
                        $subjectName,
                        $shortName !== '' ? $shortName : null,
                    ]);
                    $successMessage = 'Subject added successfully.';
                }
            } elseif ($action === 'delete_subject') {
                if ($subjectId <= 0) {
                    throw new InvalidArgumentException('Invalid subject selected.');
                }

                $statement = $database->prepare('DELETE FROM subjects WHERE id = ? AND school_id = ?');
                $statement->execute([$subjectId, $schoolId]);

                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('The selected subject could not be removed.');
                }

                $successMessage = 'Subject removed successfully.';
                $editId = 0;
            } else {
                throw new InvalidArgumentException('Unsupported subject action.');
            }
        } catch (Throwable $exception) {
            error_log('Subject setup action failed: ' . $exception->getMessage());
            $errorMessage = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The requested subject action could not be completed.';
        }
    }
}

if ($database instanceof PDO) {
    try {
        $statement = $database->prepare(
            'SELECT id, subject_name, short_name FROM subjects WHERE school_id = ? ORDER BY subject_name ASC'
        );
        $statement->execute([$schoolId]);
        $subjects = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('Subject setup list failed: ' . $exception->getMessage());
        $errorMessage = $errorMessage !== '' ? $errorMessage : 'Subjects could not be loaded.';
    }
}

if ($editId > 0) {
    foreach ($subjects as $subject) {
        if ((int)$subject['id'] === $editId) {
            $editSubject = $subject;
            break;
        }
    }
}

$subjectFormId = (int)($editSubject['id'] ?? 0);
$subjectFormName = (string)($editSubject['subject_name'] ?? '');
$subjectFormShortName = (string)($editSubject['short_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects | EduLinCore</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root { --blue: #2563eb; --blue-dark: #1d4ed8; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --page: #f8fafc; }
        * { box-sizing: border-box; }
        body { display: flex; height: 100vh; height: 100dvh; margin: 0; overflow: hidden; background: var(--page); color: var(--ink); font-family: 'Plus Jakarta Sans', sans-serif; }
        .app-sidebar { width: 260px; flex: 0 0 260px; height: 100%; display: flex; flex-direction: column; background: #fff; border-right: 1px solid var(--line); transition: left .3s ease; z-index: 1000; }
        .app-sidebar-header { padding: 1.25rem 1rem; color: var(--blue); border-bottom: 1px solid var(--line); font-size: 1.15rem; font-weight: 800; }
        .app-sidebar-header span span { color: var(--ink); }
        .sidebar-profile { display: flex; align-items: center; gap: .75rem; padding: 1rem; background: var(--page); border-bottom: 1px solid var(--line); }
        .sidebar-profile img { width: 38px; height: 38px; border-radius: 50%; }
        .sidebar-profile-name { overflow: hidden; color: var(--ink); font-size: .85rem; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
        .sidebar-profile-role { color: var(--muted); font-size: .7rem; font-weight: 700; text-transform: uppercase; }
        .sidebar-nav { display: flex; flex: 1; flex-direction: column; gap: .35rem; padding: 1rem; overflow-y: auto; }
        .nav-main-link, .nav-submenu a, .logout-link { display: flex; align-items: center; gap: .75rem; border-radius: .5rem; color: var(--muted); text-decoration: none; font-weight: 600; }
        .nav-main-link, .logout-link { padding: .65rem .85rem; font-size: .85rem; }
        .nav-submenu a { padding: .55rem .85rem .55rem 1.5rem; gap: .5rem; font-size: .8rem; }
        .nav-main-link:hover, .nav-submenu a:hover { background: rgba(37, 99, 235, .06); color: var(--blue); }
        .nav-group { margin-top: .5rem; }
        .nav-dropdown-toggle { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: .55rem .85rem; border: 0; border-radius: .5rem; color: var(--muted); background: rgba(0,0,0,.02); font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-align: left; text-transform: uppercase; cursor: pointer; }
        .nav-dropdown-toggle i { transition: transform .3s ease; font-size: .7rem; }
        .nav-submenu { display: none; flex-direction: column; gap: .25rem; margin-top: .25rem; padding-left: .5rem; }
        .nav-group.active .nav-submenu { display: flex; }
        .nav-group.active .nav-dropdown-toggle i { transform: rotate(180deg); }
        .nav-submenu a.active-link { color: var(--blue); background: rgba(37, 99, 235, .08); font-weight: 700; }
        .logout-link { margin-top: 1rem; color: #dc2626; }
        .logout-link:hover { background: rgba(220, 38, 38, .06); color: #b91c1c; }
        .app-main-area { display: flex; flex: 1; flex-direction: column; min-width: 0; height: 100%; overflow-y: auto; }
        .mockup-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem; color: #fff; background: var(--blue); }
        .brand-logo { color: #fff; font-size: 1rem; font-weight: 700; text-decoration: none; }
        .mobile-nav-toggle { display: none; padding: .35rem; border: 0; border-radius: .375rem; color: #fff; background: transparent; font-size: 1.25rem; cursor: pointer; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 999; }
        .dashboard-main-content { width: 100%; padding: 1.5rem; }
        .shell { width: min(1180px, 100%); margin: 0 auto 48px; }
        .sidebar-school { color: #fff; font-size: .85rem; font-weight: 600; }
        .panel { height: 100%; padding: 22px; border: 1px solid var(--line); border-radius: 16px; background: #fff; box-shadow: 0 10px 26px rgba(15, 23, 42, .04); }
        .panel-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .panel-heading h2 { margin: 0; font-size: 1.05rem; font-weight: 800; }
        .icon-box { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; color: var(--blue); background: rgba(37, 99, 235, .09); }
        .icon-box.green { color: #15803d; background: rgba(22, 163, 74, .09); }
        .form-control { min-height: 46px; border-color: var(--line); border-radius: 11px; background: #f8fafc; }
        .form-control:focus { border-color: rgba(37, 99, 235, .55); box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .12); background: #fff; }
        .btn { min-height: 44px; border-radius: 11px; font-weight: 700; }
        .table > :not(caption) > * > * { padding: .85rem .65rem; }
        .subject-row:hover { background: #f8fafc; }
        .code { display: inline-block; padding: 5px 9px; border: 1px solid var(--line); border-radius: 999px; color: #475569; background: #f8fafc; font-size: .72rem; font-weight: 700; }
        @media (max-width: 900px) {
            .app-sidebar { position: fixed; top: 0; bottom: 0; left: -270px; box-shadow: 8px 0 24px rgba(15, 23, 42, .12); }
            .app-sidebar.sidebar-open { left: 0; }
            body.menu-open .sidebar-overlay { display: block; }
            .mobile-nav-toggle { display: inline-block; }
            .dashboard-main-content { padding: 1rem; }
        }
        @media (max-width: 700px) { .panel { padding: 16px; } .sidebar-school { display: none; } }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="app-sidebar" id="appSidebar">
        <div class="app-sidebar-header"><span>EduLin<span>C</span>ore</span></div>
        <div class="sidebar-profile">
            <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Profile">
            <div>
                <div class="sidebar-profile-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sidebar-profile-role">School IT Officer</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a class="nav-main-link" href="<?= BASE_URL ?>/it/dashboard" data-path="/it/dashboard"><i class="fa-solid fa-house" style="width:16px"></i>Dashboard</a>

            <div class="nav-group">
                <button class="nav-dropdown-toggle" type="button"><span>Students</span><i class="fa-solid fa-chevron-down"></i></button>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/students/enroll" data-path="/it/students/enroll"><i class="fa-solid fa-user-plus" style="width:14px"></i>Enroll New Student</a>
                    <a href="<?= BASE_URL ?>/it/students/management" data-path="/it/students/management"><i class="fa-solid fa-users-gear" style="width:14px"></i>Manage Students</a>
                </div>
            </div>

            <div class="nav-group">
                <button class="nav-dropdown-toggle" type="button"><span>Teachers</span><i class="fa-solid fa-chevron-down"></i></button>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/teachers/register" data-path="/it/teachers/register"><i class="fa-solid fa-user-tie" style="width:14px"></i>Add New Teacher</a>
                    <a href="<?= BASE_URL ?>/it/teachers/manage" data-path="/it/teachers/manage"><i class="fa-solid fa-chalkboard-user" style="width:14px"></i>Manage Teachers</a>
                    <a href="<?= BASE_URL ?>/it/teachers/assignments" data-path="/it/teachers/assignments"><i class="fa-solid fa-book-bookmark" style="width:14px"></i>Teacher Assignments</a>
                </div>
            </div>

            <div class="nav-group active">
                <button class="nav-dropdown-toggle" type="button"><span>School Setup</span><i class="fa-solid fa-chevron-down"></i></button>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/setup/grades" data-path="/it/setup/grades"><i class="fa-solid fa-layer-group" style="width:14px"></i>Manage Grade Levels</a>
                    <a href="<?= BASE_URL ?>/it/setup/classes" data-path="/it/setup/classes"><i class="fa-solid fa-school-flag" style="width:14px"></i>Manage Classes</a>
                    <a href="<?= BASE_URL ?>/it/setup/subjects" data-path="/it/setup/subjects" class="active-link"><i class="fa-solid fa-book" style="width:14px"></i>Manage Subjects</a>
                </div>
            </div>

            <div class="nav-group">
                <button class="nav-dropdown-toggle" type="button"><span>Exams &amp; Reports</span><i class="fa-solid fa-chevron-down"></i></button>
                <div class="nav-submenu">
                    <a href="<?= BASE_URL ?>/it/reports/cards" data-path="/it/reports/cards"><i class="fa-solid fa-file-invoice" style="width:14px"></i>Generate Report Cards</a>
                    <a href="<?= BASE_URL ?>/it/reports/scoresheets" data-path="/it/reports/scoresheets"><i class="fa-solid fa-table-cells" style="width:14px"></i>Generate Score Sheets</a>
                    <a href="<?= BASE_URL ?>/it/reports/analysis" data-path="/it/reports/analysis"><i class="fa-solid fa-chart-pie" style="width:14px"></i>Generate Class Analysis</a>
                </div>
            </div>

            <a class="logout-link" href="<?= BASE_URL ?>/auth/logout"><i class="fa-solid fa-power-off" style="width:16px"></i>Secure Logout</a>
        </nav>
    </aside>

    <div class="app-main-area">
        <header class="mockup-header">
            <div style="display:flex;align-items:center;gap:.75rem">
                <button class="mobile-nav-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
                <a class="brand-logo" href="<?= BASE_URL ?>/it/dashboard">EduLinCore IT Portal</a>
            </div>
            <span class="sidebar-school"><i class="fa-solid fa-school"></i> <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></span>
        </header>

        <div class="dashboard-main-content">
        <main class="shell">
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success border-0 shadow-sm" role="alert"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger border-0 shadow-sm" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <section class="col-lg-4">
                <div class="panel">
                    <div class="panel-heading">
                        <div class="icon-box"><i class="bi <?= $editSubject ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i></div>
                        <h2><?= $editSubject ? 'Edit Subject' : 'Add Subject' ?></h2>
                    </div>
                    <form method="post" action="<?= htmlspecialchars(BASE_URL . '/it/setup/subjects', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="save_subject">
                        <input type="hidden" name="subject_id" value="<?= $subjectFormId ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="subject_name">Subject name</label>
                            <input class="form-control" id="subject_name" name="subject_name" value="<?= htmlspecialchars($subjectFormName, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Mathematics" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="short_name">Short name</label>
                            <input class="form-control" id="short_name" name="short_name" value="<?= htmlspecialchars($subjectFormShortName, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. MATH" maxlength="30">
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary" type="submit"><i class="bi <?= $editSubject ? 'bi-check2-circle' : 'bi-plus-circle' ?> me-2"></i><?= $editSubject ? 'Update subject' : 'Save subject' ?></button>
                            <?php if ($editSubject): ?>
                                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(BASE_URL . '/it/setup/subjects', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-x-circle me-2"></i>Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </section>

            <section class="col-lg-8">
                <div class="panel">
                    <div class="panel-heading">
                        <div class="icon-box green"><i class="bi bi-journal-text"></i></div>
                        <h2>Configured subjects <span class="text-secondary fw-normal">(<?= count($subjects) ?>)</span></h2>
                    </div>
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                        <input class="form-control border-start-0" id="subjectSearch" placeholder="Search subjects" autocomplete="off">
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>#</th><th>Subject</th><th>Code</th><th class="text-end">Actions</th></tr></thead>
                            <tbody id="subjectRows">
                                <?php foreach ($subjects as $index => $subject): ?>
                                    <tr class="subject-row">
                                        <td class="text-secondary"><?= $index + 1 ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string)$subject['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="code"><?= htmlspecialchars((string)($subject['short_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-light border text-primary" href="<?= htmlspecialchars(BASE_URL . '/it/setup/subjects?edit=' . (int)$subject['id'], ENT_QUOTES, 'UTF-8') ?>" title="Edit subject"><i class="bi bi-pencil-fill"></i></a>
                                            <form class="d-inline delete-form" method="post" action="<?= htmlspecialchars(BASE_URL . '/it/setup/subjects', ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete_subject">
                                                <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                                                <button class="btn btn-sm btn-light border text-danger" type="submit" title="Delete subject"><i class="bi bi-trash-fill"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($subjects === []): ?>
                                    <tr id="emptySubjects"><td colspan="4" class="py-5 text-center text-secondary"><i class="bi bi-journal-x d-block fs-2 mb-2"></i>No subjects configured yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
        </main>
        </div>
    </div>
    <script>
        const search = document.getElementById('subjectSearch');
        if (search) {
            search.addEventListener('input', () => {
                const query = search.value.toLowerCase().trim();
                document.querySelectorAll('.subject-row').forEach((row) => {
                    row.hidden = !row.textContent.toLowerCase().includes(query);
                });
            });
        }

        document.querySelectorAll('.delete-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (!window.confirm('Delete this subject?')) {
                    event.preventDefault();
                }
            });
        });

        const sidebar = document.getElementById('appSidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('sidebar-open');
            document.body.classList.toggle('menu-open');
        }

        if (sidebarToggle && sidebarOverlay) {
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarOverlay.addEventListener('click', toggleSidebar);
        }

        document.querySelectorAll('.nav-dropdown-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                toggle.closest('.nav-group').classList.toggle('active');
            });
        });
    </script>
</body>
</html>
