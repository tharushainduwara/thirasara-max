<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect if already authenticated
if (isLoggedIn()) {
    redirectBasedOnRole($_SESSION['user_role']);
}

$token = $_GET['token'] ?? '';

$validToken = false;
$user = null;
$errorMessage = '';


// VALIDATE RESET TOKEN

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {

    $errorMessage = 'This password reset link is invalid or has expired.';

} else {

    try {

        $db = Database::getConnection();

        /*
         * Hash the token before comparing it with the
         * hashed token stored in the database.
         */
        $hashedToken = hash(
            'sha256',
            $token
        );


        /*
         * Find a valid, non-expired reset token.
         */
        $stmt = $db->prepare("
            SELECT
                pr.id AS reset_id,
                pr.user_id,
                pr.expires_at,
                u.name,
                u.email,
                u.status
            FROM password_resets pr
            INNER JOIN users u
                ON u.id = pr.user_id
            WHERE pr.token = ?
              AND pr.expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute([
            $hashedToken
        ]);

        $result = $stmt->fetch();


        if ($result) {

            /*
             * Do not allow inactive/suspended accounts
             * to reset their password.
             */
            if ($result['status'] !== 'active') {

                $errorMessage =
                    'This account is currently unavailable. Please contact Thirasara Max.';

            } else {

                $validToken = true;

                $user = $result;
            }

        } else {

            $errorMessage =
                'This password reset link is invalid or has expired.';
        }

    } catch (Exception $e) {

        error_log(
            'Reset Password Token Error: ' .
            $e->getMessage()
        );

        $errorMessage =
            'Unable to process this password reset link right now. Please try again later.';
    }
}



// HANDLE PASSWORD RESET

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $validToken &&
    $user
) {

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';


    // Validate password

    if (empty($password) || empty($confirmPassword)) {

        setFlash(
            'error',
            'Please enter your new password and confirm it.'
        );

    } elseif (strlen($password) < 8) {

        setFlash(
            'error',
            'Your new password must be at least 8 characters long.'
        );

    } elseif ($password !== $confirmPassword) {

        setFlash(
            'error',
            'Passwords do not match.'
        );

    } else {

        try {

            $db = Database::getConnection();


            /*
             * Hash the new password.
             */
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            /*
             * Update the user's password.
             */
            $updateStmt = $db->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
                LIMIT 1
            ");

            $updateStmt->execute([
                $hashedPassword,
                $user['user_id']
            ]);


            /*
             * Delete the reset token immediately.
             *
             * This makes the reset link single-use.
             */
            $deleteStmt = $db->prepare("
                DELETE FROM password_resets
                WHERE id = ?
            ");

            $deleteStmt->execute([
                $user['reset_id']
            ]);


            /*
             * Delete any other reset tokens belonging
             * to this user as an additional security measure.
             */
            $deleteOtherStmt = $db->prepare("
                DELETE FROM password_resets
                WHERE user_id = ?
            ");

            $deleteOtherStmt->execute([
                $user['user_id']
            ]);


            /*
             * Success message.
             */
            setFlash(
                'success',
                'Your password has been reset successfully. You can now sign in with your new password.'
            );


            /*
             * Redirect to login.
             */
            header(
                'Location: ' .
                APP_URL .
                '/login.php'
            );

            exit;

        } catch (Exception $e) {

            error_log(
                'Reset Password Error: ' .
                $e->getMessage()
            );

            setFlash(
                'error',
                'Unable to reset your password right now. Please try again shortly.'
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
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Reset Password |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <meta
        name="description"
        content="Reset your Thirasara Max Mobile account password."
    >


    <!-- GOOGLE FONT -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >


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


        /* PAGE */

        .page {
            min-height: 100vh;

            display: flex;

            flex-direction: column;

            position: relative;

            overflow: hidden;

            background-image:
                linear-gradient(
                    135deg,
                    rgba(0, 0, 0, 0.72),
                    rgba(0, 0, 0, 0.48)
                ),
                url('<?php echo APP_URL; ?>/assets/images/login-background.jpg');

            background-size: cover;

            background-position: center;

            background-repeat: no-repeat;

            background-attachment: fixed;
        }


        /* RED GLOW */

        .page::before {
            content: "";

            position: absolute;

            width: 450px;
            height: 450px;

            top: -180px;
            left: -150px;

            background:
                rgba(229, 43, 52, 0.28);

            border-radius: 50%;

            filter: blur(110px);

            pointer-events: none;
        }


        /* BLUE GLOW */

        .page::after {
            content: "";

            position: absolute;

            width: 400px;
            height: 400px;

            right: -150px;
            bottom: -150px;

            background:
                rgba(37, 99, 235, 0.22);

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

            border:
                1px solid
                rgba(255, 255, 255, 0.5);

            border-radius: 10px;

            display: flex;

            align-items: center;

            justify-content: center;

            overflow: hidden;

            box-shadow:
                0 5px 20px
                rgba(0, 0, 0, 0.18);
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

            color:
                rgba(255, 255, 255, 0.65);

            letter-spacing: 1.5px;

            text-transform: uppercase;
        }


        /* BACK LINK */

        .back-link {
            color:
                rgba(255, 255, 255, 0.85);

            text-decoration: none;

            font-size: 13px;

            transition: color 0.2s;
        }

        .back-link:hover {
            color: white;
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

        .reset-container {
            width: 100%;

            max-width: 420px;
        }


        /* CARD */

        .reset-card {
            width: 100%;

            background:
                rgba(255, 255, 255, 0.95);

            backdrop-filter: blur(15px);

            -webkit-backdrop-filter: blur(15px);

            border:
                1px solid
                rgba(255, 255, 255, 0.8);

            border-radius: 18px;

            padding: 35px;

            box-shadow:
                0 25px 70px
                rgba(0, 0, 0, 0.28),

                0 0 40px
                rgba(229, 43, 52, 0.08);
        }


        /* HEADER */

        .reset-header {
            text-align: center;

            margin-bottom: 28px;
        }


        /* LOGO */

        .mobile-logo {
            width: 58px;
            height: 58px;

            margin:
                0 auto 16px;

            background: white;

            border:
                1px solid #e2e2e2;

            border-radius: 13px;

            display: flex;

            align-items: center;

            justify-content: center;

            overflow: hidden;

            box-shadow:
                0 4px 12px
                rgba(0, 0, 0, 0.05);
        }

        .mobile-logo img {
            width: 46px;
            height: 46px;

            object-fit: contain;
        }


        .reset-header h1 {
            margin: 0;

            font-size: 26px;

            font-weight: 700;

            color: #222;
        }

        .reset-header p {
            margin: 8px 0 0;

            font-size: 13px;

            line-height: 1.6;

            color: #777;
        }


        /* FLASH */

        .flash-message {
            margin-bottom: 20px;
        }


        /* ERROR STATE */

        .invalid-message {
            text-align: center;
        }

        .invalid-icon {
            width: 60px;
            height: 60px;

            margin: 0 auto 18px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            background:
                rgba(229, 43, 52, 0.08);

            color: #e52b34;

            font-size: 25px;
        }

        .invalid-message h2 {
            margin: 0 0 8px;

            font-size: 20px;

            color: #222;
        }

        .invalid-message p {
            margin: 0;

            font-size: 12px;

            line-height: 1.7;

            color: #777;
        }


        /* FORM */

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


        /* INPUT */

        .input-wrapper {
            position: relative;
        }

        .input {
            width: 100%;

            height: 46px;

            padding:
                0 44px 0 14px;

            border:
                1px solid #ddd;

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
            background: white;

            border-color: #e52b34;

            box-shadow:
                0 0 0 3px
                rgba(229, 43, 52, 0.08);
        }

        .input::placeholder {
            color: #aaa;
        }


        /* PASSWORD BUTTON */

        .input-icon {
            position: absolute;

            right: 13px;

            top: 50%;

            transform:
                translateY(-50%);

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


        /* PASSWORD HINT */

        .password-hint {
            margin-top: 6px;

            font-size: 10px;

            color: #999;
        }


        /* BUTTON */

        .reset-button {
            width: 100%;

            height: 46px;

            margin-top: 3px;

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

        .reset-button:hover {
            background: #c9232c;

            box-shadow:
                0 7px 18px
                rgba(229, 43, 52, 0.25);
        }

        .reset-button:active {
            transform:
                translateY(1px);
        }


        /* LINKS */

        .login-link {
            text-align: center;

            margin-top: 24px;

            padding-top: 22px;

            border-top:
                1px solid #eee;
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

            color:
                rgba(255, 255, 255, 0.65);

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
                padding:
                    20px 15px 40px;
            }

            .reset-card {
                padding:
                    28px 22px;

                border-radius: 14px;
            }

            .reset-header h1 {
                font-size: 23px;
            }

            .reset-header p {
                font-size: 12px;
            }
        }


        /* SMALL PHONES */

        @media (max-width: 380px) {

            .header {
                padding: 12px;
            }

            .main {
                padding:
                    15px 12px 30px;
            }

            .reset-card {
                padding:
                    24px 18px;
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


            <!-- LOGO -->

            <a
                href="<?php echo APP_URL; ?>/index.php"
                class="logo-link"
            >

                <div class="logo">

                    <img
                        src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                        alt="Thirasara Max"

                        onerror="
                            this.onerror=null;
                            this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';
                        "
                    >

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


            <!-- BACK TO STORE -->

            <a
                href="<?php echo APP_URL; ?>/index.php"
                class="back-link"
            >
                ← Back to Store
            </a>


        </div>

    </header>


    <!-- MAIN -->

    <main class="main">

        <div class="reset-container">

            <div class="reset-card">


                <?php if (!$validToken): ?>


                    <!-- INVALID TOKEN -->

                    <div class="invalid-message">


                        <div class="invalid-icon">
                            !
                        </div>


                        <h2>
                            Invalid Reset Link
                        </h2>


                        <p>
                            <?php
                            echo htmlspecialchars(
                                $errorMessage
                            );
                            ?>
                        </p>


                    </div>


                    <div class="login-link">

                        <p>

                            Need a new reset link?

                            <a
                                href="<?php echo APP_URL; ?>/forgot-password.php"
                            >
                                Forgot password
                            </a>

                        </p>

                    </div>


                <?php else: ?>


                    <!-- FLASH MESSAGE -->

                    <?php if (function_exists('displayFlash')): ?>

                        <div class="flash-message">

                            <?php displayFlash(); ?>

                        </div>

                    <?php endif; ?>


                    <!-- HEADER -->

                    <div class="reset-header">


                        <div class="mobile-logo">

                            <img
                                src="<?php echo APP_URL; ?>/assets/images/Logo.png"
                                alt="Thirasara Max"

                                onerror="
                                    this.onerror=null;
                                    this.src='<?php echo APP_URL; ?>/assets/images/Logo.jpg';
                                "
                            >

                        </div>


                        <h1>
                            Reset Password
                        </h1>


                        <p>
                            Create a new password for your
                            Thirasara Max account.
                        </p>


                    </div>


                    <!-- RESET FORM -->

                    <form
                        method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>"
                    >


                        <!-- PASSWORD -->

                        <div class="form-group">

                            <label
                                for="password"
                                class="form-label"
                            >
                                New Password
                            </label>


                            <div class="input-wrapper">

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                    placeholder="Enter your new password"
                                    class="input"
                                >


                                <button
                                    type="button"
                                    class="input-icon"
                                    id="passwordToggle"
                                    onclick="togglePassword(
                                        'password',
                                        'passwordToggle'
                                    )"
                                    aria-label="Show password"
                                >
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
                                class="form-label"
                            >
                                Confirm New Password
                            </label>


                            <div class="input-wrapper">

                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                    placeholder="Confirm your new password"
                                    class="input"
                                >


                                <button
                                    type="button"
                                    class="input-icon"
                                    id="confirmPasswordToggle"
                                    onclick="togglePassword(
                                        'confirm_password',
                                        'confirmPasswordToggle'
                                    )"
                                    aria-label="Show password"
                                >
                                    👁
                                </button>

                            </div>

                        </div>


                        <!-- RESET -->

                        <button
                            type="submit"
                            class="reset-button"
                        >
                            Reset Password
                        </button>


                    </form>


                    <!-- LOGIN -->

                    <div class="login-link">

                        <p>

                            Remember your password?

                            <a
                                href="<?php echo APP_URL; ?>/login.php"
                            >
                                Sign in
                            </a>

                        </p>

                    </div>


                <?php endif; ?>


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

    const resetForm =
        document.querySelector('form');

    if (resetForm) {

        resetForm.addEventListener(
            'submit',
            function (event) {

                const password =
                    document.getElementById(
                        'password'
                    ).value;

                const confirmPassword =
                    document.getElementById(
                        'confirm_password'
                    ).value;


                if (password !== confirmPassword) {

                    event.preventDefault();

                    alert(
                        'Passwords do not match.'
                    );
                }

            }
        );
    }

</script>


</body>

</html>

