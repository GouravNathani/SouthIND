import { useEffect, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import {
  useCreateWithdrawalMutation,
  useGetAppSettingsQuery,
  type CreateWithdrawalPayload,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { getStoredUser } from "@/utils/auth";
import { PLAY_ID_KEY } from "@/components/AuthStatusGate";
import { money, toNumber } from "@/utils/format";

const ACCOUNTS_KEY = "sind_withdraw_accounts";
const SLOT_COUNT = 3;
const DEFAULT_MIN_WITHDRAWAL = 500;
const IFSC_REGEX = /^[A-Z]{4}[0-9][A-Z0-9]{6}$/;

type AccountDraft = {
  account_name: string;
  account_number: string;
  ifsc_code: string;
  upi_id: string;
  playId: string;
};

const emptyAccount = (): AccountDraft => ({
  account_name: "",
  account_number: "",
  ifsc_code: "",
  upi_id: "",
  playId: "",
});

const normalizeAccount = (value: Partial<AccountDraft> | undefined): AccountDraft => ({
  account_name: typeof value?.account_name === "string" ? value.account_name : "",
  account_number: typeof value?.account_number === "string" ? value.account_number : "",
  ifsc_code: typeof value?.ifsc_code === "string" ? value.ifsc_code : "",
  upi_id: typeof value?.upi_id === "string" ? value.upi_id : "",
  playId: typeof value?.playId === "string" ? value.playId : "",
});

const normalizeIfsc = (value: string) =>
  value.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 11);

/**
 * Three payout slots, persisted in localStorage. Re-typing a bank account on a
 * phone is where withdrawals go wrong, so the details survive across sessions
 * and the user just picks the slot they used last time.
 */
export default function AddWithdrawalPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();

  const { data: appSettings } = useGetAppSettingsQuery();
  const [createWithdrawal, { isLoading: isSubmitting }] = useCreateWithdrawalMutation();

  const [amount, setAmount] = useState("");
  const [accounts, setAccounts] = useState<AccountDraft[]>(() =>
    Array.from({ length: SLOT_COUNT }, emptyAccount)
  );
  const [activeIndex, setActiveIndex] = useState(0);
  const [playIdLocked, setPlayIdLocked] = useState(false);
  const [hydrated, setHydrated] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    try {
      const stored = window.localStorage.getItem(ACCOUNTS_KEY);
      if (stored) {
        const parsed = JSON.parse(stored) as { activeIndex?: unknown; accounts?: unknown };
        if (Array.isArray(parsed.accounts)) {
          const saved = parsed.accounts as Array<Partial<AccountDraft> | undefined>;
          setAccounts(Array.from({ length: SLOT_COUNT }, (_, index) => normalizeAccount(saved[index])));
        }
        if (
          typeof parsed.activeIndex === "number" &&
          Number.isInteger(parsed.activeIndex) &&
          parsed.activeIndex >= 0 &&
          parsed.activeIndex < SLOT_COUNT
        ) {
          setActiveIndex(parsed.activeIndex);
        }
      }
    } catch {
      /* corrupt storage — fall back to blank slots */
    }

    const storedUser = getStoredUser();
    const assignedPlayId =
      typeof storedUser?.play_id === "string" ? storedUser.play_id.trim() : "";
    const storedPhone =
      typeof storedUser?.phone === "string" ? storedUser.phone.replace(/\D/g, "") : "";
    const remembered = (window.sessionStorage.getItem(PLAY_ID_KEY) ?? "").trim();
    const rememberedIsPhone =
      Boolean(remembered && storedPhone) && remembered.replace(/\D/g, "") === storedPhone;

    const resolvedPlayId = assignedPlayId || (rememberedIsPhone ? "" : remembered);
    if (resolvedPlayId) {
      setAccounts((prev) => prev.map((account) => ({ ...account, playId: resolvedPlayId })));
      setPlayIdLocked(Boolean(assignedPlayId));
    }

    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) return;
    window.localStorage.setItem(ACCOUNTS_KEY, JSON.stringify({ activeIndex, accounts }));
  }, [accounts, activeIndex, hydrated]);

  const current = accounts[activeIndex] ?? emptyAccount();

  const setField = (field: keyof AccountDraft, value: string) =>
    setAccounts((prev) => {
      const next = [...prev];
      next[activeIndex] = {
        ...next[activeIndex],
        [field]: field === "ifsc_code" ? normalizeIfsc(value) : value,
      };
      return next;
    });

  const minimumAmount = (() => {
    const raw = appSettings?.min_withdrawal_amount;
    if (raw === null || raw === undefined || raw === "") return DEFAULT_MIN_WITHDRAWAL;
    const parsed = Number(raw);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : DEFAULT_MIN_WITHDRAWAL;
  })();

  const ifscValid = IFSC_REGEX.test(current.ifsc_code.trim());
  const hasUpi = current.upi_id.trim().length > 0;
  const hasBank =
    current.account_number.trim().length > 0 && ifscValid && current.account_name.trim().length > 0;

  const amountValue = toNumber(amount);
  const belowMinimum = amountValue > 0 && amountValue < minimumAmount;
  const canSubmit =
    !isSubmitting && amountValue >= minimumAmount && current.playId.trim().length > 0 && (hasUpi || hasBank);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (belowMinimum) {
      setError(t("withdraw.minimum", { amount: money(minimumAmount) }));
      return;
    }
    if (!canSubmit) return;

    setError(null);
    const playIdValue = current.playId.trim();

    try {
      const payload: CreateWithdrawalPayload = { amount: amountValue, play_id: playIdValue };
      // Bank wins when both are filled — it is the slower, more explicit rail and
      // the one the user typed the most detail into.
      if (hasBank) {
        payload.destination_type = "bank";
        payload.account_number = current.account_number.trim();
        payload.ifsc_code = current.ifsc_code.trim();
        payload.account_name = current.account_name.trim();
      } else {
        payload.destination_type = "upi";
        payload.upi_id = current.upi_id.trim();
      }

      await createWithdrawal(payload).unwrap();

      window.sessionStorage.setItem(PLAY_ID_KEY, playIdValue);
      setSubmitted(true);
      window.setTimeout(
        () => navigate("/history", { replace: true, state: { historyTab: "withdrawal" } }),
        800
      );
    } catch (err) {
      setError(getApiErrorMessage(err, t("common.unexpected")));
    }
  };

  return (
    <AppShell title={t("withdraw.title")} subtitle={t("withdraw.subtitle")}>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Card>
          <CardTitle>{t("withdraw.payoutAccount")}</CardTitle>

          <div className="mb-4 grid grid-cols-3 gap-2">
            {Array.from({ length: SLOT_COUNT }, (_, index) => (
              <button
                key={index}
                type="button"
                onClick={() => setActiveIndex(index)}
                className={[
                  "h-9 truncate rounded-full border px-2 text-[12px] font-semibold transition-colors",
                  activeIndex === index
                    ? "border-transparent bg-accent text-on-accent"
                    : "border-border bg-surface-2 text-muted",
                ].join(" ")}
              >
                {t("withdraw.slot", { number: index + 1 })}
              </button>
            ))}
          </div>

          <div className="space-y-4">
            <Input
              label={t("withdraw.upi")}
              value={current.upi_id}
              onChange={(event) => setField("upi_id", event.target.value)}
              placeholder="name@bank"
              hint={t("withdraw.upiHint")}
            />

            <div className="border-t border-border pt-4">
              <p className="mb-3 text-xs font-medium text-muted">{t("withdraw.bankSection")}</p>
              <div className="space-y-4">
                <Input
                  label={t("withdraw.accountName")}
                  value={current.account_name}
                  onChange={(event) => setField("account_name", event.target.value)}
                />
                <Input
                  label={t("withdraw.accountNumber")}
                  inputMode="numeric"
                  value={current.account_number}
                  onChange={(event) =>
                    setField("account_number", event.target.value.replace(/\D/g, ""))
                  }
                />
                <Input
                  label={t("withdraw.ifsc")}
                  value={current.ifsc_code}
                  onChange={(event) => setField("ifsc_code", event.target.value)}
                  placeholder="HDFC0001234"
                  error={
                    current.ifsc_code.length > 0 && !ifscValid ? t("withdraw.ifscInvalid") : null
                  }
                />
              </div>
            </div>
          </div>
        </Card>

        <Card>
          <CardTitle>{t("withdraw.request")}</CardTitle>
          <div className="space-y-4">
            <Input
              label={t("withdraw.amount")}
              type="number"
              inputMode="numeric"
              min={minimumAmount}
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              placeholder={String(minimumAmount)}
              hint={t("withdraw.minimum", { amount: money(minimumAmount) })}
              error={belowMinimum ? t("withdraw.minimum", { amount: money(minimumAmount) }) : null}
              required
            />

            <Input
              label={t("withdraw.playId")}
              value={current.playId}
              readOnly={playIdLocked}
              onChange={(event) => setField("playId", event.target.value)}
              hint={playIdLocked ? t("withdraw.playIdLocked") : undefined}
              required
            />

            {error ? <ErrorNote>{error}</ErrorNote> : null}
            {submitted ? (
              <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
                {t("withdraw.submitted")}
              </p>
            ) : null}

            <Button type="submit" block size="lg" loading={isSubmitting} disabled={!canSubmit}>
              {t("withdraw.submit")}
            </Button>
          </div>
        </Card>
      </form>
    </AppShell>
  );
}
