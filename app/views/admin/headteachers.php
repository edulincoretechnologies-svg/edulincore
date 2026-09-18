<?php

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    header('Location: ' . BASE_URL . '/auth/admin_login');
    exit;
}

$db = Database::getConnection();

if (empty($_SESSION['admin_headteachers_csrf'])) {
    $_SESSION['admin_headteachers_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_headteachers_csrf'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $headteacherId = (int)($_POST['headteacher_id'] ?? 0);

        if ($action === 'record_payment') {
            try {
                $paymentSchoolId = (int)($_POST['payment_school_id'] ?? 0);
                $billingPeriod = trim((string)($_POST['billing_period'] ?? ''));
                $academicYear = trim((string)($_POST['academic_year'] ?? ''));
                $termName = trim((string)($_POST['term_name'] ?? ''));
                $amountDue = filter_var($_POST['amount_due'] ?? '', FILTER_VALIDATE_FLOAT);
                $amountPaid = filter_var($_POST['amount_paid'] ?? '0', FILTER_VALIDATE_FLOAT);
                $paymentStatus = trim((string)($_POST['payment_status'] ?? 'pending'));
                $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
                $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
                $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
                $paymentNotes = trim((string)($_POST['payment_notes'] ?? ''));

                if (
                    $paymentSchoolId < 1 ||
                    $billingPeriod === '' ||
                    $academicYear === '' ||
                    $amountDue === false ||
                    $amountDue < 0 ||
                    $amountPaid === false ||
                    $amountPaid < 0 ||
                    !in_array($paymentStatus, ['pending', 'partial', 'paid', 'overdue', 'cancelled'], true)
                ) {
                    throw new InvalidArgumentException('Enter a school, billing period, academic year and valid payment amounts.');
                }

                $schoolCheck = $db->prepare(
                    "SELECT id, school_name
                     FROM schools
                     WHERE id = ? AND COALESCE(is_deleted, 0) = 0
                     LIMIT 1"
                );
                $schoolCheck->execute([$paymentSchoolId]);
                $paymentSchool = $schoolCheck->fetch(PDO::FETCH_ASSOC);

                if (!$paymentSchool) {
                    throw new InvalidArgumentException('The selected school could not be found.');
                }

                $paymentInsert = $db->prepare(
                    "INSERT INTO school_payments (
                        school_id, pricing_plan_id, billing_period, academic_year, term_name,
                        amount_due, amount_paid, payment_status, payment_date, payment_method,
                        payment_reference, notes, created_at, updated_at
                    ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NOW(), NOW())"
                );
                $paymentInsert->execute([
                    $paymentSchoolId,
                    $billingPeriod,
                    $academicYear,
                    $termName !== '' ? $termName : null,
                    $amountDue,
                    $amountPaid,
                    $paymentStatus,
                    $paymentDate,
                    $paymentMethod !== '' ? $paymentMethod : null,
                    $paymentReference !== '' ? $paymentReference : null,
                    $paymentNotes !== '' ? $paymentNotes : null
                ]);

                $message = 'Payment recorded for ' . $paymentSchool['school_name'] . '.';
            } catch (Throwable $e) {
                $error = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'Unable to record the payment. Please try again.';
            }
        } elseif ($action === 'reset_password' && $headteacherId > 0) {
            try {
                $check = $db->prepare(
                    "SELECT id, full_name, email
                     FROM headteachers
                     WHERE id = ?
                     LIMIT 1"
                );
                $check->execute([$headteacherId]);
                $headteacher = $check->fetch(PDO::FETCH_ASSOC);

                if (!$headteacher) {
                    $error = 'Headteacher account could not be found.';
                } else {
                    $temporaryPassword = '123456';
                    $passwordHash = password_hash(
                        $temporaryPassword,
                        PASSWORD_DEFAULT
                    );

                    $update = $db->prepare(
                        "UPDATE headteachers
                         SET password_hash = ?,
                             requires_reset = 1
                         WHERE id = ?
                         LIMIT 1"
                    );

                    $update->execute([
                        $passwordHash,
                        $headteacherId
                    ]);

                    $message =
                        'Password reset successfully for ' .
                        $headteacher['full_name'] .
                        '. The temporary password is 123456 and the account is marked for password reset.';
                }
            } catch (Throwable $e) {
                $error = 'Unable to reset the headteacher password. Please try again.';
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$schoolFilter = (int)($_GET['school_id'] ?? 0);
$statusFilter = $_GET['status'] ?? 'all';

$headteachers = [];
$schools = [];
$totalHeadteachers = 0;
$readyAccounts = 0;
$resetRequired = 0;
$schoolsRepresented = 0;
$billingStats = [
    'amount_due' => 0.0,
    'amount_paid' => 0.0,
    'payment_count' => 0,
    'pending_submissions' => 0
];
$paymentHistory = [];

try {
    $schoolQuery = $db->query(
        "SELECT id, school_name, school_code
         FROM schools
         WHERE COALESCE(is_deleted, 0) = 0
         ORDER BY school_name ASC"
    );

    $schools = $schoolQuery->fetchAll(PDO::FETCH_ASSOC);

    $countQuery = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN COALESCE(requires_reset, 0) = 1
                    THEN 1
                    ELSE 0
                END
            ) AS reset_required,
            SUM(
                CASE
                    WHEN COALESCE(requires_reset, 0) = 0
                    THEN 1
                    ELSE 0
                END
            ) AS ready,
            COUNT(DISTINCT school_id) AS schools_represented
         FROM headteachers"
    );

    $counts = $countQuery->fetch(PDO::FETCH_ASSOC);

    $totalHeadteachers = (int)($counts['total'] ?? 0);
    $resetRequired = (int)($counts['reset_required'] ?? 0);
    $readyAccounts = (int)($counts['ready'] ?? 0);
    $schoolsRepresented = (int)($counts['schools_represented'] ?? 0);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(
            h.full_name LIKE ?
            OR h.email LIKE ?
            OR h.phone_number LIKE ?
            OR s.school_name LIKE ?
            OR s.school_code LIKE ?
        )";

        $searchValue = '%' . $search . '%';

        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
        $params[] = $searchValue;
    }

    if ($schoolFilter > 0) {
        $where[] = "h.school_id = ?";
        $params[] = $schoolFilter;
    }

    if ($statusFilter === 'ready') {
        $where[] = "COALESCE(h.requires_reset, 0) = 0";
    } elseif ($statusFilter === 'reset') {
        $where[] = "COALESCE(h.requires_reset, 0) = 1";
    }

    $whereSql = '';

    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    $stmt = $db->prepare(
        "SELECT
            h.id,
            h.full_name,
            h.gender,
            h.phone_number,
            h.email,
            h.school_id,
            h.created_at,
            COALESCE(h.requires_reset, 0) AS requires_reset,
            COALESCE(h.must_change_password, 0) AS must_change_password,
            s.school_name,
            s.school_code,
            COALESCE(s.is_suspended, 0) AS school_suspended,
            COALESCE(s.is_deleted, 0) AS school_deleted
         FROM headteachers h
         LEFT JOIN schools s
            ON s.id = h.school_id
         $whereSql
         ORDER BY h.created_at DESC, h.full_name ASC"
    );

    $stmt->execute($params);
    $headteachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $billingStatsStmt = $db->query(
        "SELECT
            COALESCE(SUM(amount_due), 0) AS amount_due,
            COALESCE(SUM(amount_paid), 0) AS amount_paid,
            COUNT(*) AS payment_count
         FROM school_payments"
    );
    $billingStats = array_merge($billingStats, $billingStatsStmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $pendingStmt = $db->query(
        "SELECT COUNT(*)
         FROM school_payment_submissions
         WHERE COALESCE(verification_status, 'pending') = 'pending'"
    );
    $billingStats['pending_submissions'] = (int)$pendingStmt->fetchColumn();

    $paymentHistoryStmt = $db->query(
        "SELECT
            sp.id,
            sp.school_id,
            sp.billing_period,
            sp.academic_year,
            sp.term_name,
            sp.amount_due,
            sp.amount_paid,
            sp.payment_status,
            sp.payment_date,
            sp.payment_method,
            sp.payment_reference,
            sp.created_at,
            s.school_name,
            COALESCE(submission_summary.submission_count, 0) AS submission_count,
            COALESCE(submission_summary.pending_count, 0) AS pending_count
         FROM school_payments sp
         INNER JOIN schools s ON s.id = sp.school_id
         LEFT JOIN (
            SELECT
                school_payment_id,
                COUNT(*) AS submission_count,
                SUM(CASE WHEN COALESCE(verification_status, 'pending') = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM school_payment_submissions
            GROUP BY school_payment_id
         ) submission_summary ON submission_summary.school_payment_id = sp.id
         ORDER BY sp.created_at DESC, sp.id DESC
         LIMIT 100"
    );
    $paymentHistory = $paymentHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Unable to load headteacher information at this time.';
}

$displayName = $_SESSION['user_name'] ?? 'System Administrator';

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Headteachers | Admin | Edulincore</title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >
    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: "Plus Jakarta Sans", sans-serif;
        }

        .page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 28px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 26px;
        }

        .heading h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .heading p {
            margin: 7px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .back-link:hover {
            background: #f8fafc;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }

        .stat {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 19px;
        }

        .stat-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .stat-value {
            margin-top: 8px;
            font-size: 25px;
            font-weight: 800;
        }

        .stat-note {
            margin-top: 5px;
            color: #94a3b8;
            font-size: 12px;
        }

        .billing-section {
            margin-top: 22px;
        }

        .billing-header {
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .billing-header h2 {
            margin: 0;
            font-size: 16px;
        }

        .billing-header p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 12px;
        }

        .billing-body {
            padding: 18px;
        }

        .billing-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            padding-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .billing-form .full-width {
            grid-column: 1 / -1;
        }

        .billing-form label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
        }

        .billing-form input,
        .billing-form select,
        .billing-form textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 10px 11px;
            background: #fff;
            color: #0f172a;
            font: inherit;
            font-size: 12px;
            outline: none;
        }

        .billing-form input,
        .billing-form select {
            height: 40px;
        }

        .billing-form textarea {
            min-height: 70px;
            resize: vertical;
        }

        .billing-form input:focus,
        .billing-form select:focus,
        .billing-form textarea:focus {
            border-color: #64748b;
            box-shadow: 0 0 0 3px rgba(100, 116, 139, .08);
        }

        .billing-form .submit-cell {
            display: flex;
            align-items: flex-end;
        }

        .billing-table-wrap {
            margin-top: 18px;
            overflow-x: auto;
        }

        .billing-table {
            min-width: 950px;
        }

        .invoice-number {
            color: #1e293b;
            font-weight: 800;
            font-size: 12px;
        }

        .invoice-meta {
            margin-top: 3px;
            color: #94a3b8;
            font-size: 10px;
        }

        .billing-status {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .billing-status.paid { background: #ecfdf5; color: #047857; }
        .billing-status.partial { background: #fffbeb; color: #b45309; }
        .billing-status.overdue { background: #fef2f2; color: #b91c1c; }
        .billing-status.pending { background: #eff6ff; color: #1d4ed8; }

        .submission-note {
            margin-top: 4px;
            color: #b45309;
            font-size: 10px;
            font-weight: 700;
        }

        .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
        }

        .filters {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr auto;
            gap: 10px;
            padding: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .input,
        .select {
            width: 100%;
            height: 43px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 0 13px;
            background: #fff;
            color: #0f172a;
            font-family: inherit;
            font-size: 13px;
            outline: none;
        }

        .input:focus,
        .select:focus {
            border-color: #64748b;
        }

        .btn {
            height: 43px;
            border: 0;
            border-radius: 9px;
            padding: 0 16px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .btn-primary {
            background: #0f172a;
            color: #fff;
        }

        .btn-light {
            background: #f1f5f9;
            color: #334155;
        }

        .notice {
            margin: 18px;
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
        }

        .notice.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .notice.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        th {
            padding: 13px 18px;
            text-align: left;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .45px;
        }

        td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .person {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #334155;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .person-name {
            font-weight: 800;
            color: #0f172a;
        }

        .person-email {
            margin-top: 3px;
            color: #94a3b8;
            font-size: 11px;
        }

        .school-name {
            font-weight: 700;
            color: #334155;
        }

        .school-code {
            margin-top: 3px;
            color: #94a3b8;
            font-size: 11px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
        }

        .badge-ready {
            background: #ecfdf5;
            color: #047857;
        }

        .badge-reset {
            background: #fff7ed;
            color: #c2410c;
        }

        .badge-suspended {
            background: #fef2f2;
            color: #b91c1c;
        }

        .action-form {
            display: inline;
        }

        .reset-btn {
            border: 1px solid #fed7aa;
            background: #fff7ed;
            color: #c2410c;
            border-radius: 8px;
            padding: 8px 10px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }

        .reset-btn:hover {
            background: #ffedd5;
        }

        .empty {
            padding: 55px 20px;
            text-align: center;
            color: #64748b;
        }

        .empty i {
            display: block;
            margin-bottom: 12px;
            font-size: 28px;
            color: #cbd5e1;
        }

        .empty strong {
            display: block;
            color: #334155;
            margin-bottom: 5px;
        }

        .mobile-note {
            display: none;
        }

        @media (max-width: 1050px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .billing-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .page {
                padding: 18px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .billing-form {
                grid-template-columns: 1fr;
            }

            .billing-form .full-width {
                grid-column: auto;
            }

            .mobile-note {
                display: block;
                padding: 10px 18px;
                color: #94a3b8;
                font-size: 11px;
                border-bottom: 1px solid #e2e8f0;
            }
        }
    </style>
</head>

<body>

<div class="page">

    <div class="topbar">
        <div class="heading">
            <h1>Headteachers</h1>
            <p>Manage headteacher accounts and school assignments.</p>
        </div>

        <a
            href="<?= e(BASE_URL) ?>/admin/dashboard"
            class="back-link"
        >
            <i class="fa-solid fa-arrow-left"></i>
            Admin Dashboard
        </a>
    </div>

    <div class="stats">

        <div class="stat">
            <div class="stat-label">Total Headteachers</div>
            <div class="stat-value">
                <?= number_format($totalHeadteachers) ?>
            </div>
            <div class="stat-note">
                Registered accounts
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Ready Accounts</div>
            <div class="stat-value">
                <?= number_format($readyAccounts) ?>
            </div>
            <div class="stat-note">
                No password reset required
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Reset Required</div>
            <div class="stat-value">
                <?= number_format($resetRequired) ?>
            </div>
            <div class="stat-note">
                Accounts requiring password reset
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Schools Represented</div>
            <div class="stat-value">
                <?= number_format($schoolsRepresented) ?>
            </div>
            <div class="stat-note">
                Schools with headteacher accounts
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Amount Paid</div>
            <div class="stat-value">
                <?= number_format((float)$billingStats['amount_paid'], 2) ?>
            </div>
            <div class="stat-note">
                Recorded school payments
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Outstanding</div>
            <div class="stat-value">
                <?= number_format(max(0, (float)$billingStats['amount_due'] - (float)$billingStats['amount_paid']), 2) ?>
            </div>
            <div class="stat-note">
                Total due less payments
            </div>
        </div>

        <div class="stat">
            <div class="stat-label">Pending Submissions</div>
            <div class="stat-value">
                <?= number_format((int)$billingStats['pending_submissions']) ?>
            </div>
            <div class="stat-note">
                Awaiting verification
            </div>
        </div>

    </div>

    <div class="panel">

        <form
            method="GET"
            action="<?= e(BASE_URL) ?>/admin/headteachers"
            class="filters"
        >

            <input
                type="search"
                name="search"
                class="input"
                placeholder="Search name, email, phone or school..."
                value="<?= e($search) ?>"
            >

            <select
                name="school_id"
                class="select"
            >
                <option value="0">All Schools</option>

                <?php foreach ($schools as $school): ?>
                    <option
                        value="<?= (int)$school['id'] ?>"
                        <?= $schoolFilter === (int)$school['id'] ? 'selected' : '' ?>
                    >
                        <?= e($school['school_name']) ?>
                        <?php if (!empty($school['school_code'])): ?>
                            — <?= e($school['school_code']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <select
                name="status"
                class="select"
            >
                <option
                    value="all"
                    <?= $statusFilter === 'all' ? 'selected' : '' ?>
                >
                    All Account Status
                </option>

                <option
                    value="ready"
                    <?= $statusFilter === 'ready' ? 'selected' : '' ?>
                >
                    Ready
                </option>

                <option
                    value="reset"
                    <?= $statusFilter === 'reset' ? 'selected' : '' ?>
                >
                    Password Reset Required
                </option>
            </select>

            <button
                type="submit"
                class="btn btn-primary"
            >
                <i class="fa-solid fa-magnifying-glass"></i>
                Search
            </button>

        </form>

        <?php if ($message !== ''): ?>
            <div class="notice success">
                <i class="fa-solid fa-circle-check"></i>
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="mobile-note">
            Swipe horizontally to view all headteacher information.
        </div>

        <div class="table-wrap">

            <?php if (empty($headteachers)): ?>

                <div class="empty">
                    <i class="fa-solid fa-user-tie"></i>
                    <strong>No headteachers found</strong>
                    Try changing the search or filter criteria.
                </div>

            <?php else: ?>

                <table>

                    <thead>
                        <tr>
                            <th>Headteacher</th>
                            <th>School</th>
                            <th>Gender</th>
                            <th>Phone</th>
                            <th>Account</th>
                            <th>School Status</th>
                            <th>Registered</th>
                            <th>Control</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($headteachers as $headteacher): ?>

                        <?php
                        $name = trim((string)$headteacher['full_name']);
                        $initials = '';

                        $nameParts = preg_split('/\s+/', $name);

                        if (!empty($nameParts[0])) {
                            $initials .= strtoupper(
                                substr($nameParts[0], 0, 1)
                            );
                        }

                        if (count($nameParts) > 1) {
                            $lastPart = end($nameParts);

                            if (!empty($lastPart)) {
                                $initials .= strtoupper(
                                    substr($lastPart, 0, 1)
                                );
                            }
                        }

                        $isResetRequired =
                            (int)$headteacher['requires_reset'] === 1;

                        $schoolSuspended =
                            (int)$headteacher['school_suspended'] === 1;
                        ?>

                        <tr>

                            <td>
                                <div class="person">

                                    <div class="avatar">
                                        <?= e($initials ?: 'HT') ?>
                                    </div>

                                    <div>
                                        <div class="person-name">
                                            <?= e($name ?: 'Unnamed Headteacher') ?>
                                        </div>

                                        <?php if (!empty($headteacher['email'])): ?>
                                            <div class="person-email">
                                                <?= e($headteacher['email']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </td>

                            <td>
                                <div class="school-name">
                                    <?= e(
                                        $headteacher['school_name']
                                        ?: 'School Not Assigned'
                                    ) ?>
                                </div>

                                <?php if (!empty($headteacher['school_code'])): ?>
                                    <div class="school-code">
                                        <?= e($headteacher['school_code']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(
                                    ucfirst(
                                        strtolower(
                                            (string)($headteacher['gender'] ?? '')
                                        )
                                    ) ?: '—'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $headteacher['phone_number']
                                    ?: '—'
                                ) ?>
                            </td>

                            <td>

                                <?php if ($isResetRequired): ?>

                                    <span class="badge badge-reset">
                                        <i class="fa-solid fa-key"></i>
                                        Reset Required
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-ready">
                                        <i class="fa-solid fa-check"></i>
                                        Ready
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php if ($schoolSuspended): ?>

                                    <span class="badge badge-suspended">
                                        <i class="fa-solid fa-lock"></i>
                                        School Suspended
                                    </span>

                                <?php elseif (
                                    (int)$headteacher['school_deleted'] === 1
                                ): ?>

                                    <span class="badge badge-suspended">
                                        <i class="fa-solid fa-ban"></i>
                                        School Removed
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-ready">
                                        <i class="fa-solid fa-circle-check"></i>
                                        Active School
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?php
                                $createdAt = $headteacher['created_at'] ?? null;

                                echo $createdAt
                                    ? e(date('d M Y', strtotime($createdAt)))
                                    : '—';
                                ?>
                            </td>

                            <td>

                                <form
                                    method="POST"
                                    action="<?= e(BASE_URL) ?>/admin/headteachers"
                                    class="action-form"
                                    onsubmit="return confirm('Reset this headteacher password to 123456? The headteacher will be required to reset the password.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="reset_password"
                                    >

                                    <input
                                        type="hidden"
                                        name="headteacher_id"
                                        value="<?= (int)$headteacher['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="reset-btn"
                                    >
                                        <i class="fa-solid fa-key"></i>
                                        Reset Password
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </div>

</div>

<section class="panel billing-section" id="billing">
    <div class="billing-header">
        <div>
            <h2>School Billing and Invoice History</h2>
            <p>Record payments and review the latest invoices and submitted payment evidence.</p>
        </div>
        <i class="fa-solid fa-file-invoice-dollar" style="color:#0f766e;font-size:20px;"></i>
    </div>

    <div class="billing-body">
        <form method="POST" action="<?= e(BASE_URL) ?>/admin/headteachers" class="billing-form">
            <div>
                <label for="paymentSchoolId">School</label>
                <select id="paymentSchoolId" name="payment_school_id" required>
                    <option value="">Select school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= (int)$school['id'] ?>">
                            <?= e($school['school_name']) ?><?= !empty($school['school_code']) ? ' — ' . e($school['school_code']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="billingPeriod">Billing Period</label>
                <input id="billingPeriod" name="billing_period" placeholder="e.g. Term 1" required>
            </div>

            <div>
                <label for="academicYear">Academic Year</label>
                <input id="academicYear" name="academic_year" placeholder="e.g. 2026" required>
            </div>

            <div>
                <label for="termName">Term Name</label>
                <input id="termName" name="term_name" placeholder="Optional">
            </div>

            <div>
                <label for="amountDue">Amount Due</label>
                <input id="amountDue" name="amount_due" type="number" min="0" step="0.01" required>
            </div>

            <div>
                <label for="amountPaid">Amount Paid</label>
                <input id="amountPaid" name="amount_paid" type="number" min="0" step="0.01" value="0" required>
            </div>

            <div>
                <label for="paymentStatus">Status</label>
                <select id="paymentStatus" name="payment_status">
                    <option value="pending">Pending</option>
                    <option value="partial">Partial</option>
                    <option value="paid">Paid</option>
                    <option value="overdue">Overdue</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <div>
                <label for="paymentDate">Payment Date</label>
                <input id="paymentDate" name="payment_date" type="date">
            </div>

            <div>
                <label for="paymentMethod">Payment Method</label>
                <input id="paymentMethod" name="payment_method" placeholder="e.g. Bank transfer">
            </div>

            <div>
                <label for="paymentReference">Payment Reference</label>
                <input id="paymentReference" name="payment_reference">
            </div>

            <div class="full-width">
                <label for="paymentNotes">Notes</label>
                <textarea id="paymentNotes" name="payment_notes" maxlength="1000" placeholder="Optional billing notes"></textarea>
            </div>

            <div class="submit-cell full-width">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="record_payment">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-receipt"></i>
                    Record Payment
                </button>
            </div>
        </form>

        <div class="billing-table-wrap">
            <?php if (empty($paymentHistory)): ?>
                <div class="empty">
                    <i class="fa-solid fa-file-invoice"></i>
                    <strong>No billing history found</strong>
                    Record the first school payment to create an invoice history.
                </div>
            <?php else: ?>
                <table class="billing-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>School</th>
                            <th>Period</th>
                            <th>Due</th>
                            <th>Paid</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Submissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <?php
                            $invoiceNumber = 'INV-' . date('Y', strtotime((string)$payment['created_at'])) . '-' . str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT);
                            $paymentStatus = strtolower((string)$payment['payment_status']);
                            ?>
                            <tr>
                                <td>
                                    <div class="invoice-number"><?= e($invoiceNumber) ?></div>
                                    <div class="invoice-meta"><?= e(date('d M Y', strtotime((string)$payment['created_at']))) ?></div>
                                </td>
                                <td><?= e($payment['school_name']) ?></td>
                                <td>
                                    <?= e($payment['billing_period']) ?>
                                    <?php if (!empty($payment['term_name'])): ?>
                                        <div class="invoice-meta"><?= e($payment['term_name']) ?>, <?= e($payment['academic_year']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((float)$payment['amount_due'], 2) ?></td>
                                <td><?= number_format((float)$payment['amount_paid'], 2) ?></td>
                                <td><span class="billing-status <?= e($paymentStatus) ?>"><?= e($paymentStatus) ?></span></td>
                                <td><?= e($payment['payment_reference'] ?: '—') ?></td>
                                <td>
                                    <?= (int)$payment['submission_count'] ?> submitted
                                    <?php if ((int)$payment['pending_count'] > 0): ?>
                                        <div class="submission-note"><?= (int)$payment['pending_count'] ?> pending verification</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
    let inactivityTimer;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);

        inactivityTimer = setTimeout(function () {
            window.location.href =
                "<?= e(BASE_URL) ?>/auth/admin_login";
        }, 900000);
    }

    [
        'click',
        'mousemove',
        'mousedown',
        'keydown',
        'scroll',
        'touchstart'
    ].forEach(function (eventName) {
        document.addEventListener(
            eventName,
            resetInactivityTimer,
            { passive: true }
        );
    });

    resetInactivityTimer();
</script>

</body>
</html>