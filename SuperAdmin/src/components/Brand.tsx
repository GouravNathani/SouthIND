// Imported rather than referenced from /public so the build fingerprints it —
// a swapped logo then busts every browser and service-worker cache by itself.
import logoUrl from "@/assets/logo.png";
import { IconHeart } from "@/components/icons";

/**
 * Compact brand mark for chrome: the heart from the logo's O, tinted with the
 * active skin's accent so it changes with the theme.
 */
export function BrandMark({ size = 36 }: { size?: number }) {
  return (
    <span
      className="grid shrink-0 place-items-center rounded-md text-accent"
      style={{
        width: size,
        height: size,
        background: "var(--accent-soft)",
        boxShadow: "inset 0 0 0 1px var(--accent-line)",
      }}
    >
      <IconHeart size={Math.round(size * 0.62)} />
    </span>
  );
}

/**
 * The full wordmark, for login screens. The artwork's letters are white with
 * thin outlines, so it is set on a dark plate rather than straight on the page —
 * on the light skins it would otherwise be white-on-white.
 */
export function BrandLockup({ className = "" }: { className?: string }) {
  return (
    <span
      className={`inline-flex max-w-full items-center justify-center rounded-lg px-6 py-4 ${className}`}
      style={{ background: "#0a0b0e" }}
    >
      <img
        src={logoUrl}
        alt="SouthIND — South Indian Exchange"
        width={990}
        height={405}
        className="h-12 w-auto max-w-full object-contain sm:h-14"
      />
    </span>
  );
}
