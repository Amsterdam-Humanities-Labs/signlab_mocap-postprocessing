<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/auth.php';

$currentUser = requireAuthApi();

use App\models\MocapFile;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';

if (!$id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing id or status']);
    exit;
}

$validStatuses = ['pending', 'approved', 'rejected', 'needs_review'];
if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

$mocapFile = new MocapFile();
$result = $mocapFile->updateReviewStatus($id, $status);

if ($result) {
    echo json_encode(['success' => true, 'message' => 'Status updated']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
}
