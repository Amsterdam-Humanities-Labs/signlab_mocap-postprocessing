// Mesh configuration for avatar loading
// Meshes containing these strings in their names will be disabled/hidden

export const EXCLUDED_MESH_NAMES = [
  "cc_base_eyeocclusion",
  "cc_base_tear_ducts",
  "cc_base_tearline",
  "chin_curtain_sparse",
  "male_bushy",
  "mustache_horseshoe",
  "stubble_neck",
  "cc_base_eye_primitive1",
  "cc_base_eye_primitive3",
];

/**
 * Check if a mesh name matches any exclusion pattern
 */
export function shouldExcludeMesh(meshName: string): boolean {
  const lowerName = meshName.toLowerCase();
  return EXCLUDED_MESH_NAMES.some(excluded => 
    lowerName.includes(excluded.toLowerCase())
  );
}
