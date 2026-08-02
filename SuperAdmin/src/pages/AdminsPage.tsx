import { useState, type FormEvent } from "react";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input, Select } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus, IconTrash } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useCreateAdminMutation,
  useDeleteAdminMutation,
  useGetAdminsQuery,
  useUpdateAdminMutation,
} from "@/services/api";
import type { AdminRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatLastSeen } from "@/utils/dateTime";
import { sanitizePhone } from "@/utils/phone";

const emptyDraft = {
  name: "",
  phone: "",
  password: "",
  branch_id: "",
  allow_profit_view: false,
};

export default function AdminsPage() {
  const { branches, branchId } = useBranch();
  const { data: admins = [], isFetching, error } = useGetAdminsQuery(
    branchId ? { branchId } : undefined
  );
  const [createAdmin, { isLoading: isCreating }] = useCreateAdminMutation();
  const [updateAdmin, { isLoading: isUpdating }] = useUpdateAdminMutation();
  const [deleteAdmin, { isLoading: isDeleting }] = useDeleteAdminMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState({ ...emptyDraft, branch_id: branchId ? String(branchId) : "" });
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);

  const patch = (partial: Partial<typeof draft>) => setDraft((prev) => ({ ...prev, ...partial }));

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setNotice(null);

    if (!draft.name.trim()) return setFormError("Give the account a name.");
    if (!draft.branch_id) return setFormError("Pick the branch this account belongs to.");
    if (draft.password.length < 8) return setFormError("Password must be at least 8 characters.");

    try {
      await createAdmin({
        name: draft.name.trim(),
        phone: draft.phone.trim(),
        password: draft.password,
        role: "admin",
        branch_id: Number(draft.branch_id),
        allow_profit_view: draft.allow_profit_view ? 1 : 0,
      }).unwrap();
      setDraft({ ...emptyDraft, branch_id: draft.branch_id });
      setNotice("Account created. Pass the password on out of band, it is not shown again.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not create this account."));
    }
  };

  const toggleActive = async (admin: AdminRecord) => {
    setFormError(null);
    try {
      await updateAdmin({ id: admin.id, body: { is_active: admin.is_active === false } }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this account."));
    }
  };

  const toggleProfitView = async (admin: AdminRecord) => {
    setFormError(null);
    try {
      await updateAdmin({
        id: admin.id,
        body: { allow_profit_view: !admin.allow_profit_view },
      }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this account."));
    }
  };

  const remove = async (id: number) => {
    setFormError(null);
    try {
      await deleteAdmin(id).unwrap();
      setConfirmingId(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not delete this account."));
    }
  };

  const columns: Column<AdminRecord>[] = [
    {
      key: "admin",
      header: "Account",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.name ?? "Unnamed"}</span>
          <span className="tabular block truncate text-xs text-faint">{row.phone ?? ""}</span>
        </span>
      ),
    },
    {
      key: "role",
      header: "Role",
      render: (row) => (
        <Badge tone={row.role === "super_admin" ? "accent" : "neutral"}>
          {(row.role ?? "").replace(/_/g, " ")}
        </Badge>
      ),
    },
    {
      key: "branch",
      header: "Branch",
      render: (row) => <span className="truncate">{row.branch?.name ?? "—"}</span>,
    },
    {
      key: "profit",
      header: "Profit view",
      // Lifetime totals are the most sensitive thing a branch admin can see, so
      // it is a per-account grant rather than something the role implies.
      render: (row) => (
        <Badge tone={row.allow_profit_view ? "pos" : "neutral"}>
          {row.allow_profit_view ? "Allowed" : "Hidden"}
        </Badge>
      ),
      secondary: true,
    },
    {
      key: "seen",
      header: "Last active",
      render: (row) => (
        <span className="whitespace-nowrap">{formatLastSeen(row.last_active_at ?? row.last_login_at)}</span>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (row) => (
        <Badge tone={row.is_active === false ? "neg" : "pos"}>
          {row.is_active === false ? "Disabled" : "Active"}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) =>
        row.role === "super_admin" ? (
          // Locking yourself out of the only super account is unrecoverable
          // without database access, so the panel refuses to offer it.
          <span className="text-xs text-faint">Protected</span>
        ) : (
          <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <Button size="sm" variant="ghost" loading={isUpdating} onClick={() => void toggleProfitView(row)}>
              {row.allow_profit_view ? "Hide profit" : "Show profit"}
            </Button>
            <Button size="sm" variant="secondary" loading={isUpdating} onClick={() => void toggleActive(row)}>
              {row.is_active === false ? "Enable" : "Disable"}
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
    <SuperShell title="Branch admins" subtitle={`${admins.length} accounts`}>
      <div className="space-y-4">
        <Card>
          <CardTitle hint="Staff are managed from the branch panel">New branch admin</CardTitle>
          <form className="space-y-4" onSubmit={create}>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Input
                label="Name"
                value={draft.name}
                onChange={(event) => patch({ name: event.target.value })}
                required
              />
              <Input
                label="Phone"
                inputMode="numeric"
                value={draft.phone}
                onChange={(event) => patch({ phone: sanitizePhone(event.target.value) })}
                hint="This is the login id."
                required
              />
              <Input
                label="Password"
                type="password"
                value={draft.password}
                onChange={(event) => patch({ password: event.target.value })}
                hint="At least 8 characters. Shown once, here."
                required
              />
              <Select
                label="Branch"
                value={draft.branch_id}
                onChange={(event) => patch({ branch_id: event.target.value })}
                required
              >
                <option value="">Choose a branch…</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {branch.name}
                  </option>
                ))}
              </Select>
            </div>

            <label className="flex min-w-0 items-start gap-3">
              <input
                type="checkbox"
                checked={draft.allow_profit_view}
                onChange={(event) => patch({ allow_profit_view: event.target.checked })}
                className="mt-0.5 size-4 shrink-0 accent-[var(--accent)]"
              />
              <span className="min-w-0">
                <span className="block text-sm font-medium text-text">Allow profit view</span>
                <span className="block text-xs text-muted">
                  Lets this account see users' lifetime deposit and withdrawal totals.
                </span>
              </span>
            </label>

            {formError ? <ErrorNote>{formError}</ErrorNote> : null}
            {notice ? (
              <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
            ) : null}

            <Button type="submit" loading={isCreating}>
              <IconPlus size={16} />
              Create account
            </Button>
          </form>
        </Card>

        <DataTable
          columns={columns}
          rows={admins}
          keyOf={(row) => row.id}
          loading={isFetching && !admins.length}
          emptyTitle="No branch admins here"
          emptyBody="Create one above, or switch the branch filter in the header."
        />
      </div>
    </SuperShell>
  );
}
