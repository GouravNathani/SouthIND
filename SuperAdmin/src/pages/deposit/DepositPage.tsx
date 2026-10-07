import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import WhatsAppShare from "@/components/WhatsAppShare";
import QueueFilters, { EMPTY_FILTERS, type QueueFilterState } from "@/components/QueueFilters";
import Pagination from "@/components/Pagination";
import DataTable, { type Column } from "@/components/ui/DataTable";
import Badge, { statusTone } from "@/components/ui/Badge";
import Button from "@/components/ui/Button";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconRefresh } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetDepositsPageQuery } from "@/services/api";
import type { DepositRecord } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { money } from "@/utils/format";
import { rangeParams } from "@/utils/timeframe";
import { depositShareMessage } from "@/utils/whatsapp";

const PER_PAGE = 25;
const SEARCH_DEBOUNCE_MS = 350;

export default function DepositPage() {
  const navigate = useNavigate();
  const { branchId, branch, branches } = useBranch();
  const [filters, setFilters] = useState<QueueFilterState>(EMPTY_FILTERS);
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [page, setPage] = useState(1);

  // Typing a play id should not fire a request per keystroke against a queue
  // that can be tens of thousands of rows.
  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(filters.search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [filters.search]);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, filters.status, filters.timeframe, filters.customStart, filters.customEnd]);

  const params = useMemo(
    () => ({
      page,
      per_page: PER_PAGE,
      search: debouncedSearch || undefined,
      status: filters.status || undefined,
      ...rangeParams(filters.timeframe, { start: filters.customStart, end: filters.customEnd }),
      branchId: branchId ?? undefined,
    }),
    [page, debouncedSearch, filters.status, filters.timeframe, filters.customStart, filters.customEnd, branchId]
  );

  const { data, isFetching, error, refetch } = useGetDepositsPageQuery(params, {
    pollingInterval: 30000,
  });
  useSessionGuard(error);

  const rows = data?.data ?? [];

  const columns: Column<DepositRecord>[] = [
    {
      key: "user",
      header: "User",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">
            {row.user?.name ?? row.play_id ?? "—"}
          </span>
          <span className="block truncate text-xs text-faint">
            {row.play_id ?? row.user?.phone ?? ""}
          </span>
        </span>
      ),
    },
    {
      key: "amount",
      header: "Amount",
      render: (row) => <span className="tabular font-semibold">{money(row.amount)}</span>,
    },
    { key: "status", header: "Status", render: (row) => <Badge tone={statusTone(row.status)}>{row.status}</Badge> },
    {
      key: "utr",
      header: "UTR",
      render: (row) => <span className="tabular truncate">{row.utr_number || "—"}</span>,
      secondary: true,
    },
    {
      key: "branch",
      header: "Branch",
      render: (row) => <span className="truncate">{row.user?.branch?.name ?? "—"}</span>,
      secondary: true,
    },
    {
      key: "created",
      header: "Requested",
      render: (row) => <span className="whitespace-nowrap">{formatDateTime(row.created_at)}</span>,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <WhatsAppShare
          number={branches.find((entry) => entry.id === row.branch_id)?.deposit_wa}
          message={depositShareMessage(row)}
        />
      ),
    },
  ];

  return (
    <SuperShell
      title="Deposits"
      subtitle={[branch?.name ?? "All branches", data?.meta ? `${data.meta.total} in range` : null]
        .filter(Boolean)
        .join(" · ")}
      action={
        <Button size="sm" variant="secondary" onClick={() => void refetch()} loading={isFetching}>
          <IconRefresh size={16} />
          Refresh
        </Button>
      }
    >
      <div className="space-y-4">
        <QueueFilters value={filters} onChange={setFilters} />

        {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load deposits.")}</ErrorNote> : null}

        <DataTable
          columns={columns}
          rows={rows}
          keyOf={(row) => row.id}
          loading={isFetching && !rows.length}
          onRowClick={(row) => navigate(`/deposit/${row.id}`)}
          emptyTitle="No deposits match these filters"
          emptyBody="Widen the period or clear the status filter."
        />

        <Pagination meta={data?.meta} page={page} onPage={setPage} />
      </div>
    </SuperShell>
  );
}
