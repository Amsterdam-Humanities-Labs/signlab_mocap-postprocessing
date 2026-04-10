# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Motion Capture Post-Processing Management System (signCollect animMIDI) — A web application for managing animation files from motion capture sessions. Engineers download original FBX files, post-process them in Unreal Engine, and upload processed versions back. Includes 3D preview with BabylonJS and side-by-side animation comparison.

## Database Configuration

- MySQL database: `admin_gebarenoverleg`
- Configuration file: `mysql_config.php`
- Primary data source: `vicon_files` table filtered on `subdirectory = 'unreal/CC'`
- Authentication: cookie-based (`sessionObject` cookie), shared with `/web/login.html` system
- Admin users: `gomer`, `jari` (hardcoded in `app/auth.php`)

### Key Tables

| Table | Purpose |
|---|---|
| `vicon_files` | Primary capture files (filter: `subdirectory = 'unreal/CC'`). Has `is_pp`, `filename_pp`, `datetime_pp`, `review_status`, `comment`, `comment_by` columns |
| `capture_assignments` | One user per capture date delegation. Unique on `capture_date` |
| `download_logs` | Activity tracking: downloads, uploads, mark_processed, mark_unprocessed |
| `users` | Shared auth system with `/web/` login |
| `matched_transcriptions` | Links broadcast names to captures via `has_mocap` |

### Filename Convention

`M20260113_9959_260319_0.fbx` = `{broadcast_name}_{capture_date_YYMMDD}_{take_number}`

Capture date extracted from `vicon_files.capture_id` via `SUBSTRING_INDEX(capture_id, '/', 1)`.

## Architecture

### Directory Structure
```
/web/animMIDI/
├── mysql_config.php          # Database configuration
├── .htaccess                 # Apache rewrite (public/ + babyloncc/dist exception)
├── public/                   # Web-accessible PHP entry points
│   ├── index.php             # File list (requires auth)
│   ├── upload.php            # Upload handler (requires auth)
│   ├── download.php          # Download handler (bundles FBX + MKV ZIP)
│   ├── review-status.php     # Review status API
│   ├── mark-processed.php    # Toggle processed status API
│   ├── update-comment.php    # Comment save API
│   ├── stats.php             # Statistics page
│   └── delegate.php          # Capture date delegation (admin only)
├── app/
│   ├── auth.php              # Cookie-based auth middleware
│   ├── config/Database.php   # PDO singleton
│   ├── controllers/
│   │   ├── FileListController.php
│   │   ├── UploadController.php
│   │   ├── DownloadController.php
│   │   ├── DelegateController.php
│   │   └── StatsController.php
│   ├── models/
│   │   ├── MocapFile.php     # Queries vicon_files (not mocap_files)
│   │   ├── Assignment.php    # Capture date assignments
│   │   └── Stats.php         # Activity statistics
│   └── views/
│       ├── file-list.php     # Main file browser with sidebar preview
│       ├── upload-form.php   # Drag-and-drop upload
│       ├── upload-result.php
│       ├── delegate.php      # Admin delegation page
│       ├── stats.php         # Chart.js statistics
│       └── partials/header.php
├── babyloncc/                # BabylonJS 3D animation viewer
│   ├── dist/
│   │   ├── index.html        # Single-file viewer (CDN BabylonJS, no build)
│   │   ├── compare.html      # Side-by-side comparison viewer
│   │   └── Palmer_optimized_ktx2.glb  # Avatar model
│   └── src/                  # Original TypeScript source (reference only)
├── migrations/               # SQL migration files
├── tests/                    # PHPUnit tests
└── vendor/                   # Composer dependencies
```

### BabylonCC Viewer

Standalone HTML files using BabylonJS CDN — no build step required. Edit and refresh.

- **index.html**: Single animation preview. URL params: `?anim=` (GLB path), `?video=` (MKV background), `?scale-skeletal-anim=` (position multiplier)
- **compare.html**: Side-by-side original vs post-processed. URL param: `?file=` (filename without extension). Auto-detects per-bone rotation amplification to compensate for Unreal export reduction.

Key technical details:
- Animation GLBs have no skeleton (0 skins) — they animate TransformNodes
- Retargeting matches TransformNode names to avatar bone linked TransformNodes
- Auto-detects position scale (meters → centimeters, ~102x)
- Per-bone rotation amplification for post-processed animations

### File Storage Paths

| Type | Disk Path | Web URL |
|---|---|---|
| Original FBX | `/web/gebarenoverleg_media/fbx/CC/` | `/gebarenoverleg_media/fbx/CC/` |
| Original GLB | `/web/gebarenoverleg_media/fbx/CC/` | `/gebarenoverleg_media/fbx/CC/` |
| Post-processed FBX | `/web/gebarenoverleg_media/fbx/post_processed/` | `/gebarenoverleg_media/fbx/post_processed/` |
| Post-processed GLB | `/web/gebarenoverleg_media/fbx/post_processed/` | `/gebarenoverleg_media/fbx/post_processed/` |
| OBS video (MKV) | `/mnt/bigstorage/razerFiles/` | `/gebarenoverleg_media/razerFiles/` (symlink) |
| Avatar GLB | `/web/animMIDI/babyloncc/dist/` | `/animMIDI/babyloncc/dist/` |

## Development Commands

```bash
# Install PHP dependencies
composer install

# Run PHPUnit tests
./vendor/bin/phpunit

# Start local PHP server
php -S localhost:8000 -t public/

# Run database migrations
mysql -u user -p admin_gebarenoverleg < migrations/001_capture_assignments.sql
mysql -u user -p admin_gebarenoverleg < migrations/002_vicon_files_pp_columns.sql
mysql -u user -p admin_gebarenoverleg < migrations/003_download_logs.sql
mysql -u user -p admin_gebarenoverleg < migrations/004_extend_download_logs_actions.sql
mysql -u user -p admin_gebarenoverleg < migrations/005_vicon_files_comment.sql
```

## Important Considerations

- Always query `vicon_files WHERE subdirectory = 'unreal/CC'`, never `mocap_files`
- File permissions: GLB files in `dist/` need `644` for Apache to serve them
- The `.htaccess` has an exception for `babyloncc/dist/` to bypass the `public/` rewrite
- BabylonCC uses CDN — no npm/Vite build needed. Just edit HTML and refresh.
- Post-processed animations from Unreal have reduced rotation ranges (~3x smaller) — the compare tool compensates with per-bone amplification
