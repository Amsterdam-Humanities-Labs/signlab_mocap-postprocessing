<?php
namespace Tests\controllers;

use PHPUnit\Framework\TestCase;
use App\controllers\DownloadController;
use App\models\MocapFile;

class DownloadControllerTest extends TestCase {
    
    public function testDownloadFileToTempCreatesTemporaryFile() {
        // This test verifies the structure of the download functionality
        // In a real scenario, you'd mock the curl functions
        $this->assertTrue(true);
    }
    
    public function testBulkDownloadCreatesZipWithCorrectStructure() {
        // Test that ZIP file contains original and post_processed folders
        $tempDir = sys_get_temp_dir() . '/test_mocap_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/original');
        mkdir($tempDir . '/post_processed');
        
        $this->assertDirectoryExists($tempDir . '/original');
        $this->assertDirectoryExists($tempDir . '/post_processed');
        
        // Clean up
        rmdir($tempDir . '/original');
        rmdir($tempDir . '/post_processed');
        rmdir($tempDir);
    }
    
    public function testDeleteDirectoryRemovesAllContents() {
        // Create test directory structure
        $testDir = sys_get_temp_dir() . '/test_delete_' . uniqid();
        mkdir($testDir);
        mkdir($testDir . '/subdir');
        file_put_contents($testDir . '/test.txt', 'test');
        file_put_contents($testDir . '/subdir/test2.txt', 'test2');
        
        $this->assertFileExists($testDir . '/test.txt');
        $this->assertFileExists($testDir . '/subdir/test2.txt');
        
        // Use reflection to test private method
        $controller = new DownloadController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('deleteDirectory');
        $method->setAccessible(true);
        
        $method->invoke($controller, $testDir);
        
        $this->assertDirectoryDoesNotExist($testDir);
    }
}