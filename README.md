# signCollect Animation Post-Processing Manager

Web application for managing motion capture animation files from the signCollect sign language recording system. Engineers download original FBX captures, post-process them in Unreal Engine, and upload processed versions back. Includes 3D animation preview and side-by-side comparison tools.

## Features

- **File Management**: Browse captures by date, search, filter by processing status
- **Authentication**: Cookie-based login with role-based access control
- **Capture Delegation**: Admins assign capture dates to specific users (one user per date)
- **Download**: Single FBX or bundled FBX + camera video (MKV) as ZIP
- **Upload**: Drag-and-drop FBX files or ZIP upload with automatic file matching
- **Processing Status**: Mark animations as correct without re-upload, with revert option
- **Activity Tracking**: All downloads, uploads, and status changes logged with timestamps
- **Comments**: Per-file inline editable comments
- **Statistics**: Dashboard with Chart.js graphs, per-user activity breakdown, and filterable activity log
- **3D Preview**: BabylonJS-based animation viewer in resizable sidebar
- **Animation Comparison**: Side-by-side original vs post-processed with per-bone rotation compensation

## Architecture

- **Backend**: PHP 7.4+ with PDO, MVC pattern
- **Frontend**: Tailwind CSS (CDN), vanilla JavaScript
- **Database**: MySQL (`admin_gebarenoverleg`)
- **3D Viewer**: BabylonJS (CDN), standalone HTML — no build step
- **Data Source**: `vicon_files` table (subdirectory `unreal/CC`)

## Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/rem0g/sC-Animation-PP.git /web/animMIDI
   ```

2. Install PHP dependencies:
   ```bash
   cd /web/animMIDI && composer install
   ```

3. Run database migrations:
   ```bash
   mysql -u user -p admin_gebarenoverleg < migrations/001_capture_assignments.sql
   mysql -u user -p admin_gebarenoverleg < migrations/002_vicon_files_pp_columns.sql
   mysql -u user -p admin_gebarenoverleg < migrations/003_download_logs.sql
   mysql -u user -p admin_gebarenoverleg < migrations/004_extend_download_logs_actions.sql
   mysql -u user -p admin_gebarenoverleg < migrations/005_vicon_files_comment.sql
   ```

4. Configure Apache to serve from `/web/animMIDI/public/` with the `.htaccess` rewrite rules.

5. Ensure file permissions:
   ```bash
   chmod 644 babyloncc/dist/Palmer_optimized_ktx2.glb
   ```

## File Storage

| Type | Path |
|---|---|
| Original FBX/GLB | `/web/gebarenoverleg_media/fbx/CC/` |
| Post-processed FBX/GLB | `/web/gebarenoverleg_media/fbx/post_processed/` |
| Camera recordings (MKV) | `/mnt/bigstorage/razerFiles/` (symlinked to web) |

## BabylonCC 3D Viewer

Standalone HTML files — edit and refresh, no npm or build tools needed.

- **Preview**: `/animMIDI/babyloncc/dist/?anim=/path/to/animation.glb`
- **Compare**: `/animMIDI/babyloncc/dist/compare.html?file=M20260126_1880_260319_1`

## Requirements

- PHP 7.4+ with PDO, cURL, ZIP extensions
- MySQL 5.7+
- Apache with mod_rewrite
- Modern browser with WebGL2 support
