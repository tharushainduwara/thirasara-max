<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already authenticated
if (isLoggedIn()) {
    redirectBasedOnRole($_SESSION['user_role']);
}

$nameValue = '';
$emailValue = '';
$phoneValue = '';
$addressValue = '';

// Handle Registration Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $nameValue = $name;
    $emailValue = $email;
    $phoneValue = $phone;
    $addressValue = $address;

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($confirmPassword)) {

        setFlash(
            'error',
            'Please fill in all required fields.'
        );
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        setFlash(
            'error',
            'Please enter a valid email address.'
        );
    } elseif (strlen($name) < 2) {

        setFlash(
            'error',
            'Please enter your full name.'
        );
    } elseif (strlen($password) < 8) {

        setFlash(
            'error',
            'Password must be at least 8 characters long.'
        );
    } elseif ($password !== $confirmPassword) {

        setFlash(
            'error',
            'Passwords do not match.'
        );
    } else {

        try {

            $db = Database::getConnection();

            // Check whether email already exists
            $checkStmt = $db->prepare("
                SELECT id
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $checkStmt->execute([$email]);

            if ($checkStmt->fetch()) {

                setFlash(
                    'error',
                    'An account with this email address already exists. Please sign in instead.'
                );
            } else {

                // Hash password securely
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Create customer account
                $stmt = $db->prepare("
                    INSERT INTO users
                    (
                        name,
                        email,
                        password,
                        phone,
                        address,
                        role,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'customer',
                        'active'
                    )
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $hashedPassword,
                    !empty($phone) ? $phone : null,
                    !empty($address) ? $address : null
                ]);

                setFlash(
                    'success',
                    'Your account has been created successfully. Please sign in.'
                );

                // Redirect to login page
                header(
                    'Location: ' .
                        APP_URL .
                        '/login.php'
                );

                exit;
            }
        } catch (Exception $e) {

            // Log the real error internally
            error_log(
                'Registration Error: ' .
                    $e->getMessage()
            );

            // Do not expose database details
            setFlash(
                'error',
                'Unable to create your account right now. Please try again shortly.'
            );
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Create Account |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <meta
        name="description"
        content="Create your Thirasara Max Mobile customer account.">

    <!-- Google Font -->
    <link
        rel="preconnect"
        href="https://fonts.googleapis.com">

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <style>
        /* RESET */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: #222;
        }


        /* PAGE BACKGROUND */

        .page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;

            background-image:
                linear-gradient(135deg,
                    rgba(0, 0, 0, 0.72),
                    rgba(0, 0, 0, 0.48)),
                url('<?php echo APP_URL; ?>/assets/images/login-background.jpg');

            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }


        /* RED BACKGROUND GLOW */

        .page::before {
            content: "";

            position: absolute;

            width: 450px;
            height: 450px;

            top: -180px;
            left: -150px;

            background: rgba(229, 43, 52, 0.28);

            border-radius: 50%;

            filter: blur(110px);

            pointer-events: none;
        }


        /* BLUE BACKGROUND GLOW */

        .page::after {
            content: "";

            position: absolute;

            width: 400px;
            height: 400px;

            right: -150px;
            bottom: -150px;

            background: rgba(37, 99, 235, 0.22);

            border-radius: 50%;

            filter: blur(110px);

            pointer-events: none;
        }


        /* HEADER */

        .header {
            width: 100%;
            padding: 20px;

            position: relative;
            z-index: 2;
        }

        .header-inner {
            max-width: 1100px;

            margin: 0 auto;

            display: flex;
            align-items: center;
            justify-content: space-between;
        }


        /* LOGO */

        .logo-link {
            display: flex;
            align-items: center;

            gap: 10px;

            text-decoration: none;
            color: white;
        }

        .logo {
            width: 42px;
            height: 42px;

            background: white;

            border: 1px solid rgba(255, 255, 255, 0.5);

            border-radius: 10px;

            display: flex;
            align-items: center;
            justify-content: center;

            overflow: hidden;

            box-shadow:
                0 5px 20px rgba(0, 0, 0, 0.18);
        }

        .logo img {
            width: 34px;
            height: 34px;

            object-fit: contain;
        }


        /* BRAND */

        .brand-name {
            font-size: 14px;
            font-weight: 700;

            color: white;
        }

        .brand-subtitle {
            margin-top: 2px;

            font-size: 9px;

            color: rgba(255, 255, 255, 0.65);

            letter-spacing: 1.5px;

            text-transform: uppercase;
        }


        /* BACK LINK */

        .back-link {
            color: rgba(255, 255, 255, 0.85);

            text-decoration: none;

            font-size: 13px;

            transition: color 0.2s;
        }

        .back-link:hover {
            color: #fff;
        }


        /* MAIN */

        .main {
            flex: 1;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 25px 20px 45px;

            position: relative;
            z-index: 2;
        }

        .register-container {
            width: 100%;
            max-width: 480px;
        }


        /* REGISTER CARD */

        .register-card {
            width: 100%;

            background: rgba(255, 255, 255, 0.95);

            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);

            border: 1px solid rgba(255, 255, 255, 0.8);

            border-radius: 18px;

            padding: 32px 35px;

            box-shadow:
                0 25px 70px rgba(0, 0, 0, 0.28),
                0 0 40px rgba(229, 43, 52, 0.08);
        }


        /* HEADER */

        .register-header {
            text-align: center;

            margin-bottom: 24px;
        }

        .mobile-logo {
            width: 55px;
            height: 55px;

            margin: 0 auto 13px;

            background: white;

            border: 1px solid #e2e2e2;

            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            overflow: hidden;

            box-shadow:
                0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .mobile-logo img {
            width: 44px;
            height: 44px;

            object-fit: contain;
        }

        .register-header h1 {
            margin: 0;

            font-size: 25px;
            font-weight: 700;

            color: #222;
        }

        .register-header p {
            margin: 7px 0 0;

            font-size: 13px;

            line-height: 1.6;

            color: #777;
        }


        /* FLASH MESSAGE */

        .flash-message {
            margin-bottom: 18px;
        }


        /* FORM */

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;

            margin-bottom: 7px;

            font-size: 13px;

            font-weight: 600;

            color: #444;
        }


        /* INPUT */

        .input-wrapper {
            position: relative;
        }

        .input {
            width: 100%;

            height: 44px;

            padding: 0 14px;

            border: 1px solid #ddd;

            border-radius: 9px;

            background: #fafafa;

            color: #222;

            font-family: inherit;

            font-size: 13px;

            outline: none;

            transition:
                border-color 0.2s,
                background 0.2s,
                box-shadow 0.2s;
        }

        .input:focus {
            background: #fff;

            border-color: #e52b34;

            box-shadow:
                0 0 0 3px rgba(229, 43, 52, 0.08);
        }

        .input::placeholder {
            color: #aaa;
        }


        /* TEXTAREA */

        textarea.input {
            height: 70px;

            padding: 11px 14px;

            resize: vertical;

            min-height: 70px;
        }


        /* PASSWORD INPUT */

        .password-input {
            padding-right: 44px;
        }

        .input-icon {
            position: absolute;

            right: 13px;
            top: 50%;

            transform: translateY(-50%);

            border: none;

            background: transparent;

            padding: 0;

            color: #999;

            cursor: pointer;

            font-size: 15px;

            line-height: 1;
        }

        .input-icon:hover {
            color: #e52b34;
        }


        /* TWO COLUMN */

        .form-row {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 14px;
        }


        /* PASSWORD HINT */

        .password-hint {
            margin-top: 5px;

            font-size: 10px;

            color: #999;
        }


        /* CREATE BUTTON */

        .register-button {
            width: 100%;

            height: 46px;

            margin-top: 5px;

            border: none;

            border-radius: 9px;

            background: #e52b34;

            color: white;

            font-family: inherit;

            font-size: 13px;

            font-weight: 600;

            cursor: pointer;

            transition:
                background 0.2s,
                transform 0.15s,
                box-shadow 0.2s;
        }

        .register-button:hover {
            background: #c9232c;

            box-shadow:
                0 7px 18px rgba(229, 43, 52, 0.25);
        }

        .register-button:active {
            transform: translateY(1px);
        }


        /* DIVIDER */

        .divider {
            display: flex;

            align-items: center;

            gap: 12px;

            margin: 20px 0;
        }

        .divider span {
            flex: 1;

            height: 1px;

            background: #e8e8e8;
        }

        .divider p {
            margin: 0;

            font-size: 10px;

            font-weight: 600;

            color: #999;

            letter-spacing: 1px;
        }


        /* GOOGLE BUTTON */

        .google-button {
            width: 100%;

            height: 46px;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 10px;

            border: 1px solid #ddd;

            border-radius: 9px;

            background: #fff;

            color: #444;

            text-decoration: none;

            font-family: 'Inter', sans-serif;

            font-size: 13px;

            font-weight: 600;

            transition:
                background 0.2s,
                border-color 0.2s,
                box-shadow 0.2s;
        }

        .google-button:hover {
            background: #fafafa;

            border-color: #ccc;

            box-shadow:
                0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .google-icon {
            width: 18px;
            height: 18px;

            flex-shrink: 0;
        }


        /* LOGIN LINK */

        .login-link {
            text-align: center;

            margin-top: 22px;

            padding-top: 20px;

            border-top: 1px solid #eee;
        }

        .login-link p {
            margin: 0;

            font-size: 12px;

            color: #777;
        }

        .login-link a {
            color: #e52b34;

            font-weight: 600;

            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }


        /* FOOTER */

        .footer {
            text-align: center;

            padding: 18px 20px;

            font-size: 10px;

            color: rgba(255, 255, 255, 0.65);

            position: relative;

            z-index: 2;
        }


        /* MOBILE */

        @media (max-width: 600px) {

            .header {
                padding: 16px;
            }

            .brand-subtitle {
                display: none;
            }

            .back-link {
                font-size: 12px;
            }

            .main {
                padding: 20px 15px 35px;
            }

            .register-card {
                padding: 27px 22px;

                border-radius: 14px;
            }

            .register-header h1 {
                font-size: 23px;
            }

            .register-header p {
                font-size: 12px;
            }

            .form-row {
                grid-template-columns: 1fr;

                gap: 0;
            }
        }


        /* SMALL PHONES */

        @media (max-width: 380px) {

            .header {
                padding: 12px;
            }

            .main {
                padding: 15px 12px 30px;
            }

            .register-card {
                padding: 24px 18px;
            }

            .logo {
                width: 38px;
                height: 38px;
            }

            .logo img {
                width: 30px;
                height: 30px;
            }
        }
    </style>

</head>


<body>

    <div class="page">


        <!-- HEADER -->

        <header class="header">

            <div class="header-inner">

                <!-- Logo -->

                <a
                    href="<?php echo APP_URL; ?>/index.php"
                    class="logo-link">

                    <div class="logo">

                        <img
                            src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                            alt="Thirasara Max"

                            onerror="
                            this.onerror=null;
                            this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';
                        ">

                    </div>

                    <div>

                        <div class="brand-name">
                            THIRASARA MAX
                        </div>

                        <div class="brand-subtitle">
                            Mobile & Accessories
                        </div>

                    </div>

                </a>


                <!-- Back -->

                <a
                    href="<?php echo APP_URL; ?>/index.php"
                    class="back-link">
                    ← Back to Store
                </a>

            </div>

        </header>


        <!-- MAIN -->

        <main class="main">

            <div class="register-container">

                <div class="register-card">


                    <!-- FLASH MESSAGE -->

                    <?php if (function_exists('displayFlash')): ?>

                        <div class="flash-message">

                            <?php displayFlash(); ?>

                        </div>

                    <?php endif; ?>


                    <!-- REGISTER HEADER -->

                    <div class="register-header">

                        <div class="mobile-logo">

                            <img
                                src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                                alt="Thirasara Max"

                                onerror="
                                this.onerror=null;
                                this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';
                            ">

                        </div>

                        <h1>
                            Create Account
                        </h1>

                        <p>
                            Create your Thirasara Max customer account.
                        </p>

                    </div>


                    <!-- REGISTRATION FORM -->

                    <form
                        method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">


                        <!-- NAME -->

                        <div class="form-group">

                            <label
                                for="name"
                                class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="<?php echo htmlspecialchars($nameValue); ?>"
                                required
                                autocomplete="name"
                                placeholder="Enter your full name"
                                class="input"
                                maxlength="150">

                        </div>


                        <!-- EMAIL -->

                        <div class="form-group">

                            <label
                                for="email"
                                class="form-label">
                                Email Address
                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?php echo htmlspecialchars($emailValue); ?>"
                                required
                                autocomplete="email"
                                placeholder="Enter your email"
                                class="input"
                                maxlength="150">

                        </div>


                        <!-- PHONE -->

                        <div class="form-group">

                            <label
                                for="phone"
                                class="form-label">
                                Phone Number
                            </label>

                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?php echo htmlspecialchars($phoneValue); ?>"
                                autocomplete="tel"
                                placeholder="Enter your phone number"
                                class="input"
                                maxlength="20">

                        </div>


                        <!-- ADDRESS -->

                        <div class="form-group">

                            <label
                                for="address"
                                class="form-label">
                                Address
                            </label>

                            <textarea
                                id="address"
                                name="address"
                                autocomplete="street-address"
                                placeholder="Enter your address"
                                class="input"><?php echo htmlspecialchars($addressValue); ?></textarea>

                        </div>


                        <!-- PASSWORD ROW -->

                        <div class="form-row">


                            <!-- PASSWORD -->

                            <div class="form-group">

                                <label
                                    for="password"
                                    class="form-label">
                                    Password
                                </label>

                                <div class="input-wrapper">

                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="Create password"
                                        class="input password-input"
                                        minlength="8">

                                    <button
                                        type="button"
                                        class="input-icon"
                                        id="passwordToggle"
                                        onclick="togglePassword('password', 'passwordToggle')"
                                        aria-label="Show password">
                                        👁
                                    </button>

                                </div>

                                <div class="password-hint">
                                    Minimum 8 characters
                                </div>

                            </div>


                            <!-- CONFIRM PASSWORD -->

                            <div class="form-group">

                                <label
                                    for="confirm_password"
                                    class="form-label">
                                    Confirm Password
                                </label>

                                <div class="input-wrapper">

                                    <input
                                        type="password"
                                        id="confirm_password"
                                        name="confirm_password"
                                        required
                                        autocomplete="new-password"
                                        placeholder="Confirm password"
                                        class="input password-input"
                                        minlength="8">

                                    <button
                                        type="button"
                                        class="input-icon"
                                        id="confirmPasswordToggle"
                                        onclick="togglePassword('confirm_password', 'confirmPasswordToggle')"
                                        aria-label="Show password">
                                        👁
                                    </button>

                                </div>

                            </div>

                        </div>


                        <!-- CREATE ACCOUNT -->

                        <button
                            type="submit"
                            class="register-button">
                            Create Account
                        </button>

                    </form>


                    <!-- GOOGLE SIGN IN -->

                    <div class="divider">

                        <span></span>

                        <p>OR</p>

                        <span></span>

                    </div>


                    <a
                        href="<?php echo APP_URL; ?>/google-login.php"
                        class="google-button">

                        <svg
                            class="google-icon"
                            viewBox="0 0 24 24"
                            aria-hidden="true">

                            <path
                                fill="#4285F4"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />

                            <path
                                fill="#34A853"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />

                            <path
                                fill="#FBBC05"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" />

                            <path
                                fill="#EA4335"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" />

                        </svg>

                        <span>
                            Continue with Google
                        </span>

                    </a>


                    <!-- LOGIN -->

                    <div class="login-link">

                        <p>

                            Already have an account?

                            <a
                                href="<?php echo APP_URL; ?>/login.php">
                                Sign in
                            </a>

                        </p>

                    </div>


                </div>

            </div>

        </main>


        <!-- FOOTER -->

        <footer class="footer">

            © <?php echo date('Y'); ?>

            <?php echo htmlspecialchars(APP_NAME); ?>.

            All rights reserved.

        </footer>


    </div>


    <!-- JAVASCRIPT -->

    <script>
        function togglePassword(inputId, buttonId) {

            const passwordInput =
                document.getElementById(inputId);

            const toggleButton =
                document.getElementById(buttonId);

            if (!passwordInput || !toggleButton) {
                return;
            }

            if (passwordInput.type === 'password') {

                passwordInput.type = 'text';

                toggleButton.textContent = '🙈';

                toggleButton.setAttribute(
                    'aria-label',
                    'Hide password'
                );

            } else {

                passwordInput.type = 'password';

                toggleButton.textContent = '👁';

                toggleButton.setAttribute(
                    'aria-label',
                    'Show password'
                );
            }
        }


        // Client-side password match validation

        document.querySelector('form').addEventListener(
            'submit',
            function(event) {

                const password =
                    document.getElementById('password').value;

                const confirmPassword =
                    document.getElementById('confirm_password').value;

                if (password !== confirmPassword) {

                    event.preventDefault();

                    alert('Passwords do not match.');

                }

            }
        );
    </script>

</body>

</html>
