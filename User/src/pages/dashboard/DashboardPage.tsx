import type { ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import BannerCarousel from "@/components/BannerCarousel";
import WinnerRibbon from "@/components/WinnerRibbon";
import PushSubscriptionManager from "@/components/PushSubscriptionManager";
import PushPromptCard from "@/components/PushPromptCard";
import Card, { CardTitle } from "@/components/ui/Card";
import { IconChat, IconDeposit, IconTrophy, IconWithdraw } from "@/components/icons";
import { useGetAppSettingsQuery, useGetReferralAccountQuery } from "@/services/api";
import { coerceToString, deriveWhatsAppLink } from "@/utils/appSettings";
import { getStoredUser } from "@/utils/auth";
import { money } from "@/utils/format";

export default function DashboardPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();

  const { data: appSettings } = useGetAppSettingsQuery();
  // Only an agent gets the Account tile; the endpoint answers is_agent:false
  // for everyone else, so no extra gate is needed.
  const { data: referralAccount } = useGetReferralAccountQuery();

  const storedUser = getStoredUser();
  const displayName =
    (typeof storedUser?.name === "string" && storedUser.name.trim()) ||
    (typeof storedUser?.play_id === "string" && storedUser.play_id) ||
    "";

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
        <PushSubscriptionManager />
        <PushPromptCard />
        <WinnerRibbon />
        <BannerCarousel href={whatsappLink ?? undefined} />

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

        <div className="grid gap-3 sm:grid-cols-2">
          <QuickLink to="/winners" icon={<IconTrophy size={18} />} label={t("winner.title")} />
          {appSettings?.support_chat_enabled !== false ? (
            <QuickLink to="/chat" icon={<IconChat size={18} />} label={t("common.support")} />
          ) : null}
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
