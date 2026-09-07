<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client as GoogleClient;
use Google\Service\Oauth2;


// REDIRECT IF ALREADY LOGGED IN

if (isLoggedIn()) {
    redirectBasedOnRole($_SESSION['user_role']);
}

// GOOGLE CLIENT CONFIGURATION

$client = new GoogleClient();

$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);

$client->addScope('openid');
$client->addScope('email');
$client->addScope('profile');

// REDIRECT USER TO GOOGLE

if (!isset($_GET['code'])) {

    $authUrl = $client->createAuthUrl();

    header('Location: ' . $authUrl);
    exit;
}

// HANDLE GOOGLE CALLBACK

try {

    $code = $_GET['code'];

    // Exchange authorization code for access token
    $token = $client->fetchAccessTokenWithAuthCode($code);

    if (isset($token['error'])) {

        throw new Exception(
            $token['error_description']
            ?? 'Google authentication failed.'
        );
    }

    $client->setAccessToken($token);

    // GET GOOGLE USER INFORMATION

    $oauth = new Oauth2($client);

    $googleUser = $oauth->userinfo->get();

    $googleId = trim((string) $googleUser->getId());
    $name     = trim((string) $googleUser->getName());
    $email    = strtolower(
        trim((string) $googleUser->getEmail())
    );

    // VALIDATE GOOGLE DATA

    if (
        empty($googleId) ||
        empty($name) ||
        empty($email)
    ) {
        throw new Exception(
            'Unable to retrieve Google account information.'
        );
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        throw new Exception(
            'Invalid email address returned by Google.'
        );
    }

    // DATABASE CONNECTION

    $db = Database::getConnection();


    // FIND USER BY GOOGLE ID

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            email,
            password,
            phone,
            address,
            role,
            status,
            google_id
        FROM users
        WHERE google_id = ?
        LIMIT 1
    ");

    $stmt->execute([$googleId]);

    $user = $stmt->fetch();


    // IF GOOGLE ID DOESN'T EXIST,

    if (!$user) {

        $stmt = $db->prepare("
            SELECT
                id,
                name,
                email,
                password,
                phone,
                address,
                role,
                status,
                google_id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        $user = $stmt->fetch();
    }


    // EXISTING USER

    if ($user) {

        // Check account status
        if ($user['status'] !== 'active') {

            setFlash(
                'error',
                'Your account is currently ' .
                htmlspecialchars($user['status']) .
                '. Please contact Thirasara Max.'
            );

            header(
                'Location: ' .
                APP_URL .
                '/login.php'
            );

            exit;
        }


        // Link Google account if email already exists

        if (
            empty($user['google_id']) ||
            $user['google_id'] !== $googleId
        ) {

            $update = $db->prepare("
                UPDATE users
                SET google_id = ?
                WHERE id = ?
            ");

            $update->execute([
                $googleId,
                $user['id']
            ]);
        }


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


        // Redirect based on role
        redirectBasedOnRole($user['role']);
    }



    // CREATE NEW GOOGLE USER

    /*
     * Google users do not need a local password.
     * password is NULL for Google-only accounts.
     */

    $stmt = $db->prepare("
        INSERT INTO users
        (
            name,
            email,
            password,
            google_id,
            role,
            status
        )
        VALUES
        (
            ?,
            ?,
            NULL,
            ?,
            'customer',
            'active'
        )
    ");

    $stmt->execute([
        $name,
        $email,
        $googleId
    ]);


    // Get newly created user ID
    $userId = (int) $db->lastInsertId();



    // CREATE SESSION

    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = 'customer';


    setFlash(
        'success',
        'Welcome to Thirasara Max, ' .
        htmlspecialchars($name) .
        '!'
    );



    // REDIRECT CUSTOMER

    redirectBasedOnRole('customer');


} catch (Throwable $e) {

    // Log actual error
    error_log(
        'Google Login Error: ' .
        $e->getMessage()
    );


    // Safe message for user
    setFlash(
        'error',
        'Unable to sign you in with Google. Please try again.'
    );


    header(
        'Location: ' .
        APP_URL .
        '/login.php'
    );

    exit;
}
