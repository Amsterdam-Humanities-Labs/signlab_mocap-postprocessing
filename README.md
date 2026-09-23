# signlab_sC-Animation-PP
A PHP/MySQL app for mocap post-processing. Engineers download FBX recordings, clean them up in Unreal, and upload them again.

## What it does
- Browse recordings by date. Filter on processing status, MCP Klaar and whether an EAF exists.
- Download an FBX, or a ZIP with the FBX and the MKV reference video. Download the EAF/SRT files of many recordings at once (MCP Klaar only, at most 100).
- Upload processed FBX files (drag and drop, or a ZIP). Mark recordings as correct, add comments, assign dates to users, and view statistics.
- `babyloncc/dist/` holds a 3D preview and an original-versus-processed comparison. These are hand-written BabylonJS pages without a build step.
- Data comes from `vicon_files WHERE subdirectory = 'unreal/CC'`. `CLAUDE.md` lists the conventions and pitfalls.

## Where it runs
| Host | Path | URL |
|---|---|---|
| Core server | `/web/animMIDI` | https://signcollect.nl/animMIDI/ |
| Demo: dev2, dev-1 | `/web/animMIDI`, `/srv/signcollect/web/animMIDI` | |

The repo deploys as `animMIDI`. `.htaccess` and `app/views/file-list.php` hardcode the `/animMIDI/` prefix.

## Status
Production.

## How to run / deploy
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack) deploys it.
```bash
composer dump-autoload --no-dev --optimize   # required: public/* loads vendor/autoload.php
for f in migrations/*.sql; do mysql -u user -p admin_gebarenoverleg < "$f"; done
php vendor/bin/phpunit                        # 5 outdated tests fail (DatabaseTest, MocapFileTest)
```
Apache maps `/animMIDI/` to this folder. `.htaccess` sends everything except `babyloncc/dist/` to `public/`. GLB files in `dist/` need mode 644.
Viewer: `/animMIDI/babyloncc/dist/?anim=<glb>`. Comparison: `/animMIDI/babyloncc/dist/compare.html?file=<recording>`. Edit `dist/*.html` directly.

## Configuration
- `mysql_config.php` (not in git) sits in the repo root, one level above `public/`. It is a symlink to the shared file of the suite.
- `paths.local.php` (optional) overrides `paths.php` for one host. Start from `paths.local.php.example`.
- Paths resolve through `sc_paths.php`, copied from signcollect-lib. Edit it there, not here. The folder `blackamgic_filesMini` really is spelled that way on disk.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `vicon_files`, `sentences`, `matched_transcriptions`, `users`, `form_data`, `labels`, `_pp_*` and `_all_sent`.
  Writes `vicon_files` (`is_pp`, `review_status`, `comment`), `sentences.mcp_status_postprocessing`, and its own tables `capture_assignments` and `download_logs` (see `migrations/`).
- [signlab_signCollect-v2](https://github.com/Amsterdam-Humanities-Labs/signlab_signCollect-v2) sets the `sessionObject` login cookie. [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib) provides the path resolver at `<root>/lib`.
- [signlab_zin](https://github.com/Amsterdam-Humanities-Labs/signlab_zin) makes the EAF/SRT files in `eaf_dir`.
- [signlab_annotation-editors](https://github.com/Amsterdam-Humanities-Labs/signlab_annotation-editors) loads `/animMIDI/babyloncc/dist/PalmerPolo1024uastc.glb` and `environment.envbin`, so keep `dist/`.
