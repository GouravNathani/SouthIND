import { api } from "@/services/api";

/**
 * Whether the super admin has support chat switched on. Read from the /me
 * poll AdminShell keeps (`features.support_chat`), so it costs no extra
 * request. Until /me has answered, chat counts as on.
 */
export const useSupportChatEnabled = (): boolean => {
  const { data } = api.endpoints.getCurrentUser.useQueryState(undefined);
  const features = data?.features as { support_chat?: boolean } | undefined;
  return features?.support_chat !== false;
};
