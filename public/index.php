<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\FileListController;

$controller = new FileListController();
$controller->index();