import type { ReactNode } from "react";
import Card from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Field";
import { TIMEFRAMES, type TimeframeId } from "@/utils/timeframe";

export const STATUS_OPTIONS = ["", "pending", "approved", "rejected"] as const;
export type StatusFilter = (typeof STATUS_OPTIONS)[number];

export type QueueFilterState = {
  search: string;
  status: StatusFilter;
  timeframe: TimeframeId;
  customStart: string;
  customEnd: string;
};

export const EMPTY_FILTERS: QueueFilterState = {
  search: "",
  status: "pending",
  timeframe: "week",
  customStart: "",
  customEnd: "",
};

/** Filter bar shared by the deposit and withdrawal queues. */
export default function QueueFilters({
  value,
  onChange,
  right,
}: {
  value: QueueFilterState;
  onChange: (next: QueueFilterState) => void;
  right?: ReactNode;
}) {
  const patch = (partial: Partial<QueueFilterState>) => onChange({ ...value, ...partial });

  return (
    <Card>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Input
          label="Search"
          placeholder="Name, phone, play ID, UTR"
          value={value.search}
          onChange={(event) => patch({ search: event.target.value })}
        />
        <Select
          label="Status"
          value={value.status}
          onChange={(event) => patch({ status: event.target.value as StatusFilter })}
        >
          <option value="">All statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </Select>
        <Select
          label="Period"
          value={value.timeframe}
          onChange={(event) => patch({ timeframe: event.target.value as TimeframeId })}
        >
          {TIMEFRAMES.map((option) => (
            <option key={option.id} value={option.id}>
              {option.label}
            </option>
          ))}
        </Select>
        <div className="flex items-end">{right}</div>
      </div>

      {value.timeframe === "range" ? (
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <Input
            label="From"
            type="date"
            value={value.customStart}
            onChange={(event) => patch({ customStart: event.target.value })}
          />
          <Input
            label="To"
            type="date"
            value={value.customEnd}
            onChange={(event) => patch({ customEnd: event.target.value })}
          />
        </div>
      ) : null}
    </Card>
  );
}
