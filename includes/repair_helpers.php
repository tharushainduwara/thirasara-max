<?php
// Helpers for the repair system. Prefixed rp_ so they can't clash with includes/functions.php.

function rp_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------- CSRF ---------- */
function rp_csrf_token(): string
{
    if (empty($_SESSION['rp_csrf'])) $_SESSION['rp_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['rp_csrf'];
}
function rp_csrf_check($t): bool
{
    return !empty($_SESSION['rp_csrf']) && is_string($t) && hash_equals($_SESSION['rp_csrf'], $t);
}
function rp_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . rp_e(rp_csrf_token()) . '">';
}

/* ---------- Phone / codes ---------- */
// users.phone is VARCHAR(10): store as 0771234567.
function rp_normalize_phone(string $raw): ?string
{
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 11 && str_starts_with($d, '94')) $d = '0' . substr($d, 2);
    elseif (strlen($d) === 9) $d = '0' . $d;
    return preg_match('/^0\d{9}$/', $d) ? $d : null;
}
function rp_make_code(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $q = $pdo->prepare('SELECT 1 FROM repairs WHERE repair_code = ?');
    do {
        $code = 'RP-';
        for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $q->execute([$code]);
    } while ($q->fetch());
    return $code;
}

/* ---------- Access control ---------- */
// Adjust the login URL if yours differs.
function rp_require_login($user, array $roles = []): void
{
    if (!$user) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    if ($roles && !in_array($user['role'] ?? '', $roles, true)) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

/* ---------- Formatting ---------- */
function rp_money($n): string
{
    return 'Rs. ' . number_format((float)$n, 2);
}
function rp_date(?string $s): string
{
    return $s ? date('d M Y', strtotime($s)) : '—';
}
function rp_datetime(?string $s): string
{
    return $s ? date('d M Y, g:i A', strtotime($s)) : '—';
}

function rp_status_meta(): array
{
    return [
        'pending'     => ['Received',    'bg-amber-50 text-amber-700 border-amber-100'],
        'assigned'    => ['Assigned',    'bg-blue-50 text-blue-700 border-blue-100'],
        'in_progress' => ['In Progress', 'bg-purple-50 text-purple-700 border-purple-100'],
        'completed'   => ['Completed',   'bg-green-50 text-green-700 border-green-100'],
        'cancelled'   => ['Cancelled',   'bg-red-50 text-red-700 border-red-100'],
    ];
}
function rp_status_badge(string $s): string
{
    $m = rp_status_meta()[$s] ?? [$s, 'bg-gray-50 text-gray-600 border-gray-100'];
    return '<span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[11px] font-bold ' . $m[1] . '">' . rp_e($m[0]) . '</span>';
}
function rp_progress_bar(string $s): string
{
    if ($s === 'cancelled') return '<div class="h-1.5 rounded-full bg-red-100"></div>';
    $order = ['pending', 'assigned', 'in_progress', 'completed'];
    $idx = array_search($s, $order, true);
    $html = '<div class="flex gap-1">';
    foreach ($order as $i => $_) {
        $html .= '<div class="flex-1 h-1.5 rounded-full ' . ($i <= $idx ? 'bg-brand-red' : 'bg-gray-200') . '"></div>';
    }
    return $html . '</div>';
}
function rp_is_open(string $s): bool
{
    return in_array($s, ['pending', 'assigned', 'in_progress'], true);
}

/* ---------- Notifications (your notifications table) ---------- */
function rp_notify(PDO $pdo, int $userId, string $title, string $message): void
{
    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'repair_status')")
        ->execute([$userId, $title, $message]);
}

/* ---------- Flash messages ---------- */
function rp_flash_set(string $type, string $msg): void
{
    $_SESSION['rp_flash'] = [$type, $msg];
}
function rp_flash_render(): string
{
    if (empty($_SESSION['rp_flash'])) return '';
    [$type, $msg] = $_SESSION['rp_flash'];
    unset($_SESSION['rp_flash']);
    $ok = $type === 'success';
    $cls  = $ok ? 'bg-green-50 border-green-100 text-green-800' : 'bg-red-50 border-red-100 text-red-700';
    $icon = $ok ? 'check-circle-2' : 'alert-circle';
    return '<div class="mb-6 flex gap-3 p-4 rounded-xl border text-sm ' . $cls . '"><i data-lucide="' . $icon . '" class="w-5 h-5 flex-shrink-0"></i><span>' . rp_e($msg) . '</span></div>';
}

/* ---------- Staff sub-navigation ---------- */
function rp_staff_nav(string $active): string
{
    $items = [
        'dashboard' => ['Dashboard', 'layout-dashboard', '/staff/dashboard.php'],
        'repairs'   => ['All Repairs', 'wrench', '/staff/repairs.php'],
    ];
    $html = '<div class="flex gap-2 mb-8">';
    foreach ($items as $k => [$label, $icon, $href]) {
        $cls = $k === $active ? 'bg-brand-dark text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50';
        $html .= '<a href="' . APP_URL . $href . '" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition ' . $cls . '"><i data-lucide="' . $icon . '" class="w-4 h-4"></i>' . $label . '</a>';
    }
    return $html . '</div>';
}

/* ---------- Pagination ---------- */
function rp_pagination(int $page, int $pages, array $query): string
{
    if ($pages <= 1) return '';
    $link = function (int $p, string $label, bool $enabled) use ($query) {
        if (!$enabled) return '<span class="px-4 py-2 rounded-xl text-sm text-gray-300 bg-white border border-gray-100">' . $label . '</span>';
        $q = http_build_query(array_merge($query, ['page' => $p]));
        return '<a href="?' . rp_e($q) . '" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-700 bg-white border border-gray-200 hover:bg-gray-50">' . $label . '</a>';
    };
    return '<div class="mt-6 flex items-center justify-between">' .
        $link($page - 1, '← Previous', $page > 1) .
        '<span class="text-xs text-gray-500">Page ' . $page . ' of ' . $pages . '</span>' .
        $link($page + 1, 'Next →', $page < $pages) . '</div>';
}
