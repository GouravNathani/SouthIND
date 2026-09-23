import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card, { CardTitle } from "@/components/ui/Card";
import Badge, { statusTone } from "@/components/ui/Badge";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { Input } from "@/components/ui/Field";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetBranchesQuery,
  useGetDepositSummaryQuery,
  useGetDepositsQuery,
  useGetWithdrawalSummaryQuery,
  useGetWithdrawalsQuery,
} from "@/services/api";
import type { DepositRecord, SummaryTotals, WithdrawRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime, formatDuration } from "@/utils/dateTime";
import { compactInr, money } from "@/utils/format";
import { TIMEFRAMES, rangeParams, type TimeframeId } from "@/utils/timeframe";

const TIMEFRAME_KEY = "sind_super_dashboard_timeframe";
const PENDING_PREVIEW = 5;

const readStoredTimeframe = (): TimeframeId => {
  const stored = localStorage.getItem(TIMEFRAME_KEY);
  return TIMEFRAMES.some((option) => option.id === stored) ? (stored as TimeframeId) : "today";
};

const isPending = (status?: string | null) => (status ?? "").toLowerCase() === "pending";

export default function DashboardPage() {
  const navigate = useNavigate();
  const { branchId, branch } = useBranch();
  const [timeframe, setTimeframe] = useState<TimeframeId>(readStoredTimeframe);
  const [customRange, setCustomRange] = useState({ start: "", end: "" });

  const params = useMemo(
    () => ({ ...rangeParams(timeframe, customRange), branchId: branchId ?? undefined }),
    [timeframe, customRange, branchId]
  );

  const branches = useGetBranchesQuery();
  const depositSummary = useGetDepositSummaryQuery(params);
  const withdrawalSummary = useGetWithdrawalSummaryQuery(params);
  const deposits = useGetDepositsQuery(
    { branchId: branchId ?? undefined },
    { pollingInterval: 30000 }
  );
  const withdrawals = useGetWithdrawalsQuery(
    { branchId: branchId ?? undefined },
    { pollingInterval: 30000 }
  );

  useSessionGuard(depositSummary.error, withdrawalSummary.error, branches.error);

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

  return (
    <SuperShell
      title="Network overview"
      subtitle={branch ? branch.name : `${branches.data?.length ?? 0} branches`}
    >
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
          <SummaryCard title="Deposits" loading={depositSummary.isLoading} summary={depositSummary.data} />
          <SummaryCard
            title="Withdrawals"
            loading={withdrawalSummary.isLoading}
            summary={withdrawalSummary.data}
          />
        </div>

        {/* Only worth showing when looking at everything — with one branch
            selected this is the same number as the card above it. */}
        {branchId === null ? (
          <Card>
            <CardTitle hint={`${branches.data?.length ?? 0}`}>Branches</CardTitle>
            {branches.isLoading ? (
              <Skeleton className="h-24 w-full" />
            ) : (
              <ul className="min-w-0">
                {(branches.data ?? []).map((entry) => (
                  <li
                    key={entry.id}
                    className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-text">{entry.name}</p>
                      <p className="truncate text-xs text-faint">
                        {entry.code}
                        {entry.domain ? ` · ${entry.domain}` : ""}
                      </p>
                    </div>
                    <span className="tabular shrink-0 text-xs text-muted">
                      {entry.users_count ?? 0} users · {entry.admins_count ?? 0} admins
                    </span>
                    <Badge tone={entry.is_active === false ? "neg" : "pos"}>
                      {entry.is_active === false ? "Off" : "Live"}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        ) : null}

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
    </SuperShell>
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

  // Headline is the approved amount; pending = requests not yet approved or rejected.
  const pendingTotal = Math.max(
    (summary?.total ?? 0) - (summary?.approvedTotal ?? 0) - (summary?.rejectedTotal ?? 0),
    0,
  );
  const pendingCount = Math.max(
    (summary?.count ?? 0) - (summary?.approvedCount ?? 0) - (summary?.rejectedCount ?? 0),
    0,
  );

  return (
    <Card>
      <CardTitle hint={`${summary?.count ?? 0} requests`}>{title}</CardTitle>
      <p className="tabular text-3xl font-semibold text-accent">{compactInr(summary?.approvedTotal)}</p>

      <div className="mt-4 grid grid-cols-2 gap-4">
        <Stat label={`Pending · ${pendingCount}`} value={money(pendingTotal)} />
        <Stat label={`Rejected · ${summary?.rejectedCount ?? 0}`} value={money(summary?.rejectedTotal)} tone="neg" />
      </div>

      <p className="mt-3 text-xs text-faint">
        Avg decision time {formatDuration(summary?.avgProcessingSeconds)}
      </p>
    </Card>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: "pos" | "neg" }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">{label}</p>
      <p
        className="tabular truncate text-lg font-semibold"
        style={{ color: tone ? (tone === "pos" ? "var(--pos)" : "var(--neg)") : undefined }}
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
