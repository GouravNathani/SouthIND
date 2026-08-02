import type { ReactNode } from "react";
import { EmptyState, Skeleton } from "@/components/ui/Feedback";

export type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  /** Hidden in the mobile card view — noise on a small screen. */
  secondary?: boolean;
  align?: "left" | "right";
};

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
                    {column.render(row)}
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
                <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5">
                  {rest
                    .filter((column) => !column.secondary)
                    .map((column) => (
                      <div key={column.key} className="min-w-0">
                        <dt className="truncate text-[10.5px] tracking-wide text-faint uppercase">
                          {column.header}
                        </dt>
                        <dd className="min-w-0 truncate text-sm text-text">{column.render(row)}</dd>
                      </div>
                    ))}
                </dl>
              </div>
            </li>
          );
        })}
      </ul>
    </>
  );
}
