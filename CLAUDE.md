# CLAUDE.md

Agent hints. Setup and layout: `README.md`.

## Rules
- Query `vicon_files WHERE subdirectory = 'unreal/CC'`, never `mocap_files` (dead table; `database/schema.sql` is a leftover).
- Never hardcode a `/web/…` or `/mnt/…` path: add a key to `paths.php`, use `Paths::dir('key')` / `Paths::toUrl($disk)`.
- `blackamgic_filesMini` is misspelled on disk on purpose (symlink). Do not "fix" it.
- No credentials in tracked files; use `$DB_PASSWORD` in examples. `mysql_config.php`, `paths.local.php` are gitignored.
- Every filename that reaches the filesystem goes through `PathSafety::safeName()` + `isWithin()`, DB-sourced names too.
- Auth = portal `sessionObject` cookie; admins hardcoded in `app/auth.php`. Non-admins see only dates in `capture_assignments`: enforce in the query.
- Edit `babyloncc/dist/*.html` directly (hand-written, no build step). GLBs in `dist/` need mode 644.
- `/animMIDI/` URL prefix is assumed by `.htaccess` and viewer links in `file-list.php`.

## Database (`admin_gebarenoverleg`, shared)
- This app owns only `capture_assignments`, `download_logs` and the `is_pp`/`filename_pp`/`datetime_pp`/`review_status`/`comment`/`comment_by` columns on `vicon_files`.
- `M20260113_9959_260319_0.fbx` = `{broadcast_name}_{capture_date_YYMMDD}_{take}`; capture date = `SUBSTRING_INDEX(capture_id, '/', 1)`.
- Broadcast → sentence: `vicon_files.filename` → `matched_transcriptions.m_file` (`+'.wav'`, `zOg='zin'`) → `sentences.ID` (`CAST(m_transcription AS UNSIGNED)`).
- "MCP Klaar" (`MocapFile::isKlaar()`) = `mcp_status_postprocessing = '1'` (dropdown value, not the word) AND `mcp_status_tijd_annotatie = 'Klaar'` (the Gloss field; `_gvg` is deliberately not part of it).
- "MCP Klaar+EAF" also needs a take-level `.eaf` on disk (`EafLocator::hasEaf()`); a broadcast-level EAF never counts.

## Viewer
- Animation GLBs are in metres, the Palmer avatar in centimetres: keep the fixed `POS_SCALE = 100`; do not replace it with a runtime-detected ratio.

## Tests
- `php vendor/bin/phpunit`. Five tests always fail (`DatabaseTest::testCannotUnserializeDatabase`, four `MocapFileTest` for `mocap_files`): not regressions.
- Services are tested against temp fixture dirs injected via constructor args; never touch production paths.
