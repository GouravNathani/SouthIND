import type { CSSProperties } from "react";
import { useGetWinnerStreakQuery } from "@/services/api";
import { IconTrophy } from "@/components/icons";

/** Seconds each winner takes to cross, so the speed reads the same at any count. */
const SECONDS_PER_ITEM = 7;

/** Below this the winners fit without scrolling, so animating would only fidget. */
const MIN_ITEMS_TO_SCROLL = 3;

/**
 * Marquee of the latest published winners. Renders nothing at all when the
 * branch has published none — an empty ribbon is worse than no ribbon.
 *
 * The list is rendered twice so the loop can restart without a visible jump;
 * the duplicate is hidden from assistive tech so the winners are announced once.
 */
export default function WinnerRibbon() {
  const { data } = useGetWinnerStreakQuery();
  const items = data?.ribbon ?? [];
  if (!items.length) return null;

  const animated = items.length >= MIN_ITEMS_TO_SCROLL;

  // Both copies share one structure so their widths are identical — the loop
  // translates by exactly one group and would drift if they differed.
  const group = (duplicate: boolean) => (
    <div className="marquee__group" aria-hidden={duplicate || undefined}>
      {items.map((item, index) => (
        <span
          key={`${item.period}-${item.name}-${index}`}
          className="flex shrink-0 items-baseline gap-2 text-xs whitespace-nowrap"
        >
          <span className="font-semibold text-text">{item.name}</span>
          {item.amount ? <span className="tabular text-accent">{item.amount}</span> : null}
          <span className="text-faint">{item.title}</span>
        </span>
      ))}
    </div>
  );

  return (
    <div className="flex min-w-0 items-center gap-3 overflow-hidden rounded-lg border border-border bg-surface px-3 py-2.5">
      <span className="shrink-0 text-accent-2">
        <IconTrophy size={18} />
      </span>

      <div className="marquee">
        <div
          className={`marquee__track${animated ? " marquee__track--animated" : ""}`}
          style={
            animated
              ? ({ "--marquee-duration": `${items.length * SECONDS_PER_ITEM}s` } as CSSProperties)
              : undefined
          }
        >
          {group(false)}
          {animated ? group(true) : null}
        </div>
      </div>
    </div>
  );
}
