<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\StatsController;

$controller = new StatsController();
$controller->index($currentUser);
