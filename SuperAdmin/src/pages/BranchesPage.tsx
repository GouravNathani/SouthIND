import { useState, type FormEvent } from "react";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus, IconTrash } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useCreateBranchMutation,
  useDeleteBranchMutation,
  useGetBranchesQuery,
  useUpdateBranchMutation,
} from "@/services/api";
import type { BranchRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { money } from "@/utils/format";

const emptyDraft = { name: "", code: "", domain: "" };

export default function BranchesPage() {
  const { branches, isLoading } = useBranch();
  const { error } = useGetBranchesQuery();
  const [createBranch, { isLoading: isCreating }] = useCreateBranchMutation();
  const [updateBranch, { isLoading: isUpdating }] = useUpdateBranchMutation();
  const [deleteBranch, { isLoading: isDeleting }] = useDeleteBranchMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState(emptyDraft);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);

  const patch = (partial: Partial<typeof draft>) => setDraft((prev) => ({ ...prev, ...partial }));

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);

    const name = draft.name.trim();
    const code = draft.code.trim().toUpperCase();
    if (!name) return setFormError("Give the branch a name.");
    if (!/^[A-Z0-9]{2,10}$/.test(code)) {
      return setFormError("Code must be 2–10 letters or digits — it prefixes every user number.");
    }
    if (branches.some((entry) => entry.code.toUpperCase() === code)) {
      return setFormError(`Code ${code} is already taken by ${branches.find((e) => e.code.toUpperCase() === code)?.name}.`);
    }

    try {
      await createBranch({ name, code, domain: draft.domain.trim() || undefined }).unwrap();
      setDraft(emptyDraft);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not create this branch."));
    }
  };

  const toggleActive = async (branch: BranchRecord) => {
    setFormError(null);
    try {
      await updateBranch({ id: branch.id, body: { is_active: branch.is_active === false } }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this branch."));
    }
  };

  const toggleAgents = async (branch: BranchRecord) => {
    setFormError(null);
    try {
      await updateBranch({ id: branch.id, body: { agent_enabled: !branch.agent_enabled } }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update the referral programme."));
    }
  };

  const remove = async (id: number) => {
    setFormError(null);
    try {
      await deleteBranch(id).unwrap();
      setConfirmingId(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not delete this branch."));
    }
  };

  const columns: Column<BranchRecord>[] = [
    {
      key: "branch",
      header: "Branch",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.name}</span>
          <span className="tabular block truncate text-xs text-faint">
            {row.code}
            {row.domain ? ` · ${row.domain}` : ""}
          </span>
        </span>
      ),
    },
    {
      key: "size",
      header: "Size",
      render: (row) => (
        <span className="tabular whitespace-nowrap">
          {row.users_count ?? 0} users · {row.admins_count ?? 0} admins
        </span>
      ),
    },
    {
      key: "limits",
      header: "Minimums",
      render: (row) => (
        <span className="tabular whitespace-nowrap">
          {row.min_deposit_amount != null ? money(row.min_deposit_amount) : "—"} /{" "}
          {row.min_withdrawal_amount != null ? money(row.min_withdrawal_amount) : "—"}
        </span>
      ),
      secondary: true,
    },
    {
      key: "status",
      header: "Status",
      render: (row) => (
        <span className="flex flex-wrap items-center gap-1.5">
          <Badge tone={row.is_active === false ? "neg" : "pos"}>
            {row.is_active === false ? "Off" : "Live"}
          </Badge>
          {row.agent_enabled ? <Badge tone="accent">Agents</Badge> : null}
        </span>
      ),
    },
    {
      key: "created",
      header: "Created",
      render: (row) => (
        <span className="whitespace-nowrap">{row.created_at ? formatDateTime(row.created_at) : "—"}</span>
      ),
      secondary: true,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          <Button size="sm" variant="ghost" loading={isUpdating} onClick={() => void toggleAgents(row)}>
            {row.agent_enabled ? "Agents off" : "Agents on"}
          </Button>
          <Button size="sm" variant="secondary" loading={isUpdating} onClick={() => void toggleActive(row)}>
            {row.is_active === false ? "Activate" : "Deactivate"}
          </Button>
          {confirmingId === row.id ? (
            <>
              <Button size="sm" variant="danger" loading={isDeleting} onClick={() => void remove(row.id)}>
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
    <SuperShell title="Branches" subtitle={`${branches.length} in the network`}>
      <div className="space-y-4">
        <Card>
          <CardTitle>New branch</CardTitle>
          <form className="space-y-4" onSubmit={create}>
            <div className="grid gap-3 sm:grid-cols-3">
              <Input
                label="Name"
                value={draft.name}
                onChange={(event) => patch({ name: event.target.value })}
                placeholder="Coimbatore Branch"
                required
              />
              <Input
                label="Code"
                value={draft.code}
                onChange={(event) => patch({ code: event.target.value.toUpperCase() })}
                placeholder="CBE"
                // The code prefixes every user's unique number for the life of
                // the branch, so it is worth getting right before the first user.
                hint="Prefixes every user number. Cannot be changed later."
                required
              />
              <Input
                label="Domain (optional)"
                value={draft.domain}
                onChange={(event) => patch({ domain: event.target.value.toLowerCase() })}
                placeholder="cbe.southind"
                hint="Lets the user app pick this branch by hostname."
              />
            </div>

            {formError ? <ErrorNote>{formError}</ErrorNote> : null}

            <Button type="submit" loading={isCreating}>
              <IconPlus size={16} />
              Create branch
            </Button>
          </form>
        </Card>

        <DataTable
          columns={columns}
          rows={branches}
          keyOf={(row) => row.id}
          loading={isLoading && !branches.length}
          emptyTitle="No branches yet"
          emptyBody="Create the first one above — nothing else in the product works without it."
        />
      </div>
    </SuperShell>
  );
}
