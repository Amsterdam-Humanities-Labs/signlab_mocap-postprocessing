# Statistics page and per-file comments

**Date:** 2026-04-10

## Why

`download_logs` already recorded every download, upload and status change, but nobody could see it. Engineers also wanted a place to leave a note on a take without leaving the file list.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Comment storage | `vicon_files.comment` + `comment_by` columns | One comment per take is enough; no history needed |
| Comment UI | Inline textarea in the list, saved on blur/Enter via `update-comment.php` | No page reload, no separate edit screen |
| Stats access | Every logged-in user | Nothing sensitive; useful for engineers to see their own progress |
| Charts | Chart.js from CDN | No build step in this app |
| Stats shape | Summary cards, 30-day timeline (downloads / uploads / processed), paginated log filterable by user | Answers "who did what, when" without further filters |

Migration: `005_vicon_files_comment.sql`. Code: `StatsController`, `models/Stats.php`, `views/stats.php`.
