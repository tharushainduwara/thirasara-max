<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already authenticated
if (isLoggedIn()) {
    redirectBasedOnRole($_SESSION['user_role']);
}

$emailValue = '';

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $emailValue = $email;

    if (empty($email) || empty($password)) {

        setFlash(
            'error',
            'Please enter your email address and password.'
        );
    } else {

        try {

            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT id, name, email, password, role, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                if ($user['status'] !== 'active') {

                    setFlash(
                        'error',
                        'Your account is currently ' .
                            htmlspecialchars($user['status']) .
                            '. Please contact Thirasara Max.'
                    );
                } else {

                    // Prevent session fixation
                    session_regenerate_id(true);

                    // Store authenticated user
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];

                    setFlash(
                        'success',
                        'Welcome back, ' .
                            htmlspecialchars($user['name']) .
                            '!'
                    );

                    redirectBasedOnRole($user['role']);
                }
            } else {

                setFlash(
                    'error',
                    'The email address or password you entered is incorrect.'
                );
            }
        } catch (Exception $e) {

            // Log the real error internally
            error_log('Login Error: ' . $e->getMessage());

            // Do not expose database details
            setFlash(
                'error',
                'Unable to sign you in right now. Please try again shortly.'
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
        Sign In |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <meta
        name="description"
        content="Sign in to your Thirasara Max Mobile customer account.">

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


        /* Red background glow */

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


        /* Blue background glow */

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


        /* Logo */

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


        /* Brand */

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


        /* Back */

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

            padding: 30px 20px 50px;

            position: relative;
            z-index: 2;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }


        /* LOGIN CARD */

        .login-card {

            width: 100%;

            background: rgba(255, 255, 255, 0.95);

            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);

            border: 1px solid rgba(255, 255, 255, 0.8);

            border-radius: 18px;

            padding: 35px;

            box-shadow:
                0 25px 70px rgba(0, 0, 0, 0.28),
                0 0 40px rgba(229, 43, 52, 0.08);
        }


        /* LOGIN HEADER*/

        .login-header {
            text-align: center;

            margin-bottom: 28px;
        }

        .mobile-logo {

            width: 58px;
            height: 58px;

            margin: 0 auto 16px;

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

            width: 46px;
            height: 46px;

            object-fit: contain;
        }

        .login-header h1 {

            margin: 0;

            font-size: 26px;

            font-weight: 700;

            color: #222;
        }

        .login-header p {

            margin: 8px 0 0;

            font-size: 13px;

            line-height: 1.6;

            color: #777;
        }


        /* FLASH MESSAGE */

        .flash-message {
            margin-bottom: 20px;
        }


        /*  FORM */

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {

            display: block;

            margin-bottom: 7px;

            font-size: 13px;

            font-weight: 600;

            color: #444;
        }


        /* Password heading */

        .password-row {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 7px;
        }

        .password-row .form-label {
            margin-bottom: 0;
        }


        /* Forgot password */

        .forgot-link {

            font-size: 11px;

            font-weight: 600;

            color: #e52b34;

            text-decoration: none;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }


        /* INPUT */

        .input-wrapper {
            position: relative;
        }

        .input {

            width: 100%;

            height: 46px;

            padding: 0 44px 0 14px;

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


        /* Password button */

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


        /* REMEMBER ME */

        .remember {

            display: flex;

            align-items: center;

            gap: 7px;

            margin: 5px 0 20px;
        }

        .remember input {

            width: 15px;
            height: 15px;

            accent-color: #e52b34;

            cursor: pointer;
        }

        .remember label {

            font-size: 12px;

            color: #666;

            cursor: pointer;
        }


        /* LOGIN BUTTON */

        .login-button {

            width: 100%;

            height: 46px;

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

        .login-button:hover {

            background: #c9232c;

            box-shadow:
                0 7px 18px rgba(229, 43, 52, 0.25);
        }

        .login-button:active {
            transform: translateY(1px);
        }

        /* DIVIDER */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0;
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

        /*  GOOGLE BUTTON */
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
            transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .google-button:hover {
            background: #fafafa;
            border-color: #ccc;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .google-icon {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        /* REGISTER */

        .register {

            text-align: center;

            margin-top: 24px;

            padding-top: 22px;

            border-top: 1px solid #eee;
        }

        .register p {

            margin: 0;

            font-size: 12px;

            color: #777;
        }

        .register a {

            color: #e52b34;

            font-weight: 600;

            text-decoration: none;
        }

        .register a:hover {
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

                padding: 20px 15px 40px;

                align-items: center;
            }

            .login-card {

                padding: 28px 22px;

                border-radius: 14px;
            }

            .login-header h1 {
                font-size: 23px;
            }

            .login-header p {
                font-size: 12px;
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

            .login-card {
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


                <!-- Back To Store -->

                <a
                    href="<?php echo APP_URL; ?>/index.php"
                    class="back-link">
                    ← Back to Store
                </a>


            </div>

        </header>



        <!-- MAIN -->

        <main class="main">


            <div class="login-container">


                <!-- Login Card -->

                <div class="login-card">


                    <!-- Flash Message -->

                    <?php if (function_exists('displayFlash')): ?>

                        <div class="flash-message">
                            <?php displayFlash(); ?>
                        </div>

                    <?php endif; ?>



                    <!-- Login Header -->

                    <div class="login-header">


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
                            Welcome Back
                        </h1>


                        <p>
                            Sign in to your Thirasara Max account.
                        </p>


                    </div>



                    <!-- LOGIN FORM-->

                    <form
                        method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">


                        <!-- Email -->

                        <div class="form-group">

                            <label
                                for="email"
                                class="form-label">
                                Email Address
                            </label>


                            <div class="input-wrapper">

                                <input
                                    type="email"
                                    id="email"
                                    name="email"

                                    value="<?php
                                            echo htmlspecialchars($emailValue);
                                            ?>"

                                    required

                                    autocomplete="email"

                                    placeholder="Enter your email"

                                    class="input">

                            </div>

                        </div>



                        <!-- Password -->

                        <div class="form-group">


                            <div class="password-row">

                                <label
                                    for="password"
                                    class="form-label">
                                    Password
                                </label>


                                <a
                                    href="<?php echo APP_URL; ?>/forgot-password.php"
                                    class="forgot-link">
                                    Forgot password?
                                </a>

                            </div>


                            <div class="input-wrapper">


                                <input
                                    type="password"
                                    id="password"
                                    name="password"

                                    required

                                    autocomplete="current-password"

                                    placeholder="Enter your password"

                                    class="input">


                                <button
                                    type="button"

                                    class="input-icon"

                                    id="passwordToggle"

                                    onclick="togglePasswordVisibility()"

                                    aria-label="Show password">
                                    👁
                                </button>


                            </div>

                        </div>



                        <!-- Remember Me -->

                        <div class="remember">


                            <input
                                type="checkbox"
                                id="remember"
                                name="remember">


                            <label for="remember">
                                Remember me
                            </label>


                        </div>



                        <!-- Sign In -->

                        <button
                            type="submit"
                            class="login-button">
                            Sign In
                        </button>


                    </form>

                    <!--GOOGLE SIGN IN -->
                    <div class="divider"> <span></span>
                        <p>OR</p> <span></span>
                    </div> <a href="<?php echo APP_URL; ?>/google-login.php" class="google-button"> <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" />
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" />
                        </svg> <span> Continue with Google </span> </a> <!-- Register -->
                    <div class="register">
                        <p> Don't have an account? <a href="<?php echo APP_URL; ?>/register.php"> Create one </a> </p>
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



    <!--JAVASCRIPT-->

    <script>
        function togglePasswordVisibility() {

            const passwordInput =
                document.getElementById('password');

            const toggleButton =
                document.getElementById('passwordToggle');


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
    </script>


</body>

</html>