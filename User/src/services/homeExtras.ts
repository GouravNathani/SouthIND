import { sindApi } from "./api";

/** The branch's running strip; null when the admin switched it off. */
export type HomeTicker = { text: string } | null;

/** The club the system put this user in; null below every club. */
export type HomeClub = { name: string; color: string; active_days: number } | null;

export type HomeExtras = { ticker: HomeTicker; club: HomeClub };

/**
 * Home-page extras (running strip + club badge), kept apart from api.ts.
 * Cheap and cached on the server; refreshed every few minutes while open.
 */
export const homeExtrasApi = sindApi.injectEndpoints({
  endpoints: (builder) => ({
    getHomeExtras: builder.query<HomeExtras, void>({
      query: () => "home/extras",
      transformResponse: (response: Partial<HomeExtras> | null): HomeExtras => ({
        ticker: response?.ticker?.text ? { text: response.ticker.text } : null,
        club: response?.club?.name ? response.club : null,
      }),
    }),
  }),
});

export const { useGetHomeExtrasQuery } = homeExtrasApi;
