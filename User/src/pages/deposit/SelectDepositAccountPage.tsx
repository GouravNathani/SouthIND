import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Card from "@/components/ui/Card";
import Badge from "@/components/ui/Badge";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useGetDepositAccountsQuery, type DepositAccount } from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { buildImageUrl } from "@/utils/media";

/** Every value on this screen is copyable — the user is about to pay into it. */
function Detail({ label, value }: { label: string; value?: string | null }) {
  if (!value) return null;
  return (
    <p className="min-w-0 text-sm">
      <span className="text-muted">{label} </span>
      <span className="tabular [overflow-wrap:anywhere] text-text">{value}</span>
    </p>
  );
}

export default function SelectDepositAccountPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { data: accounts = [], isLoading, error } = useGetDepositAccountsQuery();

  const errorMessage = error ? getApiErrorMessage(error, t("deposit.accountsError")) : null;

  const goToDetails = (accountId: number) => {
    navigate(`/deposit/add/details?${new URLSearchParams({ accountId: String(accountId) })}`);
  };

  return (
    <AppShell title={t("deposit.title")} subtitle={t("deposit.selectAccount")}>
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }, (_, i) => (
            <Skeleton key={i} className="h-28 w-full" />
          ))}
        </div>
      ) : errorMessage ? (
        <ErrorNote>{errorMessage}</ErrorNote>
      ) : !accounts.length ? (
        <Card>
          <EmptyState title={t("deposit.noAccounts")} body={t("deposit.noAccountsBody")} />
        </Card>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {accounts.map((account) => (
            <li key={account.id} className="min-w-0">
              <AccountTile account={account} onSelect={() => goToDetails(account.id)} />
            </li>
          ))}
        </ul>
      )}
    </AppShell>
  );
}

function AccountTile({
  account,
  onSelect,
}: {
  account: DepositAccount;
  onSelect: () => void;
}) {
  const type = account.type?.trim().toLowerCase() ?? "";
  const logo = account.logo_url ?? buildImageUrl(account.logo_path);
  const isQr = type === "qr";

  return (
    <button
      type="button"
      onClick={onSelect}
      className="flex h-full w-full min-w-0 flex-col gap-3 rounded-lg border border-border bg-surface p-4 text-left transition-colors hover:border-accent-line"
    >
      <div className="flex min-w-0 items-center gap-3">
        {logo && !isQr ? (
          <img
            src={logo}
            alt=""
            loading="lazy"
            className="size-10 shrink-0 rounded-md border border-border object-cover"
          />
        ) : null}
        <div className="min-w-0 flex-1">
          <p className="line-clamp-2 text-sm font-semibold text-text">{account.name}</p>
          <p className="truncate text-xs text-muted">{account.holder_name}</p>
        </div>
        <Badge tone="accent">{(account.type ?? "").toUpperCase()}</Badge>
      </div>

      <div className="min-w-0 space-y-1">
        <Detail label="A/C" value={account.account_number} />
        <Detail label="IFSC" value={account.ifsc_code} />
        <Detail label="UPI" value={account.upi_id} />
      </div>

      {isQr ? (
        logo ? (
          <img
            src={logo}
            alt={`${account.name} QR`}
            loading="lazy"
            className="mx-auto size-40 max-w-full rounded-md border border-border object-contain"
          />
        ) : (
          <p className="rounded-md border border-dashed border-border py-8 text-center text-xs text-faint">
            QR missing
          </p>
        )
      ) : null}

      {account.notes ? <p className="text-xs break-words text-faint">{account.notes}</p> : null}
    </button>
  );
}
