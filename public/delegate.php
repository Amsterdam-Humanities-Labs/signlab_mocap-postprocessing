<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = ($_SERVER['REQUEST_METHOD'] === 'POST') ? requireAuthApi() : requireAuth();

if (!isAdmin($currentUser['username'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Geen toegang']);
        exit;
    }
    http_response_code(403);
    echo '<h1>403 - Geen toegang</h1><p>U heeft geen rechten om deze pagina te bekijken.</p>';
    exit;
}

use App\controllers\DelegateController;

$controller = new DelegateController();
$controller->index($currentUser);
