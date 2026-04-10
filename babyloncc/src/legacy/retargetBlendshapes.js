// retargetBlendshapes.js
// Usage:
//   const clone = retargetAnimWithBlendshapes(avatarRoot, srcAnimGroup, "walk");
//   clone.play(true);

(function () {
  function collectAvatarMaps(avatarRoot) {
    const meshes = avatarRoot.getChildMeshes ? avatarRoot.getChildMeshes(false) : [];
    const skeleton = meshes.find(m => m.skeleton)?.skeleton || null;

    const morphMap = new Map(); // name -> MorphTarget[]
    for (const m of meshes) {
      const mtm = m.morphTargetManager;
      if (!mtm || !mtm.numTargets) continue;
      for (let i = 0; i < mtm.numTargets; i++) {
        const t = mtm.getTarget(i);
        if (!t || !t.name) continue;
        if (!morphMap.has(t.name)) morphMap.set(t.name, []);
        morphMap.get(t.name).push(t);
      }
    }

    return { skeleton, morphMap };
  }

  function retargetAnimWithBlendshapes(avatarRoot, animGroup, cloneName = "anim") {
    const { skeleton, morphMap } = collectAvatarMaps(avatarRoot);

    return animGroup.clone(cloneName, (target) => {
      if (!target) return null;

      // 1) Bones: map to linked transform node (what Babylon animates for skinned rigs)
      if (skeleton && target.name) {
        const bone = skeleton.bones.find(b => b.name === target.name);
        if (bone && bone._linkedTransformNode) return bone._linkedTransformNode;
      }

      // 2) MorphTargets: clone() hands us MorphTarget targets for facial animations
      if (typeof target.getClassName === "function" && target.getClassName() === "MorphTarget") {
        const matches = morphMap.get(target.name);
        // If multiple meshes have same morph target name, pick first.
        // (If you truly need to drive all matches, see note below.)
        return matches && matches[0] ? matches[0] : null;
      }

      // 3) Fallback: try to match by node name under avatarRoot
      if (target.name && avatarRoot.getChildren) {
        const stack = [avatarRoot];
        while (stack.length) {
          const n = stack.pop();
          if (n && n.name === target.name) return n;
          if (n && n.getChildren) stack.push(...n.getChildren());
        }
      }

      return null;
    });
  }

  window.retargetAnimWithBlendshapes = retargetAnimWithBlendshapes;
})();
