import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import { Input, Select } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetWinnerStreakPeriodQuery,
  useGetWinnerStreakQuery,
  useResetWinnerStreakMutation,
  useUpdateWinnerStreakSettingsMutation,
} from "@/services/api";
import type {
  WinnerStreakLiveRow,
  WinnerStreakPeriod,
  WinnerStreakSettings,
} from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { money } from "@/utils/format";

const PERIODS: WinnerStreakPeriod[] = ["daily", "weekly", "monthly"];

/** Which fields the user app is allowed to publish for each winner. */
const VISIBILITY_FIELDS = [
  ["show_name", "Name"],
  ["show_play_id", "Play ID"],
  ["show_phone", "Phone"],
  ["show_amount", "Amount"],
  ["show_profit_loss", "Profit / loss"],
  ["show_reward", "Reward"],
  ["mask_name", "Mask the name"],
  ["mask_amount_bucket", "Bucket the amount"],
] as const;

export default function WinnerStreakPage() {
  const [period, setPeriod] = useState<WinnerStreakPeriod>("daily");

  const overview = useGetWinnerStreakQuery();
  const periodQuery = useGetWinnerStreakPeriodQuery(period);
  const [saveSettings, { isLoading: isSaving }] = useUpdateWinnerStreakSettingsMutation();
  const [resetCycle, { isLoading: isResetting }] = useResetWinnerStreakMutation();
  useSessionGuard(overview.error, periodQuery.error);

  const [draft, setDraft] = useState<Partial<WinnerStreakSettings>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmingReset, setConfirmingReset] = useState(false);

  const payload = periodQuery.data;

  useEffect(() => {
    if (payload?.settings) setDraft(payload.settings);
  }, [payload?.settings]);

  const patch = (partial: Partial<WinnerStreakSettings>) =>
    setDraft((prev) => ({ ...prev, ...partial }));

  const save = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await saveSettings({ period, body: draft }).unwrap();
      setNotice("Saved.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save these settings."));
    }
  };

  const reset = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await resetCycle(period).unwrap();
      setConfirmingReset(false);
      setNotice("Cycle closed. A new one has started.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not close this cycle."));
    }
  };

  if (periodQuery.isLoading && !payload) {
    return (
      <AdminShell title="Winner streak">
        <Skeleton className="h-96 w-full" />
      </AdminShell>
    );
  }

  return (
    <AdminShell
      title="Winner streak"
      subtitle={payload?.cycle?.label ?? undefined}
      action={
        confirmingReset ? (
          <span className="flex items-center gap-2">
            <Button size="sm" variant="danger" loading={isResetting} onClick={() => void reset()}>
              Close cycle
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setConfirmingReset(false)}>
              Cancel
            </Button>
          </span>
        ) : (
          <span className="flex items-center gap-2">
            <Link
              to="/winner-streak/history"
              className="inline-flex h-9 shrink-0 items-center rounded-md border border-border px-3 text-[13px] font-semibold text-muted"
            >
              History
            </Link>
            <Button size="sm" variant="secondary" onClick={() => setConfirmingReset(true)}>
              Close cycle
            </Button>
          </span>
        )
      }
    >
      <div className="space-y-4">
        <div className="flex gap-2 overflow-x-auto pb-1">
          {PERIODS.map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => setPeriod(option)}
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

        {formError ? <ErrorNote>{formError}</ErrorNote> : null}
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}

        <Card>
          <CardTitle
            hint={
              payload?.settings?.next_reset_at
                ? `Next reset ${formatDateTime(payload.settings.next_reset_at)}`
                : undefined
            }
          >
            Cycle
          </CardTitle>

          <div className="flex flex-wrap items-center gap-4">
            <Badge tone={payload?.settings?.enabled ? "pos" : "neutral"}>
              {payload?.settings?.enabled ? "Published" : "Hidden"}
            </Badge>
            <span className="text-xs text-muted">
              {payload?.board?.participants ?? 0} participants
            </span>
            {payload?.cycle?.status ? (
              <span className="text-xs text-muted capitalize">{payload.cycle.status}</span>
            ) : null}
          </div>
        </Card>

        <Card>
          <CardTitle>Rules</CardTitle>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Input
              label="Board size"
              type="number"
              inputMode="numeric"
              value={String(draft.top_n ?? "")}
              onChange={(event) => patch({ top_n: Number(event.target.value) })}
            />
            <Input
              label="Min turnover"
              type="number"
              inputMode="numeric"
              value={String(draft.min_turnover ?? "")}
              onChange={(event) => patch({ min_turnover: Number(event.target.value) })}
            />
            <Input
              label="Min transactions"
              type="number"
              inputMode="numeric"
              value={String(draft.min_transactions ?? "")}
              onChange={(event) => patch({ min_transactions: Number(event.target.value) })}
            />
            <Input
              label="Reward amount"
              type="number"
              inputMode="numeric"
              value={String(draft.reward_amount ?? "")}
              onChange={(event) => patch({ reward_amount: Number(event.target.value) })}
            />
            <Input
              label="Reward label"
              value={draft.reward_label ?? ""}
              onChange={(event) => patch({ reward_label: event.target.value })}
            />
            <Input
              label="Reset time"
              type="time"
              value={draft.reset_time ?? ""}
              onChange={(event) => patch({ reset_time: event.target.value })}
            />
            <Select
              label="Eligible tag"
              value={String(draft.tag_id ?? "")}
              onChange={(event) => patch({ tag_id: event.target.value ? Number(event.target.value) : null })}
            >
              <option value="">Everyone</option>
              {(overview.data?.tags ?? []).map((tag) => (
                <option key={tag.id} value={tag.id}>
                  {tag.name}
                </option>
              ))}
            </Select>
            <Select
              label="Excluded tag"
              value={String(draft.exclude_tag_id ?? "")}
              onChange={(event) =>
                patch({ exclude_tag_id: event.target.value ? Number(event.target.value) : null })
              }
            >
              <option value="">Nobody</option>
              {(overview.data?.tags ?? []).map((tag) => (
                <option key={tag.id} value={tag.id}>
                  {tag.name}
                </option>
              ))}
            </Select>
          </div>

          <div className="mt-4 flex flex-wrap gap-4 border-t border-border pt-4">
            <Toggle
              label="Publish this board"
              checked={Boolean(draft.enabled)}
              onChange={(checked) => patch({ enabled: checked })}
            />
            <Toggle
              label="Loss board"
              checked={Boolean(draft.loss_board_enabled)}
              onChange={(checked) => patch({ loss_board_enabled: checked })}
            />
            <Toggle
              label="Loss board public"
              checked={Boolean(draft.loss_board_public)}
              onChange={(checked) => patch({ loss_board_public: checked })}
            />
            <Toggle
              label="Announce by push"
              checked={Boolean(draft.announce_push)}
              onChange={(checked) => patch({ announce_push: checked })}
            />
          </div>
        </Card>

        <Card>
          <CardTitle>What users see</CardTitle>
          {/* Each winner is a real person: publishing a phone number or an exact
              amount is a privacy decision, so every field is opt-in. */}
          <div className="flex flex-wrap gap-4">
            {VISIBILITY_FIELDS.map(([key, label]) => (
              <Toggle
                key={key}
                label={label}
                checked={Boolean(draft[key])}
                onChange={(checked) => patch({ [key]: checked } as Partial<WinnerStreakSettings>)}
              />
            ))}
          </div>
        </Card>

        <Button loading={isSaving} onClick={() => void save()}>
          Save {period} settings
        </Button>

        <div className="grid gap-3 lg:grid-cols-2">
          <BoardCard title="Top profit" rows={payload?.board?.profit ?? []} tone="pos" />
          <BoardCard title="Biggest losses" rows={payload?.board?.loss ?? []} tone="neg" />
        </div>
      </div>
    </AdminShell>
  );
}

function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <label className="flex min-w-0 items-center gap-2 text-sm text-text">
      <input
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className="size-4 shrink-0 accent-[var(--accent)]"
      />
      <span className="truncate">{label}</span>
    </label>
  );
}

function BoardCard({
  title,
  rows,
  tone,
}: {
  title: string;
  rows: WinnerStreakLiveRow[];
  tone: "pos" | "neg";
}) {
  return (
    <Card>
      <CardTitle hint={`${rows.length}`}>{title}</CardTitle>
      {rows.length ? (
        <ul className="min-w-0">
          {rows.map((row) => (
            <li
              key={`${row.kind}-${row.user_id}-${row.rank}`}
              className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
            >
              <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-muted">
                {row.rank}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-text">
                  {row.display_name ?? "—"}
                </p>
                <p className="truncate text-xs text-faint">
                  {row.display_play_id ?? row.display_phone ?? ""} · {row.transactions_count} txns
                </p>
              </div>
              <span
                className="tabular shrink-0 text-sm font-semibold"
                style={{ color: tone === "pos" ? "var(--pos)" : "var(--neg)" }}
              >
                {money(Math.abs(row.net_amount))}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState title="Nobody qualifies yet" body="Lower the thresholds or wait for activity." />
      )}
    </Card>
  );
}
