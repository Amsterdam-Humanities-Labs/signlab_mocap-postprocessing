# BabylonCC Viewer Upgrade — Design

Date: 2026-06-19

## Goal

Bring the upstream [J-Andersen-UvA/BabylonCC](https://github.com/J-Andersen-UvA/BabylonCC)
changelog (rendering, lighting, shadows, backdrop, idle animation, playbar,
camera focus, avatar picker) into our embedded preview viewer
`babyloncc/dist/index.html`, while:

- keeping it a **single self-contained HTML file** (vanilla JS, CDN BabylonJS,
  **no build step**) per the project's `CLAUDE.md` rule;
- **preserving** our custom video-background feature and the iframe URL contract
  `?anim=`, `?video=`, `?scale-skeletal-anim=` that `app/views/file-list.php:261`
  depends on;
- switching the avatar to `/web/zin/PalmerPolo1024uastc.glb`.

Upstream is a React/Vite/TypeScript app. We are **porting features**, not adopting
the build pipeline.

## Key facts established during investigation

- **New avatar is centimeters, same rig as old Palmer.** `PalmerPolo1024uastc.glb`:
  pelvis rest Z = `100.35`, eye local offsets ~`7.5`, armature scale `0.01`, baked
  `-90°X` root bone. Bone names match (`pelvis`, `head`, `spine_05`, `hand_l/r`,
  `cc_base_l_eye/r_eye`).
- **Production anim GLBs are meters.** Sample `Auris_260305_0.glb`: pelvis `1.0035`,
  l_eye `0.0775`, root `-90°X`, only `translation`/`rotation` channels — **zero
  morph/weights channels**.
- Therefore: ratio is exactly **100**, so the proven `POS_SCALE = 100`, the
  `scale 0.01 → 1` reset, name-based retargeting, and **no avatarRoot rotation**
  all carry over unchanged. The new avatar is a clean drop-in for retargeting.
- There is **no facial data** in production and no `*_shapekeys.json` served, so
  facial morph animation is **out of scope** (deferred).

## Architecture

One file: `babyloncc/dist/index.html`. Structure (top → bottom):

1. **Config block** — JS constant objects mirroring upstream configs so they stay
   tunable without a build: `RENDER_CONFIG` (hardware scaling, ACES tone
   mapping, exposure/contrast, IBL, sun, hemi, 3-point studio lights, shadows),
   `CAMERA_CONFIG` (focus bone `spine_05`, orbit), `MESH_CONFIG` (exclusions +
   material rules), `PLAYER_CONTROLS_CONFIG` (speed levels, hand bones),
   `AVATAR_CONFIG` (avatar list + idle path).
2. **Scene/engine setup** — engine, scene, `createDefaultCameraOrLight` for the
   ArcRotateCamera, `i`-key inspector toggle, resize.
3. **Lighting rig** — `setupLighting()`: environment IBL from
   `environment.envbin`, directional sun, hemispheric fill, three SpotLights
   (key/fill/rim), `ShadowGenerator` (PCF, high quality).
4. **Backdrop** — `loadBackdrop()`: load `Backdrop.glb`, matte-grey PBR material,
   `receiveShadows = true`.
5. **Avatar** — `loadAvatar(cfg)`: import selected GLB under an `Avatar`
   TransformNode, reset `0.01` scale, apply `MESH_CONFIG` exclusions + PBR
   material rules, register every enabled mesh as a shadow caster.
6. **Retarget core** — UNCHANGED from current viewer: `POS_SCALE = 100`,
   name/bone/linked-node matching, `MorphTarget` tracks skipped, `*scaleMult`
   from `?scale-skeletal-anim=`.
7. **Idle** — load `palmer_Idle.glb`, retarget, loop-play until a `?anim=` clip
   loads; `stopIdle()` on user clip load.
8. **Animation loader** — `loadAnimUrl()` / `loadAnimFile()` as today (retarget +
   play), now also stopping idle and feeding the playbar state.
9. **Video background** — UNCHANGED feature: when `?video=` set, build the video
   plane in front of the backdrop as the mocap reference.
10. **Camera** — focus on `spine_05` (`CAMERA_CONFIG`), `J` recenter key.
11. **Playbar UI** — vanilla DOM: play/pause, frame scrubber, speed cycle,
    L/R hand-focus buttons (lock camera to `hand_l`/`hand_r`, follow during play),
    keyboard shortcuts (Space / S / ←/→). Polls a `getState()` shim.
12. **Avatar picker UI** — on-demand overlay (see below) + "Change Avatar" button +
    `localStorage` default.
13. **Backdrop color picker UI** — swatches + color input that recolor the
    backdrop material live.

## Deviations from upstream (deliberate, for embedded use)

- **Non-blocking avatar picker.** Upstream shows a pre-scene selection screen on
  first visit. In our iframe that would freeze the preview on load. Instead: on
  entry we load the stored default (or the first configured avatar, Palmer)
  **immediately**; the picker is an overlay opened via the "Change Avatar"
  button, with a "Keep as default" checkbox writing `localStorage`.
- **No facial/morph pipeline** (deferred — no production data).
- **Avatar list**: `PalmerPolo1024uastc.glb` (verified production default) and
  `glassesGuySignLab.glb` (best-effort second option, retarget unverified). More
  avatars are added by dropping a GLB in `dist/` and appending an `AVATAR_CONFIG`
  entry.

## Video background vs Backdrop.glb (the one conflict)

Both can be "the background". Resolution: Backdrop.glb is **always** loaded (it
provides the floor that catches the avatar's shadow). When `?video=` is present,
the video plane is positioned in front of the backdrop wall as the reference
footage. If they visually clash, the video takes priority (backdrop wall hidden,
floor kept). Final positions tuned via Playwright against a real capture.

## Assets to stage into `babyloncc/dist/` (chmod 644)

| Asset | Source | Notes |
|---|---|---|
| `PalmerPolo1024uastc.glb` | `/web/zin/` | new default avatar (34 MB) |
| `glassesGuySignLab.glb` | `/web/zin/` | optional 2nd avatar |
| `palmer_Idle.glb` | upstream `files/anims/idle/` | idle clip |
| `Backdrop.glb` | already present (untracked) | shadow floor |
| `environment.envbin` | already present (untracked) | IBL |
| `thumbnails/Palmer.png`, `thumbnails/placeholder.png` | upstream `public/thumbnails/` | picker thumbs |

## Out of scope

- Facial shapekey / morph JSON pipeline.
- `compare.html` (unchanged this round).
- Adopting the Vite/TS build.
- Mitt/Digits avatars (GLBs not available).

## Verification

Playwright against the live preview with a real capture:
1. Avatar loads upright, correctly scaled, eyes seated (the `CLAUDE.md` "eye
   canary" via inspector spot-check).
2. Animation plays and retargets (matched ≫ miss in console).
3. Shadows render on the backdrop; lighting looks like a studio, not the old flat
   purple.
4. Video background still appears and is positioned sensibly with the backdrop.
5. Playbar play/pause/scrub/speed and L/R hand focus work.
6. `i` inspector and `J` recenter still work.
7. Avatar auto-loads without a blocking picker; "Change Avatar" opens the overlay.
