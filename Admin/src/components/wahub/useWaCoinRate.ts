import { useGetWaUsageQuery } from "@/services/api";
import { coinRate } from "@/components/wahub/waFormat";

/**
 * Coins one sent message costs, from `coins_per_message` on the usage call
 * (cached, so every screen quoting the price shares one request). Falls back
 * to the default while it loads or when the backend leaves it out.
 */
export const useWaCoinRate = (): number => {
  const { data } = useGetWaUsageQuery();
  return coinRate(data?.coins_per_message);
};
