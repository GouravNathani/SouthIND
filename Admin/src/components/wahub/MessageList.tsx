import { useState } from "react";
import type { WaMessage } from "@/types/api";
import { EmptyState, Skeleton } from "@/components/ui/Feedback";
import MessageStatus from "@/components/wahub/MessageStatus";
import { maskNumber, messageTypeLabel } from "@/components/wahub/waFormat";
import { formatDateTime } from "@/utils/dateTime";

const PREVIEW_CHARS = 90;

/**
 * Sent-message history. A real table from xl up, a card per message below it —
 * the message text needs room to expand, which the shared DataTable's
 * truncating mobile cells can not give it.
 */
export default function MessageList({
  rows,
  loading,
  showBranch,
  emptyTitle = "No messages yet",
  emptyBody,
}: {
  rows: WaMessage[];
  loading?: boolean;
  showBranch?: boolean;
  emptyTitle?: string;
  emptyBody?: string;
}) {
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set());

  const toggle = (id: number) =>
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  if (loading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-16 w-full" />
        ))}
      </div>
    );
  }

  if (!rows.length) {
    return (
      <div className="rounded-lg border border-border bg-surface">
        <EmptyState title={emptyTitle} body={emptyBody} />
      </div>
    );
  }

  const text = (row: WaMessage) => {
    const open = expanded.has(row.id);
    const long = row.body.length > PREVIEW_CHARS || row.body.includes("\n");
    return (
      <div className="min-w-0">
        <p
          className={[
            "min-w-0 text-sm text-text",
            open ? "break-words whitespace-pre-wrap select-text" : "truncate",
          ].join(" ")}
        >
          {open ? row.body : row.body.replace(/\s+/g, " ")}
        </p>
        {long ? (
          <button
            type="button"
            onClick={() => toggle(row.id)}
            aria-expanded={open}
            className="mt-0.5 text-xs font-medium text-accent hover:underline"
          >
            {open ? "Show less" : "Show full"}
          </button>
        ) : null}
      </div>
    );
  };

  const who = (row: WaMessage) => (
    <span className="block min-w-0">
      <span className="block truncate font-medium text-text">{row.to_name || "Unknown"}</span>
      <span className="tabular block truncate text-xs text-faint">{maskNumber(row.to)}</span>
    </span>
  );

  const coins = (row: WaMessage) => (
    <span className={["tabular", row.coins ? "text-text" : "text-faint"].join(" ")}>{row.coins}</span>
  );

  return (
    <>
      {/* Desktop table */}
      <div className="hidden overflow-x-auto rounded-lg border border-border bg-surface xl:block">
        <table
          className={[
            "w-full table-fixed border-collapse text-sm",
            showBranch ? "min-w-[62rem]" : "min-w-[55rem]",
          ].join(" ")}
        >
          <colgroup>
            <col className="w-[8rem]" />
            {showBranch ? <col className="w-[7.5rem]" /> : null}
            <col className="w-[9.5rem]" />
            <col className="w-[8.5rem]" />
            <col />
            <col className="w-[9.5rem]" />
            <col className="w-[4rem]" />
          </colgroup>
          <thead>
            <tr className="border-b border-border">
              {["Time (IST)", ...(showBranch ? ["Branch"] : []), "User", "Type", "Message", "Status", "Coins"].map(
                (header) => (
                  <th
                    key={header}
                    scope="col"
                    className={[
                      "px-4 py-3 text-[11px] font-semibold tracking-wide text-muted uppercase",
                      header === "Coins" ? "text-right" : "text-left",
                    ].join(" ")}
                  >
                    {header}
                  </th>
                )
              )}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-border align-top last:border-0">
                <td className="tabular px-4 py-3 text-xs whitespace-nowrap text-muted">
                  {formatDateTime(row.created_at)}
                </td>
                {showBranch ? (
                  <td className="px-4 py-3 text-sm break-words text-text">{row.branch_name ?? "—"}</td>
                ) : null}
                <td className="px-4 py-3">{who(row)}</td>
                <td className="px-4 py-3 text-sm break-words text-muted">{messageTypeLabel(row)}</td>
                <td className="px-4 py-3">{text(row)}</td>
                <td className="px-4 py-3">
                  <MessageStatus message={row} />
                </td>
                <td className="px-4 py-3 text-right">{coins(row)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile cards */}
      <ul className="space-y-2 xl:hidden">
        {rows.map((row) => (
          <li key={row.id} className="min-w-0 rounded-lg border border-border bg-surface p-3.5">
            <div className="flex min-w-0 items-start justify-between gap-3">
              <div className="min-w-0 flex-1 text-sm">{who(row)}</div>
              <div className="shrink-0">
                <MessageStatus message={row} />
              </div>
            </div>
            <div className="mt-2">{text(row)}</div>
            <div className="mt-2 flex min-w-0 items-center justify-between gap-3 text-xs text-faint">
              <span className="min-w-0 truncate">
                {[showBranch ? row.branch_name : null, messageTypeLabel(row), formatDateTime(row.created_at)]
                  .filter(Boolean)
                  .join(" · ")}
              </span>
              <span className="shrink-0">
                {coins(row)} {row.coins === 1 ? "coin" : "coins"}
              </span>
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
