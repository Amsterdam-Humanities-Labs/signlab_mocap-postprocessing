# CLAUDE.md

Guidance for Claude Code when working in this repository. For setup, external dependencies and portability see `README.md`; for the *why* behind features see `docs/decisions/`.

## What this is

PHP/MySQL web app (`/animMIDI/` on signcollect.nl) for managing motion-capture animation files: engineers download original FBX captures, post-process them in Unreal, upload them back. Includes a BabylonJS preview/compare viewer and batch download of EAF annotation bundles.

## Layout

```
public/            entry points (index, upload, download, download-eaf, stats, delegate, *-api)
app/auth.php       cookie auth: requireAuth() for pages, requireAuthApi() for JSON endpoints, isAdmin()
app/config/        Database.php (PDO singleton, reads mysql_config.php) · Paths.php (reads paths.php)
app/controllers/   FileList · Upload · Download · Delegate · Stats
app/models/        MocapFile (vicon_files queries) · Assignment · Stats
app/services/      EafLocator · TakeBundleLocator · PathSafety trait — filesystem-only, fixture-tested
app/views/         file-list · upload-form/result · delegate · stats · partials/header
babyloncc/dist/    the served viewer (.htaccess exception); index.html and
                   compare.html are hand-written, no build step
migrations/        numbered SQL, applied in order
tests/             PHPUnit (php vendor/bin/phpunit)
paths.php          all storage locations, documented per key; override in paths.local.php
```

## Rules

- **Query `vicon_files WHERE subdirectory = 'unreal/CC'`, never `mocap_files`** (dead table; `database/schema.sql` is a leftover).
- **Never hardcode a `/web/…` or `/mnt/…` path.** Add a key to `paths.php` and use `Paths::dir('key')` / `Paths::toUrl($disk)`.
- **`blackamgic_filesMini` is misspelled on disk on purpose** (symlink to `/mnt/bigstorage/blackmagic_filesMini/`). Do not "fix" it.
- **Never put credentials in tracked files** — not in docs, not in example commands. Use `$DB_PASSWORD` in examples. `mysql_config.php` and `paths.local.php` are gitignored.
- Every filename that reaches the filesystem goes through `PathSafety::safeName()` + `isWithin()`; DB-sourced names are still untrusted.
- Auth is the portal's `sessionObject` cookie (`userId`, `username`, `role`, `expiresAt`). Admins are hardcoded in `app/auth.php`. Non-admins only see dates assigned to them in `capture_assignments`; enforce in the query, not the view.
- **Edit `babyloncc/dist/*.html` directly.** They are hand-written and load
  BabylonJS from `cdn.babylonjs.com`; there is no build step (the unused Vite
  app that used to sit next to `dist/` was removed in 2026-09). GLBs in `dist/`
  need mode 644.
- The `/animMIDI/` URL prefix is assumed by `.htaccess` and the viewer links in `file-list.php`.

## Database

`admin_gebarenoverleg`, shared with the signCollect suite. This app owns only `capture_assignments`, `download_logs`, and the `is_pp`/`filename_pp`/`datetime_pp`/`review_status`/`comment`/`comment_by` columns on `vicon_files`. Everything else (`vicon_files` itself, `sentences`, `matched_transcriptions`, `users`, `form_data`, `labels`, `_pp_*`, `_all_sent`) is owned elsewhere.

Filename `M20260113_9959_260319_0.fbx` = `{broadcast_name}_{capture_date_YYMMDD}_{take}`.
Capture date = `SUBSTRING_INDEX(capture_id, '/', 1)`.
Broadcast → sentence chain: `vicon_files.filename` → `matched_transcriptions.m_file` (`+'.wav'`, `zOg='zin'`) → `sentences.ID` (`CAST(m_transcription AS UNSIGNED)`).

### "MCP Klaar" — two traps

`MocapFile::isKlaar()` = `sentences.mcp_status_postprocessing = '1'` **AND** `sentences.mcp_status_tijd_annotatie = 'Klaar'`.

1. The postprocessing column stores the dropdown **value** (`1` = Klaar, `2` = Check nodig), not the word.
2. `mcp_status_tijd_annotatie` is the **Gloss** field in `zinnen.html`. The separate `mcp_status_tijd_annotatie_gvg` is deliberately **not** part of the gate.

The "MCP Klaar+EAF" filter additionally requires a take-level `.eaf` on disk (`EafLocator::hasEaf()`); a broadcast-level EAF never counts.

## Viewer retargeting (do not "improve")

Animation GLBs are in **metres**; the Palmer avatar is in **centimetres**. The viewers apply a **fixed ×100** to every position channel (`POS_SCALE = 100`) and match TransformNode names. Do not replace it with a runtime-detected ratio: frame 0 is whatever pose the actor was in, so detection drifted to 102–108 and pushed eyes ~6 mm out of their sockets. Eye bones (`cc_base_l_eye` / `r_eye`) are the canary — spot-check them in the Inspector (`i` key) after any retarget change. Animation GLBs have no skeleton or morph targets; they animate TransformNodes only. Details: `docs/decisions/2026-06-19-babyloncc-viewer-upgrade.md`.

## Tests

`php vendor/bin/phpunit`. Five tests are stale and fail on every branch (`DatabaseTest::testCannotUnserializeDatabase`, four in `MocapFileTest` written for `mocap_files`); do not count them as regressions. Services are tested against temp fixture directories — inject dirs via constructor args, do not touch production paths in tests.
