import { useState } from "react";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Card, { CardTitle } from "@/components/ui/Card";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useGetWinnerStreakQuery, type WinnerStreakWinner } from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { dateTime } from "@/utils/format";

const PERIODS = ["daily", "weekly", "monthly"] as const;
type Period = (typeof PERIODS)[number];

export default function WinnersPage() {
  const { t } = useTranslation();
  const { data, isLoading, error } = useGetWinnerStreakQuery();
  const [period, setPeriod] = useState<Period>("daily");

  const available = PERIODS.filter((key) => data?.periods?.[key]);
  const activePeriod = available.includes(period) ? period : (available[0] ?? "daily");
  const feed = data?.periods?.[activePeriod];

  return (
    <AppShell title={t("winner.title")} subtitle={feed?.title ?? undefined}>
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }, (_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : error ? (
        <ErrorNote>{getApiErrorMessage(error, t("winner.error"))}</ErrorNote>
      ) : !available.length ? (
        <Card>
          <EmptyState title={t("winner.empty")} body={t("winner.emptyBody")} />
        </Card>
      ) : (
        <div className="space-y-4">
          {available.length > 1 ? (
            <div className="flex gap-2 overflow-x-auto pb-1">
              {available.map((key) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setPeriod(key)}
                  className={[
                    "h-9 shrink-0 rounded-full border px-4 text-[13px] font-semibold transition-colors",
                    activePeriod === key
                      ? "border-accent bg-accent-soft text-accent"
                      : "border-border bg-surface text-muted",
                  ].join(" ")}
                >
                  {t(`winner.period.${key}`)}
                </button>
              ))}
            </div>
          ) : null}

          {(feed?.cycles ?? []).map((cycle, index) => (
            <Card key={`${cycle.label ?? "cycle"}-${index}`}>
              <CardTitle hint={cycle.closed_at ? dateTime(cycle.closed_at) : undefined}>
                {cycle.label ?? t("winner.title")}
              </CardTitle>

              <WinnerList heading={t("winner.winners")} entries={cycle.winners} tone="pos" />
              {cycle.losers.length ? (
                <div className="mt-4 border-t border-border pt-4">
                  <WinnerList heading={t("winner.losers")} entries={cycle.losers} tone="neg" />
                </div>
              ) : null}
            </Card>
          ))}
        </div>
      )}
    </AppShell>
  );
}

function WinnerList({
  heading,
  entries,
  tone,
}: {
  heading: string;
  entries: WinnerStreakWinner[];
  tone: "pos" | "neg";
}) {
  if (!entries.length) return null;

  return (
    <div className="min-w-0">
      <p className="mb-2 text-[11px] font-semibold tracking-wide text-faint uppercase">{heading}</p>
      <ul className="min-w-0">
        {entries.map((entry, index) => (
          <li
            key={`${entry.rank}-${entry.play_id ?? entry.name ?? index}`}
            className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
          >
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-muted">
              {entry.rank}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-text">{entry.name ?? "—"}</p>
              {entry.play_id ? (
                <p className="truncate text-xs text-faint">{entry.play_id}</p>
              ) : null}
            </div>
            <div className="shrink-0 text-right">
              {entry.amount ? (
                <p
                  className="tabular text-sm font-semibold"
                  style={{ color: tone === "pos" ? "var(--pos)" : "var(--neg)" }}
                >
                  ₹{entry.amount}
                </p>
              ) : null}
              {entry.reward_label ?? entry.reward ? (
                <p className="truncate text-xs text-accent-2">
                  {entry.reward_label ?? entry.reward}
                </p>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
