<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['admin']);
$currentUser = getCurrentUser();

$pageTitle = 'Administrative Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900 mb-1">Administrator Dashboard</h1>
    <p class="text-gray-600">Logged in as Shop Owner / Admin: <span class="font-semibold text-primary-600"><?php echo htmlspecialchars($currentUser['name']); ?></span></p>
</div>

<div class="bg-white rounded-2xl border border-gray-200 p-8 text-center shadow-sm">
    <div class="w-14 h-14 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <i data-lucide="shield-check" class="w-8 h-8"></i>
    </div>
    <h2 class="text-xl font-bold text-gray-900 mb-2">You are logged in as Administrator!</h2>
    <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Full administrative control over product catalogue, inventory reorders, repair assignments, and monthly PDF reports.</p>
    <a href="<?php echo APP_URL; ?>/logout.php" class="inline-flex items-center space-x-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-4 py-2 rounded-xl transition text-sm">
        <i data-lucide="log-out" class="w-4 h-4"></i>
        <span>Logout</span>
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
