# EAF Bundle Expansion and MCP Filter — Design

**Date:** 2026-08-12
**Status:** Approved, ready for planning
**Builds on:** [2026-08-12-eaf-batch-download-design.md](2026-08-12-eaf-batch-download-design.md)

## Problem

Two follow-ups to the batch EAF download now on the branch:

1. `Download Selected (EAF)` currently ships only annotation files. Engineers want one archive per take carrying everything they need to work with it — annotation, subtitles, the post-processed animation, and a reference video — instead of downloading twice and merging by hand.
2. Nothing in the file list narrows the view to takes that are actually ready. With 8,167 CC takes and 670 both-Klaar, finding the ones that have an EAF on disk means scrolling.

## Background

### Assets per take, measured

| Asset | Location | Count | Avg size |
|---|---|---|---|
| CC FBX | `/web/gebarenoverleg_media/fbx/CC/` | 8,167 | 3.9 MB |
| CC GLB | same | 8,167 | 413 KB |
| Post-processed FBX | `/web/gebarenoverleg_media/fbx/post_processed/` | 682 | 3.8 MB |
| Post-processed GLB | same | 680 | 290 KB |
| Mini MP4 | `/web/gebarenoverleg_media/blackamgic_filesMini/<capture_date>/` | 1,488 | 0.3 MB |
| Full Blackmagic MP4 | `/web/gebarenoverleg_media/studioFiles/blackmagic_files/<capture_date>/` | not counted — unused here | 100 MB |
| RIGHT MKV | `/mnt/bigstorage/razerFiles/` | not counted — keyed by capture, not take | 8.6 MB |

`blackamgic_filesMini` is misspelled on disk (a symlink to `/mnt/bigstorage/blackmagic_filesMini/`). The code matches the misspelling deliberately.

Post-processed coverage (682 files) tracks the both-Klaar set (670) closely, which is expected: a take reaches Klaar *because* it was post-processed.

### Decisions

| Decision | Choice | Rationale |
|---|---|---|
| FBX/GLB in the bundle | Post-processed only | These are Klaar takes; the PP version is the finished artefact |
| Video in the bundle | Mini MP4, RIGHT MKV fallback | 0.3 MB vs 100 MB for the full MP4; 100 takes stays near 440 MB instead of 5 GB |
| Filter options | `all` and `klaar_eaf` only | The one case actually asked for; nothing speculative to maintain |
| Batch ceiling | Hard cap at 100 takes, server-side | The ZIP is built on temp disk before streaming, so an unbounded batch is a real failure, not a theoretical one |
| Missing PP/video assets | Bundle the take anyway, name the gaps | Klaar and "PP file exists on disk" are different facts |
| Missing take-level EAF | Still excludes the take | Unchanged from the original design |

Per-take bundle size is roughly 3.8 MB + 290 KB + 300 KB + annotation ≈ **4.4 MB**, so the 100-take cap corresponds to about 440 MB.

## Components

### `TakeBundleLocator` — `app/services/TakeBundleLocator.php` (new)

Composes the existing `EafLocator` and adds the animation and video assets. Filesystem-pure: no database, no HTTP, so it unit-tests against a fixture directory exactly as `EafLocator` does. The MKV filename is passed in by the caller (it comes from `MocapFile::getRightVideos()`), which keeps the database out of this class.

```php
public function __construct(
    EafLocator $eafLocator,
    string $ppDir   = '/web/gebarenoverleg_media/fbx/post_processed/',
    string $miniDir = '/web/gebarenoverleg_media/blackamgic_filesMini/',
    string $mkvDir  = '/mnt/bigstorage/razerFiles/'
)

public function bundleForTake(array $ctx): array
```

`$ctx` keys: `take` (basename, no extension), `pp_filename` (`vicon_files.filename_pp`, may be null), `capture_date` (`YYYY-MM-DD`, may be null), `mkv_name` (may be null).

Returns:

```php
[
  'files'   => [['path' => '/abs/path', 'entry' => 'name-in-zip'], …],
  'missing' => ['post-processed .fbx', 'post-processed .glb', 'reference video'],
]
```

Resolution rules:

- **Annotation:** delegate to `EafLocator::filesForTake($take)`. If it returns empty, `bundleForTake()` returns `['files' => [], 'missing' => []]` — the caller treats an empty `files` list as "exclude this take", preserving the existing no-EAF-means-skip rule.
- **Post-processed FBX:** `$ppDir . basename($pp_filename)`, when `pp_filename` is non-empty and the file exists.
- **Post-processed GLB:** the same basename with `.fbx` replaced by `.glb`.
- **Video:** `$miniDir . $capture_date . '/' . $take . '.mp4'` if it exists; otherwise `$mkvDir . $mkv_name`; otherwise record `reference video` as missing.

Every path is sanitised and containment-checked the way `EafLocator` and `DownloadController` already do — basename reduction, a strict character class, and a `realpath()` prefix check against the configured directory.

ZIP entry names are `<take>/<basename>`, so one folder per take.

### `EafLocator::hasEaf(string $takeBasename): bool` (new method)

A cheap existence check for the filter, avoiding the SRT glob that `filesForTake()` performs. Same sanitisation and containment as `filesForTake()`.

### `MocapFile` — filter support

- `klaarFileIdsWithEaf(): array` — one query for the both-Klaar file IDs and their filenames (reusing the join in `getMcpStatusForFiles()`), then `EafLocator::hasEaf()` per candidate. Returns a list of `vicon_files.id`. Cached per instance, mirroring `broadcastNameCache`, so the grouped query and the count query don't both pay for it.
- `mcpFilterPredicate(string $filenameCol, ?string $mcpFilter): array` — returns `[$sqlFragment, $params]`, exactly the shape `labelFilterPredicate()` returns. `all` or null yields an empty predicate; `klaar_eaf` yields `vf.id IN (…)`. An empty eligible set yields a predicate that matches nothing, not one that matches everything.

The filter must resolve on disk rather than in SQL because EAF existence is a filesystem fact the database does not record.

### `DownloadController::downloadEaf()` — changes

- Build a `TakeBundleLocator` and call `bundleForTake()` per take instead of `EafLocator::filesForTake()`.
- Pre-fetch MKV fallbacks for all captures in one query via the existing `getRightVideos()`, as `downloadBulk()` already does.
- Accumulate per-take missing assets into a new `MISSING_ASSETS.txt` manifest, alongside the existing `SKIPPED_NOT_KLAAR.txt` and `MISSING_EAF.txt`.
- Enforce the cap before doing any work: more than 100 file IDs returns HTTP 400 with a message naming the count and the limit.

The Klaar gate, the `addFile()`/`deleteName()` rollback, and the `close()` check all stay as they are.

### File list — filter UI

`app/views/file-list.php` gains a dropdown beside the existing filters:

| Label | Value |
|---|---|
| Alle MCP statussen | `all` |
| MCP Klaar + EAF beschikbaar | `klaar_eaf` |

`FileListController::index()` reads `$_GET['mcp']`, defaults to `all`, and threads it into the model calls. The existing pagination links must carry `mcp` forward, as they already do for `label` and `search`.

## Data Flow

```
filter:   ?mcp=klaar_eaf
            └─▶ MocapFile::klaarFileIdsWithEaf()
                   ├─ SQL: both-Klaar ids + filenames
                   └─ EafLocator::hasEaf() per candidate   ──▶ id set ──▶ mcpFilterPredicate()

download: download-eaf.php?bulk=… ──▶ cap check (≤100)
            ├─ getFilesByIds() / getMcpStatusForFiles()  ──▶ Klaar gate
            ├─ getRightVideos()                          ──▶ mkv fallbacks
            └─ TakeBundleLocator::bundleForTake()
                   ├─ EafLocator::filesForTake()   .eaf + .srt
                   ├─ post_processed/              .fbx + .glb
                   └─ Mini .mp4 → RIGHT .mkv
                          │
                          ▼
              ZIP: <take>/…  +  SKIPPED_NOT_KLAAR.txt
                                MISSING_EAF.txt
                                MISSING_ASSETS.txt
```

## Error Handling

| Condition | Behaviour |
|---|---|
| More than 100 takes selected | HTTP 400 before any work, naming the count and the limit |
| Take not both-Klaar | Skipped, listed in `SKIPPED_NOT_KLAAR.txt` (unchanged) |
| Take-level EAF absent | Skipped, listed in `MISSING_EAF.txt` (unchanged) |
| PP FBX/GLB or video absent | Take still bundled; the gap named in `MISSING_ASSETS.txt` |
| `filename_pp` null or empty | Both PP assets recorded as missing; no path is constructed |
| Filter matches nothing | Empty file list, the usual "No files found" message |
| `ZipArchive::close()` fails | HTTP 500 with `getStatusString()`, no log rows written (unchanged) |

## Testing

`TakeBundleLocatorTest`, against fixture directories:

- All five asset types present → all appear, entries prefixed with the take folder.
- `pp_filename` differs from the take name → the PP files still resolve, by `filename_pp`.
- PP FBX/GLB absent → take still bundled, both named in `missing`.
- Mini MP4 present → chosen over the MKV.
- No Mini MP4, MKV name given → MKV used.
- Neither → `reference video` in `missing`.
- No take-level EAF → empty `files`, so the caller excludes the take.
- Traversal attempt in `take` or `pp_filename` → rejected.

`EafLocatorTest` gains a `hasEaf()` case: true when the EAF exists, false when only the broadcast-level file or an SRT does.

The cap is verified at the controller boundary. ZIP assembly and streaming stay untested, consistent with the existing download methods.

## Out of Scope

- Renaming the `Download Selected (EAF)` button, though it now ships more than EAFs and no longer reads as distinct from `Download Selected`. Flagged for the user; deliberately untouched.
- Merging `getMcpStatusForFiles()` into `getGlossesForFiles()` to halve the per-page-load scans of `matched_transcriptions` — a known, separately-tracked follow-up.
- Any use of the full 100 MB Blackmagic MP4.
- Filtering by MCP status alone (without the EAF-existence check).
