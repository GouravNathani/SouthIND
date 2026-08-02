import Badge, { statusTone } from "@/components/ui/Badge";
import { inr, relativeTime } from "@/utils/format";
import type { DepositRecord, WithdrawalRecord } from "@/services/api";

export type LedgerEntry = (DepositRecord | WithdrawalRecord) & {
  kind: "deposit" | "withdrawal";
};

const destinationLabel = (entry: LedgerEntry) => {
  if (entry.upi_id) return entry.upi_id;
  if (entry.account_number) return `A/C ••••${entry.account_number.slice(-4)}`;
  return entry.account_name ?? entry.destination_type ?? "";
};

/**
 * One money movement. A rejected row is deliberately NOT rendered as a signed
 * amount — no money moved, so a green "+₹5,000" there would be a lie.
 */
export default function TransactionRow({ entry }: { entry: LedgerEntry }) {
  const tone = statusTone(entry.status);
  const dead = tone === "neg";
  const incoming = entry.kind === "deposit";

  return (
    <li className="flex min-w-0 items-center gap-3 border-b border-border py-3 last:border-0">
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-text">
          {incoming ? "Deposit" : "Withdrawal"}
          {destinationLabel(entry) ? (
            <span className="text-muted"> · {destinationLabel(entry)}</span>
          ) : null}
        </p>
        <p className="truncate text-xs text-faint">{relativeTime(entry.created_at)}</p>
      </div>

      <div className="flex shrink-0 flex-col items-end gap-1">
        <span
          className="tabular text-sm font-semibold"
          style={{ color: dead ? "var(--text-faint)" : incoming ? "var(--pos)" : "var(--text)" }}
        >
          {dead ? "" : incoming ? "+" : "−"}₹{inr(entry.amount)}
        </span>
        <Badge tone={tone}>{entry.status}</Badge>
      </div>
    </li>
  );
}
