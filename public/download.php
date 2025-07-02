<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\DownloadController;

$controller = new DownloadController();

if (isset($_GET['id'])) {
    $type = $_GET['type'] ?? 'original';
    $controller->downloadSingle($_GET['id'], $type);
} elseif (isset($_GET['bulk'])) {
    $fileIds = explode(',', $_GET['bulk']);
    $controller->downloadBulk($fileIds);
} else {
    header('Location: index.php');
    exit;
}