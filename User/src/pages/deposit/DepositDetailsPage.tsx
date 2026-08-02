import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import CopyRow from "@/components/CopyRow";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconClose, IconUpload } from "@/components/icons";
import {
  useCheckBonusCodeMutation,
  useCreateDepositMutation,
  useGetAppSettingsQuery,
  useGetDepositAccountsQuery,
  type BonusCodePreview,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { buildImageUrl } from "@/utils/media";
import { getStoredUser } from "@/utils/auth";
import { PLAY_ID_KEY } from "@/components/AuthStatusGate";
import { money, toNumber } from "@/utils/format";

const DEFAULT_MIN_DEPOSIT = 100;
const MAX_UTR_LENGTH = 22;

const readAsDataUrl = (file: File) =>
  new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });

export default function DepositDetailsPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();

  const accountIdParam = searchParams.get("accountId");
  const accountId = accountIdParam ? Number(accountIdParam) : null;

  const { data: appSettings } = useGetAppSettingsQuery();
  const { data: accounts = [], isLoading: accountsLoading, error: accountsError } =
    useGetDepositAccountsQuery();
  const [createDeposit, { isLoading: isSubmitting }] = useCreateDepositMutation();
  const [checkBonusCode, { isLoading: checkingBonus }] = useCheckBonusCodeMutation();

  const [amount, setAmount] = useState("");
  const [utr, setUtr] = useState("");
  const [playId, setPlayId] = useState("");
  const [playIdLocked, setPlayIdLocked] = useState(false);
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [proofPreview, setProofPreview] = useState<string | null>(null);
  const [bonusCode, setBonusCode] = useState("");
  const [bonusPreview, setBonusPreview] = useState<BonusCodePreview | null>(null);
  const [bonusError, setBonusError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const minimumAmount = useMemo(() => {
    const raw = appSettings?.min_deposit_amount;
    if (raw === null || raw === undefined || raw === "") return DEFAULT_MIN_DEPOSIT;
    const parsed = Number(raw);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : DEFAULT_MIN_DEPOSIT;
  }, [appSettings?.min_deposit_amount]);

  // The bonus box is admin-gated AND only appears once an amount exists, so a
  // code can never be validated against a blank amount.
  const showBonusInput = Boolean(appSettings?.bonus_deposit_enabled) && amount.trim() !== "";

  useEffect(() => {
    if (showBonusInput) return;
    setBonusCode("");
    setBonusPreview(null);
    setBonusError(null);
  }, [showBonusInput]);

  // A branch-assigned play id is locked; a remembered one is only a prefill.
  useEffect(() => {
    const storedUser = getStoredUser();
    const assignedPlayId =
      typeof storedUser?.play_id === "string" ? storedUser.play_id.trim() : "";
    const storedPhone =
      typeof storedUser?.phone === "string" ? storedUser.phone.replace(/\D/g, "") : "";
    const remembered = (window.sessionStorage.getItem(PLAY_ID_KEY) ?? "").trim();
    const rememberedIsPhone =
      Boolean(remembered && storedPhone) && remembered.replace(/\D/g, "") === storedPhone;

    if (assignedPlayId) {
      setPlayId(assignedPlayId);
      setPlayIdLocked(true);
    } else if (remembered && !rememberedIsPhone) {
      setPlayId(remembered);
    }
  }, []);

  const paramError = !accountIdParam
    ? t("deposit.missingAccount")
    : !Number.isFinite(accountId) || (accountId ?? 0) <= 0
      ? t("deposit.invalidAccount")
      : null;

  const accountError =
    paramError ?? (accountsError ? getApiErrorMessage(accountsError, t("deposit.accountsError")) : null);

  const selectedAccount = useMemo(() => {
    if (accountError || !Number.isFinite(accountId)) return null;
    return accounts.find((account) => account.id === accountId) ?? null;
  }, [accountError, accounts, accountId]);

  const isQr = selectedAccount?.type?.trim().toLowerCase() === "qr";
  const qrImageSrc = isQr
    ? (selectedAccount?.logo_url ?? buildImageUrl(selectedAccount?.logo_path))
    : null;

  const handleProofChange = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) {
      setProofFile(null);
      setProofPreview(null);
      return;
    }
    if (!file.type.startsWith("image/")) {
      setFormError(t("deposit.proofImageOnly"));
      event.target.value = "";
      return;
    }
    setFormError(null);
    setProofFile(file);
    void readAsDataUrl(file).then(setProofPreview);
  };

  const removeProof = () => {
    setProofFile(null);
    setProofPreview(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  /** Confirms the code against the entered amount, so a minimum-deposit rule
   *  never surfaces only after the deposit has been submitted. */
  const applyBonusCode = async () => {
    const trimmed = bonusCode.trim().toUpperCase();
    setBonusError(null);
    setBonusPreview(null);
    if (!trimmed) return;

    try {
      const preview = await checkBonusCode({
        code: trimmed,
        amount: toNumber(amount) || undefined,
      }).unwrap();
      setBonusCode(preview.code);
      setBonusPreview(preview);
    } catch (error) {
      setBonusError(getApiErrorMessage(error, t("deposit.bonusInvalid")));
    }
  };

  const amountValue = toNumber(amount);
  const belowMinimum = amountValue > 0 && amountValue < minimumAmount;
  const canSubmit =
    !isSubmitting &&
    Boolean(selectedAccount) &&
    amountValue >= minimumAmount &&
    playId.trim().length > 0 &&
    Boolean(proofFile);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedAccount) return;

    if (amountValue < minimumAmount) {
      setFormError(t("deposit.minimum", { amount: money(minimumAmount) }));
      return;
    }
    if (!proofFile) {
      setFormError(t("deposit.proofRequired"));
      return;
    }

    setFormError(null);
    try {
      const trimmedPlayId = playId.trim();
      await createDeposit({
        account_id: selectedAccount.id,
        amount: amountValue,
        utr_number: utr.trim() || undefined,
        play_id: trimmedPlayId,
        proof_image: proofPreview ?? (await readAsDataUrl(proofFile)),
        bonus_code: showBonusInput ? bonusPreview?.code : undefined,
      }).unwrap();

      window.sessionStorage.setItem(PLAY_ID_KEY, trimmedPlayId);
      setSubmitted(true);
      window.setTimeout(
        () => navigate("/history", { replace: true, state: { historyTab: "deposit" } }),
        800
      );
    } catch (error) {
      setFormError(getApiErrorMessage(error, t("common.unexpected")));
    }
  };

  if (accountsLoading && !accountError) {
    return (
      <AppShell title={t("deposit.title")}>
        <Skeleton className="h-64 w-full" />
      </AppShell>
    );
  }

  if (accountError || !selectedAccount) {
    return (
      <AppShell title={t("deposit.title")}>
        <Card>
          {accountError ? <ErrorNote>{accountError}</ErrorNote> : null}
          <EmptyState
            title={t("deposit.accountUnavailable")}
            action={
              <Button size="sm" variant="secondary" onClick={() => navigate("/deposit")}>
                {t("deposit.chooseAnother")}
              </Button>
            }
          />
        </Card>
      </AppShell>
    );
  }

  return (
    <AppShell title={t("deposit.title")} subtitle={selectedAccount.name}>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Card>
          <CardTitle hint={(selectedAccount.type ?? "").toUpperCase()}>
            {t("deposit.payTo")}
          </CardTitle>

          <CopyRow label={t("deposit.holder")} value={selectedAccount.holder_name} />
          <CopyRow label="UPI" value={selectedAccount.upi_id} />
          <CopyRow label="A/C" value={selectedAccount.account_number} />
          <CopyRow label="IFSC" value={selectedAccount.ifsc_code} />

          {qrImageSrc ? (
            <img
              src={qrImageSrc}
              alt={`${selectedAccount.name} QR`}
              className="mx-auto mt-4 size-52 max-w-full rounded-md border border-border object-contain"
            />
          ) : null}

          {selectedAccount.notes ? (
            <p className="mt-3 text-xs break-words text-faint">{selectedAccount.notes}</p>
          ) : null}
        </Card>

        <Card>
          <CardTitle>{t("deposit.yourDetails")}</CardTitle>
          <div className="space-y-4">
            <Input
              label={t("deposit.amount")}
              type="number"
              inputMode="numeric"
              min={minimumAmount}
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              placeholder={String(minimumAmount)}
              hint={t("deposit.minimum", { amount: money(minimumAmount) })}
              error={belowMinimum ? t("deposit.minimum", { amount: money(minimumAmount) }) : null}
              required
            />

            <Input
              label={t("deposit.playId")}
              value={playId}
              readOnly={playIdLocked}
              onChange={(event) => setPlayId(event.target.value)}
              hint={playIdLocked ? t("deposit.playIdLocked") : undefined}
              required
            />

            <Input
              label={t("deposit.utr")}
              inputMode="numeric"
              value={utr}
              onChange={(event) =>
                setUtr(event.target.value.replace(/\D/g, "").slice(0, MAX_UTR_LENGTH))
              }
              hint={t("deposit.utrHint")}
            />

            {showBonusInput ? (
              <div className="space-y-2">
                <div className="flex min-w-0 items-end gap-2">
                  <div className="min-w-0 flex-1">
                    <Input
                      label={t("deposit.bonusCode")}
                      value={bonusCode}
                      onChange={(event) => setBonusCode(event.target.value.toUpperCase())}
                      error={bonusError}
                    />
                  </div>
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={applyBonusCode}
                    loading={checkingBonus}
                    disabled={!bonusCode.trim()}
                  >
                    {t("deposit.apply")}
                  </Button>
                </div>
                {bonusPreview ? (
                  <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
                    {bonusPreview.reward_label ??
                      t("deposit.bonusApplied", { amount: money(bonusPreview.reward_amount) })}
                  </p>
                ) : null}
              </div>
            ) : null}

            <div>
              <p className="mb-1.5 text-xs font-medium text-muted">{t("deposit.proof")}</p>
              {proofPreview ? (
                <div className="relative w-fit max-w-full">
                  <img
                    src={proofPreview}
                    alt={t("deposit.proof")}
                    className="max-h-64 max-w-full rounded-md border border-border object-contain"
                  />
                  <button
                    type="button"
                    onClick={removeProof}
                    aria-label={t("common.remove")}
                    className="absolute top-2 right-2 grid size-8 place-items-center rounded-full border border-border bg-surface-solid text-muted"
                  >
                    <IconClose size={16} />
                  </button>
                </div>
              ) : (
                <label className="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-dashed border-border px-4 py-8 text-center">
                  <span className="text-muted">
                    <IconUpload size={22} />
                  </span>
                  <span className="text-sm font-medium text-text">{t("deposit.uploadProof")}</span>
                  <span className="text-xs text-faint">{t("deposit.uploadHint")}</span>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*"
                    className="sr-only"
                    onChange={handleProofChange}
                  />
                </label>
              )}
            </div>

            {formError ? <ErrorNote>{formError}</ErrorNote> : null}
            {submitted ? (
              <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">
                {t("deposit.submitted")}
              </p>
            ) : null}

            <Button type="submit" block size="lg" loading={isSubmitting} disabled={!canSubmit}>
              {t("deposit.submit")}
            </Button>
          </div>
        </Card>
      </form>
    </AppShell>
  );
}
