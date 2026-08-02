import { useCallback, useEffect, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { useDispatch } from "react-redux";
import { api, useLazyGetCurrentUserQuery, useLoginMutation } from "@/services/api";
import type { AppDispatch } from "@/store";
import { resolveErrorMessage } from "@/utils/errors";
import Button from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconMoon, IconSun } from "@/components/icons";
import { useTheme } from "@/theme/ThemeProvider";

const TOKEN_KEY = "sind-super-token";

export default function LoginPage() {
  const navigate = useNavigate();
  const dispatch = useDispatch<AppDispatch>();
  const { mode, toggleMode } = useTheme();

  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  const [login, { isLoading: isLoggingIn }] = useLoginMutation();
  const [triggerVerify, verifyResult] = useLazyGetCurrentUserQuery();

  // The first screen after login shows queue counts and lists; warming them here
  // means the dashboard paints with data instead of six spinners.
  const prefetch = useCallback(() => {
    for (const endpoint of ["getBranches", "getDeposits", "getWithdrawals", "getUsers", "getAdmins"] as const) {
      dispatch(api.util.prefetch(endpoint, undefined, { force: true }));
    }
  }, [dispatch]);

  const verifyToken = useCallback(
    async (redirectOnSuccess = false) => {
      try {
        await triggerVerify().unwrap();
        if (redirectOnSuccess) navigate("/dashboard", { replace: true });
        return true;
      } catch (err) {
        sessionStorage.removeItem(TOKEN_KEY);
        if (redirectOnSuccess) {
          setError(resolveErrorMessage(err, "Session expired. Please log in again."));
        }
        return false;
      }
    },
    [navigate, triggerVerify]
  );

  useEffect(() => {
    if (!sessionStorage.getItem(TOKEN_KEY)) return;
    void verifyToken(true).then((ok) => {
      if (ok) prefetch();
    });
  }, [prefetch, verifyToken]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");

    try {
      const result = await login({ phone: phone.trim(), password }).unwrap();
      if (!result?.token) throw new Error("Login response did not include a token.");

      sessionStorage.setItem(TOKEN_KEY, result.token);
      const ok = await verifyToken();
      if (!ok) {
        setError("Could not verify the session. Please try again.");
        return;
      }
      prefetch();
      navigate("/dashboard", { replace: true });
    } catch (err) {
      setError(resolveErrorMessage(err, "Unable to sign in."));
    }
  };

  const busy = isLoggingIn || verifyResult.isFetching;

  return (
    <div className="flex min-h-dvh w-full items-center justify-center overflow-x-hidden px-4 py-10">
      <div className="w-full max-w-sm min-w-0">
        <div className="mb-5 flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <span
              className="grid size-10 shrink-0 place-items-center rounded-md bg-accent text-base font-extrabold text-on-accent"
              style={{ boxShadow: "var(--sh-glow)" }}
            >
              S
            </span>
            <span className="truncate text-sm font-bold tracking-[0.13em] uppercase">
              South<span className="text-accent">IND</span>
              <span className="ml-1.5 text-muted">Super</span>
            </span>
          </div>
          <button
            type="button"
            onClick={toggleMode}
            aria-label={mode === "dark" ? "Switch to light mode" : "Switch to dark mode"}
            className="grid size-9 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted"
          >
            {mode === "dark" ? <IconSun /> : <IconMoon />}
          </button>
        </div>

        <form
          onSubmit={handleSubmit}
          className="space-y-4 rounded-xl border border-border bg-surface p-6 shadow-lg"
        >
          <div>
            <h1 className="text-xl font-semibold">Sign in</h1>
            <p className="mt-1 text-sm text-muted">Full network access.</p>
          </div>

          <Input
            label="Phone"
            type="tel"
            inputMode="numeric"
            autoComplete="username"
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            required
          />
          <Input
            label="Password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
          />

          {error ? <ErrorNote>{error}</ErrorNote> : null}

          <Button type="submit" block size="lg" loading={busy} disabled={!phone.trim() || !password}>
            {busy ? "Signing in…" : "Sign in"}
          </Button>
        </form>
      </div>
    </div>
  );
}
