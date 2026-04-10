<?php
namespace App\controllers;

use App\models\Stats;

class StatsController {
    private $statsModel;

    public function __construct() {
        $this->statsModel = new Stats();
    }

    public function index(array $currentUser) {
        $days = (int)($_GET['days'] ?? 30);
        if ($days < 1) $days = 30;

        $filterUser = $_GET['user'] ?? null;
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;

        $summary = $this->statsModel->getSummary();
        $dailyActivity = $this->statsModel->getDailyActivity($days);
        $userActivity = $this->statsModel->getUserActivity($days);
        $logUsers = $this->statsModel->getLogUsers();
        $activityLog = $this->statsModel->getActivityLog($filterUser, $page);
        $logTotal = $this->statsModel->getActivityLogCount($filterUser);
        $logTotalPages = $logTotal > 0 ? ceil($logTotal / 50) : 0;

        require_once dirname(__DIR__) . '/views/stats.php';
    }
}
