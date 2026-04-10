<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/auth.php';

$currentUser = requireAuthApi();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$comment = $_POST['comment'] ?? '';

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing file id']);
    exit;
}

use App\models\MocapFile;

$mocapFile = new MocapFile();
$result = $mocapFile->updateComment($id, $comment, $currentUser['username']);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Comment saved', 'comment' => $comment, 'comment_by' => $currentUser['username']]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save comment']);
}
