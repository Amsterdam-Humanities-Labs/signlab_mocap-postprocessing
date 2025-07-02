<?php
namespace App\models;

use App\config\Database;
use PDO;

class MocapFile {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function getAllFiles($filterProcessed = null) {
        $sql = "SELECT id, glos, filename, datetime, is_pp, filename_pp 
                FROM mocap_files";
        
        if ($filterProcessed !== null) {
            $sql .= " WHERE is_pp = :is_pp";
        }
        
        $sql .= " ORDER BY datetime DESC";
        
        $stmt = $this->db->prepare($sql);
        
        if ($filterProcessed !== null) {
            $stmt->bindParam(':is_pp', $filterProcessed, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getUnprocessedFiles() {
        return $this->getAllFiles(0);
    }
    
    public function getProcessedFiles() {
        return $this->getAllFiles(1);
    }
    
    public function getFileById($id) {
        $sql = "SELECT * FROM mocap_files WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function getFilesByIds($ids) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT * FROM mocap_files WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }
    
    public function markAsProcessed($id, $processedFilename) {
        $sql = "UPDATE mocap_files 
                SET is_pp = 1, filename_pp = :filename_pp, datetime_pp = NOW() 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':filename_pp', $processedFilename, PDO::PARAM_STR);
        return $stmt->execute();
    }
    
    public function findByFilename($filename) {
        $sql = "SELECT * FROM mocap_files WHERE filename = :filename";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':filename', $filename, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function getFilesGroupedByDate() {
        $sql = "SELECT *, DATE(datetime) as date_only 
                FROM mocap_files 
                ORDER BY datetime DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $date = $row['date_only'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }
        
        return $grouped;
    }
    
    public function getUnprocessedFilesGroupedByDate($limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        // Get only the latest version of each glos, but exclude glosses that have ANY processed version
        // For files with 00:00:00 time, use the highest take number from filename pattern _YYMMDD_N_
        $sql = "SELECT f1.*, DATE(f1.datetime) as date_only 
                FROM mocap_files f1
                INNER JOIN (
                    SELECT glos,
                           MAX(
                               CASE 
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                                   ELSE 
                                       CONCAT(datetime, '_0000000000')
                               END
                           ) as sort_key
                    FROM mocap_files 
                    WHERE is_pp = 0
                    GROUP BY glos
                ) f2 ON f1.glos = f2.glos
                WHERE f1.is_pp = 0
                AND f1.glos NOT IN (
                    SELECT DISTINCT glos 
                    FROM mocap_files 
                    WHERE is_pp = 1
                )
                AND CONCAT(
                    CASE 
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                        ELSE 
                            CONCAT(f1.datetime, '_0000000000')
                    END
                ) = f2.sort_key
                ORDER BY f1.datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $date = $row['date_only'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }
        
        return $grouped;
    }
    
    public function getUnprocessedFilesWithDuplicates($limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        $sql = "SELECT *, DATE(datetime) as date_only 
                FROM mocap_files 
                WHERE is_pp = 0
                ORDER BY datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $date = $row['date_only'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }
        
        return $grouped;
    }
    
    public function getUnprocessedFilesCount() {
        // Count only unique glosses (latest version of each) but exclude glosses that have ANY processed version
        $sql = "SELECT COUNT(DISTINCT glos) as total 
                FROM mocap_files 
                WHERE is_pp = 0 
                AND glos NOT IN (
                    SELECT DISTINCT glos 
                    FROM mocap_files 
                    WHERE is_pp = 1
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getAvailableDates($status = 'unprocessed') {
        if ($status === 'all') {
            $sql = "SELECT DISTINCT DATE(datetime) as date_only 
                    FROM mocap_files 
                    ORDER BY date_only DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $isProcessed = $status === 'processed' ? 1 : 0;
            $sql = "SELECT DISTINCT DATE(datetime) as date_only 
                    FROM mocap_files 
                    WHERE is_pp = :is_pp
                    ORDER BY date_only DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':is_pp', $isProcessed, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    
    public function getUnprocessedFilesByDate($date, $limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        // Get only the latest version of each glos for the specific date, but exclude glosses that have ANY processed version
        // For files with 00:00:00 time, use the highest take number from filename
        $sql = "SELECT f1.*, DATE(f1.datetime) as date_only 
                FROM mocap_files f1
                INNER JOIN (
                    SELECT glos,
                           MAX(
                               CASE 
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                                   ELSE 
                                       CONCAT(datetime, '_0000000000')
                               END
                           ) as sort_key
                    FROM mocap_files 
                    WHERE is_pp = 0 AND DATE(datetime) = :date
                    GROUP BY glos
                ) f2 ON f1.glos = f2.glos
                WHERE f1.is_pp = 0 AND DATE(f1.datetime) = :date2
                AND f1.glos NOT IN (
                    SELECT DISTINCT glos 
                    FROM mocap_files 
                    WHERE is_pp = 1
                )
                AND CONCAT(
                    CASE 
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                        ELSE 
                            CONCAT(f1.datetime, '_0000000000')
                    END
                ) = f2.sort_key
                ORDER BY f1.datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->bindParam(':date2', $date, PDO::PARAM_STR);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $dateKey = $row['date_only'];
            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [];
            }
            $grouped[$dateKey][] = $row;
        }
        
        return $grouped;
    }
    
    public function getUnprocessedFilesCountByDate($date) {
        // Count only unique glosses (latest version of each) for specific date but exclude glosses that have ANY processed version
        $sql = "SELECT COUNT(DISTINCT glos) as total 
                FROM mocap_files 
                WHERE is_pp = 0 AND DATE(datetime) = :date
                AND glos NOT IN (
                    SELECT DISTINCT glos 
                    FROM mocap_files 
                    WHERE is_pp = 1
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getProcessedFilesGroupedByDate($limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        $sql = "SELECT *, DATE(datetime) as date_only 
                FROM mocap_files 
                WHERE is_pp = 1
                ORDER BY datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $date = $row['date_only'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }
        
        return $grouped;
    }
    
    public function getProcessedFilesByDate($date, $limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        $sql = "SELECT *, DATE(datetime) as date_only 
                FROM mocap_files 
                WHERE is_pp = 1 AND DATE(datetime) = :date
                ORDER BY datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $dateKey = $row['date_only'];
            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [];
            }
            $grouped[$dateKey][] = $row;
        }
        
        return $grouped;
    }
    
    public function getProcessedFilesCount() {
        $sql = "SELECT COUNT(*) as total FROM mocap_files WHERE is_pp = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getProcessedFilesCountByDate($date) {
        $sql = "SELECT COUNT(*) as total FROM mocap_files WHERE is_pp = 1 AND DATE(datetime) = :date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getAllFilesGroupedByDate($limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        // Get the latest version of each glos (processed or unprocessed)
        $sql = "SELECT f1.*, DATE(f1.datetime) as date_only 
                FROM mocap_files f1
                INNER JOIN (
                    SELECT glos,
                           MAX(
                               CASE 
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                                   ELSE 
                                       CONCAT(datetime, '_0000000000')
                               END
                           ) as sort_key
                    FROM mocap_files 
                    GROUP BY glos
                ) f2 ON f1.glos = f2.glos
                WHERE CONCAT(
                    CASE 
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                        ELSE 
                            CONCAT(f1.datetime, '_0000000000')
                    END
                ) = f2.sort_key
                ORDER BY f1.datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $date = $row['date_only'];
            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }
            $grouped[$date][] = $row;
        }
        
        return $grouped;
    }
    
    public function getAllFilesByDate($date, $limit = null, $page = 1) {
        $offset = $limit ? ($page - 1) * $limit : 0;
        
        // Get the latest version of each glos for the specific date (processed or unprocessed)
        $sql = "SELECT f1.*, DATE(f1.datetime) as date_only 
                FROM mocap_files f1
                INNER JOIN (
                    SELECT glos,
                           MAX(
                               CASE 
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                                   WHEN HOUR(datetime) = 0 AND MINUTE(datetime) = 0 AND SECOND(datetime) = 0 AND filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                       CONCAT(DATE(datetime), '_', 
                                              LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                                   ELSE 
                                       CONCAT(datetime, '_0000000000')
                               END
                           ) as sort_key
                    FROM mocap_files 
                    WHERE DATE(datetime) = :date
                    GROUP BY glos
                ) f2 ON f1.glos = f2.glos
                WHERE DATE(f1.datetime) = :date2
                AND CONCAT(
                    CASE 
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+_' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '_', 3), '_', -1) AS UNSIGNED), 10, '0'))
                        WHEN HOUR(f1.datetime) = 0 AND MINUTE(f1.datetime) = 0 AND SECOND(f1.datetime) = 0 AND f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            CONCAT(DATE(f1.datetime), '_', 
                                   LPAD(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f1.filename, '.', 1), '_', -1) AS UNSIGNED), 10, '0'))
                        ELSE 
                            CONCAT(f1.datetime, '_0000000000')
                    END
                ) = f2.sort_key
                ORDER BY f1.datetime DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->bindParam(':date2', $date, PDO::PARAM_STR);
        
        if ($limit) {
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        $grouped = [];
        
        foreach ($results as $row) {
            $dateKey = $row['date_only'];
            if (!isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [];
            }
            $grouped[$dateKey][] = $row;
        }
        
        return $grouped;
    }
    
    public function getAllFilesCount() {
        // Count only unique glosses (latest version of each)
        $sql = "SELECT COUNT(DISTINCT glos) as total FROM mocap_files";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getAllFilesCountByDate($date) {
        // Count only unique glosses (latest version of each) for specific date
        $sql = "SELECT COUNT(DISTINCT glos) as total FROM mocap_files WHERE DATE(datetime) = :date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    public function getDuplicateGlosInfo() {
        $sql = "SELECT glos, COUNT(*) as count, 
                       MIN(datetime) as first_created, 
                       MAX(datetime) as latest_created
                FROM mocap_files 
                WHERE is_pp = 0
                GROUP BY glos 
                HAVING COUNT(*) > 1
                ORDER BY count DESC, latest_created DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getTotalDuplicatesCount() {
        $sql = "SELECT SUM(duplicate_count - 1) as total_duplicates
                FROM (
                    SELECT glos, COUNT(*) as duplicate_count
                    FROM mocap_files 
                    WHERE is_pp = 0
                    GROUP BY glos 
                    HAVING COUNT(*) > 1
                ) as duplicates";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total_duplicates'] ?? 0;
    }
}