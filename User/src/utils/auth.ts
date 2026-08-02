export const AUTH_TOKEN_KEY = "sind_token";
export const AUTH_TOKEN_CHANGE_EVENT = "sind-token-change";
export const AUTH_TOKEN_EXPIRES_KEY = "sind_token_expires";
export const USER_STORAGE_KEY = "sind_user";

const DAY_MS = 24 * 60 * 60 * 1000;

// Session storage, not local: the token dies with the tab. A shared phone is the
// normal case here, so a closed tab must not leave an authenticated session.
const emitTokenChange = () => {
  try {
    window.dispatchEvent(new Event(AUTH_TOKEN_CHANGE_EVENT));
  } catch {
    /* ignore on unsupported environments */
  }
};

export const getAuthToken = (): string | null => {
  try {
    const token = window.sessionStorage.getItem(AUTH_TOKEN_KEY);
    if (!token) return null;

    const exp = window.sessionStorage.getItem(AUTH_TOKEN_EXPIRES_KEY);
    if (exp) {
      const expTs = Number(exp);
      if (!Number.isNaN(expTs) && Date.now() > expTs) {
        clearAuthToken();
        return null;
      }
    }
    return token;
  } catch {
    return null;
  }
};

export const storeAuthToken = (token: string, ttlMs = DAY_MS) => {
  try {
    window.sessionStorage.setItem(AUTH_TOKEN_KEY, token);
    window.sessionStorage.setItem(AUTH_TOKEN_EXPIRES_KEY, String(Date.now() + ttlMs));
  } finally {
    emitTokenChange();
  }
};

export const clearAuthToken = () => {
  try {
    window.sessionStorage.removeItem(AUTH_TOKEN_KEY);
    window.sessionStorage.removeItem(AUTH_TOKEN_EXPIRES_KEY);
    window.sessionStorage.removeItem(USER_STORAGE_KEY);
  } finally {
    emitTokenChange();
  }
};

export const withAuthHeaders = (headers: Record<string, string> = {}) => {
  const token = getAuthToken();
  return token ? { ...headers, Authorization: `Bearer ${token}` } : headers;
};

export const getStoredUser = (): Record<string, unknown> | null => {
  try {
    const raw = window.sessionStorage.getItem(USER_STORAGE_KEY);
    if (!raw) return null;

    const parsed = JSON.parse(raw) as Record<string, unknown>;
    const lastLogin = parsed["lastLoginAt"];
    if (typeof lastLogin === "string") {
      const ts = Date.parse(lastLogin);
      if (!Number.isNaN(ts) && Date.now() - ts > DAY_MS) {
        window.sessionStorage.removeItem(USER_STORAGE_KEY);
        return null;
      }
    }
    return parsed;
  } catch {
    return null;
  }
};

export const storeUser = (user: Record<string, unknown>) => {
  try {
    window.sessionStorage.setItem(
      USER_STORAGE_KEY,
      JSON.stringify({ ...user, lastLoginAt: new Date().toISOString() })
    );
  } finally {
    emitTokenChange();
  }
};
