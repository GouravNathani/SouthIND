import { formatDateForApi } from "@/utils/dateTime";

export const TIMEFRAMES = [
  { id: "today", label: "Today" },
  { id: "yesterday", label: "Yesterday" },
  { id: "week", label: "Week" },
  { id: "thisMonth", label: "This month" },
  { id: "lastMonth", label: "Last month" },
  { id: "range", label: "Custom" },
] as const;

export type TimeframeId = (typeof TIMEFRAMES)[number]["id"];

const startOfToday = () => {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  return start;
};

/** Resolves a timeframe id to an inclusive [start, end] pair in local time. */
export const resolveRange = (id: TimeframeId): { start: Date; end: Date } => {
  const now = new Date();
  const todayStart = startOfToday();

  switch (id) {
    case "yesterday": {
      const start = new Date(todayStart);
      start.setDate(start.getDate() - 1);
      const end = new Date(todayStart);
      end.setMilliseconds(-1);
      return { start, end };
    }
    case "week": {
      const start = new Date(todayStart);
      start.setDate(start.getDate() - 6);
      return { start, end: now };
    }
    case "thisMonth": {
      const start = new Date(todayStart.getFullYear(), todayStart.getMonth(), 1);
      return { start, end: now };
    }
    case "lastMonth": {
      const start = new Date(todayStart.getFullYear(), todayStart.getMonth() - 1, 1);
      const end = new Date(todayStart.getFullYear(), todayStart.getMonth(), 1);
      end.setMilliseconds(-1);
      return { start, end };
    }
    case "today":
    case "range":
    default:
      return { start: todayStart, end: now };
  }
};

export const rangeParams = (id: TimeframeId, custom?: { start: string; end: string }) => {
  if (id === "range") {
    return {
      start_date: custom?.start || undefined,
      end_date: custom?.end || undefined,
    };
  }
  const { start, end } = resolveRange(id);
  return { start_date: formatDateForApi(start), end_date: formatDateForApi(end) };
};
