<?php
/** @var array $currentUser — set by the including view */
$headerUsername = htmlspecialchars($currentUser['username']);
$headerIsAdmin = isAdmin($currentUser['username']);
?>
<nav class="bg-white shadow mb-6">
    <div class="container mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <h1 class="text-lg font-bold text-gray-800">Motion Capture File Manager</h1>
            <a href="/menu.html" class="text-sm text-blue-600 hover:text-blue-800">Menu</a>
        </div>
        <div class="flex items-center gap-4">
            <a href="stats.php" class="text-sm text-gray-600 hover:text-gray-800 font-medium">Statistieken</a>
            <?php if ($headerIsAdmin): ?>
                <a href="delegate.php" class="text-sm text-purple-600 hover:text-purple-800 font-medium">Toewijzingen</a>
            <?php endif; ?>
            <span class="text-sm text-gray-600">Ingelogd als <strong><?php echo $headerUsername; ?></strong></span>
            <button onclick="logout()" class="text-sm bg-red-500 hover:bg-red-700 text-white py-1 px-3 rounded">
                Uitloggen
            </button>
        </div>
    </div>
</nav>
<script>
function logout() {
    document.cookie = 'sessionObject=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    window.location.href = '/login.html';
}
</script>
