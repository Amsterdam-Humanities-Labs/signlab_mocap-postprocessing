# signCollect Animation Post-Processing Manager

Web application for managing motion capture animation files from the signCollect sign language recording system. Engineers download original FBX captures, post-process them in Unreal Engine, and upload processed versions back. Includes 3D animation preview, side-by-side comparison, and batch download of annotation (EAF/SRT) bundles.

## Features

- **File Management**: Browse captures by date, search, filter by processing status and by MCP Klaar / EAF availability
- **Authentication**: Cookie-based login (shared `sessionObject` cookie from the signCollect portal) with role-based access control
- **Capture Delegation**: Admins assign capture dates to specific users (one user per date)
- **Download**: Single FBX, or bundled FBX + reference video (MKV) as ZIP; bulk downloads survive PHP time limits
- **EAF Batch Download**: Per-take bundles (post-processed FBX/GLB, video, EAF/SRT) gated on MCP Klaar status, capped at 100 takes
- **Upload**: Drag-and-drop FBX files or ZIP upload with automatic file matching
- **Processing Status**: Mark animations as correct without re-upload, with revert option; flags the linked sentence
- **Activity Tracking**: All downloads, uploads, and status changes logged with timestamps
- **Comments**: Per-file inline editable comments
- **Statistics**: Dashboard with Chart.js graphs, per-user activity breakdown, and filterable activity log
- **3D Preview**: BabylonJS-based animation viewer in resizable sidebar (Palmer avatar, studio rendering, playbar)
- **Animation Comparison**: Side-by-side original vs post-processed with per-bone rotation compensation

## Architecture

- **Backend**: PHP 7.4+ with PDO, MVC pattern (`app/controllers`, `app/models`, `app/services`, `app/views`)
- **Frontend**: Tailwind CSS (CDN), vanilla JavaScript
- **Database**: MySQL (`admin_gebarenoverleg`) — shared with the signCollect suite
- **3D Viewer**: `babyloncc/` — Vite + TypeScript + BabylonJS app; the built output in `babyloncc/dist/` is committed and served directly
- **Data Source**: `vicon_files` table (subdirectory `unreal/CC`)

## Requirements

- PHP 7.4+ with PDO, cURL, ZIP extensions
- MySQL 5.7+ with the signCollect `admin_gebarenoverleg` database (see *External dependencies* below)
- Apache with mod_rewrite (the app expects to be mounted at `/animMIDI/`)
- Composer
- Node.js 18+ (only if you rebuild the BabylonCC viewer)
- Modern browser with WebGL2 support

## Setup

1. Clone the repository (it must live at `/web/animMIDI` — see *Portability*):
   ```bash
   git clone git@github.com:Amsterdam-Humanities-Labs/signlab_sC-Animation-PP.git /web/animMIDI
   cd /web/animMIDI
   ```

2. Install PHP dependencies (`vendor/` is not committed):
   ```bash
   composer install
   ```

3. Create the database config (`mysql_config.php` is gitignored):
   ```php
   <?php
   $servername = "localhost";
   $username   = "user";
   $password   = "secret";
   $database   = "admin_gebarenoverleg";
   ```

4. Run database migrations, in order:
   ```bash
   for f in migrations/*.sql; do mysql -u user -p admin_gebarenoverleg < "$f"; done
   ```
   These add `capture_assignments`, `download_logs`, and extra columns on `vicon_files`. They assume the base signCollect tables already exist.

5. Configure Apache so that `/animMIDI/` maps to this directory; the root `.htaccess` rewrites everything to `public/` except `babyloncc/dist/`.

6. Ensure the web server can read the avatar assets:
   ```bash
   chmod 644 babyloncc/dist/*.glb
   ```

7. Verify:
   ```bash
   php vendor/bin/phpunit
   ```
   Note: 5 tests (`DatabaseTest::testCannotUnserializeDatabase` and 4 in `MocapFileTest`) are stale — written for the old `mocap_files` model — and currently fail; the rest should pass.

## External dependencies

This app is **not self-contained**. It is one module of the signCollect deployment and relies on:

**Database tables it does not create** (all in `admin_gebarenoverleg`, owned by signCollect):
`vicon_files`, `sentences`, `matched_transcriptions`, `users`, `form_data`, `labels`, `_pp_sent`, `_pp_bc`, `_all_sent`.

**Authentication**: there is no login page here. `app/auth.php` reads the `sessionObject` cookie set by the signCollect portal login on the same domain. Without that portal, every page redirects/401s.

**File storage paths** (hardcoded in `app/controllers/*`, `app/services/*`, `app/models/MocapFile.php`):

| Type | Path |
|---|---|
| Original FBX/GLB | `/web/gebarenoverleg_media/fbx/CC/` |
| Post-processed FBX/GLB | `/web/gebarenoverleg_media/fbx/post_processed/` |
| Blackmagic studio recordings | `/web/gebarenoverleg_media/studioFiles/blackmagic_files/` |
| Blackmagic previews (mini) | `/web/gebarenoverleg_media/blackamgic_filesMini/` — the misspelling is real and matches the directory on disk; do not "fix" it |
| Camera recordings (MKV) | `/mnt/bigstorage/razerFiles/` |
| EAF annotation files | `/web/zin/eaf/zin/` (from the `zin` project) |

Preview URLs are derived by stripping the `/web/` prefix from disk paths, so `/web` must be the web root.

## Portability

Running this on another machine works only if it reproduces the production layout:

- Cloned to **exactly** `/web/animMIDI` with `/web` as the Apache document root (`/animMIDI/` URL prefix and `/web/` path stripping are hardcoded).
- The media directories above exist (or are symlinked) at the same absolute paths.
- The full signCollect MySQL database is available, plus the signCollect portal for the login cookie.

For a standalone dev setup you would need to: (a) create the external tables (no schema is shipped for them — dump them from production), (b) fake the `sessionObject` cookie or stub `requireAuth()`, and (c) point the path constants at local directories. Making the paths configurable via `mysql_config.php`/env is the obvious next refactor.

## BabylonCC 3D Viewer

Source lives in `babyloncc/src/` (Vite + TypeScript + BabylonJS). The compiled app in `babyloncc/dist/` is committed so the server needs no Node toolchain.

- **Preview**: `/animMIDI/babyloncc/dist/?anim=/path/to/animation.glb`
- **Compare**: `/animMIDI/babyloncc/dist/compare.html?file=M20260126_1880_260319_1`

To change the viewer:
```bash
cd babyloncc
npm install
npm run dev      # local dev server
npm run build    # regenerates dist/ — commit the result
```
`compare.html` in `dist/` is a hand-written standalone page (BabylonJS via CDN), not a Vite build output.

### Retargeting: unit conventions

Animation GLBs come out of the Blender FBX→GLB pipeline in **meters** (pelvis rest ≈ 1.0 m). The Palmer avatar is authored in **centimeters** (pelvis rest ≈ 100 cm). The viewers retarget by matching TransformNode names and applying a fixed `×100` multiplier to every position channel — never a runtime-detected ratio. An earlier version auto-detected the scale from the pelvis animation's first keyframe, but actors don't always start in rest pose, so the ratio drifted to 102–108 and pushed small local offsets (eyes, jaw) visibly off. The Blender export is deterministic across all 668+ sampled takes; if you ever swap in a non-Blender exporter, re-verify that `node.translation` for `pelvis` / `cc_base_l_eye` is still 1/100 of Palmer's values before trusting the viewer.

## Development

```bash
composer install                 # PHP deps
php vendor/bin/phpunit           # tests
php -S localhost:8000 -t public  # quick local server (auth + paths still need production layout)
```

See `CLAUDE.md` for a deeper map of the codebase, key tables, and filename conventions.
