<?php
namespace App\models;

use App\config\Database;
use PDO;

class MocapFile {
    private $db;
    private $baseFilter = "subdirectory = 'unreal/CC'";

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getFileById($id) {
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE id = ? AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getFilesByIds($ids) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE id IN ($placeholders) AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function findByFilename($filename) {
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE filename = ? AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$filename]);
        return $stmt->fetch();
    }

    public function markAsProcessed($id, $processedFilename) {
        $sql = "UPDATE vicon_files SET is_pp = 1, filename_pp = ?, datetime_pp = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$processedFilename, $id]);
    }

    public function updateReviewStatus($id, $status) {
        $validStatuses = ['pending', 'approved', 'rejected', 'needs_review'];
        if (!in_array($status, $validStatuses)) {
            return false;
        }
        $sql = "UPDATE vicon_files SET review_status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    /**
     * Get file count per capture date. Returns ['2026-03-18' => 398, ...].
     */
    public function getDateCounts(): array {
        $sql = "SELECT SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date, COUNT(*) AS cnt
                FROM vicon_files WHERE {$this->baseFilter}
                GROUP BY capture_date ORDER BY capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['capture_date']] = (int)$row['cnt'];
        }
        return $result;
    }

    // ─── Available dates ───

    public function getAvailableDates($status = 'unprocessed', ?array $allowedDates = null) {
        $sql = "SELECT DISTINCT SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE {$this->baseFilter}";

        $params = [];
        if ($status === 'processed') {
            $sql .= " AND is_pp = 1";
        } elseif ($status === 'unprocessed') {
            $sql .= " AND is_pp = 0";
        }

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($placeholders)";
            $params = $allowedDates;
        }

        $sql .= " ORDER BY capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─── Unprocessed files ───

    public function getUnprocessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        // Deduplicate: latest take per base glos (strip _N suffix)
        // Base glos: M20260204_3146_260217_1.fbx → M20260204_3146_260217
        // Exclude base glosses that already have a processed version
        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 0
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter} AND f1.is_pp = 0
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(f1.capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);

        // Bind params with correct types
        foreach ($params as $i => $val) {
            if (is_int($val)) {
                $stmt->bindValue($i + 1, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($i + 1, $val, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getUnprocessedFilesCount($searchTerm = '', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 0
                AND (
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = $allowedDates;
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Unprocessed by specific date ───

    public function getUnprocessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 0
                    AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter} AND f1.is_pp = 0
                AND SUBSTRING_INDEX(f1.capture_id, '/', 1) = ?
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [$date, $date];

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getUnprocessedFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 0
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                AND (
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Processed files ───

    public function getProcessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $sql .= " ORDER BY last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getProcessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getProcessedFilesCount($searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(*) AS total FROM vicon_files WHERE {$this->baseFilter} AND is_pp = 1";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getProcessedFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(*) AS total FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── All files ───

    public function getAllFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter}
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter}";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(f1.capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getAllFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter}
                    AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter}
                AND SUBSTRING_INDEX(f1.capture_id, '/', 1) = ?";

        $params = [$date, $date];

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getAllFilesCount($searchTerm = '', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total FROM vicon_files WHERE {$this->baseFilter}";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getAllFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total FROM vicon_files
                WHERE {$this->baseFilter}
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Activity ───

    /**
     * Get the latest activity for a set of file IDs.
     * Returns: [file_id => ['username' => ..., 'action' => ..., 'downloaded_at' => ...], ...]
     */
    public function getLatestActivity(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($fileIds), '?'));
        $sql = "SELECT dl.file_id, dl.username, dl.download_type AS action, dl.downloaded_at
                FROM download_logs dl
                INNER JOIN (
                    SELECT file_id, MAX(downloaded_at) AS max_at
                    FROM download_logs
                    GROUP BY file_id
                ) latest ON dl.file_id = latest.file_id AND dl.downloaded_at = latest.max_at
                WHERE dl.file_id IN ($ph)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['file_id']] = $row;
        }
        return $result;
    }

    /**
     * Check which file IDs have been downloaded at least once.
     * Returns: [file_id => true, ...]
     */
    public function getDownloadedFileIds(array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($fileIds), '?'));
        $sql = "SELECT DISTINCT file_id FROM download_logs
                WHERE file_id IN ($ph) AND download_type IN ('original', 'processed', 'bulk')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $result[$id] = true;
        }
        return $result;
    }

    // ─── Helpers ───

    private function groupByDate(array $results, string $dateField): array {
        $grouped = [];
        foreach ($results as $row) {
            $date = $row[$dateField];
            $grouped[$date][] = $row;
        }
        return $grouped;
    }
}
