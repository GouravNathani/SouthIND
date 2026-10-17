import { api } from "./api";
import type { BusinessDashboard } from "../types/business";

/** Super-only business dashboard; the backend caches it for 5 minutes. */
const businessApi = api.injectEndpoints({
  endpoints: (builder) => ({
    getBusinessDashboard: builder.query<BusinessDashboard, { branchId?: number | null } | void>({
      query: (args) => ({
        url: "/dashboard/business",
        params: args?.branchId ? { branch_id: args.branchId } : undefined,
      }),
      transformResponse: (response: { data: BusinessDashboard }) => response.data,
    }),
  }),
});

export const { useGetBusinessDashboardQuery } = businessApi;
