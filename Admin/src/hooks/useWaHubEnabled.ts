import { api } from "@/services/api";

/**
 * Whether the super admin has WhatsApp Hub switched on. Read from the /me poll
 * AdminShell keeps (`features.wa_hub`), so it costs no extra request. Unlike
 * support chat the hub starts off, so it only counts as on once /me says so.
 */
export const useWaHubEnabled = (): boolean => {
  const { data } = api.endpoints.getCurrentUser.useQueryState(undefined);
  const features = data?.features as { wa_hub?: boolean } | undefined;
  return features?.wa_hub === true;
};
