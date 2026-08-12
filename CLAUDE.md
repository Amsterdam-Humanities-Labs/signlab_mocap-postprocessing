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
│   ├── download-eaf.php      # Batch per-take bundle (EAF/SRT + PP FBX/GLB + reference video), capped at 100 takes
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
│   ├── services/
│   │   ├── EafLocator.php    # Locates take-level .eaf/.srt files for EAF download
│   │   ├── PathSafety.php    # Shared path sanitisation/containment trait
│   │   └── TakeBundleLocator.php  # Locates PP FBX/GLB + reference video per take
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
- **compare.html**: Side-by-side original vs post-processed. URL param: `?file=` (filename without extension). Auto-detects per-bone rotation amplification to compensate for Unreal export reduction. Includes frame scrubber slider, play/pause, and 1.5m avatar separation.

Key technical details:
- Animation GLBs have no skeleton (0 skins) — they animate TransformNodes
- Retargeting matches TransformNode names to avatar bone linked TransformNodes
- Position scale is a **hardcoded ×100** (see retargeting notes below)
- Per-bone rotation amplification for post-processed animations (built from CC vs PP rotation range comparison) — currently disabled in `compare.html` but helpers remain for future use
- Press **`i`** key in any viewer to toggle the BabylonJS Inspector (debug layer)
- Compare link in file list only shows for processed files (`is_pp == 1`)

### Retargeting Notes

The Babylon viewers retarget animation GLBs onto a separately loaded Palmer avatar by matching TransformNode names. A few things to know before touching `retarget()` in `index.html` or `compare.html`:

- **Unit mismatch is fixed at ×100, not auto-detected.** The Blender FBX→GLB pipeline always exports in **meters** (pelvis rest Z = `1.0035`, eye local offsets ~`0.078`); Palmer is authored in **centimeters** (pelvis rest Z = `100.35`, eye ~`7.88`). The ratio is exactly 100 for every bone, verified across 668+ GLBs. `POS_SCALE = 100` lives at the top of each viewer's script — do not replace it with a runtime detector.
- **Why not auto-detect from the pelvis animation?** A previous version computed `avatarRestZ / animation.keys[0].z`, but frame 0 is whatever pose the actor happened to be in. A slightly crouched actor gave ratios like 108 instead of 100. On the pelvis that's a centimeter-scale error (invisible), but on local offsets the size of an eyeball (`0.0788`), an 8% error pushes the eye ~6 mm out of its socket — the eyes were visibly misaligned for those takes.
- **Eye bones are the canary.** They have tiny local translations (`cc_base_l_eye` / `cc_base_r_eye`, a few cm), so any error in the position-scale path shows up there first. If you change the retarget math, spot-check an eye in the Inspector (`i` key) against Palmer's rest pose before declaring success.
- **Scale everything, not just pelvis.** All non-rotation position channels in the animation need ×100 — both pelvis locomotion and the static local offsets baked into every other bone. Skipping non-pelvis bones leaves them at 1/100 scale relative to Palmer's skeleton.
- **If the export pipeline ever changes** (different Blender version, different exporter, or post-processing via a tool other than Unreal/Blender), reverify rest values. Drop a new GLB next to an old one, inspect `node.translation` for `pelvis` and `cc_base_l_eye`, and confirm the ratio to Palmer's bones is still 100.

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
mysql -u user -p admin_gebarenoverleg < migrations/006_download_logs_eaf_type.sql
```

## Important Considerations

- Always query `vicon_files WHERE subdirectory = 'unreal/CC'`, never `mocap_files`
- File permissions: GLB files in `dist/` need `644` for Apache to serve them
- The `.htaccess` has an exception for `babyloncc/dist/` to bypass the `public/` rewrite
- BabylonCC uses CDN — no npm/Vite build needed. Just edit HTML and refresh.
- Post-processed animations from Unreal have reduced rotation ranges (~3x smaller) — the compare tool compensates with per-bone amplification
- The file list's "MCP Klaar+EAF" filter shows only takes that are workflow-complete and have a take-level annotation on disk (via `EafLocator::hasEaf()`)
- "MCP Klaar" means `sentences.mcp_status_postprocessing = '1'` AND `sentences.mcp_status_tijd_annotatie = 'Klaar'` — see `MocapFile::isKlaar()`. Two traps: the postprocessing column stores the dropdown's *value* (`1` = Klaar, `2` = Check nodig), not the word; and `mcp_status_tijd_annotatie` is the **Gloss** field in `zinnen.html`. The separate `mcp_status_tijd_annotatie_gvg` (Gebaar voor Gebaar/Nederlands) is deliberately NOT part of the gate.
