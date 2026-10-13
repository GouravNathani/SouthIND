import type { ReactNode } from "react";
import type { WaMessageKind, WaMessageStatus, WaMessagesQuery } from "@/types/api";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Segmented from "@/components/ui/Segmented";
import { Input, Select } from "@/components/ui/Field";
import { EVENT_LABELS, MESSAGE_STATUS_LABELS } from "@/components/wahub/waFormat";

export type HistoryFilterState = {
  kind: "" | WaMessageKind;
  event: string;
  status: "" | WaMessageStatus;
  from: string;
  to: string;
  /** Number contains. */
  q: string;
};

export const EMPTY_HISTORY_FILTERS: HistoryFilterState = {
  kind: "",
  event: "",
  status: "",
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

const STATUS_ORDER: WaMessageStatus[] = [
  "sent",
  "delivered",
  "read",
  "failed",
  "skipped",
  "queued",
  "sending",
  "scheduled",
  "cancelled",
];

/** Filter state → the hub's query (event only applies to event messages). */
export const historyQuery = (filters: HistoryFilterState, q: string): WaMessagesQuery => ({
  kind: filters.kind || undefined,
  event: filters.kind === "" || filters.kind === "event" ? filters.event || undefined : undefined,
  status: filters.status || undefined,
  from: filters.from || undefined,
  to: filters.to || undefined,
  q: q.replace(/\D/g, "") || undefined,
});

export const isFiltered = (filters: HistoryFilterState) =>
  Object.values(filters).some((value) => value !== "");

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
        {eventApplies ? (
          <Select label="Event" value={value.event} onChange={(event) => patch({ event: event.target.value })}>
            <option value="">All events</option>
            {Object.entries(EVENT_LABELS).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </Select>
        ) : null}
        <div className={eventApplies ? "min-w-0" : "col-span-2 min-w-0 lg:col-span-1"}>
          <Select
            label="Status"
            value={value.status}
            onChange={(event) => patch({ status: event.target.value as HistoryFilterState["status"] })}
          >
            <option value="">All statuses</option>
            {STATUS_ORDER.map((status) => (
              <option key={status} value={status}>
                {MESSAGE_STATUS_LABELS[status]}
              </option>
            ))}
          </Select>
        </div>
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
        {isFiltered(value) ? (
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
