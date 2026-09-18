<?php
/**
 * Edulincore - Forgot Password View
 * Path: app/Views/auth/forgot_password.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Edulincore</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 font-sans text-slate-900 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md bg-white p-8 rounded-xl shadow-sm border border-slate-200">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Reset Password</h1>
            <p class="text-sm text-slate-500 mt-1">Select your role and enter your details</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/process_forgot" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Select Role</label>
                <select name="role" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                    <option value="">-- Choose Role --</option>
                    <option value="headteacher">Headteacher</option>
                    <option value="school_it">School IT Officer</option>
                    <option value="teacher">Teacher</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">School Name</label>
                <input type="text" name="school_name" required 
                       class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
                       placeholder="Enter your exact school name">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Email or Phone Number</label>
                <input type="text" name="identity" required 
                       class="w-full px-3.5 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
                       placeholder="Enter registered email or phone">
            </div>

            <button type="submit" 
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 rounded-lg transition shadow-sm">
                Reset Password to 123456
            </button>
        </form>

        <div class="text-center mt-6">
            <a href="<?= BASE_URL ?>/auth/school_login" class="text-sm font-medium text-blue-600 hover:underline">
                &larr; Back to School Login
            </a>
        </div>
    </div>

</body>
</html>