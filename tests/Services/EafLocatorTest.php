<?php
namespace Tests\services;

use PHPUnit\Framework\TestCase;
use App\services\EafLocator;

class EafLocatorTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/eaftest_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function touchFile(string $name): void {
        file_put_contents($this->dir . '/' . $name, 'x');
    }

    public function testReturnsEafAndSidecarSrts(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $this->touchFile('M20240925_1858_260319_0_Nederlands.srt');
        $this->touchFile('M20240925_1858_260319_0_Gebaar-voor-gebaar.srt');

        $found = (new EafLocator($this->dir))->filesForTake('M20240925_1858_260319_0');
        $names = array_map('basename', $found);
        sort($names);

        $this->assertSame([
            'M20240925_1858_260319_0.eaf',
            'M20240925_1858_260319_0_Gebaar-voor-gebaar.srt',
            'M20240925_1858_260319_0_Nederlands.srt',
        ], $names);
    }

    public function testExcludesBackupSrts(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $this->touchFile('M20240925_1858_260319_0_Nederlands.srt');
        $this->touchFile('M20240925_1858_260319_0_Nederlands_backup_20250415_084345.srt');

        $found = (new EafLocator($this->dir))->filesForTake('M20240925_1858_260319_0');
        $names = array_map('basename', $found);

        $this->assertNotContains('M20240925_1858_260319_0_Nederlands_backup_20250415_084345.srt', $names);
        $this->assertCount(2, $names);
    }

    public function testReturnsEmptyWhenTakeLevelEafMissing(): void {
        // Only the broadcast-level EAF and a sidecar exist — no take-level EAF.
        $this->touchFile('M20240925_1858.eaf');
        $this->touchFile('M20240925_1858_260319_0_Nederlands.srt');

        $found = (new EafLocator($this->dir))->filesForTake('M20240925_1858_260319_0');

        $this->assertSame([], $found);
    }

    public function testDoesNotMatchOtherTakesOfSameBroadcast(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $this->touchFile('M20240925_1858_260319_0_Nederlands.srt');
        $this->touchFile('M20240925_1858_260319_1_Nederlands.srt');

        $found = (new EafLocator($this->dir))->filesForTake('M20240925_1858_260319_0');
        $names = array_map('basename', $found);

        $this->assertNotContains('M20240925_1858_260319_1_Nederlands.srt', $names);
    }

    public function testRejectsTraversalAndGlobMetacharacters(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $locator = new EafLocator($this->dir);

        $this->assertSame([], $locator->filesForTake('../M20240925_1858_260319_0'));
        $this->assertSame([], $locator->filesForTake('M2024*'));
        $this->assertSame([], $locator->filesForTake(''));
    }

    public function testHasEafIsTrueOnlyForTakeLevelEaf(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $this->touchFile('M20240925_1858.eaf');
        $this->touchFile('M20240925_1858_260319_1_Nederlands.srt');

        $locator = new EafLocator($this->dir);

        $this->assertTrue($locator->hasEaf('M20240925_1858_260319_0'));
        // Only an SRT exists for this take — no annotation.
        $this->assertFalse($locator->hasEaf('M20240925_1858_260319_1'));
        // Never seen at all.
        $this->assertFalse($locator->hasEaf('M20240925_9999_260319_0'));
    }

    public function testHasEafRejectsUnsafeNames(): void {
        $this->touchFile('M20240925_1858_260319_0.eaf');
        $locator = new EafLocator($this->dir);

        $this->assertFalse($locator->hasEaf('../M20240925_1858_260319_0'));
        $this->assertFalse($locator->hasEaf('M2024*'));
        $this->assertFalse($locator->hasEaf(''));
    }

    public function testHasEafRejectsDotAndDotDot(): void {
        // Deliberately named so a non-rejecting safeName() would "accidentally"
        // resolve to a real file here: eafDir + '.' + '.eaf' === '..eaf', and
        // eafDir + '..' + '.eaf' === '...eaf'. If safeName() ever stops
        // rejecting '.'/'..' explicitly, this test starts passing for the
        // wrong reason (a literal match) instead of failing loudly.
        $this->touchFile('..eaf');
        $this->touchFile('...eaf');
        $locator = new EafLocator($this->dir);

        try {
            $this->assertFalse($locator->hasEaf('.'));
            $this->assertFalse($locator->hasEaf('..'));
        } finally {
            // Dot-prefixed names are invisible to tearDown()'s glob('*') sweep;
            // remove them explicitly so rmdir($this->dir) doesn't fail.
            @unlink($this->dir . '/..eaf');
            @unlink($this->dir . '/...eaf');
        }
    }
}
