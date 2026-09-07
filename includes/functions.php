<?php
/**
 * Global Utility & UI Helper Functions
 */

require_once __DIR__ . '/../config/config.php';

function sanitize(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function displayFlash(): void {
    $types = [
        'success' => 'bg-emerald-50 border-emerald-500 text-emerald-800',
        'error'   => 'bg-rose-50 border-rose-500 text-rose-800',
        'warning' => 'bg-amber-50 border-amber-500 text-amber-800',
        'info'    => 'bg-sky-50 border-sky-500 text-sky-800'
    ];

    foreach ($types as $key => $class) {
        if (!empty($_SESSION['flash_' . $key])) {
            echo '<div class="border-l-4 p-4 mb-5 rounded-r-xl ' . $class . ' shadow-sm flex items-center justify-between">';
            echo '<div class="flex items-center space-x-2">';
            echo '<span class="text-sm font-medium">' . htmlspecialchars($_SESSION['flash_' . $key]) . '</span>';
            echo '</div>';
            echo '<button type="button" onclick="this.parentElement.remove()" class="text-gray-400 hover:text-gray-600 font-bold ml-4 text-lg leading-none">&times;</button>';
            echo '</div>';
            unset($_SESSION['flash_' . $key]);
        }
    }
}
