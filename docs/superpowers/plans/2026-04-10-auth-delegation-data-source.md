# Auth, Delegation & Data Source Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add authentication, capture date delegation, and switch data source from `mocap_files` to `vicon_files` for the animMIDI app.

**Architecture:** PHP cookie-based auth middleware injected at each public entry point. `vicon_files` table (filtered on `subdirectory = 'unreal/CC'`) replaces `mocap_files` as the data source. New `capture_assignments` table lets admins (gomer/jari) assign capture dates to users, which filters the file list server-side. A shared header partial provides logout and navigation.

**Tech Stack:** PHP 7.4+, MySQL, PDO, Tailwind CSS (CDN), cookie-based auth (existing pattern from `/web/login.html`)

**Spec:** `docs/superpowers/specs/2026-04-10-auth-delegation-data-source-design.md`

---

### Task 1: Database Migrations

**Files:**
- Create: `migrations/001_capture_assignments.sql`
- Create: `migrations/002_vicon_files_pp_columns.sql`

- [ ] **Step 1: Create the capture_assignments migration**

Create `migrations/001_capture_assignments.sql`:

```sql
CREATE TABLE IF NOT EXISTS capture_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    capture_date DATE NOT NULL,
    assigned_by VARCHAR(255) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_date (username, capture_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Create the vicon_files columns migration**

Create `migrations/002_vicon_files_pp_columns.sql`:

```sql
ALTER TABLE vicon_files
    ADD COLUMN is_pp TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN filename_pp VARCHAR(512) DEFAULT NULL,
    ADD COLUMN datetime_pp DATETIME DEFAULT NULL,
    ADD COLUMN review_status ENUM('pending', 'approved', 'rejected', 'needs_review') NOT NULL DEFAULT 'pending';
```

- [ ] **Step 3: Run both migrations**

```bash
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg < /web/animMIDI/migrations/001_capture_assignments.sql
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg < /web/animMIDI/migrations/002_vicon_files_pp_columns.sql
```

Verify:

```bash
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg -e "DESCRIBE capture_assignments;"
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg -e "SHOW COLUMNS FROM vicon_files WHERE Field IN ('is_pp','filename_pp','datetime_pp','review_status');"
```

Expected: Both commands show the new columns/table.

- [ ] **Step 4: Commit**

```bash
git add migrations/
git commit -m "Add database migrations for capture_assignments table and vicon_files pp columns"
```

---

### Task 2: Auth Middleware

**Files:**
- Create: `app/auth.php`

- [ ] **Step 1: Create the auth middleware**

Create `app/auth.php`. This file reads the `sessionObject` cookie, validates it, and exposes `$currentUser`. It provides two functions: `requireAuth()` for page endpoints (redirects to login) and `requireAuthApi()` for JSON endpoints (returns 401). Also provides `isAdmin()`.

```php
<?php
/**
 * Authentication middleware.
 *
 * Usage in page endpoints:  require_once __DIR__ . '/auth.php'; $currentUser = requireAuth();
 * Usage in API endpoints:   require_once __DIR__ . '/auth.php'; $currentUser = requireAuthApi();
 * Admin check:              if (isAdmin($currentUser['username'])) { ... }
 */

function getSessionUser(): ?array {
    if (!isset($_COOKIE['sessionObject'])) {
        return null;
    }

    $decoded = json_decode(urldecode($_COOKIE['sessionObject']), true);
    if (!$decoded || empty($decoded['userId']) || empty($decoded['username'])) {
        return null;
    }

    // Check expiry
    if (!empty($decoded['expiresAt'])) {
        $expires = strtotime($decoded['expiresAt']);
        if ($expires !== false && $expires < time()) {
            return null;
        }
    }

    return [
        'userId'   => $decoded['userId'],
        'username' => $decoded['username'],
        'role'     => $decoded['role'] ?? 'user',
    ];
}

function requireAuth(): array {
    $user = getSessionUser();
    if (!$user) {
        header('Location: /login.html');
        exit;
    }
    return $user;
}

function requireAuthApi(): array {
    $user = getSessionUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    return $user;
}

function isAdmin(string $username): bool {
    return in_array($username, ['gomer', 'jari'], true);
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/auth.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/auth.php
git commit -m "Add auth middleware with cookie validation and admin check"
```

---

### Task 3: Wire Auth into All Public Entry Points

**Files:**
- Modify: `public/index.php`
- Modify: `public/upload.php`
- Modify: `public/download.php`
- Modify: `public/review-status.php`

- [ ] **Step 1: Add auth to `public/index.php`**

The file currently contains:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\FileListController;

$controller = new FileListController();
$controller->index();
```

Replace with:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\FileListController;

$controller = new FileListController();
$controller->index($currentUser);
```

- [ ] **Step 2: Add auth to `public/upload.php`**

The file currently contains:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\UploadController;

$controller = new UploadController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processUpload();
} else {
    $controller->showUploadForm();
}
```

Replace with:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\UploadController;

$controller = new UploadController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processUpload($currentUser);
} else {
    $controller->showUploadForm($currentUser);
}
```

Note: The UploadController methods must accept `$currentUser` so the header partial can access it. See the UploadController update below in this same task.

- [ ] **Step 3: Add auth to `public/download.php`**

The file currently contains:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\controllers\DownloadController;

$controller = new DownloadController();

if (isset($_GET['id'])) {
    $type = $_GET['type'] ?? 'original';
    $controller->downloadSingle($_GET['id'], $type);
} elseif (isset($_GET['bulk'])) {
    $fileIds = explode(',', $_GET['bulk']);
    $controller->downloadBulk($fileIds);
} else {
    header('Location: index.php');
    exit;
}
```

Replace with:

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = requireAuth();

use App\controllers\DownloadController;

$controller = new DownloadController();

if (isset($_GET['id'])) {
    $type = $_GET['type'] ?? 'original';
    $controller->downloadSingle($_GET['id'], $type);
} elseif (isset($_GET['bulk'])) {
    $fileIds = explode(',', $_GET['bulk']);
    $controller->downloadBulk($fileIds);
} else {
    header('Location: index.php');
    exit;
}
```

- [ ] **Step 4: Add auth to `public/review-status.php`**

This is an API endpoint — use `requireAuthApi()` instead. The file currently starts with:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\models\MocapFile;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
```

Replace the top section (before the method check) with:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/auth.php';

$currentUser = requireAuthApi();

use App\models\MocapFile;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
```

The rest of the file stays the same.

- [ ] **Step 5: Update UploadController to accept `$currentUser`**

The UploadController's `showUploadForm()` and `processUpload()` include views that use the header partial, which needs `$currentUser` in scope. Add the parameter to both methods.

In `app/controllers/UploadController.php`, change the method signatures:

Change `public function showUploadForm()` to:
```php
    public function showUploadForm(array $currentUser = []) {
```

Change `public function processUpload()` to:
```php
    public function processUpload(array $currentUser = []) {
```

No other changes needed — `$currentUser` will automatically be in scope when the views are `require_once`'d.

- [ ] **Step 6: Verify syntax on all files**

```bash
php -l /web/animMIDI/public/index.php && php -l /web/animMIDI/public/upload.php && php -l /web/animMIDI/public/download.php && php -l /web/animMIDI/public/review-status.php && php -l /web/animMIDI/app/controllers/UploadController.php
```

Expected: All five report `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add public/index.php public/upload.php public/download.php public/review-status.php app/controllers/UploadController.php
git commit -m "Add auth middleware to all public entry points"
```

---

### Task 4: Shared Header Partial

**Files:**
- Create: `app/views/partials/header.php`

- [ ] **Step 1: Create the header partial**

Create `app/views/partials/header.php`. This file expects `$currentUser` to be set in the including scope.

```php
<?php
/** @var array $currentUser — set by the including view */
$headerUsername = htmlspecialchars($currentUser['username']);
$headerIsAdmin = isAdmin($currentUser['username']);
?>
<nav class="bg-white shadow mb-6">
    <div class="container mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <h1 class="text-lg font-bold text-gray-800">Motion Capture File Manager</h1>
            <a href="/menu.html" class="text-sm text-blue-600 hover:text-blue-800">Menu</a>
        </div>
        <div class="flex items-center gap-4">
            <?php if ($headerIsAdmin): ?>
                <a href="delegate.php" class="text-sm text-purple-600 hover:text-purple-800 font-medium">Toewijzingen</a>
            <?php endif; ?>
            <span class="text-sm text-gray-600">Ingelogd als <strong><?php echo $headerUsername; ?></strong></span>
            <button onclick="logout()" class="text-sm bg-red-500 hover:bg-red-700 text-white py-1 px-3 rounded">
                Uitloggen
            </button>
        </div>
    </div>
</nav>
<script>
function logout() {
    document.cookie = 'sessionObject=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    window.location.href = '/login.html';
}
</script>
```

- [ ] **Step 2: Include header in `app/views/file-list.php`**

In `file-list.php`, after the opening `<body>` tag (line 9: `<body class="bg-gray-100">`), add:

```php
    <?php require_once __DIR__ . '/partials/header.php'; ?>
```

And remove the existing `<header>` block inside the container (lines 11-20):

```php
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Motion Capture File Manager</h1>
            <div class="flex gap-4">
                <div class="bg-blue-100 px-4 py-2 rounded">
                    <span class="text-blue-800 font-semibold">Unprocessed: <?php echo $unprocessedCount; ?></span>
                </div>
                <div class="bg-green-100 px-4 py-2 rounded">
                    <span class="text-green-800 font-semibold">Processed: <?php echo $processedCount; ?></span>
                </div>
            </div>
        </header>
```

Replace it with just the stats bar (no duplicated title):

```php
        <div class="mb-6 flex gap-4">
            <div class="bg-blue-100 px-4 py-2 rounded">
                <span class="text-blue-800 font-semibold">Unprocessed: <?php echo $unprocessedCount; ?></span>
            </div>
            <div class="bg-green-100 px-4 py-2 rounded">
                <span class="text-green-800 font-semibold">Processed: <?php echo $processedCount; ?></span>
            </div>
        </div>
```

- [ ] **Step 3: Include header in `app/views/upload-form.php`**

In `upload-form.php`, after the opening `<body>` tag (line 9), add:

```php
    <?php require_once __DIR__ . '/partials/header.php'; ?>
```

And replace the existing header block (lines 11-14):

```php
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Upload Processed Files</h1>
            <a href="index.php" class="text-blue-600 hover:text-blue-800">← Back to File List</a>
        </header>
```

With:

```php
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Upload Processed Files</h2>
            <a href="index.php" class="text-blue-600 hover:text-blue-800">&larr; Back to File List</a>
        </div>
```

- [ ] **Step 4: Include header in `app/views/upload-result.php`**

In `upload-result.php`, after the opening `<body>` tag (line 9), add:

```php
    <?php require_once __DIR__ . '/partials/header.php'; ?>
```

No other header changes needed — this view doesn't have its own header.

- [ ] **Step 5: Verify syntax**

```bash
php -l /web/animMIDI/app/views/partials/header.php && php -l /web/animMIDI/app/views/file-list.php && php -l /web/animMIDI/app/views/upload-form.php && php -l /web/animMIDI/app/views/upload-result.php
```

Expected: All report `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add app/views/partials/header.php app/views/file-list.php app/views/upload-form.php app/views/upload-result.php
git commit -m "Add shared header with logout button and admin nav to all views"
```

---

### Task 5: Rewrite MocapFile Model for `vicon_files`

**Files:**
- Modify: `app/models/MocapFile.php` (full rewrite)

This is the largest task. The model switches from `mocap_files` to `vicon_files WHERE subdirectory = 'unreal/CC'`. The capture date is extracted from `capture_id` using `SUBSTRING_INDEX(capture_id, '/', 1)`. The base glos for deduplication uses `SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)` (strips the take number `_N` suffix). All methods accept an optional `$allowedDates` array for filtering by assigned capture dates.

- [ ] **Step 1: Rewrite `app/models/MocapFile.php`**

Replace the entire contents of `app/models/MocapFile.php` with:

```php
<?php
namespace App\models;

use App\config\Database;
use PDO;

class MocapFile {
    private $db;
    private $baseFilter = "subdirectory = 'unreal/CC'";

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Build a date-filter clause from allowed dates.
     * Returns empty string if $allowedDates is null (admin — no filter).
     */
    private function buildDateFilter(?array $allowedDates, string $alias = ''): string {
        if ($allowedDates === null) {
            return '';
        }
        $prefix = $alias ? "$alias." : '';
        if (empty($allowedDates)) {
            return " AND 1=0"; // no assignments → no results
        }
        $placeholders = implode(',', array_fill(0, count($allowedDates), '?'));
        return " AND SUBSTRING_INDEX({$prefix}capture_id, '/', 1) IN ($placeholders)";
    }

    private function bindDateParams(\PDOStatement $stmt, ?array $allowedDates, int &$paramIndex): void {
        if ($allowedDates === null || empty($allowedDates)) {
            return;
        }
        foreach ($allowedDates as $date) {
            $stmt->bindValue($paramIndex++, $date, PDO::PARAM_STR);
        }
    }

    public function getFileById($id) {
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE id = ? AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getFilesByIds($ids) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE id IN ($placeholders) AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function findByFilename($filename) {
        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE filename = ? AND {$this->baseFilter}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$filename]);
        return $stmt->fetch();
    }

    public function markAsProcessed($id, $processedFilename) {
        $sql = "UPDATE vicon_files SET is_pp = 1, filename_pp = ?, datetime_pp = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$processedFilename, $id]);
    }

    public function updateReviewStatus($id, $status) {
        $validStatuses = ['pending', 'approved', 'rejected', 'needs_review'];
        if (!in_array($status, $validStatuses)) {
            return false;
        }
        $sql = "UPDATE vicon_files SET review_status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    // ─── Available dates ───

    public function getAvailableDates($status = 'unprocessed', ?array $allowedDates = null) {
        $sql = "SELECT DISTINCT SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files WHERE {$this->baseFilter}";

        $params = [];
        if ($status === 'processed') {
            $sql .= " AND is_pp = 1";
        } elseif ($status === 'unprocessed') {
            $sql .= " AND is_pp = 0";
        }

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($placeholders)";
            $params = $allowedDates;
        }

        $sql .= " ORDER BY capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─── Unprocessed files ───

    public function getUnprocessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        // Deduplicate: latest take per base glos (strip _N suffix)
        // Base glos: M20260204_3146_260217_1.fbx → M20260204_3146_260217
        // Exclude base glosses that already have a processed version
        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 0
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter} AND f1.is_pp = 0
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [];
        $paramIdx = 1;

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(f1.capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);

        // Bind params with correct types
        foreach ($params as $i => $val) {
            if (is_int($val)) {
                $stmt->bindValue($i + 1, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($i + 1, $val, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getUnprocessedFilesCount($searchTerm = '', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 0
                AND (
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = $allowedDates;
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Unprocessed by specific date ───

    public function getUnprocessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 0
                    AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter} AND f1.is_pp = 0
                AND SUBSTRING_INDEX(f1.capture_id, '/', 1) = ?
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [$date, $date];

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, is_int($val) ? $val : $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getUnprocessedFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 0
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                AND (
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) NOT IN (
                    SELECT DISTINCT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END
                    FROM vicon_files
                    WHERE {$this->baseFilter} AND is_pp = 1
                )";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Processed files ───

    public function getProcessedFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $sql .= " ORDER BY last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getProcessedFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT *, SUBSTRING_INDEX(capture_id, '/', 1) AS capture_date
                FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getProcessedFilesCount($searchTerm = '', $reviewStatus = 'all', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(*) AS total FROM vicon_files WHERE {$this->baseFilter} AND is_pp = 1";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        if ($reviewStatus !== 'all') {
            $sql .= " AND review_status = ?";
            $params[] = $reviewStatus;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getProcessedFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(*) AS total FROM vicon_files
                WHERE {$this->baseFilter} AND is_pp = 1
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── All files ───

    public function getAllFilesGroupedByDate($limit = null, $page = 1, $searchTerm = '', ?array $allowedDates = null) {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter}
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter}";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(f1.capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getAllFilesByDate($date, $limit = null, $page = 1, $searchTerm = '') {
        $offset = $limit ? ($page - 1) * $limit : 0;

        $sql = "SELECT f1.*, SUBSTRING_INDEX(f1.capture_id, '/', 1) AS capture_date
                FROM vicon_files f1
                INNER JOIN (
                    SELECT
                        CASE
                            WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                            ELSE REPLACE(filename, '.fbx', '')
                        END AS base_glos,
                        MAX(
                            CASE
                                WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                                    LPAD(CAST(SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                                ELSE '0000000000'
                            END
                        ) AS max_take
                    FROM vicon_files
                    WHERE {$this->baseFilter}
                    AND SUBSTRING_INDEX(capture_id, '/', 1) = ?
                    GROUP BY base_glos
                ) f2 ON (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(f1.filename, '.fbx', '')
                    END
                ) = f2.base_glos
                AND (
                    CASE
                        WHEN f1.filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN
                            LPAD(CAST(SUBSTRING_INDEX(REPLACE(f1.filename, '.fbx', ''), '_', -1) AS UNSIGNED), 10, '0')
                        ELSE '0000000000'
                    END
                ) = f2.max_take
                WHERE f1.{$this->baseFilter}
                AND SUBSTRING_INDEX(f1.capture_id, '/', 1) = ?";

        $params = [$date, $date];

        if (!empty($searchTerm)) {
            $sql .= " AND f1.filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY f1.last_modified DESC";

        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $this->groupByDate($stmt->fetchAll(), 'capture_date');
    }

    public function getAllFilesCount($searchTerm = '', ?array $allowedDates = null) {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total FROM vicon_files WHERE {$this->baseFilter}";

        $params = [];

        if ($allowedDates !== null) {
            if (empty($allowedDates)) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($allowedDates), '?'));
            $sql .= " AND SUBSTRING_INDEX(capture_id, '/', 1) IN ($ph)";
            $params = array_merge($params, $allowedDates);
        }

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    public function getAllFilesCountByDate($date, $searchTerm = '') {
        $sql = "SELECT COUNT(DISTINCT
                    CASE
                        WHEN filename REGEXP '_[0-9]{6}_[0-9]+\\.fbx$' THEN SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 3)
                        ELSE REPLACE(filename, '.fbx', '')
                    END
                ) AS total FROM vicon_files
                WHERE {$this->baseFilter}
                AND SUBSTRING_INDEX(capture_id, '/', 1) = ?";

        $params = [$date];

        if (!empty($searchTerm)) {
            $sql .= " AND filename LIKE ?";
            $params[] = '%' . $searchTerm . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch()['total'];
    }

    // ─── Helpers ───

    private function groupByDate(array $results, string $dateField): array {
        $grouped = [];
        foreach ($results as $row) {
            $date = $row[$dateField];
            $grouped[$date][] = $row;
        }
        return $grouped;
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/models/MocapFile.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/models/MocapFile.php
git commit -m "Rewrite MocapFile model to use vicon_files table instead of mocap_files"
```

---

### Task 6: Assignment Model

**Files:**
- Create: `app/models/Assignment.php`

- [ ] **Step 1: Create the Assignment model**

Create `app/models/Assignment.php`:

```php
<?php
namespace App\models;

use App\config\Database;
use PDO;

class Assignment {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all assigned dates for a specific user.
     * Returns array of date strings (e.g., ['2026-02-17', '2026-03-18']).
     */
    public function getDatesForUser(string $username): array {
        $sql = "SELECT capture_date FROM capture_assignments
                WHERE username = ? ORDER BY capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get all assignments grouped by username.
     * Returns: ['jose' => [['id'=>1, 'capture_date'=>'2026-02-17', ...], ...], ...]
     */
    public function getAllAssignments(): array {
        $sql = "SELECT ca.*, 
                    (SELECT COUNT(*) FROM vicon_files 
                     WHERE subdirectory = 'unreal/CC' 
                     AND SUBSTRING_INDEX(capture_id, '/', 1) = ca.capture_date) AS file_count
                FROM capture_assignments ca
                ORDER BY ca.username ASC, ca.capture_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['username']][] = $row;
        }
        return $grouped;
    }

    /**
     * Assign multiple capture dates to a user.
     * Silently skips duplicates (uses INSERT IGNORE).
     */
    public function assign(string $username, array $dates, string $assignedBy): int {
        if (empty($dates)) {
            return 0;
        }

        $sql = "INSERT IGNORE INTO capture_assignments (username, capture_date, assigned_by)
                VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        $inserted = 0;
        foreach ($dates as $date) {
            $stmt->execute([$username, $date, $assignedBy]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * Remove a single assignment by ID.
     */
    public function unassign(int $id): bool {
        $sql = "DELETE FROM capture_assignments WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    /**
     * Get all active (non-blocked) users from the users table.
     */
    public function getActiveUsers(): array {
        $sql = "SELECT userId, user FROM users WHERE blocked = 0 ORDER BY user ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/models/Assignment.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/models/Assignment.php
git commit -m "Add Assignment model for capture date delegation"
```

---

### Task 7: Update FileListController with Auth & Date Filtering

**Files:**
- Modify: `app/controllers/FileListController.php`

- [ ] **Step 1: Rewrite FileListController to accept `$currentUser` and filter by assignments**

Replace the entire contents of `app/controllers/FileListController.php`:

```php
<?php
namespace App\controllers;

use App\models\MocapFile;
use App\models\Assignment;

class FileListController {
    private $mocapFileModel;
    private $assignmentModel;

    public function __construct() {
        $this->mocapFileModel = new MocapFile();
        $this->assignmentModel = new Assignment();
    }

    public function index(array $currentUser) {
        $username = $currentUser['username'];

        // Determine allowed dates: null for admins (no filter), array for regular users
        if (isAdmin($username)) {
            $allowedDates = null;
        } else {
            $allowedDates = $this->assignmentModel->getDatesForUser($username);
        }

        // Get filter parameters
        $selectedDate = $_GET['date'] ?? 'all';
        $selectedStatus = $_GET['status'] ?? 'unprocessed';
        $limit = (int)($_GET['limit'] ?? 50);
        $page = (int)($_GET['page'] ?? 1);
        $searchTerm = $_GET['search'] ?? '';
        $reviewStatus = $_GET['review'] ?? 'all';

        // Get available dates for the dropdown
        $availableDates = $this->mocapFileModel->getAvailableDates($selectedStatus, $allowedDates);

        // Get filtered files
        if ($selectedDate === 'all') {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesGroupedByDate($limit, $page, $searchTerm, $reviewStatus, $allowedDates);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCount($searchTerm, $reviewStatus, $allowedDates);
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesGroupedByDate($limit, $page, $searchTerm, $allowedDates);
                $totalFiles = $this->mocapFileModel->getAllFilesCount($searchTerm, $allowedDates);
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesGroupedByDate($limit, $page, $searchTerm, $allowedDates);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCount($searchTerm, $allowedDates);
            }
        } else {
            if ($selectedStatus === 'processed') {
                $filesGroupedByDate = $this->mocapFileModel->getProcessedFilesByDate($selectedDate, $limit, $page, $searchTerm);
                $totalFiles = $this->mocapFileModel->getProcessedFilesCountByDate($selectedDate, $searchTerm);
            } elseif ($selectedStatus === 'all') {
                $filesGroupedByDate = $this->mocapFileModel->getAllFilesByDate($selectedDate, $limit, $page, $searchTerm);
                $totalFiles = $this->mocapFileModel->getAllFilesCountByDate($selectedDate, $searchTerm);
            } else {
                $filesGroupedByDate = $this->mocapFileModel->getUnprocessedFilesByDate($selectedDate, $limit, $page, $searchTerm);
                $totalFiles = $this->mocapFileModel->getUnprocessedFilesCountByDate($selectedDate, $searchTerm);
            }
        }

        $processedCount = $this->mocapFileModel->getProcessedFilesCount('', 'all', $allowedDates);
        $unprocessedCount = $this->mocapFileModel->getUnprocessedFilesCount('', $allowedDates);

        // Calculate pagination
        $totalPages = $totalFiles > 0 ? ceil($totalFiles / $limit) : 0;

        // Pass to view
        $noAssignments = ($allowedDates !== null && empty($allowedDates));

        require_once dirname(__DIR__) . '/views/file-list.php';
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/controllers/FileListController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/controllers/FileListController.php
git commit -m "Update FileListController with auth and capture date filtering"
```

---

### Task 8: Update file-list.php View

**Files:**
- Modify: `app/views/file-list.php`

The view needs these changes:
1. Header partial already added in Task 4
2. Show "no assignments" message when `$noAssignments` is true
3. Adapt column references from `mocap_files` fields (`glos`, `datetime`) to `vicon_files` fields (`filename`, `capture_date`, `last_modified`)

- [ ] **Step 1: Update the view to use vicon_files fields and show no-assignments message**

Replace the full contents of `app/views/file-list.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motion Capture File Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>

    <div class="container mx-auto px-4 py-4">
        <div class="mb-6 flex gap-4">
            <div class="bg-blue-100 px-4 py-2 rounded">
                <span class="text-blue-800 font-semibold">Unprocessed: <?php echo $unprocessedCount; ?></span>
            </div>
            <div class="bg-green-100 px-4 py-2 rounded">
                <span class="text-green-800 font-semibold">Processed: <?php echo $processedCount; ?></span>
            </div>
        </div>

        <?php if (!empty($noAssignments)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-8 text-center">
                <p class="text-yellow-800 text-lg font-medium">Geen captures aan u toegewezen.</p>
                <p class="text-yellow-700 mt-2">Neem contact op met een beheerder.</p>
            </div>
        <?php else: ?>

        <div class="mb-6 space-y-4">
            <!-- Filter Controls -->
            <div class="bg-white rounded-lg shadow p-4">
                <form method="GET" action="index.php" class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                               placeholder="Search by filename..."
                               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Processing Status</label>
                        <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="unprocessed" <?php echo $selectedStatus === 'unprocessed' ? 'selected' : ''; ?>>Unprocessed Only</option>
                            <option value="processed" <?php echo $selectedStatus === 'processed' ? 'selected' : ''; ?>>Processed Only</option>
                            <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All Files</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Filter by Date</label>
                        <select name="date" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="all" <?php echo $selectedDate === 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <?php foreach ($availableDates as $date): ?>
                                <option value="<?php echo htmlspecialchars($date); ?>" <?php echo $selectedDate === $date ? 'selected' : ''; ?>>
                                    <?php echo date('F j, Y', strtotime($date)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Files per page</label>
                        <select name="limit" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200</option>
                        </select>
                    </div>
                    <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                    <?php $selectedReview = $_GET['review'] ?? 'all'; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Review Status</label>
                        <select name="review" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="all" <?php echo $selectedReview === 'all' ? 'selected' : ''; ?>>All Reviews</option>
                            <option value="pending" <?php echo $selectedReview === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $selectedReview === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $selectedReview === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="needs_review" <?php echo $selectedReview === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                        Apply Filter
                    </button>
                    <?php if (!empty($_GET['search'])): ?>
                    <a href="index.php" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm">
                        Clear Search
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4">
                <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                <button onclick="downloadSelected()" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" id="downloadBtn" disabled>
                    Download Selected
                </button>
                <?php endif; ?>
                <a href="upload.php" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded inline-block">
                    Upload Processed Files
                </a>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow">
            <?php if (empty($filesGroupedByDate)): ?>
                <div class="p-8 text-center text-gray-500">
                    No <?php echo $selectedStatus; ?> files found.
                </div>
            <?php else: ?>
                <?php foreach ($filesGroupedByDate as $date => $files): ?>
                    <div class="border-b last:border-b-0">
                        <div class="bg-gray-50 px-6 py-3">
                            <h3 class="font-semibold text-gray-700">
                                <?php echo date('F j, Y', strtotime($date)); ?>
                                <span class="text-sm font-normal text-gray-500">(<?php echo count($files); ?> files)</span>
                            </h3>
                        </div>
                        <div class="p-6">
                            <table class="w-full">
                                <thead>
                                    <tr class="text-left text-gray-600 text-sm">
                                        <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">
                                            <input type="checkbox" class="date-checkbox" data-date="<?php echo $date; ?>">
                                        </th>
                                        <?php endif; ?>
                                        <th class="pb-3">Filename</th>
                                        <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">Processed Filename</th>
                                        <th class="pb-3">Processed Date</th>
                                        <?php endif; ?>
                                        <th class="pb-3">Status</th>
                                        <th class="pb-3">Review</th>
                                        <th class="pb-3">Last Modified</th>
                                        <th class="pb-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($files as $file): ?>
                                        <tr class="border-t">
                                            <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 0): ?>
                                                <input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file-checkbox" data-date="<?php echo $date; ?>">
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="py-3 font-mono text-sm"><?php echo htmlspecialchars($file['filename']); ?></td>
                                            <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3 font-mono text-sm text-green-700"><?php echo htmlspecialchars($file['filename_pp'] ?? 'N/A'); ?></td>
                                            <td class="py-3 text-sm text-gray-600">
                                                <?php echo $file['datetime_pp'] ? date('M j, Y H:i', strtotime($file['datetime_pp'])) : 'N/A'; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Processed</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Unprocessed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <?php $reviewStatus = $file['review_status'] ?? 'pending'; ?>
                                                    <div class="flex gap-1" data-file-id="<?php echo $file['id']; ?>">
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'approved')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'approved' ? 'bg-green-500 text-white' : 'bg-gray-200 hover:bg-green-200'; ?>"
                                                                data-status="approved" title="Approved">&#10003;</button>
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'rejected')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'rejected' ? 'bg-red-500 text-white' : 'bg-gray-200 hover:bg-red-200'; ?>"
                                                                data-status="rejected" title="Rejected">&#10007;</button>
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'needs_review')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'needs_review' ? 'bg-yellow-500 text-white' : 'bg-gray-200 hover:bg-yellow-200'; ?>"
                                                                data-status="needs_review" title="Needs Review">?</button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-sm">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 text-gray-600 text-sm"><?php echo date('M j, Y H:i', strtotime($file['last_modified'])); ?></td>
                                            <td class="py-3">
                                                <?php
                                                    $baseGlos = preg_replace('/\\.fbx$/i', '', $file['filename']);
                                                ?>
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <a href="https://avatar.signcollect.nl/blendAnims/compare.html?file=<?php echo urlencode($file['filename']); ?>"
                                                       target="_blank" class="text-purple-600 hover:text-purple-800 mr-2">Compare</a>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=processed" class="text-green-600 hover:text-green-800">Download Processed</a>
                                                    <br>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=original" class="text-blue-600 hover:text-blue-800 text-sm">Download Original</a>
                                                <?php else: ?>
                                                    <a href="https://avatar.signcollect.nl/blendAnims/compare.html?file=<?php echo urlencode($baseGlos); ?>"
                                                       target="_blank" class="text-purple-600 hover:text-purple-800 mr-2">Compare</a>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="text-blue-600 hover:text-blue-800">Download Original</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex justify-center">
                <nav class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>"
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Previous</a>
                    <?php endif; ?>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-3 py-2 bg-blue-500 text-white rounded-md text-sm font-medium"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>"
                               class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>"
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Next</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>

        <div class="mt-4 text-center text-gray-600 text-sm">
            <?php if ($selectedDate === 'all'): ?>
                Showing page <?php echo $page; ?> of <?php echo $totalPages; ?>
                (<?php echo $totalFiles; ?> total <?php echo $selectedStatus; ?> files, <?php echo $limit; ?> per page)
            <?php else: ?>
                Showing <?php echo $totalFiles; ?> <?php echo $selectedStatus; ?> files for <?php echo date('F j, Y', strtotime($selectedDate)); ?>
            <?php endif; ?>
        </div>

        <?php endif; /* end noAssignments check */ ?>
    </div>

    <script>
        function setReviewStatus(fileId, status) {
            const container = document.querySelector(`[data-file-id="${fileId}"]`);
            const buttons = container.querySelectorAll('.review-btn');
            buttons.forEach(btn => btn.disabled = true);

            fetch('review-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${fileId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    buttons.forEach(btn => {
                        const btnStatus = btn.dataset.status;
                        btn.className = 'review-btn w-8 h-8 rounded text-lg ';
                        if (btnStatus === status) {
                            if (status === 'approved') btn.className += 'bg-green-500 text-white';
                            else if (status === 'rejected') btn.className += 'bg-red-500 text-white';
                            else if (status === 'needs_review') btn.className += 'bg-yellow-500 text-white';
                        } else {
                            if (btnStatus === 'approved') btn.className += 'bg-gray-200 hover:bg-green-200';
                            else if (btnStatus === 'rejected') btn.className += 'bg-gray-200 hover:bg-red-200';
                            else if (btnStatus === 'needs_review') btn.className += 'bg-gray-200 hover:bg-yellow-200';
                        }
                    });
                } else {
                    alert('Failed to update review status');
                }
            })
            .catch(() => alert('Error updating review status'))
            .finally(() => buttons.forEach(btn => btn.disabled = false));
        }

        document.querySelector('select[name="status"]')?.addEventListener('change', function() { this.form.submit(); });
        document.querySelector('select[name="date"]')?.addEventListener('change', function() { this.form.submit(); });
        document.querySelector('select[name="limit"]')?.addEventListener('change', function() { this.form.submit(); });

        document.querySelectorAll('.date-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const date = this.dataset.date;
                document.querySelectorAll(`.file-checkbox[data-date="${date}"]`).forEach(cb => cb.checked = this.checked);
                updateDownloadButton();
            });
        });

        document.querySelectorAll('.file-checkbox').forEach(cb => cb.addEventListener('change', updateDownloadButton));

        function updateDownloadButton() {
            const btn = document.getElementById('downloadBtn');
            if (btn) btn.disabled = document.querySelectorAll('.file-checkbox:checked').length === 0;
        }

        function downloadSelected() {
            const fileIds = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
            if (fileIds.length === 0) return;
            if (fileIds.length === 1) {
                window.location.href = 'download.php?id=' + fileIds[0];
            } else {
                window.location.href = 'download.php?bulk=' + fileIds.join(',');
            }
        }
    </script>
</body>
</html>
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/views/file-list.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/views/file-list.php
git commit -m "Update file-list view for vicon_files data source and assignment filtering"
```

---

### Task 9: DelegateController & View

**Files:**
- Create: `app/controllers/DelegateController.php`
- Create: `app/views/delegate.php`
- Create: `public/delegate.php`

- [ ] **Step 1: Create `app/controllers/DelegateController.php`**

```php
<?php
namespace App\controllers;

use App\models\Assignment;
use App\models\MocapFile;

class DelegateController {
    private $assignmentModel;
    private $mocapFileModel;

    public function __construct() {
        $this->assignmentModel = new Assignment();
        $this->mocapFileModel = new MocapFile();
    }

    public function index(array $currentUser) {
        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($currentUser);
            return;
        }

        $users = $this->assignmentModel->getActiveUsers();
        $availableDates = $this->mocapFileModel->getAvailableDates('unprocessed');
        $assignments = $this->assignmentModel->getAllAssignments();

        require_once dirname(__DIR__) . '/views/delegate.php';
    }

    private function handlePost(array $currentUser) {
        header('Content-Type: application/json');

        $action = $_POST['action'] ?? '';

        if ($action === 'assign') {
            $username = $_POST['username'] ?? '';
            $dates = $_POST['dates'] ?? [];

            if (empty($username) || empty($dates)) {
                echo json_encode(['success' => false, 'message' => 'Gebruiker en datums zijn verplicht']);
                return;
            }

            if (!is_array($dates)) {
                $dates = [$dates];
            }

            $inserted = $this->assignmentModel->assign($username, $dates, $currentUser['username']);
            echo json_encode(['success' => true, 'message' => "$inserted datum(s) toegewezen", 'inserted' => $inserted]);

        } elseif ($action === 'unassign') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'message' => 'Ongeldig ID']);
                return;
            }
            $this->assignmentModel->unassign($id);
            echo json_encode(['success' => true, 'message' => 'Toewijzing verwijderd']);

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ongeldige actie']);
        }
    }
}
```

- [ ] **Step 2: Create `app/views/delegate.php`**

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capture Toewijzingen - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>

    <div class="container mx-auto px-4 py-4">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Capture Toewijzingen</h2>
            <p class="text-gray-600 mt-1">Wijs capture datums toe aan gebruikers</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Assignment Form -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Nieuwe toewijzing</h3>
                <form id="assignForm">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Gebruiker</label>
                        <select name="username" id="userSelect" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" required>
                            <option value="">-- Kies gebruiker --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['user']); ?>">
                                    <?php echo htmlspecialchars($user['user']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Capture datums</label>
                        <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-md p-3 space-y-1">
                            <?php if (empty($availableDates)): ?>
                                <p class="text-gray-500 text-sm">Geen datums beschikbaar</p>
                            <?php else: ?>
                                <label class="flex items-center text-sm mb-2 pb-2 border-b">
                                    <input type="checkbox" id="selectAllDates" class="mr-2">
                                    <span class="font-medium">Alles selecteren</span>
                                </label>
                                <?php foreach ($availableDates as $d): ?>
                                    <label class="flex items-center text-sm">
                                        <input type="checkbox" name="dates[]" value="<?php echo htmlspecialchars($d); ?>" class="date-cb mr-2">
                                        <?php echo date('F j, Y', strtotime($d)); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded">
                        Toewijzen
                    </button>
                </form>
                <div id="assignMsg" class="mt-3 hidden text-sm rounded p-2"></div>
            </div>

            <!-- Current Assignments -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Huidige toewijzingen</h3>
                <div id="assignmentsList">
                    <?php if (empty($assignments)): ?>
                        <p class="text-gray-500">Nog geen toewijzingen</p>
                    <?php else: ?>
                        <?php foreach ($assignments as $username => $userAssignments): ?>
                            <div class="mb-4 border-b pb-4 last:border-b-0" data-user="<?php echo htmlspecialchars($username); ?>">
                                <h4 class="font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($username); ?></h4>
                                <div class="space-y-1">
                                    <?php foreach ($userAssignments as $a): ?>
                                        <div class="flex items-center justify-between text-sm bg-gray-50 rounded px-3 py-2" data-assignment-id="<?php echo $a['id']; ?>">
                                            <span>
                                                <?php echo date('F j, Y', strtotime($a['capture_date'])); ?>
                                                <span class="text-gray-400 ml-2">(<?php echo $a['file_count']; ?> files)</span>
                                            </span>
                                            <button onclick="unassign(<?php echo $a['id']; ?>)" class="text-red-500 hover:text-red-700 font-bold" title="Verwijderen">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('selectAllDates')?.addEventListener('change', function() {
            document.querySelectorAll('.date-cb').forEach(cb => cb.checked = this.checked);
        });

        document.getElementById('assignForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'assign');

            fetch('delegate.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    const msg = document.getElementById('assignMsg');
                    msg.textContent = data.message;
                    msg.className = 'mt-3 text-sm rounded p-2 ' + (data.success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                    msg.classList.remove('hidden');
                    if (data.success) {
                        setTimeout(() => location.reload(), 1000);
                    }
                })
                .catch(() => alert('Fout bij toewijzen'));
        });

        function unassign(id) {
            if (!confirm('Weet u zeker dat u deze toewijzing wilt verwijderen?')) return;

            const formData = new FormData();
            formData.append('action', 'unassign');
            formData.append('id', id);

            fetch('delegate.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const el = document.querySelector(`[data-assignment-id="${id}"]`);
                        if (el) el.remove();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(() => alert('Fout bij verwijderen'));
        }
    </script>
</body>
</html>
```

- [ ] **Step 3: Create `public/delegate.php` entry point**

```php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/auth.php';

$currentUser = ($_SERVER['REQUEST_METHOD'] === 'POST') ? requireAuthApi() : requireAuth();

if (!isAdmin($currentUser['username'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Geen toegang']);
        exit;
    }
    http_response_code(403);
    echo '<h1>403 - Geen toegang</h1><p>U heeft geen rechten om deze pagina te bekijken.</p>';
    exit;
}

use App\controllers\DelegateController;

$controller = new DelegateController();
$controller->index($currentUser);
```

- [ ] **Step 4: Verify syntax on all three files**

```bash
php -l /web/animMIDI/app/controllers/DelegateController.php && php -l /web/animMIDI/app/views/delegate.php && php -l /web/animMIDI/public/delegate.php
```

Expected: All report `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add app/controllers/DelegateController.php app/views/delegate.php public/delegate.php
git commit -m "Add delegation page for admins to assign capture dates to users"
```

---

### Task 10: Update DownloadController for `vicon_files`

**Files:**
- Modify: `app/controllers/DownloadController.php`

The `DownloadController` currently downloads originals from `https://signcollect.nl/gebarenoverleg_media/fbx/` and processed files from `/web/gebarenoverleg_media/fbx/post_processed/`. With `vicon_files`, original FBX files have a `glb_path` like `/web/gebarenoverleg_media/fbx/CC/file.glb`, and the FBX is on the capture machine path. The original FBX download URL needs to use the filename from `vicon_files`.

- [ ] **Step 1: Update DownloadController to use vicon_files fields**

Replace the entire contents of `app/controllers/DownloadController.php`:

```php
<?php
namespace App\controllers;

use App\models\MocapFile;
use ZipArchive;

class DownloadController {
    private $mocapFileModel;
    private $baseUrl = 'https://signcollect.nl/gebarenoverleg_media/fbx/';
    private $processedPath = '/web/gebarenoverleg_media/fbx/post_processed/';

    public function __construct() {
        $this->mocapFileModel = new MocapFile();
    }

    public function downloadSingle($id, $type = 'original') {
        $file = $this->mocapFileModel->getFileById($id);

        if (!$file) {
            header('HTTP/1.0 404 Not Found');
            echo "File not found";
            exit;
        }

        if ($type === 'processed') {
            if (!$file['filename_pp'] || $file['is_pp'] != 1) {
                header('HTTP/1.0 404 Not Found');
                echo "Processed file not available";
                exit;
            }

            $localPath = $this->processedPath . $file['filename_pp'];
            if (!file_exists($localPath)) {
                header('HTTP/1.0 404 Not Found');
                echo "Processed file not found on server";
                exit;
            }
            $downloadFilename = $file['filename_pp'];
        } else {
            // Download original FBX — try glb_path directory first (same dir usually has FBX)
            $fbxLocalPath = str_replace('.glb', '.fbx', $file['glb_path'] ?? '');
            if (!empty($fbxLocalPath) && file_exists($fbxLocalPath)) {
                $localPath = $fbxLocalPath;
            } else {
                // Fallback: download from URL
                $fileUrl = $this->baseUrl . $file['filename'];
                $localPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
                if (!$localPath) {
                    header('HTTP/1.0 500 Internal Server Error');
                    echo "Failed to download file";
                    exit;
                }
            }
            $downloadFilename = $file['filename'];
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Content-Length: ' . filesize($localPath));
        readfile($localPath);

        // Only delete temp files (not local server files)
        if ($type === 'original' && !str_starts_with($localPath, '/web/')) {
            unlink($localPath);
        }
        exit;
    }

    public function downloadBulk($fileIds) {
        $files = $this->mocapFileModel->getFilesByIds($fileIds);

        if (empty($files)) {
            header('HTTP/1.0 404 Not Found');
            echo "No files found";
            exit;
        }

        $tempDir = sys_get_temp_dir() . '/mocap_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/original');
        mkdir($tempDir . '/post_processed');

        $zipPath = $tempDir . '/mocap_files_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            header('HTTP/1.0 500 Internal Server Error');
            echo "Failed to create ZIP file";
            exit;
        }

        foreach ($files as $file) {
            // Try local FBX first
            $fbxLocalPath = str_replace('.glb', '.fbx', $file['glb_path'] ?? '');
            if (!empty($fbxLocalPath) && file_exists($fbxLocalPath)) {
                $zip->addFile($fbxLocalPath, 'original/' . $file['filename']);
            } else {
                $fileUrl = $this->baseUrl . $file['filename'];
                $localPath = $this->downloadFileToTemp($fileUrl, $file['filename']);
                if ($localPath) {
                    $zip->addFile($localPath, 'original/' . $file['filename']);
                }
            }
            $zip->addEmptyDir('post_processed');
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mocap_files_' . date('Y-m-d_H-i-s') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);

        $this->deleteDirectory($tempDir);
        exit;
    }

    private function downloadFileToTemp($url, $filename) {
        $tempFile = sys_get_temp_dir() . '/' . uniqid() . '_' . $filename;

        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            unlink($tempFile);
            return false;
        }
        return $tempFile;
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $obj) {
            if ($obj === '.' || $obj === '..') continue;
            $path = "$dir/$obj";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l /web/animMIDI/app/controllers/DownloadController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/controllers/DownloadController.php
git commit -m "Update DownloadController to use vicon_files with local FBX fallback"
```

---

### Task 11: Smoke Test

- [ ] **Step 1: Verify all PHP files have no syntax errors**

```bash
find /web/animMIDI/app /web/animMIDI/public -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

Expected: No output (all files pass)

- [ ] **Step 2: Test database connectivity and new tables**

```bash
mysql -u user -p$DB_PASSWORD admin_gebarenoverleg -e "
SELECT 'vicon_files new columns' AS test, COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_NAME='vicon_files' AND COLUMN_NAME IN ('is_pp','filename_pp','datetime_pp','review_status');
SELECT 'capture_assignments table' AS test, COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_NAME='capture_assignments';
SELECT 'vicon_files CC count' AS test, COUNT(*) AS cnt FROM vicon_files WHERE subdirectory='unreal/CC';
"
```

Expected: 4, 1, ~6618

- [ ] **Step 3: Test a quick query through the model**

```bash
cd /web/animMIDI && php -r "
require 'vendor/autoload.php';
\$m = new App\models\MocapFile();
\$dates = \$m->getAvailableDates('unprocessed');
echo 'Available dates: ' . count(\$dates) . PHP_EOL;
\$a = new App\models\Assignment();
\$users = \$a->getActiveUsers();
echo 'Active users: ' . count(\$users) . PHP_EOL;
"
```

Expected: Shows date count (~26) and user count (~29)

- [ ] **Step 4: Commit any remaining changes**

If any fixes were needed during smoke testing, commit them:

```bash
git add -A && git status
```

Only commit if there are changes to commit.
