import { useNavigate } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import DataTable, { type Column } from "@/components/ui/DataTable";
import Badge, { statusTone } from "@/components/ui/Badge";
import Button from "@/components/ui/Button";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetAccountsQuery } from "@/services/api";
import type { AccountRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";

/** What a user would actually pay into — the identifying line for a row. */
const targetOf = (account: AccountRecord) =>
  account.upi_id || account.account_number || account.holder_name || "—";

export default function AccountsPage() {
  const navigate = useNavigate();
  const { data: accounts = [], isFetching, error } = useGetAccountsQuery();
  useSessionGuard(error);

  const columns: Column<AccountRecord>[] = [
    {
      key: "name",
      header: "Account",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.name}</span>
          <span className="block truncate text-xs text-faint">{row.holder_name}</span>
        </span>
      ),
    },
    {
      key: "type",
      header: "Type",
      render: (row) => <Badge tone="accent">{(row.type ?? "").toUpperCase()}</Badge>,
    },
    {
      key: "target",
      header: "Pays into",
      render: (row) => <span className="tabular block truncate">{targetOf(row)}</span>,
    },
    {
      key: "used_for",
      header: "Used for",
      render: (row) => <span className="truncate">{row.used_for ?? "—"}</span>,
      secondary: true,
    },
    {
      key: "status",
      header: "Status",
      render: (row) => <Badge tone={statusTone(row.status)}>{row.status ?? "unknown"}</Badge>,
    },
    {
      key: "last_used",
      header: "Last used",
      render: (row) => (
        <span className="whitespace-nowrap">
          {row.last_used_at ? formatDateTime(row.last_used_at) : "Never"}
        </span>
      ),
    },
  ];

  return (
    <AdminShell
      title="Accounts"
      subtitle={`${accounts.length} configured`}
      action={
        <Button size="sm" onClick={() => navigate("/accounts/new")}>
          <IconPlus size={16} />
          New
        </Button>
      }
    >
      <div className="space-y-4">
        {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load accounts.")}</ErrorNote> : null}

        <DataTable
          columns={columns}
          rows={accounts}
          keyOf={(row) => row.id}
          loading={isFetching && !accounts.length}
          onRowClick={(row) => navigate(`/accounts/${row.id}`)}
          emptyTitle="No deposit accounts yet"
          emptyBody="Users cannot deposit until at least one account is published."
        />
      </div>
    </AdminShell>
  );
}
