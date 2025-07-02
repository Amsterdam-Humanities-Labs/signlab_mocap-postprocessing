<?php
namespace App\controllers;

use App\models\MocapFile;

class FileListController {
    private $mocapFileModel;
    
    public function __construct() {
        $this->mocapFileModel = new MocapFile();
    }
    
    public function index() {
        // Get filter parameters
        $selectedDate = $_GET['date'] ?? 'all';
        $selectedStatus = $_GET['status'] ?? 'unprocessed';
        $limit = (int)($_GET['limit'] ?? 50);
        $page = (int)($_GET['page'] ?? 1);
        
        // Get available dates for the dropdown (based on status filter)
        $availableDates = $this->mocapFileModel->getAvailableDates($selectedStatus);
        
        // Get filtered files (always deduplicated - only latest version of each glos)
        if ($selectedDate === 'all') {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesGroupedByDate($limit, $page);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCount();
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesGroupedByDate($limit, $page);
                $totalFiles = $this->mocapFileModel->getAllFilesCount();
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesGroupedByDate($limit, $page);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCount();
            }
        } else {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesByDate($selectedDate, $limit, $page);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCountByDate($selectedDate);
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesByDate($selectedDate, $limit, $page);
                $totalFiles = $this->mocapFileModel->getAllFilesCountByDate($selectedDate);
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesByDate($selectedDate, $limit, $page);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCountByDate($selectedDate);
            }
        }
        
        $processedCount = $this->mocapFileModel->getProcessedFilesCount();
        $unprocessedCount = $this->mocapFileModel->getUnprocessedFilesCount();
        
        // Calculate pagination
        $totalPages = ceil($totalFiles / $limit);
        
        require_once dirname(__DIR__) . '/views/file-list.php';
    }
}