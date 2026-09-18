<?php

declare(strict_types=1);

if (!defined('APP_PATH')) {
    define('APP_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'app');
}

if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443
        ? 'https'
        : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', rtrim($scheme . '://' . $host, '/'));
}

if (!class_exists('Database', false) && is_file(APP_PATH . '/core/Database.php')) {
    require_once APP_PATH . '/core/Database.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'school_it'
) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . rtrim($baseUrl, '/') . '/auth/school_login');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = class_exists('Database') ? Database::getConnection() : null;
}

$showPaymentInstructions = false;
$dashboardMessage = '';
$dashboardError = '';
$paymentSecurityToken = $_SESSION['payment_security_token'] ?? '';

if (empty($paymentSecurityToken)) {
    try {
        $paymentSecurityToken = bin2hex(random_bytes(32));
        $_SESSION['payment_security_token'] = $paymentSecurityToken;
    } catch (Throwable $e) {
        $paymentSecurityToken = hash('sha256', session_id() . '|payment');
        $_SESSION['payment_security_token'] = $paymentSecurityToken;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_payment_instructions'])) {
    $submittedToken = $_POST['payment_security_token'] ?? '';

    if (hash_equals($paymentSecurityToken, $submittedToken)) {
        $showPaymentInstructions = true;
    }
}

$schoolAccount = [
    'plan_name' => 'Not Assigned',
    'plan_code' => '',
    'price' => 0,
    'billing_period' => 'term',
    'extra_months' => 0,
    'amount_due' => 0,
    'amount_paid' => 0,
    'balance' => 0,
    'payment_status' => 'Not Assigned',
    'payment_date' => null,
    'payment_reference' => null,
    'payment_count' => 0,
    'pending_submissions' => 0
];

$paymentHistory = [];
$paymentSubmissions = [];

$schoolId = 0;

if (isset($school_data) && is_array($school_data) && isset($school_data['id'])) {
    $schoolId = (int) $school_data['id'];
}

if ($schoolId <= 0 && isset($_SESSION['school_id'])) {
    $schoolId = (int) $_SESSION['school_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment']) && $schoolId > 0) {
    $submittedToken = $_POST['payment_security_token'] ?? '';

    if (!hash_equals($paymentSecurityToken, $submittedToken)) {
        $dashboardError = 'The payment security token has expired. Please try again.';
    } else {
        try {
            $submittedAmount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
            $transactionReference = trim((string)($_POST['transaction_reference'] ?? ''));
            $paymentMessage = trim((string)($_POST['payment_message'] ?? ''));
            $paymentDate = trim((string)($_POST['payment_date'] ?? ''));

            if ($submittedAmount === false || $submittedAmount <= 0 || $transactionReference === '') {
                throw new InvalidArgumentException('Enter a valid amount and transaction reference.');
            }

            $paymentSubmission = $pdo->prepare(
                "INSERT INTO school_payment_submissions (
                    school_id, school_payment_id, amount, transaction_reference,
                    payment_message, payment_date, submitted_by, submitted_role,
                    submitted_at, verification_status
                ) VALUES (?, NULLIF(?, 0), ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, 0), 'school_it', NOW(), 'pending')"
            );
            $paymentSubmission->execute([
                $schoolId,
                (int)($_POST['school_payment_id'] ?? 0),
                $submittedAmount,
                $transactionReference,
                $paymentMessage,
                $paymentDate,
                (int)($_SESSION['user_id'] ?? 0)
            ]);

            $dashboardMessage = 'Payment submission sent for administrator verification.';
        } catch (Throwable $e) {
            $dashboardError = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Unable to submit the payment. Please try again.';
        }
    }
}

if ($schoolId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $planStmt = $pdo->prepare("
            SELECT
                p.id,
                p.plan_name,
                p.plan_code,
                p.price,
                p.billing_period,
                p.extra_months
            FROM schools s
            LEFT JOIN pricing_plans p
                ON p.id = COALESCE(
                    s.pricing_plan_id,
                    (
                        SELECT sp.pricing_plan_id
                        FROM school_payments sp
                        WHERE sp.school_id = s.id
                          AND sp.pricing_plan_id IS NOT NULL
                        ORDER BY sp.created_at DESC, sp.id DESC
                        LIMIT 1
                    )
                )
            WHERE s.id = ?
              AND COALESCE(s.is_deleted, 0) = 0
            LIMIT 1
        ");

        $planStmt->execute([$schoolId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

        if ($plan && !empty($plan['id'])) {
            $schoolAccount['plan_name'] = $plan['plan_name'] ?? 'Not Assigned';
            $schoolAccount['plan_code'] = $plan['plan_code'] ?? '';
            $schoolAccount['price'] = (float) ($plan['price'] ?? 0);
            $schoolAccount['billing_period'] = $plan['billing_period'] ?? 'term';
            $schoolAccount['extra_months'] = (int) ($plan['extra_months'] ?? 0);
        }

        $paymentStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(amount_due), 0) AS total_due,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                MAX(COALESCE(payment_date, created_at)) AS latest_payment_date,
                COUNT(*) AS payment_count
            FROM school_payments
            WHERE school_id = ?
        ");

        $paymentStmt->execute([$schoolId]);
        $paymentData = $paymentStmt->fetch(PDO::FETCH_ASSOC);

        if ($paymentData) {
            $recordedDue = (float) ($paymentData['total_due'] ?? 0);
            $recordedPaid = (float) ($paymentData['total_paid'] ?? 0);

            $schoolAccount['amount_due'] = $schoolAccount['price'] > 0
                ? $schoolAccount['price']
                : $recordedDue;

            $schoolAccount['amount_paid'] = $recordedPaid;

            $schoolAccount['payment_date'] =
                $paymentData['latest_payment_date'] ?? null;
            $schoolAccount['payment_count'] = (int)($paymentData['payment_count'] ?? 0);
        } else {
            $schoolAccount['amount_due'] = $schoolAccount['price'];
        }

        $schoolAccount['balance'] = max(
            0,
            $schoolAccount['amount_due'] - $schoolAccount['amount_paid']
        );

        if ($schoolAccount['amount_due'] <= 0) {
            $schoolAccount['payment_status'] = 'Not Assigned';
        } elseif ($schoolAccount['balance'] <= 0) {
            $schoolAccount['payment_status'] = 'Paid';
        } elseif ($schoolAccount['amount_paid'] > 0) {
            $schoolAccount['payment_status'] = 'Partially Paid';
        } else {
            $schoolAccount['payment_status'] = 'Unpaid';
        }
    } catch (Throwable $e) {
        $schoolAccount['payment_status'] = 'Unavailable';
    }
}

if ($schoolId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $paymentHistoryStmt = $pdo->prepare(
            "SELECT
                id, billing_period, academic_year, term_name, amount_due, amount_paid,
                payment_status, payment_date, payment_method, payment_reference, notes, created_at
             FROM school_payments
             WHERE school_id = ?
             ORDER BY COALESCE(payment_date, created_at) DESC, id DESC
             LIMIT 20"
        );
        $paymentHistoryStmt->execute([$schoolId]);
        $paymentHistory = $paymentHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $paymentHistory = [];
    }

    try {
        $submissionStmt = $pdo->prepare(
            "SELECT
                s.amount, s.transaction_reference, s.payment_date, s.submitted_at,
                s.verification_status, s.verification_notes, s.payment_message,
                s.school_payment_id
             FROM school_payment_submissions s
             WHERE s.school_id = ?
             ORDER BY s.submitted_at DESC, s.id DESC
             LIMIT 20"
        );
        $submissionStmt->execute([$schoolId]);
        $paymentSubmissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);
        $schoolAccount['pending_submissions'] = count(array_filter(
            $paymentSubmissions,
            static fn(array $submission): bool => ($submission['verification_status'] ?? 'pending') === 'pending'
        ));
    } catch (Throwable $e) {
        $paymentSubmissions = [];
    }
}

$accountStatusClass = 'none';

if ($schoolAccount['payment_status'] === 'Paid') {
    $accountStatusClass = 'paid';
} elseif ($schoolAccount['payment_status'] === 'Partially Paid') {
    $accountStatusClass = 'partial';
} elseif ($schoolAccount['payment_status'] === 'Unpaid') {
    $accountStatusClass = 'unpaid';
}

$schoolName = 'School System';

if (isset($school_data) && is_array($school_data) && isset($school_data['school_name']) && $school_data['school_name'] !== '') {
    $schoolName = (string) $school_data['school_name'];
} elseif (isset($_SESSION['school_name']) && $_SESSION['school_name'] !== '') {
    $schoolName = (string) $_SESSION['school_name'];
}

$userName = $_SESSION['user_name'] ?? 'IT Officer';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>IT Officer Dashboard - EduLinCore</title>

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

        .dashboard-grid-split {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.25rem;
        }

        .dashboard-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .dashboard-card-header {
            padding: 1rem 1.25rem;
            font-weight: 700;
            font-size: 1rem;
            border-bottom: 1px solid var(--border-light);
            background-color: #f8fafc;
        }

        .dashboard-card-body {
            padding: 1.25rem;
        }

        .feature-box {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--border-light);
            border-radius: 0.5rem;
            background-color: #ffffff;
            transition: all 0.2s ease-in-out;
        }

        .feature-box:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.05);
            transform: translateY(-2px);
        }

        .feature-icon {
            width: 42px;
            height: 42px;
            border-radius: 0.5rem;
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .badge-pill-success {
            background-color: rgba(22, 163, 74, 0.1);
            color: var(--success);
        }

        .mockup-footer {
            padding: 1rem;
            background-color: var(--bg-card);
            border-top: 1px solid var(--border-light);
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-muted);
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
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary) !important;
            font-weight: 700 !important;
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

        .school-account-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.25rem;
            overflow: hidden;
        }

        .school-account-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.65rem 1rem;
            border-bottom: 1px solid var(--border-light);
            background: #f8fafc;
        }

        .school-account-title {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .school-account-title-icon {
            width: 32px;
            height: 32px;
            border-radius: 0.45rem;
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        .school-account-title h2 {
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
        }

        .school-account-title p {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }

        .school-account-status {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 800;
        }

        .school-account-status.paid {
            background: rgba(22, 163, 74, 0.1);
            color: #16a34a;
        }

        .school-account-status.partial {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }

        .school-account-status.unpaid {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
        }

        .school-account-status.none {
            background: rgba(100, 116, 139, 0.1);
            color: #64748b;
        }

        .school-account-body {
            padding: 0.7rem 1rem;
        }

        .school-account-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.65rem;
        }

        .school-account-item {
            border: 1px solid var(--border-light);
            border-radius: 0.5rem;
            padding: 0.6rem 0.75rem;
            background: #ffffff;
        }

        .school-account-label {
            display: block;
            font-size: 0.62rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .school-account-value {
            display: block;
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.2;
        }

        .school-account-value.balance {
            color: #dc2626;
        }

        .school-account-details {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.25rem;
            margin-top: 0.65rem;
            padding-top: 0.6rem;
            border-top: 1px solid var(--border-light);
        }

        .school-account-detail {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .school-account-detail strong {
            color: var(--text-main);
            font-weight: 700;
        }

        .school-account-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: auto;
        }

        .make-payment-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            border: 1px solid var(--primary);
            border-radius: 0.45rem;
            background: var(--primary);
            color: #ffffff;
            padding: 0.42rem 0.7rem;
            font-family: inherit;
            font-size: 0.68rem;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .make-payment-button:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .payment-modal {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.55);
        }

        .payment-modal[hidden] {
            display: none;
        }

        .payment-modal-card {
            width: min(430px, 100%);
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 0.8rem;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.2);
            overflow: hidden;
        }

        .payment-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border-light);
            background: #f8fafc;
        }

        .payment-modal-header h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .payment-modal-close {
            width: 30px;
            height: 30px;
            border: 0;
            border-radius: 0.4rem;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.95rem;
        }

        .payment-modal-close:hover {
            background: rgba(100, 116, 139, 0.1);
            color: var(--text-main);
        }

        .payment-modal-body {
            padding: 1rem;
        }

        .payment-modal-note {
            margin-bottom: 0.85rem;
            color: var(--text-muted);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        .payment-number-box {
            padding: 0.85rem;
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 0.55rem;
            background: rgba(37, 99, 235, 0.05);
            text-align: center;
        }

        .payment-number-label {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--text-muted);
            font-size: 0.62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .payment-number {
            display: block;
            color: var(--text-main);
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.04em;
        }

        .payment-modal-instructions {
            margin-top: 0.85rem;
            padding-left: 1.1rem;
            color: var(--text-main);
            font-size: 0.72rem;
            line-height: 1.55;
        }

        .payment-modal-instructions li + li {
            margin-top: 0.35rem;
        }

        .payment-alert {
            margin: 0 1rem 0.8rem;
            padding: 0.65rem 0.8rem;
            border-radius: 0.45rem;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .payment-alert.success {
            color: #166534;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
        }

        .payment-alert.error {
            color: #b42318;
            background: #fff1f0;
            border: 1px solid #fecaca;
        }

        .payment-submit-panel {
            margin-top: 0.8rem;
            padding-top: 0.8rem;
            border-top: 1px solid var(--border-light);
        }

        .payment-submit-panel h3 {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-main);
        }

        .payment-submit-panel p {
            margin: 0.25rem 0 0.7rem;
            color: var(--text-muted);
            font-size: 0.68rem;
        }

        .payment-submit-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.6rem;
        }

        .payment-submit-form .full-width {
            grid-column: 1 / -1;
        }

        .payment-submit-form label {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--text-muted);
            font-size: 0.62rem;
            font-weight: 700;
        }

        .payment-submit-form input,
        .payment-submit-form select,
        .payment-submit-form textarea {
            width: 100%;
            border: 1px solid var(--border-light);
            border-radius: 0.4rem;
            padding: 0.48rem 0.55rem;
            color: var(--text-main);
            background: #fff;
            font: inherit;
            font-size: 0.68rem;
            outline: none;
        }

        .payment-submit-form input,
        .payment-submit-form select {
            height: 32px;
        }

        .payment-submit-form textarea {
            min-height: 52px;
            resize: vertical;
        }

        .payment-submit-form input:focus,
        .payment-submit-form select:focus,
        .payment-submit-form textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        .payment-submit-form button {
            height: 32px;
            border: 0;
            border-radius: 0.4rem;
            padding: 0 0.7rem;
            color: #fff;
            background: var(--primary);
            font-size: 0.68rem;
            font-weight: 800;
            cursor: pointer;
        }

        .payment-history {
            margin-top: 0.8rem;
            overflow-x: auto;
        }

        .payment-history table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .payment-history th,
        .payment-history td {
            padding: 0.55rem;
            border-bottom: 1px solid #f0f1f2;
            text-align: left;
            font-size: 0.65rem;
        }

        .payment-history th {
            color: var(--text-muted);
            background: #fafafa;
            font-size: 0.58rem;
            text-transform: uppercase;
        }

        .history-status {
            font-weight: 800;
            text-transform: capitalize;
        }

        .history-status.paid,
        .history-status.verified { color: var(--success); }
        .history-status.pending,
        .history-status.partial { color: #d97706; }
        .history-status.overdue,
        .history-status.rejected { color: var(--danger); }

        @media (max-width: 900px) {
            .school-account-grid {
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
                box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            }

            .app-sidebar.sidebar-open {
                left: 0;
            }

            body.menu-open .sidebar-overlay {
                display: block;
            }

            .dashboard-grid-split {
                grid-template-columns: 1fr;
            }

            .dashboard-main-content {
                padding: 1rem;
            }
        }

        @media (max-width: 600px) {
            .school-account-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 0.5rem;
            }

            .school-account-actions {
                width: 100%;
                justify-content: space-between;
                margin-left: 0;
            }

            .school-account-actions form {
                margin-left: auto;
            }

            .school-account-grid {
                grid-template-columns: 1fr;
            }

            .school-account-details {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.45rem;
            }

            .payment-submit-form {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 430px) {
            .payment-submit-form {
                grid-template-columns: 1fr;
            }

            .payment-submit-form .full-width {
                grid-column: auto;
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

        <div
            style="
                padding: 1rem;
                border-bottom: 1px solid var(--border-light);
                display: flex;
                align-items: center;
                gap: 0.75rem;
                background: var(--bg-body);
            "
        >
            <img
                src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png"
                alt="Profile"
                style="width: 38px; height: 38px; border-radius: 50%;"
            >

            <div style="overflow: hidden;">
                <div
                    style="
                        font-weight: 700;
                        font-size: 0.85rem;
                        color: var(--text-main);
                    "
                >
                    <?= htmlspecialchars($userName) ?>
                </div>

                <div
                    style="
                        font-size: 0.7rem;
                        color: var(--text-muted);
                        font-weight: 700;
                        text-transform: uppercase;
                    "
                >
                    School IT Officer
                </div>
            </div>
        </div>

        <nav
            style="
                padding: 1rem;
                display: flex;
                flex-direction: column;
                gap: 0.35rem;
                flex: 1;
                overflow-y: auto;
            "
        >

            <a
                href="<?= BASE_URL ?>/it/dashboard"
                class="nav-main-link"
                data-path="/it/dashboard"
                style="
                    padding: 0.65rem 0.85rem;
                    border-radius: 0.5rem;
                    color: var(--text-muted);
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 0.85rem;
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                "
            >
                <i class="fa-solid fa-house" style="width: 16px;"></i>
                Dashboard
            </a>

            <div class="nav-group" style="margin-top: 0.5rem;">

                <div
                    class="nav-dropdown-toggle"
                    onclick="toggleSubMenu(this)"
                    style="
                        padding: 0.55rem 0.85rem;
                        font-size: 0.75rem;
                        font-weight: 700;
                        color: var(--text-muted);
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        border-radius: 0.5rem;
                        background: rgba(0,0,0,0.02);
                    "
                >
                    <span>Students</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="nav-submenu">

                    <a
                        href="<?= BASE_URL ?>/it/students/enroll"
                        data-path="/it/students/enroll"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-user-plus"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Enroll New Student
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/students/management"
                        data-path="/it/students/management"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-users-gear"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Manage Students
                    </a>

                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">

                <div
                    class="nav-dropdown-toggle"
                    onclick="toggleSubMenu(this)"
                    style="
                        padding: 0.55rem 0.85rem;
                        font-size: 0.75rem;
                        font-weight: 700;
                        color: var(--text-muted);
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        border-radius: 0.5rem;
                        background: rgba(0,0,0,0.02);
                    "
                >
                    <span>Teachers</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="nav-submenu">

                    <a
                        href="<?= BASE_URL ?>/it/teachers/register"
                        data-path="/it/teachers/register"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-user-tie"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Add New Teacher
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/teachers/manage"
                        data-path="/it/teachers/manage"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-chalkboard-user"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Manage Teachers
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/teachers/assignments"
                        data-path="/it/teachers/assignments"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-book-bookmark"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Teacher Assignments
                    </a>

                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">

                <div
                    class="nav-dropdown-toggle"
                    onclick="toggleSubMenu(this)"
                    style="
                        padding: 0.55rem 0.85rem;
                        font-size: 0.75rem;
                        font-weight: 700;
                        color: var(--text-muted);
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        border-radius: 0.5rem;
                        background: rgba(0,0,0,0.02);
                    "
                >
                    <span>School Setup</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="nav-submenu">

                    <a
                        href="<?= BASE_URL ?>/it/setup/grades"
                        data-path="/it/setup/grades"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-layer-group"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Manage Grade Levels
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/setup/classes"
                        data-path="/it/setup/classes"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-school-flag"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Manage Classes
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/setup/subjects"
                        data-path="/it/setup/subjects"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-book"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Manage Subjects
                    </a>

                </div>
            </div>

            <div class="nav-group" style="margin-top: 0.5rem;">

                <div
                    class="nav-dropdown-toggle"
                    onclick="toggleSubMenu(this)"
                    style="
                        padding: 0.55rem 0.85rem;
                        font-size: 0.75rem;
                        font-weight: 700;
                        color: var(--text-muted);
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        border-radius: 0.5rem;
                        background: rgba(0,0,0,0.02);
                    "
                >
                    <span>Exams & Reports</span>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>

                <div class="nav-submenu">

                    <a
                        href="<?= BASE_URL ?>/it/reports/cards"
                        data-path="/it/reports/cards"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-file-invoice"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Generate Report Cards
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/reports/scoresheets"
                        data-path="/it/reports/scoresheets"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-table-cells"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Generate Score Sheets
                    </a>

                    <a
                        href="<?= BASE_URL ?>/it/reports/analysis"
                        data-path="/it/reports/analysis"
                        style="
                            padding: 0.55rem 0.85rem 0.55rem 1.5rem;
                            border-radius: 0.5rem;
                            color: var(--text-muted);
                            text-decoration: none;
                            font-weight: 600;
                            font-size: 0.8rem;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        "
                    >
                        <i
                            class="fa-solid fa-chart-pie"
                            style="width: 14px; font-size: 0.75rem;"
                        ></i>
                        Generate Class Analysis
                    </a>

                </div>
            </div>

            <a
                href="<?= BASE_URL ?>/auth/logout"
                style="
                    padding: 0.65rem 0.85rem;
                    border-radius: 0.5rem;
                    color: #dc2626;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 0.85rem;
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    margin-top: 1rem;
                "
            >
                <i class="fa-solid fa-power-off" style="width: 16px;"></i>
                Secure Logout
            </a>

        </nav>
    </aside>

    <div class="app-main-area">

        <header class="mockup-header">

            <div style="display: flex; align-items: center; gap: 0.75rem;">

                <button
                    class="mobile-nav-toggle"
                    id="sidebarToggle"
                    aria-label="Toggle Navigation"
                >
                    <i class="fa-solid fa-bars"></i>
                </button>

                <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/it/dashboard" class="brand-logo">
                    EduLinCore IT Portal
                </a>

            </div>

            <nav
                class="mockup-nav"
                style="
                    display: flex;
                    align-items: center;
                    gap: 1rem;
                "
            >
                <span
                    style="
                        color: #ffffff;
                        font-weight: 600;
                        font-size: 0.85rem;
                    "
                >
                    <i class="fa-solid fa-school"></i>
                    <?= htmlspecialchars($schoolName) ?>
                </span>
            </nav>

        </header>

        <div class="dashboard-main-content">

            <div style="margin-bottom: 1.25rem;">

                <h1
                    class="section-title"
                    style="
                        font-size: 1.6rem;
                        margin-bottom: 0.35rem;
                    "
                >
                    System Overview & Metrics
                </h1>

                <p
                    class="section-desc"
                    style="
                        font-size: 0.9rem;
                        color: var(--text-muted);
                    "
                >
                    Welcome back! Here is a summary of quick management
                    utilities and system configurations available for your
                    school setup.
                </p>

            </div>

            <div class="school-account-card">

                <div class="school-account-header">

                    <div class="school-account-title">

                        <div class="school-account-title-icon">
                            <i class="fa-solid fa-wallet"></i>
                        </div>

                        <div>
                            <h2>School Account</h2>

                            <p>
                                Current plan and payment information
                            </p>
                        </div>

                    </div>

                    <div class="school-account-actions">
                        <span
                            class="school-account-status <?= htmlspecialchars($accountStatusClass) ?>"
                        >
                            <?= htmlspecialchars($schoolAccount['payment_status']) ?>
                        </span>

                        <?php if ($schoolAccount['payment_status'] !== 'Paid'): ?>
                            <form method="post" action="">
                                <input
                                    type="hidden"
                                    name="payment_security_token"
                                    value="<?= htmlspecialchars($paymentSecurityToken, ENT_QUOTES, 'UTF-8') ?>"
                                >
                                <button
                                    type="submit"
                                    name="open_payment_instructions"
                                    value="1"
                                    class="make-payment-button"
                                >
                                    <i class="fa-solid fa-mobile-screen-button"></i>
                                    Make Payment
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="school-account-body">

                    <?php if ($dashboardMessage !== ''): ?>
                        <div class="payment-alert success">
                            <i class="fa-solid fa-circle-check"></i>
                            <?= htmlspecialchars($dashboardMessage, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($dashboardError !== ''): ?>
                        <div class="payment-alert error">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <div class="school-account-grid">

                        <div class="school-account-item">
                            <span class="school-account-label">
                                Amount Due
                            </span>

                            <span class="school-account-value">
                                K<?= number_format(
                                    $schoolAccount['amount_due'],
                                    2
                                ) ?>
                            </span>
                        </div>

                        <div class="school-account-item">
                            <span class="school-account-label">
                                Amount Paid
                            </span>

                            <span class="school-account-value">
                                K<?= number_format(
                                    $schoolAccount['amount_paid'],
                                    2
                                ) ?>
                            </span>
                        </div>

                        <div class="school-account-item">
                            <span class="school-account-label">
                                Outstanding Balance
                            </span>

                            <span class="school-account-value balance">
                                K<?= number_format(
                                    $schoolAccount['balance'],
                                    2
                                ) ?>
                            </span>
                        </div>

                        <div class="school-account-item">
                            <span class="school-account-label">
                                Current Plan
                            </span>

                            <span class="school-account-value">
                                <?= htmlspecialchars(
                                    $schoolAccount['plan_name']
                                ) ?>
                            </span>
                        </div>

                    </div>

                    <div class="school-account-details">

                        <div class="school-account-detail">
                            <strong>Plan Charge:</strong>
                            K<?= number_format(
                                $schoolAccount['price'],
                                2
                            ) ?>
                        </div>

                        <?php if (!empty($schoolAccount['plan_code'])): ?>
                            <div class="school-account-detail">
                                <strong>Plan Code:</strong>
                                <?= htmlspecialchars($schoolAccount['plan_code'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <div class="school-account-detail">
                            <strong>Billing:</strong>
                            <?= htmlspecialchars(
                                ucfirst($schoolAccount['billing_period'])
                            ) ?>
                        </div>

                        <?php if ($schoolAccount['extra_months'] > 0): ?>

                            <div class="school-account-detail">
                                <strong>Extra:</strong>
                                <?= (int) $schoolAccount['extra_months'] ?>
                                <?= $schoolAccount['extra_months'] === 1
                                    ? 'month'
                                    : 'months' ?>
                            </div>

                        <?php endif; ?>

                        <?php if (!empty($schoolAccount['payment_date'])): ?>

                            <div class="school-account-detail">
                                <strong>Last Payment:</strong>
                                <?= htmlspecialchars(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            $schoolAccount['payment_date']
                                        )
                                    )
                                ) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="payment-submit-panel">
                        <h3>Submit Payment Evidence</h3>
                        <p>Record a transaction so an administrator can verify it against the school account.</p>

                        <form method="post" action="" class="payment-submit-form">
                            <div>
                                <label for="schoolPaymentId">Invoice</label>
                                <select id="schoolPaymentId" name="school_payment_id">
                                    <option value="0">General payment</option>
                                    <?php foreach ($paymentHistory as $payment): ?>
                                        <option value="<?= (int)$payment['id'] ?>">
                                            INV-<?= htmlspecialchars(date('Y', strtotime((string)$payment['created_at'])), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?>
                                            · <?= htmlspecialchars($payment['billing_period'] ?? 'Billing', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="itPaymentAmount">Amount</label>
                                <input id="itPaymentAmount" name="amount" type="number" min="0.01" step="0.01" required>
                            </div>

                            <div>
                                <label for="itTransactionReference">Transaction Reference</label>
                                <input id="itTransactionReference" name="transaction_reference" required>
                            </div>

                            <div>
                                <label for="itPaymentDate">Payment Date</label>
                                <input id="itPaymentDate" name="payment_date" type="date" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                            </div>

                            <div class="full-width">
                                <label for="itPaymentMessage">Message or Notes</label>
                                <textarea id="itPaymentMessage" name="payment_message" maxlength="1000" placeholder="Optional payment details"></textarea>
                            </div>

                            <div class="full-width">
                                <input type="hidden" name="payment_security_token" value="<?= htmlspecialchars($paymentSecurityToken, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" name="submit_payment" value="1">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    Submit for Verification
                                </button>
                            </div>
                        </form>
                    </div>

                </div>

            </div>

            <?php if ($showPaymentInstructions): ?>
                <div
                    class="payment-modal"
                    id="paymentModal"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="paymentModalTitle"
                >
                    <div class="payment-modal-card">
                        <div class="payment-modal-header">
                            <h3 id="paymentModalTitle">Make Payment</h3>
                            <button
                                type="button"
                                class="payment-modal-close"
                                id="paymentModalClose"
                                aria-label="Close payment instructions"
                            >
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <div class="payment-modal-body">
                            <p class="payment-modal-note">
                                Use the Mobile Money number below to make the school payment.
                            </p>

                            <div class="payment-number-box">
                                <span class="payment-number-label">Mobile Money Number</span>
                                <span class="payment-number">0977323918</span>
                            </div>

                            <ol class="payment-modal-instructions">
                                <li>Make the payment using the amount shown on the School Account.</li>
                                <li>Keep the Mobile Money transaction reference or confirmation message.</li>
                                <li>Submit the transaction reference to the same number and wait for confirmation message</li>
                                <li>The payment remains unconfirmed until it has been verified by the system admin.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div
                class="dashboard-grid-split"
                style="margin-bottom: 2rem;"
            >

                <div class="dashboard-card">

                    <div class="dashboard-card-header">
                        <span>Quick Management Utilities</span>
                    </div>

                    <div class="dashboard-card-body">

                        <div
                            style="
                                display: grid;
                                grid-template-columns:
                                    repeat(
                                        auto-fit,
                                        minmax(220px, 1fr)
                                    );
                                gap: 1rem;
                            "
                        >

                            <a
                                href="<?= BASE_URL ?>/it/students/enroll"
                                class="feature-box"
                                style="
                                    text-decoration: none;
                                    color: inherit;
                                "
                            >

                                <div class="feature-icon">
                                    <i class="fa-solid fa-user-plus"></i>
                                </div>

                                <div>

                                    <div
                                        style="
                                            font-weight: 700;
                                            font-size: 0.95rem;
                                            color: var(--text-main);
                                        "
                                    >
                                        Enroll Student
                                    </div>

                                    <div
                                        style="
                                            font-size: 0.8rem;
                                            color: var(--text-muted);
                                            margin-top: 0.15rem;
                                        "
                                    >
                                        Register a new student record
                                    </div>

                                </div>

                            </a>

                            <a
                                href="<?= BASE_URL ?>/it/teachers/register"
                                class="feature-box"
                                style="
                                    text-decoration: none;
                                    color: inherit;
                                "
                            >

                                <div
                                    class="feature-icon"
                                    style="
                                        background:
                                            rgba(79, 70, 229, 0.08);
                                        color: #4f46e5;
                                    "
                                >
                                    <i class="fa-solid fa-user-tie"></i>
                                </div>

                                <div>

                                    <div
                                        style="
                                            font-weight: 700;
                                            font-size: 0.95rem;
                                            color: var(--text-main);
                                        "
                                    >
                                        Add Teacher
                                    </div>

                                    <div
                                        style="
                                            font-size: 0.8rem;
                                            color: var(--text-muted);
                                            margin-top: 0.15rem;
                                        "
                                    >
                                        Register and configure faculty access
                                    </div>

                                </div>

                            </a>

                            <a
                                href="<?= BASE_URL ?>/it/teachers/assignments"
                                class="feature-box"
                                style="
                                    text-decoration: none;
                                    color: inherit;
                                "
                            >

                                <div
                                    class="feature-icon"
                                    style="
                                        background:
                                            rgba(22, 163, 74, 0.08);
                                        color: #16a34a;
                                    "
                                >
                                    <i class="fa-solid fa-book-bookmark"></i>
                                </div>

                                <div>

                                    <div
                                        style="
                                            font-weight: 700;
                                            font-size: 0.95rem;
                                            color: var(--text-main);
                                        "
                                    >
                                        Subject Assignments
                                    </div>

                                    <div
                                        style="
                                            font-size: 0.8rem;
                                            color: var(--text-muted);
                                            margin-top: 0.15rem;
                                        "
                                    >
                                        Assign subjects to teachers
                                    </div>

                                </div>

                            </a>

                        </div>

                    </div>

                </div>

                <div class="dashboard-card">

                    <div class="dashboard-card-header">
                        <span>Environment Specs</span>
                    </div>

                    <div
                        class="dashboard-card-body"
                        style="
                            font-size: 0.85rem;
                            display: flex;
                            flex-direction: column;
                            gap: 0.75rem;
                        "
                    >

                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                border-bottom:
                                    1px solid var(--border-light);
                                padding-bottom: 0.5rem;
                            "
                        >
                            <span
                                style="
                                    color: var(--text-muted);
                                    font-weight: 600;
                                "
                            >
                                Framework Version
                            </span>

                            <span
                                style="
                                    font-weight: 700;
                                    color: var(--text-main);
                                "
                            >
                                EduLinCore v2.6
                            </span>
                        </div>

                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                border-bottom:
                                    1px solid var(--border-light);
                                padding-bottom: 0.5rem;
                            "
                        >
                            <span
                                style="
                                    color: var(--text-muted);
                                    font-weight: 600;
                                "
                            >
                                Database Engine
                            </span>

                            <span
                                style="
                                    font-weight: 700;
                                    color: var(--text-main);
                                "
                            >
                                MySQL PDO
                            </span>
                        </div>

                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                border-bottom:
                                    1px solid var(--border-light);
                                padding-bottom: 0.5rem;
                            "
                        >
                            <span
                                style="
                                    color: var(--text-muted);
                                    font-weight: 600;
                                "
                            >
                                Active Session
                            </span>

                            <span class="badge-pill badge-pill-success">
                                Secure
                            </span>
                        </div>

                        <div
                            style="
                                display: flex;
                                justify-content: space-between;
                                padding-top: 0.25rem;
                            "
                        >
                            <span
                                style="
                                    color: var(--text-muted);
                                    font-weight: 600;
                                "
                            >
                                Server Time
                            </span>

                            <span
                                style="
                                    font-weight: 700;
                                    color: var(--text-main);
                                "
                            >
                                <?= date('Y-m-d H:i') ?>
                            </span>
                        </div>

                    </div>

                </div>

            </div>

            <div class="dashboard-card" style="margin-bottom: 1.25rem;">
                <div class="dashboard-card-header">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    Invoice and Payment History
                </div>
                <div class="dashboard-card-body" style="padding:0;">
                    <?php if (empty($paymentHistory) && empty($paymentSubmissions)): ?>
                        <div style="padding:1.25rem;color:var(--text-muted);font-size:.75rem;">No invoice or payment history is available.</div>
                    <?php else: ?>
                        <div class="payment-history">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Billing Period</th>
                                        <th>Due</th>
                                        <th>Paid</th>
                                        <th>Status</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentHistory as $payment): ?>
                                        <?php $historyStatus = strtolower((string)($payment['payment_status'] ?? 'pending')); ?>
                                        <tr>
                                            <td>INV-<?= htmlspecialchars(date('Y', strtotime((string)$payment['created_at'])), ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars(str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($payment['billing_period'] ?? '—', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($payment['academic_year'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>K<?= number_format((float)($payment['amount_due'] ?? 0), 2) ?></td>
                                            <td>K<?= number_format((float)($payment['amount_paid'] ?? 0), 2) ?></td>
                                            <td><span class="history-status <?= htmlspecialchars($historyStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($historyStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td>
                                                <?= htmlspecialchars($payment['payment_reference'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($payment['notes'])): ?>
                                                    <div style="margin-top:.25rem;color:var(--text-muted);font-size:.58rem;max-width:220px;"><?= htmlspecialchars($payment['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($paymentSubmissions as $submission): ?>
                                        <?php $submissionStatus = strtolower((string)($submission['verification_status'] ?? 'pending')); ?>
                                        <tr>
                                            <td><?= !empty($submission['school_payment_id']) ? 'Submission' : 'General payment' ?></td>
                                            <td><?= htmlspecialchars($submission['payment_date'] ?: 'Submitted', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>—</td>
                                            <td>K<?= number_format((float)$submission['amount'], 2) ?></td>
                                            <td><span class="history-status <?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= htmlspecialchars($submission['transaction_reference'], ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <footer class="mockup-footer">
            <span>
                &copy; 2026 EduLinCore School Management Framework.
                All rights reserved.
            </span>
        </footer>

    </div>

    <script>
        <?php if ($showPaymentInstructions): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const paymentModal = document.getElementById('paymentModal');
                const paymentModalClose = document.getElementById('paymentModalClose');

                function closePaymentModal() {
                    if (paymentModal) {
                        paymentModal.hidden = true;
                    }
                }

                if (paymentModalClose) {
                    paymentModalClose.addEventListener('click', closePaymentModal);
                }

                if (paymentModal) {
                    paymentModal.addEventListener('click', function(event) {
                        if (event.target === paymentModal) {
                            closePaymentModal();
                        }
                    });
                }

                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        closePaymentModal();
                    }
                });
            });
        <?php endif; ?>

        function toggleSubMenu(element) {
            const parentGroup = element.closest('.nav-group');

            if (parentGroup) {
                parentGroup.classList.toggle('active');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {

            const sidebarToggle =
                document.getElementById('sidebarToggle');

            const appSidebar =
                document.getElementById('appSidebar');

            const sidebarOverlay =
                document.getElementById('sidebarOverlay');

            function toggleSidebarMenu() {
                appSidebar.classList.toggle('sidebar-open');
                document.body.classList.toggle('menu-open');
            }

            if (
                sidebarToggle &&
                appSidebar &&
                sidebarOverlay
            ) {
                sidebarToggle.addEventListener(
                    'click',
                    toggleSidebarMenu
                );

                sidebarOverlay.addEventListener(
                    'click',
                    toggleSidebarMenu
                );
            }

            const currentPath = window.location.pathname;

            const links = document.querySelectorAll(
                'aside nav a[data-path]'
            );

            links.forEach(link => {

                const path = link.getAttribute('data-path');

                if (
                    currentPath === path ||
                    (
                        path !== '/it/dashboard' &&
                        currentPath.startsWith(path)
                    )
                ) {
                    link.classList.add('active-link');

                    const parentGroup =
                        link.closest('.nav-group');

                    if (parentGroup) {
                        parentGroup.classList.add('active');
                    }
                }
            });

            const dashboardLink =
                document.querySelector(
                    'aside nav a[href*="/it/dashboard"]'
                );

            if (
                dashboardLink &&
                (
                    currentPath.endsWith('/it/dashboard') ||
                    currentPath.endsWith('/it/')
                )
            ) {
                dashboardLink.style.background =
                    'rgba(37,99,235,0.1)';

                dashboardLink.style.color =
                    'var(--primary)';

                dashboardLink.style.fontWeight = '700';
            }
        });

        let inactivityTimer;

        const logoutRedirectUrl =
            '<?= BASE_URL ?>/auth/logout';

        const idleLimitMs =
            15 * 60 * 1000;

        function resetIdleTimer() {

            clearTimeout(inactivityTimer);

            inactivityTimer = setTimeout(() => {

                alert(
                    'Your session has timed out due to inactivity for security purposes. You are being logged out.'
                );

                window.location.href =
                    logoutRedirectUrl;

            }, idleLimitMs);
        }

        [
            'mousemove',
            'keydown',
            'mousedown',
            'touchstart',
            'scroll'
        ].forEach(activityEvent => {

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