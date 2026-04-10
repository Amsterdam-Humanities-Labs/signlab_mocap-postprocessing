# Auth, Delegation & Data Source Redesign

## Overview

Add authentication, logout, capture date delegation, and switch the data source from `mocap_files` to `vicon_files` for the animMIDI Motion Capture File Manager.

## 1. Auth Middleware

**New file: `app/auth.php`**

- Reads the `sessionObject` cookie, JSON-decodes it
- Validates that `userId` and `username` are present and non-empty
- Checks cookie `expiresAt` hasn't passed
- Exposes `$currentUser` array: `['userId', 'username', 'role']`
- If invalid/missing on page endpoints → redirects to `/login.html` and exits
- If invalid/missing on API endpoints (like `review-status.php`) → returns 401 JSON response
- Helper function `isAdmin($username)` returns true if username is `gomer` or `jari`

**Protected endpoints** (all require auth):
- `public/index.php`
- `public/upload.php`
- `public/download.php`
- `public/review-status.php`
- `public/delegate.php` (new, admin-only)

## 2. Data Source: `vicon_files` table

### Current state

The app currently uses the `mocap_files` table, populated by `/web/mocapDataPackage/upload.php`. This is incomplete — not all captures appear.

### New data source

Switch to `vicon_files` filtered on `subdirectory = 'unreal/CC'`. This table has 6,618 records across 26 capture dates and represents the actual capture files.

**Key fields used:**

| Field | Usage |
|---|---|
| `id` | Primary key (BIGINT) |
| `capture_id` | Format `DATE/FILENAME` — capture date extracted via `SUBSTRING_INDEX(capture_id, '/', 1)` |
| `filename` | FBX filename (e.g., `M20260204_3146_260217_1.fbx`) |
| `glb_path` | Path to converted GLB file |
| `file_path` | Original path on capture machine |
| `status` | `growing` or `complete` |
| `first_seen` | When file was first detected |
| `last_modified` | Last modification time |

**New columns to add to `vicon_files`:**

| Column | Type | Default | Purpose |
|---|---|---|---|
| `is_pp` | TINYINT(1) | 0 | Post-processed flag |
| `filename_pp` | VARCHAR(512) | NULL | Post-processed filename |
| `datetime_pp` | DATETIME | NULL | When post-processing was done |
| `review_status` | ENUM('pending','approved','rejected','needs_review') | 'pending' | Review status |

**Broadcast name link:** `SUBSTRING_INDEX(REPLACE(filename, '.fbx', ''), '_', 2)` extracts broadcast name (e.g., `M20260204_3146`) which matches `REPLACE(m_file, '.wav', '')` in `matched_transcriptions`.

**Capture date:** Extracted from `capture_id` as `SUBSTRING_INDEX(capture_id, '/', 1)`.

**Deduplication:** Same logic as current — latest take per base glos using the `_YYMMDD_N` suffix pattern from the filename.

### Model rewrite

The `MocapFile` model will be rewritten to query `vicon_files WHERE subdirectory = 'unreal/CC'` instead of `mocap_files`. All existing methods (grouped by date, filtered by status, counts, pagination, search) will be adapted to use the new table structure with `capture_id`-based date extraction.

## 3. Database: `capture_assignments` table

**New table:**

| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | |
| `username` | VARCHAR(255) | The assigned user (matches `users.user`) |
| `capture_date` | DATE | The capture date assigned |
| `assigned_by` | VARCHAR(255) | Who made the assignment (gomer or jari) |
| `assigned_at` | DATETIME DEFAULT NOW() | When the assignment was made |

- Unique constraint on `(username, capture_date)` to prevent duplicates
- Migration file at `migrations/001_capture_assignments.sql`

## 4. File List Filtering by Assignment

**In `FileListController`:**

- After auth, check `isAdmin($currentUser['username'])`:
  - Admin → no date filtering, sees all captures (current behavior)
  - Non-admin → query `capture_assignments` for their assigned dates
    - No assignments → empty state: "Geen captures aan u toegewezen. Neem contact op met een beheerder."
    - Has assignments → add `WHERE` clause filtering by assigned dates

**In `MocapFile` model:**

- Add optional `$allowedDates` parameter to all query methods
- When provided, adds date filtering clause
- Date dropdown in the UI also filtered to only show assigned dates for non-admins
- Count methods get the same filter

Filtering is enforced server-side at the query level.

## 5. Delegation Page

**New endpoint: `public/delegate.php`**
- Requires auth + `isAdmin()` check → 403 if not gomer or jari

**New controller: `DelegateController`**
**New model: `Assignment`**

Methods: `getAssignmentsForUser()`, `getAllAssignments()`, `assign($username, $dates, $assignedBy)`, `unassign($id)`

**UI (Tailwind, matches existing style):**

- **Assignment form (left panel):**
  - User dropdown (from `users` table)
  - Available capture dates as checkboxes (from `vicon_files` distinct dates where `subdirectory = 'unreal/CC'`)
  - "Toewijzen" (Assign) button

- **Current assignments (right panel):**
  - Grouped by user
  - Shows each user's assigned dates with file counts per date
  - Remove button (X) per assignment to unassign

**Backend:** POST handling for assign/unassign actions (same pattern as `review-status.php`).

## 6. Logout Button & Header

**Persistent header** added to all views (`file-list.php`, `upload-form.php`, `upload-result.php`, delegate view):

- Left: "Motion Capture File Manager" title
- Center: Link back to main menu (`/menu.html`)
- Right: Logged-in username display
- Right: "Toewijzingen" link (admin only — gomer/jari)
- Far right: Logout button (clears `sessionObject` cookie, redirects to `/login.html`)

Minimal single bar, Tailwind styling consistent with existing views.

## Architecture Summary

```
public/
  index.php        ← requires auth.php
  upload.php       ← requires auth.php
  download.php     ← requires auth.php
  review-status.php ← requires auth.php (401 JSON on failure)
  delegate.php     ← requires auth.php + isAdmin()

app/
  auth.php         ← NEW: cookie validation, $currentUser, isAdmin()
  config/Database.php  ← unchanged
  controllers/
    FileListController.php  ← updated: passes $currentUser, $allowedDates
    UploadController.php    ← updated: uses auth
    DownloadController.php  ← unchanged (auth added at entry point)
    DelegateController.php  ← NEW
  models/
    MocapFile.php    ← REWRITTEN: queries vicon_files instead of mocap_files
    Assignment.php   ← NEW: capture_assignments CRUD
  views/
    file-list.php      ← updated: header with logout, filtered dates
    upload-form.php    ← updated: header with logout
    upload-result.php  ← updated: header with logout
    delegate.php       ← NEW: delegation UI

migrations/
  001_capture_assignments.sql   ← NEW table
  002_vicon_files_pp_columns.sql ← ALTER TABLE adds pp columns
```

## Access Control Summary

| User | Sees | Can delegate |
|---|---|---|
| gomer | All captures, all dates | Yes |
| jari | All captures, all dates | Yes |
| Others | Only assigned dates | No |
| No assignments | Empty state message | No |
| Not logged in | Redirected to /login.html | No |
