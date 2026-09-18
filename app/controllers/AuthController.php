<?php

class AuthController
{
    public function authenticate()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;
        }

        $role       = trim($_POST['role'] ?? '');
        $schoolName = trim($_POST['school_name'] ?? '');
        $identity   = trim($_POST['identity'] ?? '');
        $password   = $_POST['password'] ?? '';

        try {
            $db = Database::getConnection();

            $schoolStmt = $db->prepare("
                SELECT
                    id,
                    school_level,
                    COALESCE(is_suspended, 0) AS is_suspended,
                    suspension_reason
                FROM schools
                WHERE school_name = ?
                AND COALESCE(is_deleted, 0) = 0
                LIMIT 1
            ");

            $schoolStmt->execute([$schoolName]);
            $school = $schoolStmt->fetch();

            if (!$school) {
                $_SESSION['login_error'] = 'School name not found in the system.';
                $_SESSION['old_input'] = [
                    'role' => $role,
                    'school_name' => $schoolName,
                    'identity' => $identity
                ];

                header('Location: ' . BASE_URL . '/auth/school_login');
                exit;
            }

            $schoolId = $school['id'];

            if ((int)($school['is_suspended'] ?? 0) === 1) {
                $reason = trim((string)($school['suspension_reason'] ?? ''));

                $_SESSION['login_error'] = !empty($reason)
                    ? 'Access to this school has been suspended. Reason: ' . $reason
                    : 'Access to this school has been suspended. Please contact the school administrator.';

                $_SESSION['old_input'] = [
                    'role' => $role,
                    'school_name' => $schoolName,
                    'identity' => $identity
                ];

                header('Location: ' . BASE_URL . '/auth/school_login');
                exit;
            }

            $parentSchoolLevel = strtolower(trim($school['school_level'] ?? 'primary'));
            $user = false;
            $tableFound = '';

            switch ($role) {
                case 'headteacher':
                    $stmt = $db->prepare("
                        SELECT *
                        FROM headteachers
                        WHERE school_id = ?
                        AND (email = ? OR phone_number = ?)
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $schoolId,
                        $identity,
                        $identity
                    ]);

                    $user = $stmt->fetch();
                    $tableFound = 'headteachers';
                    break;

                case 'school_it':
                    $stmt = $db->prepare("
                        SELECT *
                        FROM school_it
                        WHERE school_id = ?
                        AND (
                            email = ?
                            OR phone_number = ?
                            OR full_name = ?
                        )
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $schoolId,
                        $identity,
                        $identity,
                        $identity
                    ]);

                    $user = $stmt->fetch();
                    $tableFound = 'school_it';
                    break;

                case 'teacher':
                    $stmt = $db->prepare("
                        SELECT *
                        FROM teachers
                        WHERE school_id = ?
                        AND (email = ? OR phone_number = ?)
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $schoolId,
                        $identity,
                        $identity
                    ]);

                    $user = $stmt->fetch();
                    $tableFound = 'teachers';
                    break;

                case 'student':
                    $stmt = $db->prepare("
                        SELECT *
                        FROM students
                        WHERE school_id = ?
                        AND (
                            student_id_number = ?
                            OR full_name = ?
                        )
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $schoolId,
                        $identity,
                        $identity
                    ]);

                    $user = $stmt->fetch();
                    $tableFound = 'students';
                    break;

                default:
                    throw new Exception('Invalid user role selected.');
            }

            $isValid = false;

            if ($user) {
                if ($role === 'student') {
                    $isValid = true;
                } else {
                    $passwordField = $user['password_hash'] ?? '';

                    if (
                        !empty($passwordField) &&
                        password_verify($password, $passwordField)
                    ) {
                        $isValid = true;
                    }
                }
            }

            if ($user && $isValid) {
                if (
                    $role !== 'student' &&
                    !empty($user['requires_reset']) &&
                    (int)$user['requires_reset'] === 1
                ) {
                    $_SESSION['temp_user_id'] = $user['id'];
                    $_SESSION['temp_table'] = $tableFound;
                    $_SESSION['temp_school_id'] = $schoolId;
                    $_SESSION['flash_message'] = 'Your account requires a password update. Please enter a new password.';

                    header('Location: ' . BASE_URL . '/auth/force_new_password');
                    exit;
                }

                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'] ?? 'User';
                $_SESSION['user_role'] = $role;
                $_SESSION['school_id'] = $schoolId;
                $_SESSION['school_name'] = $schoolName;

                if ($role === 'teacher') {
                    $teacherLevel = strtolower(trim($user['level'] ?? ''));

                    if (!empty($teacherLevel)) {
                        $operatingLevel = $teacherLevel;
                    } else {
                        $assignStmt = $db->prepare("
                            SELECT grade_level
                            FROM teacher_assignments
                            WHERE teacher_id = ?
                            AND school_id = ?
                        ");

                        $assignStmt->execute([
                            $user['id'],
                            $schoolId
                        ]);

                        $assignments = $assignStmt->fetchAll();
                        $hasSecondary = false;

                        foreach ($assignments as $assignment) {
                            $grade = strtoupper(trim($assignment['grade_level']));

                            if (
                                strpos($grade, 'FORM') !== false ||
                                preg_match('/(8|9|10|11|12)/', $grade)
                            ) {
                                $hasSecondary = true;
                                break;
                            }
                        }

                        if ($parentSchoolLevel === 'secondary') {
                            $operatingLevel = 'secondary';
                        } elseif ($parentSchoolLevel === 'primary') {
                            $operatingLevel = 'primary';
                        } else {
                            $operatingLevel = $hasSecondary
                                ? 'secondary'
                                : 'primary';
                        }
                    }

                    $_SESSION['teacher_level'] = $operatingLevel;
                }

                switch (strtolower($role)) {
                    case 'headteacher':
                        header('Location: ' . BASE_URL . '/headteacher/dashboard');
                        break;

                    case 'school_it':
                        header('Location: ' . BASE_URL . '/it/dashboard');
                        break;

                    case 'teacher':
                        header('Location: ' . BASE_URL . '/teacher/dashboard');
                        break;

                    case 'student':
                        header('Location: ' . BASE_URL . '/student/dashboard');
                        break;

                    default:
                        header('Location: ' . BASE_URL . '/dashboard');
                        break;
                }

                exit;
            }

            $_SESSION['login_error'] = 'Invalid credentials or information provided for this school.';

            $_SESSION['old_input'] = [
                'role' => $role,
                'school_name' => $schoolName,
                'identity' => $identity
            ];

            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;

        } catch (Exception $e) {
            $_SESSION['login_error'] = 'System error: ' . $e->getMessage();

            $_SESSION['old_input'] = [
                'role' => $role,
                'school_name' => $schoolName,
                'identity' => $identity
            ];

            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;
        }
    }

    public function process_forgot()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;
        }

        $role       = trim($_POST['role'] ?? '');
        $schoolName = trim($_POST['school_name'] ?? '');
        $identity   = trim($_POST['identity'] ?? '');

        if (empty($role) || empty($schoolName) || empty($identity)) {
            $_SESSION['login_error'] = 'Role, school name, and email/phone number are required.';

            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;
        }

        try {
            $db = Database::getConnection();

            $schoolStmt = $db->prepare("
                SELECT
                    id,
                    COALESCE(is_suspended, 0) AS is_suspended,
                    suspension_reason
                FROM schools
                WHERE school_name = ?
                AND COALESCE(is_deleted, 0) = 0
                LIMIT 1
            ");

            $schoolStmt->execute([$schoolName]);
            $school = $schoolStmt->fetch();

            if (!$school) {
                $_SESSION['login_error'] = 'School name not found in the system.';

                header('Location: ' . BASE_URL . '/auth/forgot_password');
                exit;
            }

            if ((int)($school['is_suspended'] ?? 0) === 1) {
                $reason = trim((string)($school['suspension_reason'] ?? ''));

                $_SESSION['login_error'] = !empty($reason)
                    ? 'Password reset is unavailable because this school has been suspended. Reason: ' . $reason
                    : 'Password reset is unavailable because this school has been suspended.';

                header('Location: ' . BASE_URL . '/auth/forgot_password');
                exit;
            }

            $schoolId = $school['id'];
            $tableFound = '';

            switch ($role) {
                case 'headteacher':
                    $tableFound = 'headteachers';
                    break;

                case 'school_it':
                    $tableFound = 'school_it';
                    break;

                case 'teacher':
                    $tableFound = 'teachers';
                    break;

                default:
                    $_SESSION['login_error'] = 'Invalid role selected for password reset.';

                    header('Location: ' . BASE_URL . '/auth/forgot_password');
                    exit;
            }

            $stmt = $db->prepare("
                SELECT *
                FROM {$tableFound}
                WHERE school_id = ?
                AND (email = ? OR phone_number = ?)
                LIMIT 1
            ");

            $stmt->execute([
                $schoolId,
                $identity,
                $identity
            ]);

            $user = $stmt->fetch();

            if ($user) {
                $defaultHash = password_hash(
                    '123456',
                    PASSWORD_DEFAULT
                );

                $updateStmt = $db->prepare("
                    UPDATE {$tableFound}
                    SET password_hash = ?,
                        requires_reset = 1
                    WHERE id = ?
                ");

                $updateStmt->execute([
                    $defaultHash,
                    $user['id']
                ]);

                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_table'] = $tableFound;
                $_SESSION['temp_school_id'] = $schoolId;
                $_SESSION['flash_message'] = 'Your password has been temporarily reset to 123456. Please enter your new password below.';

                header('Location: ' . BASE_URL . '/auth/force_new_password');
                exit;
            }

            $_SESSION['login_error'] = 'No account found matching this identity for the selected role in this school.';

            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;

        } catch (Exception $e) {
            $_SESSION['login_error'] = 'System error: ' . $e->getMessage();

            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;
        }
    }

    public function force_new_password()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (
            empty($_SESSION['temp_user_id']) ||
            empty($_SESSION['temp_table'])
        ) {
            $_SESSION['login_error'] = 'Session expired. Please start the password reset process again.';

            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;
        }

        $viewFile = APP_PATH . '/Views/auth/force_new_password.php';

        if (file_exists($viewFile)) {
            require_once $viewFile;
        } else {
            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Force New Password View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/Views/auth/force_new_password.php</code>.</p>";
            echo "</div>";
        }
    }

    public function store_new_password()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . '/auth/force_new_password');
            exit;
        }

        if (
            empty($_SESSION['temp_user_id']) ||
            empty($_SESSION['temp_table'])
        ) {
            $_SESSION['login_error'] = 'Session expired. Please start the password reset process again.';

            header('Location: ' . BASE_URL . '/auth/forgot_password');
            exit;
        }

        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword) || strlen($newPassword) < 6) {
            $_SESSION['login_error'] = 'New password must be at least 6 characters long.';

            header('Location: ' . BASE_URL . '/auth/force_new_password');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['login_error'] = 'Passwords do not match.';

            header('Location: ' . BASE_URL . '/auth/force_new_password');
            exit;
        }

        try {
            $db = Database::getConnection();

            $table = $_SESSION['temp_table'];
            $userId = $_SESSION['temp_user_id'];

            $newHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $stmt = $db->prepare("
                UPDATE {$table}
                SET password_hash = ?,
                    requires_reset = 0
                WHERE id = ?
            ");

            $stmt->execute([
                $newHash,
                $userId
            ]);

            unset(
                $_SESSION['temp_user_id'],
                $_SESSION['temp_table'],
                $_SESSION['temp_school_id']
            );

            $_SESSION['flash_message'] = 'Password successfully updated! You can now log in with your new password.';

            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;

        } catch (Exception $e) {
            $_SESSION['login_error'] = 'Failed to update password: ' . $e->getMessage();

            header('Location: ' . BASE_URL . '/auth/force_new_password');
            exit;
        }
    }
}