import * as BABYLON from "@babylonjs/core";

type AvatarMaps = {
  scene: BABYLON.Scene;
  nodeMap: Map<string, BABYLON.Node>;
  boneMap: Map<string, BABYLON.Bone>;
  morphMap: Map<string, BABYLON.MorphTarget[]>;
};

type SetupOptions = {
  autoStart?: boolean;
  speedRatio?: number;
  scaleMultiplier?: number;
};

type HandleFileOptions = {
  scaleMultiplier?: number;
};

declare global {
  interface Window {
    setupSkeletalAnimLoader?: (scene: BABYLON.Scene, avatarRoot: any, opts?: SetupOptions) => {
      play: (idx?: number) => void;
      stop: () => void;
      list: () => string[];
      loadFile: (file: File, options?: HandleFileOptions) => Promise<void>;
    };
    buildAvatarMorphMap?: (avatarRoot: any) => Map<string, BABYLON.MorphTarget[]>;
    findRetargetMorphTargets?: (maps: AvatarMaps, target: BABYLON.MorphTarget) => BABYLON.MorphTarget[];
  }
}

function buildAvatarMaps(avatarRoot: any): AvatarMaps {
  const nodeMap = new Map<string, BABYLON.Node>();
  const boneMap = new Map<string, BABYLON.Bone>();
  const scene = avatarRoot.getScene() as BABYLON.Scene;

  // Build nodeMap from ALL scene transform nodes (not just avatarRoot children)
  for (const node of scene.transformNodes) {
    if (node.name) nodeMap.set(node.name, node);
  }
  for (const mesh of scene.meshes) {
    if (mesh.name) nodeMap.set(mesh.name, mesh);
  }

  // Build boneMap from ALL scene skeletons
  for (const skeleton of scene.skeletons) {
    for (const bone of skeleton.bones) {
      if (!bone.name) continue;
      boneMap.set(`${skeleton.name}/${bone.name}`, bone);
      boneMap.set(bone.name, bone);
      // Also map by linked transform node name (glTF bones have these)
      const linked = bone.getTransformNode();
      if (linked && linked.name && linked.name !== bone.name) {
        boneMap.set(linked.name, bone);
      }
    }
  }

  const morphMap = window.buildAvatarMorphMap ? window.buildAvatarMorphMap(avatarRoot) : new Map();
  return { scene, nodeMap, boneMap, morphMap };
}

function retargetAnimationGroup(
  srcGroup: BABYLON.AnimationGroup,
  maps: AvatarMaps,
  opts: SetupOptions
): BABYLON.AnimationGroup {
  const dst = new BABYLON.AnimationGroup(`${srcGroup.name}_retarget`, maps.scene);
  const scaleMultiplier = opts.scaleMultiplier ?? 1.0;

  // Debug: log available map keys
  const boneKeys = Array.from(maps.boneMap.keys());
  const nodeKeys = Array.from(maps.nodeMap.keys());
  console.log(`[Retarget] boneMap has ${boneKeys.length} keys, sample:`, boneKeys.slice(0, 15));
  console.log(`[Retarget] nodeMap has ${nodeKeys.length} keys, sample:`, nodeKeys.slice(0, 15));

  let matched = 0, unmatched = 0;

  for (const ta of srcGroup.targetedAnimations) {
    const anim = ta.animation;
    const tgt = ta.target as any;

    let newTarget: any = null;
    const tgtClass = tgt?.getClassName?.() || "unknown";
    const tgtName = tgt?.name || "unnamed";

    if (tgtClass === "Bone") {
      const key1 = tgt._skeleton ? `${tgt._skeleton.name}/${tgt.name}` : null;
      newTarget = (key1 && maps.boneMap.get(key1)) || maps.boneMap.get(tgt.name) || null;
    } else if (tgtClass === "MorphTarget") {
      const targets = window.findRetargetMorphTargets ? window.findRetargetMorphTargets(maps, tgt) : [];
      if (!targets.length) {
        unmatched++;
        continue;
      }
      matched += targets.length;
      for (const target of targets) dst.addTargetedAnimation(anim, target);
      continue;
    } else {
      // TransformNode or other — match to avatar bones first, then nodes
      newTarget = maps.boneMap.get(tgtName) || maps.nodeMap.get(tgtName) || null;
      if (!newTarget && unmatched < 5) {
        console.log(`[Retarget] MISS: "${tgtName}" (class=${tgtClass}, prop=${anim.targetProperty}), boneMap has it: ${maps.boneMap.has(tgtName)}, nodeMap has it: ${maps.nodeMap.has(tgtName)}`);
      }
    }

    if (newTarget) {
      matched++;
      if (scaleMultiplier !== 1.0 && anim.targetProperty === "position") {
        const scaledAnim = anim.clone();
        const keys = scaledAnim.getKeys();
        const newKeys = keys.map(key => {
          const value = key.value as BABYLON.Vector3;
          if (value && value.x !== undefined && value.y !== undefined && value.z !== undefined) {
            return {
              frame: key.frame,
              value: new BABYLON.Vector3(
                value.x * scaleMultiplier,
                value.y * scaleMultiplier,
                value.z * scaleMultiplier
              ),
              inTangent: key.inTangent,
              outTangent: key.outTangent,
            };
          }
          return key;
        });
        scaledAnim.setKeys(newKeys);
        dst.addTargetedAnimation(scaledAnim, newTarget);
      } else {
        dst.addTargetedAnimation(anim, newTarget);
      }
    } else {
      unmatched++;
    }
  }

  console.log(`[Retarget] result: matched=${matched} unmatched=${unmatched} targetedAnimations=${dst.targetedAnimations.length}`);

  dst.loopAnimation = true;
  if (typeof opts.speedRatio === "number") dst.speedRatio = opts.speedRatio;

  return dst;
}

async function loadDroppedFile(scene: BABYLON.Scene, file: File) {
  return BABYLON.SceneLoader.LoadAssetContainerAsync("file:", file, scene);
}

async function loadFromUrl(scene: BABYLON.Scene, url: string) {
  const lastSlash = url.lastIndexOf("/");
  const rootUrl = url.substring(0, lastSlash + 1);
  const filename = url.substring(lastSlash + 1);
  return BABYLON.SceneLoader.LoadAssetContainerAsync(rootUrl, filename, scene);
}

function stopAndDispose(groups: BABYLON.AnimationGroup[]) {
  for (const group of groups) {
    try {
      group.stop();
    } catch {}
    try {
      group.dispose();
    } catch {}
  }
}

function debugAvatarMorphs(avatarRoot: any, maps: AvatarMaps) {
  const meshes = avatarRoot.getChildMeshes ? avatarRoot.getChildMeshes(false) : [];
  let totalTargets = 0;
  const byMesh: { mesh: string; targets: number; names: (string | undefined)[] }[] = [];

  for (const mesh of meshes) {
    const mtm = mesh.morphTargetManager as BABYLON.MorphTargetManager | null;
    if (!mtm || !mtm.numTargets) continue;
    totalTargets += mtm.numTargets;
    byMesh.push({
      mesh: mesh.name,
      targets: mtm.numTargets,
      names: Array.from({ length: mtm.numTargets }, (_, i) => mtm.getTarget(i)?.name),
    });
  }

  console.log("[Avatar] morph meshes:", byMesh.length, "total morph targets:", totalTargets);
  byMesh
    .slice(0, 10)
    .forEach(x => console.log("[Avatar] mesh:", x.mesh, "targets:", x.targets, x.names?.slice(0, 20)));
  console.log("[Avatar] morphMap sample keys:", Array.from(maps.morphMap.keys()).slice(0, 30));
}

export function setupSkeletalAnimLoader(scene: BABYLON.Scene, avatarRoot: any, opts: SetupOptions = {}) {
  if (!scene || !avatarRoot) throw new Error("setupSkeletalAnimLoader(scene, avatarRoot) requires both arguments.");

  const maps = buildAvatarMaps(avatarRoot);
  console.log("[Avatar] morphMap keys (sample):", Array.from(maps.morphMap.keys()).slice(0, 80));
  console.log("[Avatar] morphMap key count:", maps.morphMap.size);
  debugAvatarMorphs(avatarRoot, maps);

  let currentGroups: BABYLON.AnimationGroup[] = [];

  async function handleFile(file: File, fileOpts: HandleFileOptions = {}) {
    console.log("[Drop] file=", file);
    if (!file) return;
    const name = (file.name || "").toLowerCase();
    if (!name.endsWith(".glb") && !name.endsWith(".gltf")) return;

    const mergedOpts: SetupOptions = { ...opts, ...fileOpts };

    let container: BABYLON.AssetContainer;
    try {
      container = await loadDroppedFile(scene, file);
    } catch (e) {
      console.error(e);
      return;
    }

    const srcGroups = container.animationGroups || [];
    if (!srcGroups.length) {
      container.dispose();
      console.warn("[Drop] No animations found in file");
      return;
    }

    for (const group of srcGroups) {
      let bone = 0,
        morph = 0,
        node = 0,
        other = 0;
      for (const ta of group.targetedAnimations) {
        const t = ta.target as any;
        const cls = t?.getClassName?.();
        if (cls === "Bone") bone++;
        else if (cls === "MorphTarget") morph++;
        else if (t?.name) node++;
        else other++;
      }
      console.log(
        `[Drop] group="${group.name}" targetedAnimations=${group.targetedAnimations.length} bone=${bone} morph=${morph} node=${node} other=${other}`
      );
    }

    const sample = srcGroups[0].targetedAnimations
      .filter(ta => ta.target?.getClassName?.() === "MorphTarget")
      .slice(0, 30)
      .map(ta => (ta.target as any).name);
    console.log("[Drop] morph target sample:", sample);

    stopAndDispose(currentGroups);
    currentGroups = [];

    for (const group of srcGroups) {
      const rg = retargetAnimationGroup(group, maps, mergedOpts);
      currentGroups.push(rg);
    }

    const first = currentGroups[0];
    if (first) {
      console.log(`[Retarget] firstGroup="${first.name}" targetedAnimations=${first.targetedAnimations.length}`);
      first.targetedAnimations.slice(0, 30).forEach(ta => {
        const t = ta.target as any;
        console.log(
          "[Retarget] targetClass=",
          t?.getClassName?.(),
          "targetName=",
          t?.name,
          "prop=",
          ta.animation?.targetProperty
        );
      });
    }

    container.dispose();

    console.log(`[Drop] Loaded ${currentGroups.length} anim group(s)`);
  }

  async function handleUrl(url: string, fileOpts: HandleFileOptions = {}) {
    console.log("[URL] loading=", url);
    if (!url) return;
    const lower = url.toLowerCase();
    if (!lower.endsWith(".glb") && !lower.endsWith(".gltf")) return;

    const mergedOpts: SetupOptions = { ...opts, ...fileOpts };

    let container: BABYLON.AssetContainer;
    try {
      container = await loadFromUrl(scene, url);
    } catch (e) {
      console.error("[URL] Failed to load:", e);
      return;
    }

    const srcGroups = container.animationGroups || [];
    if (!srcGroups.length) {
      container.dispose();
      console.warn("[URL] No animations found in file");
      return;
    }

    stopAndDispose(currentGroups);
    currentGroups = [];

    for (const group of srcGroups) {
      const rg = retargetAnimationGroup(group, maps, mergedOpts);
      currentGroups.push(rg);
    }

    container.dispose();
    console.log(`[URL] Loaded ${currentGroups.length} anim group(s)`);
  }

  return {
    play: (idx?: number) => {
      if (idx !== undefined) {
        currentGroups[idx]?.play(true);
      } else {
        currentGroups.forEach(group => group.play(true));
      }
    },
    stop: () => currentGroups.forEach(group => group.stop()),
    list: () => currentGroups.map(group => group.name),
    loadFile: handleFile,
    loadUrl: handleUrl,
  };
}

window.setupSkeletalAnimLoader = setupSkeletalAnimLoader;
