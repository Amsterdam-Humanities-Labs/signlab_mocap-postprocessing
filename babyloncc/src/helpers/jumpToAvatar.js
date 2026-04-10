// jumpToAvatar.js
// Usage: setupJumpToAvatar(scene, avatarRoot, { key: "j" });

(function () {
  function findRootBoneNode(avatarRoot) {
    const meshes = avatarRoot.getChildMeshes ? avatarRoot.getChildMeshes(false) : [];
    const skel = meshes.find(m => m.skeleton)?.skeleton || null;
    if (!skel || !skel.bones?.length) return null;

    // Prefer a bone named "root" if present, else use first bone.
    const rootBone =
      skel.bones.find(b => (b.name || "").toLowerCase() === "root") ||
      skel.bones[0];

    return rootBone?._linkedTransformNode || null;
  }

  function getWorldPos(node) {
    if (!node) return null;
    const v = node.getAbsolutePosition ? node.getAbsolutePosition() : null;
    return v || null;
  }

  window.setupJumpToAvatar = function setupJumpToAvatar(scene, avatarRoot, opts = {}) {
    const key = (opts.key || "j").toLowerCase();
    if (!scene || !avatarRoot) throw new Error("setupJumpToAvatar(scene, avatarRoot) requires both arguments.");

    const cam = scene.activeCamera;
    if (!cam) throw new Error("No activeCamera found.");

    const jump = () => {
      const targetNode = findRootBoneNode(avatarRoot) || avatarRoot;
      const p = getWorldPos(targetNode);
      if (!p) return;

      // ArcRotateCamera: setTarget + radius clamp
      if (cam.setTarget) cam.setTarget(p);

      if ("radius" in cam) {
        const r = cam.radius || 2;
        cam.radius = Math.max(0.25, r);
      }

      // Also nudge near plane so close-ups don't clip.
      if ("minZ" in cam) cam.minZ = Math.min(cam.minZ || 1, 0.01);

      console.log("[JumpToAvatar] camera targeted:", targetNode.name, "at", p.toString());
    };

    window.addEventListener("keydown", (e) => {
      if ((e.key || "").toLowerCase() === key) jump();
    });

    return { jump };
  };
})();
