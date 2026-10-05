import { ensureStoragePublicPath } from "@/utils/storage";

const env = import.meta.env as Record<string, string | undefined>;
const apiBase = env.VITE_API_BASE_URL ?? "";

// Uploads are served from the backend origin, not from /api/super — strip the
// API suffix off the configured base rather than asking for a second env var.
const fallbackImageBase = apiBase ? apiBase.replace(/\/api(?:\/(?:admin|super))?\/?$/i, "/") : "";
const imageBase = env.VITE_IMG_BASE_URL ?? fallbackImageBase;

export const resolveUploadUrl = (path?: string | null): string => {
  const normalized = ensureStoragePublicPath(path);
  if (!normalized) return "";
  if (/^https?:\/\//i.test(normalized)) return normalized;
  if (!imageBase) return normalized;
  return `${imageBase.replace(/\/$/, "")}/${normalized.replace(/^\//, "")}`;
};
