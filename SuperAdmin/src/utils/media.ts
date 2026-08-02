import { IMG_BASE_URL } from "@/config/env";

const STORAGE_PREFIX = "storage/";
const STORAGE_APP_PUBLIC_PREFIX = "storage/app/public/";

const ensurePublicStoragePath = (value: string) => {
  const normalized = value.replace(/\\/g, "/");
  if (normalized.startsWith(STORAGE_APP_PUBLIC_PREFIX)) {
    return normalized.replace(STORAGE_APP_PUBLIC_PREFIX, STORAGE_PREFIX);
  }
  if (normalized.startsWith(`/${STORAGE_APP_PUBLIC_PREFIX}`)) {
    return normalized.replace(`/${STORAGE_APP_PUBLIC_PREFIX}`, `/${STORAGE_PREFIX}`);
  }
  return normalized;
};

const stripLeadingSlash = (value: string) => value.replace(/^\/+/, "");

export const buildImageUrl = (path?: string | null): string | null => {
  if (!path) {
    return null;
  }
  if (/^https?:\/\//i.test(path)) {
    return path;
  }
  const normalized = stripLeadingSlash(path);
  const storageAwarePath = ensurePublicStoragePath(normalized);
  return `${IMG_BASE_URL}/${storageAwarePath}`;
};
