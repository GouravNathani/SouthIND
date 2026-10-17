import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";
import type {
  WaCampaignCounts,
  WaCampaignStatus,
  WaConnection,
  WaConnectionStatus,
  WaMessageKind,
  WaMessageStatus,
  WaPageMeta,
  PaginationMeta,
} from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { inr, toNumber } from "@/utils/format";

export type WaTone = "neutral" | "pos" | "neg" | "warn" | "info" | "accent";

// ── Connection ──────────────────────────────────────────────────────────────

export const CONNECTION_STATUS: Record<WaConnectionStatus, { label: string; tone: WaTone }> = {
  not_linked: { label: "Not connected", tone: "neutral" },
  starting: { label: "Preparing QR", tone: "warn" },
  qr: { label: "Waiting for scan", tone: "warn" },
  connected: { label: "Connected", tone: "pos" },
  expired: { label: "Not connected", tone: "neutral" },
  logged_out: { label: "Logged out", tone: "neg" },
  banned: { label: "Banned", tone: "neg" },
  replaced: { label: "Replaced", tone: "warn" },
};

export const connectionStatus = (status?: string | null) =>
  CONNECTION_STATUS[(status ?? "not_linked") as WaConnectionStatus] ?? CONNECTION_STATUS.not_linked;

export const isLinking = (connection?: Pick<WaConnection, "status"> | null) =>
  connection?.status === "starting" || connection?.status === "qr";

/**
 * asleep / waking are the hub saving resources between sends; to an admin the
 * number is simply connected. Only "down" is worth a word.
 */
export const isReconnecting = (connection?: Pick<WaConnection, "status" | "socket"> | null) =>
  connection?.status === "connected" && connection.socket === "down";

const PLATFORMS: Record<string, string> = {
  android: "Android",
  ios: "iPhone",
  iphone: "iPhone",
  smba: "WhatsApp Business · Android",
  smbi: "WhatsApp Business · iPhone",
  web: "Web",
  macos: "Mac",
  windows: "Windows",
};

export const platformLabel = (platform?: string | null) => {
  if (!platform) return "—";
  const known = PLATFORMS[platform.toLowerCase()];
  return known ?? platform.charAt(0).toUpperCase() + platform.slice(1);
};

// ── Numbers ─────────────────────────────────────────────────────────────────

/**
 * "+91 98•••••210". Numbers the server already masked ("91******3210") keep
 * their mask and only get the country code split off.
 */
export const maskNumber = (raw?: string | null): string => {
  if (!raw) return "—";
  if (/[*•]/.test(raw)) {
    const masked = raw.replace(/\*/g, "•").replace(/^\+/, "");
    return masked.length === 12 && masked.startsWith("91") ? `+91 ${masked.slice(2)}` : masked;
  }
  const digits = raw.replace(/\D/g, "");
  if (digits.length < 8) return digits || "—";
  const national = digits.length > 10 ? digits.slice(-10) : digits;
  const country = digits.length > 10 ? digits.slice(0, -10) : "";
  const hidden = "•".repeat(Math.max(0, national.length - 5));
  const masked = `${national.slice(0, 2)}${hidden}${national.slice(-3)}`;
  return country ? `+${country} ${masked}` : masked;
};

export const count = (value?: number | null) => inr(value ?? 0);

// ── Coins ───────────────────────────────────────────────────────────────────

// Coins can be fractions (0.25 per message), so never round them like counts.
const coinFormat = new Intl.NumberFormat("en-IN", { maximumFractionDigits: 2 });

/** Coins with up to 2 decimals: "0.25", "32.5", "1,240". */
export const coins = (value?: number | string | null) => coinFormat.format(toNumber(value));

/** "1 coin" / "0.25 coins" / "32.5 coins". */
export const coinsLabel = (value?: number | string | null) => {
  const amount = toNumber(value);
  return `${coins(amount)} ${amount === 1 ? "coin" : "coins"}`;
};

/** What one sent message costs when the backend has not said (Control's WA Hub Out default). */
export const DEFAULT_COINS_PER_MESSAGE = 0.25;

/** The backend's `coins_per_message`, or the default while it is missing. */
export const coinRate = (value?: number | string | null): number => {
  if (value === null || value === undefined || value === "") return DEFAULT_COINS_PER_MESSAGE;
  const rate = Number(value);
  return Number.isFinite(rate) && rate >= 0 ? rate : DEFAULT_COINS_PER_MESSAGE;
};

/** "0.25 coins per message". */
export const rateLabel = (rate: number) => `${coinsLabel(rate)} per message`;

// ── Events and messages ─────────────────────────────────────────────────────

/** The hub's fixed catalogue — used where the events list is not loaded. */
export const EVENT_LABELS: Record<string, string> = {
  "deposit.approved": "Deposit approved",
  "deposit.rejected": "Deposit rejected",
  "withdrawal.approved": "Withdrawal approved",
  "withdrawal.rejected": "Withdrawal rejected",
  "user.welcome": "New user welcome",
  "mpin.reset": "MPIN reset",
  "bonus.approved": "Bonus approved",
};

export const eventLabel = (key?: string | null) => (key ? (EVENT_LABELS[key] ?? key) : "—");

export const KIND_LABELS: Record<WaMessageKind, string> = {
  event: "Event",
  campaign: "Campaign",
  test: "Test",
};

export const messageTypeLabel = (message: { kind: WaMessageKind; event: string | null }) =>
  message.kind === "event" ? eventLabel(message.event) : (KIND_LABELS[message.kind] ?? message.kind);

/** Why a message was not sent, in words an admin understands. */
export const SKIP_REASONS: Record<string, string> = {
  event_off: "Event is off",
  not_connected: "WhatsApp not connected",
  disabled: "Branch paused",
  opted_out: "Excluded from WhatsApp",
  bad_number: "Invalid number",
  no_whatsapp: "Not on WhatsApp",
  cap_reached: "Daily limit reached",
  duplicate: "Already sent in 24 h",
  cancelled: "Cancelled",
};

export const skipReasonLabel = (reason?: string | null) =>
  reason ? (SKIP_REASONS[reason] ?? reason.replace(/_/g, " ")) : "Skipped";

export const MESSAGE_STATUS_LABELS: Record<WaMessageStatus, string> = {
  scheduled: "Scheduled",
  queued: "Queued",
  sending: "Sending",
  sent: "Sent",
  delivered: "Delivered",
  read: "Read",
  failed: "Failed",
  skipped: "Skipped",
  cancelled: "Cancelled",
};

// ── Campaigns ───────────────────────────────────────────────────────────────

export const CAMPAIGN_STATUS: Record<WaCampaignStatus, { label: string; tone: WaTone }> = {
  scheduled: { label: "Scheduled", tone: "info" },
  running: { label: "Sending", tone: "warn" },
  done: { label: "Done", tone: "pos" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const campaignStatus = (status?: string | null) =>
  CAMPAIGN_STATUS[(status ?? "scheduled") as WaCampaignStatus] ?? {
    label: status ?? "—",
    tone: "neutral" as const,
  };

/** Shares of the bar: reached the phone, dropped (failed + skipped), still waiting. */
export const campaignProgress = (counts?: Partial<WaCampaignCounts> | null) => {
  const total = Math.max(0, counts?.total ?? 0);
  const pending = Math.max(0, counts?.pending ?? 0);
  const reached = (counts?.sent ?? 0) + (counts?.delivered ?? 0) + (counts?.read ?? 0);
  const dropped = (counts?.failed ?? 0) + (counts?.skipped ?? 0);
  const pct = (value: number) => (total > 0 ? Math.min(100, (value / total) * 100) : 0);
  return {
    total,
    done: Math.max(0, total - pending),
    reached,
    dropped,
    reachedPct: pct(reached),
    droppedPct: pct(dropped),
  };
};

export const CAMPAIGN_RULES_HINT =
  "Campaigns go out only between 9 AM and 9 PM IST, slowly (a few messages a minute). " +
  "Anything left continues the next day at 9 AM. A user gets at most one campaign a day.";

// ── Dates (IST) ─────────────────────────────────────────────────────────────

const istParts = (date: Date) => {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Kolkata",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(date);
  const pick = (type: string) => parts.find((part) => part.type === type)?.value ?? "00";
  return {
    year: pick("year"),
    month: pick("month"),
    day: pick("day"),
    hour: pick("hour") === "24" ? "00" : pick("hour"),
    minute: pick("minute"),
  };
};

/** Now in IST as a datetime-local value ("2026-10-07T18:30"). */
export const istLocalNow = (offsetMinutes = 0) => {
  const p = istParts(new Date(Date.now() + offsetMinutes * 60000));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
};

/** A datetime-local value read as IST wall-clock, as ISO-8601 with the offset. */
export const istLocalToIso = (value: string) => (value ? `${value.length === 16 ? `${value}:00` : value}+05:30` : "");

// ── Errors ──────────────────────────────────────────────────────────────────

type ErrorBody = { code?: string; error?: string; message?: string; errors?: Record<string, string[]> };

const errorBody = (error: unknown): ErrorBody | undefined => {
  if (!error || typeof error !== "object" || !("status" in error)) return undefined;
  const data = (error as FetchBaseQueryError).data;
  return data && typeof data === "object" ? (data as ErrorBody) : undefined;
};

/** The stable code from either our backend (`code`) or the hub (`error`). */
export const waErrorCode = (error: unknown): string | undefined => {
  const body = errorBody(error);
  return body?.code ?? (typeof body?.error === "string" ? body.error : undefined);
};

export const isWaUnavailable = (error: unknown) => {
  const code = waErrorCode(error);
  if (code === "wa_hub_unavailable" || code === "hub_starting") return true;
  const status = (error as FetchBaseQueryError | undefined)?.status;
  return status === 502 || status === 503 || status === 504;
};

export const WA_OFF_MESSAGE = "WhatsApp Hub is turned off in Global settings.";
export const WA_UNAVAILABLE_MESSAGE = "WhatsApp service is not reachable, try again.";

/** One message for every WhatsApp call, with the two hub-wide failures worded kindly. */
export const waErrorMessage = (error: unknown, fallback: string): string => {
  const code = waErrorCode(error);
  if (code === "wa_hub_disabled") return WA_OFF_MESSAGE;
  if (isWaUnavailable(error)) return WA_UNAVAILABLE_MESSAGE;
  if ((error as FetchBaseQueryError | undefined)?.status === "FETCH_ERROR") {
    return "No connection. Check the internet and try again.";
  }
  const body = errorBody(error);
  if (code === "unknown_variable") {
    return body?.message || "This text uses a variable that does not exist here.";
  }
  if (code === "name_taken") return "A template with this name already exists.";
  if (code === "no_recipients") return "None of these users can get a WhatsApp message.";
  if (code === "too_many_recipients") {
    return body?.message || "This audience is too large for one campaign. Narrow it down.";
  }
  const firstFieldError = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
  return firstFieldError || resolveErrorMessage(error, fallback);
};

// ── Paging ──────────────────────────────────────────────────────────────────

/** The hub pages as { total, page, per_page }; the shared Pagination wants last_page. */
export const toPaginationMeta = (meta?: WaPageMeta | null): PaginationMeta | undefined =>
  meta
    ? {
        current_page: meta.page,
        per_page: meta.per_page,
        total: meta.total,
        last_page: Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page))),
      }
    : undefined;
