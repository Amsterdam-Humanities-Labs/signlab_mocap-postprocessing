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
$action = $_POST['action'] ?? 'process'; // 'process' or 'unprocess'

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing file id']);
    exit;
}

use App\models\MocapFile;
use App\config\Database;

$mocapFile = new MocapFile();
$file = $mocapFile->getFileById($id);

if (!$file) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

if ($action === 'unprocess') {
    $result = $mocapFile->markAsUnprocessed($id);
    $logType = 'mark_unprocessed';
    $message = 'Reverted to unprocessed';
    $newStatus = 'unprocessed';
} else {
    $result = $mocapFile->markAsProcessed($id, $file['filename']);
    $logType = 'mark_processed';
    $message = 'Marked as processed';
    $newStatus = 'processed';
}

if ($result) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("INSERT INTO download_logs (username, file_id, filename, download_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$currentUser['username'], $id, $file['filename'], $logType]);

    echo json_encode(['success' => true, 'message' => $message, 'status' => $newStatus]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update']);
}
