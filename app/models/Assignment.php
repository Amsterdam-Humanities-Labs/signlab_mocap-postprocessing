<?php
namespace App\models;

use App\config\Database;
use PDO;

class Assignment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all assigned dates for a specific user.
     * Returns array of date strings (e.g., ['2026-02-17', '2026-03-18']).
     */
    public function getDatesForUser(string $username): array {
        $sql = "SELECT capture_date FROM capture_assignments
                WHERE username = ? ORDER BY capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all assignments grouped by username.
     * Returns: ['jose' => [['id'=>1, 'capture_date'=>'2026-02-17', ...], ...], ...]
     */
    public function getAllAssignments(): array {
        $sql = "SELECT ca.*,
                    (SELECT COUNT(*) FROM vicon_files
                     WHERE subdirectory = 'unreal/CC'
                     AND SUBSTRING_INDEX(capture_id, '/', 1) = ca.capture_date) AS file_count
                FROM capture_assignments ca
                ORDER BY ca.username ASC, ca.capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['username']][] = $row;
        }
        return $grouped;
    }

    /**
     * Assign multiple capture dates to a user.
     * Silently skips duplicates (uses INSERT IGNORE).
     */
    public function assign(string $username, array $dates, string $assignedBy): int {
        if (empty($dates)) {
            return 0;
        }

        $sql = "INSERT IGNORE INTO capture_assignments (username, capture_date, assigned_by)
                VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        $inserted = 0;
        foreach ($dates as $date) {
            $stmt->execute([$username, $date, $assignedBy]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * Remove a single assignment by ID.
     */
    public function unassign(int $id): bool {
        $sql = "DELETE FROM capture_assignments WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Get all active (non-blocked) users from the users table.
     */
    public function getActiveUsers(): array {
        $sql = "SELECT userId, user FROM users WHERE blocked = 0 ORDER BY user ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
