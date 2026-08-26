# Batch EAF/SRT Download Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Download Selected (EAF)" button to the animMIDI file list that ZIPs the ELAN annotation file and its sidecar SRTs for each selected take, but only for takes whose two MCP statuses both read "Klaar".

**Architecture:** A new `EafLocator` service resolves take-level annotation files on disk with no database or network involvement. A new `MocapFile::getMcpStatusForFiles()` reads both MCP gates in one query, reusing the exact join chain `getGlossesForFiles()` already uses. `DownloadController::downloadEaf()` combines the two, skipping non-qualifying takes into explanatory manifest files inside the ZIP. The file list gains universal row checkboxes, the new button, and a per-row Klaar badge.

**Tech Stack:** PHP 7.4+, PDO (MySQL), ZipArchive, PHPUnit 9.5, Tailwind CSS via CDN, vanilla JS.

## Global Constraints

- Design doc: `docs/superpowers/specs/2026-08-12-eaf-batch-download-design.md`. Read it before starting.
- Namespaces are lowercase per segment: `App\models`, `App\controllers`, `App\config`, and the new `App\services`. Test namespaces mirror this: `Tests\models`, `Tests\services`.
- PSR-4 maps `App\` → `app/` and `Tests\` → `tests/`. Test directories are capitalised (`tests/Models/`), namespaces are not (`Tests\models`). Follow that inconsistency; do not "fix" it.
- Annotation directory: `/web/zin/eaf/zin/`
- "Klaar" means `sentences.mcp_status_postprocessing === '1'` **and** `sentences.mcp_status_tijd_annotatie === 'Klaar'`. The postprocessing column stores the dropdown's *value* (`<option value="1">Klaar</option>` in `/web/zin/zinnen.html`), not the word. Comment this wherever it appears.
- Never fall back to the broadcast-level EAF (`M20240925_1858.eaf`) when the take-level one (`M20240925_1858_260319_0.eaf`) is missing — it is not time-aligned to the take.
- Never include `*_backup_*.srt` files.
- Run tests with `./vendor/bin/phpunit` from `/web/animMIDI`.
- Current working branch is `babyloncc-viewer-preview-download-fixes`. Stay on it.
- `app/models/MocapFile.php` has uncommitted changes in the working tree. Do not revert or stage them; `git add` only the specific files each commit step names.

## File Structure

| File | Responsibility |
|---|---|
| `app/services/EafLocator.php` (new) | Given a take basename, return the disk paths of its `.eaf` and non-backup `.srt` sidecars. No DB, no HTTP. |
| `tests/Services/EafLocatorTest.php` (new) | Unit tests for the above against a fixture directory. |
| `app/models/MocapFile.php` (modify) | Add `isKlaar()` static predicate and `getMcpStatusForFiles()` query. |
| `tests/Models/McpStatusTest.php` (new) | Unit tests for the `isKlaar()` predicate. |
| `migrations/006_download_logs_eaf_type.sql` (new) | Extend the `download_logs.download_type` enum with `'eaf'`. |
| `app/controllers/DownloadController.php` (modify) | Add `downloadEaf()`: enforce Klaar, build the ZIP, write manifests, log. |
| `public/download-eaf.php` (new) | Auth + entry point, mirroring `public/download.php`. |
| `app/controllers/FileListController.php` (modify) | Fetch the status map and expose it to the view. |
| `app/views/file-list.php` (modify) | Universal checkboxes, the new button, per-row badge, `downloadSelectedEaf()`. |

---

### Task 1: `EafLocator` service

**Files:**
- Create: `app/services/EafLocator.php`
- Test: `tests/Services/EafLocatorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `App\services\EafLocator::__construct(string $eafDir = '/web/zin/eaf/zin/')` and `EafLocator::filesForTake(string $takeBasename): array` returning a list of absolute file paths, empty when the take-level `.eaf` is absent. Task 3 uses both.

- [ ] **Step 1: Write the failing test**

Create `tests/Services/EafLocatorTest.php`:

```php
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Services/EafLocatorTest.php`
Expected: FAIL — `Class "App\services\EafLocator" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/services/EafLocator.php`:

```php
<?php
namespace App\services;

/**
 * Locates the ELAN annotation file and its sidecar subtitle files for one mocap
 * take inside the shared annotation directory (/web/zin/eaf/zin/).
 *
 * Take-level only: take "M20240925_1858_260319_0" resolves to
 * M20240925_1858_260319_0.eaf plus every M20240925_1858_260319_0_*.srt.
 * The broadcast-level M20240925_1858.eaf is deliberately NOT a fallback — it
 * covers the whole broadcast and is not time-aligned to an individual take, so
 * shipping it would be silently wrong rather than merely missing.
 */
class EafLocator {
    private string $eafDir;

    public function __construct(string $eafDir = '/web/zin/eaf/zin/') {
        $this->eafDir = rtrim($eafDir, '/') . '/';
    }

    /**
     * Absolute paths of the take's .eaf plus its non-backup .srt sidecars.
     * Returns [] when the take-level .eaf is missing: the SRTs alone are not
     * useful without the annotation they belong to.
     */
    public function filesForTake(string $takeBasename): array {
        $safe = $this->safeName($takeBasename);
        if ($safe === '') {
            return [];
        }

        $eafPath = $this->eafDir . $safe . '.eaf';
        if (!is_file($eafPath) || !$this->isWithin($eafPath)) {
            return [];
        }

        $found = [$eafPath];
        foreach (glob($this->eafDir . $safe . '_*.srt') ?: [] as $srt) {
            // The directory keeps timestamped history next to the live files.
            if (strpos(basename($srt), '_backup_') !== false) {
                continue;
            }
            if (is_file($srt) && $this->isWithin($srt)) {
                $found[] = $srt;
            }
        }
        return $found;
    }

    /**
     * Reduce a (DB-sourced, but still untrusted) take name to a bare basename of
     * safe characters. The strict character class blocks path traversal and also
     * keeps glob() metacharacters (*, ?, [) out of the pattern built above.
     */
    private function safeName(string $name): string {
        $base = basename(str_replace('\\', '/', $name));
        return preg_match('/^[A-Za-z0-9._-]+$/', $base) ? $base : '';
    }

    /** True only if $path resolves to a real file inside the annotation directory. */
    private function isWithin(string $path): bool {
        $real = realpath($path);
        $base = realpath($this->eafDir);
        return $real !== false && $base !== false
            && strncmp($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Services/EafLocatorTest.php`
Expected: PASS — 5 tests, 7 assertions.

- [ ] **Step 5: Verify against the real annotation directory**

Run:

```bash
php -r 'require "vendor/autoload.php";
$l = new App\services\EafLocator();
var_dump(array_map("basename", $l->filesForTake("M20240925_1858_260319_0")));
var_dump($l->filesForTake("M20240925_1858"));'
```

Expected: the first dump lists `M20240925_1858_260319_0.eaf` plus its `.srt` sidecars; the second is an empty array only if no `M20240925_1858_*.srt`-style take files pollute it — a non-empty result here is fine and not an error, since `M20240925_1858.eaf` genuinely exists. Record what you see; do not change code to make the second dump empty.

- [ ] **Step 6: Commit**

```bash
git add app/services/EafLocator.php tests/Services/EafLocatorTest.php
git commit -m "feat: add EafLocator for take-level annotation files"
```

---

### Task 2: MCP status lookup on `MocapFile`

**Files:**
- Modify: `app/models/MocapFile.php` (add both methods immediately after `getGlossesForFiles()`, which ends at the `// ─── Helpers ───` comment)
- Test: `tests/Models/McpStatusTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `App\models\MocapFile::isKlaar(?string $pp, ?string $ta): bool` — static.
  - `App\models\MocapFile::getMcpStatusForFiles(array $fileIds): array` — returns `int $fileId => ['pp' => ?string, 'ta' => ?string, 'klaar' => bool]`. Files with no matching sentence are **absent** from the map. Tasks 3 and 4 both consume this shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Models/McpStatusTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/Models/McpStatusTest.php`
Expected: FAIL — `Call to undefined method App\models\MocapFile::isKlaar()`.

- [ ] **Step 3: Write the implementation**

In `app/models/MocapFile.php`, insert both methods after the closing brace of `getGlossesForFiles()` and before the `// ─── Helpers ───` comment:

```php
    /**
     * True when both motion-capture gates read "Klaar".
     *
     * Note the asymmetry between the two columns: zinnen.html renders the
     * postprocessing dropdown as <option value="1">Klaar</option>, so that
     * column stores '1' (and '2' for "Check nodig"), while the tijd-annotatie
     * column stores the literal word "Klaar".
     */
    public static function isKlaar(?string $pp, ?string $ta): bool {
        return $pp === '1' && $ta === 'Klaar';
    }

    /**
     * MCP postprocessing / tijd-annotatie status per vicon_files row.
     *
     * Follows the same chain as getGlossesForFiles(): the mocap filename's first
     * two underscore-separated parts are the broadcast name, which matches
     * matched_transcriptions.m_file with a .wav suffix, whose m_transcription is
     * the sentences.ID for zOg='zin' rows.
     *
     * Returns file_id => ['pp' => ?string, 'ta' => ?string, 'klaar' => bool].
     * Files with no matching sentence are absent from the map; callers treat a
     * missing entry as not-Klaar.
     */
    public function getMcpStatusForFiles(array $fileIds): array {
        if (empty($fileIds)) return [];
        $ph = implode(',', array_fill(0, count($fileIds), '?'));

        $sql = "
            SELECT vf.id AS file_id,
                   s.mcp_status_postprocessing AS pp,
                   s.mcp_status_tijd_annotatie AS ta
            FROM vicon_files vf
            JOIN matched_transcriptions mt
                   ON mt.m_file = CONCAT(SUBSTRING_INDEX(vf.filename, '_', 2), '.wav')
                  AND LOWER(mt.zOg) = 'zin'
            JOIN sentences s
                   ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
            WHERE vf.id IN ($ph)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($fileIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $fid = (int)$row['file_id'];
            if (isset($result[$fid])) continue; // first match per file wins

            $pp = $row['pp'] !== null ? (string)$row['pp'] : null;
            $ta = $row['ta'] !== null ? (string)$row['ta'] : null;
            $result[$fid] = ['pp' => $pp, 'ta' => $ta, 'klaar' => self::isKlaar($pp, $ta)];
        }
        return $result;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/Models/McpStatusTest.php`
Expected: PASS — 5 tests, 8 assertions.

- [ ] **Step 5: Verify the query against the real database**

The unit test covers the predicate only; the SQL needs a live check. Run:

```bash
php -r 'require "vendor/autoload.php";
$m = new App\models\MocapFile();
$db = App\config\Database::getInstance()->getConnection();
$ids = $db->query("SELECT id FROM vicon_files WHERE subdirectory = \"unreal/CC\" ORDER BY id DESC LIMIT 400")
          ->fetchAll(PDO::FETCH_COLUMN);
$map = $m->getMcpStatusForFiles($ids);
printf("rows in: %d, statuses found: %d, klaar: %d\n",
    count($ids), count($map), count(array_filter($map, fn($s) => $s["klaar"])));'
```

Expected: "statuses found" is greater than 0 and no more than "rows in"; "klaar" is greater than 0. A fatal SQL error or `statuses found: 0` means the join is wrong — fix before continuing.

Cross-check the total against the design doc's figure of 670 both-Klaar takes:

```bash
mysql -u user -pCHeZeGa85W admin_gebarenoverleg -e "
SELECT COUNT(DISTINCT v.id) AS both_klaar FROM vicon_files v
JOIN matched_transcriptions mt ON mt.m_file = CONCAT(SUBSTRING_INDEX(v.filename,'_',2), '.wav') AND LOWER(mt.zOg)='zin'
JOIN sentences s ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
WHERE v.subdirectory='unreal/CC' AND s.mcp_status_postprocessing='1' AND s.mcp_status_tijd_annotatie='Klaar';"
```

Expected: a number near 670 (it drifts as annotators work; an order-of-magnitude match is what matters).

- [ ] **Step 6: Commit**

```bash
git add app/models/MocapFile.php tests/Models/McpStatusTest.php
git commit -m "feat: add MCP Klaar status lookup to MocapFile"
```

Note: `app/models/MocapFile.php` had uncommitted changes before this task. That is expected — this commit carries them along.

---

### Task 3: `downloadEaf()` controller action and entry point

**Files:**
- Create: `migrations/006_download_logs_eaf_type.sql`
- Modify: `app/controllers/DownloadController.php` (add `use App\services\EafLocator;` near the existing `use` block at the top; add `downloadEaf()` after `downloadBulk()`, which ends at line 266)
- Create: `public/download-eaf.php`

**Interfaces:**
- Consumes: `EafLocator::filesForTake()` (Task 1); `MocapFile::getMcpStatusForFiles()` (Task 2); the existing private `DownloadController::safeName()` and `logDownload()`.
- Produces: `DownloadController::downloadEaf(array $fileIds, array $currentUser = []): void` and the URL `public/download-eaf.php?bulk=1,2,3`, which Task 4's JavaScript calls.

- [ ] **Step 1: Add the migration file**

The `download_logs.download_type` column is an ENUM that does not yet allow `'eaf'`, so logging would silently fail (or throw in strict mode). Create `migrations/006_download_logs_eaf_type.sql`:

```sql
ALTER TABLE download_logs
    MODIFY download_type ENUM('original', 'processed', 'bulk', 'upload', 'mark_processed', 'mark_unprocessed', 'eaf') NOT NULL DEFAULT 'original';
```

- [ ] **Step 2: Apply the migration**

Run:

```bash
mysql -u user -pCHeZeGa85W admin_gebarenoverleg < migrations/006_download_logs_eaf_type.sql
mysql -u user -pCHeZeGa85W admin_gebarenoverleg -e "SHOW COLUMNS FROM download_logs LIKE 'download_type'"
```

Expected: the printed enum definition includes `'eaf'`.

- [ ] **Step 3: Add the `use` statement**

In `app/controllers/DownloadController.php`, add below the existing `use App\config\Database;`:

```php
use App\services\EafLocator;
```

- [ ] **Step 4: Write `downloadEaf()`**

Add this method to `DownloadController` after `downloadBulk()`:

```php
    /**
     * ZIP the ELAN annotation file and sidecar SRTs for each selected take.
     *
     * Only takes whose two MCP gates both read "Klaar" are included. The check is
     * repeated here even though the file list badges it, because the client
     * controls which IDs it posts. Everything excluded is named in a manifest
     * inside the ZIP, so an unexpectedly small download is explainable.
     */
    public function downloadEaf($fileIds, array $currentUser = []) {
        $files = $this->mocapFileModel->getFilesByIds($fileIds);

        if (empty($files)) {
            header('HTTP/1.0 404 Not Found');
            echo "No files found";
            exit;
        }

        $statuses = $this->mocapFileModel->getMcpStatusForFiles(
            array_map(static fn($f) => (int)$f['id'], $files)
        );
        $locator = new EafLocator();

        $tempDir = sys_get_temp_dir() . '/eaf_' . uniqid();
        mkdir($tempDir);

        $stamp = date('Y-m-d_H-i-s');
        $zipPath = $tempDir . '/eaf_files_' . $stamp . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->deleteDirectory($tempDir);
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to create ZIP file";
            exit;
        }

        $skipped = [];
        $missing = [];
        $included = [];

        foreach ($files as $file) {
            $status = $statuses[(int)$file['id']] ?? ['pp' => null, 'ta' => null, 'klaar' => false];
            $take = preg_replace('/\.fbx$/i', '', $this->safeName($file['filename']));

            if ($take === '') {
                $missing[] = (string)$file['filename'];
                continue;
            }

            if (!$status['klaar']) {
                $skipped[] = sprintf(
                    '%s — postprocessing: %s, tijd annotatie: %s',
                    $take,
                    $this->describePostprocessing($status['pp']),
                    $status['ta'] === null || $status['ta'] === '' ? '(leeg)' : $status['ta']
                );
                continue;
            }

            $paths = $locator->filesForTake($take);
            if (empty($paths)) {
                $missing[] = $take;
                continue;
            }

            foreach ($paths as $path) {
                $zip->addFile($path, $take . '/' . basename($path));
            }
            $included[] = $file;
        }

        if (!empty($skipped)) {
            $zip->addFromString(
                'SKIPPED_NOT_KLAAR.txt',
                "These takes were left out because MCP postprocessing and/or tijd annotatie\n"
                . "is not set to Klaar in zinnen.html:\n\n"
                . implode("\n", $skipped) . "\n"
            );
        }

        if (!empty($missing)) {
            $zip->addFromString(
                'MISSING_EAF.txt',
                "No take-level .eaf was found in /web/zin/eaf/zin/ for these takes.\n"
                . "(The broadcast-level .eaf is not used as a fallback: it covers the whole\n"
                . "broadcast and is not time-aligned to an individual take.)\n\n"
                . implode("\n", $missing) . "\n"
            );
        }

        $zip->close();

        if (!empty($currentUser['username'])) {
            foreach ($included as $file) {
                $this->logDownload($currentUser['username'], (int)$file['id'], $file['filename'], 'eaf');
            }
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="eaf_files_' . $stamp . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        $this->deleteDirectory($tempDir);
        exit;
    }

    /** Render the postprocessing status value as the label zinnen.html shows for it. */
    private function describePostprocessing(?string $pp): string {
        if ($pp === '1') return 'Klaar';
        if ($pp === '2') return 'Check nodig';
        return ($pp === null || $pp === '') ? '(leeg)' : $pp;
    }
```

- [ ] **Step 5: Write the entry point**

Create `public/download-eaf.php`:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\DownloadController;

$fileIds = array_values(array_filter(
    array_map('intval', explode(',', $_GET['bulk'] ?? '')),
    static fn($id) => $id > 0
));

if (empty($fileIds)) {
    header('Location: index.php');
    exit;
}

$controller = new DownloadController();
$controller->downloadEaf($fileIds, $currentUser);
```

The `intval` filter matters beyond input hygiene: `MocapFile::getFilesByIds()` builds its placeholder string with `str_repeat('?,', count($ids) - 1)` and fatals on an empty array, so the empty case must be caught here.

- [ ] **Step 6: Verify the whole suite still passes**

Run: `./vendor/bin/phpunit`
Expected: PASS — no new failures versus the pre-task baseline. If the pre-existing suite already had failures, they must be the *same* failures.

- [ ] **Step 7: Verify the ZIP end to end from the CLI**

This exercises the real database and real annotation directory without a browser. Run:

```bash
php -r '
require "vendor/autoload.php";
$db = App\config\Database::getInstance()->getConnection();
$rows = $db->query("
    SELECT v.id FROM vicon_files v
    JOIN matched_transcriptions mt ON mt.m_file = CONCAT(SUBSTRING_INDEX(v.filename,\"_\",2), \".wav\") AND LOWER(mt.zOg)=\"zin\"
    JOIN sentences s ON s.ID = CAST(mt.m_transcription AS UNSIGNED)
    WHERE v.subdirectory=\"unreal/CC\" AND s.mcp_status_postprocessing=\"1\" AND s.mcp_status_tijd_annotatie=\"Klaar\"
    LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$all = $db->query("SELECT id FROM vicon_files WHERE subdirectory=\"unreal/CC\" AND id NOT IN (" . implode(",", $rows) . ") LIMIT 1")->fetchAll(PDO::FETCH_COLUMN);
echo "klaar ids: " . implode(",", $rows) . " | control id: " . implode(",", $all) . "\n";
' 
```

Take the printed IDs and request the ZIP through the built-in server:

```bash
php -S localhost:8765 -t public/ &
sleep 1
curl -s -o /tmp/eaf_test.zip "http://localhost:8765/download-eaf.php?bulk=<KLAAR_IDS>,<CONTROL_ID>"
unzip -l /tmp/eaf_test.zip
kill %1
```

Expected: if `requireAuth()` redirects the unauthenticated curl request, instead verify by calling the controller directly:

```bash
php -r '
require "vendor/autoload.php";
$c = new App\controllers\DownloadController();
ob_start();
try { $c->downloadEaf([<KLAAR_IDS>, <CONTROL_ID>], ["username" => "cli-test"]); } catch (Throwable $e) {}
$out = ob_get_clean();
file_put_contents("/tmp/eaf_test.zip", $out);
' ; unzip -l /tmp/eaf_test.zip
```

Expected listing: one directory per Klaar take containing its `.eaf` and `.srt` files, plus `SKIPPED_NOT_KLAAR.txt` naming the control ID's take with its actual statuses. No `*_backup_*.srt` entries. Confirm the log row landed:

```bash
mysql -u user -pCHeZeGa85W admin_gebarenoverleg -e "SELECT username, filename, download_type FROM download_logs WHERE download_type='eaf' ORDER BY id DESC LIMIT 5"
```

Expected: rows with `download_type = 'eaf'` for the included takes only.

- [ ] **Step 8: Commit**

```bash
git add migrations/006_download_logs_eaf_type.sql app/controllers/DownloadController.php public/download-eaf.php
git commit -m "feat: add EAF/SRT batch download gated on MCP Klaar status"
```

---

### Task 4: File list UI — universal checkboxes, button, and badge

**Files:**
- Modify: `app/controllers/FileListController.php:81` (add one lookup after `getGlossesForFiles`)
- Modify: `app/views/file-list.php` — button block at lines 104-114, checkbox header at 135-139, checkbox cell at 155-161, badge inside the filename cell at 162-185, JS at 477-490

**Interfaces:**
- Consumes: `MocapFile::getMcpStatusForFiles()` (Task 2); `public/download-eaf.php?bulk=…` (Task 3).
- Produces: the `$fileMcpStatus` view variable and the `downloadSelectedEaf()` JS function. Nothing later depends on these.

- [ ] **Step 1: Fetch the status map in the controller**

In `app/controllers/FileListController.php`, directly after the existing line 81:

```php
        $fileGlosses = $this->mocapFileModel->getGlossesForFiles($allFileIds);
```

add:

```php
        $fileMcpStatus = $this->mocapFileModel->getMcpStatusForFiles($allFileIds);
```

The view is pulled in with `require_once` from inside `index()`, so local variables are already in its scope — no other plumbing is needed.

- [ ] **Step 2: Make the checkbox column unconditional**

In `app/views/file-list.php`, the header cell at lines 135-139 currently reads:

```php
                                        <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">
                                            <input type="checkbox" class="date-checkbox" data-date="<?php echo $date; ?>">
                                        </th>
                                        <?php endif; ?>
```

Replace with (guard removed, so the column exists in every view):

```php
                                        <th class="pb-3">
                                            <input type="checkbox" class="date-checkbox" data-date="<?php echo $date; ?>">
                                        </th>
```

The body cell at lines 155-161 currently reads:

```php
                                            <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 0): ?>
                                                <input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file-checkbox" data-date="<?php echo $date; ?>">
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
```

Replace with (both guards removed — EAFs are wanted *after* postprocessing, so processed rows must be selectable):

```php
                                            <td class="py-3">
                                                <input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file-checkbox" data-date="<?php echo $date; ?>">
                                            </td>
```

- [ ] **Step 3: Add the EAF button**

In the action-button block at lines 104-114, after the `<?php endif; ?>` that closes the existing `Download Selected` button and before the `Upload Processed Files` link, insert:

```php
                <button onclick="downloadSelectedEaf()" class="bg-purple-600 hover:bg-purple-800 text-white font-bold py-2 px-4 rounded disabled:opacity-50" id="downloadEafBtn" disabled>
                    Download Selected (EAF)
                </button>
```

It sits outside the `$selectedStatus` guard on purpose: annotation files are most often wanted for already-processed takes.

- [ ] **Step 4: Add the per-row Klaar badge**

In the filename cell, after the `<?php endif; ?>` that closes the labels block (line 184) and before the closing `</td>` (line 185), insert:

```php
                                                <?php
                                                    $mcp = $fileMcpStatus[$file['id']] ?? ['pp' => null, 'ta' => null, 'klaar' => false];
                                                    $ppLabel = $mcp['pp'] === '1' ? 'Klaar' : ($mcp['pp'] === '2' ? 'Check nodig' : ($mcp['pp'] === null || $mcp['pp'] === '' ? 'leeg' : $mcp['pp']));
                                                    $taLabel = ($mcp['ta'] === null || $mcp['ta'] === '') ? 'leeg' : $mcp['ta'];
                                                    if ($mcp['klaar']) {
                                                        $mcpText  = 'MCP Klaar';
                                                        $mcpClass = 'bg-green-100 text-green-800';
                                                    } else {
                                                        $blocking = [];
                                                        if ($mcp['pp'] !== '1') $blocking[] = 'PP';
                                                        if ($mcp['ta'] !== 'Klaar') $blocking[] = 'TA';
                                                        $mcpText  = implode(' + ', $blocking) . ' niet klaar';
                                                        $mcpClass = 'bg-gray-100 text-gray-600';
                                                    }
                                                ?>
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium <?php echo $mcpClass; ?>"
                                                          title="MCP postprocessing: <?php echo htmlspecialchars($ppLabel); ?> — MCP tijd annotatie: <?php echo htmlspecialchars($taLabel); ?>">
                                                        <?php echo htmlspecialchars($mcpText); ?>
                                                    </span>
                                                </div>
```

Putting the badge inside the existing filename cell rather than in a new column keeps the header row untouched, so the three view modes stay in sync.

- [ ] **Step 5: Wire up the JavaScript**

Replace `updateDownloadButton()` (lines 477-480) with:

```javascript
        function updateDownloadButton() {
            const anyChecked = document.querySelectorAll('.file-checkbox:checked').length > 0;
            const btn = document.getElementById('downloadBtn');
            if (btn) btn.disabled = !anyChecked;
            const eafBtn = document.getElementById('downloadEafBtn');
            if (eafBtn) eafBtn.disabled = !anyChecked;
        }
```

and add, directly after `downloadSelected()` (which ends at line 490):

```javascript
        function downloadSelectedEaf() {
            const fileIds = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
            if (fileIds.length === 0) return;
            window.location.href = 'download-eaf.php?bulk=' + fileIds.join(',');
        }
```

Unlike `downloadSelected()`, there is no single-file special case: the EAF endpoint always returns a ZIP because a take can carry several SRT sidecars.

- [ ] **Step 6: Verify the page renders in all three views**

Run:

```bash
php -l app/views/file-list.php && php -l app/controllers/FileListController.php
```

Expected: `No syntax errors detected` for both.

Then open the live site in a browser and check each of `?status=unprocessed`, `?status=processed`, and `?status=all`:

- The checkbox column appears in all three, with a checkbox on every row (including processed rows).
- Each row shows either a green `MCP Klaar` badge or a grey `PP niet klaar` / `TA niet klaar` / `PP + TA niet klaar` badge, and hovering shows the raw statuses.
- Both buttons start disabled and both enable as soon as a row is ticked; the date-level checkbox still toggles its whole group.
- Spot-check one badge against `zinnen.html` for the same broadcast to confirm they agree.

- [ ] **Step 7: Download a real batch through the browser**

Tick two takes with a green `MCP Klaar` badge and one with a grey badge, click **Download Selected (EAF)**, and open the resulting ZIP.

Expected: a folder per green take holding its `.eaf` and `.srt` files, plus `SKIPPED_NOT_KLAAR.txt` naming only the grey take. No `*_backup_*.srt` anywhere.

Then tick a green-badged take that you know has no take-level EAF (find one with: `mysql -u user -pCHeZeGa85W admin_gebarenoverleg -N -e "SELECT REPLACE(v.filename,'.fbx','') FROM vicon_files v JOIN matched_transcriptions mt ON mt.m_file = CONCAT(SUBSTRING_INDEX(v.filename,'_',2), '.wav') AND LOWER(mt.zOg)='zin' JOIN sentences s ON s.ID = CAST(mt.m_transcription AS UNSIGNED) WHERE v.subdirectory='unreal/CC' AND s.mcp_status_postprocessing='1' AND s.mcp_status_tijd_annotatie='Klaar'" | while read f; do [ -f "/web/zin/eaf/zin/$f.eaf" ] || echo "$f"; done | head -3`) and confirm it lands in `MISSING_EAF.txt`.

- [ ] **Step 8: Commit**

```bash
git add app/controllers/FileListController.php app/views/file-list.php
git commit -m "feat: add EAF download button, universal checkboxes, MCP status badges"
```

---

## Self-Review Notes

Spec coverage check, section by section:

| Spec section | Task |
|---|---|
| `getMcpStatusForFiles` | Task 2 |
| `EafLocator` | Task 1 |
| `downloadEaf` (skip/manifest/log/stream) | Task 3 |
| `public/download-eaf.php` | Task 3 |
| `file-list.php` (checkboxes, button, badge, JS) | Task 4 |
| `FileListController::index` | Task 4 |
| Error-handling table | Task 3 Steps 4-5 (404, 500, redirect, manifests) |
| Testing section | Tasks 1-2 |

One item the spec did not anticipate: `download_logs.download_type` is an ENUM that rejects `'eaf'`, so Task 3 adds migration `006`. Everything else maps to a task.
