<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\UploadController;

$controller = new UploadController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processUpload($currentUser);
} else {
    $controller->showUploadForm($currentUser);
}
