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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$scriptDir = dirname($scriptName);

if ($scriptDir !== '/' && $scriptDir !== '\\' && strpos($requestUri, $scriptDir) === 0) {
    $path = substr($requestUri, strlen($scriptDir));
} else {
    $path = $requestUri;
}

$path = str_replace('/index.php', '', $path);
$path = '/' . trim($path, '/');

if ($path === '/' || $path === '//' || $path === '') {
    $path = '/home';
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($path) {

    case '/home':

        require_once APP_PATH . '/views/home.php';

        break;


    case '/auth/school_login':

        if ($method === 'GET') {

            $loginFile = APP_PATH . '/views/auth/school_login.php';

            if (file_exists($loginFile)) {
                require_once $loginFile;
            } else {
                echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
                echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Login View Missing</h1>";
                echo "<p style='color:#64748b;'>Could not locate <code>app/views/auth/school_login.php</code>.</p>";
                echo "</div>";
            }
        }

        break;


    case '/auth/authenticate':

        if ($method === 'POST') {

            $role = trim($_POST['role'] ?? '');
            $schoolName = trim($_POST['school_name'] ?? '');
            $identity = trim($_POST['identity'] ?? '');
            $password = $_POST['password'] ?? '';

            try {

                $db = Database::getConnection();

                $schoolStmt = $db->prepare(
                    "SELECT id, school_level FROM schools WHERE school_name = ? LIMIT 1"
                );

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

                $parentSchoolLevel = strtolower(
                    trim($school['school_level'] ?? 'primary')
                );

                $user = false;

                switch ($role) {

                    case 'headteacher':

                        $stmt = $db->prepare(
                            "SELECT * FROM headteachers
                             WHERE school_id = ?
                             AND (email = ? OR phone_number = ?)
                             LIMIT 1"
                        );

                        $stmt->execute([
                            $schoolId,
                            $identity,
                            $identity
                        ]);

                        $user = $stmt->fetch();

                        break;


                    case 'school_it':

                        $stmt = $db->prepare(
                            "SELECT * FROM school_it
                             WHERE school_id = ?
                             AND (email = ? OR phone_number = ? OR full_name = ?)
                             LIMIT 1"
                        );

                        $stmt->execute([
                            $schoolId,
                            $identity,
                            $identity,
                            $identity
                        ]);

                        $user = $stmt->fetch();

                        break;


                    case 'teacher':

                        $stmt = $db->prepare(
                            "SELECT * FROM teachers
                             WHERE school_id = ?
                             AND (email = ? OR phone_number = ?)
                             LIMIT 1"
                        );

                        $stmt->execute([
                            $schoolId,
                            $identity,
                            $identity
                        ]);

                        $user = $stmt->fetch();

                        break;


                    case 'student':

                        $stmt = $db->prepare(
                            "SELECT * FROM students
                             WHERE school_id = ?
                             AND (student_id_number = ? OR full_name = ?)
                             LIMIT 1"
                        );

                        $stmt->execute([
                            $schoolId,
                            $identity,
                            $identity
                        ]);

                        $user = $stmt->fetch();

                        break;


                    default:

                        throw new Exception("Invalid user role selected.");
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

                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['full_name'] ?? 'User';
                    $_SESSION['user_role'] = $role;
                    $_SESSION['school_id'] = $schoolId;
                    $_SESSION['school_name'] = $schoolName;

                    if ($role === 'teacher') {

                        $teacherLevel = strtolower(
                            trim($user['level'] ?? '')
                        );

                        if (!empty($teacherLevel)) {

                            $operatingLevel = $teacherLevel;

                        } else {

                            $assignStmt = $db->prepare(
                                "SELECT grade_level
                                 FROM teacher_assignments
                                 WHERE teacher_id = ?
                                 AND school_id = ?"
                            );

                            $assignStmt->execute([
                                $user['id'],
                                $schoolId
                            ]);

                            $assignments = $assignStmt->fetchAll();

                            $hasSecondary = false;

                            foreach ($assignments as $assignment) {

                                $grade = strtoupper(
                                    trim($assignment['grade_level'])
                                );

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

                            header(
                                'Location: ' .
                                BASE_URL .
                                '/headteacher/dashboard'
                            );

                            break;


                        case 'school_it':

                            header(
                                'Location: ' .
                                BASE_URL .
                                '/it/dashboard'
                            );

                            break;


                        case 'teacher':

                            header(
                                'Location: ' .
                                BASE_URL .
                                '/teacher/dashboard'
                            );

                            break;


                        case 'student':

                            header(
                                'Location: ' .
                                BASE_URL .
                                '/student/dashboard'
                            );

                            break;


                        default:

                            header(
                                'Location: ' .
                                BASE_URL .
                                '/dashboard'
                            );

                            break;
                    }

                    exit;

                } else {

                    $_SESSION['login_error'] =
                        "Invalid credentials or information provided for this school.";

                    $_SESSION['old_input'] = [
                        'role' => $role,
                        'school_name' => $schoolName,
                        'identity' => $identity
                    ];

                    header(
                        'Location: ' .
                        BASE_URL .
                        '/auth/school_login'
                    );

                    exit;
                }

            } catch (Exception $e) {

                $_SESSION['login_error'] =
                    "System error: " . $e->getMessage();

                $_SESSION['old_input'] = [
                    'role' => $role,
                    'school_name' => $schoolName,
                    'identity' => $identity
                ];

                header(
                    'Location: ' .
                    BASE_URL .
                    '/auth/school_login'
                );

                exit;
            }
        }

        break;


    case '/auth/admin_login':

        if ($method === 'GET') {

            $adminLoginFile =
                APP_PATH . '/views/auth/admin_login.php';

            if (file_exists($adminLoginFile)) {

                require_once $adminLoginFile;

            } else {

                echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
                echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Admin Login View Missing</h1>";
                echo "<p style='color:#64748b;'>Could not locate <code>app/views/auth/admin_login.php</code>.</p>";
                echo "</div>";
            }
        }

        break;


    case '/auth/admin_authenticate':

        if ($method === 'POST') {

            $identity = trim($_POST['identity'] ?? '');
            $password = $_POST['password'] ?? '';

            try {

                $db = Database::getConnection();

                $stmt = $db->prepare(
                    "SELECT * FROM admins WHERE email = ? LIMIT 1"
                );

                $stmt->execute([$identity]);

                $admin = $stmt->fetch();

                if (
                    $admin &&
                    password_verify(
                        $password,
                        $admin['password_hash'] ?? ''
                    )
                ) {

                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['user_name'] =
                        $admin['full_name'] ?? 'System Administrator';
                    $_SESSION['user_role'] = 'admin';

                    header(
                        'Location: ' .
                        BASE_URL .
                        '/admin/dashboard'
                    );

                    exit;

                } else {

                    $_SESSION['login_error'] =
                        'Invalid administrator credentials.';

                    $_SESSION['old_input'] = [
                        'identity' => $identity
                    ];

                    header(
                        'Location: ' .
                        BASE_URL .
                        '/auth/admin_login'
                    );

                    exit;
                }

            } catch (Exception $e) {

                $_SESSION['login_error'] =
                    "System error: " . $e->getMessage();

                $_SESSION['old_input'] = [
                    'identity' => $identity
                ];

                header(
                    'Location: ' .
                    BASE_URL .
                    '/auth/admin_login'
                );

                exit;
            }
        }

        break;


    case '/it/dashboard':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $dashboardView =
            APP_PATH . '/views/it/dashboard.php';

        if (file_exists($dashboardView)) {

            require_once $dashboardView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>IT Dashboard View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/dashboard.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/teachers':
    case '/it/teachers/manage':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/teachers/manage.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Teacher Manage View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/teachers/manage.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/teachers/register':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/teachers/register.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Register Teacher View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/teachers/register.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/teachers/view':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/teachers/view_teachers.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>View Teachers View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/teachers/view_teachers.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/teachers/assignments':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/teachers/assignments.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Teacher Assignments View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/teachers/assignments.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/students/store':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        if ($method === 'POST') {

            $controllerPath =
                APP_PATH .
                '/controllers/it/StudentEnrollController.php';

            if (file_exists($controllerPath)) {

                require_once $controllerPath;

                exit;
            }

            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Controller File Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/controllers/it/StudentEnrollController.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/students/enroll':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $enrollView =
            APP_PATH . '/views/it/students/enroll.php';

        if (file_exists($enrollView)) {

            require_once $enrollView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Enrollment View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/students/enroll.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/students/management':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $managementView =
            APP_PATH .
            '/views/it/students/student_management.php';

        if (file_exists($managementView)) {

            require_once $managementView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Student Management View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/students/student_management.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/setup/classes':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/setup/classes.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Classes View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/setup/classes.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/setup/subjects':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'school_it'
        ) {
            header('Location: ' . BASE_URL . '/auth/school_login');
            exit;
        }

        $subjectView = APP_PATH . '/views/it/setup/subject.php';

        if (file_exists($subjectView)) {
            require_once $subjectView;
        } else {
            http_response_code(500);
            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Subject View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/setup/subject.php</code>.</p>";
            echo "</div>";
        }

        exit;

    case '/it/setup/classlevels':
    case '/it/setup/grades':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $setupType = '/it/setup/grades' === $path ? 'grades' : 'classlevels';

        $controllerPath =
            APP_PATH .
            '/controllers/it/GradeSetupController.php';

        if (file_exists($controllerPath)) {

            require_once $controllerPath;

            if (
                class_exists(
                    'App\Controllers\IT\GradeSetupController'
                )
            ) {

                $controller =
                    new \App\Controllers\IT\GradeSetupController();

                if (method_exists($controller, 'index')) {
                    $controller->index($setupType);
                    exit;
                }
            }
        }

        $viewFile =
            APP_PATH .
            '/views/it/setup/' .
            $setupType .
            '.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            http_response_code(404);
            echo "Setup view missing.";
        }

        break;


    case '/it/setup/grades/store':
    case '/it/setup/grades/update':
    case '/it/setup/grades/edit':
    case '/it/setup/grades/delete':
    case '/it/setup/classlevels/store':
    case '/it/setup/classlevels/update':
    case '/it/setup/classlevels/edit':
    case '/it/setup/classlevels/delete':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $setupType = strpos($path, '/grades/') !== false ? 'grades' : 'classlevels';

        $controllerPath =
            APP_PATH .
            '/controllers/it/GradeSetupController.php';

        if (file_exists($controllerPath)) {

            require_once $controllerPath;

            if (
                class_exists(
                    'App\Controllers\IT\GradeSetupController'
                )
            ) {

                $controller =
                    new \App\Controllers\IT\GradeSetupController();

                if (strpos($path, '/delete') !== false) {

                    $controller->delete($setupType);

                } elseif (strpos($path, '/update') !== false) {

                    $controller->update($setupType);

                } elseif (strpos($path, '/edit') !== false) {

                    $controller->edit($setupType);

                } else {

                    $controller->store($setupType);
                }

                exit;
            }
        }

        http_response_code(500);
        echo "GradeSetupController missing.";

        exit;


    case '/it/reports/analysis':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/reports/analysis.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Analysis Report View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/reports/analysis.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/reports/cards':
    case '/it/reports/reportcards':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/reports/reportcards.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Report Cards View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/reports/reportcards.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/it/reports/scoresheets':

        if (
            empty($_SESSION['user_logged_in']) ||
            $_SESSION['user_role'] !== 'school_it'
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $viewFile =
            APP_PATH . '/views/it/reports/scoresheets.php';

        if (file_exists($viewFile)) {

            require_once $viewFile;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Scoresheets View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/it/reports/scoresheets.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/teacher/primary_marks':
    case '/teacher/primarymarks':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $primaryMarksView =
            APP_PATH .
            '/views/teacher/primarymarks.php';

        if (file_exists($primaryMarksView)) {

            require_once $primaryMarksView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Primary Marks View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/primarymarks.php</code>.</p>";
            echo "<br><a href='" .
                BASE_URL .
                "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }

        break;


    case '/teacher/secondary_marks':
    case '/teacher/secondarymarks':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $secondaryMarksView =
            APP_PATH .
            '/views/teacher/secondarymarks.php';

        if (file_exists($secondaryMarksView)) {

            require_once $secondaryMarksView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Secondary Marks View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/secondarymarks.php</code>.</p>";
            echo "<br><a href='" .
                BASE_URL .
                "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }

        break;


    case '/teacher_view_report':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $progressSheetView =
            APP_PATH .
            '/views/teacher/progress_sheet.php';

        if (file_exists($progressSheetView)) {

            require_once $progressSheetView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Progress Sheet View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/progress_sheet.php</code>.</p>";
            echo "<br><a href='" .
                BASE_URL .
                "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }

        break;


    case '/teacher_analysis_report':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $analysisView =
            APP_PATH .
            '/views/teacher/analysis_report.php';

        if (file_exists($analysisView)) {

            require_once $analysisView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Analysis Report View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/teacher/analysis_report.php</code>.</p>";
            echo "<br><a href='" .
                BASE_URL .
                "/teacher/dashboard' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Back to Dashboard</a>";
            echo "</div>";
        }

        break;


    case '/teacher/dashboard':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $controllerPath =
            APP_PATH .
            '/controllers/teacher/TeacherController.php';

        if (file_exists($controllerPath)) {

            require_once $controllerPath;

            if (
                class_exists(
                    'App\Controllers\Teacher\TeacherController'
                )
            ) {

                $controller =
                    new \App\Controllers\Teacher\TeacherController();

                if (
                    method_exists(
                        $controller,
                        'index'
                    )
                ) {

                    $controller->index();

                    exit;
                }
            }
        }

        $teacherDashboardView =
            APP_PATH .
            '/views/teacher/teacher_dashboard.php';

        if (file_exists($teacherDashboardView)) {

            require_once $teacherDashboardView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Teacher Controller / View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>TeacherController</code> or <code>app/views/teacher/teacher_dashboard.php</code>.</p>";
            echo "<br><a href='" .
                BASE_URL .
                "/auth/logout' style='background:#dc2626; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Sign Out</a>";
         echo "</div>";
        }

        break;


    case '/admin/schools':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $schoolsView =
            APP_PATH .
            '/views/admin/schools.php';

        if (file_exists($schoolsView)) {
            require_once $schoolsView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Schools View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/schools.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/headteachers':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $headteachersView =
            APP_PATH .
            '/views/admin/headteachers.php';

        if (file_exists($headteachersView)) {
            require_once $headteachersView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Headteachers View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/headteachers.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/school-it':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $schoolItView =
            APP_PATH .
            '/views/admin/school-it.php';

        if (file_exists($schoolItView)) {
            require_once $schoolItView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>School IT View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/school-it.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/teachers':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $teachersView =
            APP_PATH .
            '/views/admin/teachers.php';

        if (file_exists($teachersView)) {
            require_once $teachersView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Teachers View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/teachers.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/students':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $studentsView =
            APP_PATH .
            '/views/admin/students.php';

        if (file_exists($studentsView)) {
            require_once $studentsView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Students View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/students.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/administrators':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $administratorsView =
            APP_PATH .
            '/views/admin/administrators.php';

        if (file_exists($administratorsView)) {
            require_once $administratorsView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Administrators View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/administrators.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/admin/reports':

        if (
            empty($_SESSION['user_logged_in']) ||
            ($_SESSION['user_role'] ?? '') !== 'admin'
        ) {
            header(
                'Location: ' .
                BASE_URL .
                '/auth/admin_login'
            );
            exit;
        }

        $reportsView =
            APP_PATH .
            '/views/admin/reports.php';

        if (file_exists($reportsView)) {
            require_once $reportsView;
        } else {
            http_response_code(500);

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Reports View Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate <code>app/views/admin/reports.php</code>.</p>";
            echo "</div>";
        }

        break;


    case '/headteacher/dashboard':
    case '/student/dashboard':
    case '/admin/dashboard':
        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $dashboardView =
            APP_PATH .
            '/views' .
            $path .
            '.php';

        if (file_exists($dashboardView)) {

            require_once $dashboardView;

        } else {

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#1e3a8a;'>Welcome to your Dashboard, " .
                htmlspecialchars(
                    $_SESSION['user_name'] ?? 'User'
                ) .
                "!</h1>";

            echo "<p style='color:#475569; margin-top:0.5rem;'>Role: " .
                htmlspecialchars(
                    ucfirst(
                        $_SESSION['user_role'] ?? ''
                    )
                ) .
                "</p>";

            echo "<br><a href='" .
                BASE_URL .
                "/auth/logout' style='background:#dc2626; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Sign Out</a>";

            echo "</div>";
        }

        break;


    case '/dashboard':

        if (
            empty($_SESSION['user_logged_in']) &&
            empty($_SESSION['user_id'])
        ) {

            header(
                'Location: ' .
                BASE_URL .
                '/auth/school_login'
            );

            exit;
        }

        $roleRoute =
            $_SESSION['user_role'] ?? '';

        if (
            in_array(
                $roleRoute,
                [
                    'headteacher',
                    'school_it',
                    'teacher',
                    'student',
                    'admin'
                ]
            )
        ) {

            $mapped =
                ($roleRoute === 'school_it')
                    ? 'it'
                    : $roleRoute;

            header(
                'Location: ' .
                BASE_URL .
                '/' .
                $mapped .
                '/dashboard'
            );

            exit;
        }

        echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";

        echo "<h1 style='color:#1e3a8a;'>Welcome to your Dashboard, " .
            htmlspecialchars(
                $_SESSION['user_name'] ?? 'User'
            ) .
            "!</h1>";

        echo "<br><a href='" .
            BASE_URL .
            "/auth/logout' style='background:#dc2626; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Sign Out</a>";

        echo "</div>";

        break;


    case '/auth/logout':

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {

            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();

        header(
            "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"
        );

        header("Pragma: no-cache");

        header(
            'Location: ' .
            BASE_URL .
            '/auth/school_login'
        );

        exit;


    case '/auth/forgot_password':

        if ($method === 'GET') {

            $viewFile =
                APP_PATH .
                '/views/auth/forgot_password.php';

            if (file_exists($viewFile)) {

                require_once $viewFile;

            } else {

                echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
                echo "<h1 style='color:#1e3a8a; font-size:1.5rem;'>Forgot Password View Missing</h1>";
                echo "<p style='color:#64748b;'>Could not locate <code>app/views/auth/forgot_password.php</code>.</p>";
                echo "</div>";
            }
        }

        break;


    case '/auth/process_forgot':
    case '/auth/force_new_password':
    case '/auth/store_new_password':

        if (
            $method === 'POST' ||
            $method === 'GET'
        ) {

            $controllerPath =
                APP_PATH .
                '/controllers/AuthController.php';

            if (file_exists($controllerPath)) {

                require_once $controllerPath;

                if (class_exists('AuthController')) {

                    $controller =
                        new AuthController();

                    $action =
                        ltrim(
                            $path,
                            '/auth/'
                        );

                    if (
                        method_exists(
                            $controller,
                            $action
                        )
                    ) {

                        $controller->$action();

                        exit;
                    }
                }
            }

            echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";
            echo "<h1 style='color:#dc2626; font-size:1.5rem;'>Auth Controller Missing</h1>";
            echo "<p style='color:#64748b;'>Could not locate method for <code>" .
                htmlspecialchars($path) .
                "</code>.</p>";
            echo "</div>";
        }

        break;


    default:

        http_response_code(404);

        echo "<div style='font-family:sans-serif; text-align:center; padding:4rem;'>";

        echo "<h1 style='color:#dc2626; font-size:2rem; margin-bottom:1rem;'>404 Not Found</h1>";

        echo "<p style='color:#64748b; margin-bottom:1.5rem;'>The requested route <code>" .
            htmlspecialchars($path) .
            "</code> does not exist on Edulincore.</p>";

        echo "<a href='" .
            BASE_URL .
            "/home' style='background:#2563eb; color:#fff; padding:0.5rem 1rem; border-radius:0.375rem; text-decoration:none; font-weight:600;'>Return Home</a>";

        echo "</div>";

        break;
}