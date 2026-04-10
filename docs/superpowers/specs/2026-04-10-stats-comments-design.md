# Statistics Page & Comments Column Design

## Overview

Add a statistics page showing activity from `download_logs` with charts and filterable log, and a per-file comment field on the file list.

## 1. Comments Column

**Database:** Add `comment` TEXT DEFAULT NULL and `comment_by` VARCHAR(255) DEFAULT NULL to `vicon_files`.

**UI:** In the file list table, add a "Comment" column. Shows truncated comment text (or empty placeholder). Clicking opens an inline textarea. On blur or Enter, saves via AJAX.

**Endpoint:** `public/update-comment.php` — POST with `id`, `comment`. Requires auth. Updates `vicon_files.comment` and `vicon_files.comment_by` with the current user's name.

**Model:** Add `updateComment($id, $comment, $username)` to `MocapFile`.

## 2. Statistics Page

**Endpoint:** `public/stats.php` → `StatsController::index($currentUser)` → `app/views/stats.php`

**Access:** All logged-in users.

**Navigation:** Add "Statistieken" link in the header nav (visible to all users, next to the admin-only "Toewijzingen" link).

### Layout

**Section 1 — Summary cards (top row):**
- Total files processed
- Total files downloaded
- Total files uploaded
- Total active users (distinct usernames in download_logs)

**Section 2 — Timeline chart:**
- Chart.js line chart (loaded from CDN)
- X-axis: dates (last 30 days by default)
- Y-axis: count of actions
- Three lines: downloads (blue), uploads (green), processed (orange)
- Date range selector to adjust the window

**Section 3 — Activity log table:**
- Columns: Username, Filename, Action, Date/Time
- Dropdown filter: "All users" or specific user
- Paginated (50 per page), most recent first
- Action labels: downloaded, uploaded, processed, reverted

### Backend

**New model: `app/models/Stats.php`**

Methods:
- `getSummary(): array` — returns `['processed' => N, 'downloaded' => N, 'uploaded' => N, 'users' => N]`
- `getDailyActivity(int $days = 30): array` — returns `[['date' => '2026-04-10', 'downloads' => N, 'uploads' => N, 'processed' => N], ...]`
- `getActivityLog(?string $username = null, int $page = 1, int $limit = 50): array` — paginated log rows
- `getActivityLogCount(?string $username = null): int` — total count for pagination
- `getLogUsers(): array` — distinct usernames from download_logs

**New controller: `app/controllers/StatsController.php`**

Single `index($currentUser)` method that fetches all data and renders the view.

## Architecture

```
public/stats.php           ← requires auth (all users)
public/update-comment.php  ← requires auth API

app/controllers/StatsController.php  ← NEW
app/models/Stats.php                 ← NEW
app/views/stats.php                  ← NEW

app/models/MocapFile.php             ← add updateComment()
app/views/file-list.php              ← add comment column
app/views/partials/header.php        ← add Statistieken link

migrations/005_vicon_files_comment.sql  ← add comment + comment_by columns
```
