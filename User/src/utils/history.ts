import type { HistoryResponse } from "@/services/api";

export const mergeHistoryRecords = <T extends { id?: number | string }>(
  payload?: HistoryResponse<T> | null
): T[] => {
  if (!payload) return [];
  return [
    ...(Array.isArray(payload.data) ? payload.data : []),
    ...(Array.isArray(payload.pending) ? payload.pending : []),
    ...(Array.isArray(payload.recent_success) ? payload.recent_success : []),
    ...(Array.isArray(payload.recent_failed) ? payload.recent_failed : []),
    ...(Array.isArray(payload.recent_pending) ? payload.recent_pending : []),
  ];
};
