import type { ReactNode } from "react";
import Card from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Field";
import Segmented from "@/components/ui/Segmented";
import { TIMEFRAMES, type TimeframeId } from "@/utils/timeframe";

export const STATUS_OPTIONS = ["", "pending", "approved", "rejected"] as const;
export type StatusFilter = (typeof STATUS_OPTIONS)[number];

const STATUS_CHOICES = [
  { value: "", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
] as const satisfies readonly { value: StatusFilter; label: string }[];

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
      {/* sm: search | period, status underneath; lg: one row with status widest. */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Input
          label="Search"
          placeholder="Name, phone, play ID, UTR"
          value={value.search}
          onChange={(event) => patch({ search: event.target.value })}
        />
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
        <Segmented
          label="Status"
          value={value.status}
          onChange={(status) => patch({ status })}
          options={STATUS_CHOICES}
          className="sm:col-span-2"
        />
        {right ? <div className="flex items-end sm:col-span-2 lg:col-span-4">{right}</div> : null}
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
