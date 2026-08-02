import { useState, type FormEvent } from "react";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input, Select, Textarea } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus, IconTrash } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useCreateBonusCodeMutation,
  useDeleteBonusCodeMutation,
  useGenerateBonusCodeMutation,
  useGetBonusCodesQuery,
  useGetBonusRedemptionsQuery,
  useUpdateBonusCodeMutation,
  useUpdateBonusRedemptionMutation,
} from "@/services/api";
import type { BonusCodeRecord, BonusRedemptionRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { money } from "@/utils/format";

type Tab = "codes" | "redemptions";

const FREQUENCIES = ["once", "daily", "weekly", "monthly", "unlimited"] as const;

const emptyDraft = {
  code: "",
  title: "",
  reward_amount: "",
  reward_label: "",
  frequency: "once" as (typeof FREQUENCIES)[number],
  per_user_limit: "1",
  max_redemptions: "",
  min_deposit: "",
  requires_deposit: true,
  auto_approve: false,
  expires_at: "",
  terms_text: "",
};

export default function BonusCodePage() {
  const [tab, setTab] = useState<Tab>("codes");

  return (
    <AdminShell title="Bonus codes" subtitle="Codes users apply on the deposit form">
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-1 rounded-full border border-border bg-surface-2 p-1 sm:max-w-sm">
          {(["codes", "redemptions"] as const).map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setTab(key)}
              className={[
                "h-9 truncate rounded-full text-[13px] font-semibold transition-colors",
                tab === key ? "bg-accent text-on-accent" : "text-muted",
              ].join(" ")}
            >
              {key === "codes" ? "Codes" : "Redemptions"}
            </button>
          ))}
        </div>

        {tab === "codes" ? <CodesTab /> : <RedemptionsTab />}
      </div>
    </AdminShell>
  );
}

function CodesTab() {
  const { data: codes = [], isFetching, error } = useGetBonusCodesQuery();
  const [createCode, { isLoading: isCreating }] = useCreateBonusCodeMutation();
  const [updateCode] = useUpdateBonusCodeMutation();
  const [deleteCode, { isLoading: isDeleting }] = useDeleteBonusCodeMutation();
  const [generateCode, { isLoading: isGenerating }] = useGenerateBonusCodeMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState(emptyDraft);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);

  const patch = (partial: Partial<typeof draft>) => setDraft((prev) => ({ ...prev, ...partial }));

  const suggestCode = async () => {
    setFormError(null);
    try {
      const generated = await generateCode({ count: 1, length: 8 }).unwrap();
      const [first] = Array.isArray(generated) ? generated : [];
      if (first) patch({ code: first });
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not generate a code."));
    }
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);

    const reward = Number(draft.reward_amount);
    if (!draft.code.trim()) return setFormError("Give the code a value users can type.");
    if (!Number.isFinite(reward) || reward <= 0) return setFormError("Reward amount must be above zero.");

    try {
      await createCode({
        code: draft.code.trim().toUpperCase(),
        title: draft.title.trim() || undefined,
        reward_amount: reward,
        reward_label: draft.reward_label.trim() || undefined,
        frequency: draft.frequency,
        per_user_limit: Number(draft.per_user_limit || 1),
        max_redemptions: draft.max_redemptions ? Number(draft.max_redemptions) : undefined,
        min_deposit: draft.min_deposit ? Number(draft.min_deposit) : 0,
        requires_deposit: draft.requires_deposit,
        auto_approve: draft.auto_approve,
        expires_at: draft.expires_at || undefined,
        terms_text: draft.terms_text.trim() || undefined,
      }).unwrap();
      setDraft(emptyDraft);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not create this code."));
    }
  };

  const togglePause = async (record: BonusCodeRecord) => {
    setFormError(null);
    try {
      await updateCode({
        id: record.id,
        status: record.status === "active" ? "paused" : "active",
      }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not change this code."));
    }
  };

  const columns: Column<BonusCodeRecord>[] = [
    {
      key: "code",
      header: "Code",
      render: (row) => (
        <span className="block min-w-0">
          <span className="tabular block truncate font-semibold text-text">{row.code}</span>
          <span className="block truncate text-xs text-faint">{row.title ?? ""}</span>
        </span>
      ),
    },
    {
      key: "reward",
      header: "Reward",
      render: (row) => <span className="tabular">{money(row.reward_amount)}</span>,
    },
    {
      key: "used",
      header: "Used",
      render: (row) => (
        <span className="tabular">
          {row.redeemed_count}
          {row.max_redemptions ? ` / ${row.max_redemptions}` : ""}
        </span>
      ),
    },
    {
      key: "frequency",
      header: "Frequency",
      render: (row) => <span className="capitalize">{row.frequency}</span>,
      secondary: true,
    },
    {
      key: "status",
      header: "Status",
      // effective_status folds in expiry and exhaustion — a code can be "active"
      // and still be unusable, and the admin needs to see that difference.
      render: (row) => <Badge tone={statusTone(row.effective_status)}>{row.effective_status}</Badge>,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          <Button size="sm" variant="secondary" onClick={() => void togglePause(row)}>
            {row.status === "active" ? "Pause" : "Resume"}
          </Button>
          {confirmingId === row.id ? (
            <>
              <Button
                size="sm"
                variant="danger"
                loading={isDeleting}
                onClick={() => void deleteCode(row.id).unwrap().catch(() => undefined)}
              >
                Delete
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirmingId(null)}>
                Cancel
              </Button>
            </>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => setConfirmingId(row.id)}>
              <IconTrash size={16} />
            </Button>
          )}
        </span>
      ),
    },
  ];

  return (
    <>
      <Card>
        <CardTitle>New code</CardTitle>
        <form className="space-y-4" onSubmit={submit}>
          <div className="grid gap-3 sm:grid-cols-3">
            <div className="flex min-w-0 items-end gap-2">
              <div className="min-w-0 flex-1">
                <Input
                  label="Code"
                  value={draft.code}
                  onChange={(event) => patch({ code: event.target.value.toUpperCase() })}
                  required
                />
              </div>
              <Button type="button" variant="secondary" loading={isGenerating} onClick={() => void suggestCode()}>
                Suggest
              </Button>
            </div>
            <Input
              label="Reward amount"
              type="number"
              inputMode="numeric"
              value={draft.reward_amount}
              onChange={(event) => patch({ reward_amount: event.target.value })}
              required
            />
            <Input
              label="Title (optional)"
              value={draft.title}
              onChange={(event) => patch({ title: event.target.value })}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-4">
            <Select
              label="Frequency"
              value={draft.frequency}
              onChange={(event) => patch({ frequency: event.target.value as typeof draft.frequency })}
            >
              {FREQUENCIES.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </Select>
            <Input
              label="Per user limit"
              type="number"
              inputMode="numeric"
              value={draft.per_user_limit}
              onChange={(event) => patch({ per_user_limit: event.target.value })}
            />
            <Input
              label="Total limit"
              type="number"
              inputMode="numeric"
              value={draft.max_redemptions}
              onChange={(event) => patch({ max_redemptions: event.target.value })}
              hint="Blank means unlimited."
            />
            <Input
              label="Min deposit"
              type="number"
              inputMode="numeric"
              value={draft.min_deposit}
              onChange={(event) => patch({ min_deposit: event.target.value })}
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Expires"
              type="date"
              value={draft.expires_at}
              onChange={(event) => patch({ expires_at: event.target.value })}
            />
            <Input
              label="Reward label (optional)"
              value={draft.reward_label}
              onChange={(event) => patch({ reward_label: event.target.value })}
              hint="Shown to the user instead of the raw amount."
            />
          </div>

          <Textarea
            label="Terms shown to the user"
            rows={2}
            value={draft.terms_text}
            onChange={(event) => patch({ terms_text: event.target.value })}
          />

          <div className="flex flex-wrap gap-4">
            <label className="flex items-center gap-2 text-sm text-text">
              <input
                type="checkbox"
                checked={draft.requires_deposit}
                onChange={(event) => patch({ requires_deposit: event.target.checked })}
                className="size-4 accent-[var(--accent)]"
              />
              Requires a deposit
            </label>
            <label className="flex items-center gap-2 text-sm text-text">
              <input
                type="checkbox"
                checked={draft.auto_approve}
                onChange={(event) => patch({ auto_approve: event.target.checked })}
                className="size-4 accent-[var(--accent)]"
              />
              Auto-approve redemptions
            </label>
          </div>

          {formError ? <ErrorNote>{formError}</ErrorNote> : null}

          <Button type="submit" loading={isCreating}>
            <IconPlus size={16} />
            Create code
          </Button>
        </form>
      </Card>

      <DataTable
        columns={columns}
        rows={codes}
        keyOf={(row) => row.id}
        loading={isFetching && !codes.length}
        emptyTitle="No bonus codes yet"
        emptyBody="Create one above to offer it on the deposit form."
      />
    </>
  );
}

function RedemptionsTab() {
  const [status, setStatus] = useState("");
  const { data: redemptions = [], isFetching, error } = useGetBonusRedemptionsQuery(
    status ? { status } : undefined
  );
  const [updateRedemption, { isLoading: isDeciding }] = useUpdateBonusRedemptionMutation();
  useSessionGuard(error);

  const [formError, setFormError] = useState<string | null>(null);

  const decide = async (id: number, next: "fulfilled" | "rejected") => {
    setFormError(null);
    try {
      await updateRedemption({ id, status: next }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this redemption."));
    }
  };

  const columns: Column<BonusRedemptionRecord>[] = [
    {
      key: "user",
      header: "User",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.user?.name ?? "—"}</span>
          <span className="block truncate text-xs text-faint">
            {row.user?.play_id ?? row.user?.phone ?? ""}
          </span>
        </span>
      ),
    },
    { key: "code", header: "Code", render: (row) => <span className="tabular">{row.code}</span> },
    {
      key: "amount",
      header: "Amount",
      render: (row) => <span className="tabular">{money(row.amount)}</span>,
    },
    {
      key: "status",
      header: "Status",
      render: (row) => <Badge tone={statusTone(row.status)}>{row.status.replace(/_/g, " ")}</Badge>,
    },
    {
      key: "when",
      header: "Redeemed",
      render: (row) => (
        <span className="whitespace-nowrap">
          {row.redeemed_at ? formatDateTime(row.redeemed_at) : "—"}
        </span>
      ),
      secondary: true,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) =>
        row.status === "pending" ? (
          <span className="flex shrink-0 items-center justify-end gap-2">
            <Button size="sm" loading={isDeciding} onClick={() => void decide(row.id, "fulfilled")}>
              Fulfil
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
        <div className="sm:max-w-xs">
          <Select label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">All</option>
            <option value="awaiting_deposit">Awaiting deposit</option>
            <option value="pending">Pending</option>
            <option value="fulfilled">Fulfilled</option>
            <option value="rejected">Rejected</option>
          </Select>
        </div>
      </Card>

      {formError ? <ErrorNote>{formError}</ErrorNote> : null}

      <DataTable
        columns={columns}
        rows={redemptions}
        keyOf={(row) => row.id}
        loading={isFetching && !redemptions.length}
        emptyTitle="No redemptions"
        emptyBody="Nobody has applied a bonus code with these filters."
      />
    </>
  );
}
