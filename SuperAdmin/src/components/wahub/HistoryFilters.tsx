import type { ReactNode } from "react";
import type { WaMessageKind, WaMessagesQuery } from "@/types/api";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Segmented from "@/components/ui/Segmented";
import { Input, Select } from "@/components/ui/Field";
import { EVENT_LABELS } from "@/components/wahub/waFormat";

/**
 * "" = every message that went out or is still going (not-sent rows left out:
 * "Already sent", STOP, no WhatsApp… would read as extra messages).
 */
export type HistoryShow = "" | "sent" | "pending" | "failed" | "skipped";
export type HistoryOrder = "newest" | "oldest";

export type HistoryFilterState = {
  kind: "" | WaMessageKind;
  event: string;
  show: HistoryShow;
  order: HistoryOrder;
  from: string;
  to: string;
  /** Number contains. */
  q: string;
};

export const EMPTY_HISTORY_FILTERS: HistoryFilterState = {
  kind: "",
  event: "",
  show: "",
  order: "newest",
  from: "",
  to: "",
  q: "",
};

const KIND_OPTIONS = [
  { value: "", label: "All" },
  { value: "event", label: "Event" },
  { value: "campaign", label: "Campaign" },
  { value: "test", label: "Test" },
] as const satisfies readonly { value: HistoryFilterState["kind"]; label: string }[];

const SHOW_OPTIONS = [
  { value: "", label: "All" },
  { value: "sent", label: "Sent" },
  { value: "pending", label: "Pending" },
  { value: "failed", label: "Failed" },
  { value: "skipped", label: "Not sent" },
] as const satisfies readonly { value: HistoryShow; label: string }[];

const ORDER_OPTIONS = [
  { value: "newest", label: "Newest" },
  { value: "oldest", label: "Oldest" },
] as const satisfies readonly { value: HistoryOrder; label: string }[];

const SHOW_STATUSES: Record<HistoryShow, string> = {
  "": "scheduled,queued,sending,sent,delivered,read,failed",
  sent: "sent,delivered,read",
  pending: "scheduled,queued,sending",
  failed: "failed",
  skipped: "skipped,cancelled",
};

/** Filter state → the hub's query (event only applies to event messages). */
export const historyQuery = (filters: HistoryFilterState, q: string): WaMessagesQuery => ({
  kind: filters.kind || undefined,
  event: filters.kind === "" || filters.kind === "event" ? filters.event || undefined : undefined,
  status: SHOW_STATUSES[filters.show],
  order: filters.order === "oldest" ? "oldest" : undefined,
  from: filters.from || undefined,
  to: filters.to || undefined,
  q: q.replace(/\D/g, "") || undefined,
});

export const isFiltered = (filters: HistoryFilterState) =>
  (Object.keys(EMPTY_HISTORY_FILTERS) as (keyof HistoryFilterState)[]).some(
    (key) => key !== "order" && filters[key] !== EMPTY_HISTORY_FILTERS[key]
  );

export default function HistoryFilters({
  value,
  onChange,
  leading,
}: {
  value: HistoryFilterState;
  onChange: (next: HistoryFilterState) => void;
  /** Extra control placed first (the super admin's branch picker). */
  leading?: ReactNode;
}) {
  const patch = (partial: Partial<HistoryFilterState>) => onChange({ ...value, ...partial });
  const eventApplies = value.kind === "" || value.kind === "event";
  const changed = isFiltered(value) || value.order !== EMPTY_HISTORY_FILTERS.order;

  return (
    <Card>
      {/* Two columns on a phone (paired fields side by side keep the card short), four on desktop. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {leading ? <div className="col-span-2 min-w-0 lg:col-span-1">{leading}</div> : null}
        <Segmented
          label="Type"
          value={value.kind}
          onChange={(kind) => patch({ kind })}
          options={KIND_OPTIONS}
          className="col-span-2"
        />
        <Segmented
          label="Show"
          value={value.show}
          onChange={(show) => patch({ show })}
          options={SHOW_OPTIONS}
          breakAfter={3}
          className="col-span-2"
          hint={
            value.show === ""
              ? "Messages skipped before sending (already sent today, STOP, no WhatsApp) are under Not sent."
              : undefined
          }
        />
        <Segmented
          label="Sort"
          value={value.order}
          onChange={(order) => patch({ order })}
          options={ORDER_OPTIONS}
          className="col-span-2 lg:col-span-1"
        />
        {eventApplies ? (
          <div className="col-span-2 min-w-0 lg:col-span-1">
            <Select label="Event" value={value.event} onChange={(event) => patch({ event: event.target.value })}>
              <option value="">All events</option>
              {Object.entries(EVENT_LABELS).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </Select>
          </div>
        ) : null}
        <Input
          label="From"
          type="date"
          value={value.from}
          max={value.to || undefined}
          onChange={(event) => patch({ from: event.target.value })}
        />
        <Input
          label="To"
          type="date"
          value={value.to}
          min={value.from || undefined}
          onChange={(event) => patch({ to: event.target.value })}
        />
        <div className="col-span-2 min-w-0 lg:col-span-1">
          <Input
            label="Number"
            inputMode="numeric"
            placeholder="Search by number"
            value={value.q}
            onChange={(event) => patch({ q: event.target.value.replace(/[^\d+\s]/g, "") })}
          />
        </div>
        {changed ? (
          <div className="col-span-2 flex items-end lg:col-span-1">
            <Button variant="ghost" onClick={() => onChange(EMPTY_HISTORY_FILTERS)}>
              Clear filters
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  );
}
