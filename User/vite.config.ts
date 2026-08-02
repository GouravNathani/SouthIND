import { defineConfig, type Plugin, type ResolvedConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";
import { fileURLToPath } from "node:url";
import { mkdirSync, writeFileSync } from "node:fs";
import { resolve as resolvePath } from "node:path";
import packageJson from "./package.json" with { type: "json" };

const buildTimestamp = new Date().toISOString().replace(/[-:T.Z]/g, "").slice(0, 14);

const buildVersion =
  process.env.BUILD_VERSION && process.env.BUILD_VERSION.trim().length > 0
    ? process.env.BUILD_VERSION
    : `${packageJson.version}-${buildTimestamp}`;

// Publishes the built version under /version so a running PWA can detect that a
// newer bundle exists without waiting for the service worker's own check.
const emitBuildVersionPlugin = (): Plugin => {
  let outDir = "dist";
  return {
    name: "emit-build-version",
    apply: "build",
    configResolved(config: ResolvedConfig) {
      outDir = config.build.outDir;
    },
    writeBundle() {
      const versionDir = resolvePath(outDir, "version");
      mkdirSync(versionDir, { recursive: true });
      writeFileSync(
        resolvePath(versionDir, "version.json"),
        JSON.stringify({ version: buildVersion, builtAt: new Date().toISOString() }, null, 2)
      );
      writeFileSync(resolvePath(versionDir, "latest.txt"), buildVersion);
    },
  };
};

export default defineConfig(({ command }) => ({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      strategies: "injectManifest",
      srcDir: "src",
      filename: "sw.ts",
      registerType: "autoUpdate",
      includeAssets: ["conf/Logo.png"],
      manifest: {
        name: "SouthIND",
        short_name: "SouthIND",
        description: "SouthIND user panel.",
        start_url: "/",
        scope: "/",
        display: "standalone",
        background_color: "#07080b",
        theme_color: "#07080b",
        lang: "en-US",
        icons: [
          { src: "/conf/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
          { src: "/conf/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
          { src: "/conf/icon-maskable.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
        ],
      },
      devOptions: { enabled: command === "serve", type: "module" },
    }),
    emitBuildVersionPlugin(),
  ],
  define: {
    __BUILD_VERSION__: JSON.stringify(buildVersion),
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./src", import.meta.url)),
    },
  },
  server: { port: 5173 },
  build: { outDir: "dist" },
}));
