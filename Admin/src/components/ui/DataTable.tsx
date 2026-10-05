import type { ReactNode } from "react";
import { EmptyState, Skeleton } from "@/components/ui/Feedback";

export type Column<T> = {
  /** "actions" is special: on a phone it renders as a full-width footer row of the
   *  card rather than a half-width cell, where its buttons would be clipped. */
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  /** Hidden in the mobile card view — noise on a small screen. */
  secondary?: boolean;
  align?: "left" | "right";
};

const isActions = <T,>(column: Column<T>) => column.key === "actions";

/**
 * One dataset, two presentations. A real <table> on lg+ (inside its own
 * horizontal scroller so a wide row can never widen the page) and a stack of
 * label/value cards below it. Tables are the single biggest source of layout
 * overflow on a phone, so admin lists never render one there.
 */
export default function DataTable<T>({
  columns,
  rows,
  keyOf,
  onRowClick,
  loading,
  emptyTitle,
  emptyBody,
}: {
  columns: Column<T>[];
  rows: T[];
  keyOf: (row: T) => string | number;
  onRowClick?: (row: T) => void;
  loading?: boolean;
  emptyTitle: string;
  emptyBody?: string;
}) {
  if (loading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-14 w-full" />
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

  return (
    <>
      {/* Desktop table */}
      <div className="hidden overflow-x-auto rounded-lg border border-border bg-surface lg:block">
        <table className="w-full min-w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-border">
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={[
                    "px-4 py-3 text-[11px] font-semibold tracking-wide text-muted uppercase",
                    column.align === "right" ? "text-right" : "text-left",
                  ].join(" ")}
                >
                  {column.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={keyOf(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                className={[
                  "border-b border-border last:border-0",
                  onRowClick ? "cursor-pointer hover:bg-surface-2" : "",
                ].join(" ")}
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={[
                      "px-4 py-3 align-middle",
                      column.align === "right" ? "text-right" : "text-left",
                    ].join(" ")}
                  >
                    {isActions(column) ? (
                      column.render(row)
                    ) : (
                      // An auto-layout table sizes a column to its widest unbroken
                      // text, so a long UPI id or UTR would widen the whole table.
                      // The cap gives `truncate` inside a cell a width to work with.
                      <div className="max-w-[20rem] min-w-0 break-words">{column.render(row)}</div>
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile cards */}
      <ul className="space-y-2 lg:hidden">
        {rows.map((row) => {
          const [lead, ...rest] = columns;
          const fields = rest.filter((column) => !column.secondary && !isActions(column));
          // A row with nothing to act on (e.g. an already-decided payout) gets no footer.
          const actions = rest
            .filter(isActions)
            .map((column) => ({ key: column.key, node: column.render(row) }))
            .filter((entry) => entry.node !== null && entry.node !== undefined && entry.node !== false);
          return (
            <li key={keyOf(row)}>
              <div
                role={onRowClick ? "button" : undefined}
                tabIndex={onRowClick ? 0 : undefined}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={
                  onRowClick
                    ? (event) => {
                        if (event.key === "Enter" || event.key === " ") onRowClick(row);
                      }
                    : undefined
                }
                className="min-w-0 rounded-lg border border-border bg-surface p-3.5"
              >
                <div className="min-w-0 text-sm font-semibold text-text">{lead?.render(row)}</div>
                {fields.length ? (
                  <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5">
                    {fields.map((column) => (
                      <div key={column.key} className="min-w-0">
                        <dt className="truncate text-[10.5px] tracking-wide text-faint uppercase">
                          {column.header}
                        </dt>
                        <dd className="min-w-0 truncate text-sm text-text">{column.render(row)}</dd>
                      </div>
                    ))}
                  </dl>
                ) : null}
                {actions.map((entry) => (
                  <div key={entry.key} className="mt-3 min-w-0 border-t border-border pt-3">
                    {entry.node}
                  </div>
                ))}
              </div>
            </li>
          );
        })}
      </ul>
    </>
  );
}
