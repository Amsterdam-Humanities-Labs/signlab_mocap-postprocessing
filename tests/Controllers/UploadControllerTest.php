<?php
namespace Tests\controllers;

use PHPUnit\Framework\TestCase;
use App\controllers\UploadController;

class UploadControllerTest extends TestCase {
    
    public function testReArrayFilesTransformsFilesArray() {
        // Test the file array transformation
        $input = [
            'name' => ['file1.fbx', 'file2.fbx'],
            'type' => ['application/octet-stream', 'application/octet-stream'],
            'tmp_name' => ['/tmp/php1234', '/tmp/php5678'],
            'error' => [0, 0],
            'size' => [1024, 2048]
        ];
        
        $controller = new UploadController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('reArrayFiles');
        $method->setAccessible(true);
        
        $result = $method->invoke($controller, $input);
        
        $this->assertCount(2, $result);
        $this->assertEquals('file1.fbx', $result[0]['name']);
        $this->assertEquals('file2.fbx', $result[1]['name']);
        $this->assertEquals('/tmp/php1234', $result[0]['tmp_name']);
        $this->assertEquals('/tmp/php5678', $result[1]['tmp_name']);
    }
    
    public function testProcessSingleFileValidation() {
        // Test file validation logic
        $file = [
            'name' => 'test.fbx',
            'error' => UPLOAD_ERR_NO_FILE,
            'tmp_name' => '',
            'size' => 0
        ];
        
        $controller = new UploadController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('processSingleFile');
        $method->setAccessible(true);
        
        $result = $method->invoke($controller, $file);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Upload failed', $result['message']);
    }
    
    public function testStorageDirectoryCreation() {
        // Test that storage directory is created
        $storageDir = dirname(__DIR__, 2) . '/storage/fbx/post_processed/';
        $this->assertDirectoryExists(dirname($storageDir));
    }
}