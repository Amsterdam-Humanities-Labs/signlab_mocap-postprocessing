import type * as BABYLON from "@babylonjs/core";

interface AnimationControllerOptions {
  mappingUrl: string;
  autoStart?: boolean;
  speedRatio?: number;
  jumpKey?: string;
}

interface SkeletalLoadOptions {
  scaleMultiplier?: number;
}

export interface AnimationController {
  loadSkeletal: (file: File, options?: SkeletalLoadOptions) => void;
  loadSkeletalFromUrl: (url: string, options?: SkeletalLoadOptions) => void;
  loadBlendshape: (file: File) => void;
  playAll: () => void;
}

declare global {
  interface Window {
    setupSkeletalAnimLoader?: (scene: BABYLON.Scene, avatarRoot: any, opts?: any) => any;
    setupJumpToAvatar?: (scene: BABYLON.Scene, avatarRoot: any, opts?: any) => any;
    setupMorphAnimLoader?: (scene: BABYLON.Scene, avatarRoot: any, opts?: any) => any;
  }
}

export async function createAnimationController(
  scene: BABYLON.Scene,
  avatarRoot: any,
  options: AnimationControllerOptions
): Promise<AnimationController> {
  await import("./skeletalAnimLoader.ts");
  await import("../helpers/jumpToAvatar.js");
  await import("./morphAnimLoader.ts");

  const animDropHandler = window.setupSkeletalAnimLoader?.(scene, avatarRoot, {
    autoStart: options.autoStart ?? true,
    speedRatio: options.speedRatio ?? 1.0,
  });

  window.setupJumpToAvatar?.(scene, avatarRoot, { key: options.jumpKey ?? "j" });

  const morphHandler = window.setupMorphAnimLoader?.(scene, avatarRoot, {
    loop: true,
    mappingUrl: options.mappingUrl,
  });

  return {
    loadSkeletal: (file: File, loadOptions?: SkeletalLoadOptions) => {
      if (!animDropHandler?.loadFile) {
        console.error("[AnimLoader] animDrop handler not ready");
        return;
      }

      console.log("[AnimLoader] Loading skeletal animation:", file.name);
      if (loadOptions?.scaleMultiplier) {
        console.log("[AnimLoader] Skeletal scale multiplier:", loadOptions.scaleMultiplier);
      }

      animDropHandler.loadFile(file, {
        scaleMultiplier: loadOptions?.scaleMultiplier ?? 1.0,
      });
    },
    loadSkeletalFromUrl: async (url: string, loadOptions?: SkeletalLoadOptions) => {
      if (!animDropHandler?.loadUrl) {
        console.error("[AnimLoader] animDrop handler not ready");
        return;
      }

      console.log("[AnimLoader] Loading skeletal animation from URL:", url);
      await animDropHandler.loadUrl(url, {
        scaleMultiplier: loadOptions?.scaleMultiplier ?? 1.0,
      });
    },
    loadBlendshape: (file: File) => {
      if (!morphHandler?.loadFile) return;

      console.log("[AnimLoader] Loading blendshape animation:", file.name);
      morphHandler.loadFile(file);
    },
    playAll: () => {
      console.log("[AnimLoader] Playing all animations");

      if (animDropHandler?.play) {
        animDropHandler.play();
      }

      if (morphHandler?.play) {
        morphHandler.play();
      }
    },
  };
}
