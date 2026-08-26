# EAF bundle expansion and the MCP Klaar + EAF filter

**Date:** 2026-08-12 — builds on [2026-08-12-eaf-batch-download.md](2026-08-12-eaf-batch-download.md)

## Why

1. `Download Selected (EAF)` shipped only annotation files; engineers wanted one folder per take with annotation, subtitles, the post-processed animation and a reference video.
2. With ~8,200 CC takes and ~670 both-Klaar, finding the ones that actually have an EAF on disk meant scrolling.

## Facts (measured)

| Asset | Avg size |
|---|---|
| CC FBX / GLB | 3.9 MB / 413 KB |
| Post-processed FBX / GLB | 3.8 MB / 290 KB |
| Mini MP4 (`blackmagic_mini`) | 0.3 MB |
| Full Blackmagic MP4 | ~100 MB |
| RIGHT MKV (`razer_mkv`) | 8.6 MB |

Post-processed coverage (682) tracks the both-Klaar set (670): a take reaches Klaar *because* it was post-processed. Mini MP4 coverage only starts 2026-05-11, so older takes fall back to the MKV (~10 MB/take average in practice).

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Animation in bundle | Post-processed FBX + GLB only | Klaar takes; the PP version is the finished artefact |
| Video in bundle | Mini MP4, RIGHT MKV fallback, never the full MP4 | 0.3 MB vs 100 MB; 100 takes ≈ 0.5–1 GB instead of 5 GB |
| Batch ceiling | Hard cap of **100 takes**, enforced server-side before any work (HTTP 400) | The ZIP is built on temp disk before streaming; ~1 GB at STORE throughput stays inside Apache's 300 s timeout |
| Missing PP file or video | Bundle the take anyway; name the gap in `MISSING_ASSETS.txt` | "Klaar" and "file exists on disk" are different facts |
| Missing take-level EAF | Still excludes the take | Unchanged |
| Filter options | Only `all` and `klaar_eaf` | The one case asked for |
| Filter mechanics | SQL for both-Klaar ids, then `EafLocator::hasEaf()` per candidate, cached per request | EAF existence is a filesystem fact the DB does not record |
| Empty eligible set | Predicate that matches nothing | Not one that matches everything |

## Known follow-ups (deliberately not done)

- The button is still called `Download Selected (EAF)` although it ships more than EAFs.
- `getMcpStatusForFiles()` and `getGlossesForFiles()` each scan `matched_transcriptions`; merging them would halve per-page-load cost.

Code: `services/TakeBundleLocator.php` (composes `EafLocator`; MKV name injected by caller so the class stays DB-free), `MocapFile::klaarFileIdsWithEaf()` / `mcpFilterPredicate()`, `?mcp=` query param threaded through `FileListController` and pagination links.
