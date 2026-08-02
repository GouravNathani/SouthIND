import { useEffect, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { WHATSAPP_LINK } from "@/config/env";
import LanguageSwitcher from "@/components/LanguageSwitcher";
import { BrandLockup, BrandMark } from "@/components/Brand";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconAlert } from "@/components/icons";
import { PLAY_ID_KEY } from "@/components/AuthStatusGate";
import { getAuthToken, getStoredUser, storeAuthToken, storeUser } from "@/utils/auth";
import {
  useCheckBranchMutation,
  useGetAppSettingsQuery,
  useGetGlobalSettingsQuery,
  useLoginMutation,
} from "@/services/api";
import { getApiErrorMessage } from "@/utils/apiError";
import { extractUserRecord, isStatusActive, isStatusBanned } from "@/utils/userRecord";
import { deriveWhatsAppLink } from "@/utils/appSettings";
import { useTheme } from "@/theme/ThemeProvider";
import { IconMoon, IconSun } from "@/components/icons";

const isNonEmptyString = (value: unknown): value is string =>
  typeof value === "string" && value.trim().length > 0;

const isAllSame = (s: string) => s.split("").every((ch) => ch === s[0]);

/** Ascending or descending run with wrap — 1234567890 and 0987654321 both count. */
const isSequential = (s: string) => {
  let asc = true;
  let desc = true;
  for (let i = 1; i < s.length; i++) {
    const prev = Number(s[i - 1]);
    const cur = Number(s[i]);
    if (!Number.isFinite(prev) || !Number.isFinite(cur)) return false;
    if (cur !== (prev + 1) % 10) asc = false;
    if (cur !== (prev + 9) % 10) desc = false;
  }
  return asc || desc;
};

const hasRepeatedRun = (s: string, runLen: number) => {
  for (let i = 0; i + runLen <= s.length; i++) {
    if (isAllSame(s.slice(i, i + runLen))) return true;
  }
  return false;
};

/** Junk numbers typed to get past the form — rejected before a request is spent. */
const isBlockedPhone = (s: string) => {
  if (s.length !== 10) return false;
  return isAllSame(s) || isSequential(s) || hasRepeatedRun(s, 9) || hasRepeatedRun(s, 8);
};

export default function LoginPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { mode, toggleMode } = useTheme();

  const [phone, setPhone] = useState("");
  const [userId, setUserId] = useState("");
  const [loginMode, setLoginMode] = useState<"phone" | "userId">("phone");
  const [branchCode, setBranchCode] = useState("");
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [requiresBranchCode, setRequiresBranchCode] = useState(false);

  const [login, { isLoading }] = useLoginMutation();
  const [checkBranch, { isLoading: checkingBranch }] = useCheckBranchMutation();
  const { data: appSettings } = useGetAppSettingsQuery();
  const { data: globalSettings } = useGetGlobalSettingsQuery();

  useEffect(() => {
    if (getStoredUser() && getAuthToken()) navigate("/dashboard", { replace: true });
  }, [navigate]);

  // A phone number can exist in more than one branch; when it does the backend
  // asks for a branch code, so the field only appears when it is actually needed.
  useEffect(() => {
    if (loginMode !== "phone" || phone.length !== 10) {
      setRequiresBranchCode(false);
      setBranchCode("");
      return;
    }

    let active = true;
    const timer = window.setTimeout(() => {
      checkBranch({ phone })
        .unwrap()
        .then((result) => {
          if (!active) return;
          const needsBranch = Boolean(result?.needs_branch_code);
          setRequiresBranchCode(needsBranch);
          if (!needsBranch) setBranchCode("");
        })
        .catch(() => {
          if (active) setRequiresBranchCode(false);
        });
    }, 300);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [checkBranch, loginMode, phone]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (isLoading) return;

    const trimmedPin = pin.trim();
    const trimmedBranchCode = branchCode.trim().toUpperCase();
    const sanitizedPhone = phone.replace(/[^0-9]/g, "");
    const trimmedUserId = userId.trim().toUpperCase();

    if (loginMode === "phone") {
      if (sanitizedPhone.length !== 10) return setError(t("login.invalidPhone"));
      if (isBlockedPhone(sanitizedPhone)) return setError(t("login.blockedPhone"));
      if (!/^\d{6}$/.test(trimmedPin)) return setError(t("login.pinError"));
      if (requiresBranchCode && !trimmedBranchCode) return setError(t("login.branchRequired"));
    } else {
      if (!trimmedUserId) return setError(t("login.invalidUserId"));
      if (!/^\d{6}$/.test(trimmedPin)) return setError(t("login.pinError"));
    }

    setError(null);

    try {
      const payload = await login(
        loginMode === "phone"
          ? {
              phone: sanitizedPhone,
              mpin: trimmedPin,
              ...(trimmedBranchCode ? { branch_code: trimmedBranchCode } : {}),
            }
          : { user_id: trimmedUserId, mpin: trimmedPin }
      ).unwrap();

      const userRecord = extractUserRecord(payload);
      if (isStatusBanned(userRecord["status"])) throw new Error(t("login.banned"));
      if (!isStatusActive(userRecord["status"])) throw new Error(t("login.inactive"));

      const tokenValue = isNonEmptyString(payload["token"]) ? payload["token"] : "";
      if (!tokenValue) throw new Error(t("login.tokenMissing"));

      const resolvedPhone =
        sanitizedPhone || (typeof userRecord["phone"] === "string" ? userRecord["phone"] : "");
      storeUser({ ...userRecord, phone: resolvedPhone });
      storeAuthToken(tokenValue);

      const playIdCandidate = userRecord["play_id"];
      const resolvedPlayId = isNonEmptyString(playIdCandidate) ? playIdCandidate.trim() : "";
      const playIdMatchesPhone =
        resolvedPlayId && sanitizedPhone && resolvedPlayId.replace(/\D/g, "") === sanitizedPhone;
      if (resolvedPlayId && !playIdMatchesPhone) {
        window.sessionStorage.setItem(PLAY_ID_KEY, resolvedPlayId);
      }

      navigate("/dashboard");
    } catch (err) {
      setError(getApiErrorMessage(err, t("login.failed")));
    }
  };

  // Support link precedence: global settings, then branch app settings, then env.
  const envWhatsappLink =
    WHATSAPP_LINK && !/^https?:\/\/wa\.me\/?$/i.test(WHATSAPP_LINK) ? WHATSAPP_LINK : undefined;
  const globalRaw =
    typeof globalSettings?.whatsapp_link === "string" ? globalSettings.whatsapp_link.trim() : "";
  const globalDigits = globalRaw.replace(/\D/g, "");
  const globalWhatsappLink =
    globalRaw && !/^https?:\/\/wa\.me\/?$/i.test(globalRaw)
      ? /^https?:\/\//i.test(globalRaw)
        ? globalRaw
        : globalDigits.length
          ? `https://wa.me/${globalDigits}`
          : undefined
      : undefined;

  const whatsappHref = globalWhatsappLink ?? deriveWhatsAppLink(appSettings) ?? envWhatsappLink;
  const whatsappHrefWithMessage = whatsappHref
    ? `${whatsappHref}${whatsappHref.includes("?") ? "&" : "?"}text=${encodeURIComponent(
        t("login.whatsappMessage")
      )}`
    : undefined;

  const showCredentialFields =
    loginMode === "phone" ? phone.length === 10 && !checkingBranch : userId.trim().length > 0;
  const canSubmit =
    showCredentialFields &&
    /^\d{6}$/.test(pin.trim()) &&
    (loginMode !== "phone" || !requiresBranchCode || branchCode.trim().length > 0);

  const chrome = (
    <div className="flex items-center gap-2">
      <LanguageSwitcher />
      <button
        type="button"
        onClick={toggleMode}
        aria-label={mode === "dark" ? "Switch to light mode" : "Switch to dark mode"}
        className="grid size-8 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted"
      >
        {mode === "dark" ? <IconSun /> : <IconMoon />}
      </button>
    </div>
  );

  if (globalSettings?.user_panel_maintenance_enabled) {
    return (
      <div className="flex min-h-dvh w-full items-center justify-center overflow-x-hidden px-4 py-10">
        <div className="w-full max-w-md min-w-0 rounded-xl border border-border bg-surface p-8 text-center shadow-lg">
          <div
            className="mx-auto mb-4 grid size-14 place-items-center rounded-full"
            style={{ background: "var(--warn)", color: "var(--text-on-accent)" }}
          >
            <IconAlert size={24} />
          </div>
          <h1 className="text-xl font-semibold">{t("login.maintenanceTitle")}</h1>
          <p className="mt-2 text-sm text-muted">{t("login.maintenance")}</p>
          <div className="mt-6 flex justify-center">{chrome}</div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-dvh w-full items-center justify-center overflow-x-hidden px-4 py-10">
      <div className="w-full max-w-md min-w-0">
        <div className="mb-5 flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <BrandMark size={40} />
            <span className="truncate text-sm font-bold tracking-[0.13em] uppercase">
              South<span className="text-accent">IND</span>
            </span>
          </div>
          {chrome}
        </div>

        <div className="mb-4 flex justify-center">
          <BrandLockup />
        </div>

        <div className="rounded-xl border border-border bg-surface p-6 shadow-lg sm:p-7">
          <h1 className="text-2xl font-semibold">{t("login.title")}</h1>
          <p className="mt-1 text-sm text-muted">{t("login.subtitle")}</p>

          <div className="mt-5 grid grid-cols-2 gap-1 rounded-full border border-border bg-surface-2 p-1">
            {(["phone", "userId"] as const).map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => {
                  setLoginMode(option);
                  setError(null);
                  setPin("");
                }}
                className={[
                  "h-9 truncate rounded-full text-[13px] font-semibold transition-colors",
                  loginMode === option ? "bg-accent text-on-accent" : "text-muted",
                ].join(" ")}
              >
                {option === "phone" ? t("login.usePhone") : t("login.useUserId")}
              </button>
            ))}
          </div>

          <form className="mt-5 space-y-4" onSubmit={handleSubmit}>
            {loginMode === "phone" ? (
              <Input
                label={t("login.phone")}
                type="tel"
                inputMode="numeric"
                autoComplete="tel"
                value={phone}
                maxLength={10}
                placeholder="9845127634"
                onChange={(event) => setPhone(event.target.value.replace(/[^0-9]/g, ""))}
                hint={checkingBranch && phone.length === 10 ? t("login.checkingBranch") : undefined}
                required
              />
            ) : (
              <Input
                label={t("login.userId")}
                autoComplete="username"
                value={userId}
                placeholder="SIND1024"
                onChange={(event) => setUserId(event.target.value.toUpperCase().trim())}
                required
              />
            )}

            {requiresBranchCode && showCredentialFields ? (
              <Input
                label={t("login.branchCode")}
                value={branchCode}
                placeholder="001"
                autoComplete="off"
                onChange={(event) => setBranchCode(event.target.value.toUpperCase().trim())}
                hint={t("login.branchHint")}
                required
              />
            ) : null}

            {showCredentialFields ? (
              <Input
                label={t("login.pin")}
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={6}
                autoComplete="current-password"
                value={pin}
                placeholder="••••••"
                onChange={(event) => setPin(event.target.value.replace(/[^0-9]/g, ""))}
                hint={t("login.pinHint")}
                required
              />
            ) : null}

            {error ? <ErrorNote>{error}</ErrorNote> : null}

            <Button type="submit" block size="lg" loading={isLoading} disabled={!canSubmit}>
              {isLoading ? t("login.verifying") : t("login.button")}
            </Button>
          </form>
        </div>

        {whatsappHrefWithMessage ? (
          <div className="mt-4 rounded-lg border border-dashed border-border px-5 py-4 text-center">
            <p className="text-xs font-semibold tracking-wide text-muted uppercase">
              {t("login.needNewId")}
            </p>
            <p className="mt-1.5 text-sm text-muted">{t("login.newUserBody")}</p>
            <a
              href={whatsappHrefWithMessage}
              target="_blank"
              rel="noreferrer noopener"
              className="mt-3 inline-flex h-10 items-center justify-center rounded-full bg-accent px-5 text-[13px] font-semibold text-on-accent"
            >
              {t("login.contactWhatsapp")}
            </a>
          </div>
        ) : null}
      </div>
    </div>
  );
}
