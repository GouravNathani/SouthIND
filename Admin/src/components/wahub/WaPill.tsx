import type { ReactNode } from "react";
import type { WaTone } from "@/components/wahub/waFormat";

const TONE: Record<WaTone, { color: string; background: string }> = {
  neutral: { color: "var(--text-muted)", background: "var(--surface-2)" },
  pos: { color: "var(--pos)", background: "color-mix(in srgb, var(--pos) 14%, transparent)" },
  neg: { color: "var(--neg)", background: "color-mix(in srgb, var(--neg) 14%, transparent)" },
  warn: { color: "var(--warn)", background: "color-mix(in srgb, var(--warn) 16%, transparent)" },
  info: { color: "var(--info)", background: "color-mix(in srgb, var(--info) 14%, transparent)" },
  accent: { color: "var(--accent)", background: "var(--accent-soft)" },
};

/**
 * Badge's shape, but sentence case: WhatsApp statuses are phrases ("Waiting
 * for scan", "Not on WhatsApp") that read oddly in Title Case.
 */
export default function WaPill({
  tone = "neutral",
  icon,
  children,
  title,
}: {
  tone?: WaTone;
  icon?: ReactNode;
  children: ReactNode;
  title?: string;
}) {
  return (
    <span
      title={title}
      className="inline-flex max-w-full min-w-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold tracking-wide whitespace-nowrap [&_svg]:shrink-0"
      style={TONE[tone]}
    >
      {icon}
      <span className="min-w-0 truncate">{children}</span>
    </span>
  );
}

/** A tinted one-line note (paused branch, reconnecting, campaign rules). */
export function WaNotice({
  tone = "warn",
  icon,
  children,
}: {
  tone?: WaTone;
  icon?: ReactNode;
  children: ReactNode;
}) {
  return (
    <div
      className="flex min-w-0 items-start gap-2 rounded-md px-3 py-2.5 text-xs leading-relaxed [&_svg]:mt-px [&_svg]:shrink-0"
      style={{ color: TONE[tone].color, background: TONE[tone].background }}
    >
      {icon}
      <div className="min-w-0">{children}</div>
    </div>
  );
}
