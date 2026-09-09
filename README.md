# signCollect Animation Post-Processing Manager

Web application for managing motion capture animation files from the signCollect sign language recording system. Engineers download original FBX captures, post-process them in Unreal Engine, and upload processed versions back. Includes 3D animation preview, side-by-side comparison, and batch download of annotation (EAF/SRT) bundles.

**Status: production.** This is in daily use by the post-processing engineers, it is under active development (most recently September 2026), and it ships in the standard deployment alongside the rest of the signCollect web interface. Unlike the experimental repositories in this organisation, changes here reach real users.

## Where it runs

| Host | Path | URL |
|---|---|---|
| signcollect core server (production VPS) | `/web/animMIDI` | `https://signcollect.nl/animMIDI/` |
| dev2 (demo) | `/web/animMIDI` | |
| dev-1 (demo) | `/srv/signcollect/web/animMIDI` | |

**The repository name and the directory name differ**: `signlab_sC-Animation-PP` deploys to `animMIDI`. That is not a mistake to be tidied up — the `/animMIDI/` URL prefix is hardcoded in the root `.htaccess`, in the viewer links in `app/views/file-list.php`, and in pages elsewhere in the suite that link here. The deploy manifest in the `signcollect-demovps` repository (`scripts/repos.tsv`) maps the two.

On the demo hosts the docroot is not always `/web`, so every filesystem path is resolved at runtime from the install root rather than hardcoded — see *External dependencies* below.

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
- Composer (for `dump-autoload`; there are no packages to fetch)
- Node.js 18+ (only if you rebuild the BabylonCC viewer)
- Modern browser with WebGL2 support

## Size and how to clone

A full clone is about **146 MB** (42 MB of it packed history), and essentially all of it is `babyloncc/` — the committed viewer build. The single biggest file is `babyloncc/dist/PalmerPolo1024uastc.glb` at 34 MB; `Palmer_optimized_ktx2.glb` (13 MB) exists in three copies, `glassesGuySignLab.glb` is 11 MB, and the bundled Babylon build is 8 MB. That is a deliberate trade: `dist/` is committed so the server needs no Node toolchain.

Clone it normally — the deploy needs the whole tree, and the avatars in `dist/` are load-bearing for other components (see *External dependencies*). If you only want to read the PHP:

```bash
git clone --depth 1 --filter=blob:none git@github.com:Amsterdam-Humanities-Labs/signlab_sC-Animation-PP.git
```

## Setup

1. Clone the repository:
   ```bash
   git clone git@github.com:Amsterdam-Humanities-Labs/signlab_sC-Animation-PP.git /web/animMIDI
   cd /web/animMIDI
   ```

2. **Generate the Composer autoloader. This step is not optional.** `vendor/` is gitignored, so a fresh clone has no `vendor/autoload.php` — and all nine entry points under `public/` `require` it on their first line. Skip this and every page returns a blank 500 with `Failed opening required '…/vendor/autoload.php'` in the Apache error log, which looks like a permissions or rewrite problem and is not.
   ```bash
   composer dump-autoload --no-dev --optimize
   ```
   `dump-autoload`, not `install`: `composer.json` declares no packages, only PHP extensions and a PSR-4 map (`App\` → `app/`). There is nothing to download and a host must not need a package registry to come up. Use `composer install` only if you want the dev dependency (PHPUnit) as well.

3. Create the database config. `mysql_config.php` is gitignored, so a clone never has one, and `app/config/Database.php` looks for it at `dirname(__DIR__, 2) . '/mysql_config.php'` — i.e. **in the repository root, one level above the `public/` document root**, not at the docroot and not next to `Database.php`. On a host that keeps one credential file for the whole suite, symlink rather than copy:
   ```bash
   ln -sfn /web/mysql_config.php /web/animMIDI/mysql_config.php
   ```
   Its contents:
   ```php
   <?php
   $servername = "localhost";
   $username   = "user";
   $password   = getenv('DB_PASSWORD');
   $database   = "admin_gebarenoverleg";
   ```
   If your storage layout differs from production, also `cp paths.local.php.example paths.local.php` and edit it.

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

**Other repositories in the deployment:**

| Repository | Relationship |
|---|---|
| `signlab_signcollect-lib` | Deploys to `<root>/lib`. Supplies the install-root resolver that `sc_paths.php` looks for, and the credentials the rest of the suite reads. Must be on disk before this component. |
| `signlab_signCollect-v2` | The portal that sets the `sessionObject` cookie this app authenticates with. |
| `signlab_zin` | Produces the `.eaf` / `.srt` files in `eaf_dir` that the EAF batch download ships. |
| `signlab_annotation-editors` | **Depends on this repository, not the other way round.** The subBeta8 and 3DAnn3 editors load `babyloncc/dist/environment.envbin` and the 34 MB PalmerPolo avatar by absolute `/animMIDI/…` path. If animMIDI is not deployed, those editors break. |

**File storage paths** — configured in `paths.php` (each key is documented there); override per machine by copying `paths.local.php.example` to `paths.local.php`.

Since September 2026 these are not hardcoded `/web/…` strings. `paths.php` requires the vendored `sc_paths.php` shim and resolves every location through `sc_root()` / `sc_dir()`, which find `signcollect-lib`'s resolver at `lib/paths.php` (searching `./lib`, `../lib`, `../../lib`, then `/web/lib`) and fall back to a hardcoded `/web` when no library is present — production has no `/web/lib`, and a missing library must not turn a path lookup into a 500. **`sc_paths.php` is a vendored file, byte-identical in every consumer repository.** Do not edit this copy; edit `signlab_signcollect-lib`'s `consumer/sc_paths.php` and re-copy.

The values below are what those calls produce with an install root of `/web`:

| Key | Path under a `/web` root |
|---|---|
| `fbx_original` | `/web/gebarenoverleg_media/fbx/CC/` |
| `fbx_processed` | `/web/gebarenoverleg_media/fbx/post_processed/` — the only directory this app writes to |
| `blackmagic_full` | `/web/gebarenoverleg_media/studioFiles/blackmagic_files/` |
| `blackmagic_mini` | `/web/gebarenoverleg_media/blackamgic_filesMini/` — the misspelling is real and matches the directory on disk; do not "fix" it |
| `razer_mkv` | `/mnt/bigstorage/razerFiles/` — absolute, outside the root, with its own URL prefix `razer_mkv_url` |
| `eaf_dir` | `/web/zin/eaf/zin/` (from the `zin` project) |
| `remote_fbx_base_url` | `https://signcollect.nl/gebarenoverleg_media/fbx/` — HTTPS fallback when an original FBX is missing locally; `''` disables it |

Preview URLs are derived by stripping `web_root` from disk paths, so media that the browser fetches must live below `web_root`.

**Files that are never in a clone**, and where each comes from:

| File | Source |
|---|---|
| `mysql_config.php` | The host. Symlinked to the suite's single credential file by `scripts/host-bootstrap.sh` on the demo hosts; hand-written on production. See Setup step 3 for the exact location it is looked up at. |
| `vendor/autoload.php` | Generated by `composer dump-autoload` (Setup step 2). |
| `paths.local.php` | Optional, per machine. Template: `paths.local.php.example`. |

## Portability

Running this on another machine works only if it reproduces the production layout:

- Served under the `/animMIDI/` URL prefix (still assumed by `.htaccess` and the viewer links).
- The media directories exist somewhere — set their locations in `paths.local.php`; browser-fetched ones (GLBs, preview MP4s) must be below `web_root`.
- The full signCollect MySQL database is available, plus the signCollect portal for the login cookie.

For a standalone dev setup you would still need to: (a) create the external tables (no schema is shipped for them — dump them from production), and (b) fake the `sessionObject` cookie or stub `requireAuth()`.

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
composer install                 # autoloader + PHPUnit (dump-autoload alone suffices to serve)
php vendor/bin/phpunit           # tests
php -S localhost:8000 -t public  # quick local server (auth + paths still need production layout)
```

See `CLAUDE.md` for conventions and gotchas, and `docs/decisions/` for the reasoning behind each feature.
