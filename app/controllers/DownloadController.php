<?php
namespace App\controllers;

use App\models\MocapFile;
use ZipArchive;

class DownloadController {
    private $mocapFileModel;
    private $baseUrl = 'https://signcollect.nl/gebarenoverleg_media/fbx/';
    private $processedPath = '/web/gebarenoverleg_media/fbx/post_processed/';
    
    public function __construct() {
        $this->mocapFileModel = new MocapFile();
    }
    
    public function downloadSingle($id, $type = 'original') {
        $file = $this->mocapFileModel->getFileById($id);
        
        if (!$file) {
            header('HTTP/1.0 404 Not Found');
            echo "File not found";
            exit;
        }
        
        if ($type === 'processed') {
            // Download processed file from local storage
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
            // Download original file from URL
            $fileUrl = $this->baseUrl . $file['filename'];
            $localPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
            
            if (!$localPath) {
                header('HTTP/1.0 500 Internal Server Error');
                echo "Failed to download file";
                exit;
            }
            
            $downloadFilename = $file['filename'];
        }
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Content-Length: ' . filesize($localPath));
        
        readfile($localPath);
        
        // Only delete temp files for original downloads
        if ($type === 'original') {
            unlink($localPath);
        }
        exit;
    }
    
    public function downloadBulk($fileIds) {
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
            $fileUrl = $this->baseUrl . $file['filename'];
            $localPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
            
            if ($localPath) {
                $zip->addFile($localPath, 'original/' . $file['filename']);
                $zip->addEmptyDir('post_processed');
            }
        }
        
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mocap_files_' . date('Y-m-d_H-i-s') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        
        readfile($zipPath);
        
        // Clean up
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
        if (!is_dir($dir)) {
            return;
        }
        
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    $this->deleteDirectory($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}