import { useEffect, useMemo, useState } from "react";
import Pagination from "@/components/Pagination";
import HistoryFilters, {
  EMPTY_HISTORY_FILTERS,
  historyQuery,
  isFiltered,
  type HistoryFilterState,
} from "@/components/wahub/HistoryFilters";
import MessageList from "@/components/wahub/MessageList";
import WaErrorState from "@/components/wahub/WaErrorState";
import { count, toPaginationMeta } from "@/components/wahub/waFormat";
import { usePageVisible } from "@/hooks/usePageVisible";
import { useGetWaMessagesQuery } from "@/services/api";

const PER_PAGE = 25;
const POLL_MS = 15000;
const SEARCH_DEBOUNCE_MS = 350;

/** Every message this branch's number sent or skipped, newest first. */
export default function HistoryTab() {
  const [filters, setFilters] = useState<HistoryFilterState>(EMPTY_HISTORY_FILTERS);
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const visible = usePageVisible();

  useEffect(() => {
    const timer = window.setTimeout(() => setQ(filters.q.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [filters.q]);

  const query = useMemo(() => historyQuery(filters, q), [filters, q]);
  const queryKey = JSON.stringify(query);
  useEffect(() => setPage(1), [queryKey]);

  // Ticks change after sending (delivered, read), so the open list refreshes
  // itself — but only while someone can see it.
  const messages = useGetWaMessagesQuery(
    { ...query, page, per_page: PER_PAGE },
    { pollingInterval: visible ? POLL_MS : 0, refetchOnMountOrArgChange: true }
  );

  const rows = messages.data?.data ?? [];
  const meta = messages.data?.meta;

  return (
    <div className="space-y-4">
      <HistoryFilters value={filters} onChange={setFilters} />

      {messages.error && !messages.data ? (
        <WaErrorState
          error={messages.error}
          onRetry={() => void messages.refetch()}
          retrying={messages.isFetching}
          fallback="Could not load history."
        />
      ) : (
        <>
          {meta ? (
            <p className="tabular text-xs text-muted">
              {count(meta.total)} message{meta.total === 1 ? "" : "s"}
              {rows.length ? ` · ${count(rows.reduce((sum, row) => sum + (row.coins ?? 0), 0))} coins on this page` : ""}
            </p>
          ) : null}
          <MessageList
            rows={rows}
            loading={messages.isFetching && !messages.data}
            emptyTitle={isFiltered(filters) ? "No messages match these filters" : "No messages yet"}
            emptyBody={
              isFiltered(filters)
                ? "Clear a filter or pick a wider date range."
                : "Messages appear here as soon as an event, campaign or test sends one."
            }
          />
          <Pagination meta={toPaginationMeta(meta)} page={page} onPage={setPage} />
        </>
      )}
    </div>
  );
}
