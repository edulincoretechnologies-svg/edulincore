<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$error   = $_SESSION['login_error'] ?? '';
$success = $_SESSION['flash_message'] ?? '';
$old     = $_SESSION['old_input'] ?? [];
unset($_SESSION['login_error'], $_SESSION['flash_message'], $_SESSION['old_input']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Portal Login - Edulincore</title>
    <style>
        :root {
            font-family: Arial, sans-serif;
            color: #0f172a;
            background: #f8fafc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .login-card { width: 100%; max-width: 28rem; padding: 1.5rem; background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; box-shadow: 0 1px 2px rgb(15 23 42 / 5%); }
        .login-header { margin-bottom: 1rem; text-align: center; }
        .login-header h1 { margin: 0; color: #1e293b; font-size: 1.25rem; line-height: 1.75rem; }
        .login-header p { margin: .125rem 0 0; color: #64748b; font-size: .75rem; }
        .notice { margin-bottom: .75rem; padding: .625rem; border-radius: .5rem; font-size: .75rem; }
        .notice-success { color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .notice-error { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; }
        .login-form { display: grid; gap: .75rem; }
        .field-label { display: block; margin-bottom: .125rem; color: #334155; font-size: .75rem; font-weight: 600; }
        .field-control { width: 100%; padding: .5rem .75rem; color: #0f172a; background: #fff; border: 1px solid #cbd5e1; border-radius: .5rem; font-size: .875rem; outline: none; }
        .field-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgb(59 130 246 / 25%); }
        .password-field { position: relative; }
        .password-field .field-control { padding-right: 2.5rem; }
        .password-toggle { position: absolute; top: 0; right: 0; bottom: 0; display: flex; align-items: center; padding: 0 .75rem; color: #94a3b8; background: transparent; border: 0; cursor: pointer; }
        .password-toggle:hover { color: #475569; }
        .password-toggle:focus-visible, .login-link:focus-visible, .submit-button:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .field-help { margin: .125rem 0 0; color: #94a3b8; font-size: 11px; }
        .login-actions { display: flex; justify-content: space-between; padding-top: .125rem; font-size: .75rem; }
        .login-link { color: #2563eb; font-weight: 600; }
        .login-link:hover, .home-link:hover { text-decoration: underline; }
        .submit-button { width: 100%; margin-top: .25rem; padding: .5rem; color: #fff; background: #2563eb; border: 0; border-radius: .5rem; box-shadow: 0 1px 2px rgb(15 23 42 / 10%); cursor: pointer; font-size: .875rem; font-weight: 600; }
        .submit-button:hover { background: #1d4ed8; }
        .home-link-wrap { margin-top: 1rem; text-align: center; }
        .home-link { color: #475569; font-size: .75rem; font-weight: 600; }
        @media (max-width: 30rem) { .login-card { padding: 1.25rem; } }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <h1>School Portal Login</h1>
            <p>Sign in to your institutional account</p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="notice notice-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="notice notice-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/auth/authenticate" method="POST" class="login-form">
            <div>
            <label class="field-label">Select Role</label>
            <select name="role" required class="field-control">
                    <option value="">-- Choose Role --</option>
                    <option value="headteacher" <?= (isset($old['role']) && $old['role'] === 'headteacher') ? 'selected' : '' ?>>Headteacher</option>
                    <option value="school_it" <?= (isset($old['role']) && $old['role'] === 'school_it') ? 'selected' : '' ?>>School IT Officer</option>
                    <option value="teacher" <?= (isset($old['role']) && $old['role'] === 'teacher') ? 'selected' : '' ?>>Teacher</option>
                    <option value="student" <?= (isset($old['role']) && $old['role'] === 'student') ? 'selected' : '' ?>>Student</option>
                </select>
            </div>

            <div>
                <label class="field-label">School Name</label>
                <input type="text" name="school_name" required 
                       value="<?= htmlspecialchars($old['school_name'] ?? '') ?>"
                       class="field-control"
                       placeholder="Enter exact school name">
            </div>

            <div>
                <label class="field-label">Email, Phone, or ID Number</label>
                <input type="text" name="identity" required 
                       value="<?= htmlspecialchars($old['identity'] ?? '') ?>"
                       class="field-control"
                       placeholder="Enter your email, phone, or ID">
            </div>

            <div>
                <label class="field-label">Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="password"
                           class="field-control"
                           placeholder="Enter your password">
                    <button type="button" onclick="togglePassword('password', 'eye-icon')" class="password-toggle" aria-label="Show password">
                        <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
                <p class="field-help">Students can leave password blank if not required.</p>
            </div>

            <div class="login-actions">
                <a href="<?= BASE_URL ?>/auth/forgot_password" class="login-link">Forgot Password?</a>
            </div>

            <button type="submit" 
                    class="submit-button">
                Sign In
            </button>
        </form>

        <div class="home-link-wrap">
            <a href="<?= BASE_URL ?>/home" class="home-link">
                &larr; Return to Home
            </a>
        </div>
    </div>

    <script>
    function togglePassword(fieldId, iconId) {
        const input = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />`;
        } else {
            input.type = 'password';
            icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />`;
        }
    }
    </script>
</body>
</html>