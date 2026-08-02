import { useMemo, type ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import BannerCarousel from "@/components/BannerCarousel";
import WinnerRibbon from "@/components/WinnerRibbon";
import TransactionRow, { type LedgerEntry } from "@/components/TransactionRow";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconChat, IconDeposit, IconTrophy, IconWithdraw } from "@/components/icons";
import {
  useGetAppSettingsQuery,
  useGetDepositsQuery,
  useGetReferralAccountQuery,
  useGetWithdrawalsQuery,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { coerceToString, deriveWhatsAppLink } from "@/utils/appSettings";
import { mergeHistoryRecords } from "@/utils/history";
import { getStoredUser } from "@/utils/auth";
import { money } from "@/utils/format";

const RECENT_LIMIT = 6;

const sortByCreatedAtDesc = (a: LedgerEntry, b: LedgerEntry) =>
  Date.parse(b.created_at ?? "") - Date.parse(a.created_at ?? "");

export default function DashboardPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();

  const depositsQuery = useGetDepositsQuery();
  const withdrawalsQuery = useGetWithdrawalsQuery();
  const { data: appSettings } = useGetAppSettingsQuery();
  // Only an agent gets the Account tile; the endpoint answers is_agent:false
  // for everyone else, so no extra gate is needed.
  const { data: referralAccount } = useGetReferralAccountQuery();

  const storedUser = getStoredUser();
  const displayName =
    (typeof storedUser?.name === "string" && storedUser.name.trim()) ||
    (typeof storedUser?.play_id === "string" && storedUser.play_id) ||
    "";

  const recent = useMemo<LedgerEntry[]>(() => {
    const deposits = mergeHistoryRecords(depositsQuery.data).map<LedgerEntry>((record) => ({
      ...record,
      kind: "deposit",
    }));
    const withdrawals = mergeHistoryRecords(withdrawalsQuery.data).map<LedgerEntry>((record) => ({
      ...record,
      kind: "withdrawal",
    }));
    return [...deposits, ...withdrawals].sort(sortByCreatedAtDesc).slice(0, RECENT_LIMIT);
  }, [depositsQuery.data, withdrawalsQuery.data]);

  const summaryError = depositsQuery.error
    ? getApiErrorMessage(depositsQuery.error, t("dashboard.depositsUnavailable"))
    : withdrawalsQuery.error
      ? getApiErrorMessage(withdrawalsQuery.error, t("dashboard.withdrawalsUnavailable"))
      : null;

  const loading = depositsQuery.isLoading || withdrawalsQuery.isLoading;
  const whatsappLink = deriveWhatsAppLink(appSettings ?? null);
  const depositOffer = coerceToString(appSettings?.deposit_offer_text);
  const withdrawalOffer = coerceToString(appSettings?.withdrawal_offer_text);
  const commission = referralAccount?.is_agent ? referralAccount.account : undefined;

  return (
    <AppShell
      title={t("dashboard.title")}
      subtitle={displayName ? t("dashboard.greeting", { name: displayName }) : undefined}
    >
      <div className="space-y-4">
        <WinnerRibbon />
        <BannerCarousel href={whatsappLink ?? undefined} />

        {summaryError ? <ErrorNote>{summaryError}</ErrorNote> : null}

        <div className="grid gap-3 sm:grid-cols-2">
          <ActionTile
            icon={<IconDeposit size={22} />}
            label={t("dashboard.deposit")}
            offer={depositOffer}
            onClick={() => navigate("/deposit")}
          />
          <ActionTile
            icon={<IconWithdraw size={22} />}
            label={t("dashboard.withdraw")}
            offer={withdrawalOffer}
            onClick={() => navigate("/withdrawal")}
          />
        </div>

        {commission ? (
          <Card>
            <CardTitle hint={referralAccount?.rate?.label ?? undefined}>
              {t("account.title")}
            </CardTitle>
            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
              <div className="min-w-0">
                <p className="text-xs text-muted">{t("account.available")}</p>
                <p className="tabular text-2xl font-semibold text-accent">
                  {money(commission.available_balance)}
                </p>
              </div>
              <div className="min-w-0">
                <p className="text-xs text-muted">{t("account.team")}</p>
                <p className="tabular text-lg font-semibold">{commission.team_count}</p>
              </div>
              <Link
                to="/account"
                className="ml-auto shrink-0 text-[13px] font-semibold text-accent"
              >
                {t("common.viewAll")}
              </Link>
            </div>
          </Card>
        ) : null}

        <Card>
          <CardTitle
            hint={
              recent.length ? (
                <Link to="/history" className="text-accent">
                  {t("common.viewAll")}
                </Link>
              ) : undefined
            }
          >
            {t("dashboard.recent")}
          </CardTitle>

          {loading ? (
            <div className="space-y-3">
              {Array.from({ length: 3 }, (_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : recent.length ? (
            <ul className="min-w-0">
              {recent.map((entry) => (
                <TransactionRow key={`${entry.kind}-${entry.id}`} entry={entry} />
              ))}
            </ul>
          ) : (
            <EmptyState
              title={t("dashboard.noActivity")}
              body={t("dashboard.noActivityBody")}
              action={
                <Button size="sm" onClick={() => navigate("/deposit")}>
                  {t("dashboard.deposit")}
                </Button>
              }
            />
          )}
        </Card>

        <div className="grid gap-3 sm:grid-cols-2">
          <QuickLink to="/winners" icon={<IconTrophy size={18} />} label={t("winner.title")} />
          <QuickLink to="/chat" icon={<IconChat size={18} />} label={t("common.support")} />
        </div>
      </div>
    </AppShell>
  );
}

function ActionTile({
  icon,
  label,
  offer,
  onClick,
}: {
  icon: ReactNode;
  label: string;
  offer?: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex min-w-0 items-center gap-3 rounded-lg border border-border bg-surface p-4 text-left transition-colors hover:border-accent-line"
    >
      <span className="grid size-11 shrink-0 place-items-center rounded-md bg-accent text-on-accent">
        {icon}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-text">{label}</span>
        {offer ? <span className="block truncate text-xs text-accent-2">{offer}</span> : null}
      </span>
    </button>
  );
}

function QuickLink({
  to,
  icon,
  label,
}: {
  to: string;
  icon: ReactNode;
  label: string;
}) {
  return (
    <Link
      to={to}
      className="flex min-w-0 items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-sm font-medium text-text transition-colors hover:border-accent-line"
    >
      <span className="shrink-0 text-muted">{icon}</span>
      <span className="truncate">{label}</span>
    </Link>
  );
}
