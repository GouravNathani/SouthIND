import { useGetHomeExtrasQuery } from "@/services/homeExtras";

/**
 * Top of the home page: the user's club badge and the branch's running strip
 * ("Live Casino Not Allowed"). Each part is hidden when there is nothing to
 * show, so with the strip switched off nothing of it appears at all.
 */
export default function HomeStrip() {
  const { data } = useGetHomeExtrasQuery(undefined, { pollingInterval: 300000 });
  const ticker = data?.ticker;
  const club = data?.club;

  if (!ticker && !club) return null;

  return (
    <div className="space-y-3">
      {club ? (
        <div className="flex">
          <span
            title={`${club.active_days} active days`}
            className="inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold text-text"
            style={{
              borderColor: `color-mix(in srgb, ${club.color} 55%, transparent)`,
              background: `color-mix(in srgb, ${club.color} 16%, transparent)`,
            }}
          >
            <svg aria-hidden viewBox="0 0 24 24" className="h-4 w-4 shrink-0" fill="none" stroke={club.color} strokeWidth={2}>
              <circle cx="12" cy="8.5" r="5.5" />
              <path d="M8.6 13 7 21l5-2.8 5 2.8-1.6-8" />
            </svg>
            <span className="truncate">{club.name}</span>
          </span>
        </div>
      ) : null}

      {ticker ? (
        <div
          role="marquee"
          aria-label={ticker.text}
          className="flex items-center gap-2 overflow-hidden rounded-full bg-accent py-2 pl-3 text-sm font-semibold text-on-accent"
        >
          <svg aria-hidden viewBox="0 0 24 24" className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth={2}>
            <path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z" />
            <path d="M15.5 8.5a5 5 0 0 1 0 7" />
          </svg>
          <div className="min-w-0 flex-1 overflow-hidden">
            <span className="ticker-run whitespace-nowrap">{ticker.text}</span>
          </div>
        </div>
      ) : null}
    </div>
  );
}
