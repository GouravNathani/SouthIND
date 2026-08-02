import { useCallback, useEffect, useState, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { AUTH_TOKEN_CHANGE_EVENT, clearAuthToken, getAuthToken } from "@/utils/auth";
import { useGetAuthMeQuery } from "@/services/api";
import { extractUserRecord, isStatusBanned } from "@/utils/userRecord";

const INACTIVITY_LOGOUT_MS = 60 * 60 * 1000;
const LAST_ACTIVITY_KEY = "sind_last_activity";
export const PLAY_ID_KEY = "sind_play_id";
const USER_KEY = "sind_user";

/**
 * Wraps the router. Owns three things no page should own itself: bouncing an
 * unauthenticated visitor back to login, logging out on a banned account or a
 * rejected token, and the one-hour inactivity logout (shared phones).
 */
export default function AuthStatusGate({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [token, setToken] = useState<string | null>(() => getAuthToken());

  const { data, isSuccess, isError } = useGetAuthMeQuery(undefined, { skip: !token });

  const handleLogout = useCallback(() => {
    clearAuthToken();
    window.sessionStorage.removeItem(USER_KEY);
    window.sessionStorage.removeItem(PLAY_ID_KEY);
    window.sessionStorage.removeItem(LAST_ACTIVITY_KEY);
    navigate("/", { replace: true });
  }, [navigate]);

  useEffect(() => {
    const refreshToken = () => setToken(getAuthToken());
    window.addEventListener(AUTH_TOKEN_CHANGE_EVENT, refreshToken);
    window.addEventListener("storage", refreshToken);
    return () => {
      window.removeEventListener(AUTH_TOKEN_CHANGE_EVENT, refreshToken);
      window.removeEventListener("storage", refreshToken);
    };
  }, []);

  useEffect(() => {
    if (token && isError) handleLogout();
  }, [handleLogout, isError, token]);

  useEffect(() => {
    if (!token) return;

    const scheduleLogout = (lastActiveMs: number) => {
      const remainingMs = INACTIVITY_LOGOUT_MS - (Date.now() - lastActiveMs);
      if (remainingMs <= 0) {
        handleLogout();
        return;
      }
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(handleLogout, remainingMs);
    };

    const recordActivity = () => {
      const now = Date.now();
      window.sessionStorage.setItem(LAST_ACTIVITY_KEY, String(now));
      scheduleLogout(now);
    };

    let timeoutId = window.setTimeout(handleLogout, INACTIVITY_LOGOUT_MS);
    const storedLast = Number(window.sessionStorage.getItem(LAST_ACTIVITY_KEY));
    if (Number.isFinite(storedLast) && storedLast > 0) {
      scheduleLogout(storedLast);
    } else {
      recordActivity();
    }

    const activityEvents = ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "click"] as const;
    activityEvents.forEach((eventName) => {
      window.addEventListener(eventName, recordActivity, { passive: true });
    });

    const handleStorage = (event: StorageEvent) => {
      if (event.key !== LAST_ACTIVITY_KEY || !event.newValue) return;
      const updated = Number(event.newValue);
      if (Number.isFinite(updated) && updated > 0) scheduleLogout(updated);
    };
    window.addEventListener("storage", handleStorage);

    return () => {
      window.clearTimeout(timeoutId);
      activityEvents.forEach((eventName) => {
        window.removeEventListener(eventName, recordActivity);
      });
      window.removeEventListener("storage", handleStorage);
    };
  }, [handleLogout, token]);

  useEffect(() => {
    if (token) return;
    if (location.pathname === "/" || location.pathname === "/login") return;
    navigate("/", { replace: true });
  }, [location.pathname, navigate, token]);

  useEffect(() => {
    if (!token || !isSuccess || !data) return;

    const userRecord = extractUserRecord(data);
    if (isStatusBanned(userRecord["status"])) {
      handleLogout();
      return;
    }

    window.sessionStorage.setItem(
      USER_KEY,
      JSON.stringify({ ...userRecord, lastCheckedAt: new Date().toISOString() })
    );

    // The play id is only worth caching when it is genuinely distinct from the
    // phone number — some branches issue one, others just reuse the phone.
    const playIdCandidate =
      typeof userRecord["play_id"] === "string" ? userRecord["play_id"].trim() : "";
    const userPhoneDigits =
      typeof userRecord["phone"] === "string" ? userRecord["phone"].replace(/\D/g, "") : "";
    if (playIdCandidate && playIdCandidate.replace(/\D/g, "") !== userPhoneDigits) {
      window.sessionStorage.setItem(PLAY_ID_KEY, playIdCandidate);
    }
  }, [data, handleLogout, isSuccess, token]);

  return <>{children}</>;
}
