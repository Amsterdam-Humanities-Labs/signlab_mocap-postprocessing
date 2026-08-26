# EAF Bundle Expansion and MCP Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Download Selected (EAF)` ship a complete per-take bundle (annotation, subtitles, post-processed FBX/GLB, reference video) and add a file-list filter that shows only both-Klaar takes with an EAF on disk.

**Architecture:** A new `TakeBundleLocator` service composes the existing `EafLocator` and resolves the animation and video assets, staying filesystem-pure so it unit-tests against fixture directories. `DownloadController::downloadEaf()` swaps to it, gains a `MISSING_ASSETS.txt` manifest and a 100-take cap. The filter resolves an eligible id set (SQL for Klaar, then a stat per candidate for EAF existence) and threads through the twelve query methods exactly as the existing `$labelFilter` does.

**Tech Stack:** PHP 7.4+, PDO (MySQL), ZipArchive, PHPUnit 9.5, Tailwind CSS via CDN, vanilla JS.

## Global Constraints

- Design doc: `docs/superpowers/specs/2026-08-12-eaf-bundle-and-mcp-filter-design.md`. Read it before starting. It builds on `docs/superpowers/specs/2026-08-12-eaf-batch-download-design.md`.
- PHP 7.4+ floor. Typed properties and arrow functions are available; `str_contains`, `match`, and constructor property promotion are **not** (PHP 8).
- Namespaces are lowercase per segment: `App\models`, `App\controllers`, `App\services`. Test directories are capitalised (`tests/Services/`), test namespaces are not (`Tests\services`). Follow that inconsistency; do not "fix" it.
- Asset directories, exactly:
  - Annotations: `/web/zin/eaf/zin/`
  - Post-processed FBX/GLB: `/web/gebarenoverleg_media/fbx/post_processed/`
  - Mini MP4: `/web/gebarenoverleg_media/blackamgic_filesMini/<capture_date>/` — **"blackamgic" is misspelled on disk and the code must match it**; it is a symlink to `/mnt/bigstorage/blackmagic_filesMini/`.
  - RIGHT MKV: `/mnt/bigstorage/razerFiles/`
- "Klaar" means `sentences.mcp_status_postprocessing === '1'` AND `sentences.mcp_status_tijd_annotatie === 'Klaar'`. The postprocessing column stores the dropdown's *value*, not the word.
- Post-processed files are keyed by `vicon_files.filename_pp`, which can differ from the take name. Never derive them from the take name.
- The take-level EAF rule is unchanged: no broadcast-level fallback, no `*_backup_*.srt`, and a take with no take-level EAF is excluded entirely.
- Batch ceiling: 100 takes, enforced server-side.
- Run tests with `./vendor/bin/phpunit` from `/web/animMIDI`. Baseline is 24 tests / 19 passing; the 5 failures are pre-existing placeholder-mock tests in `Tests\config\DatabaseTest` and `Tests\models\MocapFileTest`. Do not try to fix them.
- Stay on branch `babyloncc-viewer-preview-download-fixes`. Do not create branches, merge, or push.
- The working tree carries unrelated untracked scratch (`.claude/`, `.mcp.json`, `.playwright-mcp/`, `backups/`) and a modified `.phpunit.cache/test-results`. `git add` only the files each commit step names.

## File Structure

| File | Responsibility |
|---|---|
| `app/services/EafLocator.php` (modify) | Add `hasEaf()` — a cheap existence check for the filter. |
| `tests/Services/EafLocatorTest.php` (modify) | Cover `hasEaf()`. |
| `app/services/TakeBundleLocator.php` (new) | Resolve a take's full asset list: annotation (via `EafLocator`) + post-processed FBX/GLB + video. No DB, no HTTP. |
| `tests/Services/TakeBundleLocatorTest.php` (new) | Unit tests against fixture directories. |
| `app/controllers/DownloadController.php` (modify) | `downloadEaf()` uses the bundle locator, adds `MISSING_ASSETS.txt` and the 100-take cap. |
| `app/models/MocapFile.php` (modify) | `klaarFileIdsWithEaf()`, `mcpFilterPredicate()`, and the `$mcpFilter` parameter on twelve query methods. |
| `app/controllers/FileListController.php` (modify) | Read `$_GET['mcp']` and thread it into the model calls. |
| `app/views/file-list.php` (modify) | The filter dropdown; carry `mcp` in pagination links. |

---

### Task 1: `EafLocator::hasEaf()`

**Files:**
- Modify: `app/services/EafLocator.php`
- Test: `tests/Services/EafLocatorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\services\EafLocator::hasEaf(string $takeBasename): bool` — true only when the take-level `.eaf` exists inside the configured directory. Tasks 2 and 4 both rely on it.

- [ ] **Step 1: Write the failing test**

Append these two methods to the existing `EafLocatorTest` class (it already has a `setUp()` creating `$this->dir` and a `touchFile()` helper — reuse them, do not redefine them):

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Services/EafLocatorTest.php`
Expected: FAIL — `Call to undefined method App\services\EafLocator::hasEaf()`.

- [ ] **Step 3: Write the implementation**

Read the existing class first: it already has private `safeName()` and `isWithin()` helpers, and `safeName()` rejects any input containing a path separator *before* calling `basename()`. Reuse them exactly — do not duplicate or re-derive their logic.

Add to `app/services/EafLocator.php`, directly after `filesForTake()`:

```php
    /**
     * Cheap existence check for a take's annotation, skipping the SRT glob that
     * filesForTake() performs. Used by the file-list filter, which asks this
     * question once per candidate take.
     */
    public function hasEaf(string $takeBasename): bool {
        $safe = $this->safeName($takeBasename);
        if ($safe === '') {
            return false;
        }
        $path = $this->eafDir . $safe . '.eaf';
        return is_file($path) && $this->isWithin($path);
    }
```

If the existing `isWithin()` takes a second `$baseDir` argument rather than defaulting to `$this->eafDir`, match its actual signature.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Services/EafLocatorTest.php`
Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/services/EafLocator.php tests/Services/EafLocatorTest.php
git commit -m "feat: add EafLocator::hasEaf() existence check"
```

---

### Task 2: `TakeBundleLocator`

**Files:**
- Create: `app/services/TakeBundleLocator.php`
- Test: `tests/Services/TakeBundleLocatorTest.php`

**Interfaces:**
- Consumes: `App\services\EafLocator::filesForTake(string $takeBasename): array` (returns absolute paths; empty when the take-level `.eaf` is absent).
- Produces:
  - `App\services\TakeBundleLocator::__construct(EafLocator $eafLocator, string $ppDir = '/web/gebarenoverleg_media/fbx/post_processed/', string $miniDir = '/web/gebarenoverleg_media/blackamgic_filesMini/', string $mkvDir = '/mnt/bigstorage/razerFiles/')`
  - `TakeBundleLocator::bundleForTake(array $ctx): array` returning `['files' => [['path' => string, 'entry' => string], …], 'missing' => [string, …]]`. `$ctx` keys: `take`, `pp_filename`, `capture_date`, `mkv_name`. Task 3 consumes this exact shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Services/TakeBundleLocatorTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Services/TakeBundleLocatorTest.php`
Expected: FAIL — `Class "App\services\TakeBundleLocator" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/services/TakeBundleLocator.php`:

```php
<?php
namespace App\services;

/**
 * Resolves every file that belongs in one take's download bundle: the ELAN
 * annotation and its subtitle sidecars (delegated to EafLocator), the
 * post-processed animation, and a reference video.
 *
 * Filesystem-pure by design — the MKV filename is supplied by the caller from
 * MocapFile::getRightVideos(), so this class needs no database and can be
 * tested against fixture directories.
 *
 * The annotation is the gate: a take with no take-level .eaf yields an empty
 * bundle, and the caller excludes it. The other assets are optional — a take
 * whose GLB was never exported still ships, with the gap reported in 'missing'.
 */
class TakeBundleLocator {
    private EafLocator $eafLocator;
    private string $ppDir;
    private string $miniDir;
    private string $mkvDir;

    public function __construct(
        EafLocator $eafLocator,
        string $ppDir   = '/web/gebarenoverleg_media/fbx/post_processed/',
        // "blackamgic" (m/a transposed) is the real directory name on disk —
        // a symlink to /mnt/bigstorage/blackmagic_filesMini/. Do not "correct" it.
        string $miniDir = '/web/gebarenoverleg_media/blackamgic_filesMini/',
        string $mkvDir  = '/mnt/bigstorage/razerFiles/'
    ) {
        $this->eafLocator = $eafLocator;
        $this->ppDir   = rtrim($ppDir, '/') . '/';
        $this->miniDir = rtrim($miniDir, '/') . '/';
        $this->mkvDir  = rtrim($mkvDir, '/') . '/';
    }

    /**
     * $ctx keys: take, pp_filename, capture_date, mkv_name (any may be null).
     * Returns ['files' => [['path' => …, 'entry' => …], …], 'missing' => [...]].
     */
    public function bundleForTake(array $ctx): array {
        $take = $this->safeName((string)($ctx['take'] ?? ''));
        if ($take === '') {
            return ['files' => [], 'missing' => []];
        }

        $eafPaths = $this->eafLocator->filesForTake($take);
        if (empty($eafPaths)) {
            return ['files' => [], 'missing' => []];
        }

        $files = [];
        foreach ($eafPaths as $path) {
            $files[] = ['path' => $path, 'entry' => $take . '/' . basename($path)];
        }

        $missing = [];

        // Post-processed animation, keyed by filename_pp — which can differ
        // from the take name, so it must never be derived from $take.
        $pp = $this->safeName((string)($ctx['pp_filename'] ?? ''));
        if ($pp === '') {
            $missing[] = 'post-processed .fbx';
            $missing[] = 'post-processed .glb';
        } else {
            $fbxPath = $this->ppDir . $pp;
            if (is_file($fbxPath) && $this->isWithin($fbxPath, $this->ppDir)) {
                $files[] = ['path' => $fbxPath, 'entry' => $take . '/' . $pp];
            } else {
                $missing[] = 'post-processed .fbx';
            }

            $glb = preg_replace('/\.fbx$/i', '.glb', $pp);
            $glbPath = $this->ppDir . $glb;
            if ($glb !== $pp && is_file($glbPath) && $this->isWithin($glbPath, $this->ppDir)) {
                $files[] = ['path' => $glbPath, 'entry' => $take . '/' . $glb];
            } else {
                $missing[] = 'post-processed .glb';
            }
        }

        // Reference video: the small Mini MP4 first, the RIGHT MKV as fallback.
        $date = preg_replace('/[^0-9-]/', '', (string)($ctx['capture_date'] ?? ''));
        $miniPath = $date !== '' ? $this->miniDir . $date . '/' . $take . '.mp4' : '';
        $mkvName  = $this->safeName((string)($ctx['mkv_name'] ?? ''));
        $mkvPath  = $mkvName !== '' ? $this->mkvDir . $mkvName : '';

        if ($miniPath !== '' && is_file($miniPath) && $this->isWithin($miniPath, $this->miniDir)) {
            $files[] = ['path' => $miniPath, 'entry' => $take . '/' . basename($miniPath)];
        } elseif ($mkvPath !== '' && is_file($mkvPath) && $this->isWithin($mkvPath, $this->mkvDir)) {
            $files[] = ['path' => $mkvPath, 'entry' => $take . '/' . $mkvName];
        } else {
            $missing[] = 'reference video';
        }

        return ['files' => $files, 'missing' => $missing];
    }

    /**
     * Reduce a DB-sourced name to a safe bare basename. Anything containing a
     * path separator is rejected outright rather than normalised, and the strict
     * character class keeps traversal and shell/glob metacharacters out of every
     * path this class builds.
     */
    private function safeName(string $name): string {
        if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $name) ? $name : '';
    }

    /** True only if $path resolves to a real file inside $baseDir. */
    private function isWithin(string $path, string $baseDir): bool {
        $real = realpath($path);
        $base = realpath($baseDir);
        return $real !== false && $base !== false
            && strncmp($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Services/TakeBundleLocatorTest.php`
Expected: PASS — 8 tests.

- [ ] **Step 5: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: the 5 pre-existing failures and nothing new.

- [ ] **Step 6: Commit**

```bash
git add app/services/TakeBundleLocator.php tests/Services/TakeBundleLocatorTest.php
git commit -m "feat: add TakeBundleLocator for full per-take download bundles"
```

---

### Task 3: `downloadEaf()` ships the full bundle, with a cap

**Files:**
- Modify: `app/controllers/DownloadController.php` — add `use App\services\TakeBundleLocator;` beside the existing `use App\services\EafLocator;`, add a class constant, and rework the body of `downloadEaf()`

**Interfaces:**
- Consumes: `TakeBundleLocator::bundleForTake(array $ctx): array` → `['files' => [['path','entry'], …], 'missing' => [string, …]]` (Task 2); the existing `MocapFile::getRightVideos(array $captureIds): array` returning `capture_id => mkv filename`; the existing private `safeName()`, `logDownload()`, `deleteDirectory()`.
- Produces: no new signatures. `downloadEaf()` keeps its `(array $fileIds, array $currentUser = []): void` shape.

- [ ] **Step 1: Add the cap constant and the use statement**

At the top of `app/controllers/DownloadController.php`, beside the existing service import:

```php
use App\services\TakeBundleLocator;
```

And inside the class, next to the existing private path properties:

```php
    /**
     * Hard ceiling on takes per EAF bundle. At roughly 4.4 MB a take the ZIP is
     * built on temp disk before streaming, so an unbounded batch is a real
     * failure mode rather than a theoretical one.
     */
    private const EAF_BATCH_LIMIT = 100;
```

- [ ] **Step 2: Enforce the cap**

At the very top of `downloadEaf()`, before `getFilesByIds()` — the check must cost nothing when it trips:

```php
        if (count($fileIds) > self::EAF_BATCH_LIMIT) {
            header('HTTP/1.0 400 Bad Request');
            echo "Too many takes selected (" . count($fileIds) . "). "
               . "The limit is " . self::EAF_BATCH_LIMIT . " per EAF download — "
               . "please select fewer takes and try again.";
            exit;
        }
```

- [ ] **Step 3: Swap in the bundle locator**

Replace the `EafLocator` construction with the composed locator, and pre-fetch the MKV fallbacks in one query the way `downloadBulk()` already does. After the `$statuses = …getMcpStatusForFiles(…)` line:

```php
        $locator = new TakeBundleLocator(new EafLocator());

        // One query for every capture's RIGHT-MKV fallback, rather than one per take.
        $captureIds = array_values(array_unique(array_map(
            static fn($f) => $f['capture_id'],
            $files
        )));
        $rightVideos = $this->mocapFileModel->getRightVideos($captureIds);
```

Then, inside the per-file loop, replace the `$paths = $locator->filesForTake($take);` block and the `foreach ($paths as $path)` add-loop with:

```php
            $bundle = $locator->bundleForTake([
                'take'         => $take,
                'pp_filename'  => $file['filename_pp'] ?? null,
                'capture_date' => $file['capture_date'] ?? null,
                'mkv_name'     => $rightVideos[$file['capture_id']] ?? null,
            ]);

            if (empty($bundle['files'])) {
                $missing[] = $take;
                continue;
            }

            $addFailed = false;
            $addedEntries = [];
            foreach ($bundle['files'] as $item) {
                if ($zip->addFile($item['path'], $item['entry'])) {
                    $addedEntries[] = $item['entry'];
                    continue;
                }
                $addFailed = true;
                break;
            }

            if ($addFailed) {
                foreach ($addedEntries as $entry) {
                    $zip->deleteName($entry);
                }
                $missing[] = $take . ' (could not be added to the archive)';
                continue;
            }

            if (!empty($bundle['missing'])) {
                $incompleteAssets[] = $take . ' — ' . implode(', ', $bundle['missing']);
            }

            $included[] = $file;
```

Keep the existing `$addFailed`/`deleteName()` rollback semantics exactly as written above — that hardening is deliberate and must not be dropped. Declare `$incompleteAssets = [];` alongside the existing `$skipped` / `$missing` / `$included` initialisers.

- [ ] **Step 4: Add the `MISSING_ASSETS.txt` manifest**

Beside the existing `SKIPPED_NOT_KLAAR.txt` and `MISSING_EAF.txt` blocks, before `$zip->close()`:

```php
        if (!empty($incompleteAssets)) {
            $zip->addFromString(
                'MISSING_ASSETS.txt',
                "These takes were included, but some of their assets were not found on the server.\n"
                . "(A take is bundled whenever its .eaf exists; the post-processed animation and the\n"
                . "reference video are optional.)\n\n"
                . implode("\n", $incompleteAssets) . "\n"
            );
        }
```

- [ ] **Step 5: Verify the cap**

Run:

```bash
php -r 'require "vendor/autoload.php";
$c = new App\controllers\DownloadController();
$c->downloadEaf(range(1, 101), ["username" => "cap-test"]);'
```

Expected: prints the "Too many takes selected (101)" message and exits, with no ZIP work attempted. Then confirm the boundary is inclusive — a 100-id call must NOT trip the cap (it will fail later for other reasons if those ids do not exist, which is fine; you are only checking that the cap message does not appear).

- [ ] **Step 6: Verify the bundle end to end**

Pick three both-Klaar file ids and one control id that is not Klaar:

```bash
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg -N -e "
SELECT v.id FROM vicon_files v
JOIN matched_transcriptions mt ON mt.m_file = CONCAT(SUBSTRING_INDEX(v.filename,'_',2), '.wav') AND LOWER(mt.zOg)='zin'
JOIN sentences s ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
WHERE v.subdirectory='unreal/CC' AND s.mcp_status_postprocessing='1' AND s.mcp_status_tijd_annotatie='Klaar'
AND v.filename_pp IS NOT NULL LIMIT 3;"
```

In PHP CLI `header()` is a no-op and `readfile()` writes to stdout, so shell redirection captures the ZIP:

```bash
php -r 'require "vendor/autoload.php";
$c = new App\controllers\DownloadController();
$c->downloadEaf([<KLAAR_IDS>, <CONTROL_ID>], ["username" => "bundle-test"]);' > /tmp/eaf_bundle.zip
unzip -l /tmp/eaf_bundle.zip
```

Expected: one folder per Klaar take containing its `.eaf`, its `.srt` sidecars, a `.fbx` and `.glb` from `post_processed/`, and one `.mp4` or `.mkv`; `SKIPPED_NOT_KLAAR.txt` naming the control take; `MISSING_ASSETS.txt` present only if some take genuinely lacked an asset; and no `*_backup_*.srt` anywhere.

Then delete the verification rows so they do not pollute the activity views:

```sql
DELETE FROM download_logs WHERE username IN ('cap-test', 'bundle-test');
```

Run this through the `mysql` client. **If the permission gate refuses the command, stop and report it — do not route around the denial by moving the same SQL into a script file.** Leaving the rows for the controller to clean up is the correct outcome in that case.

- [ ] **Step 7: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: the 5 pre-existing failures, nothing new.

- [ ] **Step 8: Commit**

```bash
git add app/controllers/DownloadController.php
git commit -m "feat: EAF download ships post-processed FBX/GLB and video, capped at 100 takes"
```

---

### Task 4: MCP filter in the model

**Files:**
- Modify: `app/models/MocapFile.php` — a new cache property, two new methods, and a `$mcpFilter` parameter on twelve query methods

**Interfaces:**
- Consumes: `EafLocator::hasEaf(string $takeBasename): bool` (Task 1).
- Produces:
  - `MocapFile::klaarFileIdsWithEaf(): array` — a list of `vicon_files.id` (ints).
  - Twelve query methods each gain a trailing `?string $mcpFilter = null` parameter. Task 5 passes `'all'` or `'klaar_eaf'` into all of them.

- [ ] **Step 1: Add the cache property**

Beside the existing `private $broadcastNameCache = [];`:

```php
    private ?array $klaarEafIdCache = null;
```

- [ ] **Step 2: Add the id resolver**

Place it next to `getMcpStatusForFiles()`, whose join chain it reuses:

```php
    /**
     * vicon_files.id for every both-Klaar take that also has a take-level .eaf
     * on disk.
     *
     * Two steps by necessity: the Klaar gate is a SQL fact, but EAF existence is
     * a filesystem fact the database does not record, so each candidate needs a
     * stat. The candidate set is small (hundreds), so this costs single-digit
     * milliseconds. Cached per instance because the grouped query and the count
     * query both ask for it within one request.
     */
    public function klaarFileIdsWithEaf(): array {
        if ($this->klaarEafIdCache !== null) return $this->klaarEafIdCache;

        $sql = "
            SELECT vf.id AS file_id, vf.filename
            FROM vicon_files vf
            JOIN matched_transcriptions mt
                   ON mt.m_file = CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav')
                  AND LOWER(mt.zOg) = 'zin'
            JOIN sentences s
                   ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
            WHERE vf.subdirectory = 'unreal/CC'
              AND s.mcp_status_postprocessing = '1'
              AND s.mcp_status_tijd_annotatie = 'Klaar'
        ";
        $stmt = $this->db->query($sql);

        $locator = new \App\services\EafLocator();
        $ids  = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $fid = (int)$row['file_id'];
            if (isset($seen[$fid])) continue;   // one row per file, regardless of join fan-out
            $seen[$fid] = true;

            $take = preg_replace('/\.fbx$/i', '', basename((string)$row['filename']));
            if ($locator->hasEaf($take)) {
                $ids[] = $fid;
            }
        }

        return $this->klaarEafIdCache = $ids;
    }
```

Note the literal `vf.subdirectory = 'unreal/CC'` rather than `{$this->baseFilter}`: `baseFilter` uses unqualified column names, which would be ambiguous or wrong against this aliased three-table join. The blocked-dates exclusion that `baseFilter` also carries does not matter here, because this method only ever narrows a result set that the calling query has already filtered.

- [ ] **Step 3: Add the predicate builder**

Place it directly after the existing `labelFilterPredicate()`, whose return shape it copies:

```php
    /**
     * Supported $mcpFilter values: 'klaar_eaf' (both MCP gates Klaar AND a
     * take-level .eaf on disk). 'all', '' and null disable the filter.
     *
     * $idCol must be qualified to match the calling query's alias — 'f1.id' for
     * the grouped/by-date queries, plain 'id' for the count queries.
     */
    private function mcpFilterPredicate(string $idCol, ?string $mcpFilter): array {
        if ($mcpFilter === null) return ['', []];
        $mf = strtolower(trim($mcpFilter));
        if ($mf === '' || $mf === 'all') return ['', []];
        if ($mf !== 'klaar_eaf') return ['', []];

        $ids = $this->klaarFileIdsWithEaf();
        if (empty($ids)) return ['1 = 0', []]; // no matches → filter out everything

        $ph = implode(',', array_fill(0, count($ids), '?'));
        return ["$idCol IN ($ph)", $ids];
    }
```

- [ ] **Step 4: Thread the parameter through all twelve query methods**

Each of these takes a new **trailing** `?string $mcpFilter = null` parameter, and gains a predicate block immediately after its existing `labelFilterPredicate` block. Use `f1.id` where the method's existing label predicate uses `f1.filename`, and plain `id` where it uses `filename`:

| Method | Existing label column | `$idCol` to use |
|---|---|---|
| `getUnprocessedFilesGroupedByDate` | `f1.filename` | `f1.id` |
| `getUnprocessedFilesCount` | `filename` | `id` |
| `getUnprocessedFilesByDate` | `f1.filename` | `f1.id` |
| `getUnprocessedFilesCountByDate` | `filename` | `id` |
| `getProcessedFilesGroupedByDate` | `filename` | `id` |
| `getProcessedFilesByDate` | `filename` | `id` |
| `getProcessedFilesCount` | `filename` | `id` |
| `getProcessedFilesCountByDate` | `filename` | `id` |
| `getAllFilesGroupedByDate` | `f1.filename` | `f1.id` |
| `getAllFilesByDate` | `f1.filename` | `f1.id` |
| `getAllFilesCount` | `filename` | `id` |
| `getAllFilesCountByDate` | `filename` | `id` |

Do not guess the column from the table above alone — open each method and confirm what its `labelFilterPredicate` call passes, because that is the alias actually in scope.

The block to add in each, immediately after the existing label block (adjust `f1.id` / `id` per the table):

```php
        [$mfrag, $mp] = $this->mcpFilterPredicate('f1.id', $mcpFilter);
        if ($mfrag !== '') {
            $sql .= " AND " . $mfrag;
            $params = array_merge($params, $mp);
        }
```

The existing label block in each method is the exact template — match its placement and style.

- [ ] **Step 5: Verify against the live database**

The filter has no unit test (it needs both the database and the annotation directory), so verify it directly:

```bash
php -r 'require "vendor/autoload.php";
$m = new App\models\MocapFile();
$ids = $m->klaarFileIdsWithEaf();
printf("klaar+eaf ids: %d\n", count($ids));

$all  = $m->getAllFilesCount("", null, null, "all");
$filt = $m->getAllFilesCount("", null, null, "klaar_eaf");
printf("all files: %d, filtered: %d\n", $all, $filt);

$rows = $m->getAllFilesGroupedByDate(25, 1, "", null, null, "klaar_eaf");
$n = 0; foreach ($rows as $d => $fs) { $n += count($fs); }
printf("first page rows: %d\n", $n);'
```

Expected: `klaar+eaf ids` is in the low hundreds (the design measured 670 both-Klaar takes, of which 402 had a take-level EAF, so a number near 400 is right); `filtered` is far smaller than `all` and consistent with the id count; `first page rows` is greater than zero and no more than 25. A count of 0, or `filtered == all`, means the predicate is not being applied — fix before continuing.

Also confirm the cache works — two calls in one request must issue only one round of stats:

```bash
php -r 'require "vendor/autoload.php";
$m = new App\models\MocapFile();
$t0 = microtime(true); $m->klaarFileIdsWithEaf(); $t1 = microtime(true);
$m->klaarFileIdsWithEaf(); $t2 = microtime(true);
printf("first: %.1f ms, cached: %.3f ms\n", ($t1-$t0)*1000, ($t2-$t1)*1000);'
```

Expected: the second call is orders of magnitude faster.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: the 5 pre-existing failures, nothing new.

- [ ] **Step 7: Commit**

```bash
git add app/models/MocapFile.php
git commit -m "feat: add MCP Klaar+EAF filter to the file list queries"
```

---

### Task 5: Filter UI and controller wiring

**Files:**
- Modify: `app/controllers/FileListController.php` — read `$_GET['mcp']` and pass it to all twelve model calls
- Modify: `app/views/file-list.php` — the dropdown, and `mcp` in every pagination link

**Interfaces:**
- Consumes: the twelve query methods' trailing `?string $mcpFilter = null` parameter (Task 4).
- Produces: nothing later depends on this.

- [ ] **Step 1: Read the parameter in the controller**

In `FileListController::index()`, beside the existing `$labelFilter = $_GET['label'] ?? 'all';`:

```php
        $mcpFilter = $_GET['mcp'] ?? 'all';
```

- [ ] **Step 2: Pass it to every model call**

The controller makes twelve calls (grouped/by-date/count across the unprocessed, processed and all branches). Add `$mcpFilter` as the final argument to each. For example:

```php
$filesGroupedByDate = $this->mocapFileModel->getProcessedFilesGroupedByDate($limit, $page, $searchTerm, $reviewStatus, $allowedDates, $labelFilter, $mcpFilter);
$totalFiles         = $this->mocapFileModel->getProcessedFilesCount($searchTerm, $reviewStatus, $allowedDates, $labelFilter, $mcpFilter);
```

Work through every call site — grep for `$labelFilter` in the file; each occurrence is a call that needs the new argument. Missing one produces a page whose count disagrees with its rows, which is exactly the bug this step exists to avoid.

- [ ] **Step 3: Add the dropdown**

In `app/views/file-list.php`, in the filter form beside the existing label dropdown, matching its markup:

```php
                    <?php $selectedMcp = $_GET['mcp'] ?? 'all'; ?>
                    <div>
                        <select name="mcp" class="border rounded px-3 py-2">
                            <option value="all" <?php echo $selectedMcp === 'all' ? 'selected' : ''; ?>>Alle MCP statussen</option>
                            <option value="klaar_eaf" <?php echo $selectedMcp === 'klaar_eaf' ? 'selected' : ''; ?>>MCP Klaar + EAF beschikbaar</option>
                        </select>
                    </div>
```

The form already auto-submits on any `select` change (there is a listener binding every `select` inside `form[action="index.php"]`), so no JavaScript is needed.

- [ ] **Step 4: Carry `mcp` through the pagination links**

Each pagination `<a href="?status=…&date=…&limit=…&page=…&search=…&label=…">` needs `&mcp=<?php echo urlencode($selectedMcp); ?>` appended. There are three such links (previous, numbered, next). Without this, paging past page 1 silently drops the filter.

Make `$selectedMcp` available where the pagination block reads it — define it once near the top of the view alongside the other `$selected*` variables rather than only inside the filter form.

- [ ] **Step 5: Syntax-check**

Run: `php -l app/views/file-list.php && php -l app/controllers/FileListController.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Verify the rendered page**

A render harness for this view already exists as a model — read `/web/animMIDI/.superpowers/sdd/2026-08-12-eaf-batch-download/task-4-render-verification.md` for the approach (set up the controller's variables, include the view directly, bypassing auth). Build on it in the scratchpad (not in the repo) and confirm, for `?mcp=klaar_eaf` in each of the three status modes:

- Every rendered row is one of the ids returned by `klaarFileIdsWithEaf()`.
- The row count matches the count the controller computed for the same filter — a mismatch means a model call was missed in Step 2.
- Every row shows the green `MCP Klaar` badge (by construction, a filtered row is Klaar).
- With `?mcp=all`, the row set is unchanged from before this task.
- `<th>`/`<td>` counts stay symmetric.

Report the actual numbers.

- [ ] **Step 7: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: the 5 pre-existing failures, nothing new.

- [ ] **Step 8: Commit**

```bash
git add app/controllers/FileListController.php app/views/file-list.php
git commit -m "feat: add MCP Klaar+EAF filter dropdown to the file list"
```

---

## Self-Review Notes

Spec coverage:

| Spec section | Task |
|---|---|
| `TakeBundleLocator` (interface, resolution rules, entry naming) | Task 2 |
| `EafLocator::hasEaf()` | Task 1 |
| `downloadEaf()` bundle swap, `MISSING_ASSETS.txt`, cap | Task 3 |
| `klaarFileIdsWithEaf()`, `mcpFilterPredicate()` | Task 4 |
| Filter UI, controller wiring, pagination | Task 5 |
| Error-handling table | Task 3 Steps 2-4 (cap, manifests), Task 4 Step 3 (empty set → `1 = 0`) |
| Testing section | Tasks 1-2 (unit), Tasks 3-5 (live verification) |

Deliberately unchanged, per the spec's Out of Scope: the `Download Selected (EAF)` button label; the `getMcpStatusForFiles()`/`getGlossesForFiles()` query merge; the full 100 MB Blackmagic MP4; filtering on MCP status without the EAF check.
