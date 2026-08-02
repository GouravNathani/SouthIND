// Generates the PWA icon set: the brand heart in the logo's green, on the app's
// dark background. Run: node scripts/make-icons.mjs
//
// The heart is drawn from the implicit curve (x²+y²−1)³ − x²y³ ≤ 0 rather than
// traced from Logo.png, so it stays crisp at 192px and 512px alike. There is no
// SVG rasteriser on the build host, hence the dependency-free PNG encoder below.
import { deflateSync } from "node:zlib";
import { mkdirSync, writeFileSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const OUT = resolve(dirname(fileURLToPath(import.meta.url)), "../public/conf");

const BG = [0x07, 0x08, 0x0b];
/** Picked from Logo.png — the green of the O and the heart in the wordmark. */
const BRAND = [0x90, 0xfc, 0x00];

const crcTable = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

const crc32 = (buf) => {
  let c = 0xffffffff;
  for (const byte of buf) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
};

const chunk = (type, data) => {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, "ascii"), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
};

const png = (size, pixel) => {
  const stride = size * 4;
  const raw = Buffer.alloc((stride + 1) * size);
  for (let y = 0; y < size; y++) {
    const rowStart = y * (stride + 1);
    raw[rowStart] = 0; // filter: none
    for (let x = 0; x < size; x++) {
      const [r, g, b, a] = pixel(x, y);
      const i = rowStart + 1 + x * 4;
      raw[i] = r;
      raw[i + 1] = g;
      raw[i + 2] = b;
      raw[i + 3] = a;
    }
  }

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk("IHDR", ihdr),
    chunk("IDAT", deflateSync(raw, { level: 9 })),
    chunk("IEND", Buffer.alloc(0)),
  ]);
};

const mix = (a, b, t) => a.map((c, i) => Math.round(c + (b[i] - c) * t));
const clamp01 = (value) => Math.min(1, Math.max(0, value));

/** Inside-ness of the heart curve at unit coordinates, >0 means inside. */
const heartField = (x, y) => {
  const t = x * x + y * y - 1;
  return -(t * t * t - x * x * y * y * y);
};

/**
 * @param size    icon edge in px
 * @param padding fraction of the edge kept clear of art (maskable needs ~20%)
 */
const draw = (size, padding) => {
  const cx = size / 2 - 0.5;
  const cy = size / 2 - 0.5;
  const safe = size * (1 - padding * 2);
  // The curve spans roughly ±1.2 horizontally; the extra factor centres it.
  const scale = safe / 2.5;
  const samples = 2; // supersampled edges — a heart is all diagonals

  return (px, py) => {
    let coverage = 0;
    for (let sx = 0; sx < samples; sx++) {
      for (let sy = 0; sy < samples; sy++) {
        const x = (px + (sx + 0.5) / samples - cx) / scale;
        // Shift down slightly: the lobes are visually heavier than the point.
        const y = -(py + (sy + 0.5) / samples - cy - size * 0.03) / scale;
        if (heartField(x, y) >= 0) coverage += 1;
      }
    }
    coverage = clamp01(coverage / (samples * samples));

    let alpha = 255;
    if (padding < 0.15) {
      const r = size * 0.22;
      const qx = Math.abs(px - cx) - (size / 2 - r);
      const qy = Math.abs(py - cy) - (size / 2 - r);
      const corner = Math.hypot(Math.max(qx, 0), Math.max(qy, 0)) - r + 0.5;
      alpha = Math.round(255 * clamp01(-corner / 1.2 + 0.5));
      if (alpha === 0) return [0, 0, 0, 0];
    }

    return [...mix(BG, BRAND, coverage), alpha];
  };
};

mkdirSync(OUT, { recursive: true });
const targets = [
  ["icon-192.png", 192, 0.1],
  ["icon-512.png", 512, 0.1],
  ["icon-maskable.png", 512, 0.2],
  ["favicon-32.png", 32, 0.06],
];

for (const [name, size, padding] of targets) {
  writeFileSync(resolve(OUT, name), png(size, draw(size, padding)));
  console.log(`wrote conf/${name} (${size}px)`);
}
