export const formatDateTime = (value?: string | Date | null): string => {
  if (!value) {
    return "-";
  }
  if (typeof value === "string") {
    const match = value.match(
      /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/,
    );
    if (match) {
      const [, year, month, day, hour, minute] = match;
      return `${day}-${month}-${year} ${hour}:${minute}`;
    }
  }
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "-";
  }
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Kolkata",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? "";
  const day = pick("day");
  const month = pick("month");
  const year = pick("year");
  const hour = pick("hour");
  const minute = pick("minute");
  if (!day || !month || !year || !hour || !minute) {
    return "-";
  }
  return `${day}-${month}-${year} ${hour}:${minute}`;
};

// The backend serializes timestamps as IST wall-clock with NO timezone suffix
// (e.g. "2026-07-18T23:23:31"). Passing that straight to new Date() makes the
// browser read it in ITS OWN timezone, so on a non-IST device "last seen" is
// off by the IST offset (5.5h) and "Active now" misfires. Pin the offset-less
// form to IST (+05:30) so relative time is correct regardless of device tz.
const OFFSETLESS_ISO = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/;
export const parseServerDate = (value?: string | Date | null): Date | null => {
  if (!value) return null;
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }
  const normalized = OFFSETLESS_ISO.test(value)
    ? `${value.replace(" ", "T")}+05:30`
    : value;
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
};

// True if the timestamp is within the last 2 minutes (treated as "online now").
export const isActiveNow = (value?: string | Date | null): boolean => {
  const last = parseServerDate(value);
  if (!last) return false;
  return Date.now() - last.getTime() <= 2 * 60 * 1000;
};

// Human "last seen" label: "Active now", "5 min ago", "2 hours ago", then a date.
export const formatLastSeen = (value?: string | Date | null): string => {
  const last = parseServerDate(value);
  if (!last) {
    return "Never";
  }
  const diffMs = Date.now() - last.getTime();
  if (diffMs <= 2 * 60 * 1000) {
    return "Active now";
  }
  const minutes = Math.max(1, Math.floor(diffMs / 60000));
  if (minutes < 60) {
    return `${minutes} min ago`;
  }
  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    return `${hours} hour${hours === 1 ? "" : "s"} ago`;
  }
  const days = Math.floor(hours / 24);
  if (days < 3) {
    return `${days} day${days === 1 ? "" : "s"} ago`;
  }
  return formatDateTime(last);
};

export const formatDateForApi = (value?: string | Date | null): string => {
  if (!value) {
    return "";
  }
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Kolkata",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? "";
  const day = pick("day");
  const month = pick("month");
  const year = pick("year");
  if (!day || !month || !year) {
    return "";
  }
  return `${year}-${month}-${day}`;
};

export const formatDuration = (seconds?: number | null): string => {
  if (seconds === null || seconds === undefined) {
    return "-";
  }
  const totalSeconds = Math.max(0, Math.floor(seconds));
  const hrs = Math.floor(totalSeconds / 3600);
  const mins = Math.floor((totalSeconds % 3600) / 60);
  const secs = totalSeconds % 60;

  if (hrs > 0) {
    return `${hrs}h ${mins}m`;
  }
  if (mins > 0) {
    return `${mins}m ${secs}s`;
  }
  return `${secs}s`;
};
