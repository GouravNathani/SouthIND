import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Badge, { statusTone } from "@/components/ui/Badge";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { Input } from "@/components/ui/Field";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetCurrentUserQuery,
  useGetDepositSummaryQuery,
  useGetDepositsQuery,
  useGetWithdrawalSummaryQuery,
  useGetWithdrawalsQuery,
} from "@/services/api";
import type { DepositRecord, SummaryTotals, WithdrawRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime, formatDuration } from "@/utils/dateTime";
import { money } from "@/utils/format";
import { TIMEFRAMES, rangeParams, type TimeframeId } from "@/utils/timeframe";

const TIMEFRAME_KEY = "sind_admin_dashboard_timeframe";
const PENDING_PREVIEW = 5;

const readStoredTimeframe = (): TimeframeId => {
  const stored = localStorage.getItem(TIMEFRAME_KEY);
  return TIMEFRAMES.some((option) => option.id === stored) ? (stored as TimeframeId) : "today";
};

const isPending = (status?: string | null) => (status ?? "").toLowerCase() === "pending";

export default function DashboardPage() {
  const navigate = useNavigate();
  const [timeframe, setTimeframe] = useState<TimeframeId>(readStoredTimeframe);
  const [customRange, setCustomRange] = useState({ start: "", end: "" });

  const params = useMemo(
    () => rangeParams(timeframe, customRange),
    [timeframe, customRange]
  );

  const me = useGetCurrentUserQuery();
  const depositSummary = useGetDepositSummaryQuery(params);
  const withdrawalSummary = useGetWithdrawalSummaryQuery(params);
  const deposits = useGetDepositsQuery(undefined, { pollingInterval: 30000 });
  const withdrawals = useGetWithdrawalsQuery(undefined, { pollingInterval: 30000 });

  useSessionGuard(me.error, depositSummary.error, withdrawalSummary.error);

  const chooseTimeframe = (id: TimeframeId) => {
    setTimeframe(id);
    localStorage.setItem(TIMEFRAME_KEY, id);
  };

  const error =
    depositSummary.error || withdrawalSummary.error
      ? resolveErrorMessage(
          depositSummary.error ?? withdrawalSummary.error,
          "Could not load the summary."
        )
      : null;

  const pendingDeposits = (deposits.data ?? []).filter((record) => isPending(record.status));
  const pendingWithdrawals = (withdrawals.data ?? []).filter((record) => isPending(record.status));

  const adminName =
    (me.data && typeof me.data === "object" && "name" in me.data
      ? String((me.data as { name?: unknown }).name ?? "")
      : "") || undefined;

  return (
    <AdminShell title="Dashboard" subtitle={adminName}>
      <div className="space-y-4">
        <div className="flex gap-2 overflow-x-auto pb-1">
          {TIMEFRAMES.map((option) => (
            <button
              key={option.id}
              type="button"
              onClick={() => chooseTimeframe(option.id)}
              className={[
                "h-9 shrink-0 rounded-full border px-4 text-[13px] font-semibold transition-colors",
                timeframe === option.id
                  ? "border-accent bg-accent-soft text-accent"
                  : "border-border bg-surface text-muted",
              ].join(" ")}
            >
              {option.label}
            </button>
          ))}
        </div>

        {timeframe === "range" ? (
          <Card>
            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                label="From"
                type="date"
                value={customRange.start}
                onChange={(event) =>
                  setCustomRange((prev) => ({ ...prev, start: event.target.value }))
                }
              />
              <Input
                label="To"
                type="date"
                value={customRange.end}
                onChange={(event) => setCustomRange((prev) => ({ ...prev, end: event.target.value }))}
              />
            </div>
          </Card>
        ) : null}

        {error ? <ErrorNote>{error}</ErrorNote> : null}

        <div className="grid gap-3 lg:grid-cols-2">
          <SummaryCard
            title="Deposits"
            loading={depositSummary.isLoading}
            summary={depositSummary.data}
          />
          <SummaryCard
            title="Withdrawals"
            loading={withdrawalSummary.isLoading}
            summary={withdrawalSummary.data}
          />
        </div>

        <div className="grid gap-3 lg:grid-cols-2">
          <PendingCard
            title="Pending deposits"
            rows={pendingDeposits}
            loading={deposits.isLoading}
            onOpen={(id) => navigate(`/deposit/${id}`)}
            onOpenAll={() => navigate("/deposit")}
          />
          <PendingCard
            title="Pending withdrawals"
            rows={pendingWithdrawals}
            loading={withdrawals.isLoading}
            onOpen={(id) => navigate(`/withdraw/${id}`)}
            onOpenAll={() => navigate("/withdraw")}
          />
        </div>
      </div>
    </AdminShell>
  );
}

function SummaryCard({
  title,
  summary,
  loading,
}: {
  title: string;
  summary?: SummaryTotals;
  loading: boolean;
}) {
  if (loading) return <Skeleton className="h-40 w-full" />;

  return (
    <Card>
      <CardTitle hint={`${summary?.count ?? 0} requests`}>{title}</CardTitle>
      <p className="tabular text-3xl font-semibold text-accent">{money(summary?.total)}</p>

      <div className="mt-4 grid grid-cols-2 gap-4">
        <Stat
          label="Approved"
          value={money(summary?.approvedTotal)}
          hint={`${summary?.approvedCount ?? 0}`}
          tone="pos"
        />
        <Stat
          label="Rejected"
          value={money(summary?.rejectedTotal)}
          hint={`${summary?.rejectedCount ?? 0}`}
          tone="neg"
        />
      </div>

      <p className="mt-3 text-xs text-faint">
        Avg decision time {formatDuration(summary?.avgProcessingSeconds)}
      </p>
    </Card>
  );
}

function Stat({
  label,
  value,
  hint,
  tone,
}: {
  label: string;
  value: string;
  hint: string;
  tone: "pos" | "neg";
}) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">
        {label} <span className="text-faint">· {hint}</span>
      </p>
      <p
        className="tabular truncate text-lg font-semibold"
        style={{ color: tone === "pos" ? "var(--pos)" : "var(--neg)" }}
      >
        {value}
      </p>
    </div>
  );
}

function PendingCard({
  title,
  rows,
  loading,
  onOpen,
  onOpenAll,
}: {
  title: string;
  rows: Array<DepositRecord | WithdrawRecord>;
  loading: boolean;
  onOpen: (id: number) => void;
  onOpenAll: () => void;
}) {
  if (loading) return <Skeleton className="h-40 w-full" />;

  return (
    <Card>
      <CardTitle
        hint={
          <button type="button" onClick={onOpenAll} className="text-accent">
            Open queue
          </button>
        }
      >
        {title}
      </CardTitle>

      {rows.length ? (
        <ul className="min-w-0">
          {rows.slice(0, PENDING_PREVIEW).map((row) => (
            <li key={row.id} className="min-w-0 border-b border-border last:border-0">
              <button
                type="button"
                onClick={() => onOpen(row.id)}
                className="flex w-full min-w-0 items-center gap-3 py-2.5 text-left"
              >
                <div className="min-w-0 flex-1">
                  <p className="tabular truncate text-sm font-semibold">{money(row.amount)}</p>
                  <p className="truncate text-xs text-faint">{formatDateTime(row.created_at)}</p>
                </div>
                <Badge tone={statusTone(row.status)}>{row.status}</Badge>
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="py-6 text-center text-sm text-muted">Queue is clear.</p>
      )}
    </Card>
  );
}
