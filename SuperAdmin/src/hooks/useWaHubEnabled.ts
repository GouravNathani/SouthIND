import { api } from "@/services/api";

/**
 * Whether WhatsApp Hub is switched on in Global settings. Read from the /me
 * query SuperShell keeps (`features.wa_hub`), so it costs no extra request. The
 * hub starts off, so it only counts as on once /me says so.
 */
export const useWaHubEnabled = (): boolean => {
  const { data } = api.endpoints.getCurrentUser.useQueryState(undefined);
  const features = data?.features as { wa_hub?: boolean } | undefined;
  return features?.wa_hub === true;
};
