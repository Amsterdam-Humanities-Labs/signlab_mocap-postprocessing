import * as BABYLON from "@babylonjs/core";

type MorphLoaderOptions = {
  mappingUrl?: string;
  loop?: boolean;
  speedRatio?: number;
};

declare global {
  interface Window {
    setupMorphAnimLoader?: (scene: BABYLON.Scene, avatarRoot: any, opts?: MorphLoaderOptions) => {
      stop: () => void;
      play: () => void;
      dispose: () => void;
      loadFile: (file: File) => Promise<void>;
    };
  }
}

async function tryLoadText(url: string) {
  try {
    const response = await fetch(url, { cache: "no-store" });
    if (!response.ok) return null;
    return await response.text();
  } catch {
    return null;
  }
}

function parseSimpleCsvMapping(text: string) {
  const lines = text
    .split(/\r?\n/)
    .map(line => line.trim())
    .filter(line => line && !line.startsWith("#"));

  const map = new Map<string, string>();
  if (lines.length === 0) return map;

  const header = lines[0].split(",").map(cell => cell.trim().replace(/^"|"$/g, ""));
  const idxARKit = header.findIndex(h => h.toLowerCase() === "arkit");
  const idxTargets = header.findIndex(h => h.toLowerCase() === "targets");
  const hasHeader = idxARKit !== -1 && idxTargets !== -1;

  const start = hasHeader ? 1 : 0;

  for (let i = start; i < lines.length; i++) {
    const cols = lines[i].split(",").map(cell => cell.trim().replace(/^"|"$/g, ""));
    if (cols.length < 2) continue;

    const arkit = hasHeader ? cols[idxARKit] : cols[0];
    const targets = hasHeader ? cols[idxTargets] : cols[1];

    if (!arkit || !targets) continue;

    map.set(arkit.toLowerCase(), targets);
  }

  return map;
}

function buildMorphMapAllMeshes(avatarRoot: any) {
  const map = new Map<string, BABYLON.MorphTarget[]>();
  for (const mesh of avatarRoot.getChildMeshes(false)) {
    const mtm = mesh.morphTargetManager as BABYLON.MorphTargetManager | null;
    if (!mtm) continue;
    for (let i = 0; i < mtm.numTargets; i++) {
      const target = mtm.getTarget(i);
      if (!target?.name) continue;
      const key = target.name.toLowerCase();
      if (!map.has(key)) map.set(key, []);
      map.get(key)?.push(target);
    }
  }
  return map;
}

function curveNames(json: any) {
  if (json.curves) return Object.keys(json.curves);
  if (json.morphCurves) return Object.keys(json.morphCurves);
  if (Array.isArray(json.channels)) return json.channels.map((c: any) => c?.name).filter(Boolean);
  return [] as string[];
}

function curvePairs(json: any, name: string) {
  if (json.curves?.[name]) return json.curves[name];
  if (json.morphCurves?.[name]) return json.morphCurves[name];
  if (Array.isArray(json.channels)) {
    const channel = json.channels.find((c: any) => c.name === name);
    if (!channel?.keys) return null;
    return channel.keys.map((k: any) => [k.t ?? k.time, k.v ?? k.value]);
  }
  return null;
}

function stopAndDispose(group?: BABYLON.AnimationGroup | null) {
  if (!group) return;
  try {
    group.stop();
  } catch {}
  try {
    group.dispose();
  } catch {}
}

export function setupMorphAnimLoader(scene: BABYLON.Scene, avatarRoot: any, opts: MorphLoaderOptions = {}) {
  if (!scene || !avatarRoot) throw new Error("scene + avatarRoot required");

  let currentGroup: BABYLON.AnimationGroup | null = null;
  let nameMap: Map<string, string> | null = null;

  (async () => {
    const url = opts.mappingUrl || "./CCARKitMapping.csv";
    const text = await tryLoadText(url);
    if (!text) {
      console.log("[morphAnim] mapping not found:", url);
      return;
    }
    nameMap = parseSimpleCsvMapping(text);
    console.log("[morphAnim] mapping loaded:", url, "rows:", nameMap.size);
    console.log("[morphAnim] mapping sample:", [...nameMap.entries()].slice(0, 10));
  })();

  async function handleFile(file: File) {
    console.log("[morphAnim] file dropped:", file?.name);
    if (!file || !file.name.toLowerCase().endsWith(".json")) return;

    const json = JSON.parse(await file.text());
    stopAndDispose(currentGroup);

    const fps = Number.isFinite(json.fps) ? json.fps : 60;
    const morphMap = buildMorphMapAllMeshes(avatarRoot);

    console.log("[morphAnim] morphMap keys (sample):", [...morphMap.keys()].slice(0, 10));
    console.log("[morphAnim] morphMap key count:", morphMap.size);

    const group = new BABYLON.AnimationGroup("jsonMorphs", scene);

    let matched = 0;
    const unmatched: string[] = [];

    for (const srcName of curveNames(json)) {
      const pairs = curvePairs(json, srcName);
      if (!pairs || pairs.length < 2) continue;

      const mappedRaw = nameMap?.get(srcName.toLowerCase()) || srcName;
      const mappedNames = String(mappedRaw)
        .split("|")
        .map(name => name.trim().toLowerCase())
        .filter(Boolean);

      const targetSet = new Set<BABYLON.MorphTarget>();
      for (const name of mappedNames) {
        const targets = morphMap.get(name);
        if (targets && targets.length) {
          targets.forEach(target => targetSet.add(target));
        }
      }

      const targets = Array.from(targetSet);

      if (targets.length === 0) {
        if (unmatched.length < 10) console.log("[morphAnim] no target for:", srcName, "->", mappedRaw);
        unmatched.push(srcName);
        continue;
      }

      const keys = pairs
        .map(([t, v]: [number, number]) => ({ t: Number(t), v: Number(v) }))
        .filter(k => Number.isFinite(k.t) && Number.isFinite(k.v))
        .sort((a, b) => a.t - b.t);

      if (keys.length < 2) continue;

      const maxT = keys[keys.length - 1].t;
      const timeIsSeconds = maxT <= 300;

      for (const mt of targets) {
        const anim = new BABYLON.Animation(
          `mt_${srcName}`,
          "influence",
          fps,
          BABYLON.Animation.ANIMATIONTYPE_FLOAT,
          BABYLON.Animation.ANIMATIONLOOPMODE_CYCLE
        );

        anim.setKeys(
          keys.map(k => ({
            frame: timeIsSeconds ? k.t * fps : k.t,
            value: k.v,
          }))
        );

        group.addTargetedAnimation(anim, mt);
        matched++;
      }
    }

    console.log("[morphAnim] matched:", matched, "unmatched_count:", unmatched.length);
    console.log("[morphAnim] unmatched sample:", unmatched.slice(0, 25));

    currentGroup = group;

    if (typeof opts.speedRatio === "number") group.speedRatio = opts.speedRatio;

    console.log("[morphAnim] targetedAnimations:", group.targetedAnimations.length);
  }

  return {
    stop: () => currentGroup?.stop(),
    play: () => {
      if (currentGroup) {
        currentGroup.start(opts.loop !== false, 1.0);
      }
    },
    dispose: () => stopAndDispose(currentGroup),
    loadFile: handleFile,
  };
}

window.setupMorphAnimLoader = setupMorphAnimLoader;
