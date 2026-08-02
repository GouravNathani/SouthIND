import { useGetWinnerStreakQuery } from "@/services/api";
import { IconTrophy } from "@/components/icons";

/**
 * Marquee of the latest published winners. Renders nothing at all when the
 * branch has published none — an empty ribbon is worse than no ribbon.
 */
export default function WinnerRibbon() {
  const { data } = useGetWinnerStreakQuery();
  const items = data?.ribbon ?? [];
  if (!items.length) return null;

  return (
    <div className="flex min-w-0 items-center gap-3 overflow-hidden rounded-lg border border-border bg-surface px-3 py-2.5">
      <span className="shrink-0 text-accent-2">
        <IconTrophy size={18} />
      </span>
      <div className="flex min-w-0 flex-1 gap-6 overflow-x-auto">
        {items.map((item, index) => (
          <span
            key={`${item.period}-${item.name}-${index}`}
            className="flex shrink-0 items-baseline gap-2 text-xs whitespace-nowrap"
          >
            <span className="font-semibold text-text">{item.name}</span>
            {item.amount ? <span className="tabular text-accent">₹{item.amount}</span> : null}
            <span className="text-faint">{item.title}</span>
          </span>
        ))}
      </div>
    </div>
  );
}
