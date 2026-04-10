<?php
namespace App\controllers;

use App\models\MocapFile;
use App\config\Database;
use PDO;
use ZipArchive;

class DownloadController {
    private $mocapFileModel;
    private $baseUrl = 'https://signcollect.nl/gebarenoverleg_media/fbx/';
    private $processedPath = '/web/gebarenoverleg_media/fbx/post_processed/';

    public function __construct() {
        $this->mocapFileModel = new MocapFile();
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

            $localPath = $this->processedPath . $file['filename_pp'];
            if (!file_exists($localPath)) {
                header('HTTP/1.0 404 Not Found');
                echo "Processed file not found on server";
                exit;
            }
            $downloadFilename = $file['filename_pp'];
        } else {
            // Download original FBX + RIGHT MKV as ZIP
            $fbxLocalPath = str_replace('.glb', '.fbx', $file['glb_path'] ?? '');
            if (empty($fbxLocalPath) || !file_exists($fbxLocalPath)) {
                $fileUrl = $this->baseUrl . $file['filename'];
                $fbxLocalPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
                if (!$fbxLocalPath) {
                    header('HTTP/1.0 500 Internal Server Error');
                    echo "Failed to download file";
                    exit;
                }
            }

            // Find RIGHT MKV via capture_id
            $rightVideos = $this->mocapFileModel->getRightVideos([$file['capture_id']]);
            $rightVideo = $rightVideos[$file['capture_id']] ?? null;
            $mkvPath = $rightVideo ? '/mnt/bigstorage/razerFiles/' . $rightVideo : null;

            if ($rightVideo && $mkvPath && file_exists($mkvPath)) {
                // Bundle FBX + MKV in a ZIP
                $baseName = preg_replace('/\\.fbx$/i', '', $file['filename']);
                $zipPath = sys_get_temp_dir() . '/' . $baseName . '.zip';
                $zip = new \ZipArchive();
                if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
                    $zip->addFile($fbxLocalPath, $file['filename']);
                    $zip->addFile($mkvPath, $rightVideo);
                    $zip->close();

                    if (!empty($currentUser['username'])) {
                        $this->logDownload($currentUser['username'], (int)$file['id'], $file['filename'], $type);
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
            $downloadFilename = $file['filename'];
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

        foreach ($files as $file) {
            // Try local FBX first
            $fbxLocalPath = str_replace('.glb', '.fbx', $file['glb_path'] ?? '');
            if (!empty($fbxLocalPath) && file_exists($fbxLocalPath)) {
                $zip->addFile($fbxLocalPath, 'original/' . $file['filename']);
            } else {
                $fileUrl = $this->baseUrl . $file['filename'];
                $localPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
                if ($localPath) {
                    $zip->addFile($localPath, 'original/' . $file['filename']);
                }
            }
            $zip->addEmptyDir('post_processed');
        }

        $zip->close();

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
