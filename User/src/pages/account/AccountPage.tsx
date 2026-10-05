import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import ThemeSwitcher from "@/components/ThemeSwitcher";
import LanguageSwitcher from "@/components/LanguageSwitcher";
import CopyRow from "@/components/CopyRow";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import Segmented from "@/components/ui/Segmented";
import Badge, { statusTone } from "@/components/ui/Badge";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import {
  useApplyReferralCodeMutation,
  useChangeMpinMutation,
  useGetReferralAccountQuery,
  useGetReferralPayoutsQuery,
  useGetReferralTeamQuery,
  useLogoutMutation,
  useRequestReferralPayoutMutation,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { clearAuthToken, getStoredUser } from "@/utils/auth";
import { dateTime, money, toNumber } from "@/utils/format";

export default function AccountPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const { data: account, isLoading } = useGetReferralAccountQuery();
  const isAgent = Boolean(account?.is_agent);

  const storedUser = getStoredUser();
  const name = typeof storedUser?.name === "string" ? storedUser.name : "";
  const phone = typeof storedUser?.phone === "string" ? storedUser.phone : "";
  const playId = typeof storedUser?.play_id === "string" ? storedUser.play_id : "";

  const [logout, { isLoading: loggingOut }] = useLogoutMutation();

  const handleLogout = async () => {
    // The local session is cleared either way — a failed API logout must not
    // strand the user in a signed-in shell.
    try {
      await logout().unwrap();
    } catch {
      /* ignore */
    }
    clearAuthToken();
    navigate("/", { replace: true });
  };

  return (
    <AppShell title={t("account.title")} subtitle={name || playId || undefined}>
      <div className="space-y-4">
        <Card>
          <CardTitle>{t("account.profile")}</CardTitle>
          <CopyRow label={t("account.name")} value={name} />
          <CopyRow label={t("account.phone")} value={phone} />
          <CopyRow label={t("account.playId")} value={playId} />
        </Card>

        {isLoading ? <Skeleton className="h-40 w-full" /> : null}
        {!isLoading && account?.enabled ? (
          isAgent ? (
            <AgentSection />
          ) : (
            <ReferralJoinCard />
          )
        ) : null}

        <Card>
          <CardTitle>{t("account.appearance")}</CardTitle>
          <ThemeSwitcher />
          <div className="mt-4 border-t border-border pt-4">
            <p className="mb-2 text-xs font-medium text-muted">{t("account.language")}</p>
            <LanguageSwitcher />
          </div>
        </Card>

        <ChangeMpinCard />

        <Card>
          <Button variant="secondary" block loading={loggingOut} onClick={handleLogout}>
            {t("account.logout")}
          </Button>
        </Card>
      </div>
    </AppShell>
  );
}

/** Shown to a non-agent: the only referral action available is attaching a code. */
function ReferralJoinCard() {
  const { t } = useTranslation();
  const [code, setCode] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [applyReferralCode, { isLoading }] = useApplyReferralCodeMutation();

  const handleApply = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setMessage(null);
    try {
      const result = await applyReferralCode({ referral_code: code.trim().toUpperCase() }).unwrap();
      setMessage(
        result.data?.referrer_name
          ? t("account.referrerLinked", { name: result.data.referrer_name })
          : (result.message ?? t("account.referralApplied"))
      );
      setCode("");
    } catch (err) {
      setError(getApiErrorMessage(err, t("account.referralFailed")));
    }
  };

  return (
    <Card>
      <CardTitle>{t("account.referral")}</CardTitle>
      <form className="flex min-w-0 items-end gap-2" onSubmit={handleApply}>
        <div className="min-w-0 flex-1">
          <Input
            label={t("account.referralCode")}
            value={code}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
            error={error}
          />
        </div>
        <Button type="submit" loading={isLoading} disabled={!code.trim()}>
          {t("account.apply")}
        </Button>
      </form>
      {message ? (
        <p className="mt-2 rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{message}</p>
      ) : null}
    </Card>
  );
}

function AgentSection() {
  const { t } = useTranslation();
  const { data: account } = useGetReferralAccountQuery();
  const { data: team = [] } = useGetReferralTeamQuery();
  const { data: payouts = [] } = useGetReferralPayoutsQuery();

  const summary = account?.account;
  const programme = account?.programme;

  return (
    <>
      <Card>
        <CardTitle hint={account?.rate?.label ?? undefined}>{t("account.commission")}</CardTitle>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label={t("account.available")} value={money(summary?.available_balance)} accent />
          <Stat label={t("account.pending")} value={money(summary?.pending_balance)} />
          <Stat label={t("account.lifetime")} value={money(summary?.lifetime_earned)} />
          <Stat label={t("account.team")} value={String(summary?.team_count ?? 0)} />
        </div>

        {account?.referral_code ? (
          <div className="mt-4 border-t border-border pt-3">
            <CopyRow label={t("account.referralCode")} value={account.referral_code} />
          </div>
        ) : null}

        {account?.next_tier ? (
          <p className="mt-3 text-xs text-muted">
            {t("account.nextTier", {
              label: account.next_tier.label,
              amount: account.next_tier.remaining.toLocaleString("en-IN"),
              percent: account.next_tier.percent,
            })}
          </p>
        ) : null}
      </Card>

      {programme?.enabled ? <PayoutCard minimum={programme.min_payout_amount} /> : null}

      <Card>
        <CardTitle hint={String(team.length)}>{t("account.teamTitle")}</CardTitle>
        {team.length ? (
          <ul className="min-w-0">
            {team.map((member) => (
              <li
                key={member.id}
                className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-text">{member.name}</p>
                  <p className="truncate text-xs text-faint">
                    {member.play_id ?? member.phone ?? ""}
                  </p>
                </div>
                <div className="shrink-0 text-right">
                  <p className="tabular text-sm font-semibold text-accent">
                    {money(member.commission_earned)}
                  </p>
                  <p className="tabular text-xs text-faint">{money(member.deposit_total)}</p>
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title={t("account.noTeam")} body={t("account.noTeamBody")} />
        )}
      </Card>

      {payouts.length ? (
        <Card>
          <CardTitle>{t("account.payouts")}</CardTitle>
          <ul className="min-w-0">
            {payouts.map((payout) => (
              <li
                key={payout.id}
                className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0"
              >
                <div className="min-w-0 flex-1">
                  <p className="tabular text-sm font-semibold">{money(payout.amount)}</p>
                  <p className="truncate text-xs text-faint">
                    {payout.method.toUpperCase()} · {dateTime(payout.created_at)}
                  </p>
                </div>
                <Badge tone={statusTone(payout.status)}>
                  {t(`history.filter.${payout.status}`, { defaultValue: payout.status })}
                </Badge>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </>
  );
}

function PayoutCard({ minimum }: { minimum: number }) {
  const { t } = useTranslation();
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<"upi" | "bank" | "play">("upi");
  const [upiId, setUpiId] = useState("");
  const [accountNumber, setAccountNumber] = useState("");
  const [ifsc, setIfsc] = useState("");
  const [accountName, setAccountName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const [requestPayout, { isLoading }] = useRequestReferralPayoutMutation();

  const amountValue = toNumber(amount);
  const canSubmit =
    amountValue >= minimum &&
    (method === "play" ||
      (method === "upi" && upiId.trim().length > 0) ||
      (method === "bank" && accountNumber.trim().length > 0 && accountName.trim().length > 0));

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setDone(false);
    try {
      await requestPayout({
        amount: amountValue,
        method,
        ...(method === "upi" ? { upi_id: upiId.trim() } : {}),
        ...(method === "bank"
          ? {
              account_number: accountNumber.trim(),
              ifsc_code: ifsc.trim().toUpperCase(),
              account_name: accountName.trim(),
            }
          : {}),
      }).unwrap();
      setDone(true);
      setAmount("");
    } catch (err) {
      setError(getApiErrorMessage(err, t("common.unexpected")));
    }
  };

  return (
    <Card>
      <CardTitle>{t("account.requestPayout")}</CardTitle>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Input
          label={t("account.amount")}
          type="number"
          inputMode="numeric"
          min={minimum}
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          hint={t("account.payoutMinimum", { amount: money(minimum) })}
          required
        />

        <Segmented
          label={t("account.method")}
          value={method}
          onChange={setMethod}
          options={[
            { value: "upi", label: "UPI" },
            { value: "bank", label: t("account.bank") },
            { value: "play", label: t("account.playCredit") },
          ]}
        />

        {method === "upi" ? (
          <Input
            label="UPI"
            value={upiId}
            onChange={(event) => setUpiId(event.target.value)}
            placeholder="name@bank"
          />
        ) : null}

        {method === "bank" ? (
          <>
            <Input
              label={t("withdraw.accountName")}
              value={accountName}
              onChange={(event) => setAccountName(event.target.value)}
            />
            <Input
              label={t("withdraw.accountNumber")}
              inputMode="numeric"
              value={accountNumber}
              onChange={(event) => setAccountNumber(event.target.value.replace(/\D/g, ""))}
            />
            <Input
              label={t("withdraw.ifsc")}
              value={ifsc}
              onChange={(event) => setIfsc(event.target.value.toUpperCase())}
            />
          </>
        ) : null}

        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {done ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
            {t("account.payoutRequested")}
          </p>
        ) : null}

        <Button type="submit" block loading={isLoading} disabled={!canSubmit}>
          {t("account.submitPayout")}
        </Button>
      </form>
    </Card>
  );
}

function ChangeMpinCard() {
  const { t } = useTranslation();
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [changeMpin, { isLoading }] = useChangeMpinMutation();

  const digitsOnly = (value: string) => value.replace(/\D/g, "").slice(0, 6);
  const valid = /^\d{6}$/.test(current) && /^\d{6}$/.test(next) && next === confirm;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setDone(false);
    try {
      await changeMpin({ current_mpin: current, mpin: next, mpin_confirmation: confirm }).unwrap();
      setDone(true);
      setCurrent("");
      setNext("");
      setConfirm("");
    } catch (err) {
      setError(getApiErrorMessage(err, t("common.unexpected")));
    }
  };

  return (
    <Card>
      <CardTitle>{t("account.changeMpin")}</CardTitle>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Input
          label={t("account.currentMpin")}
          type="password"
          inputMode="numeric"
          value={current}
          onChange={(event) => setCurrent(digitsOnly(event.target.value))}
        />
        <Input
          label={t("account.newMpin")}
          type="password"
          inputMode="numeric"
          value={next}
          onChange={(event) => setNext(digitsOnly(event.target.value))}
        />
        <Input
          label={t("account.confirmMpin")}
          type="password"
          inputMode="numeric"
          value={confirm}
          onChange={(event) => setConfirm(digitsOnly(event.target.value))}
          error={confirm.length > 0 && confirm !== next ? t("account.mpinMismatch") : null}
        />

        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {done ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
            {t("account.mpinChanged")}
          </p>
        ) : null}

        <Button type="submit" block variant="secondary" loading={isLoading} disabled={!valid}>
          {t("account.changeMpin")}
        </Button>
      </form>
    </Card>
  );
}

function Stat({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="min-w-0">
      <p className="truncate text-xs text-muted">{label}</p>
      <p
        className="tabular truncate text-lg font-semibold"
        style={accent ? { color: "var(--accent)" } : undefined}
      >
        {value}
      </p>
    </div>
  );
}
