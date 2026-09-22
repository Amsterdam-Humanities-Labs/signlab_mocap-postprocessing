# signlab_sC-Animation-PP
PHP/MySQL app where engineers download mocap FBX captures, post-process them in Unreal and upload them back.

## What it does
- Browse captures by date; filter by processing status, MCP Klaar and EAF availability.
- Download FBX (or FBX + MKV reference video as ZIP); batch-download per-take EAF/SRT bundles (MCP Klaar only, max 100).
- Upload processed FBX (drag-and-drop or ZIP), mark takes correct, comment, delegate dates to users, stats page.
- 3D preview and original-vs-processed compare in `babyloncc/dist/` (hand-written BabylonJS pages, no build step).
- Data source: `vicon_files WHERE subdirectory = 'unreal/CC'`. Conventions and traps: `CLAUDE.md`, `docs/decisions/`.

## Where it runs
| Host | Path | URL |
|---|---|---|
| core (production) | `/web/animMIDI` | https://signcollect.nl/animMIDI/ |
| dev2, dev-1 (demo) | `/web/animMIDI`, `/srv/signcollect/web/animMIDI` | |

The repo deploys as `animMIDI`; the `/animMIDI/` prefix is hardcoded in `.htaccess` and `app/views/file-list.php`.

## Status
production

## How to run / deploy
Deployed by the stack: https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack
```bash
composer dump-autoload --no-dev --optimize   # required: public/* require vendor/autoload.php
for f in migrations/*.sql; do mysql -u user -p admin_gebarenoverleg < "$f"; done
php vendor/bin/phpunit                        # 5 stale tests fail (DatabaseTest, MocapFileTest)
```
Apache maps `/animMIDI/` here; `.htaccess` rewrites all but `babyloncc/dist/` to `public/`. GLBs in `dist/` need mode 644.
Viewer: `/animMIDI/babyloncc/dist/?anim=<glb>`, compare: `.../dist/compare.html?file=<take>`. Edit `dist/*.html` directly.

## Configuration
- `mysql_config.php` (not in git): repo root, one level above `public/`; symlink to the suite's shared file.
- `paths.local.php` (optional): per-host overrides of `paths.php`; template `paths.local.php.example`.
- Paths resolve via vendored `sc_paths.php` (edit it in signcollect-lib, not here). `blackamgic_filesMini` is misspelled on disk on purpose.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `vicon_files`, `sentences`, `matched_transcriptions`, `users`, `form_data`, `labels`, `_pp_*`, `_all_sent`.
- signlab_signCollect-v2: sets the `sessionObject` login cookie. signlab_signcollect-lib: path resolver at `<root>/lib`.
- signlab_zin: produces the EAF/SRT files in `eaf_dir`.
- signlab_annotation-editors loads `/animMIDI/babyloncc/dist/PalmerPolo1024uastc.glb` and `environment.envbin`: keep `dist/`.
