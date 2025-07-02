<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\UploadController;

$controller = new UploadController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processUpload();
} else {
    $controller->showUploadForm();
}