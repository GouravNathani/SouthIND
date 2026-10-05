import { useState } from "react";
import SuperShell from "@/components/SuperShell";
import Card, { CardTitle } from "@/components/ui/Card";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input, Select } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetDailyPayoutQuery, useGetMonthlyPayoutQuery } from "@/services/api";
import type { DailyPayoutRow, MonthlyPayoutRow } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { compactInr, money } from "@/utils/format";

type Grain = "monthly" | "daily";

const currentYear = new Date().getFullYear();
const YEARS = [currentYear, currentYear - 1, currentYear - 2];

export default function PayoutPage() {
  const [grain, setGrain] = useState<Grain>("monthly");
  const [year, setYear] = useState(currentYear);
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));

  const monthly = useGetMonthlyPayoutQuery({ year }, { skip: grain !== "monthly" });
  const daily = useGetDailyPayoutQuery({ month }, { skip: grain !== "daily" });
  useSessionGuard(monthly.error, daily.error);

  const active = grain === "monthly" ? monthly : daily;
  const summary = active.data?.summary;

  const monthlyColumns: Column<MonthlyPayoutRow>[] = [
    { key: "label", header: "Month", render: (row) => <span className="truncate">{row.label}</span> },
    {
      key: "approved",
      header: "Approved deposits",
      render: (row) => <span className="tabular">{money(row.approved_total)}</span>,
    },
    {
      key: "count",
      header: "Count",
      render: (row) => <span className="tabular">{row.deposit_count}</span>,
      secondary: true,
    },
    {
      key: "payout",
      header: "Payout",
      align: "right",
      render: (row) => (
        <span className="tabular font-semibold text-accent">
          {money(row.payout)}
          {row.deduction?.status === "settled" ? " · paid" : row.deduction?.status === "owed" ? " · owed" : ""}
        </span>
      ),
    },
  ];

  const dailyColumns: Column<DailyPayoutRow>[] = [
    { key: "date", header: "Date", render: (row) => <span className="tabular truncate">{row.date}</span> },
    {
      key: "approved",
      header: "Approved deposits",
      render: (row) => <span className="tabular">{money(row.approved_total)}</span>,
    },
    {
      key: "count",
      header: "Count",
      render: (row) => <span className="tabular">{row.deposit_count}</span>,
      secondary: true,
    },
    {
      key: "payout",
      header: "Payout",
      align: "right",
      render: (row) => <span className="tabular font-semibold text-accent">{money(row.payout)}</span>,
    },
  ];

  return (
    <SuperShell
      title="Payout report"
      subtitle="Network-wide — the branch picker does not narrow this"
    >
      <div className="space-y-4">
        <Card>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select
              label="Grain"
              value={grain}
              onChange={(event) => setGrain(event.target.value as Grain)}
            >
              <option value="monthly">Month by month</option>
              <option value="daily">Day by day</option>
            </Select>
            {grain === "monthly" ? (
              <Select label="Year" value={String(year)} onChange={(event) => setYear(Number(event.target.value))}>
                {YEARS.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </Select>
            ) : (
              <Input label="Month" type="month" value={month} onChange={(event) => setMonth(event.target.value)} />
            )}
          </div>
        </Card>

        <Card>
          <CardTitle hint={summary?.percent != null ? `${summary.percent}% of approved` : undefined}>
            {grain === "monthly" ? `${year} total` : "Recent total"}
          </CardTitle>
          {/* The payout is a percentage of approved deposits, so both numbers
              belong together — the rate is what makes the payout checkable. */}
          <p className="tabular text-3xl font-semibold text-accent">{compactInr(summary?.payout)}</p>
          <p className="mt-1 text-xs text-muted">
            on {money(summary?.approved_total)} approved across {summary?.deposit_count ?? 0} deposits
          </p>
        </Card>

        {active.error ? (
          <ErrorNote>{resolveErrorMessage(active.error, "Could not load the report.")}</ErrorNote>
        ) : null}

        {grain === "monthly" ? (
          <DataTable
            columns={monthlyColumns}
            rows={monthly.data?.data ?? []}
            keyOf={(row) => row.month}
            loading={monthly.isFetching && !monthly.data}
            emptyTitle="Nothing for this year"
            emptyBody="Pick another year, or widen the branch filter in the header."
          />
        ) : (
          <DataTable
            columns={dailyColumns}
            rows={daily.data?.data ?? []}
            keyOf={(row) => row.date}
            loading={daily.isFetching && !daily.data}
            emptyTitle="No days to show"
            emptyBody="Approved deposits appear here the day after they are decided."
          />
        )}
      </div>
    </SuperShell>
  );
}
