<?php
namespace Tests\config;

use PHPUnit\Framework\TestCase;
use App\config\Database;

class DatabaseTest extends TestCase {
    
    public function testDatabaseSingleton() {
        // Test that Database returns the same instance
        $instance1 = Database::getInstance();
        $instance2 = Database::getInstance();
        
        $this->assertSame($instance1, $instance2);
    }
    
    public function testDatabaseConnectionType() {
        // Test that connection is PDO instance
        $db = Database::getInstance();
        $connection = $db->getConnection();
        
        $this->assertInstanceOf(\PDO::class, $connection);
    }
    
    public function testCannotCloneDatabase() {
        $this->expectException(\Error::class);
        
        $db = Database::getInstance();
        $clone = clone $db;
    }
    
    public function testCannotUnserializeDatabase() {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');
        
        $db = Database::getInstance();
        $serialized = serialize($db);
        unserialize($serialized);
    }
}