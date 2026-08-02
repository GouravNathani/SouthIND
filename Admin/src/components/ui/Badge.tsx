import type { ReactNode } from "react";

export type Tone = "neutral" | "pos" | "neg" | "warn" | "info" | "accent";

const TONE: Record<Tone, { color: string; background: string }> = {
  neutral: { color: "var(--text-muted)", background: "var(--surface-2)" },
  pos: { color: "var(--pos)", background: "color-mix(in srgb, var(--pos) 14%, transparent)" },
  neg: { color: "var(--neg)", background: "color-mix(in srgb, var(--neg) 14%, transparent)" },
  warn: { color: "var(--warn)", background: "color-mix(in srgb, var(--warn) 16%, transparent)" },
  info: { color: "var(--info)", background: "color-mix(in srgb, var(--info) 14%, transparent)" },
  accent: { color: "var(--accent)", background: "var(--accent-soft)" },
};

/** Maps the backend's status strings onto a tone once, so no page re-invents it. */
export const statusTone = (status?: string | null): Tone => {
  const value = (status ?? "").toLowerCase();
  if (["approved", "success", "completed", "fulfilled", "paid", "active"].includes(value)) return "pos";
  if (["rejected", "failed", "cancelled", "canceled", "void", "suspended", "banned"].includes(value))
    return "neg";
  if (["pending", "processing", "awaiting_deposit", "in_review"].includes(value)) return "warn";
  return "neutral";
};

export default function Badge({ tone = "neutral", children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span
      className="inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-semibold tracking-wide capitalize"
      style={TONE[tone]}
    >
      <span className="truncate">{children}</span>
    </span>
  );
}
