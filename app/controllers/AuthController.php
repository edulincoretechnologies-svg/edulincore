<?php

class AuthController
{
    private function beginSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function redirect(string $path): void
    {
        header('Location: ' . BASE_URL . $path);
        exit;
    }

    private function saveLoginError(string $message, array $input = []): void
    {
        $_SESSION['login_error'] = $message;
        $_SESSION['old_input'] = $input;
    }

    private function getSchoolByName(PDO $db, string $schoolName): ?array
    {
        $stmt = $db->prepare("
            SELECT id, school_level, COALESCE(is_suspended, 0) AS is_suspended, suspension_reason
            FROM schools
            WHERE school_name = ?
            AND COALESCE(is_deleted, 0) = 0
            LIMIT 1
        ");

        $stmt->execute([$schoolName]);
        $school = $stmt->fetch();

        return is_array($school) ? $school : null;
    }

    private function resolveUser(PDO $db, int $schoolId, string $role, string $identity): array
    {
        $user = false;
        $table = '';

        switch ($role) {
            case 'headteacher':
                $stmt = $db->prepare("
                    SELECT *
                    FROM headteachers
                    WHERE school_id = ?
                    AND (email = ? OR phone_number = ?)
                    LIMIT 1
                ");
                $stmt->execute([$schoolId, $identity, $identity]);
                $user = $stmt->fetch();
                $table = 'headteachers';
                break;

            case 'school_it':
                $stmt = $db->prepare("
                    SELECT *
                    FROM school_it
                    WHERE school_id = ?
                    AND (email = ? OR phone_number = ? OR full_name = ?)
                    LIMIT 1
                ");
                $stmt->execute([$schoolId, $identity, $identity, $identity]);
                $user = $stmt->fetch();
                $table = 'school_it';
                break;

            case 'teacher':
                $stmt = $db->prepare("
                    SELECT *
                    FROM teachers
                    WHERE school_id = ?
                    AND (email = ? OR phone_number = ?)
                    LIMIT 1
                ");
                $stmt->execute([$schoolId, $identity, $identity]);
                $user = $stmt->fetch();
                $table = 'teachers';
                break;

            case 'student':
                $stmt = $db->prepare("
                    SELECT *
                    FROM students
                    WHERE school_id = ?
                    AND (student_id_number = ? OR full_name = ?)
                    LIMIT 1
                ");
                $stmt->execute([$schoolId, $identity, $identity]);
                $user = $stmt->fetch();
                $table = 'students';
                break;

            default:
                throw new Exception('Invalid user role selected.');
        }

        return [$user, $table];
    }

    private function determineTeacherLevel(PDO $db, int $schoolId, string $parentSchoolLevel, array $user): string
    {
        $teacherLevel = strtolower(trim((string)($user['level'] ?? '')));

        if ($teacherLevel !== '') {
            return $teacherLevel;
        }

        $assignStmt = $db->prepare("
            SELECT grade_level
            FROM teacher_assignments
            WHERE teacher_id = ?
            AND school_id = ?
        ");

        $assignStmt->execute([$user['id'], $schoolId]);
        $assignments = $assignStmt->fetchAll();

        $hasSecondary = false;

        foreach ($assignments as $assignment) {
            $grade = strtoupper(trim((string)($assignment['grade_level'] ?? '')));

            if (strpos($grade, 'FORM') !== false || preg_match('/(8|9|10|11|12)/', $grade)) {
                $hasSecondary = true;
                break;
            }
        }

        if ($parentSchoolLevel === 'secondary') {
            return 'secondary';
        }

        if ($parentSchoolLevel === 'primary') {
            return 'primary';
        }

        return $hasSecondary ? 'secondary' : 'primary';
    }

    public function authenticate(): void
    {
        $this->beginSession();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/auth/school_login');
        }

        $role = trim((string)($_POST['role'] ?? ''));
        $schoolName = trim((string)($_POST['school_name'] ?? ''));
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        try {
            $db = Database::getConnection();
            $school = $this->getSchoolByName($db, $schoolName);

            if ($school === null) {
                $this->saveLoginError('School name not found in the system.', [
                    'role' => $role,
                    'school_name' => $schoolName,
                    'identity' => $identity,
                ]);
                $this->redirect('/auth/school_login');
            }

            $schoolId = (int)$school['id'];

            if ((int)($school['is_suspended'] ?? 0) === 1) {
                $reason = trim((string)($school['suspension_reason'] ?? ''));
                $message = $reason !== ''
                    ? 'Access to this school has been suspended. Reason: ' . $reason
                    : 'Access to this school has been suspended. Please contact the school administrator.';

                $this->saveLoginError($message, [
                    'role' => $role,
                    'school_name' => $schoolName,
                    'identity' => $identity,
                ]);
                $this->redirect('/auth/school_login');
            }

            [$user, $tableFound] = $this->resolveUser($db, $schoolId, $role, $identity);

            $isValid = false;
            if ($user) {
                if ($role === 'student') {
                    $isValid = true;
                } else {
                    $passwordField = (string)($user['password_hash'] ?? '');
                    $isValid = $passwordField !== '' && password_verify($password, $passwordField);
                }
            }

            if ($user && $isValid) {
                if ($role !== 'student' && !empty($user['requires_reset']) && (int)$user['requires_reset'] === 1) {
                    $_SESSION['temp_user_id'] = $user['id'];
                    $_SESSION['temp_table'] = $tableFound;
                    $_SESSION['temp_school_id'] = $schoolId;
                    $_SESSION['flash_message'] = 'Your account requires a password update. Please enter a new password.';
                    $this->redirect('/auth/force_new_password');
                }

                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'] ?? 'User';
                $_SESSION['user_role'] = $role;
                $_SESSION['school_id'] = $schoolId;
                $_SESSION['school_name'] = $schoolName;

                if ($role === 'teacher') {
                    $parentSchoolLevel = strtolower(trim((string)($school['school_level'] ?? 'primary')));
                    $_SESSION['teacher_level'] = $this->determineTeacherLevel($db, $schoolId, $parentSchoolLevel, $user);
                }

                $routes = [
                    'headteacher' => '/headteacher/dashboard',
                    'school_it' => '/it/dashboard',
                    'teacher' => '/teacher/dashboard',
                    'student' => '/student/dashboard',
                ];

                $this->redirect($routes[$role] ?? '/dashboard');
            }

            $this->saveLoginError('Invalid credentials or information provided for this school.', [
                'role' => $role,
                'school_name' => $schoolName,
                'identity' => $identity,
            ]);
            $this->redirect('/auth/school_login');
        } catch (Exception $e) {
            $this->saveLoginError('System error: ' . $e->getMessage(), [
                'role' => $role,
                'school_name' => $schoolName,
                'identity' => $identity,
            ]);
            $this->redirect('/auth/school_login');
        }
    }

    public function process_forgot(): void
    {
        $this->beginSession();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/auth/forgot_password');
        }

        $role = trim((string)($_POST['role'] ?? ''));
        $schoolName = trim((string)($_POST['school_name'] ?? ''));
        $identity = trim((string)($_POST['identity'] ?? ''));

        if ($role === '' || $schoolName === '' || $identity === '') {
            $_SESSION['login_error'] = 'Role, school name, and email/phone number are required.';
            $this->redirect('/auth/forgot_password');
        }

        try {
            $db = Database::getConnection();
            $school = $this->getSchoolByName($db, $schoolName);

            if ($school === null) {
                $_SESSION['login_error'] = 'School name not found in the system.';
                $this->redirect('/auth/forgot_password');
            }

            if ((int)($school['is_suspended'] ?? 0) === 1) {
                $reason = trim((string)($school['suspension_reason'] ?? ''));
                $_SESSION['login_error'] = $reason !== ''
                    ? 'Password reset is unavailable because this school has been suspended. Reason: ' . $reason
                    : 'Password reset is unavailable because this school has been suspended.';
                $this->redirect('/auth/forgot_password');
            }

            $schoolId = (int)$school['id'];
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
                    $this->redirect('/auth/forgot_password');
            }

            $stmt = $db->prepare("
                SELECT *
                FROM {$tableFound}
                WHERE school_id = ?
                AND (email = ? OR phone_number = ?)
                LIMIT 1
            ");

            $stmt->execute([$schoolId, $identity, $identity]);
            $user = $stmt->fetch();

            if ($user) {
                $defaultHash = password_hash('123456', PASSWORD_DEFAULT);
                $updateStmt = $db->prepare("
                    UPDATE {$tableFound}
                    SET password_hash = ?,
                        requires_reset = 1
                    WHERE id = ?
                ");
                $updateStmt->execute([$defaultHash, $user['id']]);

                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_table'] = $tableFound;
                $_SESSION['temp_school_id'] = $schoolId;
                $_SESSION['flash_message'] = 'Your password has been temporarily reset to 123456. Please enter your new password below.';
                $this->redirect('/auth/force_new_password');
            }

            $_SESSION['login_error'] = 'No account found matching this identity for the selected role in this school.';
            $this->redirect('/auth/forgot_password');
        } catch (Exception $e) {
            $_SESSION['login_error'] = 'System error: ' . $e->getMessage();
            $this->redirect('/auth/forgot_password');
        }
    }

    public function force_new_password(): void
    {
        $this->beginSession();

        if (empty($_SESSION['temp_user_id']) || empty($_SESSION['temp_table'])) {
            $_SESSION['login_error'] = 'Session expired. Please start the password reset process again.';
            $this->redirect('/auth/forgot_password');
        }

        $viewFile = APP_PATH . '/Views/auth/force_new_password.php';

        if (file_exists($viewFile)) {
            require_once $viewFile;
            return;
        }

        echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
        echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Force New Password View Missing</h1>";
        echo "<p style='color:#64748b;'>Could not locate <code>app/Views/auth/force_new_password.php</code>.</p>";
        echo '</div>';
    }

    public function store_new_password(): void
    {
        $this->beginSession();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/auth/force_new_password');
        }

        if (empty($_SESSION['temp_user_id']) || empty($_SESSION['temp_table'])) {
            $_SESSION['login_error'] = 'Session expired. Please start the password reset process again.';
            $this->redirect('/auth/forgot_password');
        }

        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || strlen($newPassword) < 6) {
            $_SESSION['login_error'] = 'New password must be at least 6 characters long.';
            $this->redirect('/auth/force_new_password');
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['login_error'] = 'Passwords do not match.';
            $this->redirect('/auth/force_new_password');
        }

        try {
            $db = Database::getConnection();
            $table = $_SESSION['temp_table'];
            $userId = $_SESSION['temp_user_id'];
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                UPDATE {$table}
                SET password_hash = ?,
                    requires_reset = 0
                WHERE id = ?
            ");

            $stmt->execute([$newHash, $userId]);

            unset($_SESSION['temp_user_id'], $_SESSION['temp_table'], $_SESSION['temp_school_id']);
            $_SESSION['flash_message'] = 'Password successfully updated! You can now log in with your new password.';
            $this->redirect('/auth/school_login');
        } catch (Exception $e) {
            $_SESSION['login_error'] = 'Failed to update password: ' . $e->getMessage();
            $this->redirect('/auth/force_new_password');
        }
    }
}
