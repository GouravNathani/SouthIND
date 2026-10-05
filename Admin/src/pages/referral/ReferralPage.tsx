import { useEffect, useState } from "react";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input } from "@/components/ui/Field";
import Segmented from "@/components/ui/Segmented";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetCommissionPayoutsQuery,
  useGetReferralAgentsQuery,
  useGetReferralOverviewQuery,
  useProcessCommissionPayoutMutation,
  useRegenerateReferralCodeMutation,
  useUpdateReferralAgentMutation,
  useUpdateReferralSettingsMutation,
} from "@/services/api";
import type { CommissionPayout, ReferralAgent, ReferralSettings } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { compactInr, money } from "@/utils/format";

type Tab = "overview" | "agents" | "payouts" | "settings";

const TABS: Array<{ id: Tab; label: string }> = [
  { id: "overview", label: "Overview" },
  { id: "agents", label: "Agents" },
  { id: "payouts", label: "Payouts" },
  { id: "settings", label: "Programme" },
];

export default function ReferralPage() {
  const [tab, setTab] = useState<Tab>("overview");
  const overview = useGetReferralOverviewQuery();
  useSessionGuard(overview.error);

  const stats = overview.data?.overview;

  return (
    <AdminShell
      title="Agents"
      subtitle={
        stats ? `${stats.agents} agents · ${stats.referred_users} referred users` : undefined
      }
    >
      <div className="space-y-4">
        <div className="flex min-w-0 flex-wrap gap-2">
          {TABS.map((option) => (
            <button
              key={option.id}
              type="button"
              onClick={() => setTab(option.id)}
              className={[
                "h-9 shrink-0 rounded-full border px-4 text-[13px] font-semibold transition-colors",
                tab === option.id
                  ? "border-accent bg-accent-soft text-accent"
                  : "border-border bg-surface text-muted",
              ].join(" ")}
            >
              {option.label}
            </button>
          ))}
        </div>

        {overview.error ? (
          <ErrorNote>{resolveErrorMessage(overview.error, "Could not load the programme.")}</ErrorNote>
        ) : null}

        {tab === "overview" ? <OverviewTab /> : null}
        {tab === "agents" ? <AgentsTab /> : null}
        {tab === "payouts" ? <PayoutsTab /> : null}
        {tab === "settings" ? <SettingsTab /> : null}
      </div>
    </AdminShell>
  );
}

function OverviewTab() {
  const { data, isLoading } = useGetReferralOverviewQuery();
  if (isLoading) return <Skeleton className="h-64 w-full" />;

  const stats = data?.overview;
  const leaders = data?.leaderboard ?? [];

  return (
    <>
      <Card>
        <CardTitle hint={stats?.enabled ? "Live" : "Disabled"}>Commission liability</CardTitle>
        {/* Liability is available + pending: what the branch owes right now if
            every agent cashed out. It is the number that matters, not lifetime. */}
        <p className="tabular text-2xl font-semibold break-words text-accent sm:text-3xl">{money(stats?.liability)}</p>

        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label="Available" value={money(stats?.available_total)} />
          <Stat label="On hold" value={money(stats?.pending_total)} />
          <Stat label="Paid lifetime" value={money(stats?.lifetime_paid)} />
          <Stat label="This month" value={money(stats?.earned_this_month)} />
        </div>

        {stats?.pending_payouts ? (
          <p className="mt-3 rounded-md px-3 py-2 text-xs"
             style={{ color: "var(--warn)", background: "color-mix(in srgb, var(--warn) 14%, transparent)" }}>
            {stats.pending_payouts} payout request{stats.pending_payouts === 1 ? "" : "s"} waiting ·{" "}
            {money(stats.pending_payout_total)}
          </p>
        ) : null}
      </Card>

      <Card>
        <CardTitle hint={`${leaders.length}`}>Top agents</CardTitle>
        {leaders.length ? (
          <ul className="min-w-0">
            {leaders.map((row, index) => (
              <li
                key={row.agent_id}
                className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
              >
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-muted">
                  {index + 1}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-text">{row.name ?? "—"}</p>
                  <p className="truncate text-xs text-faint">
                    {row.play_id ?? ""} · {row.team_count} in team
                  </p>
                </div>
                <span className="tabular shrink-0 text-sm font-semibold text-accent">
                  {compactInr(row.team_deposit_total)}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="No agents yet" body="Promote a user to agent to start the programme." />
        )}
      </Card>
    </>
  );
}

function AgentsTab() {
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [agentStatus, setAgentStatus] = useState("");

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(search.trim()), 350);
    return () => window.clearTimeout(timer);
  }, [search]);

  const { data, isFetching, error } = useGetReferralAgentsQuery({
    search: debounced || undefined,
    agent_status: agentStatus || undefined,
    per_page: 25,
  });
  const [updateAgent, { isLoading: isUpdating }] = useUpdateReferralAgentMutation();
  const [regenerateCode, { isLoading: isRegenerating }] = useRegenerateReferralCodeMutation();
  useSessionGuard(error);

  const [formError, setFormError] = useState<string | null>(null);

  const toggleSuspend = async (agent: ReferralAgent) => {
    setFormError(null);
    try {
      await updateAgent({
        id: agent.id,
        agent_status: agent.agent_status === "active" ? "suspended" : "active",
      }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this agent."));
    }
  };

  const columns: Column<ReferralAgent>[] = [
    {
      key: "agent",
      header: "Agent",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.name}</span>
          <span className="block truncate text-xs text-faint">{row.play_id ?? row.phone ?? ""}</span>
        </span>
      ),
    },
    {
      key: "code",
      header: "Code",
      render: (row) => <span className="tabular truncate">{row.referral_code ?? "—"}</span>,
    },
    {
      key: "team",
      header: "Team",
      render: (row) => (
        <span className="tabular">
          {row.account?.team_count ?? 0}
          <span className="text-faint"> / {row.account?.team_active_count ?? 0} active</span>
        </span>
      ),
    },
    {
      key: "available",
      header: "Available",
      render: (row) => <span className="tabular">{money(row.account?.available_balance)}</span>,
    },
    {
      key: "status",
      header: "Status",
      render: (row) => <Badge tone={statusTone(row.agent_status)}>{row.agent_status}</Badge>,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          <Button
            size="sm"
            variant="ghost"
            loading={isRegenerating}
            onClick={() => void regenerateCode(row.id).unwrap().catch(() => undefined)}
          >
            New code
          </Button>
          <Button size="sm" variant="secondary" loading={isUpdating} onClick={() => void toggleSuspend(row)}>
            {row.agent_status === "active" ? "Suspend" : "Reinstate"}
          </Button>
        </span>
      ),
    },
  ];

  return (
    <>
      <Card>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input
            label="Search"
            placeholder="Name, phone, play ID"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
          <Segmented
            label="Status"
            value={agentStatus}
            onChange={setAgentStatus}
            options={[
              { value: "", label: "All" },
              { value: "active", label: "Active" },
              { value: "suspended", label: "Suspended" },
            ]}
          />
        </div>
      </Card>

      {formError ? <ErrorNote>{formError}</ErrorNote> : null}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        keyOf={(row) => row.id}
        loading={isFetching && !data?.data?.length}
        emptyTitle="No agents match this search"
        emptyBody="Promote a user to agent from their profile."
      />
    </>
  );
}

function PayoutsTab() {
  const [status, setStatus] = useState("pending");
  const { data, isFetching, error } = useGetCommissionPayoutsQuery({
    status: status || undefined,
    per_page: 25,
  });
  const [processPayout, { isLoading: isDeciding }] = useProcessCommissionPayoutMutation();
  useSessionGuard(error);

  const [formError, setFormError] = useState<string | null>(null);

  const decide = async (id: number, next: "approved" | "rejected") => {
    setFormError(null);
    try {
      await processPayout({ id, status: next }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not process this payout."));
    }
  };

  const columns: Column<CommissionPayout>[] = [
    {
      key: "agent",
      header: "Agent",
      render: (row) => <span className="block truncate font-medium text-text">{row.agent_name ?? `#${row.agent_id}`}</span>,
    },
    {
      key: "amount",
      header: "Amount",
      render: (row) => <span className="tabular font-semibold">{money(row.amount)}</span>,
    },
    {
      key: "method",
      header: "To",
      // Same rule as withdrawals: the payout target is what gets typed into the
      // banking app, so it belongs in the list, not two clicks away.
      render: (row) => (
        <span className="tabular block truncate">
          {row.method === "play"
            ? "Play credit"
            : (row.upi_id ??
              (row.account_number ? `A/C ••••${row.account_number.slice(-4)}` : row.method.toUpperCase()))}
        </span>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge>,
    },
    {
      key: "created",
      header: "Requested",
      render: (row) => <span className="whitespace-nowrap">{formatDateTime(row.created_at)}</span>,
      secondary: true,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) =>
        row.status === "pending" ? (
          <span className="flex shrink-0 items-center justify-end gap-2">
            <Button size="sm" loading={isDeciding} onClick={() => void decide(row.id, "approved")}>
              Approve
            </Button>
            <Button
              size="sm"
              variant="danger"
              loading={isDeciding}
              onClick={() => void decide(row.id, "rejected")}
            >
              Reject
            </Button>
          </span>
        ) : null,
    },
  ];

  return (
    <>
      <Card>
        <div className="sm:max-w-md">
          <Segmented
            label="Status"
            value={status}
            onChange={setStatus}
            options={[
              { value: "pending", label: "Pending" },
              { value: "approved", label: "Approved" },
              { value: "rejected", label: "Rejected" },
              { value: "", label: "All" },
            ]}
          />
        </div>
      </Card>

      {formError ? <ErrorNote>{formError}</ErrorNote> : null}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        keyOf={(row) => row.id}
        loading={isFetching && !data?.data?.length}
        emptyTitle="No payout requests"
        emptyBody="Agents request payouts from their Account page in the user app."
      />
    </>
  );
}

function SettingsTab() {
  const { data, isLoading } = useGetReferralOverviewQuery();
  const [saveSettings, { isLoading: isSaving }] = useUpdateReferralSettingsMutation();

  const [draft, setDraft] = useState<Partial<ReferralSettings>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (data?.settings) setDraft(data.settings);
  }, [data?.settings]);

  if (isLoading) return <Skeleton className="h-96 w-full" />;

  const patch = (partial: Partial<ReferralSettings>) => setDraft((prev) => ({ ...prev, ...partial }));

  const save = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await saveSettings(draft).unwrap();
      setNotice("Programme saved.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save the programme."));
    }
  };

  return (
    <>
      <Card>
        <CardTitle>Commission</CardTitle>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Input
            label="Base percent"
            type="number"
            inputMode="decimal"
            value={String(draft.commission_percent ?? "")}
            onChange={(event) => patch({ commission_percent: Number(event.target.value) })}
          />
          <Input
            label="Level 2 percent"
            type="number"
            inputMode="decimal"
            value={String(draft.level2_percent ?? "")}
            onChange={(event) => patch({ level2_percent: Number(event.target.value) })}
          />
          <Input
            label="Min deposit to earn"
            type="number"
            inputMode="numeric"
            value={String(draft.min_deposit_amount ?? "")}
            onChange={(event) => patch({ min_deposit_amount: Number(event.target.value) })}
          />
          <Input
            label="Holding hours"
            type="number"
            inputMode="numeric"
            value={String(draft.holding_hours ?? "")}
            onChange={(event) => patch({ holding_hours: Number(event.target.value) })}
            hint="How long commission stays on hold."
          />
          <Input
            label="Monthly cap per agent"
            type="number"
            inputMode="numeric"
            value={String(draft.monthly_cap_per_agent ?? "")}
            onChange={(event) => patch({ monthly_cap_per_agent: Number(event.target.value) })}
          />
          <Input
            label="Per deposit cap"
            type="number"
            inputMode="numeric"
            value={String(draft.per_deposit_cap ?? "")}
            onChange={(event) => patch({ per_deposit_cap: Number(event.target.value) })}
          />
          <Input
            label="Min payout"
            type="number"
            inputMode="numeric"
            value={String(draft.min_payout_amount ?? "")}
            onChange={(event) => patch({ min_payout_amount: Number(event.target.value) })}
          />
          <Input
            label="Max referrals per day"
            type="number"
            inputMode="numeric"
            value={String(draft.max_referrals_per_day ?? "")}
            onChange={(event) => patch({ max_referrals_per_day: Number(event.target.value) })}
          />
        </div>
      </Card>

      <Card>
        <CardTitle>Rules</CardTitle>
        <div className="flex flex-wrap gap-4">
          <Toggle
            label="Programme enabled"
            checked={Boolean(draft.enabled)}
            onChange={(checked) => patch({ enabled: checked })}
          />
          <Toggle
            label="Tiers"
            checked={Boolean(draft.tiers_enabled)}
            onChange={(checked) => patch({ tiers_enabled: checked })}
          />
          <Toggle
            label="Level 2"
            checked={Boolean(draft.level2_enabled)}
            onChange={(checked) => patch({ level2_enabled: checked })}
          />
          <Toggle
            label="First deposit only"
            checked={Boolean(draft.first_deposit_only)}
            onChange={(checked) => patch({ first_deposit_only: checked })}
          />
          {/* Anti-fraud: an agent referring their own second number, or several
              "team members" sharing one payout account, is the usual abuse. */}
          <Toggle
            label="Block same phone"
            checked={Boolean(draft.block_same_phone)}
            onChange={(checked) => patch({ block_same_phone: checked })}
          />
          <Toggle
            label="Block shared payout"
            checked={Boolean(draft.block_shared_payout)}
            onChange={(checked) => patch({ block_shared_payout: checked })}
          />
          <Toggle
            label="Auto-promote to agent"
            checked={Boolean(draft.auto_promote_to_agent)}
            onChange={(checked) => patch({ auto_promote_to_agent: checked })}
          />
          <Toggle
            label="Retroactive on attach"
            checked={Boolean(draft.retroactive_on_attach)}
            onChange={(checked) => patch({ retroactive_on_attach: checked })}
          />
          <Toggle
            label="Payout to bank"
            checked={Boolean(draft.payout_to_bank_enabled)}
            onChange={(checked) => patch({ payout_to_bank_enabled: checked })}
          />
          <Toggle
            label="Payout to play credit"
            checked={Boolean(draft.payout_to_play_enabled)}
            onChange={(checked) => patch({ payout_to_play_enabled: checked })}
          />
        </div>
      </Card>

      {formError ? <ErrorNote>{formError}</ErrorNote> : null}
      {notice ? (
        <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
      ) : null}

      <Button loading={isSaving} onClick={() => void save()}>
        Save programme
      </Button>
    </>
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

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">{label}</p>
      <p className="tabular truncate text-lg font-semibold">{value}</p>
    </div>
  );
}
