/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

declare const __BUILD_VERSION__: string;

interface ImportMetaEnv {
  readonly VITE_API_HOST?: string;
  readonly VITE_IMG_BASE_URL?: string;
  readonly VITE_BRANCH_CODE?: string;
  readonly VITE_WHATSAPP_URL?: string;
  readonly VITE_INSTAGRAM_URL?: string;
  readonly VITE_TELEGRAM_URL?: string;
  readonly VITE_VAPID_PUBLIC_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
