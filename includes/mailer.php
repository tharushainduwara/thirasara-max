<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';


function sendPasswordResetEmail(
    string $email,
    string $name,
    string $resetLink
): bool {

    $mail = new PHPMailer(true);

    try {

        // SMTP configuration
        $mail->isSMTP();

        $mail->Host = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = $_ENV['MAIL_USERNAME'] ?? '';
        $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port =
            (int) ($_ENV['MAIL_PORT'] ?? 587);


        // Sender
        $mail->setFrom(
            $_ENV['MAIL_FROM_EMAIL'] ?? '',
            $_ENV['MAIL_FROM_NAME'] ?? 'Thirasara Max'
        );


        // Recipient
        $mail->addAddress(
            $email,
            $name
        );


        // Email format
        $mail->isHTML(true);

        $mail->Subject =
            'Reset Your Thirasara Max Password';


        // HTML email
        $mail->Body = '
        <!DOCTYPE html>

        <html>

        <body style="
            margin:0;
            padding:30px;
            background:#f5f5f5;
            font-family:Arial,sans-serif;
        ">

            <div style="
                max-width:600px;
                margin:auto;
                background:#ffffff;
                padding:35px;
                border-radius:12px;
            ">

                <h2 style="
                    color:#e52b34;
                    margin-top:0;
                ">
                    Thirasara Max
                </h2>

                <p>
                    Hello ' .
                    htmlspecialchars($name) .
                    ',
                </p>

                <p>
                    We received a request to reset
                    the password for your Thirasara Max
                    account.
                </p>

                <p>
                    Click the button below to create
                    a new password:
                </p>

                <div style="
                    text-align:center;
                    margin:30px 0;
                ">

                    <a href="' .
                        htmlspecialchars($resetLink) .
                        '"
                        style="
                            display:inline-block;
                            padding:13px 25px;
                            background:#e52b34;
                            color:#ffffff;
                            text-decoration:none;
                            border-radius:7px;
                            font-weight:bold;
                        "
                    >
                        Reset Password
                    </a>

                </div>

                <p>
                    This link will expire in
                    <strong>30 minutes</strong>.
                </p>

                <p style="
                    color:#777;
                    font-size:13px;
                ">
                    If you did not request a password
                    reset, you can safely ignore this
                    email.
                </p>

                <hr style="
                    border:0;
                    border-top:1px solid #eeeeee;
                    margin:25px 0;
                ">

                <p style="
                    color:#999;
                    font-size:12px;
                ">
                    © ' .
                    date('Y') .
                    ' Thirasara Max.
                    All rights reserved.
                </p>

            </div>

        </body>

        </html>
        ';


        // Plain-text fallback
        $mail->AltBody =
            "Hello $name,\n\n" .
            "We received a request to reset your " .
            "Thirasara Max password.\n\n" .
            "Reset your password here:\n" .
            "$resetLink\n\n" .
            "This link expires in 30 minutes.\n\n" .
            "If you did not request this, " .
            "you can ignore this email.";


        // Send
        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log(
            'Password Reset Email Error: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}