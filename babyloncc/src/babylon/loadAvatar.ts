import * as BABYLON from "@babylonjs/core";
import "@babylonjs/loaders/glTF";
import { shouldExcludeMesh } from "../config/meshConfig";

export async function loadAvatar(scene: BABYLON.Scene): Promise<BABYLON.TransformNode> {
  const result = await BABYLON.SceneLoader.ImportMeshAsync(
    "",
    "/animMIDI/babyloncc/dist/",
    "Palmer_optimized_ktx2.glb",
    scene
  );

  const avatarRoot =
    result.transformNodes[0] ||
    result.meshes.find((m) => !m.parent) ||
    new BABYLON.TransformNode("avatarRoot", scene);

  // If there is no explicit root node, parent imported meshes under one
  if (!result.transformNodes.length) {
    result.meshes.forEach((m) => {
      if (m !== avatarRoot && !m.parent) {
        m.parent = avatarRoot;
      }
    });
  }

  console.log("[Avatar] imported meshes:", result.meshes.length);
  console.log("[Avatar] imported transformNodes:", result.transformNodes.length);
  console.log("[Avatar] avatarRoot:", avatarRoot.name);

  if (avatarRoot.scaling?.x === 0.01) {
    avatarRoot.scaling.set(1, 1, 1);
    console.log("[Avatar] Scaled avatar from 0.01 to 1.0");
  }

  result.meshes.forEach((m) => {
    if (shouldExcludeMesh(m.name)) {
      m.setEnabled(false);
      console.log(`[MeshConfig] Excluded mesh: ${m.name}`);
      return;
    }

    m.alwaysSelectAsActiveMesh = true;

    const mat = m.material as BABYLON.PBRMaterial | null;
    if (!mat) return;

    const n = `${m.name} ${mat.name}`.toLowerCase();

    if (n.includes("hair")) {
      mat.backFaceCulling = true;
      m.renderingGroupId = 0;
      mat.transparencyMode = BABYLON.PBRMaterial.PBRMATERIAL_ALPHABLEND;
    } else if (n.includes("beard") || n.includes("brow")) {
      mat.roughness = 0.6;
    } else if (n.includes("scalp")) {
      m.renderingGroupId = 0;
      m.alphaIndex = 0;
    } else if (n.includes("eyelash")) {
      mat.transparencyMode = BABYLON.PBRMaterial.PBRMATERIAL_ALPHABLEND;
    } else {
      mat.transparencyMode = BABYLON.PBRMaterial.PBRMATERIAL_OPAQUE;
    }
  });

  return avatarRoot;
}