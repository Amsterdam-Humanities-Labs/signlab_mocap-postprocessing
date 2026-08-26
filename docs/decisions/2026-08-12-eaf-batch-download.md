# Batch EAF/SRT download gated on MCP Klaar

**Date:** 2026-08-12

## Why

Engineers need the ELAN annotation (`.eaf`) and subtitle sidecars (`.srt`) for selected takes without digging through `/web/zin/eaf/zin/`. Annotations are only meaningful once a take has cleared both motion-capture status gates, so the download enforces the gate instead of trusting the user to.

## Facts

- The EAF directory (`eaf_dir` in `paths.php`) holds **take-level** files (`M20240925_1858_260319_0.eaf`, 444 at the time), **broadcast-level** files (`M20240925_1858.eaf`, 4727), SRT sidecars (`_Nederlands`, `_Gebaar-voor-gebaar`, `_Signbank_ID_glossen`, `_Handvorm`) and historical `*_backup_YYYYMMDD_HHMMSS.srt` files.
- MCP statuses live on `sentences` in the same database; the chain is `vicon_files.filename → matched_transcriptions.m_file (+'.wav', zOg='zin') → sentences.ID`, as in `MocapFile::getGlossesForFiles()`.
- "Klaar" = `mcp_status_postprocessing = '1'` **and** `mcp_status_tijd_annotatie = 'Klaar'`. The first column stores the dropdown's *value* (`1` = Klaar, `2` = Check nodig), not the label. See CLAUDE.md.
- At the time: 670 both-Klaar CC takes, 402 of which had a take-level EAF.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Status source | Direct DB join, not the `getZinnen.php` HTTP API | Same database; no auth hop, no network failure mode |
| ZIP contents | EAF **and** all non-backup SRTs | The subtitle tracks belong with the annotation |
| Missing take-level EAF | Skip the take; **never** fall back to the broadcast-level EAF | A broadcast EAF is not time-aligned to the take — silently wrong beats missing |
| Backup SRTs | Excluded by `_backup_` in the name | Historical copies |
| Checkbox scope | Every row in every view | EAFs are wanted after post-processing too |
| Non-Klaar selection | Badge in the list **and** re-check at download time | The client controls which IDs it posts |
| All takes skipped | Still return a ZIP containing only the manifests | An explanation beats an empty error page |
| `mcp_status_tijd_annotatie_gvg` | Not part of the gate | Third status the user did not ask for |

Manifests in the ZIP root: `SKIPPED_NOT_KLAAR.txt` (with the actual status values), `MISSING_EAF.txt`. One `download_logs` row per included take, `download_type = 'eaf'` (migration `006`).

Code: `services/EafLocator.php` (filesystem only, unit-tested on fixtures), `MocapFile::getMcpStatusForFiles()`, `DownloadController::downloadEaf()`, `public/download-eaf.php`.
