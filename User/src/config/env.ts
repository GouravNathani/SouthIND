const trimTrailingSlashes = (value: string) => value.replace(/\/+$/, "");

const envOr = (value: string | undefined, fallback: string) =>
  value && value.trim().length ? value : fallback;

const rawApiHost = envOr(import.meta.env.VITE_API_HOST, "http://127.0.0.1:8000");
const fallbackImageHost = rawApiHost.replace(/\/api\/?$/i, "");
const rawImageHost = envOr(import.meta.env.VITE_IMG_BASE_URL, fallbackImageHost);

export const API_HOST = trimTrailingSlashes(rawApiHost);
export const API_BASE = `${API_HOST}/api/`;
export const IMG_BASE_URL = trimTrailingSlashes(rawImageHost);
export const BRANCH_CODE = envOr(import.meta.env.VITE_BRANCH_CODE, "");

export const WHATSAPP_LINK = envOr(import.meta.env.VITE_WHATSAPP_URL, "https://wa.me/");
export const INSTAGRAM_LINK = envOr(import.meta.env.VITE_INSTAGRAM_URL, "https://www.instagram.com/");
export const TELEGRAM_LINK = envOr(import.meta.env.VITE_TELEGRAM_URL, "https://t.me/");

export const BUILD_VERSION =
  typeof __BUILD_VERSION__ === "string" && __BUILD_VERSION__.length > 0 ? __BUILD_VERSION__ : "dev";
