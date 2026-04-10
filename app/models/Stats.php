<?php
namespace App\models;

use App\config\Database;
use PDO;

class Stats {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getSummary(): array {
        $sql = "SELECT
            SUM(CASE WHEN download_type IN ('original', 'processed', 'bulk') THEN 1 ELSE 0 END) AS downloaded,
            SUM(CASE WHEN download_type = 'upload' THEN 1 ELSE 0 END) AS uploaded,
            SUM(CASE WHEN download_type = 'mark_processed' THEN 1 ELSE 0 END) AS processed,
            COUNT(DISTINCT username) AS active_users
            FROM download_logs";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch();
        return [
            'downloaded' => (int)($row['downloaded'] ?? 0),
            'uploaded'   => (int)($row['uploaded'] ?? 0),
            'processed'  => (int)($row['processed'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
        ];
    }

    public function getDailyActivity(int $days = 30): array {
        $sql = "SELECT
            DATE(downloaded_at) AS date,
            SUM(CASE WHEN download_type IN ('original', 'processed', 'bulk') THEN 1 ELSE 0 END) AS downloads,
            SUM(CASE WHEN download_type = 'upload' THEN 1 ELSE 0 END) AS uploads,
            SUM(CASE WHEN download_type = 'mark_processed' THEN 1 ELSE 0 END) AS processed
            FROM download_logs
            WHERE downloaded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(downloaded_at)
            ORDER BY date ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public function getUserActivity(int $days = 30): array {
        $sql = "SELECT
            username,
            SUM(CASE WHEN download_type IN ('original', 'processed', 'bulk') THEN 1 ELSE 0 END) AS downloads,
            SUM(CASE WHEN download_type = 'upload' THEN 1 ELSE 0 END) AS uploads,
            SUM(CASE WHEN download_type = 'mark_processed' THEN 1 ELSE 0 END) AS processed,
            COUNT(*) AS total
            FROM download_logs
            WHERE downloaded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY username
            ORDER BY total DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public function getActivityLog(?string $username = null, int $page = 1, int $limit = 50): array {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT username, filename, download_type, downloaded_at
                FROM download_logs";
        $params = [];

        if ($username) {
            $sql .= " WHERE username = ?";
            $params[] = $username;
        }

        $sql .= " ORDER BY downloaded_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getActivityLogCount(?string $username = null): int {
        $sql = "SELECT COUNT(*) AS total FROM download_logs";
        $params = [];

        if ($username) {
            $sql .= " WHERE username = ?";
            $params[] = $username;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['total'];
    }

    public function getLogUsers(): array {
        $sql = "SELECT DISTINCT username FROM download_logs ORDER BY username ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
