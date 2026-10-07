import type { WaMessage } from "@/types/api";
import { IconClock } from "@/components/icons";
import WaPill from "@/components/wahub/WaPill";
import { MESSAGE_STATUS_LABELS, skipReasonLabel } from "@/components/wahub/waFormat";

/** WhatsApp's own tick language: one grey, two grey, two blue. */
function Ticks({ double, read }: { double?: boolean; read?: boolean }) {
  return (
    <svg
      width={double ? 18 : 13}
      height={12}
      viewBox={double ? "0 0 18 12" : "0 0 13 12"}
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      style={{ color: read ? "var(--info)" : "var(--text-muted)" }}
    >
      <path d="m1.5 6.5 3.2 3.2L11.5 2" />
      {double ? <path d="m8.7 9 .7.7L16.2 2" /> : null}
    </svg>
  );
}

export default function MessageStatus({ message }: { message: Pick<WaMessage, "status" | "skip_reason" | "error"> }) {
  const label = MESSAGE_STATUS_LABELS[message.status] ?? message.status;

  switch (message.status) {
    case "sent":
    case "delivered":
    case "read":
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap text-muted">
          <Ticks double={message.status !== "sent"} read={message.status === "read"} />
          <span style={message.status === "read" ? { color: "var(--info)" } : undefined}>{label}</span>
        </span>
      );
    case "failed":
      return (
        <WaPill tone="neg" title={message.error ?? undefined}>
          {message.error ? `Failed · ${plainError(message.error)}` : "Failed"}
        </WaPill>
      );
    case "skipped":
      return <WaPill tone="neutral">{skipReasonLabel(message.skip_reason)}</WaPill>;
    case "cancelled":
      return <WaPill tone="neutral">Cancelled</WaPill>;
    default:
      // scheduled / queued / sending
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap text-faint">
          <IconClock size={13} />
          {label}
        </span>
      );
  }
}

/** Hub errors are short codes or library text; keep the chip readable. */
const plainError = (error: string) => {
  const text = error.replace(/_/g, " ").trim();
  return text.length > 28 ? `${text.slice(0, 27)}…` : text;
};
