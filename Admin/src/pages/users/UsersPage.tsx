import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import AgentSelect from "@/components/AgentSelect";
import Pagination from "@/components/Pagination";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input } from "@/components/ui/Field";
import Segmented from "@/components/ui/Segmented";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useCreateUserMutation, useGetUsersQuery } from "@/services/api";
import type { UserRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatLastSeen, isActiveNow } from "@/utils/dateTime";
import { compactInr } from "@/utils/format";
import { sanitizePhone, validateMobile } from "@/utils/phone";

const PER_PAGE = 25;
const SEARCH_DEBOUNCE_MS = 350;

export default function UsersPage() {
  const navigate = useNavigate();

  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  const [formName, setFormName] = useState("");
  const [formPhone, setFormPhone] = useState("");
  const [formPlayId, setFormPlayId] = useState("");
  const [formAgentId, setFormAgentId] = useState<number | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [formSuccess, setFormSuccess] = useState<string | null>(null);

  const [createUser, { isLoading: isCreating }] = useCreateUserMutation();

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => setPage(1), [debouncedSearch, status]);

  const params = useMemo(
    () => ({
      page,
      per_page: PER_PAGE,
      search: debouncedSearch || undefined,
      status: status || undefined,
    }),
    [page, debouncedSearch, status]
  );

  const { data, isFetching, error } = useGetUsersQuery(params);
  useSessionGuard(error);

  const rows = data?.data ?? [];
  const stats = data?.user_stats;

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setFormSuccess(null);

    const phone = sanitizePhone(formPhone);
    setFormPhone(phone);

    const invalid = validateMobile(phone);
    if (invalid) {
      setFormError(invalid);
      return;
    }

    try {
      const result = await createUser({
        phone,
        ...(formName.trim() ? { name: formName.trim() } : {}),
        ...(formPlayId.trim() ? { play_id: formPlayId.trim() } : {}),
        ...(formAgentId ? { agent_id: formAgentId } : {}),
      }).unwrap();

      // The backend returns a warning (not an error) for things like a phone
      // that already exists in another branch — surface it rather than swallow it.
      const warning =
        typeof (result as Record<string, unknown>)?.warning === "string"
          ? String((result as Record<string, unknown>).warning)
          : "";

      setFormSuccess(
        [
          formAgentId ? "User generated and attached to the agent." : "User generated.",
          warning,
        ]
          .filter(Boolean)
          .join(" ")
      );
      setFormName("");
      setFormPhone("");
      setFormPlayId("");
      setFormAgentId(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not generate this user."));
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
          <span className="block truncate text-xs text-faint">
            {row.play_id ?? row.unique_number ?? row.phone ?? ""}
          </span>
        </span>
      ),
    },
    { key: "phone", header: "Phone", render: (row) => <span className="tabular">{row.phone ?? "—"}</span> },
    {
      key: "mpin",
      header: "MPIN",
      render: (row) => (
        <span className="tabular inline-flex items-center gap-2">
          {row.mpin ?? "—"}
          {row.mpin_locked ? <Badge tone="neg">Locked</Badge> : null}
        </span>
      ),
    },
    {
      key: "deposits",
      header: "Deposited",
      // null means this admin is not cleared to see totals — that is not ₹0.
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
  ];

  return (
    <AdminShell
      title="Users"
      subtitle={
        stats
          ? `${stats.total ?? 0} total · ${stats.active_now ?? 0} online · ${stats.new_24h ?? 0} new today`
          : undefined
      }
    >
      <div className="space-y-4">
        <Card>
          <CardTitle>Generate user</CardTitle>
          <form className="space-y-4" onSubmit={handleCreate}>
            <div className="grid gap-3 sm:grid-cols-3">
              <Input
                label="Phone"
                inputMode="numeric"
                placeholder="9845127634"
                value={formPhone}
                onChange={(event) => setFormPhone(sanitizePhone(event.target.value))}
                required
              />
              <Input
                label="Name (optional)"
                value={formName}
                onChange={(event) => setFormName(event.target.value)}
              />
              <Input
                label="Play ID (optional)"
                value={formPlayId}
                onChange={(event) => setFormPlayId(event.target.value.toUpperCase())}
                hint="Blank lets the backend assign one."
              />
            </div>

            <AgentSelect value={formAgentId} onChange={(id) => setFormAgentId(id)} />

            {formError ? <ErrorNote>{formError}</ErrorNote> : null}
            {formSuccess ? (
              <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{formSuccess}</p>
            ) : null}

            <Button type="submit" loading={isCreating} disabled={formPhone.length !== 10}>
              <IconPlus size={16} />
              Generate
            </Button>
          </form>
        </Card>

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
              value={status}
              onChange={setStatus}
              options={[
                { value: "", label: "All" },
                { value: "active", label: "Active" },
                { value: "banned", label: "Banned" },
              ]}
            />
          </div>
        </Card>

        {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load users.")}</ErrorNote> : null}

        <DataTable
          columns={columns}
          rows={rows}
          keyOf={(row) => row.id}
          loading={isFetching && !rows.length}
          onRowClick={(row) => navigate(`/users/${row.id}`)}
          emptyTitle="No users match this search"
          emptyBody="Generate one above, or clear the filters."
        />

        <Pagination meta={data?.meta} page={page} onPage={setPage} />
      </div>
    </AdminShell>
  );
}
