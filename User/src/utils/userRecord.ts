const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === "object" && value !== null;

export const extractUserRecord = (payload: unknown): Record<string, unknown> => {
  if (!isRecord(payload)) {
    return {};
  }
  const userNode = payload["user"];
  if (isRecord(userNode)) {
    return userNode;
  }
  const dataNode = payload["data"];
  if (isRecord(dataNode)) {
    return dataNode;
  }
  return payload;
};

export const normalizeUserStatus = (value: unknown): string =>
  typeof value === "string" ? value.trim().toLowerCase() : "";

export const isStatusActive = (value: unknown): boolean =>
  normalizeUserStatus(value) === "active";

export const isStatusBanned = (value: unknown): boolean =>
  normalizeUserStatus(value) === "banned";
