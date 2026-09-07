<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['staff', 'admin']);
$currentUser = getCurrentUser();

$pageTitle = 'Technician Workbench';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900 mb-1">Technician Workbench</h1>
    <p class="text-gray-600">Logged in as: <span class="font-semibold text-primary-600"><?php echo htmlspecialchars($currentUser['name']); ?></span> (Staff Role)</p>
</div>

<div class="bg-white rounded-2xl border border-gray-200 p-8 text-center shadow-sm">
    <div class="w-14 h-14 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <i data-lucide="wrench" class="w-8 h-8"></i>
    </div>
    <h2 class="text-xl font-bold text-gray-900 mb-2">You are logged in as Staff / Technician!</h2>
    <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">You have access to the repair queue, diagnosis management, and warranty verification tools.</p>
    <a href="<?php echo APP_URL; ?>/logout.php" class="inline-flex items-center space-x-2 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-4 py-2 rounded-xl transition text-sm">
        <i data-lucide="log-out" class="w-4 h-4"></i>
        <span>Logout</span>
    </a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
