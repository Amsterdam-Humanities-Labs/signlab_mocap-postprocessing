<?php
namespace App\models;

use App\config\Database;
use PDO;

class MocapFile {
    private $db;
    private $baseFilter = "subdirectory = 'unreal/CC'";
    private $broadcastNameCache = [];

    /**
     * Capture dates (YYYY-MM-DD) to hide everywhere in the app.
     * Use for sessions that get re-imported by accident — a sync keeps
     * re-creating their vicon_files rows, so we filter them out at read time
     * instead of fighting the importer. Add a date string to block it.
     */
    private const BLOCKED_DATES = ['2026-05-22'];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();

        if (!empty(self::BLOCKED_DATES)) {
            $quoted = implode(',', array_map(
                fn($d) => $this->db->quote($d),
                self::BLOCKED_DATES
            ));
            $this->baseFilter .= " AND SUBSTRING_INDEX(capture_id, '/', 1) NOT IN ($quoted)";
        }
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

    public function markAsUnprocessed($id) {
        $sql = "UPDATE vicon_files SET is_pp = 0, filename_pp = NULL, datetime_pp = NULL WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Flag the linked sentence as post-processed (sentences.mcp_status_postprocessing = 1)
     * for a capture file, via matched_transcriptions:
     *   broadcast name (filename's first two '_' segments) -> mt.m_file = '<broadcast>.wav'
     *   -> mt.m_transcription -> sentences.ID
     * Only zOg='Zin' rows link to sentences (glos/labels/extern point at form_data),
     * so the update is restricted to those.
     *
     * @return int number of sentences rows updated
     */
    public function markSentencePostProcessed(string $filename): int {
        $broadcast = implode('_', array_slice(explode('_', $filename), 0, 2));
        if ($broadcast === '') return 0;
        $mFile = $broadcast . '.wav';

        $sql = "UPDATE sentences s
                JOIN matched_transcriptions mt
                  ON LOWER(mt.zOg) = 'zin'
                 AND mt.m_transcription REGEXP '^[0-9]+$'
                 AND s.ID = CAST(mt.m_transcription AS UNSIGNED)
                SET s.mcp_status_postprocessing = 1
                WHERE mt.m_file = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$mFile]);
        return $stmt->rowCount();
    }

    /**
     * SET clause that recomputes sentences.mcp_status_postprocessing from the live
     * capture state: 1 if ANY CC take of ANY broadcast linked to the sentence is
     * is_pp=1, otherwise NULL (not-yet-post-processed sentences are left unmarked).
     * Correlated on s.ID so it's correct even when a sentence is linked to multiple
     * broadcasts.
     */
    private const SENTENCE_PP_RECOMPUTE = "
        SET s.mcp_status_postprocessing = (
            CASE WHEN EXISTS (
                SELECT 1
                FROM matched_transcriptions mt2
                JOIN vicon_files vf
                  ON vf.subdirectory = 'unreal/CC' AND vf.is_pp = 1
                 AND CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav') = mt2.m_file
                WHERE LOWER(mt2.zOg) = 'zin'
                  AND mt2.m_transcription REGEXP '^[0-9]+$'
                  AND CAST(mt2.m_transcription AS UNSIGNED) = s.ID
            ) THEN 1 ELSE NULL END
        )";

    /**
     * Recompute the post-processed flag for the sentence linked to a reverted file.
     * Leaves it at 1 if another take of the broadcast is still processed.
     *
     * @return int number of sentences rows updated
     */
    public function markSentenceUnprocessed(string $filename): int {
        $broadcast = implode('_', array_slice(explode('_', $filename), 0, 2));
        if ($broadcast === '') return 0;
        $mFile = $broadcast . '.wav';

        $sql = "UPDATE sentences s
                JOIN matched_transcriptions mt
                  ON LOWER(mt.zOg) = 'zin'
                 AND mt.m_transcription REGEXP '^[0-9]+$'
                 AND s.ID = CAST(mt.m_transcription AS UNSIGNED)
                " . self::SENTENCE_PP_RECOMPUTE . "
                WHERE mt.m_file = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$mFile]);
        return $stmt->rowCount();
    }

    /**
     * One-off sync: recompute sentences.mcp_status_postprocessing for every
     * Zin-linked sentence from the current vicon_files post-processed state.
     *
     * @return int number of sentences rows updated
     */
    public function syncAllSentencePostProcessed(): array {
        // Distinct broadcasts that have at least one post-processed CC take.
        $this->db->exec("DROP TEMPORARY TABLE IF EXISTS _pp_bc");
        $this->db->exec("CREATE TEMPORARY TABLE _pp_bc (mfile VARCHAR(255) PRIMARY KEY)");
        $this->db->exec("INSERT IGNORE INTO _pp_bc (mfile)
            SELECT DISTINCT CONCAT(SUBSTRING_INDEX(filename, '_', 2), '.wav')
            FROM vicon_files WHERE subdirectory = 'unreal/CC' AND is_pp = 1");

        // Sentence IDs linked (zin) to a post-processed broadcast → set 1.
        $this->db->exec("DROP TEMPORARY TABLE IF EXISTS _pp_sent");
        $this->db->exec("CREATE TEMPORARY TABLE _pp_sent (sid INT PRIMARY KEY)");
        $this->db->exec("INSERT IGNORE INTO _pp_sent (sid)
            SELECT DISTINCT CAST(mt.m_transcription AS UNSIGNED)
            FROM matched_transcriptions mt
            JOIN _pp_bc p ON p.mfile = mt.m_file
            WHERE LOWER(mt.zOg) = 'zin' AND mt.m_transcription REGEXP '^[0-9]+$'");

        // All zin-linked sentence IDs.
        $this->db->exec("DROP TEMPORARY TABLE IF EXISTS _all_sent");
        $this->db->exec("CREATE TEMPORARY TABLE _all_sent (sid INT PRIMARY KEY)");
        $this->db->exec("INSERT IGNORE INTO _all_sent (sid)
            SELECT DISTINCT CAST(mt.m_transcription AS UNSIGNED)
            FROM matched_transcriptions mt
            WHERE LOWER(mt.zOg) = 'zin' AND mt.m_transcription REGEXP '^[0-9]+$'");

        $set1 = $this->db->exec("UPDATE sentences s JOIN _pp_sent p ON p.sid = s.ID
            SET s.mcp_status_postprocessing = 1");
        // Not-yet-post-processed sentences are left unmarked (NULL).
        $cleared = $this->db->exec("UPDATE sentences s
            JOIN _all_sent a ON a.sid = s.ID
            LEFT JOIN _pp_sent p ON p.sid = s.ID
            SET s.mcp_status_postprocessing = NULL
            WHERE p.sid IS NULL AND s.mcp_status_postprocessing IS NOT NULL");

        return ['set_to_1' => (int)$set1, 'cleared_to_null' => (int)$cleared];
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

    public function getUnprocessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('f1.filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('f1.filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $sql .= " ORDER BY f1.last_modified DESC, f1.id DESC";

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

    public function getUnprocessedFilesCount($searchTerm = '', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Unprocessed by specific date ───

    public function getUnprocessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '', ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('f1.filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('f1.filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $sql .= " ORDER BY f1.last_modified DESC, f1.id DESC";

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

    public function getUnprocessedFilesCountByDate($date, $searchTerm = '', ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Processed files ───

    public function getProcessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $sql .= " ORDER BY last_modified DESC, id DESC";

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

    public function getProcessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '', ?string $labelFilter = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $sql .= " ORDER BY last_modified DESC, id DESC";

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

    public function getProcessedFilesCount($searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getProcessedFilesCountByDate($date, $searchTerm = '', ?string $labelFilter = null) {
        $sql = "SELECT COUNT(*) AS total FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── All files ───

    public function getAllFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('f1.filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('f1.filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $sql .= " ORDER BY f1.last_modified DESC, f1.id DESC";

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

    public function getAllFilesByDate($date, $limit = null, $page = 1, $searchTerm = '', ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('f1.filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('f1.filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $sql .= " ORDER BY f1.last_modified DESC, f1.id DESC";

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

    public function getAllFilesCount($searchTerm = '', ?array $allowedDates = null, ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getAllFilesCountByDate($date, $searchTerm = '', ?string $labelFilter = null) {
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
            [$frag, $sp] = $this->searchPredicate('filename', $searchTerm);
            $sql .= " AND " . $frag;
            $params = array_merge($params, $sp);
        }
        [$lfrag, $lp] = $this->labelFilterPredicate('filename', $labelFilter);
        if ($lfrag !== '') {
            $sql .= " AND " . $lfrag;
            $params = array_merge($params, $lp);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    /**
     * Get RIGHT camera video filenames for a set of capture_ids.
     * Returns: ['2026-03-17/M20260112_9544_260317_0' => 'M20260112_9544_260317_0_RIGHT_2026-03-17_13-59-29.mkv', ...]
     */
    public function getRightVideos(array $captureIds): array {
        if (empty($captureIds)) return [];
        $ph = implode(',', array_fill(0, count($captureIds), '?'));
        $sql = "SELECT capture_id, filename FROM vicon_files
                WHERE capture_id IN ($ph) AND subdirectory = 'obs' AND filename LIKE '%RIGHT%'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($captureIds);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['capture_id']] = $row['filename'];
        }
        return $result;
    }

    /**
     * Resolve the preview-video web URL for each file: prefer the Blackmagic MP4
     * (same basename as the FBX, inside the capture's date folder), fall back to
     * the RIGHT MKV. Only files with an existing video are returned.
     *
     * @param array $files rows with id, filename, capture_id, capture_date
     * @return array [file_id => web URL]
     */
    public function getPreviewVideos(array $files): array {
        if (empty($files)) return [];

        $bmDisk = '/web/gebarenoverleg_media/blackmagic_filesMini/';
        $bmWeb  = '/gebarenoverleg_media/blackmagic_filesMini/';

        // MKV fallback, keyed by capture_id
        $captureIds = [];
        foreach ($files as $f) { if (!empty($f['capture_id'])) $captureIds[] = $f['capture_id']; }
        $mkvByCapture = $this->getRightVideos(array_unique($captureIds));

        $result = [];
        foreach ($files as $f) {
            $date = preg_replace('/[^0-9-]/', '', (string)($f['capture_date'] ?? ''));
            $mp4Name = preg_replace('/\\.fbx$/i', '.mp4', basename($f['filename']));

            if ($date !== '' && $mp4Name !== '' && is_file($bmDisk . $date . '/' . $mp4Name)) {
                $result[$f['id']] = $bmWeb . $date . '/' . $mp4Name;
            } elseif (!empty($mkvByCapture[$f['capture_id']])) {
                $result[$f['id']] = '/gebarenoverleg_media/razerFiles/' . $mkvByCapture[$f['capture_id']];
            }
        }
        return $result;
    }

    public function updateComment($id, $comment, $username) {
        $sql = "UPDATE vicon_files SET comment = ?, comment_by = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$comment, $username, $id]);
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

    // ─── Labels ───

    /**
     * Resolve display labels for a set of vicon_files IDs.
     *
     * Two paths, both via matched_transcriptions on m_file = broadcast_name + '.wav':
     *   - zOg='labels' → form_data.labels (JSON array). Only emit if it contains
     *     'Basiswoordenlijst Amsterdamse Kleuters'.
     *   - zOg='Zin'    → sentences.label (plain string, e.g. ZNN, HH, Sencity).
     *
     * Colors come from the `labels` table when the label name matches; otherwise a fallback.
     *
     * Returns: [file_id => [['label' => str, 'color' => str], ...], ...]
     */
    public function getLabelsForFiles(array $fileIds): array {
        if (empty($fileIds)) return [];
        $ph = implode(',', array_fill(0, count($fileIds), '?'));

        $sql = "
            SELECT vf.id AS file_id,
                   CASE
                     WHEN mt.zOg = 'labels'
                          AND fd.labels IS NOT NULL
                          AND JSON_VALID(fd.labels)
                          AND JSON_CONTAINS(CAST(fd.labels AS JSON), JSON_QUOTE('Basiswoordenlijst Amsterdamse Kleuters'))
                       THEN 'Basiswoordenlijst Amsterdamse Kleuters'
                     WHEN mt.zOg = 'Zin'
                          AND s.label IS NOT NULL AND s.label <> ''
                       THEN s.label
                     ELSE NULL
                   END AS label
            FROM vicon_files vf
            LEFT JOIN matched_transcriptions mt
                   ON mt.m_file = CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav')
            LEFT JOIN form_data fd
                   ON mt.zOg = 'labels' AND fd.id = CAST(mt.m_transcription AS UNSIGNED)
            LEFT JOIN sentences s
                   ON mt.zOg = 'Zin' AND s.ID = CAST(mt.m_transcription AS UNSIGNED)
            WHERE vf.id IN ($ph)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);

        // Collect labels per file (dedup)
        $perFile = [];
        $labelSet = [];
        foreach ($stmt->fetchAll() as $row) {
            if (empty($row['label'])) continue;
            $fid = $row['file_id'];
            $lbl = $row['label'];
            if (!isset($perFile[$fid])) $perFile[$fid] = [];
            if (in_array($lbl, $perFile[$fid], true)) continue;
            $perFile[$fid][] = $lbl;
            $labelSet[$lbl] = true;
        }
        if (empty($perFile)) return [];

        // Fetch colors for all referenced label names
        $names = array_keys($labelSet);
        $nameHolders = implode(',', array_fill(0, count($names), '?'));
        $colorStmt = $this->db->prepare("SELECT label, color FROM labels WHERE label IN ($nameHolders)");
        $colorStmt->execute($names);
        $colors = [];
        foreach ($colorStmt->fetchAll() as $r) { $colors[$r['label']] = $r['color']; }

        // Build result with color attached
        $result = [];
        foreach ($perFile as $fid => $labels) {
            $result[$fid] = [];
            foreach ($labels as $lbl) {
                $result[$fid][] = [
                    'label' => $lbl,
                    'color' => $colors[$lbl] ?? '#6b7280',
                ];
            }
        }
        return $result;
    }

    /**
     * Resolve the glos to display under each filename, via matched_transcriptions
     * on m_file = broadcast_name + '.wav' (broadcast = first two filename segments).
     *
     *   - zOg in (Glos|labels|extern) → form_data.glos (a single glos word)
     *   - zOg = Zin                   → sentences.zinString (the sentence text)
     *
     * Returns: [file_id => "GLOS …", ...] (only files that resolved a value).
     */
    public function getGlossesForFiles(array $fileIds): array {
        if (empty($fileIds)) return [];
        $ph = implode(',', array_fill(0, count($fileIds), '?'));

        $sql = "
            SELECT vf.id AS file_id,
                   LOWER(mt.zOg) AS zog,
                   fd.glos AS fd_glos,
                   s.zinString AS s_glosses
            FROM vicon_files vf
            LEFT JOIN matched_transcriptions mt
                   ON mt.m_file = CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav')
            LEFT JOIN form_data fd
                   ON LOWER(mt.zOg) IN ('glos','labels','extern')
                  AND fd.id = CAST(mt.m_transcription AS UNSIGNED)
            LEFT JOIN sentences s
                   ON LOWER(mt.zOg) = 'zin'
                  AND s.ID = CAST(mt.m_transcription AS UNSIGNED)
            WHERE vf.id IN ($ph)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $fid = $row['file_id'];
            if (isset($result[$fid])) continue; // first match per file wins

            $zog = $row['zog'];
            $value = null;
            if (in_array($zog, ['glos', 'labels', 'extern'], true)) {
                $value = $row['fd_glos'];
            } elseif ($zog === 'zin') {
                $value = $row['s_glosses'];
            }

            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                $result[$fid] = $value;
            }
        }
        return $result;
    }

    /**
     * True when both motion-capture gates read "Klaar".
     *
     * Note the asymmetry between the two columns: zinnen.html renders the
     * postprocessing dropdown as <option value="1">Klaar</option>, so that
     * column stores '1' (and '2' for "Check nodig"), while the tijd-annotatie
     * column stores the literal word "Klaar".
     */
    public static function isKlaar(?string $pp, ?string $ta): bool {
        return $pp === '1' && $ta === 'Klaar';
    }

    /**
     * Render the postprocessing status value as the label zinnen.html shows for it.
     *
     * Same asymmetry as isKlaar() above: the postprocessing dropdown stores '1'
     * for "Klaar" and '2' for "Check nodig", not the words themselves — keep this
     * mapping here, in the one place both the EAF-download manifest and the file
     * list's MCP badge read it from.
     */
    public static function describePostprocessing(?string $pp): string {
        if ($pp === '1') return 'Klaar';
        if ($pp === '2') return 'Check nodig';
        return ($pp === null || $pp === '') ? 'leeg' : $pp;
    }

    /**
     * MCP postprocessing / tijd-annotatie status per vicon_files row.
     *
     * Follows the same chain as getGlossesForFiles(): the mocap filename's first
     * two underscore-separated parts are the broadcast name, which matches
     * matched_transcriptions.m_file with a .wav suffix, whose m_transcription is
     * the sentences.ID for zOg='zin' rows.
     *
     * Returns file_id => ['pp' => ?string, 'ta' => ?string, 'klaar' => bool].
     * Files with no matching sentence are absent from the map; callers treat a
     * missing entry as not-Klaar.
     */
    public function getMcpStatusForFiles(array $fileIds): array {
        if (empty($fileIds)) return [];
        $ph = implode(',', array_fill(0, count($fileIds), '?'));

        $sql = "
            SELECT vf.id AS file_id,
                   s.mcp_status_postprocessing AS pp,
                   s.mcp_status_tijd_annotatie AS ta
            FROM vicon_files vf
            JOIN matched_transcriptions mt
                   ON mt.m_file = CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav')
                  AND LOWER(mt.zOg) = 'zin'
            JOIN sentences s
                   ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
            WHERE vf.id IN ($ph)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $fid = (int)$row['file_id'];
            if (isset($result[$fid])) continue; // first match per file wins

            $pp = $row['pp'] !== null ? (string)$row['pp'] : null;
            $ta = $row['ta'] !== null ? (string)$row['ta'] : null;
            $result[$fid] = ['pp' => $pp, 'ta' => $ta, 'klaar' => self::isKlaar($pp, $ta)];
        }
        return $result;
    }

    // ─── Helpers ───

    /**
     * Resolve broadcast names (e.g. "M20260113_9959") that are tagged with a
     * label matching the search term via matched_transcriptions.
     *
     * Two paths:
     *   - zOg='Zin'    → sentences.label LIKE '%term%'  (ZNN, HH, Sencity, …)
     *   - zOg='labels' → form_data.labels JSON contains 'Basiswoordenlijst …'
     *                    (only when term is "BAK" case-insensitive)
     *
     * Result is cached per-instance so repeated queries in one request
     * (grouped + count) don't re-query.
     */
    private function broadcastNamesMatchingLabel(string $searchTerm): array {
        $key = strtolower($searchTerm);
        if (isset($this->broadcastNameCache[$key])) return $this->broadcastNameCache[$key];

        $isBAK = (strcasecmp(trim($searchTerm), 'bak') === 0);
        $names = [];

        // Sentence label path
        $sqlZ = "SELECT DISTINCT SUBSTRING_INDEX(mt.m_file, '.', 1) AS bn
                 FROM matched_transcriptions mt
                 INNER JOIN sentences s ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
                 WHERE mt.zOg = 'Zin'
                   AND mt.m_file IS NOT NULL AND mt.m_file <> ''
                   AND s.label LIKE ?";
        $stmt = $this->db->prepare($sqlZ);
        $stmt->execute(['%' . $searchTerm . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) { if ($n !== null && $n !== '') $names[$n] = true; }

        // BAK path
        if ($isBAK) {
            $sqlB = "SELECT DISTINCT SUBSTRING_INDEX(mt.m_file, '.', 1) AS bn
                     FROM matched_transcriptions mt
                     INNER JOIN form_data fd ON fd.id = CAST(mt.m_transcription AS UNSIGNED)
                     WHERE mt.zOg = 'labels'
                       AND mt.m_file IS NOT NULL AND mt.m_file <> ''
                       AND fd.labels IS NOT NULL
                       AND JSON_VALID(fd.labels)
                       AND JSON_CONTAINS(CAST(fd.labels AS JSON), JSON_QUOTE('Basiswoordenlijst Amsterdamse Kleuters'))";
            $stmt = $this->db->prepare($sqlB);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) { if ($n !== null && $n !== '') $names[$n] = true; }
        }

        $list = array_keys($names);
        $this->broadcastNameCache[$key] = $list;
        return $list;
    }

    /** Filename-only search predicate. */
    private function searchPredicate(string $filenameCol, string $searchTerm): array {
        return ["$filenameCol LIKE ?", ['%' . $searchTerm . '%']];
    }

    /**
     * Build a label-restriction predicate: restricts rows to those whose broadcast
     * name (filename prefix) is in the set derived from the label filter.
     *
     * Supported labelFilter values: 'bak', 'znn', 'hh', 'sencity' (case-insensitive),
     * anything else (incl. '', 'all') → no restriction.
     *
     * Returns a predicate that always evaluates false when the label has no matches
     * so no rows are returned (UI will show "No files found"), rather than silently
     * ignoring the filter.
     *
     * @return array{0:string,1:array} [sqlFragment (no leading AND), params] or ['', []]
     */
    private function labelFilterPredicate(string $filenameCol, ?string $labelFilter): array {
        if ($labelFilter === null) return ['', []];
        $lf = strtolower(trim($labelFilter));
        if ($lf === '' || $lf === 'all') return ['', []];

        // Map dropdown value to the search term used by broadcastNamesMatchingLabel
        $termMap = ['bak' => 'BAK', 'znn' => 'ZNN', 'hh' => 'HH', 'sencity' => 'Sencity'];
        if (!isset($termMap[$lf])) return ['', []];
        $term = $termMap[$lf];

        $names = $this->broadcastNamesMatchingLabel($term);
        if (empty($names)) return ['1 = 0', []]; // no matches → filter out everything

        $ph = implode(',', array_fill(0, count($names), '?'));
        return ["SUBSTRING_INDEX($filenameCol, '_', 2) IN ($ph)", $names];
    }

    private function groupByDate(array $results, string $dateField): array {
        $grouped = [];
        foreach ($results as $row) {
            $date = $row[$dateField];
            $grouped[$date][] = $row;
        }
        return $grouped;
    }
}
