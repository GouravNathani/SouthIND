import { useMemo, useState } from "react";
import { useLocation } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Card from "@/components/ui/Card";
import Badge, { statusTone } from "@/components/ui/Badge";
import Button from "@/components/ui/Button";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import {
  useCancelWithdrawalMutation,
  useGetDepositsQuery,
  useGetWithdrawalsQuery,
  type DepositRecord,
  type WithdrawalRecord,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { mergeHistoryRecords } from "@/utils/history";
import { dateTime, inr } from "@/utils/format";

type TabKey = "deposit" | "withdrawal";
type FilterKey = "all" | "pending" | "approved" | "rejected";

const FILTERS: FilterKey[] = ["all", "pending", "approved", "rejected"];

const normalizeStatus = (status?: string | null) => (status ?? "").toLowerCase();

const byCreatedAtDesc = <T extends { created_at: string }>(a: T, b: T) =>
  Date.parse(b.created_at) - Date.parse(a.created_at);

export default function HistoryPage() {
  const { t } = useTranslation();
  const location = useLocation();
  const initialTab = (location.state as { historyTab?: TabKey } | null)?.historyTab ?? "deposit";

  const [tab, setTab] = useState<TabKey>(initialTab);
  const [filter, setFilter] = useState<FilterKey>("all");
  const [cancelError, setCancelError] = useState<string | null>(null);

  const depositsQuery = useGetDepositsQuery();
  const withdrawalsQuery = useGetWithdrawalsQuery();
  const [cancelWithdrawal, { isLoading: cancelling }] = useCancelWithdrawalMutation();

  const records = useMemo(() => {
    const list =
      tab === "deposit"
        ? mergeHistoryRecords(depositsQuery.data)
        : mergeHistoryRecords(withdrawalsQuery.data);

    // The backend returns overlapping buckets (data + pending + recent_*), so a
    // record can arrive more than once — dedupe by id before showing it.
    const unique = new Map<number, DepositRecord | WithdrawalRecord>();
    for (const record of list) unique.set(record.id, record);

    return [...unique.values()]
      .sort(byCreatedAtDesc)
      .filter((record) => filter === "all" || normalizeStatus(record.status) === filter);
  }, [depositsQuery.data, withdrawalsQuery.data, tab, filter]);

  const query = tab === "deposit" ? depositsQuery : withdrawalsQuery;
  const errorMessage = query.error ? getApiErrorMessage(query.error, t("history.error")) : null;

  const handleCancel = async (id: number) => {
    setCancelError(null);
    try {
      await cancelWithdrawal(id).unwrap();
    } catch (error) {
      setCancelError(getApiErrorMessage(error, t("common.unexpected")));
    }
  };

  return (
    <AppShell title={t("history.title")}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-1 rounded-full border border-border bg-surface-2 p-1">
          {(["deposit", "withdrawal"] as const).map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setTab(key)}
              className={[
                "h-9 truncate rounded-full text-[13px] font-semibold transition-colors",
                tab === key ? "bg-accent text-on-accent" : "text-muted",
              ].join(" ")}
            >
              {key === "deposit" ? t("history.deposits") : t("history.withdrawals")}
            </button>
          ))}
        </div>

        {/* Wraps rather than scrolls: in Tamil or Malayalam the four chips are wider
            than a phone, and a hidden scrollbar gave no hint the last one existed. */}
        <div className="flex flex-wrap gap-2">
          {FILTERS.map((key) => (
            <button
              key={key}
              type="button"
              onClick={() => setFilter(key)}
              className={[
                "h-8 shrink-0 rounded-full border px-3.5 text-xs font-medium transition-colors",
                filter === key
                  ? "border-accent bg-accent-soft text-accent"
                  : "border-border bg-surface text-muted",
              ].join(" ")}
            >
              {t(`history.filter.${key}`)}
            </button>
          ))}
        </div>

        {cancelError ? <ErrorNote>{cancelError}</ErrorNote> : null}
        {errorMessage ? <ErrorNote>{errorMessage}</ErrorNote> : null}

        {query.isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }, (_, i) => (
              <Skeleton key={i} className="h-24 w-full" />
            ))}
          </div>
        ) : records.length ? (
          <ul className="space-y-3">
            {records.map((record) => (
              <li key={record.id}>
                <RecordCard
                  record={record}
                  canCancel={tab === "withdrawal" && normalizeStatus(record.status) === "pending"}
                  cancelling={cancelling}
                  onCancel={() => handleCancel(record.id)}
                />
              </li>
            ))}
          </ul>
        ) : (
          <Card>
            <EmptyState title={t("history.empty")} body={t("history.emptyBody")} />
          </Card>
        )}
      </div>
    </AppShell>
  );
}

function RecordCard({
  record,
  canCancel,
  cancelling,
  onCancel,
}: {
  record: DepositRecord | WithdrawalRecord;
  canCancel: boolean;
  cancelling: boolean;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const destination =
    record.upi_id ??
    (record.account_number ? `A/C ••••${record.account_number.slice(-4)}` : null) ??
    record.account_name ??
    null;

  return (
    <Card>
      <div className="flex min-w-0 items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="tabular text-xl font-semibold">₹{inr(record.amount)}</p>
          <p className="truncate text-xs text-muted">{dateTime(record.created_at)}</p>
        </div>
        <Badge tone={statusTone(record.status)}>
          {t(`history.filter.${normalizeStatus(record.status)}`, { defaultValue: record.status })}
        </Badge>
      </div>

      {destination ? (
        <p className="mt-2 truncate text-sm text-muted">{destination}</p>
      ) : null}
      {record.notes ? (
        <p className="mt-2 text-sm break-words text-faint">{record.notes}</p>
      ) : null}

      {canCancel ? (
        <div className="mt-3">
          <Button size="sm" variant="secondary" loading={cancelling} onClick={onCancel}>
            {t("history.cancel")}
          </Button>
        </div>
      ) : null}
    </Card>
  );
}
