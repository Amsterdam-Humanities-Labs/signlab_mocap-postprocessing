<?php
namespace Tests\models;

use PHPUnit\Framework\TestCase;
use App\models\MocapFile;

class McpStatusTest extends TestCase {
    public function testBothGatesKlaarPasses(): void {
        $this->assertTrue(MocapFile::isKlaar('1', 'Klaar'));
    }

    public function testPostprocessingAloneIsNotEnough(): void {
        $this->assertFalse(MocapFile::isKlaar('1', null));
        $this->assertFalse(MocapFile::isKlaar('1', ''));
        $this->assertFalse(MocapFile::isKlaar('1', 'Niet Klaar'));
    }

    public function testTijdAnnotatieAloneIsNotEnough(): void {
        $this->assertFalse(MocapFile::isKlaar(null, 'Klaar'));
        $this->assertFalse(MocapFile::isKlaar('', 'Klaar'));
    }

    public function testCheckNodigIsNotKlaar(): void {
        // '2' is "Check nodig" in the zinnen.html postprocessing dropdown.
        $this->assertFalse(MocapFile::isKlaar('2', 'Klaar'));
    }

    public function testNeitherGateSet(): void {
        $this->assertFalse(MocapFile::isKlaar(null, null));
    }
}
