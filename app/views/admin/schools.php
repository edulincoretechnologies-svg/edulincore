<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (
    empty($_SESSION['user_logged_in']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    header('Location: ' . BASE_URL . '/auth/admin_login');
    exit;
}

function adminSchoolEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function adminSchoolDate($value): string
{
    if (empty($value)) {
        return '—';
    }

    $timestamp = strtotime((string)$value);

    return $timestamp
        ? date('d M Y, H:i', $timestamp)
        : '—';
}

function adminSchoolSetupStatus($value): array
{
    if ((int)$value === 1) {
        return [
            'label' => 'Complete',
            'class' => 'complete'
        ];
    }

    return [
        'label' => 'Pending',
        'class' => 'pending'
    ];
}

function adminSchoolStatus(array $school): array
{
    if ((int)($school['is_deleted'] ?? 0) === 1) {
        return [
            'label' => 'Removed',
            'class' => 'removed'
        ];
    }

    if ((int)($school['is_suspended'] ?? 0) === 1) {
        return [
            'label' => 'Suspended',
            'class' => 'suspended'
        ];
    }

    return [
        'label' => 'Active',
        'class' => 'active'
    ];
}

if (empty($_SESSION['admin_schools_csrf'])) {
    $_SESSION['admin_schools_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['admin_schools_csrf'];

$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $actionMessage = 'The security token has expired. Please try again.';
        $actionType = 'error';
    } else {
        $action = trim($_POST['action'] ?? '');
        $schoolId = filter_var(
            $_POST['school_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $actionsWithoutSchool = ['add_school', 'record_payment', 'initiate_bill'];

        if (!in_array($action, $actionsWithoutSchool, true) && (!$schoolId || $schoolId < 1)) {
            $actionMessage = 'Invalid school selected.';
            $actionType = 'error';
        } else {
            try {
                $db = Database::getConnection();

                if ($action === 'add_school') {
                    $schoolName = trim($_POST['school_name'] ?? '');
                    $schoolCode = trim($_POST['school_code'] ?? '');
                    $schoolLevel = trim($_POST['school_level'] ?? '');
                    $pricingPlanId = (int)($_POST['pricing_plan_id'] ?? 0);
                    $district = trim($_POST['district'] ?? '');
                    $province = trim($_POST['province'] ?? '');
                    $classNamingSystem = trim($_POST['class_naming_system'] ?? '');
                    $classNamingType = trim($_POST['class_naming_type'] ?? '');
                    $classNamingCustom = trim($_POST['class_naming_custom'] ?? '');

                    if ($schoolName === '' || strlen($schoolName) > 255) {
                        throw new InvalidArgumentException('A valid school name is required.');
                    }

                    if ($schoolLevel === '') {
                        throw new InvalidArgumentException('School level is required.');
                    }

                    if ($pricingPlanId < 1) {
                        throw new InvalidArgumentException('A pricing plan is required.');
                    }

                    $planCheck = $db->prepare('SELECT id FROM pricing_plans WHERE id = ? AND COALESCE(is_active, 0) = 1 LIMIT 1');
                    $planCheck->execute([$pricingPlanId]);

                    if (!$planCheck->fetchColumn()) {
                        throw new InvalidArgumentException('The selected pricing plan is not active.');
                    }

                    $duplicateStmt = $db->prepare('SELECT id FROM schools WHERE school_name = ? OR (? <> "" AND school_code = ?) LIMIT 1');
                    $duplicateStmt->execute([$schoolName, $schoolCode, $schoolCode]);

                    if ($duplicateStmt->fetchColumn()) {
                        throw new InvalidArgumentException('A school with that name or code already exists.');
                    }

                    $insertStmt = $db->prepare('
                        INSERT INTO schools (
                            pricing_plan_id, school_name, school_code, school_level, District, Province,
                            class_naming_system, class_naming_type, class_naming_custom,
                            grade_setup_done, is_deleted, is_suspended, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, NOW())
                    ');
                    $insertStmt->execute([
                        $pricingPlanId,
                        $schoolName,
                        $schoolCode !== '' ? $schoolCode : null,
                        $schoolLevel,
                        $district !== '' ? $district : null,
                        $province !== '' ? $province : null,
                        $classNamingSystem !== '' ? $classNamingSystem : null,
                        $classNamingType !== '' ? $classNamingType : null,
                        $classNamingCustom !== '' ? $classNamingCustom : null
                    ]);

                    $actionMessage = 'School added successfully.';
                    $actionType = 'success';
                } elseif ($action === 'edit_school') {
                    $schoolName = trim($_POST['school_name'] ?? '');
                    $schoolCode = trim($_POST['school_code'] ?? '');
                    $schoolLevel = trim($_POST['school_level'] ?? '');
                    $pricingPlanId = (int)($_POST['pricing_plan_id'] ?? 0);
                    $district = trim($_POST['district'] ?? '');
                    $province = trim($_POST['province'] ?? '');
                    $classNamingSystem = trim($_POST['class_naming_system'] ?? '');
                    $classNamingType = trim($_POST['class_naming_type'] ?? '');
                    $classNamingCustom = trim($_POST['class_naming_custom'] ?? '');

                    if (!$schoolId || $schoolName === '' || $schoolLevel === '' || $pricingPlanId < 1) {
                        throw new InvalidArgumentException('School name, level, pricing plan and school selection are required.');
                    }

                    $planCheck = $db->prepare('SELECT id FROM pricing_plans WHERE id = ? AND COALESCE(is_active, 0) = 1 LIMIT 1');
                    $planCheck->execute([$pricingPlanId]);

                    if (!$planCheck->fetchColumn()) {
                        throw new InvalidArgumentException('The selected pricing plan is not active.');
                    }

                    $duplicateStmt = $db->prepare('SELECT id FROM schools WHERE (school_name = ? OR (? <> "" AND school_code = ?)) AND id <> ? LIMIT 1');
                    $duplicateStmt->execute([$schoolName, $schoolCode, $schoolCode, $schoolId]);

                    if ($duplicateStmt->fetchColumn()) {
                        throw new InvalidArgumentException('Another school already uses that name or code.');
                    }

                    $updateStmt = $db->prepare('
                        UPDATE schools
                        SET pricing_plan_id = ?, school_name = ?, school_code = ?, school_level = ?, District = ?, Province = ?,
                            class_naming_system = ?, class_naming_type = ?, class_naming_custom = ?
                        WHERE id = ? AND COALESCE(is_deleted, 0) = 0
                    ');
                    $updateStmt->execute([
                        $pricingPlanId,
                        $schoolName,
                        $schoolCode !== '' ? $schoolCode : null,
                        $schoolLevel,
                        $district !== '' ? $district : null,
                        $province !== '' ? $province : null,
                        $classNamingSystem !== '' ? $classNamingSystem : null,
                        $classNamingType !== '' ? $classNamingType : null,
                        $classNamingCustom !== '' ? $classNamingCustom : null,
                        $schoolId
                    ]);

                    $actionMessage = 'School details updated successfully.';
                    $actionType = 'success';
                } elseif ($action === 'record_payment' || $action === 'initiate_bill') {
                    $paymentSchoolId = filter_var($_POST['payment_school_id'] ?? '', FILTER_VALIDATE_INT);
                    $amountDue = filter_var($_POST['amount_due'] ?? '', FILTER_VALIDATE_FLOAT);
                    $amountPaid = filter_var($_POST['amount_paid'] ?? '0', FILTER_VALIDATE_FLOAT);
                    $billingPeriod = trim($_POST['billing_period'] ?? '');
                    $academicYear = trim($_POST['academic_year'] ?? '');
                    $termName = trim($_POST['term_name'] ?? '');
                    $paymentStatus = trim($_POST['payment_status'] ?? 'pending');
                    $paymentDate = trim($_POST['payment_date'] ?? '');
                    $paymentMethod = trim($_POST['payment_method'] ?? '');
                    $paymentReference = trim($_POST['payment_reference'] ?? '');
                    $paymentNotes = trim($_POST['payment_notes'] ?? '');

                    if ($action === 'initiate_bill') {
                        $amountPaid = 0.0;
                        $paymentStatus = 'pending';
                        $paymentDate = '';
                        $paymentReference = '';

                        if ($paymentNotes === '') {
                            $paymentNotes = 'Thank you for being part of EduLinCore. Please complete this payment on time so your school can continue enjoying uninterrupted access to our services.';
                        }
                    }

                    if (!$paymentSchoolId || $amountDue === false || $amountDue <= 0 || $amountPaid === false || $amountPaid < 0 || $billingPeriod === '' || $academicYear === '') {
                        throw new InvalidArgumentException('School, billing period, academic year and valid amounts are required.');
                    }

                    $pricingPlanId = (int)($_POST['pricing_plan_id'] ?? 0);

                    if ($action === 'initiate_bill' && $pricingPlanId < 1) {
                        throw new InvalidArgumentException('Select a pricing plan before initiating a bill.');
                    }

                    if ($pricingPlanId > 0) {
                        $planCheck = $db->prepare('SELECT id FROM pricing_plans WHERE id = ? AND COALESCE(is_active, 0) = 1 LIMIT 1');
                        $planCheck->execute([$pricingPlanId]);

                        if (!$planCheck->fetchColumn()) {
                            throw new InvalidArgumentException('The selected pricing plan is not active.');
                        }
                    }

                    if (!in_array($paymentStatus, ['pending', 'partial', 'paid', 'overdue', 'cancelled'], true)) {
                        throw new InvalidArgumentException('Invalid payment status.');
                    }

                    $schoolCheck = $db->prepare('SELECT id FROM schools WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
                    $schoolCheck->execute([$paymentSchoolId]);

                    if (!$schoolCheck->fetchColumn()) {
                        throw new InvalidArgumentException('The selected school was not found.');
                    }

                    $paymentStmt = $db->prepare('
                        INSERT INTO school_payments (
                            school_id, pricing_plan_id, billing_period, academic_year, term_name,
                            amount_due, amount_paid, payment_status, payment_date, payment_method,
                            payment_reference, notes, created_at, updated_at
                        ) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, NULLIF(?, ""), ?, ?, ?, NOW(), NOW())
                    ');
                    $paymentStmt->execute([
                        $paymentSchoolId,
                        $pricingPlanId,
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

                    $actionMessage = $action === 'initiate_bill'
                        ? 'Bill initiated successfully. It is now available on the school IT and headteacher dashboards.'
                        : 'School payment recorded successfully.';
                    $actionType = 'success';
                } elseif ($action === 'suspend') {
                    $reason = trim($_POST['suspension_reason'] ?? '');

                    if ($reason === '') {
                        $actionMessage = 'A suspension reason is required.';
                        $actionType = 'error';
                    } elseif (strlen($reason) > 255) {
                        $actionMessage = 'The suspension reason must not exceed 255 characters.';
                        $actionType = 'error';
                    } else {
                        $checkStmt = $db->prepare("
                            SELECT
                                id,
                                school_name,
                                COALESCE(is_deleted, 0) AS is_deleted,
                                COALESCE(is_suspended, 0) AS is_suspended
                            FROM schools
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $checkStmt->execute([$schoolId]);
                        $school = $checkStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$school) {
                            $actionMessage = 'School not found.';
                            $actionType = 'error';
                        } elseif ((int)$school['is_deleted'] === 1) {
                            $actionMessage = 'This school has already been removed.';
                            $actionType = 'error';
                        } elseif ((int)$school['is_suspended'] === 1) {
                            $actionMessage = 'This school is already suspended.';
                            $actionType = 'error';
                        } else {
                            $updateStmt = $db->prepare("
                                UPDATE schools
                                SET
                                    is_suspended = 1,
                                    suspension_reason = ?,
                                    suspended_at = NOW()
                                WHERE id = ?
                                AND COALESCE(is_deleted, 0) = 0
                            ");

                            $updateStmt->execute([
                                $reason,
                                $schoolId
                            ]);

                            $actionMessage = $school['school_name'] . ' has been suspended successfully.';
                            $actionType = 'success';
                        }
                    }
                } elseif ($action === 'activate') {
                    $checkStmt = $db->prepare("
                        SELECT
                            id,
                            school_name,
                            COALESCE(is_deleted, 0) AS is_deleted,
                            COALESCE(is_suspended, 0) AS is_suspended
                        FROM schools
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $checkStmt->execute([$schoolId]);
                    $school = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$school) {
                        $actionMessage = 'School not found.';
                        $actionType = 'error';
                    } elseif ((int)$school['is_deleted'] === 1) {
                        $actionMessage = 'A removed school cannot be activated from this page.';
                        $actionType = 'error';
                    } elseif ((int)$school['is_suspended'] === 0) {
                        $actionMessage = 'This school is already active.';
                        $actionType = 'error';
                    } else {
                        $updateStmt = $db->prepare("
                            UPDATE schools
                            SET
                                is_suspended = 0,
                                suspension_reason = NULL,
                                suspended_at = NULL
                            WHERE id = ?
                            AND COALESCE(is_deleted, 0) = 0
                        ");

                        $updateStmt->execute([$schoolId]);

                        $actionMessage = $school['school_name'] . ' has been activated successfully.';
                        $actionType = 'success';
                    }
                } elseif ($action === 'remove') {
                    $checkStmt = $db->prepare("
                        SELECT
                            id,
                            school_name,
                            COALESCE(is_deleted, 0) AS is_deleted,
                            COALESCE(is_suspended, 0) AS is_suspended
                        FROM schools
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $checkStmt->execute([$schoolId]);
                    $school = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$school) {
                        $actionMessage = 'School not found.';
                        $actionType = 'error';
                    } elseif ((int)$school['is_deleted'] === 1) {
                        $actionMessage = 'This school has already been removed.';
                        $actionType = 'error';
                    } elseif ((int)$school['is_suspended'] === 0) {
                        $actionMessage = 'An active school cannot be removed. Suspend the school first.';
                        $actionType = 'error';
                    } else {
                        $updateStmt = $db->prepare("
                            UPDATE schools
                            SET
                                is_deleted = 1,
                                is_suspended = 0
                            WHERE id = ?
                            AND COALESCE(is_deleted, 0) = 0
                            AND COALESCE(is_suspended, 0) = 1
                        ");

                        $updateStmt->execute([$schoolId]);

                        $actionMessage = $school['school_name'] . ' has been removed from active school records.';
                        $actionType = 'success';
                    }
                } else {
                    $actionMessage = 'Invalid administrative action.';
                    $actionType = 'error';
                }
            } catch (Throwable $e) {
                $actionMessage = 'The requested action could not be completed.';
                $actionType = 'error';
            }
        }
    }
}

$schools = [];
$pricingPlans = [];
$dbError = false;

try {
    $db = Database::getConnection();

    $pricingPlanStmt = $db->query("SELECT id, plan_name, plan_code, price, billing_period, extra_months, description FROM pricing_plans WHERE COALESCE(is_active, 0) = 1 ORDER BY sort_order ASC, plan_name ASC");
    $pricingPlans = $pricingPlanStmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            id,
            pricing_plan_id,
            school_name,
            school_code,
            school_level,
            District,
            Province,
            created_at,
            COALESCE(payment_summary.amount_due, 0) AS amount_due,
            COALESCE(payment_summary.amount_paid, 0) AS amount_paid,
            COALESCE(payment_summary.payment_count, 0) AS payment_count,
            COALESCE(submission_summary.pending_submissions, 0) AS pending_submissions,
            class_naming_system,
            class_naming_type,
            class_naming_custom,
            grade_setup_done,
            COALESCE(is_deleted, 0) AS is_deleted,
            COALESCE(is_suspended, 0) AS is_suspended,
            suspension_reason,
            suspended_at
        FROM schools
        LEFT JOIN (
            SELECT
                school_id,
                SUM(amount_due) AS amount_due,
                SUM(amount_paid) AS amount_paid,
                COUNT(*) AS payment_count
            FROM school_payments
            GROUP BY school_id
        ) payment_summary ON payment_summary.school_id = schools.id
        LEFT JOIN (
            SELECT
                school_id,
                COUNT(*) AS pending_submissions
            FROM school_payment_submissions
            WHERE verification_status = 'pending'
            GROUP BY school_id
        ) submission_summary ON submission_summary.school_id = schools.id
        WHERE COALESCE(is_deleted, 0) = 0
        ORDER BY school_name ASC
    ");

    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError = true;
}

$totalSchools = count($schools);
$activeSchools = 0;
$suspendedSchools = 0;
$completedSetup = 0;
$pendingSetup = 0;

foreach ($schools as $school) {
    if ((int)($school['is_suspended'] ?? 0) === 1) {
        $suspendedSchools++;
    } else {
        $activeSchools++;
    }

    if ((int)($school['grade_setup_done'] ?? 0) === 1) {
        $completedSetup++;
    } else {
        $pendingSetup++;
    }
}

$flashMessage = $actionMessage;
$flashType = $actionType;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schools | Edulincore Administration</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f5f7fb;
            color: #172033;
        }

        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }

        button {
            cursor: pointer;
        }

        .page {
            width: 100%;
            max-width: 1500px;
            margin: 0 auto;
            padding: 28px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 25px;
        }

        .header-content h1 {
            margin: 0 0 7px;
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .header-content p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 15px;
            border-radius: 10px;
            border: 1px solid #dbe2ea;
            background: #ffffff;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .header-button:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .summary-card {
            background: #ffffff;
            border: 1px solid #e5eaf0;
            border-radius: 15px;
            padding: 19px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, 0.04);
        }

        .summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 13px;
        }

        .summary-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.35px;
        }

        .summary-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #334155;
            font-size: 15px;
        }

        .summary-value {
            font-size: 27px;
            line-height: 1;
            font-weight: 800;
            color: #172033;
        }

        .summary-subtitle {
            margin-top: 8px;
            font-size: 11px;
            color: #94a3b8;
        }

        .toolbar {
            background: #ffffff;
            border: 1px solid #e5eaf0;
            border-radius: 15px;
            padding: 16px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 260px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
        }

        .search-box input {
            width: 100%;
            height: 43px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            outline: none;
            padding: 0 14px 0 39px;
            font-size: 13px;
            color: #1e293b;
            background: #ffffff;
        }

        .search-box input:focus {
            border-color: #94a3b8;
            box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.08);
        }

        .filter-box {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .filter-box label {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
        }

        .filter-box select {
            height: 43px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 35px 0 12px;
            color: #334155;
            background: #ffffff;
            outline: none;
            font-size: 13px;
            font-weight: 600;
        }

        .alert {
            border-radius: 11px;
            padding: 13px 15px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .alert.success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .table-card {
            background: #ffffff;
            border: 1px solid #e5eaf0;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 18px rgba(15, 23, 42, 0.04);
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid #edf1f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .table-header h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
        }

        .table-header span {
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        thead th {
            padding: 13px 17px;
            text-align: left;
            background: #f8fafc;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.55px;
            font-weight: 800;
            border-bottom: 1px solid #e5eaf0;
            white-space: nowrap;
        }

        tbody td {
            padding: 16px 17px;
            border-bottom: 1px solid #edf1f5;
            vertical-align: middle;
            font-size: 12px;
            color: #334155;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover {
            background: #fafbfc;
        }

        .school-name {
            font-weight: 800;
            color: #172033;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .school-code {
            color: #94a3b8;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .location-main {
            font-weight: 700;
            color: #475569;
            margin-bottom: 3px;
        }

        .location-sub {
            color: #94a3b8;
            font-size: 10px;
        }

        .level-badge {
            display: inline-flex;
            padding: 5px 8px;
            border-radius: 7px;
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .status-badge,
        .setup-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 7px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-badge.active {
            background: #ecfdf5;
            color: #15803d;
        }

        .status-badge.suspended {
            background: #fff7ed;
            color: #c2410c;
        }

        .status-badge.removed {
            background: #fef2f2;
            color: #b91c1c;
        }

        .setup-badge.complete {
            background: #ecfdf5;
            color: #15803d;
        }

        .setup-badge.pending {
            background: #fff7ed;
            color: #c2410c;
        }

        .suspension-info {
            max-width: 190px;
        }

        .suspension-reason {
            color: #c2410c;
            font-size: 10px;
            line-height: 1.45;
            margin-top: 5px;
        }

        .suspension-date {
            color: #94a3b8;
            font-size: 9px;
            margin-top: 4px;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .action-button {
            border: 1px solid #dbe2ea;
            background: #ffffff;
            color: #475569;
            min-height: 34px;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: 0.2s ease;
        }

        .action-button:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .action-button.suspend {
            color: #c2410c;
            border-color: #fed7aa;
            background: #fffaf5;
        }

        .action-button.suspend:hover {
            background: #fff7ed;
        }

        .action-button.activate {
            color: #15803d;
            border-color: #bbf7d0;
            background: #f0fdf4;
        }

        .action-button.activate:hover {
            background: #ecfdf5;
        }

        .action-button.remove {
            color: #b91c1c;
            border-color: #fecaca;
            background: #fffafa;
        }

        .action-button.remove:hover {
            background: #fef2f2;
        }

        .empty-state {
            padding: 65px 25px;
            text-align: center;
        }

        .empty-state i {
            width: 54px;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: #f1f5f9;
            color: #94a3b8;
            font-size: 20px;
            margin-bottom: 14px;
        }

        .empty-state h3 {
            margin: 0 0 7px;
            font-size: 15px;
        }

        .empty-state p {
            margin: 0;
            color: #94a3b8;
            font-size: 12px;
        }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            border-top: 1px solid #edf1f5;
            background: #fff;
        }

        .pagination-info {
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pagination-button,
        .page-size-select {
            min-height: 32px;
            border: 1px solid #dbe2ea;
            border-radius: 7px;
            background: #fff;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
        }

        .pagination-button {
            min-width: 32px;
            padding: 0 9px;
            cursor: pointer;
        }

        .pagination-button:hover:not(:disabled),
        .pagination-button.active {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .pagination-button:disabled {
            cursor: not-allowed;
            opacity: .45;
        }

        .page-size-select {
            padding: 0 7px;
            outline: none;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9999;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            width: 100%;
            max-width: 470px;
            max-height: calc(100vh - 40px);
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.2);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal > form {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
        }

        .modal-header {
            padding: 19px 20px;
            border-bottom: 1px solid #edf1f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 8px;
            background: #f1f5f9;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .modal-body {
            padding: 20px;
            flex: 1 1 auto;
            overflow-y: auto;
            min-height: 0;
        }

        .modal-body p {
            margin: 0 0 15px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }

        .modal-school {
            background: #f8fafc;
            border: 1px solid #e5eaf0;
            border-radius: 10px;
            padding: 12px 13px;
            margin-bottom: 16px;
        }

        .modal-school strong {
            display: block;
            color: #172033;
            font-size: 13px;
            margin-bottom: 3px;
        }

        .modal-school span {
            color: #94a3b8;
            font-size: 10px;
        }

        .form-group {
            margin-bottom: 10px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
        }

        .form-group textarea {
            width: 100%;
            min-height: 95px;
            resize: vertical;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 11px 12px;
            outline: none;
            color: #334155;
            font-size: 12px;
        }

        .form-group textarea:focus {
            border-color: #94a3b8;
            box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.08);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 11px;
            outline: none;
            color: #334155;
            font-size: 12px;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #94a3b8;
            box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.08);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 12px;
        }

        .form-grid .full-width {
            grid-column: 1 / -1;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #edf1f5;
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            flex-shrink: 0;
            background: #ffffff;
        }

        @media (max-height: 700px) {
            .modal-overlay {
                align-items: flex-start;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .modal {
                max-height: calc(100vh - 24px);
            }

            .modal-header,
            .modal-footer {
                padding-top: 11px;
                padding-bottom: 11px;
            }

            .modal-body {
                padding-top: 14px;
                padding-bottom: 14px;
            }
        }

        .modal-button {
            border: 1px solid #dbe2ea;
            background: #ffffff;
            color: #475569;
            border-radius: 9px;
            padding: 9px 14px;
            font-size: 11px;
            font-weight: 800;
        }

        .modal-button.primary {
            border-color: #fed7aa;
            background: #fff7ed;
            color: #c2410c;
        }

        .modal-button.bill {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .modal-button.danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        @media (max-width: 1050px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .page {
                padding: 18px;
            }

            .page-header {
                flex-direction: column;
            }

            .header-actions {
                width: 100%;
            }

            .header-button {
                flex: 1;
                justify-content: center;
            }

            .summary-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .summary-card {
                padding: 15px;
            }

            .summary-value {
                font-size: 23px;
            }

            .toolbar {
                align-items: stretch;
            }

            .search-box {
                min-width: 100%;
            }

            .filter-box {
                justify-content: space-between;
            }

            .filter-box select {
                flex: 1;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-grid .full-width {
                grid-column: auto;
            }

            .pagination-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .pagination-controls {
                justify-content: space-between;
            }
        }
    </style>
</head>

<body>

<div class="page">

    <div class="page-header">
        <div class="header-content">
            <h1>Schools</h1>
            <p>Manage school access, operational status and setup from one place.</p>
        </div>

        <div class="header-actions">
            <a
                href="<?= adminSchoolEscape(BASE_URL . '/admin/dashboard') ?>"
                class="header-button"
            >
                <i class="fa-solid fa-arrow-left"></i>
                Dashboard
            </a>

            <button
                type="button"
                class="header-button"
                onclick="openSchoolModal('add')"
            >
                <i class="fa-solid fa-plus"></i>
                Add School
            </button>

            <button
                type="button"
                class="header-button"
                onclick="window.location.reload();"
            >
                <i class="fa-solid fa-rotate"></i>
                Refresh
            </button>
        </div>
    </div>

    <?php if (!empty($flashMessage)): ?>
        <div class="alert <?= $flashType === 'success' ? 'success' : 'error' ?>">
            <i class="fa-solid <?= $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
            <span><?= adminSchoolEscape($flashMessage) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($dbError): ?>

        <div class="alert error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>School information could not be loaded at this time.</span>
        </div>

    <?php else: ?>

        <div class="summary-grid">

            <div class="summary-card">
                <div class="summary-top">
                    <span class="summary-label">Total Schools</span>
                    <span class="summary-icon">
                        <i class="fa-solid fa-school"></i>
                    </span>
                </div>

                <div class="summary-value">
                    <?= number_format($totalSchools) ?>
                </div>

                <div class="summary-subtitle">
                    Registered schools
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-top">
                    <span class="summary-label">Active</span>
                    <span class="summary-icon">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>
                </div>

                <div class="summary-value">
                    <?= number_format($activeSchools) ?>
                </div>

                <div class="summary-subtitle">
                    Currently operational
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-top">
                    <span class="summary-label">Suspended</span>
                    <span class="summary-icon">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                </div>

                <div class="summary-value">
                    <?= number_format($suspendedSchools) ?>
                </div>

                <div class="summary-subtitle">
                    Access currently restricted
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-top">
                    <span class="summary-label">Setup Pending</span>
                    <span class="summary-icon">
                        <i class="fa-solid fa-gears"></i>
                    </span>
                </div>

                <div class="summary-value">
                    <?= number_format($pendingSetup) ?>
                </div>

                <div class="summary-subtitle">
                    Grade setup not completed
                </div>
            </div>

        </div>

        <div class="toolbar">

            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="text"
                    id="schoolSearch"
                    placeholder="Search school name, code, district or province..."
                    autocomplete="off"
                >
            </div>

            <div class="filter-box">
                <label for="statusFilter">Status</label>

                <select id="statusFilter">
                    <option value="all">All Schools</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

        </div>

        <div class="table-card" id="billing">

            <div class="table-header">
                <div>
                    <h2>School Management</h2>
                </div>

                <span>
                    <?= number_format($totalSchools) ?> school<?= $totalSchools === 1 ? '' : 's' ?>
                </span>
            </div>

            <?php if (empty($schools)): ?>

                <div class="empty-state">
                    <i class="fa-solid fa-school"></i>
                    <h3>No schools found</h3>
                    <p>There are currently no active schools in the system.</p>
                </div>

            <?php else: ?>

                <div class="table-wrapper">

                    <table id="schoolsTable">

                        <thead>
                            <tr>
                                <th>School</th>
                                <th>Location</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Grade Setup</th>
                                <th>Billing</th>
                                <th>Registered</th>
                                <th>Control</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($schools as $school): ?>

                            <?php
                            $status = adminSchoolStatus($school);
                            $setup = adminSchoolSetupStatus($school['grade_setup_done'] ?? 0);

                            $searchText = strtolower(
                                implode(' ', [
                                    $school['school_name'] ?? '',
                                    $school['school_code'] ?? '',
                                    $school['District'] ?? '',
                                    $school['Province'] ?? '',
                                    $school['school_level'] ?? ''
                                ])
                            );
                            ?>

                            <tr
                                class="school-row"
                                data-status="<?= adminSchoolEscape(strtolower($status['label'])) ?>"
                                data-search="<?= adminSchoolEscape($searchText) ?>"
                            >

                                <td>
                                    <div class="school-name">
                                        <?= adminSchoolEscape($school['school_name']) ?>
                                    </div>

                                    <div class="school-code">
                                        <?= !empty($school['school_code'])
                                            ? adminSchoolEscape($school['school_code'])
                                            : 'No school code'
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="location-main">
                                        <?= !empty($school['District'])
                                            ? adminSchoolEscape($school['District'])
                                            : '—'
                                        ?>
                                    </div>

                                    <div class="location-sub">
                                        <?= !empty($school['Province'])
                                            ? adminSchoolEscape($school['Province'])
                                            : 'Province not set'
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="level-badge">
                                        <?= adminSchoolEscape(
                                            ucfirst(
                                                strtolower(
                                                    $school['school_level'] ?? 'Not set'
                                                )
                                            )
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge <?= adminSchoolEscape($status['class']) ?>">
                                        <i class="fa-solid
                                            <?= $status['class'] === 'active'
                                                ? 'fa-circle-check'
                                                : 'fa-lock'
                                            ?>"></i>

                                        <?= adminSchoolEscape($status['label']) ?>
                                    </span>

                                    <?php if ($status['class'] === 'suspended'): ?>

                                        <div class="suspension-info">

                                            <?php if (!empty($school['suspension_reason'])): ?>
                                                <div class="suspension-reason">
                                                    <?= adminSchoolEscape($school['suspension_reason']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($school['suspended_at'])): ?>
                                                <div class="suspension-date">
                                                    <?= adminSchoolEscape(
                                                        adminSchoolDate($school['suspended_at'])
                                                    ) ?>
                                                </div>
                                            <?php endif; ?>

                                        </div>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="setup-badge <?= adminSchoolEscape($setup['class']) ?>">
                                        <i class="fa-solid
                                            <?= $setup['class'] === 'complete'
                                                ? 'fa-check'
                                                : 'fa-clock'
                                            ?>"></i>

                                        <?= adminSchoolEscape($setup['label']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="location-main">
                                        <?= number_format((float)$school['amount_paid'], 2) ?> / <?= number_format((float)$school['amount_due'], 2) ?>
                                    </div>
                                    <div class="location-sub">
                                        <?= (int)$school['pending_submissions'] > 0
                                            ? (int)$school['pending_submissions'] . ' submission' . ((int)$school['pending_submissions'] === 1 ? '' : 's') . ' pending'
                                            : ((int)$school['payment_count'] > 0 ? 'Payments recorded' : 'No payments')
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <?= adminSchoolEscape(
                                        adminSchoolDate($school['created_at'] ?? null)
                                    ) ?>
                                </td>

                                <td>

                                    <div class="actions">

                                        <button
                                            type="button"
                                            class="action-button"
                                            onclick="openSchoolModal('edit', <?= htmlspecialchars(json_encode([
                                                'id' => (int)$school['id'],
                                                'pricing_plan_id' => (int)$school['pricing_plan_id'],
                                                'school_name' => $school['school_name'],
                                                'school_code' => $school['school_code'],
                                                'school_level' => $school['school_level'],
                                                'District' => $school['District'],
                                                'Province' => $school['Province'],
                                                'class_naming_system' => $school['class_naming_system'],
                                                'class_naming_type' => $school['class_naming_type'],
                                                'class_naming_custom' => $school['class_naming_custom']
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)"
                                        >
                                            <i class="fa-solid fa-pen"></i>
                                            Edit
                                        </button>

                                        <button
                                            type="button"
                                            class="action-button"
                                            onclick="openPaymentModal(<?= (int)$school['id'] ?>, '<?= adminSchoolEscape($school['school_name']) ?>')"
                                        >
                                            <i class="fa-solid fa-receipt"></i>
                                            Billing
                                        </button>

                                        <?php if ((int)$school['is_suspended'] === 0): ?>

                                            <button
                                                type="button"
                                                class="action-button suspend"
                                                onclick="openSuspendModal(
                                                    <?= (int)$school['id'] ?>,
                                                    '<?= adminSchoolEscape($school['school_name']) ?>'
                                                )"
                                            >
                                                <i class="fa-solid fa-lock"></i>
                                                Suspend
                                            </button>

                                        <?php else: ?>

                                            <form method="POST" style="margin:0;">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= adminSchoolEscape($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="activate"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="school_id"
                                                    value="<?= (int)$school['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-button activate"
                                                    onclick="return confirm('Activate <?= adminSchoolEscape($school['school_name']) ?>? School users will be able to access the system again.')"
                                                >
                                                    <i class="fa-solid fa-unlock"></i>
                                                    Activate
                                                </button>
                                            </form>

                                            <form method="POST" style="margin:0;">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= adminSchoolEscape($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="remove"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="school_id"
                                                    value="<?= (int)$school['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="action-button remove"
                                                    onclick="return confirm('Remove <?= adminSchoolEscape($school['school_name']) ?> from active school records? This will not physically delete the database record.')"
                                                >
                                                    <i class="fa-solid fa-trash-can"></i>
                                                    Remove
                                                </button>
                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                    <div
                        id="noSearchResults"
                        class="empty-state"
                        style="display:none;"
                    >
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <h3>No matching schools</h3>
                        <p>Try a different search term or status filter.</p>
                    </div>

                    <div class="pagination-bar" id="paginationBar">
                        <div class="pagination-info" id="paginationInfo"></div>
                        <div class="pagination-controls">
                            <label for="pageSize" class="pagination-info">Show</label>
                            <select id="pageSize" class="page-size-select" aria-label="Schools per page">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                            </select>
                            <button type="button" class="pagination-button" id="previousPage" aria-label="Previous page">&lsaquo;</button>
                            <span id="pageButtons"></span>
                            <button type="button" class="pagination-button" id="nextPage" aria-label="Next page">&rsaquo;</button>
                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<div class="modal-overlay" id="schoolModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h3 id="schoolModalTitle">Add School</h3>
            <button type="button" class="modal-close" onclick="closeModal('schoolModal')" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="schoolName">School Name</label>
                        <input id="schoolName" name="school_name" required maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="schoolCode">School Code</label>
                        <input id="schoolCode" name="school_code" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="schoolLevel">School Level</label>
                        <select id="schoolLevel" name="school_level" required>
                            <option value="">Select level</option>
                            <option value="primary">Primary</option>
                            <option value="secondary">Secondary</option>
                            <option value="combined">Combined</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label for="schoolPricingPlan">Pricing Plan</label>
                        <select id="schoolPricingPlan" name="pricing_plan_id" required>
                            <option value="">Select pricing plan</option>
                            <?php foreach ($pricingPlans as $plan): ?>
                                <option value="<?= (int)$plan['id'] ?>">
                                    <?= adminSchoolEscape($plan['plan_name']) ?> - <?= number_format((float)$plan['price'], 2) ?> / <?= adminSchoolEscape($plan['billing_period']) ?>
                                    <?php if ((int)$plan['extra_months'] > 0): ?>
                                        (+<?= (int)$plan['extra_months'] ?> month<?= (int)$plan['extra_months'] === 1 ? '' : 's' ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="district">District</label>
                        <input id="district" name="district" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="province">Province</label>
                        <input id="province" name="province" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="classNamingSystem">Class Naming System</label>
                        <input id="classNamingSystem" name="class_naming_system" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="classNamingType">Class Naming Type</label>
                        <input id="classNamingType" name="class_naming_type" maxlength="100">
                    </div>
                    <div class="form-group full-width">
                        <label for="classNamingCustom">Custom Class Naming</label>
                        <input id="classNamingCustom" name="class_naming_custom" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <input type="hidden" name="csrf_token" value="<?= adminSchoolEscape($csrfToken) ?>">
                <input type="hidden" name="action" id="schoolModalAction" value="add_school">
                <input type="hidden" name="school_id" id="schoolModalId" value="">
                <button type="button" class="modal-button" onclick="closeModal('schoolModal')">Cancel</button>
                <button type="submit" class="modal-button primary" id="schoolModalSubmit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save School
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="paymentModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h3>Send Bill or Record School Payment</h3>
            <button type="button" class="modal-close" onclick="closeModal('paymentModal')" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST">
            <div class="modal-body">
                <div class="modal-school">
                    <strong id="paymentSchoolName"></strong>
                    <span>Send a new bill to the school dashboards or record a payment received.</span>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="billingPeriod">Billing Period</label>
                        <input id="billingPeriod" name="billing_period" placeholder="e.g. Term 1" required>
                    </div>
                    <div class="form-group">
                        <label for="academicYear">Academic Year</label>
                        <input id="academicYear" name="academic_year" placeholder="e.g. 2026" required>
                    </div>
                    <div class="form-group">
                        <label for="termName">Term Name</label>
                        <input id="termName" name="term_name" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="pricingPlanId">Pricing Plan</label>
                        <select id="pricingPlanId" name="pricing_plan_id">
                            <option value="0" data-price="0">No plan override</option>
                            <?php foreach ($pricingPlans as $plan): ?>
                                <option value="<?= (int)$plan['id'] ?>" data-price="<?= htmlspecialchars((string)$plan['price'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= adminSchoolEscape($plan['plan_name']) ?> - <?= number_format((float)$plan['price'], 2) ?> / <?= adminSchoolEscape($plan['billing_period']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="amountDue">Amount Due</label>
                        <input id="amountDue" name="amount_due" type="number" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="amountPaid">Amount Paid</label>
                        <input id="amountPaid" name="amount_paid" type="number" min="0" step="0.01" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="paymentStatus">Payment Status</label>
                        <select id="paymentStatus" name="payment_status">
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="paymentDate">Payment Date</label>
                        <input id="paymentDate" name="payment_date" type="date">
                    </div>
                    <div class="form-group">
                        <label for="paymentMethod">Payment Method</label>
                        <input id="paymentMethod" name="payment_method" placeholder="e.g. Bank transfer">
                    </div>
                    <div class="form-group">
                        <label for="paymentReference">Payment Reference</label>
                        <input id="paymentReference" name="payment_reference">
                    </div>
                    <div class="form-group full-width">
                        <label for="paymentNotes">Notes</label>
                        <textarea id="paymentNotes" name="payment_notes" maxlength="1000" placeholder="Optional billing notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <input type="hidden" name="csrf_token" value="<?= adminSchoolEscape($csrfToken) ?>">
                <input type="hidden" name="action" id="paymentAction" value="record_payment">
                <input type="hidden" name="payment_school_id" id="paymentSchoolId" value="">
                <button type="button" class="modal-button" onclick="closeModal('paymentModal')">Cancel</button>
                <button type="submit" class="modal-button bill" onclick="document.getElementById('paymentAction').value = 'initiate_bill';">
                    <i class="fa-solid fa-file-circle-plus"></i>
                    Send Bill to School
                </button>
                <button type="submit" class="modal-button primary">
                    <i class="fa-solid fa-receipt"></i>
                    Record Payment
                </button>
            </div>
        </form>
    </div>
</div>

<div
    class="modal-overlay"
    id="suspendModal"
    aria-hidden="true"
>
    <div class="modal">

        <div class="modal-header">

            <h3>Suspend School</h3>

            <button
                type="button"
                class="modal-close"
                onclick="closeSuspendModal()"
                aria-label="Close"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <form method="POST">

            <div class="modal-body">

                <p>
                    Suspending a school will prevent its school users from accessing the system.
                </p>

                <div class="modal-school">
                    <strong id="modalSchoolName"></strong>
                    <span>School access will be restricted immediately.</span>
                </div>

                <div class="form-group">

                    <label for="suspensionReason">
                        Suspension Reason
                    </label>

                    <textarea
                        id="suspensionReason"
                        name="suspension_reason"
                        maxlength="255"
                        required
                        placeholder="Enter the reason for suspending this school..."
                    ></textarea>

                </div>

            </div>

            <div class="modal-footer">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= adminSchoolEscape($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="suspend"
                >

                <input
                    type="hidden"
                    name="school_id"
                    id="modalSchoolId"
                    value=""
                >

                <button
                    type="button"
                    class="modal-button"
                    onclick="closeSuspendModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="modal-button primary"
                >
                    <i class="fa-solid fa-lock"></i>
                    Suspend School
                </button>

            </div>

        </form>

    </div>
</div>

<script>
    const schoolSearch = document.getElementById('schoolSearch');
    const statusFilter = document.getElementById('statusFilter');
    const schoolRows = Array.from(document.querySelectorAll('.school-row'));
    const noSearchResults = document.getElementById('noSearchResults');
    const paginationBar = document.getElementById('paginationBar');
    const paginationInfo = document.getElementById('paginationInfo');
    const pageSizeSelect = document.getElementById('pageSize');
    const previousPage = document.getElementById('previousPage');
    const nextPage = document.getElementById('nextPage');
    const pageButtons = document.getElementById('pageButtons');
    let currentPage = 1;

    function getFilteredRows() {
        const searchValue = (schoolSearch?.value || '').trim().toLowerCase();
        const statusValue = (statusFilter?.value || 'all').toLowerCase();

        return schoolRows.filter(function(row) {
            const rowSearch = row.getAttribute('data-search') || '';
            const rowStatus = row.getAttribute('data-status') || '';

            return (
                (searchValue === '' || rowSearch.includes(searchValue)) &&
                (statusValue === 'all' || rowStatus === statusValue)
            );
        });
    }

    function renderPagination(filteredRows) {
        const pageSize = Number(pageSizeSelect?.value || 20);
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));

        currentPage = Math.min(currentPage, totalPages);

        schoolRows.forEach(function(row) {
            row.style.display = 'none';
        });

        const start = (currentPage - 1) * pageSize;
        filteredRows.slice(start, start + pageSize).forEach(function(row) {
            row.style.display = '';
        });

        if (paginationInfo) {
            const first = filteredRows.length === 0 ? 0 : start + 1;
            const last = Math.min(start + pageSize, filteredRows.length);
            paginationInfo.textContent = `Showing ${first}-${last} of ${filteredRows.length} schools`;
        }

        if (previousPage) {
            previousPage.disabled = currentPage <= 1;
        }

        if (nextPage) {
            nextPage.disabled = currentPage >= totalPages;
        }

        if (pageButtons) {
            pageButtons.innerHTML = '';
            const visiblePages = Math.min(totalPages, 5);
            let firstPage = Math.max(1, currentPage - 2);
            firstPage = Math.min(firstPage, Math.max(1, totalPages - visiblePages + 1));

            for (let page = firstPage; page < firstPage + visiblePages; page++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pagination-button' + (page === currentPage ? ' active' : '');
                button.textContent = page;
                button.setAttribute('aria-label', `Go to page ${page}`);
                button.addEventListener('click', function() {
                    currentPage = page;
                    renderPagination(getFilteredRows());
                });
                pageButtons.appendChild(button);
            }
        }

        if (paginationBar) {
            paginationBar.style.display = schoolRows.length > 0 ? 'flex' : 'none';
        }
    }

    function filterSchools() {
        const filteredRows = getFilteredRows();
        currentPage = 1;
        renderPagination(filteredRows);

        if (noSearchResults) {
            noSearchResults.style.display =
                filteredRows.length === 0 && schoolRows.length > 0
                    ? 'block'
                    : 'none';
        }
    }

    if (schoolSearch) {
        schoolSearch.addEventListener('input', filterSchools);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', filterSchools);
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
            currentPage = 1;
            renderPagination(getFilteredRows());
        });
    }

    if (previousPage) {
        previousPage.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                renderPagination(getFilteredRows());
            }
        });
    }

    if (nextPage) {
        nextPage.addEventListener('click', function() {
            const pageSize = Number(pageSizeSelect?.value || 20);
            const totalPages = Math.ceil(getFilteredRows().length / pageSize);

            if (currentPage < totalPages) {
                currentPage++;
                renderPagination(getFilteredRows());
            }
        });
    }

    filterSchools();

    const suspendModal = document.getElementById('suspendModal');
    const modalSchoolName = document.getElementById('modalSchoolName');
    const modalSchoolId = document.getElementById('modalSchoolId');
    const suspensionReason = document.getElementById('suspensionReason');

    function closeModal(id) {
        const modal = document.getElementById(id);

        if (modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    function openSchoolModal(mode, school) {
        const modal = document.getElementById('schoolModal');
        const form = modal ? modal.querySelector('form') : null;

        if (!modal || !form) {
            return;
        }

        const values = school || {};
        document.getElementById('schoolModalTitle').textContent = mode === 'edit' ? 'Edit School' : 'Add School';
        document.getElementById('schoolModalAction').value = mode === 'edit' ? 'edit_school' : 'add_school';
        document.getElementById('schoolModalSubmit').innerHTML = mode === 'edit'
            ? '<i class="fa-solid fa-floppy-disk"></i> Save Changes'
            : '<i class="fa-solid fa-floppy-disk"></i> Save School';
        document.getElementById('schoolModalId').value = values.id || '';
        document.getElementById('schoolName').value = values.school_name || '';
        document.getElementById('schoolCode').value = values.school_code || '';
        document.getElementById('schoolLevel').value = values.school_level || '';
        document.getElementById('schoolPricingPlan').value = values.pricing_plan_id || '';
        document.getElementById('district').value = values.District || '';
        document.getElementById('province').value = values.Province || '';
        document.getElementById('classNamingSystem').value = values.class_naming_system || '';
        document.getElementById('classNamingType').value = values.class_naming_type || '';
        document.getElementById('classNamingCustom').value = values.class_naming_custom || '';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('schoolName').focus();
    }

    function openPaymentModal(schoolId, schoolName) {
        const modal = document.getElementById('paymentModal');

        if (!modal) {
            return;
        }

        document.getElementById('paymentSchoolId').value = schoolId;
        document.getElementById('paymentSchoolName').textContent = schoolName;
        document.getElementById('paymentAction').value = 'record_payment';
        document.getElementById('pricingPlanId').value = '0';
        document.getElementById('amountDue').value = '';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('billingPeriod').focus();
    }

    const pricingPlanSelect = document.getElementById('pricingPlanId');
    const amountDueInput = document.getElementById('amountDue');

    if (pricingPlanSelect && amountDueInput) {
        pricingPlanSelect.addEventListener('change', function() {
            const selectedPlan = pricingPlanSelect.options[pricingPlanSelect.selectedIndex];
            const planPrice = selectedPlan ? selectedPlan.getAttribute('data-price') : '';

            if (planPrice && planPrice !== '0') {
                amountDueInput.value = planPrice;
            }
        });
    }

    function openSuspendModal(schoolId, schoolName) {
        if (!suspendModal) {
            return;
        }

        modalSchoolId.value = schoolId;
        modalSchoolName.textContent = schoolName;
        suspensionReason.value = '';

        suspendModal.classList.add('show');
        suspendModal.setAttribute('aria-hidden', 'false');

        setTimeout(function() {
            suspensionReason.focus();
        }, 100);
    }

    function closeSuspendModal() {
        if (!suspendModal) {
            return;
        }

        suspendModal.classList.remove('show');
        suspendModal.setAttribute('aria-hidden', 'true');
    }

    if (suspendModal) {
        suspendModal.addEventListener('click', function(event) {
            if (event.target === suspendModal) {
                closeSuspendModal();
            }
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeSuspendModal();
            closeModal('schoolModal');
            closeModal('paymentModal');
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal(modal.id);
            }
        });
    });

    setTimeout(function() {
        const alert = document.querySelector('.alert');

        if (alert) {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';

            setTimeout(function() {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 450);
        }
    }, 6000);
</script>

</body>
</html>