import { useEffect, useState } from "react";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input } from "@/components/ui/Field";
import { IconChevronDown } from "@/components/icons";
import { ErrorNote } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetUsersQuery,
  useMoveUserBranchMutation,
  useResetUserMpinMutation,
  useUpdateUserStatusMutation,
} from "@/services/api";
import type { UserRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatLastSeen, isActiveNow } from "@/utils/dateTime";
import { compactInr } from "@/utils/format";

const SEARCH_DEBOUNCE_MS = 350;

export default function UsersPage() {
  const { branches, branchId, branch } = useBranch();
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [movingId, setMovingId] = useState<number | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  const { data: users = [], isFetching, error } = useGetUsersQuery({
    branchId: branchId ?? undefined,
    search: debouncedSearch || undefined,
  });
  const [updateStatus, { isLoading: isUpdating }] = useUpdateUserStatusMutation();
  const [resetMpin, { isLoading: isResetting }] = useResetUserMpinMutation();
  const [moveBranch, { isLoading: isMoving }] = useMoveUserBranchMutation();
  useSessionGuard(error);

  const toggleBan = async (user: UserRecord) => {
    setFormError(null);
    setNotice(null);
    const banned = (user.status ?? "").toLowerCase() === "banned";
    try {
      await updateStatus({ id: user.id, status: banned ? "active" : "banned" }).unwrap();
      setNotice(banned ? "User unbanned." : "User banned.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not change the status."));
    }
  };

  const regenerate = async (user: UserRecord) => {
    setFormError(null);
    setNotice(null);
    try {
      await resetMpin({ id: user.id }).unwrap();
      setNotice("MPIN regenerated — the new one shows in the list.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not regenerate the MPIN."));
    }
  };

  const move = async (user: UserRecord, targetBranchId: number) => {
    setFormError(null);
    setNotice(null);
    try {
      await moveBranch({ id: user.id, branch_id: targetBranchId }).unwrap();
      setMovingId(null);
      setNotice(`${user.name ?? "User"} moved to ${branches.find((b) => b.id === targetBranchId)?.name}.`);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not move this user."));
    }
  };

  const columns: Column<UserRecord>[] = [
    {
      key: "user",
      header: "User",
      render: (row) => (
        <span className="block min-w-0">
          <span className="flex min-w-0 items-center gap-2">
            <span
              aria-hidden
              className="size-1.5 shrink-0 rounded-full"
              style={{ background: isActiveNow(row.last_seen_at) ? "var(--pos)" : "var(--text-faint)" }}
            />
            <span className="truncate font-medium text-text">{row.name || "Unnamed"}</span>
          </span>
          <span className="tabular block truncate text-xs text-faint">
            {row.play_id ?? row.unique_number ?? row.phone ?? ""}
          </span>
        </span>
      ),
    },
    { key: "phone", header: "Phone", render: (row) => <span className="tabular">{row.phone ?? "—"}</span> },
    {
      key: "branch",
      header: "Branch",
      render: (row) => <span className="truncate">{row.branch?.name ?? "—"}</span>,
    },
    {
      key: "mpin",
      header: "MPIN",
      render: (row) => (
        <span className="tabular inline-flex items-center gap-2">
          {row.mpin ?? "—"}
          {row.mpin_locked ? <Badge tone="neg">Locked</Badge> : null}
        </span>
      ),
      secondary: true,
    },
    {
      key: "deposits",
      header: "Deposited",
      render: (row) => (
        <span className="tabular">
          {row.deposit_approved_total == null ? "—" : compactInr(row.deposit_approved_total)}
        </span>
      ),
      secondary: true,
    },
    {
      key: "status",
      header: "Status",
      render: (row) => <Badge tone={statusTone(row.status)}>{row.status ?? "unknown"}</Badge>,
    },
    {
      key: "seen",
      header: "Last seen",
      render: (row) => <span className="whitespace-nowrap">{formatLastSeen(row.last_seen_at)}</span>,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          {movingId === row.id ? (
            // Moving a user re-homes their whole history, so the target is an
            // explicit pick rather than a cycle-through-branches button.
            <>
              <span className="relative inline-block max-w-[11rem] min-w-0">
                <select
                  aria-label="Move to branch"
                  defaultValue=""
                  onChange={(event) => {
                    if (event.target.value) void move(row, Number(event.target.value));
                  }}
                  className="h-9 w-full min-w-0 cursor-pointer appearance-none truncate rounded-full border border-border bg-surface-2 pr-8 pl-3 text-xs text-text focus:border-accent focus:outline-none"
                >
                  <option value="">Move to…</option>
                  {branches
                    .filter((entry) => entry.id !== row.branch_id)
                    .map((entry) => (
                      <option key={entry.id} value={entry.id}>
                        {entry.name}
                      </option>
                    ))}
                </select>
                <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-muted">
                  <IconChevronDown size={14} />
                </span>
              </span>
              <Button size="sm" variant="ghost" onClick={() => setMovingId(null)}>
                Cancel
              </Button>
            </>
          ) : branches.length > 1 ? (
            <Button size="sm" variant="ghost" loading={isMoving} onClick={() => setMovingId(row.id)}>
              Move
            </Button>
          ) : null}
          <Button size="sm" variant="ghost" loading={isResetting} onClick={() => void regenerate(row)}>
            New MPIN
          </Button>
          <Button
            size="sm"
            variant={(row.status ?? "").toLowerCase() === "banned" ? "primary" : "secondary"}
            loading={isUpdating}
            onClick={() => void toggleBan(row)}
          >
            {(row.status ?? "").toLowerCase() === "banned" ? "Unban" : "Ban"}
          </Button>
        </span>
      ),
    },
  ];

  return (
    <SuperShell
      title="Users"
      subtitle={`${branch?.name ?? "All branches"} · ${users.length} shown`}
    >
      <div className="space-y-4">
        <Card>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Search"
              placeholder="Name, phone, play ID"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
            {/* Read-only: the branch is chosen once, in the header, for the whole panel. */}
            <div className="min-w-0">
              <span className="mb-1.5 block text-xs font-medium text-muted">Branch</span>
              <p className="flex h-11 min-w-0 items-center rounded-md border border-dashed border-border px-3.5 text-sm text-text">
                <span className="truncate">{branch?.name ?? "All branches"}</span>
              </p>
              {branches.length > 1 ? (
                <span className="mt-1.5 block text-xs text-faint">Switch it from the branch picker at the top.</span>
              ) : null}
            </div>
          </div>
        </Card>

        {formError ? <ErrorNote>{formError}</ErrorNote> : null}
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}
        {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load users.")}</ErrorNote> : null}

        <DataTable
          columns={columns}
          rows={users}
          keyOf={(row) => row.id}
          loading={isFetching && !users.length}
          emptyTitle="No users match this search"
          emptyBody="Clear the search, or switch the branch in the header."
        />
      </div>
    </SuperShell>
  );
}
