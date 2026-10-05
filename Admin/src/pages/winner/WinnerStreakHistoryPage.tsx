import { useState } from "react";
import { Link } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetWinnerStreakHistoryQuery,
  useRecalculateWinnerStreakCycleMutation,
  useUpdateWinnerStreakEntryMutation,
} from "@/services/api";
import type {
  WinnerStreakEntry,
  WinnerStreakHistoryCycle,
  WinnerStreakPeriod,
} from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { money } from "@/utils/format";

const PERIODS: WinnerStreakPeriod[] = ["daily", "weekly", "monthly"];
const LIMITS = [12, 30, 60];

/**
 * Closed cycles and the winners frozen into them.
 *
 * The live board on the Winner Streak page only ever shows the cycle that is
 * currently open, so every board that has already closed — and every reward
 * still owed from one — was invisible from the panel. This is where that
 * history lives, and where the reward queue is actually worked: rewards are
 * paid by hand, exactly like bonus redemptions, so each entry carries its own
 * paid / skipped control.
 */
export default function WinnerStreakHistoryPage() {
  const [period, setPeriod] = useState<WinnerStreakPeriod>("daily");
  const [limit, setLimit] = useState(12);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const show = (next: WinnerStreakPeriod) => {
    setPeriod(next);
    setNotice(null);
    setFormError(null);
  };

  const history = useGetWinnerStreakHistoryQuery({ period, limit });
  const [recalculate, { isLoading: isRecalculating }] = useRecalculateWinnerStreakCycleMutation();
  const [updateEntry, { isLoading: isUpdatingEntry }] = useUpdateWinnerStreakEntryMutation();
  useSessionGuard(history.error);

  const cycles = history.data ?? [];
  const pendingRewards = cycles.reduce(
    (total, cycle) =>
      total + cycle.profit.filter((e) => e.reward_amount > 0 && e.reward_status === "pending").length,
    0,
  );

  const recount = async (cycleId: number) => {
    setFormError(null);
    setNotice(null);
    try {
      const result = await recalculate({ cycleId, period }).unwrap();
      setNotice(
        `Recounted: ${result.added} added, ${result.updated} updated, ${result.removed} removed, ${result.kept_paid} kept for paid rewards.`,
      );
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not recount this cycle."));
    }
  };

  const setRewardStatus = async (entry: WinnerStreakEntry, status: "paid" | "skipped") => {
    setFormError(null);
    setNotice(null);
    try {
      await updateEntry({ id: entry.id, period, reward_status: status }).unwrap();
      setNotice(status === "paid" ? "Marked as paid." : "Reward skipped.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this reward."));
    }
  };

  return (
    <AdminShell
      title="Winner history"
      subtitle={pendingRewards ? `${pendingRewards} rewards awaiting payout` : undefined}
      action={
        <Link
          to="/winner-streak"
          className="inline-flex h-9 shrink-0 items-center rounded-full border border-border bg-surface px-3.5 text-[13px] font-semibold text-text transition-colors hover:border-border-strong"
        >
          Live board
        </Link>
      }
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex min-w-0 flex-wrap gap-2">
            {PERIODS.map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => show(option)}
                className={[
                  "h-9 shrink-0 rounded-full border px-4 text-[13px] font-semibold capitalize transition-colors",
                  period === option
                    ? "border-accent bg-accent-soft text-accent"
                    : "border-border bg-surface text-muted",
                ].join(" ")}
              >
                {option}
              </button>
            ))}
          </div>

          <div className="ml-auto flex shrink-0 gap-1">
            {LIMITS.map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => {
                  setLimit(option);
                  setNotice(null);
                }}
                className={[
                  "h-9 rounded-full border px-3.5 text-xs font-semibold transition-colors",
                  limit === option
                    ? "border-accent text-accent"
                    : "border-border text-faint",
                ].join(" ")}
              >
                {option}
              </button>
            ))}
          </div>
        </div>

        {formError ? <ErrorNote>{formError}</ErrorNote> : null}
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}

        {history.isLoading ? (
          <Skeleton className="h-96 w-full" />
        ) : cycles.length ? (
          cycles.map((cycle) => (
            <CycleCard
              key={cycle.id}
              cycle={cycle}
              busy={isRecalculating || isUpdatingEntry}
              onRecount={() => void recount(cycle.id)}
              onReward={(entry, status) => void setRewardStatus(entry, status)}
            />
          ))
        ) : (
          <Card>
            <EmptyState
              title="No closed cycles yet"
              body="A cycle appears here once it closes — either on its reset schedule or from Close cycle on the live board."
            />
          </Card>
        )}
      </div>
    </AdminShell>
  );
}

function CycleCard({
  cycle,
  busy,
  onRecount,
  onReward,
}: {
  cycle: WinnerStreakHistoryCycle;
  busy: boolean;
  onRecount: () => void;
  onReward: (entry: WinnerStreakEntry, status: "paid" | "skipped") => void;
}) {
  return (
    <Card>
      <CardTitle hint={cycle.closed_at ? formatDateTime(cycle.closed_at) : undefined}>
        {cycle.label ?? `Cycle #${cycle.id}`}
      </CardTitle>

      <div className="mb-3 flex flex-wrap items-center gap-3">
        <Badge tone={cycle.announced ? "info" : "neutral"}>
          {cycle.announced ? "Announced" : "Not announced"}
        </Badge>
        <span className="text-xs text-muted">{cycle.participants_count} participants</span>
        <Button size="sm" variant="ghost" loading={busy} onClick={onRecount} className="ml-auto">
          Recount
        </Button>
      </div>

      <EntryList title="Winners" rows={cycle.profit} tone="pos" onReward={onReward} busy={busy} />
      {cycle.loss.length ? (
        <EntryList title="Biggest losses" rows={cycle.loss} tone="neg" busy={busy} />
      ) : null}
    </Card>
  );
}

function EntryList({
  title,
  rows,
  tone,
  busy,
  onReward,
}: {
  title: string;
  rows: WinnerStreakEntry[];
  tone: "pos" | "neg";
  busy: boolean;
  onReward?: (entry: WinnerStreakEntry, status: "paid" | "skipped") => void;
}) {
  if (!rows.length) {
    return (
      <div className="pt-1">
        <p className="text-[11px] font-semibold tracking-wide text-faint uppercase">{title}</p>
        <p className="py-2 text-xs text-faint">Nobody qualified in this cycle.</p>
      </div>
    );
  }

  return (
    <div className="pt-1">
      <p className="text-[11px] font-semibold tracking-wide text-faint uppercase">{title}</p>
      <ul className="min-w-0">
        {rows.map((entry) => (
          <li
            key={entry.id}
            className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-border py-2.5 last:border-0"
          >
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-muted">
              {entry.rank}
            </span>

            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-text">{entry.name ?? "—"}</p>
              <p className="truncate text-xs text-faint">
                {entry.play_id ?? entry.phone ?? ""} · {entry.transactions_count} txns
              </p>
              {entry.shared_payout ? (
                <p className="truncate text-xs" style={{ color: "var(--warn)" }}>
                  Shares a payout destination with {entry.shared_payout.shared_with} other account
                  {entry.shared_payout.shared_with === 1 ? "" : "s"}
                </p>
              ) : null}
            </div>

            <span
              className="tabular shrink-0 text-sm font-semibold"
              style={{ color: tone === "pos" ? "var(--pos)" : "var(--neg)" }}
            >
              {money(Math.abs(entry.net_amount))}
            </span>

            {entry.reward_amount > 0 ? (
              <span className="flex shrink-0 items-center gap-2">
                <Badge tone={statusTone(entry.reward_status)}>
                  {money(entry.reward_amount)} {entry.reward_status}
                </Badge>
                {onReward && entry.reward_status === "pending" ? (
                  <>
                    <Button size="sm" loading={busy} onClick={() => onReward(entry, "paid")}>
                      Paid
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      loading={busy}
                      onClick={() => onReward(entry, "skipped")}
                    >
                      Skip
                    </Button>
                  </>
                ) : null}
              </span>
            ) : null}
          </li>
        ))}
      </ul>
    </div>
  );
}
