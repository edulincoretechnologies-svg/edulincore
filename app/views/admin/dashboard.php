<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . rtrim($baseUrl, '/') . '/auth/admin_login');
    exit;
}

$db = Database::getConnection();

$adminName = trim((string)($_SESSION['user_name'] ?? 'System Administrator'));
if ($adminName === '') {
    $adminName = 'System Administrator';
}

$schoolName = 'System Administration';

function adminCount(PDO $db, string $sql): int
{
    try {
        $stmt = $db->query($sql);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function adminAmount(PDO $db, string $sql): float
{
    try {
        $stmt = $db->query($sql);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

$schools = adminCount(
    $db,
    "SELECT COUNT(*) FROM schools WHERE COALESCE(is_deleted, 0) = 0"
);

$deletedSchools = adminCount(
    $db,
    "SELECT COUNT(*) FROM schools WHERE COALESCE(is_deleted, 0) = 1"
);

$setupPending = adminCount(
    $db,
    "SELECT COUNT(*) FROM schools
     WHERE COALESCE(is_deleted, 0) = 0
     AND COALESCE(grade_setup_done, 0) = 0"
);

$amountDue = adminAmount(
    $db,
    "SELECT COALESCE(SUM(amount_due), 0) FROM school_payments"
);

$amountPaid = adminAmount(
    $db,
    "SELECT COALESCE(SUM(amount_paid), 0) FROM school_payments"
);

$pendingPayments = adminCount(
    $db,
    "SELECT COUNT(*)
     FROM school_payment_submissions
     WHERE COALESCE(verification_status, 'pending') = 'pending'"
);

$outstandingBalance = max(0.0, $amountDue - $amountPaid);

$teachers = adminCount(
    $db,
    "SELECT COUNT(*) FROM teachers"
);

$students = adminCount(
    $db,
    "SELECT COUNT(*) FROM students"
);

$headteachers = adminCount(
    $db,
    "SELECT COUNT(*) FROM headteachers"
);

$schoolIt = adminCount(
    $db,
    "SELECT COUNT(*) FROM school_it"
);

$admins = adminCount(
    $db,
    "SELECT COUNT(*) FROM admins"
);

$headteacherResets = adminCount(
    $db,
    "SELECT COUNT(*)
     FROM headteachers
     WHERE COALESCE(requires_reset, 0) = 1
        OR COALESCE(must_change_password, 0) = 1"
);

$schoolItResets = adminCount(
    $db,
    "SELECT COUNT(*)
     FROM school_it
     WHERE COALESCE(requires_reset, 0) = 1
        OR COALESCE(force_password_change, 0) = 1"
);

$teacherResets = adminCount(
    $db,
    "SELECT COUNT(*)
     FROM teachers
     WHERE COALESCE(requires_reset, 0) = 1"
);

$totalResets = $headteacherResets + $schoolItResets + $teacherResets;

$attentionCount = $setupPending + $totalResets + $pendingPayments;

$systemStatus = $attentionCount === 0 ? 'System operating normally' : 'Attention required';

$baseUrl = defined('BASE_URL') ? BASE_URL : '';

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$currentPath = rtrim((string)$currentPath, '/');

$dashboardPath = rtrim($baseUrl, '/') . '/admin/dashboard';

function adminUrl(string $path): string
{
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return $base . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>System Administrator Dashboard - EduLinCore</title>

    <script>
        window.addEventListener('pageshow', function(event) {
            if (
                event.persisted ||
                (performance.navigation && performance.navigation.type === 2)
            ) {
                window.location.reload();
            }
        });
    </script>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
    >

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
            --warning: #d97706;
            --danger: #dc2626;
            --purple: #7c3aed;
            --teal: #0f766e;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.app-body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            display: flex;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }

        .app-sidebar {
            width: 260px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            height: 100%;
            flex-shrink: 0;
            transition: left .3s ease;
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

        .profile-area {
            padding: 1rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: .75rem;
            background: var(--bg-body);
        }

        .profile-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .profile-details {
            overflow: hidden;
        }

        .profile-name {
            font-weight: 700;
            font-size: .85rem;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-role {
            font-size: .68rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            margin-top: .15rem;
        }

        .app-sidebar nav {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
            flex: 1;
            overflow-y: auto;
        }

        .nav-main-link {
            padding: .65rem .85rem;
            border-radius: .5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            transition: .2s ease;
        }

        .nav-main-link:hover,
        .nav-main-link.active {
            background: rgba(37, 99, 235, .08);
            color: var(--primary);
        }

        .nav-main-link i {
            width: 16px;
        }

        .nav-section-title {
            margin-top: .75rem;
            padding: .55rem .85rem;
            font-size: .72rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            background: rgba(0, 0, 0, .02);
            border-radius: .5rem;
        }

        .nav-sub-link {
            padding: .55rem .85rem .55rem 1.5rem;
            border-radius: .5rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: .8rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            transition: .2s ease;
        }

        .nav-sub-link:hover {
            background: rgba(37, 99, 235, .06);
            color: var(--primary);
        }

        .nav-sub-link i {
            width: 14px;
            font-size: .75rem;
        }

        .logout-link {
            padding: .65rem .85rem;
            border-radius: .5rem;
            color: var(--danger);
            text-decoration: none;
            font-weight: 600;
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-top: 1rem;
        }

        .logout-link:hover {
            background: rgba(220, 38, 38, .06);
        }

        .app-main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow-y: auto;
            min-width: 0;
        }

        .top-header {
            background: var(--primary);
            color: #fff;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        .brand-logo {
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            text-decoration: none;
            white-space: nowrap;
        }

        .header-school {
            color: #fff;
            font-weight: 600;
            font-size: .85rem;
        }

        .mobile-nav-toggle {
            display: none;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.25rem;
            cursor: pointer;
            padding: .35rem;
            border-radius: .375rem;
        }

        .dashboard-main-content {
            padding: 1.5rem;
            flex: 1;
        }

        .page-heading {
            margin-bottom: 1.5rem;
        }

        .page-heading h1 {
            font-size: 1.6rem;
            margin-bottom: .35rem;
        }

        .page-heading p {
            font-size: .9rem;
            color: var(--text-muted);
        }

        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: .85rem 1rem;
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: .75rem;
            margin-bottom: 1.25rem;
        }

        .status-left {
            display: flex;
            align-items: center;
            gap: .55rem;
            font-size: .82rem;
            font-weight: 700;
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--success);
        }

        .status-dot.warning {
            background: var(--warning);
        }

        .status-right {
            color: var(--text-muted);
            font-size: .78rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: .75rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .04);
            min-width: 0;
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .stat-icon {
            width: 34px;
            height: 34px;
            border-radius: .5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: var(--primary);
            flex-shrink: 0;
        }

        .stat-value {
            margin-top: .8rem;
            font-size: 1.65rem;
            line-height: 1;
            font-weight: 800;
            color: var(--text-main);
        }

        .stat-note {
            margin-top: .5rem;
            color: var(--text-muted);
            font-size: .7rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: .75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
            overflow: hidden;
        }

        .dashboard-card-header {
            padding: 1rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            border-bottom: 1px solid var(--border-light);
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
        }

        .dashboard-card-body {
            padding: 1.25rem;
        }

        .summary-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: .85rem 0;
            border-bottom: 1px solid var(--border-light);
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-label {
            font-size: .82rem;
            font-weight: 600;
        }

        .summary-description {
            font-size: .7rem;
            color: var(--text-muted);
            margin-top: .15rem;
        }

        .summary-value {
            font-size: .9rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .attention-list {
            display: flex;
            flex-direction: column;
        }

        .attention-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            padding: .85rem 0;
            border-bottom: 1px solid var(--border-light);
        }

        .attention-item:last-child {
            border-bottom: 0;
        }

        .attention-title {
            font-size: .8rem;
            font-weight: 700;
        }

        .attention-description {
            font-size: .68rem;
            color: var(--text-muted);
            margin-top: .15rem;
            line-height: 1.4;
        }

        .attention-badge {
            min-width: 30px;
            height: 28px;
            padding: 0 .45rem;
            border-radius: 999px;
            background: #fff7ed;
            color: var(--warning);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: .72rem;
            font-weight: 800;
        }

        .attention-badge.clear {
            background: #f0fdf4;
            color: var(--success);
        }

        .management-card {
            margin-bottom: 1.5rem;
        }

        .management-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .management-item {
            display: flex;
            align-items: center;
            gap: .85rem;
            padding: 1rem;
            border: 1px solid var(--border-light);
            border-radius: .6rem;
            background: #fff;
            text-decoration: none;
            color: inherit;
            transition: .2s ease;
        }

        .management-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(37, 99, 235, .07);
            transform: translateY(-1px);
        }

        .management-icon {
            width: 40px;
            height: 40px;
            border-radius: .5rem;
            background: rgba(37, 99, 235, .08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .management-title {
            font-size: .82rem;
            font-weight: 700;
        }

        .management-description {
            font-size: .68rem;
            color: var(--text-muted);
            margin-top: .15rem;
            line-height: 1.35;
        }

        .system-details {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .system-detail {
            padding: .9rem 1rem;
            background: #f8fafc;
            border: 1px solid var(--border-light);
            border-radius: .55rem;
        }

        .system-detail-label {
            color: var(--text-muted);
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .system-detail-value {
            margin-top: .35rem;
            font-size: .82rem;
            font-weight: 700;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: .2rem .5rem;
            border-radius: 999px;
            font-size: .65rem;
            font-weight: 800;
        }

        .badge-success {
            background: rgba(22, 163, 74, .1);
            color: var(--success);
        }

        .mockup-footer {
            padding: 1rem;
            background: var(--bg-card);
            border-top: 1px solid var(--border-light);
            text-align: center;
            font-size: .72rem;
            color: var(--text-muted);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .4);
            z-index: 999;
        }

        @media (max-width: 1250px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .management-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .system-details {
                grid-template-columns: repeat(2, 1fr);
            }
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
                box-shadow: 4px 0 15px rgba(0, 0, 0, .1);
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

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .management-grid {
                grid-template-columns: 1fr;
            }

            .system-details {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 500px) {
            .header-school {
                display: none;
            }

            .brand-logo {
                font-size: .9rem;
            }

            .page-heading h1 {
                font-size: 1.35rem;
            }

            .stats-grid {
                gap: .7rem;
            }

            .stat-card {
                padding: .85rem;
            }

            .stat-value {
                font-size: 1.4rem;
            }

            .status-bar {
                align-items: flex-start;
                flex-direction: column;
                gap: .35rem;
            }
        }
    </style>
</head>

<body class="app-body">

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="app-sidebar" id="appSidebar">

    <div class="app-sidebar-header">
        <span>EduLin<span>C</span>ore</span>
    </div>

    <div class="profile-area">
        <div class="profile-icon">
            <i class="fa-solid fa-user-shield"></i>
        </div>

        <div class="profile-details">
            <div class="profile-name">
                <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="profile-role">
                System Administrator
            </div>
        </div>
    </div>

    <nav>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-main-link active"
        >
            <i class="fa-solid fa-house"></i>
            Dashboard
        </a>

        <div class="nav-section-title">
            System Management
        </div>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/schools'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-school"></i>
            Schools
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/schools#billing'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-receipt"></i>
            Billing
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/headteachers'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-user-tie"></i>
            Headteachers
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/school-it'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-computer"></i>
            School IT
        </a>

        <div class="nav-section-title">
            Academic Users
        </div>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/teachers'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-chalkboard-user"></i>
            Teachers
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/students'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-users"></i>
            Students
        </a>

        <div class="nav-section-title">
            Administration
        </div>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/administrators'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-user-shield"></i>
            Administrators
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/admin/reports'), ENT_QUOTES, 'UTF-8') ?>"
            class="nav-sub-link"
        >
            <i class="fa-solid fa-chart-column"></i>
            System Reports
        </a>

        <a
            href="<?= htmlspecialchars(adminUrl('/auth/logout'), ENT_QUOTES, 'UTF-8') ?>"
            class="logout-link"
        >
            <i class="fa-solid fa-power-off"></i>
            Secure Logout
        </a>

    </nav>
</aside>

<div class="app-main-area">

    <header class="top-header">

        <div class="header-left">

            <button
                class="mobile-nav-toggle"
                id="sidebarToggle"
                aria-label="Toggle Navigation"
                type="button"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <a
                href="<?= htmlspecialchars(adminUrl('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"
                class="brand-logo"
            >
                EduLinCore System Administration
            </a>

        </div>

        <div class="header-school">
            <i class="fa-solid fa-shield-halved"></i>
            System Administrator
        </div>

    </header>

    <main class="dashboard-main-content">

        <div class="page-heading">
            <h1>System Overview</h1>
            <p>
                Welcome back, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>.
                Manage the Edulincore system from one central administration portal.
            </p>
        </div>

        <div class="status-bar">

            <div class="status-left">
                <span class="status-dot <?= $attentionCount > 0 ? 'warning' : '' ?>"></span>

                <span>
                    <?= htmlspecialchars($systemStatus, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <div class="status-right">
                <?= number_format($schools) ?> active school<?= $schools === 1 ? '' : 's' ?>
            </div>

        </div>

        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Schools</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-school"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($schools) ?>
                </div>

                <div class="stat-note">
                    Active schools
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Teachers</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($teachers) ?>
                </div>

                <div class="stat-note">
                    Teacher accounts
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Students</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-users"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($students) ?>
                </div>

                <div class="stat-note">
                    Student records
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Headteachers</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-user-tie"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($headteachers) ?>
                </div>

                <div class="stat-note">
                    School leadership
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">School IT</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-computer"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($schoolIt) ?>
                </div>

                <div class="stat-note">
                    IT officer accounts
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Administrators</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-user-shield"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($admins) ?>
                </div>

                <div class="stat-note">
                    System administrator accounts
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Collected</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-money-bill-wave"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($amountPaid, 2) ?>
                </div>

                <div class="stat-note">
                    Recorded payments
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Outstanding</span>
                    <span class="stat-icon">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </span>
                </div>

                <div class="stat-value">
                    <?= number_format($outstandingBalance, 2) ?>
                </div>

                <div class="stat-note">
                    Due minus payments
                </div>
            </div>

        </div>

        <div class="dashboard-grid">

            <div class="dashboard-card">

                <div class="dashboard-card-header">
                    <span>System Summary</span>

                    <i
                        class="fa-solid fa-chart-simple"
                        style="color: var(--primary);"
                    ></i>
                </div>

                <div class="dashboard-card-body">

                    <div class="summary-list">

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    Active Schools
                                </div>

                                <div class="summary-description">
                                    Schools currently active in Edulincore.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($schools) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    School Setup
                                </div>

                                <div class="summary-description">
                                    Schools still awaiting grade setup.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($setupPending) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    Teaching Staff
                                </div>

                                <div class="summary-description">
                                    Teachers registered across all schools.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($teachers) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    Student Population
                                </div>

                                <div class="summary-description">
                                    Students registered across the system.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($students) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    School Leadership
                                </div>

                                <div class="summary-description">
                                    Registered headteacher accounts.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($headteachers) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    Technical Support
                                </div>

                                <div class="summary-description">
                                    Registered School IT accounts.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($schoolIt) ?>
                            </div>
                        </div>

                        <div class="summary-row">
                            <div>
                                <div class="summary-label">
                                    Billing Outstanding
                                </div>

                                <div class="summary-description">
                                    Total recorded balance still due.
                                </div>
                            </div>

                            <div class="summary-value">
                                <?= number_format($outstandingBalance, 2) ?>
                            </div>
                        </div>

                    </div>

                </div>

            </div>

            <div class="dashboard-card">

                <div class="dashboard-card-header">
                    <span>Attention Required</span>

                    <i
                        class="fa-solid fa-triangle-exclamation"
                        style="color: var(--warning);"
                    ></i>
                </div>

                <div class="dashboard-card-body">

                    <div class="attention-list">

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    School Setup
                                </div>

                                <div class="attention-description">
                                    Schools without completed grade setup.
                                </div>
                            </div>

                            <span class="attention-badge <?= $setupPending === 0 ? 'clear' : '' ?>">
                                <?= number_format($setupPending) ?>
                            </span>
                        </div>

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    Headteacher Accounts
                                </div>

                                <div class="attention-description">
                                    Accounts requiring password attention.
                                </div>
                            </div>

                            <span class="attention-badge <?= $headteacherResets === 0 ? 'clear' : '' ?>">
                                <?= number_format($headteacherResets) ?>
                            </span>
                        </div>

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    School IT Accounts
                                </div>

                                <div class="attention-description">
                                    Accounts requiring password attention.
                                </div>
                            </div>

                            <span class="attention-badge <?= $schoolItResets === 0 ? 'clear' : '' ?>">
                                <?= number_format($schoolItResets) ?>
                            </span>
                        </div>

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    Teacher Accounts
                                </div>

                                <div class="attention-description">
                                    Teacher accounts requiring reset.
                                </div>
                            </div>

                            <span class="attention-badge <?= $teacherResets === 0 ? 'clear' : '' ?>">
                                <?= number_format($teacherResets) ?>
                            </span>
                        </div>

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    Archived Schools
                                </div>

                                <div class="attention-description">
                                    Schools marked as deleted.
                                </div>
                            </div>

                            <span class="attention-badge <?= $deletedSchools === 0 ? 'clear' : '' ?>">
                                <?= number_format($deletedSchools) ?>
                            </span>
                        </div>

                        <div class="attention-item">
                            <div>
                                <div class="attention-title">
                                    Payment Submissions
                                </div>

                                <div class="attention-description">
                                    School payments waiting for verification.
                                </div>
                            </div>

                            <span class="attention-badge <?= $pendingPayments === 0 ? 'clear' : '' ?>">
                                <?= number_format($pendingPayments) ?>
                            </span>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="dashboard-card management-card">

            <div class="dashboard-card-header">
                <span>System Management</span>

                <i
                    class="fa-solid fa-sliders"
                    style="color: var(--primary);"
                ></i>
            </div>

            <div class="dashboard-card-body">

                <div class="management-grid">

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/schools'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-school"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Schools
                            </div>

                            <div class="management-description">
                                Manage schools and institutional setup.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/headteachers'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Headteachers
                            </div>

                            <div class="management-description">
                                Manage school leadership accounts.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/school-it'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-computer"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                School IT
                            </div>

                            <div class="management-description">
                                Manage School IT accounts and access.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/teachers'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Teachers
                            </div>

                            <div class="management-description">
                                Manage teacher accounts system-wide.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/students'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-users"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Students
                            </div>

                            <div class="management-description">
                                View and manage student records.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/administrators'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Administrators
                            </div>

                            <div class="management-description">
                                Manage system administrator accounts.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars(adminUrl('/admin/reports'), ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-chart-column"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                System Reports
                            </div>

                            <div class="management-description">
                                Review system-wide administrative information.
                            </div>
                        </div>
                    </a>

                    <a
                        href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>"
                        class="management-item"
                    >
                        <div class="management-icon">
                            <i class="fa-solid fa-rotate"></i>
                        </div>

                        <div>
                            <div class="management-title">
                                Refresh Dashboard
                            </div>

                            <div class="management-description">
                                Load the latest system information.
                            </div>
                        </div>
                    </a>

                </div>

            </div>

        </div>

        <div class="dashboard-card">

            <div class="dashboard-card-header">
                <span>System Information</span>

                <i
                    class="fa-solid fa-server"
                    style="color: var(--primary);"
                ></i>
            </div>

            <div class="dashboard-card-body">

                <div class="system-details">

                    <div class="system-detail">
                        <div class="system-detail-label">
                            Platform
                        </div>

                        <div class="system-detail-value">
                            EduLinCore
                        </div>
                    </div>

                    <div class="system-detail">
                        <div class="system-detail-label">
                            Database
                        </div>

                        <div class="system-detail-value">
                            MySQL PDO
                        </div>
                    </div>

                    <div class="system-detail">
                        <div class="system-detail-label">
                            Session
                        </div>

                        <div class="system-detail-value">
                            <span class="badge badge-success">
                                <i class="fa-solid fa-lock" style="margin-right: .25rem;"></i>
                                Secure
                            </span>
                        </div>
                    </div>

                    <div class="system-detail">
                        <div class="system-detail-label">
                            Server Time
                        </div>

                        <div class="system-detail-value">
                            <?= htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </main>

    <footer class="mockup-footer">
        <span>
            &copy; <?= date('Y') ?> EduLinCore School Management Framework. All rights reserved.
        </span>
    </footer>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const appSidebar = document.getElementById('appSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            appSidebar.classList.toggle('sidebar-open');
            document.body.classList.toggle('menu-open');
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', toggleSidebar);
        }

        const currentPath = window.location.pathname;

        document.querySelectorAll('.app-sidebar nav a').forEach(function(link) {
            const href = link.getAttribute('href');

            if (!href) {
                return;
            }

            try {
                const linkUrl = new URL(href, window.location.origin);
                const linkPath = linkUrl.pathname.replace(/\/$/, '');

                if (
                    linkPath === currentPath.replace(/\/$/, '') &&
                    linkPath !== ''
                ) {
                    link.classList.add('active');
                }
            } catch (error) {
            }
        });
    });

    let inactivityTimer;

    const logoutRedirectUrl =
        <?= json_encode(adminUrl('/auth/logout'), JSON_UNESCAPED_SLASHES) ?>;

    const idleLimitMs = 15 * 60 * 1000;

    function resetIdleTimer() {
        clearTimeout(inactivityTimer);

        inactivityTimer = setTimeout(function() {
            alert(
                'Your session has timed out due to inactivity for security purposes. You are being logged out.'
            );

            window.location.href = logoutRedirectUrl;
        }, idleLimitMs);
    }

    [
        'mousemove',
        'keydown',
        'mousedown',
        'touchstart',
        'scroll'
    ].forEach(function(activityEvent) {
        window.addEventListener(
            activityEvent,
            resetIdleTimer,
            true
        );
    });

    resetIdleTimer();
</script>

</body>
</html>