// Generates the PWA icon set. Placeholder brand mark — an emerald ring on the
// Midnight background — until real artwork lands. Run: node scripts/make-icons.mjs
import { deflateSync } from "node:zlib";
import { mkdirSync, writeFileSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const OUT = resolve(dirname(fileURLToPath(import.meta.url)), "../public/conf");

const BG = [0x07, 0x08, 0x0b];
const ACCENT = [0x00, 0xe5, 0xa0];
const ACCENT_2 = [0xff, 0xc9, 0x4d];

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

// Antialiased coverage of a value against a threshold, ~1px feather.
const smooth = (edge, value) => Math.min(1, Math.max(0, (edge - value) / 1.2 + 0.5));
const mix = (a, b, t) => a.map((c, i) => Math.round(c + (b[i] - c) * t));

/**
 * @param size    icon edge in px
 * @param padding fraction of the edge kept clear of art (maskable needs ~20%)
 */
const draw = (size, padding) => (x, y) => {
  const cx = size / 2 - 0.5;
  const cy = size / 2 - 0.5;
  const safe = size * (1 - padding * 2);

  const dx = x - cx;
  const dy = y - cy;
  const dist = Math.hypot(dx, dy);

  let color = BG;
  let alpha = 255;

  // Rounded-square backdrop (full bleed on maskable, rounded on the plain icon).
  if (padding < 0.15) {
    const r = size * 0.22;
    const qx = Math.abs(dx) - (size / 2 - r);
    const qy = Math.abs(dy) - (size / 2 - r);
    const corner = Math.hypot(Math.max(qx, 0), Math.max(qy, 0)) - r + 0.5;
    alpha = Math.round(255 * smooth(0, corner));
    if (alpha === 0) return [0, 0, 0, 0];
  }

  // Ring.
  const ringR = safe * 0.34;
  const ringW = safe * 0.11;
  const ring = Math.abs(dist - ringR) - ringW / 2;
  const ringCoverage = smooth(0, ring);

  // The ring fades from accent to the gold highlight across its sweep.
  const sweep = (Math.atan2(dy, dx) + Math.PI) / (2 * Math.PI);
  const ringColor = mix(ACCENT, ACCENT_2, sweep * 0.55);
  color = mix(color, ringColor, ringCoverage);

  // Centre dot.
  const dotCoverage = smooth(0, dist - safe * 0.11);
  color = mix(color, ACCENT, dotCoverage);

  return [...color, alpha];
};

mkdirSync(OUT, { recursive: true });
const targets = [
  ["icon-192.png", 192, 0.1],
  ["icon-512.png", 512, 0.1],
  ["icon-maskable.png", 512, 0.2],
  ["favicon-32.png", 32, 0.05],
];

for (const [name, size, padding] of targets) {
  writeFileSync(resolve(OUT, name), png(size, draw(size, padding)));
  console.log(`wrote conf/${name} (${size}px)`);
}
