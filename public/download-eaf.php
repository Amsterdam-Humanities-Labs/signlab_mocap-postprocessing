<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\DownloadController;

$fileIds = array_values(array_filter(
    array_map('intval', explode(',', $_GET['bulk'] ?? '')),
    static fn($id) => $id > 0
));

if (empty($fileIds)) {
    header('Location: index.php');
    exit;
}

$controller = new DownloadController();
$controller->downloadEaf($fileIds, $currentUser);
