<?php
namespace App\controllers;

use App\models\MocapFile;
use App\models\Assignment;

class FileListController {
    private $mocapFileModel;
    private $assignmentModel;

    public function __construct() {
        $this->mocapFileModel = new MocapFile();
        $this->assignmentModel = new Assignment();
    }

    public function index(array $currentUser) {
        $username = $currentUser['username'];

        // Determine allowed dates: null for admins (no filter), array for regular users
        if (isAdmin($username)) {
            $allowedDates = null;
        } else {
            $allowedDates = $this->assignmentModel->getDatesForUser($username);
        }

        // Get filter parameters
        $selectedDate = $_GET['date'] ?? 'all';
        $selectedStatus = $_GET['status'] ?? 'unprocessed';
        $limit = (int)($_GET['limit'] ?? 50);
        $page = (int)($_GET['page'] ?? 1);
        $searchTerm = $_GET['search'] ?? '';
        $reviewStatus = $_GET['review'] ?? 'all';
        $labelFilter = $_GET['label'] ?? 'all';

        // Get available dates for the dropdown
        $availableDates = $this->mocapFileModel->getAvailableDates($selectedStatus, $allowedDates);

        // Get filtered files
        if ($selectedDate === 'all') {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesGroupedByDate($limit, $page, $searchTerm, $reviewStatus, $allowedDates, $labelFilter);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCount($searchTerm, $reviewStatus, $allowedDates, $labelFilter);
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesGroupedByDate($limit, $page, $searchTerm, $allowedDates, $labelFilter);
                $totalFiles = $this->mocapFileModel->getAllFilesCount($searchTerm, $allowedDates, $labelFilter);
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesGroupedByDate($limit, $page, $searchTerm, $allowedDates, $labelFilter);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCount($searchTerm, $allowedDates, $labelFilter);
            }
        } else {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesByDate($selectedDate, $limit, $page, $searchTerm, $labelFilter);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCountByDate($selectedDate, $searchTerm, $labelFilter);
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesByDate($selectedDate, $limit, $page, $searchTerm, $labelFilter);
                $totalFiles = $this->mocapFileModel->getAllFilesCountByDate($selectedDate, $searchTerm, $labelFilter);
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesByDate($selectedDate, $limit, $page, $searchTerm, $labelFilter);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCountByDate($selectedDate, $searchTerm, $labelFilter);
            }
        }

        $processedCount = $this->mocapFileModel->getProcessedFilesCount('', 'all', $allowedDates);
        $unprocessedCount = $this->mocapFileModel->getUnprocessedFilesCount('', $allowedDates);

        // Calculate pagination
        $totalPages = $totalFiles > 0 ? ceil($totalFiles / $limit) : 0;

        // Collect all visible file rows / IDs for lookups
        $allFiles = [];
        $allFileIds = [];
        foreach ($filesGroupedByDate as $files) {
            foreach ($files as $f) {
                $allFiles[] = $f;
                $allFileIds[] = $f['id'];
            }
        }
        $fileActivities = $this->mocapFileModel->getLatestActivity($allFileIds);
        $downloadedFiles = $this->mocapFileModel->getDownloadedFileIds($allFileIds);
        $fileLabels = $this->mocapFileModel->getLabelsForFiles($allFileIds);
        $fileGlosses = $this->mocapFileModel->getGlossesForFiles($allFileIds);
        $fileMcpStatus = $this->mocapFileModel->getMcpStatusForFiles($allFileIds);

        // Preview video per file: Blackmagic MP4 preferred, RIGHT MKV fallback
        $previewVideos = $this->mocapFileModel->getPreviewVideos($allFiles);

        // Pass to view
        $noAssignments = ($allowedDates !== null && empty($allowedDates));

        require_once dirname(__DIR__) . '/views/file-list.php';
    }
}
