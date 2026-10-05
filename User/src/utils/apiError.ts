import type { SerializedError } from "@reduxjs/toolkit";
import type { FetchBaseQueryError } from "@reduxjs/toolkit/query";

const isFetchError = (error: unknown): error is FetchBaseQueryError =>
  typeof error === "object" && error !== null && "status" in error;

export const getApiErrorMessage = (error: unknown, fallback = "Something went wrong.") => {
  if (isFetchError(error)) {
    const data = error.data as Record<string, unknown> | undefined;
    const dataMessage = data?.message;
    if (typeof dataMessage === "string" && dataMessage.trim().length) {
      return dataMessage;
    }
    if ("error" in error) {
      const baseError = error as { error?: string };
      if (typeof baseError.error === "string" && baseError.error.trim().length) {
        return baseError.error;
      }
    }
  }
  const serialized = error as SerializedError | undefined;
  if (serialized?.message && serialized.message.trim().length) {
    return serialized.message;
  }
  if (error instanceof Error && error.message.trim().length) {
    return error.message;
  }
  return fallback;
};

/** The API refused a support chat call because the super admin switched chat off. */
export const isSupportChatOffError = (error: unknown): boolean =>
  isFetchError(error) &&
  error.status === 403 &&
  (error.data as { code?: string } | undefined)?.code === "support_chat_disabled";
