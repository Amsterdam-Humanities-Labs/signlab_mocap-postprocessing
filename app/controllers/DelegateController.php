<?php
namespace App\controllers;

use App\models\Assignment;
use App\models\MocapFile;

class DelegateController {
    private $assignmentModel;
    private $mocapFileModel;

    public function __construct() {
        $this->assignmentModel = new Assignment();
        $this->mocapFileModel = new MocapFile();
    }

    public function index(array $currentUser) {
        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($currentUser);
            return;
        }

        $users = $this->assignmentModel->getActiveUsers();
        $availableDates = $this->mocapFileModel->getAvailableDates('unprocessed');
        $dateCounts = $this->mocapFileModel->getDateCounts();
        $assignments = $this->assignmentModel->getAllAssignments();

        require_once dirname(__DIR__) . '/views/delegate.php';
    }

    private function handlePost(array $currentUser) {
        header('Content-Type: application/json');

        $action = $_POST['action'] ?? '';

        if ($action === 'assign') {
            $username = $_POST['username'] ?? '';
            $dates = $_POST['dates'] ?? [];

            if (empty($username) || empty($dates)) {
                echo json_encode(['success' => false, 'message' => 'Gebruiker en datums zijn verplicht']);
                return;
            }

            if (!is_array($dates)) {
                $dates = [$dates];
            }

            $inserted = $this->assignmentModel->assign($username, $dates, $currentUser['username']);
            echo json_encode(['success' => true, 'message' => "$inserted datum(s) toegewezen", 'inserted' => $inserted]);

        } elseif ($action === 'unassign') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Ongeldig ID']);
                return;
            }
            $this->assignmentModel->unassign($id);
            echo json_encode(['success' => true, 'message' => 'Toewijzing verwijderd']);

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ongeldige actie']);
        }
    }
}
