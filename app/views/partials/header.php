<?php
/** @var array $currentUser — set by the including view */
$headerUsername = htmlspecialchars($currentUser['username']);
$headerIsAdmin = isAdmin($currentUser['username']);
?>
<nav class="bg-white shadow mb-6">
    <div class="container mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <h1 class="text-lg font-bold text-gray-800">Motion Capture File Manager</h1>
            <a href="index.php" class="text-sm text-blue-600 hover:text-blue-800">Home</a>
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
(function trackActivity() {
    var cookies = decodeURIComponent(document.cookie);
    var sessionCookie = cookies.split('; ').find(function(row) { return row.startsWith('sessionObject='); });
    if (!sessionCookie) return;
    try {
        var sessionData = JSON.parse(sessionCookie.split('=')[1]);
        if (!sessionData.userId) return;
        var page = 'animMIDI/' + (location.pathname.split('/').pop() || 'index.php');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/users_api.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('action=activity&userId=' + encodeURIComponent(sessionData.userId) + '&page=' + encodeURIComponent(page));
    } catch (e) {}
})();
</script>
