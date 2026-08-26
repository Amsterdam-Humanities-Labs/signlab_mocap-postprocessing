# BabylonCC viewer upgrade

**Date:** 2026-06-19

## Why

Bring the upstream [J-Andersen-UvA/BabylonCC](https://github.com/J-Andersen-UvA/BabylonCC) improvements — studio lighting, shadows, backdrop, idle animation, playbar, camera focus, avatar picker — into the embedded preview viewer, while keeping the iframe URL contract that `app/views/file-list.php` depends on: `?anim=`, `?video=`, `?scale-skeletal-anim=`.

## Facts established

- New avatar `PalmerPolo1024uastc.glb` is in **centimetres** with the same rig as old Palmer (pelvis rest Z 100.35, eye offsets ≈ 7.5, armature scale 0.01, baked −90° X root).
- Production animation GLBs are in **metres** (pelvis 1.0035, eye 0.0775), translation/rotation channels only, **no morph targets**.
- Ratio is therefore exactly 100 → `POS_SCALE = 100`, name-based retargeting and the 0.01→1 scale reset carry over unchanged. See CLAUDE.md *Retargeting* for why this is fixed, not detected.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Avatar picker | Load the stored default immediately; picker is an on-demand overlay | Upstream's blocking pre-scene picker would freeze the preview inside the iframe |
| Backdrop vs video background | `Backdrop.glb` always loaded (it is the shadow floor); with `?video=` the video plane sits in front and the backdrop wall is hidden | Both features kept; floor keeps the shadow |
| Facial / morph pipeline | Out of scope | No morph data in production GLBs |
| Avatars shipped | Palmer (verified) and `glassesGuySignLab.glb` (unverified second option) | Add more by dropping a GLB in `dist/` and extending `AVATAR_CONFIG` |
| `compare.html` | Untouched | Separate standalone page |

## Note on the build

This design originally targeted a single CDN-based HTML file. The viewer that shipped is a Vite + TypeScript build from `babyloncc/src/`; `dist/index.html` is generated (`npm run build`) and committed. Only `dist/compare.html` remains hand-written.
