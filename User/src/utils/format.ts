/** Indian digit grouping everywhere — 1,24,500, never 124,500. */
export const inr = (value: number | string | null | undefined): string => {
  const amount = typeof value === "string" ? Number(value) : (value ?? 0);
  if (!Number.isFinite(amount)) return "0";
  return Math.round(amount).toLocaleString("en-IN");
};

export const money = (value: number | string | null | undefined) => `₹${inr(value)}`;

export const toNumber = (value: unknown): number => {
  const parsed = typeof value === "string" ? Number(value) : typeof value === "number" ? value : NaN;
  return Number.isFinite(parsed) ? parsed : 0;
};

const RELATIVE_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
  ["second", 60],
  ["minute", 60],
  ["hour", 24],
  ["day", 7],
];

/** "2 min ago" for anything under a week, an absolute date after that. */
export const relativeTime = (value?: string | null): string => {
  if (!value) return "";
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return "";

  let delta = (ts - Date.now()) / 1000;
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

  for (const [unit, step] of RELATIVE_UNITS) {
    if (Math.abs(delta) < step) return formatter.format(Math.round(delta), unit);
    delta /= step;
  }
  return new Date(ts).toLocaleDateString(undefined, { day: "numeric", month: "short" });
};

export const dateTime = (value?: string | null): string => {
  if (!value) return "";
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return "";
  return new Date(ts).toLocaleString(undefined, {
    day: "numeric",
    month: "short",
    hour: "numeric",
    minute: "2-digit",
  });
};
