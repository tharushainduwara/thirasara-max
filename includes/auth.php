<?php
/**
 * Authentication & Role-Based Access Control (RBAC) Module
 * Supports: Customer, Staff/Technician, Administrator/Shop Owner
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? 'User',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role'] ?? 'customer'
    ];
}

function hasRole(string $role): bool {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

function requireAuth(array $allowedRoles = []): void {
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Please log in to access this page.';
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    if (!empty($allowedRoles) && !in_array($_SESSION['user_role'], $allowedRoles)) {
        $_SESSION['flash_error'] = 'Access denied. You do not have permission to view that page.';
        redirectBasedOnRole($_SESSION['user_role']);
    }
}

function redirectBasedOnRole(string $role): void {
    switch ($role) {
        case 'admin':
            header('Location: ' . APP_URL . '/admin/dashboard.php');
            break;
        case 'staff':
            header('Location: ' . APP_URL . '/staff/dashboard.php');
            break;
        case 'customer':
        default:
            header('Location: ' . APP_URL . '/customer/dashboard.php');
            break;
    }
    exit;
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
