import { useEffect, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { BrandLockup } from "@/components/Brand";
import Button from "@/components/ui/Button";
import { ErrorNote } from "@/components/ui/Feedback";
import { PLAY_ID_KEY } from "@/components/AuthStatusGate";
import { useSsoAddaMutation } from "@/services/api";
import { storeAuthToken, storeUser } from "@/utils/auth";
import { getApiErrorMessage } from "@/utils/apiError";
import { extractUserRecord, isStatusActive, isStatusBanned } from "@/utils/userRecord";

/**
 * Landing page for users coming from Adda: #/sso?t=<ticket>. The ticket is
 * single-use and lives in the URL fragment, so it never reaches a server log;
 * it is also wiped from the address bar before anything else happens.
 */
export default function SsoPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const [params] = useSearchParams();
  const [exchange] = useSsoAddaMutation();
  const [error, setError] = useState<string | null>(null);
  // A ticket works once; StrictMode's double effect must not spend it twice.
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const ticket = params.get("t") ?? "";
    window.history.replaceState(null, "", `${window.location.pathname}#/sso`);

    if (!ticket) {
      setError(t("sso.missing"));
      return;
    }

    (async () => {
      try {
        const payload = await exchange({ ticket }).unwrap();
        const userRecord = extractUserRecord(payload);
        if (isStatusBanned(userRecord["status"])) throw new Error(t("login.banned"));
        if (!isStatusActive(userRecord["status"])) throw new Error(t("login.inactive"));

        const token = typeof payload["token"] === "string" ? payload["token"] : "";
        if (!token) throw new Error(t("login.tokenMissing"));

        storeUser(userRecord);
        storeAuthToken(token);

        const playId = typeof userRecord["play_id"] === "string" ? userRecord["play_id"].trim() : "";
        const phoneDigits = typeof userRecord["phone"] === "string" ? userRecord["phone"].replace(/\D/g, "") : "";
        if (playId && playId.replace(/\D/g, "") !== phoneDigits) {
          window.sessionStorage.setItem(PLAY_ID_KEY, playId);
        }

        navigate("/dashboard", { replace: true });
      } catch (err) {
        setError(getApiErrorMessage(err, t("sso.failed")));
      }
    })();
  }, [exchange, navigate, params, t]);

  return (
    <div className="flex min-h-dvh w-full items-center justify-center overflow-x-hidden px-4 py-10">
      <div className="w-full max-w-md min-w-0">
        <div className="mb-4 flex justify-center">
          <BrandLockup />
        </div>
        <div className="rounded-xl border border-border bg-surface p-6 shadow-lg sm:p-7">
          {error ? (
            <div className="space-y-4">
              <h1 className="text-xl font-semibold">{t("sso.failedTitle")}</h1>
              <ErrorNote>{error}</ErrorNote>
              <Button block onClick={() => navigate("/", { replace: true })}>
                {t("sso.loginInstead")}
              </Button>
            </div>
          ) : (
            <div className="space-y-2 text-center">
              <h1 className="text-xl font-semibold">{t("sso.title")}</h1>
              <p className="text-sm text-muted">{t("sso.body")}</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
