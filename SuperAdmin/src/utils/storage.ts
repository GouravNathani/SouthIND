export const ensureStoragePublicPath = (path?: string | null): string => {
  if (!path) {
    return "";
  }
  const normalized = path.replace(/\\/g, "/");
  if (/^https?:\/\//i.test(normalized)) {
    return normalized;
  }
  return normalized.replace(/\/storage\/app\/public\//i, "/storage/");
};
