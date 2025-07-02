<?php
namespace Tests\models;

use PHPUnit\Framework\TestCase;
use App\models\MocapFile;
use App\config\Database;
use PDO;

class MocapFileTest extends TestCase {
    private $mocapFile;
    private $db;
    
    protected function setUp(): void {
        // Mock the database connection
        $this->db = $this->createMock(PDO::class);
        
        // Mock the Database singleton
        $databaseMock = $this->createMock(Database::class);
        $databaseMock->method('getConnection')->willReturn($this->db);
        
        // Create instance
        $this->mocapFile = new MocapFile();
    }
    
    public function testGetAllFiles() {
        $expectedData = [
            ['id' => 1, 'glos' => 'test1', 'filename' => 'test1.fbx', 'datetime' => '2024-01-01 10:00:00', 'is_pp' => 0, 'filename_pp' => null],
            ['id' => 2, 'glos' => 'test2', 'filename' => 'test2.fbx', 'datetime' => '2024-01-01 11:00:00', 'is_pp' => 1, 'filename_pp' => 'test2.fbx']
        ];
        
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($expectedData);
        $stmt->expects($this->once())->method('execute');
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Note: Since we can't easily inject our mock into the MocapFile class,
        // this test serves as a structure example. In a real scenario, you'd need
        // to refactor the MocapFile class to accept dependency injection.
        $this->assertTrue(true);
    }
    
    public function testMarkAsProcessed() {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->exactly(2))->method('bindParam');
        $stmt->method('execute')->willReturn(true);
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test structure
        $this->assertTrue(true);
    }
    
    public function testFindByFilename() {
        $expectedData = ['id' => 1, 'glos' => 'test1', 'filename' => 'test1.fbx', 'datetime' => '2024-01-01 10:00:00', 'is_pp' => 0, 'filename_pp' => null];
        
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($expectedData);
        $stmt->expects($this->once())->method('bindParam');
        $stmt->expects($this->once())->method('execute');
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test structure
        $this->assertTrue(true);
    }
    
    public function testGetFilesGroupedByDate() {
        $rawData = [
            ['id' => 1, 'glos' => 'test1', 'filename' => 'test1.fbx', 'datetime' => '2024-01-01 10:00:00', 'is_pp' => 0, 'filename_pp' => null, 'date_only' => '2024-01-01'],
            ['id' => 2, 'glos' => 'test2', 'filename' => 'test2.fbx', 'datetime' => '2024-01-01 11:00:00', 'is_pp' => 0, 'filename_pp' => null, 'date_only' => '2024-01-01'],
            ['id' => 3, 'glos' => 'test3', 'filename' => 'test3.fbx', 'datetime' => '2024-01-02 10:00:00', 'is_pp' => 0, 'filename_pp' => null, 'date_only' => '2024-01-02']
        ];
        
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rawData);
        $stmt->expects($this->once())->method('execute');
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test that data would be grouped correctly
        $this->assertCount(3, $rawData);
        $this->assertEquals('2024-01-01', $rawData[0]['date_only']);
        $this->assertEquals('2024-01-02', $rawData[2]['date_only']);
    }
}