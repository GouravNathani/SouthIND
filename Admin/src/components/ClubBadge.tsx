import type { UserClub } from "@/types/api";

/** The club the system put a user in, as a small coloured badge. */
export default function ClubBadge({ club }: { club: UserClub }) {
  return (
    <span
      title={`${club.active_days} active day${club.active_days === 1 ? "" : "s"}`}
      className="inline-flex max-w-full shrink-0 items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold"
      style={{
        borderColor: `color-mix(in srgb, ${club.color} 45%, transparent)`,
        background: `color-mix(in srgb, ${club.color} 14%, transparent)`,
        color: club.color,
      }}
    >
      <span aria-hidden className="size-1.5 shrink-0 rounded-full" style={{ background: club.color }} />
      <span className="truncate">{club.name}</span>
    </span>
  );
}
