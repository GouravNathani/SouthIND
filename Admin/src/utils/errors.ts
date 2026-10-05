import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

type ErrorWithMessage = {
  message?: string;
  error?: string;
  data?: unknown;
};

const extractFromData = (data: unknown): string | undefined => {
  if (!data || typeof data !== "object") {
    return undefined;
  }
  const bucket = data as Record<string, unknown>;
  const message = bucket.message ?? bucket.error ?? bucket.detail;
  return typeof message === "string" ? message : undefined;
};

export const resolveErrorMessage = (
  error: unknown,
  fallback: string,
): string => {
  if (!error) {
    return fallback;
  }
  if (typeof error === "string") {
    return error;
  }
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === "object") {
    const typed = error as ErrorWithMessage & FetchBaseQueryError;
    if (typeof typed.message === "string") {
      return typed.message;
    }
    if (typeof typed.error === "string") {
      return typed.error;
    }
    if ("data" in typed) {
      const derived = extractFromData(typed.data);
      if (derived) {
        return derived;
      }
    }
  }
  return fallback;
};

export const isUnauthorizedError = (error: unknown): boolean => {
  if (!error || typeof error !== "object") {
    return false;
  }
  const typed = error as FetchBaseQueryError;
  return typed.status === 401;
};

/** The API refused a support chat call because the super admin switched chat off. */
export const isSupportChatOffError = (error: unknown): boolean => {
  if (!error || typeof error !== "object") {
    return false;
  }
  const typed = error as FetchBaseQueryError;
  return (
    typed.status === 403 &&
    (typed.data as { code?: string } | undefined)?.code === "support_chat_disabled"
  );
};
