<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'headteacher'
) {
    header('Location: ' . BASE_URL . '/auth/school_login');
    exit;
}

$school_id = $_SESSION['school_id'] ?? null;

if (!$school_id) {
    header('Location: ' . BASE_URL . '/auth/logout');
    exit;
}

$db = Database::getConnection();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function numberFormat(mixed $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals);
}

function percentage(float $value): string
{
    return number_format($value, 1) . '%';
}

$reportsRoute = BASE_URL . '/headteacher/dashboard#overview';
$performanceRoute = BASE_URL . '/headteacher/dashboard#payments';

$school = [
    'school_name' => 'School',
    'school_code' => '',
    'pricing_plan_id' => null
];

$headteacherName = 'Headteacher';
$headteacherId = (int)($_SESSION['user_id'] ?? 0);

$csrfSessionKey = 'headteacher_dashboard_csrf';

if (empty($_SESSION[$csrfSessionKey])) {
    $_SESSION[$csrfSessionKey] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION[$csrfSessionKey];
$dashboardMessage = '';
$dashboardError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $dashboardError = 'Your security token has expired. Please refresh and try again.';
    } elseif (($_POST['action'] ?? '') === 'submit_payment') {
        try {
            $paymentId = (int)($_POST['school_payment_id'] ?? 0);
            $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
            $transactionReference = trim((string)($_POST['transaction_reference'] ?? ''));
            $paymentMessage = trim((string)($_POST['payment_message'] ?? ''));
            $paymentDate = trim((string)($_POST['payment_date'] ?? ''));

            if ($amount === false || $amount <= 0 || $transactionReference === '') {
                throw new InvalidArgumentException('Enter a valid amount and transaction reference.');
            }

            if ($paymentId > 0) {
                $paymentCheck = $db->prepare(
                    'SELECT id FROM school_payments WHERE id = ? AND school_id = ? LIMIT 1'
                );
                $paymentCheck->execute([$paymentId, $school_id]);

                if (!$paymentCheck->fetchColumn()) {
                    throw new InvalidArgumentException('The selected invoice does not belong to this school.');
                }
            }

            $submission = $db->prepare(
                "INSERT INTO school_payment_submissions (
                    school_id, school_payment_id, amount, transaction_reference,
                    payment_message, payment_date, submitted_by, submitted_role,
                    submitted_at, verification_status
                ) VALUES (?, NULLIF(?, 0), ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, 0), 'headteacher', NOW(), 'pending')"
            );
            $submission->execute([
                $school_id,
                $paymentId,
                $amount,
                $transactionReference,
                $paymentMessage,
                $paymentDate,
                $headteacherId
            ]);

            $dashboardMessage = 'Payment submission sent for verification.';
        } catch (Throwable $e) {
            $dashboardError = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Unable to submit the payment for verification.';
        }
    }
}

$pricingPlan = null;

$totalDue = 0.0;
$totalPaid = 0.0;
$balance = 0.0;
$paymentPercentage = 0.0;
$paymentStatus = 'Unavailable';

$recentPayments = [];
$paymentOptions = [];
$paymentSubmissions = [];

$studentCount = 0;
$teacherCount = 0;
$classCount = 0;
$subjectCount = 0;

try {
    $stmt = $db->prepare("
        SELECT id, school_name, school_code, pricing_plan_id
        FROM schools
        WHERE id = ?
        AND (is_deleted = 0 OR is_deleted IS NULL)
        LIMIT 1
    ");

    $stmt->execute([$school_id]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $school = array_merge($school, $result);
    }
} catch (Throwable $e) {
    $school = [
        'school_name' => 'School',
        'school_code' => '',
        'pricing_plan_id' => null
    ];
}

try {
    $stmt = $db->prepare("
        SELECT id, school_id, full_name
        FROM headteachers
        WHERE school_id = ?
        LIMIT 1
    ");

    $stmt->execute([$school_id]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && !empty($result['full_name'])) {
        $headteacherName = $result['full_name'];
    }
} catch (Throwable $e) {
    $headteacherName = 'Headteacher';
}

if (empty($school['pricing_plan_id'])) {
    try {
        $fallbackPlanStmt = $db->prepare(
            "SELECT pricing_plan_id
             FROM school_payments
             WHERE school_id = ? AND pricing_plan_id IS NOT NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        $fallbackPlanStmt->execute([$school_id]);
        $school['pricing_plan_id'] = $fallbackPlanStmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $school['pricing_plan_id'] = null;
    }
}

if (!empty($school['pricing_plan_id'])) {
    try {
        $stmt = $db->prepare("
            SELECT id, plan_name, price, billing_period, extra_months
            FROM pricing_plans
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$school['pricing_plan_id']]);

        $pricingPlan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $pricingPlan = null;
    }
}

try {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(amount_due), 0) AS total_due,
            COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM school_payments
        WHERE school_id = ?
    ");

    $stmt->execute([$school_id]);

    $paymentTotals = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($paymentTotals) {
        $totalDue = (float) ($paymentTotals['total_due'] ?? 0);
        $totalPaid = (float) ($paymentTotals['total_paid'] ?? 0);
    }
} catch (Throwable $e) {
    $totalDue = 0.0;
    $totalPaid = 0.0;
}

$balance = max(0, $totalDue - $totalPaid);

if ($totalDue > 0) {
    $paymentPercentage = min(
        100,
        round(($totalPaid / $totalDue) * 100, 1)
    );
}

if (!$pricingPlan) {
    $paymentStatus = 'Not Assigned';
} elseif ($totalDue > 0 && $totalPaid >= $totalDue) {
    $paymentStatus = 'Paid';
} elseif ($totalPaid > 0) {
    $paymentStatus = 'Partially Paid';
} elseif ($totalDue > 0) {
    $paymentStatus = 'Unpaid';
} else {
    $paymentStatus = 'Unavailable';
}

try {
    $stmt = $db->prepare("
        SELECT
            id,
            payment_date,
            amount_due,
            amount_paid,
            billing_period,
            academic_year,
            term_name,
            payment_reference,
            payment_status,
            payment_method,
            created_at
        FROM school_payments
        WHERE school_id = ?
        ORDER BY COALESCE(payment_date, created_at) DESC, id DESC
        LIMIT 20
    ");

    $stmt->execute([$school_id]);

    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentPayments = [];
}

$paymentOptions = $recentPayments;

try {
    $stmt = $db->prepare(
        "SELECT
            s.id,
            s.school_payment_id,
            s.amount,
            s.transaction_reference,
            s.payment_message,
            s.payment_date,
            s.submitted_at,
            s.verification_status,
            s.verification_notes,
            sp.billing_period,
            sp.academic_year
         FROM school_payment_submissions s
         LEFT JOIN school_payments sp ON sp.id = s.school_payment_id
         WHERE s.school_id = ?
         ORDER BY s.submitted_at DESC, s.id DESC
         LIMIT 20"
    );
    $stmt->execute([$school_id]);
    $paymentSubmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paymentSubmissions = [];
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT se.student_id)
        FROM student_enrollments se
        WHERE se.school_id = ?
    ");

    $stmt->execute([$school_id]);

    $studentCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $studentCount = 0;
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM teachers
        WHERE school_id = ?
    ");

    $stmt->execute([$school_id]);

    $teacherCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $teacherCount = 0;
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT CONCAT(grade_level, '|', class_name))
        FROM student_enrollments
        WHERE school_id = ?
    ");

    $stmt->execute([$school_id]);

    $classCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    $classCount = 0;
}

try {
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT subject_id)
        FROM marks
        WHERE school_id = ?
    ");

    $stmt->execute([$school_id]);

    $subjectCount = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM subjects
            WHERE school_id = ?
        ");

        $stmt->execute([$school_id]);

        $subjectCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $subjectCount = 0;
    }
}

$schoolName = $school['school_name'] ?: 'School';
$schoolCode = $school['school_code'] ?: 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Headteacher Dashboard | <?= e($schoolName) ?></title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --primary: #1f4f82;
            --primary-light: #eef5fb;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --background: #f7f8fa;
            --white: #ffffff;
            --success: #237a57;
            --warning: #9a6700;
            --danger: #b42318;
            --shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;
            background: var(--background);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button {
            font: inherit;
        }

        .layout {
            min-height: 100vh;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 255px;
            background: var(--white);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 1000;
        }

        .brand {
            height: 76px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .brand-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.3px;
        }

        .brand-subtitle {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
            font-weight: 500;
        }

        .profile-mini {
            padding: 22px 20px;
            border-bottom: 1px solid var(--border);
        }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            flex-shrink: 0;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-role {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            flex: 1;
            overflow-y: auto;
        }

        .nav-label {
            padding: 0 12px 9px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #9ca3af;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            margin-bottom: 3px;
            border-radius: 7px;
            color: #4b5563;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .nav-link i {
            width: 18px;
            font-size: 16px;
        }

        .nav-link:hover {
            background: #f4f6f8;
            color: var(--primary);
        }

        .nav-link.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
        }

        .sidebar-footer {
            padding: 14px 12px;
            border-top: 1px solid var(--border);
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            color: #6b7280;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 500;
        }

        .logout-link:hover {
            background: #f8f8f8;
            color: var(--danger);
        }

        .main {
            margin-left: 255px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .topbar-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }

        .topbar-school {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .readonly-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 20px;
            color: var(--muted);
            background: var(--white);
            font-size: 11px;
            font-weight: 600;
        }

        .content {
            padding: 30px 32px 40px;
            max-width: 1500px;
            margin: 0 auto;
        }

        .welcome {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 28px 30px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .welcome-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 7px;
        }

        .welcome h1 {
            margin: 0;
            font-size: 26px;
            line-height: 1.25;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .welcome-school {
            margin-top: 8px;
            font-size: 14px;
            color: var(--muted);
        }

        .welcome-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 9px;
            margin-top: 18px;
        }

        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: #4b5563;
            font-size: 11px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 13px;
        }

        .section-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }

        .section-description {
            font-size: 12px;
            color: var(--muted);
            margin-top: 3px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
        }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 19px;
            box-shadow: var(--shadow);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
        }

        .stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 7px;
            background: #f5f7f9;
            color: #59636f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .stat-value {
            margin-top: 13px;
            font-size: 25px;
            line-height: 1;
            font-weight: 700;
            color: var(--text);
        }

        .payment-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
            gap: 18px;
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            box-shadow: var(--shadow);
        }

        .payment-summary {
            padding: 22px;
        }

        .payment-summary-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .payment-plan-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .payment-plan {
            font-size: 17px;
            font-weight: 700;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status.paid {
            background: #edf7f2;
            color: var(--success);
        }

        .status.partial {
            background: #fff8e6;
            color: var(--warning);
        }

        .status.unpaid {
            background: #fff1f0;
            color: var(--danger);
        }

        .status.neutral {
            background: #f3f4f6;
            color: #6b7280;
        }

        .payment-amounts {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 24px;
        }

        .amount-box {
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 7px;
        }

        .amount-label {
            font-size: 10px;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 16px;
            font-weight: 700;
        }

        .payment-progress {
            margin-top: 22px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 7px;
            font-size: 11px;
            color: var(--muted);
        }

        .progress-track {
            width: 100%;
            height: 7px;
            background: #edf0f2;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: var(--primary);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .payment-note {
            margin-top: 17px;
            font-size: 11px;
            color: var(--muted);
            line-height: 1.6;
        }

        .recent-card {
            overflow: hidden;
        }

        .card-heading {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .card-heading h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
        }

        .card-heading p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 11px;
        }

        .payment-table-wrap {
            overflow-x: auto;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 480px;
        }

        .payment-table th {
            text-align: left;
            padding: 11px 20px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #9ca3af;
            font-weight: 700;
            background: #fafafa;
            border-bottom: 1px solid var(--border);
        }

        .payment-table td {
            padding: 13px 20px;
            font-size: 11px;
            color: #4b5563;
            border-bottom: 1px solid #f0f1f2;
        }

        .payment-table tbody tr:last-child td {
            border-bottom: none;
        }

        .payment-table .amount {
            font-weight: 600;
            color: var(--text);
        }

        .payment-status {
            font-size: 10px;
            font-weight: 600;
        }

        .payment-status.paid {
            color: var(--success);
        }

        .payment-status.pending {
            color: var(--warning);
        }

        .payment-status.other {
            color: var(--muted);
        }

        .empty-table {
            padding: 35px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        .dashboard-alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
        }

        .dashboard-alert.success {
            color: #166534;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
        }

        .dashboard-alert.error {
            color: #b42318;
            background: #fff1f0;
            border: 1px solid #fecaca;
        }

        .payment-action-card {
            padding: 20px;
            margin-bottom: 18px;
        }

        .payment-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 15px;
        }

        .payment-form .full-width {
            grid-column: 1 / -1;
        }

        .payment-form label {
            display: block;
            margin-bottom: 6px;
            color: #4b5563;
            font-size: 11px;
            font-weight: 700;
        }

        .payment-form input,
        .payment-form select,
        .payment-form textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 9px 10px;
            color: var(--text);
            background: var(--white);
            font: inherit;
            font-size: 12px;
            outline: none;
        }

        .payment-form input,
        .payment-form select {
            height: 38px;
        }

        .payment-form textarea {
            min-height: 66px;
            resize: vertical;
        }

        .payment-form input:focus,
        .payment-form select:focus,
        .payment-form textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(31, 79, 130, .1);
        }

        .payment-form .submit-row {
            display: flex;
            align-items: flex-end;
        }

        .payment-submit {
            height: 38px;
            border: 0;
            border-radius: 6px;
            padding: 0 14px;
            color: #fff;
            background: var(--primary);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .payment-submit:hover {
            background: #163f69;
        }

        .submission-card {
            margin-top: 18px;
            overflow: hidden;
        }

        .submission-table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        .submission-table th,
        .submission-table td {
            padding: 11px 20px;
            border-bottom: 1px solid #f0f1f2;
            text-align: left;
            font-size: 11px;
        }

        .submission-table th {
            color: #9ca3af;
            background: #fafafa;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .verification-status {
            font-weight: 700;
            text-transform: capitalize;
        }

        .verification-status.pending { color: var(--warning); }
        .verification-status.verified { color: var(--success); }
        .verification-status.rejected { color: var(--danger); }

        .coming-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .coming-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .coming-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f5f7f9;
            color: #59636f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 17px;
        }

        .coming-card h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .coming-card p {
            margin: 8px 0 17px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .coming-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            background: #f3f4f6;
            color: #6b7280;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .coming-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 15px;
            color: var(--primary);
            font-size: 11px;
            font-weight: 600;
        }

        .coming-link:hover {
            text-decoration: underline;
        }

        .footer {
            border-top: 1px solid var(--border);
            margin-top: 35px;
            padding-top: 20px;
            color: #9ca3af;
            font-size: 10px;
            text-align: center;
        }

        .mobile-menu {
            display: none;
            border: none;
            background: transparent;
            color: var(--text);
            font-size: 22px;
            cursor: pointer;
            padding: 4px;
        }

        .sidebar-overlay {
            display: none;
        }

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .payment-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 850px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.2s ease;
                box-shadow: 8px 0 25px rgba(15, 23, 42, 0.08);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: block;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 24px 20px 35px;
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.25);
                z-index: 950;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }

        @media (max-width: 650px) {
            .topbar-title {
                font-size: 14px;
            }

            .readonly-badge {
                display: none;
            }

            .welcome {
                padding: 22px;
            }

            .welcome h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 22px;
            }

            .payment-amounts {
                grid-template-columns: 1fr;
            }

            .payment-form {
                grid-template-columns: 1fr 1fr;
            }

            .coming-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 430px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding-left: 14px;
                padding-right: 14px;
            }

            .topbar {
                padding-left: 14px;
                padding-right: 14px;
            }

            .payment-form {
                grid-template-columns: 1fr;
            }

            .payment-form .full-width {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<div class="layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">

        <div class="brand">
            <div>
                <div class="brand-name">EduLinCore</div>
                <span class="brand-subtitle">School Management</span>
            </div>
        </div>

        <div class="profile-mini">
            <div class="profile-row">
                <div class="avatar">
                    <?= e(strtoupper(substr(trim($headteacherName), 0, 1))) ?>
                </div>

                <div>
                    <div class="profile-name">
                        <?= e($headteacherName) ?>
                    </div>

                    <div class="profile-role">
                        Headteacher
                    </div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">

            <div class="nav-label">
                Dashboard
            </div>

            <a
                href="#overview"
                class="nav-link active"
                data-section="overview"
            >
                <i class="bi bi-grid-1x2"></i>
                <span>Overview</span>
            </a>

            <a
                href="<?= e($reportsRoute) ?>"
                class="nav-link"
            >
                <i class="bi bi-file-earmark-bar-graph"></i>
                <span>Dashboard Overview</span>
            </a>

            <a
                href="<?= e($performanceRoute) ?>"
                class="nav-link"
            >
                <i class="bi bi-graph-up"></i>
                <span>Account &amp; Payments</span>
            </a>

            <div class="nav-label" style="margin-top: 20px;">
                Account
            </div>

            <a
                href="#payments"
                class="nav-link"
                data-section="payments"
            >
                <i class="bi bi-credit-card"></i>
                <span>Account &amp; Payments</span>
            </a>

        </nav>

        <div class="sidebar-footer">
            <a
                href="<?= e(BASE_URL) ?>/auth/logout"
                class="logout-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Secure Logout</span>
            </a>
        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <div style="display:flex;align-items:center;gap:12px;">

                <button
                    type="button"
                    class="mobile-menu"
                    id="mobileMenu"
                    aria-label="Open navigation"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>
                    <div class="topbar-title">
                        Headteacher Dashboard
                    </div>

                    <div class="topbar-school">
                        <?= e($schoolName) ?>
                    </div>
                </div>

            </div>

            <div class="readonly-badge">
                <i class="bi bi-shield-check"></i>
                Client Portal
            </div>

        </header>

        <div class="content">

            <section id="overview" class="section">

                <div class="welcome">

                    <div class="welcome-label">
                        Welcome back
                    </div>

                    <h1>
                        <?= e($headteacherName) ?>
                    </h1>

                    <div class="welcome-school">
                        <?= e($schoolName) ?>
                    </div>

                    <div class="welcome-meta">

                        <div class="meta-item">
                            <i class="bi bi-building"></i>
                            School Code:
                            <strong><?= e($schoolCode) ?></strong>
                        </div>

                        <div class="meta-item">
                            <i class="bi bi-eye"></i>
                            Dashboard Access
                        </div>

                    </div>

                </div>

                <?php if ($dashboardMessage !== ''): ?>
                    <div class="dashboard-alert success">
                        <i class="bi bi-check-circle"></i>
                        <?= e($dashboardMessage) ?>
                    </div>
                <?php endif; ?>

                <?php if ($dashboardError !== ''): ?>
                    <div class="dashboard-alert error">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= e($dashboardError) ?>
                    </div>
                <?php endif; ?>

                <div class="section-header">
                    <div>
                        <h2 class="section-title">
                            Dashboard Overview
                        </h2>

                        <div class="section-description">
                            A quick view of the school's current records.
                        </div>
                    </div>
                </div>

                <div class="stats-grid">

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-label">
                                Students
                            </div>

                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>

                        <div class="stat-value">
                            <?= numberFormat($studentCount) ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-label">
                                Teachers
                            </div>

                            <div class="stat-icon">
                                <i class="bi bi-person-workspace"></i>
                            </div>
                        </div>

                        <div class="stat-value">
                            <?= numberFormat($teacherCount) ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-label">
                                Classes
                            </div>

                            <div class="stat-icon">
                                <i class="bi bi-collection"></i>
                            </div>
                        </div>

                        <div class="stat-value">
                            <?= numberFormat($classCount) ?>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-top">
                            <div class="stat-label">
                                Subjects
                            </div>

                            <div class="stat-icon">
                                <i class="bi bi-book"></i>
                            </div>
                        </div>

                        <div class="stat-value">
                            <?= numberFormat($subjectCount) ?>
                        </div>
                    </div>

                </div>

            </section>

            <section id="payments" class="section">

                <div class="section-header">
                    <div>
                        <h2 class="section-title">
                            Account &amp; Payments
                        </h2>

                        <div class="section-description">
                            Review invoices, submit payments, and track verification.
                        </div>
                    </div>
                </div>

                <div class="card payment-action-card">
                    <div class="card-heading" style="padding:0;border:0;">
                        <h3>Submit a Payment</h3>
                        <p>Send payment details to the administrator for verification.</p>
                    </div>

                    <form method="POST" class="payment-form">
                        <div>
                            <label for="schoolPaymentId">Invoice</label>
                            <select id="schoolPaymentId" name="school_payment_id">
                                <option value="0">General payment</option>
                                <?php foreach ($paymentOptions as $payment): ?>
                                    <option value="<?= (int)$payment['id'] ?>">
                                        INV-<?= e(date('Y', strtotime((string)$payment['created_at']))) ?>-<?= e(str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT)) ?>
                                        · <?= e($payment['billing_period'] ?? 'Billing') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="submissionAmount">Amount</label>
                            <input id="submissionAmount" name="amount" type="number" min="0.01" step="0.01" required>
                        </div>

                        <div>
                            <label for="transactionReference">Transaction Reference</label>
                            <input id="transactionReference" name="transaction_reference" required>
                        </div>

                        <div>
                            <label for="submissionDate">Payment Date</label>
                            <input id="submissionDate" name="payment_date" type="date" value="<?= e(date('Y-m-d')) ?>">
                        </div>

                        <div class="full-width">
                            <label for="paymentMessage">Message or Payment Notes</label>
                            <textarea id="paymentMessage" name="payment_message" maxlength="1000" placeholder="Add any payment details for the administrator."></textarea>
                        </div>

                        <div class="submit-row full-width">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="submit_payment">
                            <button type="submit" class="payment-submit">
                                <i class="bi bi-send"></i>
                                Submit for Verification
                            </button>
                        </div>
                    </form>
                </div>

                <div class="payment-layout">

                    <div class="card payment-summary">

                        <div class="payment-summary-top">

                            <div>
                                <div class="payment-plan-label">
                                    Current Plan
                                </div>

                                <div class="payment-plan">
                                    <?= e(
                                        $pricingPlan['plan_name']
                                        ?? 'No plan assigned'
                                    ) ?>
                                </div>
                            </div>

                            <?php
                            $statusClass = 'neutral';

                            if ($paymentStatus === 'Paid') {
                                $statusClass = 'paid';
                            } elseif ($paymentStatus === 'Partially Paid') {
                                $statusClass = 'partial';
                            } elseif ($paymentStatus === 'Unpaid') {
                                $statusClass = 'unpaid';
                            }
                            ?>

                            <span class="status <?= e($statusClass) ?>">
                                <?= e($paymentStatus) ?>
                            </span>

                        </div>

                        <div class="payment-amounts">

                            <div class="amount-box">
                                <div class="amount-label">
                                    Total Due
                                </div>

                                <div class="amount-value">
                                    K<?= numberFormat($totalDue, 2) ?>
                                </div>
                            </div>

                            <div class="amount-box">
                                <div class="amount-label">
                                    Amount Paid
                                </div>

                                <div class="amount-value">
                                    K<?= numberFormat($totalPaid, 2) ?>
                                </div>
                            </div>

                            <div class="amount-box">
                                <div class="amount-label">
                                    Balance
                                </div>

                                <div class="amount-value">
                                    K<?= numberFormat($balance, 2) ?>
                                </div>
                            </div>

                        </div>

                        <div class="payment-progress">

                            <div class="progress-header">
                                <span>Payment progress</span>
                                <strong><?= percentage($paymentPercentage) ?></strong>
                            </div>

                            <div class="progress-track">
                                <div
                                    class="progress-bar"
                                    style="width: <?= e((string) $paymentPercentage) ?>%;"
                                ></div>
                            </div>

                        </div>

                        <?php if ($pricingPlan): ?>

                            <div class="payment-note">
                                Billing period:
                                <strong>
                                    <?= e(
                                        $pricingPlan['billing_period']
                                        ?? 'Not specified'
                                    ) ?>
                                </strong>

                                <?php if (!empty($pricingPlan['extra_months'])): ?>
                                    &nbsp;·&nbsp;
                                    Additional months:
                                    <strong>
                                        <?= e($pricingPlan['extra_months']) ?>
                                    </strong>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>

                            <div class="payment-note">
                                No pricing plan is currently assigned to this school.
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="card recent-card">

                        <div class="card-heading">

                            <h3>
                                Recent Payments
                            </h3>

                            <p>
                                Latest payment records for this school.
                            </p>

                        </div>

                        <div class="payment-table-wrap">

                            <?php if (!empty($recentPayments)): ?>

                                <table class="payment-table">

                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Reference</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($recentPayments as $payment): ?>

                                            <?php
                                            $paymentRecordStatus = strtolower(
                                                trim((string) ($payment['payment_status'] ?? ''))
                                            );

                                            if (
                                                $paymentRecordStatus === 'paid' ||
                                                $paymentRecordStatus === 'completed'
                                            ) {
                                                $paymentStatusClass = 'paid';
                                            } elseif (
                                                $paymentRecordStatus === 'pending'
                                            ) {
                                                $paymentStatusClass = 'pending';
                                            } else {
                                                $paymentStatusClass = 'other';
                                            }
                                            ?>

                                            <tr>

                                                <td>
                                                    <?= e(
                                                        !empty($payment['payment_date'])
                                                            ? date(
                                                                'd M Y',
                                                                strtotime(
                                                                    (string) $payment['payment_date']
                                                                )
                                                            )
                                                            : '—'
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= e(
                                                        $payment['payment_reference'] ?? '—'
                                                    ) ?>
                                                </td>

                                                <td class="amount">
                                                    K<?= numberFormat(
                                                        $payment['amount_paid'] ?? 0,
                                                        2
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <span
                                                        class="payment-status <?= e($paymentStatusClass) ?>"
                                                    >
                                                        <?= e(
                                                            $payment['payment_status']
                                                            ?? '—'
                                                        ) ?>
                                                    </span>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            <?php else: ?>

                                <div class="empty-table">
                                    No payment records available.
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="card submission-card">
                    <div class="card-heading">
                        <h3>Payment Submission History</h3>
                        <p>Track verification results for submitted payment evidence.</p>
                    </div>

                    <?php if (empty($paymentSubmissions)): ?>
                        <div class="empty-table">No payment submissions available.</div>
                    <?php else: ?>
                        <div class="payment-table-wrap">
                            <table class="submission-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentSubmissions as $submission): ?>
                                        <?php
                                        $submissionStatus = strtolower((string)($submission['verification_status'] ?? 'pending'));
                                        $invoice = !empty($submission['school_payment_id'])
                                            ? 'INV-' . date('Y', strtotime((string)($submission['submitted_at']))) . '-' . str_pad((string)$submission['school_payment_id'], 6, '0', STR_PAD_LEFT)
                                            : 'General payment';
                                        ?>
                                        <tr>
                                            <td><?= e(date('d M Y', strtotime((string)$submission['submitted_at']))) ?></td>
                                            <td><?= e($invoice) ?></td>
                                            <td class="amount">K<?= numberFormat($submission['amount'] ?? 0, 2) ?></td>
                                            <td><?= e($submission['transaction_reference']) ?></td>
                                            <td><span class="verification-status <?= e($submissionStatus) ?>"><?= e($submissionStatus) ?></span></td>
                                            <td><?= e($submission['verification_notes'] ?: $submission['payment_message'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </section>

            <section class="section">

                <div class="section-header">
                    <div>
                        <h2 class="section-title">
                            Academic Tools
                        </h2>

                        <div class="section-description">
                            Additional academic management features will be available here.
                        </div>
                    </div>
                </div>

                <div class="coming-grid">

                    <div class="coming-card">

                        <div class="coming-icon">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                        </div>

                        <h3>
                            Dashboard Overview
                        </h3>

                        <p>
                            Academic reporting and report generation tools are currently
                            being prepared and will be available in a future release.
                        </p>

                        <span class="coming-badge">
                            Coming Soon
                        </span>

                        <br>

                        <a
                            href="<?= e($reportsRoute) ?>"
                            class="coming-link"
                        >
                            Return to Overview
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                    <div class="coming-card">

                        <div class="coming-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>

                        <h3>
                            Account &amp; Payments
                        </h3>

                        <p>
                            School performance summaries and related academic insights
                            will be introduced in a future release.
                        </p>

                        <span class="coming-badge">
                            Coming Soon
                        </span>

                        <br>

                        <a
                            href="<?= e($performanceRoute) ?>"
                            class="coming-link"
                        >
                            View Payments
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                </div>

            </section>

            <footer class="footer">
                <?= e($schoolName) ?> &middot; EduLinCore
            </footer>

        </div>

    </main>

</div>

<script>
    const mobileMenu = document.getElementById('mobileMenu');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('show');
    }

    if (mobileMenu) {
        mobileMenu.addEventListener('click', function () {
            sidebar.classList.add('open');
            sidebarOverlay.classList.add('show');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    document.querySelectorAll('.nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 850) {
                closeSidebar();
            }
        });
    });

    const inPageLinks = document.querySelectorAll(
        '.nav-link[data-section]'
    );

    inPageLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            inPageLinks.forEach(function (item) {
                item.classList.remove('active');
            });

            link.classList.add('active');
        });
    });

    let inactivityTimer;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);

        inactivityTimer = setTimeout(function () {
            window.location.href = <?= json_encode(BASE_URL . '/auth/logout') ?>;
        }, 15 * 60 * 1000);
    }

    [
        'click',
        'mousemove',
        'keypress',
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