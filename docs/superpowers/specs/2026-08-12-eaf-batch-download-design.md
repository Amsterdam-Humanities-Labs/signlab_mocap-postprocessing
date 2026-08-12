# Batch EAF/SRT Download — Design

**Date:** 2026-08-12
**Status:** Approved, ready for planning

## Problem

Engineers working in the animMIDI file list need the ELAN annotation files (`.eaf`) and their
sidecar subtitle files (`.srt`) for takes they select, without hunting through
`/web/zin/eaf/zin/` by hand. Annotation files are only meaningful once a take has cleared both
motion-capture status gates, so the download must enforce those gates rather than leaving it to
the user to remember.

## Background

### Where the annotation files live

`/web/zin/eaf/zin/` holds three naming forms:

| Form | Example | Count |
|---|---|---|
| Take-level EAF | `M20240925_1858_260319_0.eaf` | 444 |
| Broadcast-level EAF | `M20240925_1858.eaf` | 4727 |
| Sidecar SRT | `M20240925_1858_260319_0_Nederlands.srt` | many |

Take-level names match `vicon_files.filename` exactly, minus the `.fbx` extension. Known SRT
suffixes are `_Nederlands`, `_Gebaar-voor-gebaar`, `_Signbank_ID_glossen`, and `_Handvorm`. The
directory also contains historical `*_backup_YYYYMMDD_HHMMSS.srt` files that must never be
included.

### Where the statuses live

The MCP statuses shown on `https://signcollect.nl/zin/zinnen.html` are columns on
`admin_gebarenoverleg.sentences` — the same database animMIDI already uses. There is no need to
call `getZinnen.php` over HTTP: a direct join is one query, needs no auth hop, and cannot fail
because of a network hiccup.

The link from a mocap file to its sentence follows the chain already used by
`MocapFile::getGlossesForFiles()`:

```
vicon_files.filename  ──"M20240925_1858" + ".wav"──▶  matched_transcriptions.m_file
matched_transcriptions.m_transcription (zOg='zin')  ──▶  sentences.ID
```

"Klaar" on each status means:

| Status | Column | Value meaning "Klaar" |
|---|---|---|
| MCP postprocessing | `sentences.mcp_status_postprocessing` | `'1'` |
| MCP tijd annotatie | `sentences.mcp_status_tijd_annotatie` | `'Klaar'` |

The postprocessing column stores the dropdown's *value*, not its label — `zinnen.html` renders
`<option value="1">Klaar</option>`. This asymmetry is a trap and must be commented in the code.

### Current numbers

- 670 `unreal/CC` takes are both-Klaar.
- 402 of those have a take-level EAF; the other 268 have only a broadcast-level one.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Status source | Direct DB join, not the HTTP API | Same database; fewer moving parts |
| ZIP contents | EAF **and** all non-backup sidecar SRTs | Users want the subtitle tracks alongside the annotation |
| Missing take-level EAF | Skip; do **not** fall back to broadcast-level | A broadcast EAF is not time-aligned to the take, so it would be silently wrong |
| Checkbox scope | Every row, all three views | EAFs are wanted *after* postprocessing, so the processed view needs selection too |
| Non-Klaar selection | Badge in the list **and** skip at download time | Visible before clicking, enforced server-side regardless |

## Components

### `MocapFile::getMcpStatusForFiles(array $fileIds): array`

Mirrors `getGlossesForFiles()`: same join chain, same `.wav` suffix match, same `zOg='zin'`
filter, same `CAST(mt.m_transcription AS UNSIGNED)` guard.

Returns `file_id => ['pp' => ?string, 'ta' => ?string, 'klaar' => bool]`. Files with no matching
sentence are absent from the map, which callers treat as not-Klaar. `klaar` is
`pp === '1' && ta === 'Klaar'`.

Depends on: the PDO connection only. Used by both the file list (badges) and the download
controller (enforcement).

### `EafLocator` — `app/services/EafLocator.php`

Pure filesystem logic, no database and no HTTP, so it can be unit-tested on a fixture directory.

```php
public function __construct(string $eafDir = '/web/zin/eaf/zin/')
public function filesForTake(string $takeBasename): array  // list of absolute paths
```

Given `M20240925_1858_260319_0`, returns `<base>.eaf` if it exists plus every
`<base>_*.srt` that is not a backup, each verified to resolve inside `$eafDir`. Returns an empty
array when the take-level EAF is absent — the caller decides what that means. Backup exclusion
matches `_backup_` in the filename.

The take basename is sanitised the same way `DownloadController::safeName()` sanitises
filenames, and the resolved path is checked with the same containment logic, so a crafted
`filename` in the database cannot escape the directory.

### `DownloadController::downloadEaf(array $fileIds, array $currentUser): void`

1. Load the files via `getFilesByIds()`, the statuses via `getMcpStatusForFiles()`.
2. For each file: not Klaar → record in `SKIPPED_NOT_KLAAR.txt` with its actual `pp`/`ta` values
   and continue. No EAF found → record in `MISSING_EAF.txt` and continue.
3. Otherwise add every located file under a per-take folder: `M20240925_1858_260319_0/…`.
4. Write the manifests into the ZIP root when non-empty, mirroring the existing
   `MISSING_FILES.txt` / `MISSING_VIDEOS.txt` convention.
5. Log one `download_logs` row per *included* take with `download_type = 'eaf'`.
6. Stream as `eaf_files_<Y-m-d_H-i-s>.zip`, then clean up the temp directory.

The Klaar check is repeated here even though the UI badges it, because the client controls which
IDs it posts.

If every selected take is skipped, the ZIP still downloads containing only the manifests — the
user gets an explanation rather than an empty error page.

### `public/download-eaf.php`

Mirrors `download.php`: `requireAuth()`, then `$controller->downloadEaf(explode(',', $_GET['bulk']), $currentUser)`.
Redirects to `index.php` when `bulk` is absent.

### `app/views/file-list.php`

- **Checkbox column**: remove the `$selectedStatus` guard on the `<th>`/`<td>` and the
  `$file['is_pp'] == 0` guard on the input, so every row is selectable in every view. The
  `Download Selected` button keeps its current `unprocessed`/`all` gating.
- **New button** `Download Selected (EAF)` next to `Download Selected`, visible in all views,
  sharing the disabled-when-nothing-checked behaviour via `updateDownloadButton()`.
- **Status badge** per row, from the status map passed in by `FileListController`: green `Klaar`
  when both gates pass, otherwise a grey badge naming what is blocking (`PP: niet klaar`,
  `TA: niet klaar`, or both). Reuses the existing label-badge markup.
- **`downloadSelectedEaf()`** sends the checked IDs to `download-eaf.php?bulk=…`, matching
  `downloadSelected()`.

### `FileListController::index()`

Add one call to `getMcpStatusForFiles($allFileIds)` alongside the existing
`getGlossesForFiles($allFileIds)` and pass the result to the view as `$fileMcpStatus`.

## Data Flow

```
user ticks rows ─▶ downloadSelectedEaf() ─▶ download-eaf.php?bulk=1,2,3
                                              │
                                              ├─ MocapFile::getFilesByIds()
                                              ├─ MocapFile::getMcpStatusForFiles()  ──▶ Klaar?
                                              └─ EafLocator::filesForTake()         ──▶ paths
                                                        │
                                                        ▼
                                       ZIP: <take>/…eaf, <take>/…srt
                                            SKIPPED_NOT_KLAAR.txt
                                            MISSING_EAF.txt
```

## Error Handling

| Condition | Behaviour |
|---|---|
| No IDs given | Redirect to `index.php` |
| No files found for the IDs | 404, `"No files found"` (matches `downloadBulk()`) |
| Take not both-Klaar | Skipped, listed in `SKIPPED_NOT_KLAAR.txt` with its actual statuses |
| Take-level EAF absent | Skipped, listed in `MISSING_EAF.txt` |
| `/web/zin/eaf/zin/` unreadable | Treated as "no files found" for every take; all takes land in `MISSING_EAF.txt` |
| ZIP creation fails | 500, `"Failed to create ZIP file"` (matches `downloadBulk()`) |

## Testing

PHPUnit, against the existing suite:

- `EafLocator`: finds EAF + SRTs on a fixture directory; excludes `*_backup_*.srt`; returns empty
  when the EAF is missing; rejects a basename containing `../`.
- Klaar predicate: `('1', 'Klaar')` passes; `('1', null)`, `(null, 'Klaar')`, `('2', 'Klaar')`,
  and a file absent from the map all fail.

ZIP assembly and streaming stay untested, consistent with the existing `downloadBulk()`.

## Out of Scope

- Broadcast-level EAF fallback for the 268 takes that lack a take-level file.
- Filtering the file list by Klaar status.
- `mcp_status_tijd_annotatie_gvg`, which is a third status the user did not ask to gate on.
