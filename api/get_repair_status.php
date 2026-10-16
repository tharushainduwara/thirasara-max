<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/repair_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function rp_out(int $code, array $arr): void
{
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rp_out(405, ['error' => 'Method not allowed']);
if (!rp_csrf_check($_POST['csrf'] ?? '')) rp_out(403, ['error' => 'Session expired. Please refresh the page.']);

// Rate limit: 10 lookups per 10 minutes per session
$now = time();
$_SESSION['repair_hits'] = array_values(array_filter($_SESSION['repair_hits'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['repair_hits']) >= 10) rp_out(429, ['error' => 'Too many attempts. Please try again in a few minutes.']);
$_SESSION['repair_hits'][] = $now;

$ref     = strtoupper(trim($_POST['ref'] ?? ''));
$contact = trim($_POST['contact'] ?? '');

if (!preg_match('/^RP-[A-Z0-9]{6}$/', $ref) || $contact === '') {
    rp_out(400, ['error' => 'Enter a valid reference code and your phone or email.']);
}

try {
    $stmt = Database::getConnection()->prepare(
        'SELECT r.repair_code, r.device_model, r.repair_status, r.is_warranty_covered,
                r.estimated_cost, r.final_cost, r.preferred_date, r.completed_at, r.updated_at,
                u.phone, u.email
         FROM repairs r JOIN users u ON u.id = r.user_id
         WHERE r.repair_code = ?'
    );
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Repair status: ' . $e->getMessage());
    rp_out(500, ['error' => 'Something went wrong. Please try again.']);
}

$ok = false;
if ($row) {
    if (str_contains($contact, '@')) {
        $ok = strcasecmp($contact, $row['email']) === 0;
    } else {
        $p = rp_normalize_phone($contact);
        $ok = $p !== null && !empty($row['phone']) && $p === rp_normalize_phone($row['phone']);
    }
}
if (!$ok) rp_out(404, ['error' => 'No repair found for those details.']);

// Customer-safe fields only (no diagnosis/repair notes, no contact details)
rp_out(200, [
    'repair_code'    => $row['repair_code'],
    'device_model'   => $row['device_model'],
    'status'         => $row['repair_status'],
    'warranty'       => (bool)$row['is_warranty_covered'],
    'estimated_cost' => (float)$row['estimated_cost'],
    'final_cost'     => (float)$row['final_cost'],
    'preferred_date' => $row['preferred_date'],
    'completed_at'   => $row['completed_at'],
    'updated_at'     => $row['updated_at'],
]);
