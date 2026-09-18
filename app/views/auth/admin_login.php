<?php

$oldIdentity = $_SESSION['old_input']['identity'] ?? '';

?>

<!DOCTYPE html>
<html lang="en" style="height: 100%; overflow: hidden;">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Administrator Login | Edulincore System</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/layout.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/cards.css">

</head>

<body style="height: 100%; margin: 0; display: flex; flex-direction: column; overflow: hidden;">

    <header class="mockup-header" style="flex-shrink: 0;">

        <a href="<?= BASE_URL ?>/home" class="brand-logo">
            Edulincore
        </a>

        <nav class="mockup-nav">

            <a href="<?= BASE_URL ?>/home">
                Home
            </a>

            <a href="<?= BASE_URL ?>/home#features">
                Features
            </a>

            <a href="<?= BASE_URL ?>/home#about">
                About
            </a>

            <a href="<?= BASE_URL ?>/home#contact">
                Contact
            </a>

        </nav>

    </header>


    <main
        class="main-wrapper"
        style="flex: 1; display: flex; flex-direction: column; overflow: hidden;"
    >

        <section
            class="content-section active"
            style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1rem 1rem; overflow: hidden; box-sizing: border-box;"
        >

            <h1
                class="hero-title"
                style="font-size: 1.6rem; margin-bottom: 0.25rem; line-height: 1.2;"
            >
                System Administration
            </h1>

            <p
                class="hero-subtitle"
                style="margin-bottom: 0.85rem; font-size: 0.875rem;"
            >
                Sign in with your administrative credentials.
            </p>


            <?php if (!empty($_SESSION['login_error'])): ?>

                <div
                    style="background: #fee2e2; border: 1px solid #fecaca; color: #dc2626; padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-size: 0.8rem; max-width: 420px; width: 100%; margin-bottom: 0.75rem; text-align: center;"
                >

                    <?= htmlspecialchars($_SESSION['login_error']) ?>

                    <?php unset($_SESSION['login_error']); ?>

                </div>

            <?php endif; ?>


            <div
                style="background: rgba(255,255,255,0.95); padding: 1.25rem 2rem; border-radius: 0.75rem; border: 1px solid #e2e8f0; max-width: 420px; width: 100%; text-align: left; box-shadow: 0 4px 12px rgba(0,0,0,0.03);"
            >

                <form
                    action="<?= BASE_URL ?>/auth/admin_authenticate"
                    method="POST"
                >

                    <input
                        type="hidden"
                        name="role"
                        value="admin"
                    >


                    <div style="margin-bottom: 0.75rem;">

                        <label
                            for="identity"
                            style="display: block; font-weight: 700; color: #1e3a8a; font-size: 0.8rem; margin-bottom: 0.2rem;"
                        >
                            Administrator Email
                        </label>

                        <input
                            type="email"
                            id="identity"
                            name="identity"
                            value="<?= htmlspecialchars($oldIdentity) ?>"
                            required
                            autocomplete="username"
                            placeholder="admin@edulincore.com"
                            style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 0.85rem; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                        >

                    </div>


                    <div style="margin-bottom: 0.85rem;">

                        <label
                            for="password"
                            style="display: block; font-weight: 700; color: #1e3a8a; font-size: 0.8rem; margin-bottom: 0.2rem;"
                        >
                            Password
                        </label>

                        <div
                            style="position: relative; display: flex; align-items: center;"
                        >

                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••••"
                                style="width: 100%; padding: 0.5rem 2.5rem 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 0.85rem; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                            >

                            <button
                                type="button"
                                id="togglePassword"
                                onclick="togglePasswordVisibility()"
                                style="position: absolute; right: 0.5rem; background: none; border: none; cursor: pointer; color: #64748b; font-size: 0.8rem; padding: 0.2rem; display: flex; align-items: center; justify-content: center;"
                                title="Show/Hide Password"
                                aria-label="Show or hide password"
                            >
                                &#128065;
                            </button>

                        </div>

                    </div>


                    <div
                        style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem; font-size: 0.8rem;"
                    >

                        <label
                            style="display: flex; align-items: center; color: #475569; cursor: pointer;"
                        >

                            <input
                                type="checkbox"
                                name="remember"
                                style="margin-right: 0.3rem;"
                            >

                            Remember this device

                        </label>


                        <a
                            href="<?= BASE_URL ?>/auth/forgot_password"
                            style="color: #2563eb; text-decoration: none;"
                        >
                            Forgot password?
                        </a>

                    </div>


                    <button
                        type="submit"
                        class="btn-primary"
                        style="width: 100%; justify-content: center; padding: 0.6rem; font-size: 0.9rem; cursor: pointer;"
                    >
                        Sign In as Admin &rarr;
                    </button>

                </form>

            </div>


            <div
                style="margin-top: 0.75rem; font-size: 0.8rem; color: #475569;"
            >

                Looking for school logins?

                <a
                    href="<?= BASE_URL ?>/auth/school_login"
                    style="color: #2563eb; text-decoration: none; font-weight: 600;"
                >
                    Switch to School Portal
                </a>

            </div>

        </section>

    </main>


    <footer
        class="mockup-footer"
        style="flex-shrink: 0; padding: 0.5rem 1rem; font-size: 0.8rem;"
    >

        <span>
            Edulincore &reg; <?php echo date('Y'); ?>. All Rights Reserved.
        </span>

        <span style="color: #334155;">
            |
        </span>

        <span>
            Contact: 0977323918
        </span>

        <span style="color: #334155;">
            |
        </span>

        <a href="<?= BASE_URL ?>/home#about">
            Privacy Policy
        </a>

        <span style="color: #334155;">
            |
        </span>

        <a href="<?= BASE_URL ?>/home#about">
            Terms of Service
        </a>

        <span style="color: #334155;">
            |
        </span>

        <a href="<?= BASE_URL ?>/home#contact">
            Contact Support
        </a>

    </footer>


    <script>

        function togglePasswordVisibility() {

            const passwordInput =
                document.getElementById('password');

            const toggleBtn =
                document.getElementById('togglePassword');

            if (passwordInput.type === 'password') {

                passwordInput.type = 'text';

                toggleBtn.style.color = '#2563eb';

            } else {

                passwordInput.type = 'password';

                toggleBtn.style.color = '#64748b';
            }
        }

    </script>

</body>

</html>

<?php

unset($_SESSION['old_input']);

?>