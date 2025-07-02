<?php
namespace App\controllers;

use App\models\MocapFile;
use ZipArchive;

class UploadController {
    private $mocapFileModel;
    private $storageDir;
    
    public function __construct() {
        $this->mocapFileModel = new MocapFile();
        $this->storageDir = '/web/gebarenoverleg_media/fbx/post_processed/';
        
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }
    
    public function showUploadForm() {
        require_once dirname(__DIR__) . '/views/upload-form.php';
    }
    
    public function processUpload() {
        $results = ['success' => [], 'errors' => []];
        $allowOverwrite = isset($_POST['allow_overwrite']) && $_POST['allow_overwrite'] == '1';
        
        if (isset($_FILES['files'])) {
            // Multiple file upload
            $files = $this->reArrayFiles($_FILES['files']);
            
            foreach ($files as $file) {
                $result = $this->processSingleFile($file, $allowOverwrite);
                if ($result['success']) {
                    $results['success'][] = $result['message'];
                } else {
                    $results['errors'][] = $result['message'];
                }
            }
        } elseif (isset($_FILES['zipfile'])) {
            // ZIP file upload
            $result = $this->processZipFile($_FILES['zipfile'], $allowOverwrite);
            if ($result['success']) {
                $results = $result['results'];
            } else {
                $results['errors'][] = $result['message'];
            }
        }
        
        require_once dirname(__DIR__) . '/views/upload-result.php';
    }
    
    private function processSingleFile($file, $allowOverwrite = false) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload failed for ' . $file['name']];
        }
        
        $filename = basename($file['name']);
        
        // Skip only true system files (not files with ._ prefix in their actual names)
        if ($this->isTrueSystemFile($filename)) {
            return ['success' => true, 'message' => "Skipped system file '$filename' (not an error)"];
        }
        
        // Find the original file in database with flexible matching
        $mocapFile = $this->findFileInDatabase($filename);
        
        if (!$mocapFile) {
            return ['success' => false, 'message' => "File '$filename' not found in database"];
        }
        
        if ($mocapFile['is_pp'] == 1 && !$allowOverwrite) {
            return ['success' => false, 'message' => "File '$filename' is already marked as processed. Check 'Allow overwriting' to replace it."];
        }
        
        // Normalize filename for storage (use original filename from database)
        $storageFilename = $mocapFile['filename'];
        $destination = $this->storageDir . $storageFilename;
        
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'message' => "Failed to save file '$filename'"];
        }
        
        // Update database with storage filename (which is the original filename)
        $this->mocapFileModel->markAsProcessed($mocapFile['id'], $storageFilename);
        
        $message = "File '$filename' processed successfully";
        if ($mocapFile['is_pp'] == 1) {
            $message .= " (overwritten)";
        }
        
        return ['success' => true, 'message' => $message];
    }
    
    private function processZipFile($zipFile, $allowOverwrite = false) {
        if ($zipFile['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'ZIP upload failed'];
        }
        
        $zip = new ZipArchive();
        $tempPath = $zipFile['tmp_name'];
        
        if ($zip->open($tempPath) !== true) {
            return ['success' => false, 'message' => 'Failed to open ZIP file'];
        }
        
        $results = ['success' => [], 'errors' => []];
        $tempExtractDir = sys_get_temp_dir() . '/mocap_extract_' . uniqid();
        
        $zip->extractTo($tempExtractDir);
        $zip->close();
        
        // Find post_processed folder recursively
        $postProcessedDir = $this->findPostProcessedFolder($tempExtractDir);
        
        if ($postProcessedDir) {
            $files = $this->getAllFbxFiles($postProcessedDir);
            
            foreach ($files as $filePath) {
                $filename = basename($filePath);
                
                // Skip non-FBX files
                if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'fbx') {
                    continue;
                }
                
                // Skip only true system files
                if ($this->isTrueSystemFile($filename)) {
                    continue; // Silently skip true system files
                }
                
                // Find the original file in database with flexible matching
                $mocapFile = $this->findFileInDatabase($filename);
                
                if (!$mocapFile) {
                    $results['errors'][] = "File '$filename' not found in database";
                    continue;
                }
                
                if ($mocapFile['is_pp'] == 1 && !$allowOverwrite) {
                    $results['errors'][] = "File '$filename' is already marked as processed. Check 'Allow overwriting' to replace it.";
                    continue;
                }
                
                // Normalize filename for storage (use original filename from database)
                $storageFilename = $mocapFile['filename'];
                $destination = $this->storageDir . $storageFilename;
                
                if (!rename($filePath, $destination)) {
                    $results['errors'][] = "Failed to save file '$filename'";
                    continue;
                }
                
                // Update database with storage filename (which is the original filename)
                $this->mocapFileModel->markAsProcessed($mocapFile['id'], $storageFilename);
                
                $message = "File '$filename' processed successfully";
                if ($mocapFile['is_pp'] == 1) {
                    $message .= " (overwritten)";
                }
                $results['success'][] = $message;
            }
        } else {
            // If no post_processed folder found, look for FBX files in the root
            $results['errors'][] = "No 'post_processed' folder found in ZIP file. Looking for FBX files in ZIP root...";
            $files = $this->getAllFbxFiles($tempExtractDir);
            
            if (empty($files)) {
                $results['errors'][] = "No FBX files found in the ZIP file";
            } else {
                $results['errors'][] = "Found " . count($files) . " FBX files. Please ensure they are in a 'post_processed' folder.";
            }
        }
        
        // Clean up
        $this->deleteDirectory($tempExtractDir);
        
        return ['success' => true, 'results' => $results];
    }
    
    private function reArrayFiles($filePost) {
        $fileArray = [];
        $fileCount = count($filePost['name']);
        $fileKeys = array_keys($filePost);
        
        for ($i = 0; $i < $fileCount; $i++) {
            foreach ($fileKeys as $key) {
                $fileArray[$i][$key] = $filePost[$key][$i];
            }
        }
        
        return $fileArray;
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
    
    private function findPostProcessedFolder($dir) {
        // First check if post_processed folder exists at current level
        if (is_dir($dir . '/post_processed')) {
            return $dir . '/post_processed';
        }
        
        // Search recursively for post_processed folder
        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveIterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
        
        foreach ($recursiveIterator as $file) {
            if ($file->isDir()) {
                $basename = basename($file->getPathname());
                if ($basename === 'post_processed') {
                    return $file->getPathname();
                }
            }
        }
        
        return null;
    }
    
    private function getAllFbxFiles($dir) {
        $fbxFiles = [];
        
        if (!is_dir($dir)) {
            return $fbxFiles;
        }
        
        $iterator = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $recursiveIterator = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::LEAVES_ONLY);
        
        foreach ($recursiveIterator as $file) {
            if ($file->isFile()) {
                $filename = basename($file->getPathname());
                $extension = strtolower($file->getExtension());
                
                // Skip true system files and only include .fbx files
                if ($extension === 'fbx' && !$this->isTrueSystemFile($filename)) {
                    $fbxFiles[] = $file->getPathname();
                }
            }
        }
        
        return $fbxFiles;
    }
    
    private function isTrueSystemFile($filename) {
        // Skip common system files (but not files with ._ in their actual names)
        $systemFiles = ['.DS_Store', 'Thumbs.db', '__MACOSX', '._.DS_Store'];
        if (in_array($filename, $systemFiles)) {
            return true;
        }
        
        // Skip files without proper extensions
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($extension) || $extension !== 'fbx') {
            return true;
        }
        
        return false;
    }
    
    private function findFileInDatabase($filename) {
        // First try exact match
        $mocapFile = $this->mocapFileModel->findByFilename($filename);
        
        if ($mocapFile) {
            return $mocapFile;
        }
        
        // If filename starts with ._, try without the prefix
        if (strpos($filename, '._') === 0) {
            $filenameWithoutPrefix = substr($filename, 2);
            $mocapFile = $this->mocapFileModel->findByFilename($filenameWithoutPrefix);
            
            if ($mocapFile) {
                return $mocapFile;
            }
        }
        
        // If filename doesn't start with ._, try adding the prefix
        else {
            $filenameWithPrefix = '._' . $filename;
            $mocapFile = $this->mocapFileModel->findByFilename($filenameWithPrefix);
            
            if ($mocapFile) {
                return $mocapFile;
            }
        }
        
        return null;
    }
}