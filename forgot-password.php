<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

// Redirect if already authenticated
if (isLoggedIn()) {
    redirectBasedOnRole($_SESSION['user_role']);
}

$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = sanitize($_POST['email'] ?? '');

    $emailValue = $email;

    if (empty($email)) {

        setFlash(
            'error',
            'Please enter your email address.'
        );
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        setFlash(
            'error',
            'Please enter a valid email address.'
        );
    } else {

        try {

            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT id, name, email
                FROM users
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $user = $stmt->fetch();

            if ($user) {

                /*Remove old reset tokens for this user. */
                $deleteStmt = $db->prepare("
                    DELETE FROM password_resets
                    WHERE user_id = ?
                ");

                $deleteStmt->execute([
                    $user['id']
                ]);


                /* Generate a secure reset token. */
                $token = bin2hex(random_bytes(32));

                /* Remove old reset tokens for this user. */
                $deleteStmt = $db->prepare("
                DELETE FROM password_resets
                WHERE user_id = ?
                ");

                $deleteStmt->execute([
                    $user['id']
                ]);

                /* Store token and set expiry using MySQL time. */
                $insertStmt = $db->prepare("
                INSERT INTO password_resets
                (
                    user_id,
                   token,
                   expires_at
                )
                VALUES
                (
                    ?,
                    ?,
                    DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                )
                ");

                $insertStmt->execute([
                    $user['id'],
                    hash('sha256', $token)
                ]);

                /* Create password reset URL. */
                $resetLink =
                    APP_URL .
                    '/reset-password.php?token=' .
                    urlencode($token);


                /*EMAIL SECTION*/

                $emailSent = sendPasswordResetEmail(
                    $user['email'],
                    $user['name'],
                    $resetLink
                );

                if (!$emailSent) {
                    error_log(
                        'Failed to send password reset email to: ' .
                            $user['email']
                    );
                }
            }

            setFlash(
                'success',
                'If an account exists with that email address, a password reset link has been sent.'
            );
        } catch (Exception $e) {

            error_log(
                'Forgot Password Error: ' .
                    $e->getMessage()
            );

            setFlash(
                'error',
                'Unable to process your request right now. Please try again shortly.'
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
        Forgot Password |
        <?php echo htmlspecialchars(APP_NAME); ?>
    </title>

    <meta
        name="description"
        content="Reset your Thirasara Max Mobile account password.">

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


        /* PAGE */

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


        /* RED GLOW */

        .page::before {
            content: "";

            position: absolute;

            width: 450px;
            height: 450px;

            top: -180px;
            left: -150px;

            background: rgba(229,
                    43,
                    52,
                    0.28);

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

            background: rgba(37,
                    99,
                    235,
                    0.22);

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

            color:
                rgba(255, 255, 255, 0.65);

            letter-spacing: 1.5px;

            text-transform: uppercase;
        }


        /* BACK */

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

        .forgot-container {
            width: 100%;

            max-width: 420px;
        }


        /* CARD */

        .forgot-card {
            width: 100%;

            background:
                rgba(255, 255, 255, 0.95);

            backdrop-filter: blur(15px);

            -webkit-backdrop-filter: blur(15px);

            border:
                1px solid rgba(255, 255, 255, 0.8);

            border-radius: 18px;

            padding: 35px;

            box-shadow:
                0 25px 70px rgba(0, 0, 0, 0.28),

                0 0 40px rgba(229, 43, 52, 0.08);
        }


        /* HEADER */

        .forgot-header {
            text-align: center;

            margin-bottom: 28px;
        }


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
                0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .mobile-logo img {
            width: 46px;
            height: 46px;

            object-fit: contain;
        }


        .forgot-header h1 {
            margin: 0;

            font-size: 26px;

            font-weight: 700;

            color: #222;
        }

        .forgot-header p {
            margin: 8px 0 0;

            font-size: 13px;

            line-height: 1.6;

            color: #777;
        }


        /* FLASH */

        .flash-message {
            margin-bottom: 20px;
        }


        /* FORM */

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;

            margin-bottom: 7px;

            font-size: 13px;

            font-weight: 600;

            color: #444;
        }


        /* INPUT */

        .input {
            width: 100%;

            height: 46px;

            padding: 0 14px;

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
                0 0 0 3px rgba(229, 43, 52, 0.08);
        }

        .input::placeholder {
            color: #aaa;
        }


        /* BUTTON */

        .forgot-button {
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

        .forgot-button:hover {
            background: #c9232c;

            box-shadow:
                0 7px 18px rgba(229, 43, 52, 0.25);
        }

        .forgot-button:active {
            transform:
                translateY(1px);
        }


        /* BACK TO LOGIN */

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

            .forgot-card {
                padding:
                    28px 22px;

                border-radius: 14px;
            }

            .forgot-header h1 {
                font-size: 23px;
            }

            .forgot-header p {
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

            .forgot-card {
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


                <!-- BACK -->

                <a
                    href="<?php echo APP_URL; ?>/index.php"
                    class="back-link">
                    ← Back to Store
                </a>


            </div>

        </header>


        <!-- MAIN -->

        <main class="main">

            <div class="forgot-container">

                <div class="forgot-card">


                    <!-- FLASH -->

                    <?php if (function_exists('displayFlash')): ?>

                        <div class="flash-message">

                            <?php displayFlash(); ?>

                        </div>

                    <?php endif; ?>


                    <!-- HEADER -->

                    <div class="forgot-header">


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
                            Forgot Password?
                        </h1>


                        <p>
                            Enter your email address and
                            we'll help you reset your password.
                        </p>


                    </div>


                    <!-- FORM -->

                    <form
                        method="POST"
                        action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">


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


                        <button
                            type="submit"
                            class="forgot-button">
                            Send Reset Link
                        </button>


                    </form>


                    <!-- LOGIN -->

                    <div class="login-link">

                        <p>

                            Remember your password?

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

</body>

</html>