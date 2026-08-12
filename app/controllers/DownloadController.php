<?php
namespace App\controllers;

use App\models\MocapFile;
use App\config\Database;
use App\services\EafLocator;
use PDO;
use ZipArchive;

class DownloadController {
    private $mocapFileModel;
    private $baseUrl = 'https://signcollect.nl/gebarenoverleg_media/fbx/';
    private $processedPath = '/web/gebarenoverleg_media/fbx/post_processed/';
    private $ccPath = '/web/gebarenoverleg_media/fbx/CC/';

    private $blackmagicPath = '/web/gebarenoverleg_media/studioFiles/blackmagic_files/';
    private $razerPath = '/mnt/bigstorage/razerFiles/';

    public function __construct() {
        $this->mocapFileModel = new MocapFile();
    }

    /**
     * Reduce a (DB-sourced, but still untrusted) filename to a plain basename and
     * reject anything that isn't a bare filename. Returns '' if invalid.
     */
    private function safeName(?string $name): string {
        $base = basename(str_replace('\\', '/', (string)$name));
        if ($base === '' || $base === '.' || $base === '..' || strpos($base, "\0") !== false) {
            return '';
        }
        return $base;
    }

    /** True only if $path resolves to a real file inside $baseDir (blocks ../ and symlink escapes). */
    private function isWithin(string $path, string $baseDir): bool {
        $real = realpath($path);
        $base = realpath($baseDir);
        return $real !== false && $base !== false
            && strncmp($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0;
    }

    /**
     * Locate the reference video to bundle with a take's FBX: prefer the Blackmagic
     * MP4 (same basename as the FBX, inside the capture-date folder), fall back to the
     * RIGHT MKV. Returns ['path' => disk path, 'entry' => zip/download name] or null.
     *
     * $rightVideos: optional pre-fetched capture_id => filename map (so bulk downloads
     * don't issue one query per file); when null, looked up for this file's capture.
     */
    private function findVideoForFile(array $file, string $safeFbx, ?array $rightVideos = null): ?array {
        $dateFolder = preg_replace('/[^0-9-]/', '', (string)($file['capture_date'] ?? ''));
        $mp4Name = preg_replace('/\\.fbx$/i', '.mp4', $safeFbx);
        $mp4Path = $dateFolder !== '' ? $this->blackmagicPath . $dateFolder . '/' . $mp4Name : null;
        if ($mp4Path && is_file($mp4Path) && $this->isWithin($mp4Path, $this->blackmagicPath)) {
            return ['path' => $mp4Path, 'entry' => $mp4Name];
        }

        $map = $rightVideos ?? $this->mocapFileModel->getRightVideos([$file['capture_id']]);
        $safeVideo = $this->safeName($map[$file['capture_id']] ?? null);
        $mkvPath = $safeVideo !== '' ? $this->razerPath . $safeVideo : null;
        if ($mkvPath && is_file($mkvPath) && $this->isWithin($mkvPath, $this->razerPath)) {
            return ['path' => $mkvPath, 'entry' => $safeVideo];
        }

        return null;
    }

    private function logDownload(string $username, int $fileId, string $filename, string $type): void {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO download_logs (username, file_id, filename, download_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $fileId, $filename, $type]);
    }

    public function downloadSingle($id, $type = 'original', array $currentUser = []) {
        $file = $this->mocapFileModel->getFileById($id);

        if (!$file) {
            header('HTTP/1.0 404 Not Found');
            echo "File not found";
            exit;
        }

        if ($type === 'processed') {
            if (!$file['filename_pp'] || $file['is_pp'] != 1) {
                header('HTTP/1.0 404 Not Found');
                echo "Processed file not available";
                exit;
            }

            $safe = $this->safeName($file['filename_pp']);
            $localPath = $this->processedPath . $safe;
            if ($safe === '' || !is_file($localPath) || !$this->isWithin($localPath, $this->processedPath)) {
                header('HTTP/1.0 404 Not Found');
                echo "Processed file not found on server";
                exit;
            }
            $downloadFilename = $safe;
        } else {
            // Download original FBX (from unreal/CC) + RIGHT MKV as ZIP.
            // The original .fbx lives in unreal/CC, keyed by filename.
            $safe = $this->safeName($file['filename']);
            if ($safe === '') {
                header('HTTP/1.0 400 Bad Request');
                echo "Invalid filename";
                exit;
            }

            $fbxLocalPath = $this->ccPath . $safe;
            if (!is_file($fbxLocalPath) || !$this->isWithin($fbxLocalPath, $this->ccPath)) {
                $fileUrl = $this->baseUrl . rawurlencode($safe);
                $fbxLocalPath = $this->downloadFileToTemp($fileUrl, $safe);
                if (!$fbxLocalPath) {
                    header('HTTP/1.0 500 Internal Server Error');
                    echo "Failed to download file";
                    exit;
                }
            }

            // Pick the video to bundle: prefer the Blackmagic MP4, fall back to RIGHT MKV.
            $video = $this->findVideoForFile($file, $safe);
            $videoPath = $video['path'] ?? null;
            $videoEntry = $video['entry'] ?? null;

            if ($videoPath) {
                // Bundle FBX + video in a ZIP
                $baseName = preg_replace('/\\.fbx$/i', '', $safe);
                $zipPath = sys_get_temp_dir() . '/' . $baseName . '.zip';
                $zip = new \ZipArchive();
                if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
                    $zip->addFile($fbxLocalPath, $safe);
                    $zip->addFile($videoPath, $videoEntry);
                    $zip->close();

                    if (!empty($currentUser['username'])) {
                        $this->logDownload($currentUser['username'], (int)$file['id'], $safe, $type);
                    }

                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
                    header('Content-Length: ' . filesize($zipPath));
                    readfile($zipPath);
                    unlink($zipPath);
                    exit;
                }
            }

            // Fallback: just the FBX if no video found
            $localPath = $fbxLocalPath;
            $downloadFilename = $safe;
        }

        if (!empty($currentUser['username'])) {
            $this->logDownload($currentUser['username'], (int)$file['id'], $downloadFilename, $type);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Content-Length: ' . filesize($localPath));
        readfile($localPath);

        // Only delete temp files (not local server files)
        if ($type === 'original' && !str_starts_with($localPath, '/web/')) {
            unlink($localPath);
        }
        exit;
    }

    public function downloadBulk($fileIds, array $currentUser = []) {
        $files = $this->mocapFileModel->getFilesByIds($fileIds);

        if (empty($files)) {
            header('HTTP/1.0 404 Not Found');
            echo "No files found";
            exit;
        }

        $tempDir = sys_get_temp_dir() . '/mocap_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/original');
        mkdir($tempDir . '/post_processed');

        $zipPath = $tempDir . '/mocap_files_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to create ZIP file";
            exit;
        }

        $zip->addEmptyDir('post_processed');

        // Pre-fetch RIGHT-MKV fallbacks for all captures in one query (most takes use
        // the Blackmagic MP4, but this avoids a per-file query when they don't).
        $captureIds = array_values(array_unique(array_map(
            static fn($f) => $f['capture_id'],
            $files
        )));
        $rightVideos = $this->mocapFileModel->getRightVideos($captureIds);

        $missing = [];
        $missingVideos = [];
        foreach ($files as $file) {
            // The original .fbx lives in unreal/CC, keyed by filename. Fall back to
            // a remote fetch only if it's genuinely not on disk.
            $safe = $this->safeName($file['filename']);
            if ($safe === '') {
                $missing[] = (string)$file['filename'];
                continue;
            }

            $fbxLocalPath = $this->ccPath . $safe;
            if (!is_file($fbxLocalPath) || !$this->isWithin($fbxLocalPath, $this->ccPath)) {
                $fileUrl = $this->baseUrl . rawurlencode($safe);
                $fbxLocalPath = $this->downloadFileToTemp($fileUrl, $safe);
            }

            if ($fbxLocalPath && is_file($fbxLocalPath)) {
                $zip->addFile($fbxLocalPath, 'original/' . $safe);
            } else {
                // Don't drop silently — record it so the batch is explainable.
                $missing[] = $safe;
            }

            // Bundle the matching reference video (MP4, or RIGHT MKV fallback) next
            // to its FBX — the same pairing the single download produces.
            $video = $this->findVideoForFile($file, $safe, $rightVideos);
            if ($video) {
                $zip->addFile($video['path'], 'original/' . $video['entry']);
            } else {
                $missingVideos[] = $safe;
            }
        }

        if (!empty($missing)) {
            $zip->addFromString(
                'MISSING_FILES.txt',
                "These selected files had no original .fbx on the server and were left out:\n\n"
                . implode("\n", $missing) . "\n"
            );
        }

        if (!empty($missingVideos)) {
            $zip->addFromString(
                'MISSING_VIDEOS.txt',
                "No reference video (Blackmagic MP4 or RIGHT MKV) was found for these takes:\n\n"
                . implode("\n", $missingVideos) . "\n"
            );
        }

        if (!$zip->close()) {
            $statusString = $zip->getStatusString();
            $this->deleteDirectory($tempDir);
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to finalize ZIP file: " . $statusString;
            exit;
        }

        if (!empty($currentUser['username'])) {
            foreach ($files as $file) {
                $this->logDownload($currentUser['username'], (int)$file['id'], $file['filename'], 'bulk');
            }
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mocap_files_' . date('Y-m-d_H-i-s') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        $this->deleteDirectory($tempDir);
        exit;
    }

    /**
     * ZIP the ELAN annotation file and sidecar SRTs for each selected take.
     *
     * Only takes whose two MCP gates both read "Klaar" are included. The check is
     * repeated here even though the file list badges it, because the client
     * controls which IDs it posts. Everything excluded is named in a manifest
     * inside the ZIP, so an unexpectedly small download is explainable.
     */
    public function downloadEaf($fileIds, array $currentUser = []) {
        $files = $this->mocapFileModel->getFilesByIds($fileIds);

        if (empty($files)) {
            header('HTTP/1.0 404 Not Found');
            echo "No files found";
            exit;
        }

        $statuses = $this->mocapFileModel->getMcpStatusForFiles(
            array_map(static fn($f) => (int)$f['id'], $files)
        );
        $locator = new EafLocator();

        $tempDir = sys_get_temp_dir() . '/eaf_' . uniqid();
        mkdir($tempDir);

        $stamp = date('Y-m-d_H-i-s');
        $zipPath = $tempDir . '/eaf_files_' . $stamp . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->deleteDirectory($tempDir);
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to create ZIP file";
            exit;
        }

        $skipped = [];
        $missing = [];
        $included = [];

        foreach ($files as $file) {
            $status = $statuses[(int)$file['id']] ?? ['pp' => null, 'ta' => null, 'klaar' => false];
            $take = preg_replace('/\.fbx$/i', '', $this->safeName($file['filename']));

            if ($take === '') {
                $missing[] = (string)$file['filename'];
                continue;
            }

            if (!$status['klaar']) {
                $skipped[] = sprintf(
                    '%s — postprocessing: %s, tijd annotatie: %s',
                    $take,
                    MocapFile::describePostprocessing($status['pp']),
                    $status['ta'] === null || $status['ta'] === '' ? '(leeg)' : $status['ta']
                );
                continue;
            }

            $paths = $locator->filesForTake($take);
            if (empty($paths)) {
                $missing[] = $take;
                continue;
            }

            $addedEntries = [];
            $addFailed = false;
            foreach ($paths as $path) {
                $entry = $take . '/' . basename($path);
                if ($zip->addFile($path, $entry)) {
                    $addedEntries[] = $entry;
                } else {
                    $addFailed = true;
                    break;
                }
            }

            if ($addFailed) {
                // A path EafLocator found via is_file() became unreadable/missing by the
                // time addFile() ran (narrow TOCTOU window). Don't leave a half-added take
                // in the ZIP, and don't log or count it as delivered.
                foreach ($addedEntries as $entry) {
                    $zip->deleteName($entry);
                }
                $missing[] = $take . ' (file(s) could not be added to the ZIP — removed or unreadable while the download was being built)';
                continue;
            }

            $included[] = $file;
        }

        if (!empty($skipped)) {
            $zip->addFromString(
                'SKIPPED_NOT_KLAAR.txt',
                "These takes were left out because MCP postprocessing and/or tijd annotatie\n"
                . "is not set to Klaar in zinnen.html:\n\n"
                . implode("\n", $skipped) . "\n"
            );
        }

        if (!empty($missing)) {
            $zip->addFromString(
                'MISSING_EAF.txt',
                "These takes have no files in this ZIP. Either no take-level .eaf was found\n"
                . "in /web/zin/eaf/zin/, or (see the note on the line, if present) a file could\n"
                . "not be added while the ZIP was being built.\n"
                . "(The broadcast-level .eaf is not used as a fallback: it covers the whole\n"
                . "broadcast and is not time-aligned to an individual take.)\n\n"
                . implode("\n", $missing) . "\n"
            );
        }

        if (!$zip->close()) {
            $statusString = $zip->getStatusString();
            $this->deleteDirectory($tempDir);
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to finalize ZIP file: " . $statusString;
            exit;
        }

        if (!empty($currentUser['username'])) {
            foreach ($included as $file) {
                $this->logDownload($currentUser['username'], (int)$file['id'], $file['filename'], 'eaf');
            }
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="eaf_files_' . $stamp . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        $this->deleteDirectory($tempDir);
        exit;
    }

    private function downloadFileToTemp($url, $filename) {
        $tempFile = sys_get_temp_dir() . '/' . uniqid() . '_' . $filename;

        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            unlink($tempFile);
            return false;
        }
        return $tempFile;
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $obj) {
            if ($obj === '.' || $obj === '..') continue;
            $path = "$dir/$obj";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
