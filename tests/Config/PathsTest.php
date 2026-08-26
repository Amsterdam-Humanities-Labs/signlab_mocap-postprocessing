<?php
namespace Tests\Config;

use App\config\Paths;
use PHPUnit\Framework\TestCase;

class PathsTest extends TestCase {
    protected function tearDown(): void { Paths::set(null); }

    public function testDefaultsLoadAndNormalise(): void {
        Paths::set(null);
        $this->assertStringEndsWith('/', Paths::dir('fbx_processed'));
        $this->assertSame('/web', rtrim(Paths::get('web_root'), '/'));
    }

    public function testOverrideAndToUrl(): void {
        Paths::set(['web_root' => '/srv/www/', 'fbx_original' => '/srv/www/media/fbx']);
        $this->assertSame('/srv/www/media/fbx/', Paths::dir('fbx_original'));
        $this->assertSame('/media/fbx/a.glb', Paths::toUrl('/srv/www/media/fbx/a.glb'));
        $this->assertSame('', Paths::toUrl('/mnt/elsewhere/a.glb'));
        $this->assertFalse(Paths::isUnderWebRoot('/tmp/x'));
    }

    public function testUnknownKeyThrows(): void {
        $this->expectException(\RuntimeException::class);
        Paths::get('nope');
    }
}
