# Auth, delegation and the `vicon_files` data source

**Date:** 2026-04-10

## Why

The app originally read `mocap_files`, populated by `/web/mocapDataPackage/upload.php`, which never contained every capture. `vicon_files` (filtered on `subdirectory = 'unreal/CC'`) is the authoritative list of capture files. At the same time the app had no login and no way to split work between engineers.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Data source | `vicon_files WHERE subdirectory = 'unreal/CC'` — never `mocap_files` | Complete; matches what is on disk |
| Post-processing state | Extra columns on `vicon_files` (`is_pp`, `filename_pp`, `datetime_pp`, `review_status`) rather than a join table | One row per take; simplest queries |
| Authentication | Reuse the portal's `sessionObject` cookie (JSON: `userId`, `username`, `role`, `expiresAt`); no login page here | Same domain, same `users` table, one login for the suite |
| Admins | Hardcoded usernames in `app/auth.php` (`gomer`, `jari`) | Two people; a roles table was not worth it |
| Delegation | `capture_assignments(username, capture_date)`, **one user per date** | Work is handed out per recording day; avoids two people editing the same takes |
| Enforcement | Non-admins get an `allowedDates` filter injected into every model query; no assignments ⇒ empty list | Server-side, not just hidden in the UI |
| Page vs API failure | Pages redirect to `/login.html`; API endpoints return 401 JSON | Fetch calls cannot follow a redirect usefully |

## Derived facts worth remembering

- Capture date = `SUBSTRING_INDEX(capture_id, '/', 1)`.
- Broadcast name = `SUBSTRING_INDEX(REPLACE(filename,'.fbx',''), '_', 2)`; it matches `REPLACE(matched_transcriptions.m_file,'.wav','')`.
- "Latest take per glos" dedup uses the `_YYMMDD_N` suffix.

Migrations: `001_capture_assignments.sql`, `002_vicon_files_pp_columns.sql`.
