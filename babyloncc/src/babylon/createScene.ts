import * as BABYLON from "@babylonjs/core";

interface SceneSetupResult {
  engine: BABYLON.Engine;
  scene: BABYLON.Scene;
  dispose: () => void;
}

function cameraInit(scene: BABYLON.Scene): BABYLON.Camera | null {
    const cam = scene.activeCamera as any;
    if (cam) {
        cam.alpha = Math.PI / 2;
        cam.beta = 1.2;
        cam.radius = 2.2;
        cam.minZ = 0.001;
        cam.lowerRadiusLimit = 0.005;
    }
    return cam;
}

function debugInit(scene: BABYLON.Scene): (e: KeyboardEvent) => void {
  // Inspector hidden by default (performance), toggle with 'i' key
  scene.debugLayer.hide();
  const onKeyDownInspector = (e: KeyboardEvent) => {
    if (e.key === "i" || e.key === "I") {
      if (scene.debugLayer.isVisible()) {
        scene.debugLayer.hide();
      } else {
        scene.debugLayer.show();
      }
    }
  };
  window.addEventListener("keydown", onKeyDownInspector);
  return onKeyDownInspector;
}

export function createScene(canvas: HTMLCanvasElement): SceneSetupResult {
  const engine = new BABYLON.Engine(canvas, true);
  engine.setHardwareScalingLevel(1.5); // Render at 67% resolution for better FPS
  const scene = new BABYLON.Scene(engine);

  // Default camera/light similar to createDefaultCameraOrLight(true,true,true)
  scene.createDefaultCameraOrLight(true, true, true);

  cameraInit(scene);

  const onResize = () => engine.resize();
  window.addEventListener("resize", onResize);

  const onKeyDownInspector = debugInit(scene);

  // Dragover prevent (matches your HTML)
  const onDragOver = (e: DragEvent) => e.preventDefault();
  window.addEventListener("dragover", onDragOver);

  return {
    engine,
    scene,
    dispose: () => {
      window.removeEventListener("resize", onResize);
      window.removeEventListener("keydown", onKeyDownInspector);
      window.removeEventListener("dragover", onDragOver);
    },
  };
}
