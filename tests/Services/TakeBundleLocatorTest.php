<?php
namespace Tests\services;

use PHPUnit\Framework\TestCase;
use App\services\EafLocator;
use App\services\TakeBundleLocator;

class TakeBundleLocatorTest extends TestCase {
    private string $root;
    private string $eafDir;
    private string $ppDir;
    private string $miniDir;
    private string $mkvDir;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/bundletest_' . uniqid();
        $this->eafDir  = $this->root . '/eaf';
        $this->ppDir   = $this->root . '/pp';
        $this->miniDir = $this->root . '/mini';
        $this->mkvDir  = $this->root . '/mkv';
        foreach ([$this->root, $this->eafDir, $this->ppDir, $this->miniDir, $this->mkvDir] as $d) {
            mkdir($d);
        }
    }

    protected function tearDown(): void {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function put(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($path, 'x');
    }

    private function locator(): TakeBundleLocator {
        return new TakeBundleLocator(
            new EafLocator($this->eafDir),
            $this->ppDir,
            $this->miniDir,
            $this->mkvDir
        );
    }

    /** Entry names only, for readable assertions. */
    private function entries(array $bundle): array {
        $names = array_map(static fn($f) => $f['entry'], $bundle['files']);
        sort($names);
        return $names;
    }

    public function testBundlesEverySupportedAsset(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->eafDir}/{$take}_Nederlands.srt");
        $this->put("{$this->ppDir}/{$take}.fbx");
        $this->put("{$this->ppDir}/{$take}.glb");
        $this->put("{$this->miniDir}/2026-03-19/{$take}.mp4");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => "{$take}.fbx",
            'capture_date' => '2026-03-19',
            'mkv_name'     => null,
        ]);

        $this->assertSame([
            "{$take}/{$take}.eaf",
            "{$take}/{$take}.fbx",
            "{$take}/{$take}.glb",
            "{$take}/{$take}.mp4",
            "{$take}/{$take}_Nederlands.srt",
        ], $this->entries($bundle));
        $this->assertSame([], $bundle['missing']);
    }

    public function testUsesFilenamePpWhenItDiffersFromTakeName(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->ppDir}/renamed_take_v2.fbx");
        $this->put("{$this->ppDir}/renamed_take_v2.glb");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => 'renamed_take_v2.fbx',
            'capture_date' => null,
            'mkv_name'     => null,
        ]);

        $this->assertContains("{$take}/renamed_take_v2.fbx", $this->entries($bundle));
        $this->assertContains("{$take}/renamed_take_v2.glb", $this->entries($bundle));
    }

    public function testMissingPostProcessedAssetsStillBundleTheTake(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->mkvDir}/some_take_RIGHT.mkv");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => "{$take}.fbx",
            'capture_date' => null,
            'mkv_name'     => 'some_take_RIGHT.mkv',
        ]);

        $this->assertContains("{$take}/{$take}.eaf", $this->entries($bundle));
        $this->assertSame(['post-processed .fbx', 'post-processed .glb'], $bundle['missing']);
    }

    public function testNullFilenamePpMarksBothAnimationAssetsMissing(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => null,
            'capture_date' => null,
            'mkv_name'     => null,
        ]);

        $this->assertSame(
            ['post-processed .fbx', 'post-processed .glb', 'reference video'],
            $bundle['missing']
        );
    }

    public function testMiniMp4IsPreferredOverMkv(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->miniDir}/2026-03-19/{$take}.mp4");
        $this->put("{$this->mkvDir}/some_take_RIGHT.mkv");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => null,
            'capture_date' => '2026-03-19',
            'mkv_name'     => 'some_take_RIGHT.mkv',
        ]);

        $this->assertContains("{$take}/{$take}.mp4", $this->entries($bundle));
        $this->assertNotContains("{$take}/some_take_RIGHT.mkv", $this->entries($bundle));
    }

    public function testFallsBackToMkvWhenNoMiniMp4(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->mkvDir}/some_take_RIGHT.mkv");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => null,
            'capture_date' => '2026-03-19',
            'mkv_name'     => 'some_take_RIGHT.mkv',
        ]);

        $this->assertContains("{$take}/some_take_RIGHT.mkv", $this->entries($bundle));
        $this->assertNotContains('reference video', $bundle['missing']);
    }

    public function testNoEafMeansEmptyBundle(): void {
        $take = 'M20240925_1858_260319_0';
        // Every other asset exists; only the annotation is absent.
        $this->put("{$this->ppDir}/{$take}.fbx");
        $this->put("{$this->ppDir}/{$take}.glb");
        $this->put("{$this->miniDir}/2026-03-19/{$take}.mp4");

        $bundle = $this->locator()->bundleForTake([
            'take'         => $take,
            'pp_filename'  => "{$take}.fbx",
            'capture_date' => '2026-03-19',
            'mkv_name'     => null,
        ]);

        $this->assertSame([], $bundle['files']);
        $this->assertSame([], $bundle['missing']);
    }

    public function testRejectsTraversalInTakeAndFilenamePp(): void {
        $take = 'M20240925_1858_260319_0';
        $this->put("{$this->eafDir}/{$take}.eaf");
        $this->put("{$this->ppDir}/{$take}.fbx");

        $traversal = $this->locator()->bundleForTake([
            'take' => "../{$take}", 'pp_filename' => null,
            'capture_date' => null, 'mkv_name' => null,
        ]);
        $this->assertSame([], $traversal['files']);

        $badPp = $this->locator()->bundleForTake([
            'take' => $take, 'pp_filename' => "../pp/{$take}.fbx",
            'capture_date' => null, 'mkv_name' => null,
        ]);
        $this->assertContains('post-processed .fbx', $badPp['missing']);
    }
}
