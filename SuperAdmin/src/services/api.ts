import type { BaseQueryFn, FetchArgs, FetchBaseQueryError } from "@reduxjs/toolkit/query";
import { createApi, fetchBaseQuery } from "@reduxjs/toolkit/query/react";
import { withInFlightDedup } from "./dedupeBaseQuery";

import type {
  AccountRecord,
  AdminRecord,
  AppSettings,
  BannerRecord,
  BranchRecord,
  Credentials,
  DepositRecord,
  DepositQueryParams,
  GlobalSettings,
  LoginResponse,
  PaginatedResponse,
  SummaryTotals,
  SupportConversationSummary,
  SupportMessage,
  SupportThread,
  Tag,
  BonusCodeRecord,
  BonusRedemptionRecord,
  UserRecord,
  UsersResponse,
  UserActivityPoint,
  UserAverageMetrics,
  WalletExpiryStatus,
  WalletResponse,
  WalletDailyResponse,
  MonthlyPayoutResponse,
  DailyPayoutResponse,
  WithdrawRecord,
  WithdrawQueryParams,
  WhatsAppAccount,
  WhatsAppTemplate,
  WhatsAppConversationSummary,
  WhatsAppThread,
  WhatsAppMessage,
  WinnerStreakBranchSummary,
  WinnerStreakEntry,
  WinnerStreakHistoryCycle,
  WinnerStreakOverview,
  WinnerStreakPeriod,
  WinnerStreakPeriodPayload,
  WinnerStreakSettings,
  CommissionEntry,
  CommissionPayout,
  Paginated,
  ReferralAgent,
  ReferralAgentDetail,
  ReferralAudit,
  ReferralBranchSummary,
  ReferralOverview,
  ReferralSettings,
} from "../types/api";

const TOKEN_KEY = "sind-super-token";
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "";
const XSRF_COOKIE_NAME = "XSRF-TOKEN";

const readCookie = (name: string) => {
  if (typeof document === "undefined") {
    return null;
  }
  const cookies = document.cookie ? document.cookie.split("; ") : [];
  for (const cookie of cookies) {
    const [key, ...rest] = cookie.split("=");
    if (key === name) {
      return rest.join("=");
    }
  }
  return null;
};

const resolveApiOrigin = (baseUrl: string) => {
  if (!baseUrl) {
    return "";
  }
  try {
    const parsed = new URL(baseUrl);
    return parsed.origin;
  } catch {
    const trimmed = baseUrl.replace(/\/+$/, "");
    const match = trimmed.match(/^(https?:\/\/[^/]+)(\/|$)/i);
    return match ? match[1] : trimmed;
  }
};

const unwrapData = <T>(payload: unknown): T => {
  if (payload && typeof payload === "object" && "data" in payload) {
    const container = payload as { data?: T };
    if (container.data !== undefined) {
      return container.data;
    }
  }
  return payload as T;
};

const extractSummary = (payload: unknown): SummaryTotals => {
  if (payload && typeof payload === "object" && "summary" in payload) {
    const container = payload as { summary?: SummaryTotals };
    if (container.summary) {
      return container.summary;
    }
  }
  return {
    count: 0,
    total: 0,
    approvedTotal: 0,
    rejectedTotal: 0,
    approvedCount: 0,
    rejectedCount: 0,
    avgProcessingSeconds: 0,
  };
};

type AdminUpdatePayload = Partial<AdminRecord> & {
  password?: string;
};

const rawBaseQuery = fetchBaseQuery({
  baseUrl: API_BASE_URL,
  credentials: "include",
  prepareHeaders: (headers) => {
    const token = sessionStorage.getItem(TOKEN_KEY);
    if (token) {
      headers.set("authorization", `Bearer ${token}`);
    }
    headers.set("x-requested-with", "XMLHttpRequest");
    const csrfToken = readCookie(XSRF_COOKIE_NAME);
    if (csrfToken) {
      headers.set("x-xsrf-token", decodeURIComponent(csrfToken));
    }
    return headers;
  },
});

const baseQueryWithCsrfRetry: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = async (args, api, extraOptions) => {
  let result = await rawBaseQuery(args, api, extraOptions);
  if (result.error?.status === 419) {
    const origin = resolveApiOrigin(API_BASE_URL);
    if (origin) {
      await rawBaseQuery(
        {
          url: `${origin}/sanctum/csrf-cookie`,
          method: "GET",
        },
        api,
        extraOptions,
      );
      result = await rawBaseQuery(args, api, extraOptions);
    }
  }
  return result;
};

export const api = createApi({
  reducerPath: "api",
  baseQuery: withInFlightDedup(baseQueryWithCsrfRetry),
  tagTypes: [
    "BonusCodes",
    "BonusRedemptions",
    "Me",
    "Accounts",
    "Account",
    "Deposits",
    "Deposit",
    "Withdrawals",
    "Withdrawal",
    "AppSettings",
    "Banners",
    "Users",
    "User",
    "Branches",
    "Branch",
    "Admins",
    "Admin",
    "GlobalSettings",
    "Tags",
    "SupportConversations",
    "SupportThread",
    "Wallet",
    "Payouts",
    "WhatsAppAccounts",
    "WhatsAppTemplates",
    "WhatsAppConversations",
    "WhatsAppThread",
    "WinnerStreak",
    "WinnerStreakHistory",
    "Referral",
    "ReferralAgents",
    "CommissionEntries",
    "CommissionPayouts",
    "ReferralAudits",
  ],
  endpoints: (builder) => ({
    login: builder.mutation<LoginResponse, Credentials>({
      query: (credentials) => ({
        url: "/login",
        method: "POST",
        body: credentials,
      }),
      invalidatesTags: ["Me"],
    }),
    logout: builder.mutation<{ message?: string }, void>({
      query: () => ({
        url: "/logout",
        method: "POST",
      }),
      invalidatesTags: ["Me"],
    }),
    getCurrentUser: builder.query<Record<string, unknown>, void>({
      query: () => "/me",
      providesTags: ["Me"],
    }),
    getWalletStatus: builder.query<WalletExpiryStatus, void>({
      query: () => "/wallet-status",
      transformResponse: (
        response: { data?: WalletExpiryStatus } | WalletExpiryStatus,
      ) => unwrapData<WalletExpiryStatus>(response),
    }),
    getMonthlyPayout: builder.query<MonthlyPayoutResponse, { year?: number } | void>({
      query: (args) => ({
        url: "/payout/monthly",
        params: args?.year ? { year: args.year } : undefined,
      }),
      providesTags: [{ type: "Payouts", id: "MONTHLY" }],
    }),
    getDailyPayout: builder.query<DailyPayoutResponse, { month: string }>({
      query: ({ month }) => ({
        url: "/payout/daily",
        params: { month },
      }),
      providesTags: [{ type: "Payouts", id: "DAILY" }],
    }),
    getWallet: builder.query<WalletResponse, void>({
      query: () => "/wallet",
      transformResponse: (response: { data?: WalletResponse } | WalletResponse) =>
        unwrapData<WalletResponse>(response),
      providesTags: ["Wallet"],
    }),
    // Day-wise deduction ledger: kept locally even when Control is unreachable.
    getWalletDaily: builder.query<WalletDailyResponse, { month: string }>({
      query: ({ month }) => ({ url: "/wallet/daily", params: { month } }),
      providesTags: [{ type: "Wallet", id: "DAILY" }],
    }),
    getAccounts: builder.query<AccountRecord[], void>({
      query: () => "/accounts",
      transformResponse: (response: { data?: AccountRecord[] } | AccountRecord[]) =>
        unwrapData<AccountRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Account" as const, id })),
              { type: "Accounts" as const, id: "LIST" },
            ]
          : [{ type: "Accounts" as const, id: "LIST" }],
    }),
    getAccount: builder.query<AccountRecord, number>({
      query: (id) => `/accounts/${id}`,
      transformResponse: (response: { data?: AccountRecord } | AccountRecord) =>
        unwrapData<AccountRecord>(response),
      providesTags: (_result, _error, id) => [{ type: "Account", id }],
    }),
    createAccount: builder.mutation<
      AccountRecord,
      FormData | Partial<AccountRecord>
    >({
      query: (body) => ({
        url: "/accounts",
        method: "POST",
        body,
      }),
      invalidatesTags: [{ type: "Accounts", id: "LIST" }],
    }),
    updateAccount: builder.mutation<
      AccountRecord,
      { id: number; body: Partial<AccountRecord> }
    >({
      query: ({ id, body }) => ({
        url: `/accounts/${id}`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "Account", id },
        { type: "Accounts", id: "LIST" },
      ],
    }),
    deleteAccount: builder.mutation<{ success: boolean }, number>({
      query: (id) => ({
        url: `/accounts/${id}`,
        method: "DELETE",
      }),
      invalidatesTags: (_result, _error, id) => [
        { type: "Account", id },
        { type: "Accounts", id: "LIST" },
      ],
    }),
    getDeposits: builder.query<
      DepositRecord[],
      | {
          branchId?: string | number;
          status?: string;
          search?: string;
          start_date?: string;
          end_date?: string;
        }
      | void
    >({
      query: (args) => {
        const params: Record<string, string | number> = {};
        if (args?.branchId) params.branch_id = args.branchId;
        if (args?.status) params.status = args.status;
        if (args?.search) params.search = args.search;
        if (args?.start_date) params.start_date = args.start_date;
        if (args?.end_date) params.end_date = args.end_date;
        return {
          url: "/deposits",
          params: Object.keys(params).length ? params : undefined,
        };
      },
      transformResponse: (response: { data?: DepositRecord[] } | DepositRecord[]) =>
        unwrapData<DepositRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Deposit" as const, id })),
              { type: "Deposits" as const, id: "LIST" },
            ]
          : [{ type: "Deposits" as const, id: "LIST" }],
    }),
    getDepositsPage: builder.query<
      PaginatedResponse<DepositRecord>,
      DepositQueryParams | void
    >({
      query: (params) => {
        const queryParams: Record<string, string | number> = {};
        if (params?.page) queryParams.page = params.page;
        if (params?.per_page) queryParams.per_page = params.per_page;
        if (params?.search) queryParams.search = params.search;
        if (params?.status) queryParams.status = params.status;
        if (params?.start_date) queryParams.start_date = params.start_date;
        if (params?.end_date) queryParams.end_date = params.end_date;
        if (params?.branchId) queryParams.branch_id = params.branchId;
        return {
          url: "/deposits",
          params: queryParams,
        };
      },
      transformResponse: (
        response: PaginatedResponse<DepositRecord> | DepositRecord[],
      ) => {
        if (Array.isArray(response)) {
          return { data: response };
        }
        return response;
      },
      providesTags: (result) => {
        const deposits = result?.data ?? [];
        return deposits.length
          ? [
              ...deposits.map(({ id }) => ({ type: "Deposit" as const, id })),
              { type: "Deposits" as const, id: "LIST" },
            ]
          : [{ type: "Deposits" as const, id: "LIST" }];
      },
    }),
    updateDepositStatus: builder.mutation<
      DepositRecord,
      { id: number; status: string; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/deposits/${id}/status`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "Deposit", id },
        { type: "Deposits", id: "LIST" },
      ],
    }),
    getDepositSummary: builder.query<SummaryTotals, DepositQueryParams | void>({
      query: (args) => {
        const params: Record<string, string | number> = {};
        if (args?.branchId) params.branch_id = args.branchId;
        if (args?.status) params.status = args.status;
        if (args?.search) params.search = args.search;
        if (args?.start_date) params.start_date = args.start_date;
        if (args?.end_date) params.end_date = args.end_date;
        return {
          url: "/deposits",
          params: Object.keys(params).length ? params : undefined,
        };
      },
      transformResponse: (response: unknown) => extractSummary(response),
    }),
    getWithdrawals: builder.query<
      WithdrawRecord[],
      | {
          branchId?: string | number;
          status?: string;
          search?: string;
          start_date?: string;
          end_date?: string;
        }
      | void
    >({
      query: (args) => {
        const params: Record<string, string | number> = {};
        if (args?.branchId) params.branch_id = args.branchId;
        if (args?.status) params.status = args.status;
        if (args?.search) params.search = args.search;
        if (args?.start_date) params.start_date = args.start_date;
        if (args?.end_date) params.end_date = args.end_date;
        return {
          url: "/withdrawals",
          params: Object.keys(params).length ? params : undefined,
        };
      },
      transformResponse: (
        response: { data?: WithdrawRecord[] } | WithdrawRecord[],
      ) => unwrapData<WithdrawRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Withdrawal" as const, id })),
              { type: "Withdrawals" as const, id: "LIST" },
            ]
          : [{ type: "Withdrawals" as const, id: "LIST" }],
    }),
    getWithdrawalsPage: builder.query<
      PaginatedResponse<WithdrawRecord>,
      WithdrawQueryParams | void
    >({
      query: (params) => {
        const queryParams: Record<string, string | number> = {};
        if (params?.page) queryParams.page = params.page;
        if (params?.per_page) queryParams.per_page = params.per_page;
        if (params?.search) queryParams.search = params.search;
        if (params?.status) queryParams.status = params.status;
        if (params?.start_date) queryParams.start_date = params.start_date;
        if (params?.end_date) queryParams.end_date = params.end_date;
        if (params?.branchId) queryParams.branch_id = params.branchId;
        return {
          url: "/withdrawals",
          params: queryParams,
        };
      },
      transformResponse: (
        response: PaginatedResponse<WithdrawRecord> | WithdrawRecord[],
      ) => {
        if (Array.isArray(response)) {
          return { data: response };
        }
        return response;
      },
      providesTags: (result) => {
        const withdrawals = result?.data ?? [];
        return withdrawals.length
          ? [
              ...withdrawals.map(({ id }) => ({
                type: "Withdrawal" as const,
                id,
              })),
              { type: "Withdrawals" as const, id: "LIST" },
            ]
          : [{ type: "Withdrawals" as const, id: "LIST" }];
      },
    }),
    getWithdrawalSummary: builder.query<
      SummaryTotals,
      WithdrawQueryParams | void
    >({
      query: (args) => {
        const params: Record<string, string | number> = {};
        if (args?.branchId) params.branch_id = args.branchId;
        if (args?.status) params.status = args.status;
        if (args?.search) params.search = args.search;
        if (args?.start_date) params.start_date = args.start_date;
        if (args?.end_date) params.end_date = args.end_date;
        return {
          url: "/withdrawals",
          params: Object.keys(params).length ? params : undefined,
        };
      },
      transformResponse: (response: unknown) => extractSummary(response),
    }),
    updateWithdrawalStatus: builder.mutation<
      WithdrawRecord,
      { id: number; status: string; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/withdrawals/${id}/status`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "Withdrawal", id },
        { type: "Withdrawals", id: "LIST" },
      ],
    }),
    getAppSettings: builder.query<AppSettings | null, void>({
      query: () => "/app-settings",
      transformResponse: (response: { data?: AppSettings } | AppSettings) => {
        const payload = unwrapData<AppSettings>(response);
        if (payload && typeof payload === "object") {
          return payload;
        }
        return null;
      },
      providesTags: ["AppSettings"],
    }),
    saveAppSettings: builder.mutation<
      AppSettings,
      { id?: number; body: Record<string, string> }
    >({
      query: ({ id, body }) => ({
        url: id ? `/app-settings/${id}` : "/app-settings",
        method: id ? "PATCH" : "POST",
        body,
      }),
      invalidatesTags: ["AppSettings"],
    }),
    getBanners: builder.query<BannerRecord[], void>({
      query: () => "/banners",
      transformResponse: (response: { data?: BannerRecord[] } | BannerRecord[]) =>
        unwrapData<BannerRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Banners" as const, id })),
              { type: "Banners" as const, id: "LIST" },
            ]
          : [{ type: "Banners" as const, id: "LIST" }],
    }),
    uploadBanners: builder.mutation<Record<string, unknown>, FormData>({
      query: (formData) => ({
        url: "/banners",
        method: "POST",
        body: formData,
      }),
      invalidatesTags: [{ type: "Banners", id: "LIST" }],
    }),
    deleteBanner: builder.mutation<{ success: boolean }, number>({
      query: (id) => ({
        url: `/banners/${id}`,
        method: "DELETE",
      }),
      invalidatesTags: (_result, _error, id) => [
        { type: "Banners", id },
        { type: "Banners", id: "LIST" },
      ],
    }),
    getUsers: builder.query<UserRecord[], { branchId?: string | number; search?: string } | void>({
      query: (args) => ({
        url: "/users",
        params: {
          ...(args?.branchId ? { branch_id: args.branchId } : {}),
          ...(args?.search ? { search: args.search } : {}),
        },
      }),
      transformResponse: (response: { data?: UserRecord[] } | UserRecord[]) =>
        unwrapData<UserRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "User" as const, id })),
              { type: "Users" as const, id: "LIST" },
            ]
          : [{ type: "Users" as const, id: "LIST" }],
    }),
    getUsersPage: builder.query<
      UsersResponse,
      | {
          page?: number;
          per_page?: number;
          branchId?: string | number;
          search?: string;
          sort_by?: string;
        }
      | void
    >({
      query: (args) => {
        const params: Record<string, string | number> = {};
        if (args?.page) params.page = args.page;
        if (args?.per_page) params.per_page = args.per_page;
        if (args?.branchId) params.branch_id = args.branchId;
        if (args?.search) params.search = args.search;
        if (args?.sort_by) params.sort_by = args.sort_by;
        return {
          url: "/users",
          params: Object.keys(params).length ? params : undefined,
        };
      },
      transformResponse: (
        response: UsersResponse | UserRecord[],
      ) => {
        if (Array.isArray(response)) {
          return { data: response };
        }
        return response;
      },
      providesTags: (result) => {
        const users = result?.data ?? [];
        return users.length
          ? [
              ...users.map(({ id }) => ({ type: "User" as const, id })),
              { type: "Users" as const, id: "LIST" },
            ]
          : [{ type: "Users" as const, id: "LIST" }];
      },
    }),
    createUser: builder.mutation<UserRecord, Record<string, string | number>>({
      query: (body) => ({
        url: "/users",
        method: "POST",
        body,
      }),
      invalidatesTags: [{ type: "Users", id: "LIST" }],
    }),
    getUser: builder.query<UserRecord, number>({
      query: (id) => `/users/${id}`,
      transformResponse: (response: { data?: UserRecord } | UserRecord) =>
        unwrapData<UserRecord>(response),
      providesTags: (_result, _error, id) => [{ type: "User", id }],
    }),
    updateUser: builder.mutation<UserRecord, { id: number; body: Record<string, string> }>(
      {
        query: ({ id, body }) => ({
          url: `/users/${id}`,
          method: "PATCH",
          body,
        }),
        invalidatesTags: (_result, _error, { id }) => [
          { type: "User", id },
          { type: "Users", id: "LIST" },
        ],
      },
    ),
    updateUserStatus: builder.mutation<UserRecord, { id: number; status: string }>({
      query: ({ id, status }) => ({
        url: `/users/${id}/status`,
        method: "PATCH",
        body: { status },
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "User", id },
        { type: "Users", id: "LIST" },
      ],
    }),
    resetUserMpin: builder.mutation<Record<string, unknown>, { id: number }>({
      query: ({ id }) => ({
        url: `/users/${id}/mpin`,
        method: "POST",
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "User", id },
        { type: "Users", id: "LIST" },
      ],
    }),
    moveUserBranch: builder.mutation<UserRecord, { id: number; branch_id: number }>({
      query: ({ id, ...body }) => ({
        url: `/users/${id}/branch`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "User", id },
        { type: "Users", id: "LIST" },
      ],
    }),
    getBonusCodes: builder.query<
      BonusCodeRecord[],
      { search?: string; status?: string; branch_id?: number; global_only?: boolean } | void
    >({
      query: (params) => ({
        url: "/bonus-codes",
        params: {
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.status ? { status: params.status } : {}),
          ...(params?.branch_id ? { branch_id: params.branch_id } : {}),
          ...(params?.global_only ? { global_only: 1 } : {}),
        },
      }),
      transformResponse: (response: { data?: BonusCodeRecord[] } | BonusCodeRecord[]) =>
        unwrapData<BonusCodeRecord[]>(response),
      providesTags: [{ type: "BonusCodes", id: "LIST" }],
    }),
    createBonusCode: builder.mutation<BonusCodeRecord, Record<string, unknown>>({
      query: (body) => ({ url: "/bonus-codes", method: "POST", body }),
      invalidatesTags: [{ type: "BonusCodes", id: "LIST" }],
    }),
    updateBonusCode: builder.mutation<
      BonusCodeRecord,
      { id: number } & Record<string, unknown>
    >({
      query: ({ id, ...body }) => ({ url: `/bonus-codes/${id}`, method: "PATCH", body }),
      invalidatesTags: [{ type: "BonusCodes", id: "LIST" }],
    }),
    deleteBonusCode: builder.mutation<{ message: string }, number>({
      query: (id) => ({ url: `/bonus-codes/${id}`, method: "DELETE" }),
      invalidatesTags: [{ type: "BonusCodes", id: "LIST" }],
    }),
    generateBonusCode: builder.mutation<
      string[],
      { length?: number; prefix?: string; count?: number } | void
    >({
      query: (body) => ({ url: "/bonus-codes/generate", method: "POST", body: body ?? {} }),
      transformResponse: (response: { data?: string[] } | string[]) => unwrapData<string[]>(response),
    }),
    getBonusRedemptions: builder.query<
      BonusRedemptionRecord[],
      { status?: string; search?: string; bonus_code_id?: number; branch_id?: number } | void
    >({
      query: (params) => ({
        url: "/bonus-codes/redemptions",
        params: {
          ...(params?.status ? { status: params.status } : {}),
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.bonus_code_id ? { bonus_code_id: params.bonus_code_id } : {}),
          ...(params?.branch_id ? { branch_id: params.branch_id } : {}),
        },
      }),
      transformResponse: (response: { data?: BonusRedemptionRecord[] } | BonusRedemptionRecord[]) =>
        unwrapData<BonusRedemptionRecord[]>(response),
      providesTags: [{ type: "BonusRedemptions", id: "LIST" }],
    }),
    updateBonusRedemption: builder.mutation<
      BonusRedemptionRecord,
      { id: number; status: string; notes?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/bonus-codes/redemptions/${id}`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: [
        { type: "BonusRedemptions", id: "LIST" },
        { type: "BonusCodes", id: "LIST" },
      ],
    }),
    getUserBonusHistory: builder.query<BonusRedemptionRecord[], number>({
      query: (userId) => `/bonus-codes/users/${userId}/redemptions`,
      transformResponse: (response: { data?: BonusRedemptionRecord[] } | BonusRedemptionRecord[]) =>
        unwrapData<BonusRedemptionRecord[]>(response),
      providesTags: [{ type: "BonusRedemptions", id: "USER" }],
    }),
    getBranches: builder.query<BranchRecord[], void>({
      query: () => "/branches",
      transformResponse: (response: { data?: BranchRecord[] } | BranchRecord[]) =>
        unwrapData<BranchRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Branch" as const, id })),
              { type: "Branches" as const, id: "LIST" },
            ]
          : [{ type: "Branches" as const, id: "LIST" }],
    }),
    createBranch: builder.mutation<
      BranchRecord,
      Record<string, string | number | undefined>
    >({
      query: (body) => ({
        url: "/branches",
        method: "POST",
        body,
      }),
      invalidatesTags: [{ type: "Branches", id: "LIST" }],
    }),
    updateBranch: builder.mutation<BranchRecord, { id: number; body: Partial<BranchRecord> }>({
      query: ({ id, body }) => ({
        url: `/branches/${id}`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "Branch", id },
        { type: "Branches", id: "LIST" },
      ],
    }),
    deleteBranch: builder.mutation<{ success: boolean }, number>({
      query: (id) => ({
        url: `/branches/${id}`,
        method: "DELETE",
      }),
      invalidatesTags: (_result, _error, id) => [
        { type: "Branch", id },
        { type: "Branches", id: "LIST" },
      ],
    }),
    getAdmins: builder.query<AdminRecord[], { branchId?: string | number } | void>({
      query: (args) => ({
        url: "/admins",
        params: args?.branchId ? { branch_id: args.branchId } : undefined,
      }),
      transformResponse: (response: { data?: AdminRecord[] } | AdminRecord[]) =>
        unwrapData<AdminRecord[]>(response),
      providesTags: (result) =>
        result
          ? [
              ...result.map(({ id }) => ({ type: "Admin" as const, id })),
              { type: "Admins" as const, id: "LIST" },
            ]
          : [{ type: "Admins" as const, id: "LIST" }],
    }),
    getAdmin: builder.query<AdminRecord, number>({
      query: (id) => `/admins/${id}`,
      transformResponse: (response: { data?: AdminRecord } | AdminRecord) =>
        unwrapData<AdminRecord>(response),
      providesTags: (_result, _error, id) => [{ type: "Admin", id }],
    }),
    createAdmin: builder.mutation<
      AdminRecord,
      Record<string, string | number | undefined>
    >({
      query: (body) => ({
        url: "/admins",
        method: "POST",
        body,
      }),
      invalidatesTags: [{ type: "Admins", id: "LIST" }],
    }),
    updateAdmin: builder.mutation<AdminRecord, { id: number; body: AdminUpdatePayload }>({
      query: ({ id, body }) => ({
        url: `/admins/${id}`,
        method: "PATCH",
        body,
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "Admin", id },
        { type: "Admins", id: "LIST" },
      ],
    }),
    updatePassword: builder.mutation<{ message: string }, { body: Record<string, string> }>({
      query: ({ body }) => ({
        url: "/password",
        method: "PATCH",
        body,
      }),
    }),
    deleteAdmin: builder.mutation<{ success: boolean }, number>({
      query: (id) => ({
        url: `/admins/${id}`,
        method: "DELETE",
      }),
      invalidatesTags: (_result, _error, id) => [
        { type: "Admin", id },
        { type: "Admins", id: "LIST" },
      ],
    }),
    getDashboardUserActivity: builder.query<
      UserActivityPoint[],
      { mode?: "30days" | "months"; branchId?: string | number } | void
    >({
      query: (args) => ({
        url: "/dashboard/user-activity",
        params: {
          ...(args?.mode ? { mode: args.mode } : {}),
          ...(args?.branchId ? { branch_id: args.branchId } : {}),
        },
      }),
      transformResponse: (
        response: { data?: UserActivityPoint[] } | UserActivityPoint[],
      ) => unwrapData<UserActivityPoint[]>(response),
    }),
    getDashboardUserAverages: builder.query<
      UserAverageMetrics,
      { start_date?: string; end_date?: string; branchId?: string | number } | void
    >({
      query: (args) => ({
        url: "/dashboard/user-averages",
        params: {
          ...(args?.start_date ? { start_date: args.start_date } : {}),
          ...(args?.end_date ? { end_date: args.end_date } : {}),
          ...(args?.branchId ? { branch_id: args.branchId } : {}),
        },
      }),
      transformResponse: (
        response: { data?: UserAverageMetrics } | UserAverageMetrics,
      ) => unwrapData<UserAverageMetrics>(response),
    }),
    getGlobalSettings: builder.query<GlobalSettings | null, void>({
      query: () => "/global-settings",
      transformResponse: (response: { data?: GlobalSettings } | GlobalSettings) => {
        const payload = unwrapData<GlobalSettings>(response);
        if (payload && typeof payload === "object") {
          return payload;
        }
        return null;
      },
      providesTags: ["GlobalSettings"],
    }),
    updateGlobalSettings: builder.mutation<
      GlobalSettings,
      { body: Record<string, string | boolean> }
    >({
      query: ({ body }) => ({
        url: "/global-settings",
        method: "PATCH",
        body,
      }),
      invalidatesTags: ["GlobalSettings"],
    }),
    // Winner Streak. Every call except the cross-branch summary carries a
    // branch_id — the owner always operates on one branch's board at a time.
    getWinnerStreakBranches: builder.query<WinnerStreakBranchSummary[], void>({
      query: () => "/winner-streak",
      transformResponse: (
        response: { data?: { branches?: WinnerStreakBranchSummary[] } } | unknown,
      ) => unwrapData<{ branches?: WinnerStreakBranchSummary[] }>(response)?.branches ?? [],
      providesTags: [{ type: "WinnerStreak", id: "BRANCHES" }],
    }),
    getWinnerStreak: builder.query<WinnerStreakOverview, number>({
      query: (branchId) => ({ url: "/winner-streak", params: { branch_id: branchId } }),
      transformResponse: (response: { data?: WinnerStreakOverview } | WinnerStreakOverview) =>
        unwrapData<WinnerStreakOverview>(response),
      providesTags: (_r, _e, branchId) => [{ type: "WinnerStreak", id: branchId }],
    }),
    getWinnerStreakPeriod: builder.query<
      WinnerStreakPeriodPayload,
      { period: WinnerStreakPeriod; branch_id: number }
    >({
      query: ({ period, branch_id }) => ({
        url: `/winner-streak/${period}`,
        params: { branch_id },
      }),
      transformResponse: (
        response: { data?: WinnerStreakPeriodPayload } | WinnerStreakPeriodPayload,
      ) => unwrapData<WinnerStreakPeriodPayload>(response),
    }),
    updateWinnerStreakSettings: builder.mutation<
      WinnerStreakSettings,
      { period: WinnerStreakPeriod; branch_id: number; body: Partial<WinnerStreakSettings> }
    >({
      query: ({ period, branch_id, body }) => ({
        url: `/winner-streak/${period}`,
        method: "PUT",
        body: { ...body, branch_id },
      }),
      transformResponse: (response: { data?: WinnerStreakSettings } | WinnerStreakSettings) =>
        unwrapData<WinnerStreakSettings>(response),
      invalidatesTags: (_r, _e, { branch_id }) => [
        { type: "WinnerStreak", id: branch_id },
        { type: "WinnerStreak", id: "BRANCHES" },
      ],
    }),
    getWinnerStreakHistory: builder.query<
      WinnerStreakHistoryCycle[],
      { period: WinnerStreakPeriod; branch_id: number; limit?: number }
    >({
      query: ({ period, branch_id, limit }) => ({
        url: `/winner-streak/${period}/history`,
        params: limit ? { branch_id, limit } : { branch_id },
      }),
      transformResponse: (
        response: { data?: WinnerStreakHistoryCycle[] } | WinnerStreakHistoryCycle[],
      ) => unwrapData<WinnerStreakHistoryCycle[]>(response),
      providesTags: (_r, _e, { period, branch_id }) => [
        { type: "WinnerStreakHistory", id: `${branch_id}-${period}` },
      ],
    }),
    resetWinnerStreak: builder.mutation<
      { id: number },
      { period: WinnerStreakPeriod; branch_id: number }
    >({
      query: ({ period, branch_id }) => ({
        url: `/winner-streak/${period}/reset`,
        method: "POST",
        body: { branch_id },
      }),
      transformResponse: (response: { data?: { id: number } } | { id: number }) =>
        unwrapData<{ id: number }>(response),
      invalidatesTags: (_r, _e, { period, branch_id }) => [
        { type: "WinnerStreak", id: branch_id },
        { type: "WinnerStreak", id: "BRANCHES" },
        { type: "WinnerStreakHistory", id: `${branch_id}-${period}` },
      ],
    }),
    recalculateWinnerStreakCycle: builder.mutation<
      { added: number; updated: number; kept_paid: number; removed: number },
      { cycleId: number; period: WinnerStreakPeriod; branch_id: number }
    >({
      query: ({ cycleId, branch_id }) => ({
        url: `/winner-streak/cycles/${cycleId}/recalculate`,
        method: "POST",
        body: { branch_id },
      }),
      transformResponse: (response: { data?: never } | never) => unwrapData(response),
      invalidatesTags: (_r, _e, { period, branch_id }) => [
        { type: "WinnerStreakHistory", id: `${branch_id}-${period}` },
        { type: "WinnerStreak", id: branch_id },
      ],
    }),
    updateWinnerStreakEntry: builder.mutation<
      WinnerStreakEntry,
      {
        id: number;
        period: WinnerStreakPeriod;
        branch_id: number;
        reward_status?: string;
        notes?: string | null;
      }
    >({
      query: ({ id, reward_status, notes }) => ({
        url: `/winner-streak/entries/${id}`,
        method: "PATCH",
        body: { reward_status, notes },
      }),
      transformResponse: (response: { data?: WinnerStreakEntry } | WinnerStreakEntry) =>
        unwrapData<WinnerStreakEntry>(response),
      invalidatesTags: (_r, _e, { period, branch_id }) => [
        { type: "WinnerStreakHistory", id: `${branch_id}-${period}` },
        { type: "WinnerStreak", id: "BRANCHES" },
      ],
    }),
    getTags: builder.query<Tag[], { branch_id?: number } | void>({
      query: (params) => ({
        url: "/tags",
        params: params?.branch_id ? { branch_id: params.branch_id } : {},
      }),
      transformResponse: (response: { data?: Tag[] } | Tag[]) =>
        unwrapData<Tag[]>(response),
      providesTags: [{ type: "Tags", id: "LIST" }],
    }),
    createTag: builder.mutation<
      Tag,
      { branch_id: number; name: string; color: string }
    >({
      query: (body) => ({
        url: "/tags",
        method: "POST",
        body,
      }),
      transformResponse: (response: { data?: Tag } | Tag) =>
        unwrapData<Tag>(response),
      invalidatesTags: [{ type: "Tags", id: "LIST" }],
    }),
    updateTag: builder.mutation<Tag, { id: number; name: string; color: string }>({
      query: ({ id, ...body }) => ({
        url: `/tags/${id}`,
        method: "PATCH",
        body,
      }),
      transformResponse: (response: { data?: Tag } | Tag) =>
        unwrapData<Tag>(response),
      invalidatesTags: [{ type: "Tags", id: "LIST" }],
    }),
    deleteTag: builder.mutation<{ id: number }, number>({
      query: (id) => ({
        url: `/tags/${id}`,
        method: "DELETE",
      }),
      transformResponse: (response: { data?: { id: number } } | { id: number }) =>
        unwrapData<{ id: number }>(response),
      invalidatesTags: [{ type: "Tags", id: "LIST" }],
    }),
    syncUserTags: builder.mutation<Tag[], { id: number; tag_ids: number[] }>({
      query: ({ id, tag_ids }) => ({
        url: `/users/${id}/tags`,
        method: "PUT",
        body: { tag_ids },
      }),
      transformResponse: (response: { data?: Tag[] } | Tag[]) =>
        unwrapData<Tag[]>(response),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "User", id },
        { type: "Tags", id: "LIST" },
      ],
    }),
    getWhatsAppAccounts: builder.query<WhatsAppAccount[], void>({
      query: () => "/whatsapp/accounts",
      transformResponse: (response: { data?: WhatsAppAccount[] } | WhatsAppAccount[]) =>
        unwrapData<WhatsAppAccount[]>(response),
      providesTags: [{ type: "WhatsAppAccounts", id: "LIST" }],
    }),
    createWhatsAppAccount: builder.mutation<WhatsAppAccount, Record<string, string>>({
      query: (body) => ({ url: "/whatsapp/accounts", method: "POST", body }),
      invalidatesTags: [{ type: "WhatsAppAccounts", id: "LIST" }],
    }),
    updateWhatsAppAccount: builder.mutation<
      WhatsAppAccount,
      { id: number; body: Record<string, string> }
    >({
      query: ({ id, body }) => ({ url: `/whatsapp/accounts/${id}`, method: "PATCH", body }),
      invalidatesTags: [{ type: "WhatsAppAccounts", id: "LIST" }],
    }),
    deleteWhatsAppAccount: builder.mutation<{ message: string }, number>({
      query: (id) => ({ url: `/whatsapp/accounts/${id}`, method: "DELETE" }),
      invalidatesTags: [{ type: "WhatsAppAccounts", id: "LIST" }],
    }),
    syncWhatsAppTemplates: builder.mutation<{ synced_count: number }, number>({
      query: (id) => ({ url: `/whatsapp/accounts/${id}/sync-templates`, method: "POST" }),
      invalidatesTags: [{ type: "WhatsAppTemplates", id: "LIST" }],
    }),
    getWhatsAppTemplates: builder.query<
      WhatsAppTemplate[],
      { whatsapp_account_id?: number } | void
    >({
      query: (args) => ({
        url: "/whatsapp/templates",
        params: args?.whatsapp_account_id ? { whatsapp_account_id: args.whatsapp_account_id } : undefined,
      }),
      transformResponse: (response: { data?: WhatsAppTemplate[] } | WhatsAppTemplate[]) =>
        unwrapData<WhatsAppTemplate[]>(response),
      providesTags: [{ type: "WhatsAppTemplates", id: "LIST" }],
    }),
    getWhatsAppConversations: builder.query<
      WhatsAppConversationSummary[],
      { whatsapp_account_id?: number; search?: string } | void
    >({
      query: (args) => ({
        url: "/whatsapp/conversations",
        params: {
          ...(args?.whatsapp_account_id ? { whatsapp_account_id: args.whatsapp_account_id } : {}),
          ...(args?.search ? { search: args.search } : {}),
        },
      }),
      transformResponse: (
        response: { data?: WhatsAppConversationSummary[] } | WhatsAppConversationSummary[],
      ) => unwrapData<WhatsAppConversationSummary[]>(response),
      providesTags: [{ type: "WhatsAppConversations", id: "LIST" }],
    }),
    getWhatsAppThread: builder.query<WhatsAppThread, number>({
      query: (id) => `/whatsapp/conversations/${id}`,
      transformResponse: (response: { data?: WhatsAppThread } | WhatsAppThread) =>
        unwrapData<WhatsAppThread>(response),
      providesTags: (_result, _error, id) => [{ type: "WhatsAppThread", id }],
    }),
    sendWhatsAppMessage: builder.mutation<
      WhatsAppMessage,
      {
        whatsapp_account_id: number;
        to: string;
        message?: string;
        template_name?: string;
        language?: string;
      }
    >({
      query: (body) => ({ url: "/whatsapp/send", method: "POST", body }),
      transformResponse: (response: { data?: WhatsAppMessage } | WhatsAppMessage) =>
        unwrapData<WhatsAppMessage>(response),
      invalidatesTags: [
        { type: "WhatsAppConversations", id: "LIST" },
        { type: "WhatsAppThread", id: "LIST" },
      ],
    }),
    getSupportConversations: builder.query<
      SupportConversationSummary[],
      { status?: string; search?: string; tag_id?: number; branch_id?: number | string } | void
    >({
      query: (params) => ({
        url: "/support/conversations",
        params: {
          ...(params?.status ? { status: params.status } : {}),
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.tag_id ? { tag_id: params.tag_id } : {}),
          ...(params?.branch_id ? { branch_id: params.branch_id } : {}),
        },
      }),
      transformResponse: (
        response: { data?: SupportConversationSummary[] } | SupportConversationSummary[],
      ) => unwrapData<SupportConversationSummary[]>(response),
      providesTags: [{ type: "SupportConversations", id: "LIST" }],
    }),
    getSupportThread: builder.query<SupportThread, number>({
      query: (id) => `/support/conversations/${id}`,
      transformResponse: (response: { data?: SupportThread } | SupportThread) =>
        unwrapData<SupportThread>(response),
      providesTags: (_result, _error, id) => [{ type: "SupportThread", id }],
    }),
    sendSupportMessage: builder.mutation<
      SupportMessage,
      { id: number; body?: string; image?: string; audio?: string }
    >({
      query: ({ id, ...body }) => ({
        url: `/support/conversations/${id}/messages`,
        method: "POST",
        body,
      }),
      transformResponse: (response: { data?: SupportMessage } | SupportMessage) =>
        unwrapData<SupportMessage>(response),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "SupportThread", id },
        { type: "SupportConversations", id: "LIST" },
      ],
    }),
    editSupportMessage: builder.mutation<
      SupportMessage,
      { conversationId: number; messageId: number; body: string }
    >({
      query: ({ conversationId, messageId, body }) => ({
        url: `/support/conversations/${conversationId}/messages/${messageId}`,
        method: "PATCH",
        body: { body },
      }),
      transformResponse: (response: { data?: SupportMessage } | SupportMessage) =>
        unwrapData<SupportMessage>(response),
      invalidatesTags: (_result, _error, { conversationId }) => [
        { type: "SupportThread", id: conversationId },
        { type: "SupportConversations", id: "LIST" },
      ],
    }),
    deleteSupportMessage: builder.mutation<
      SupportMessage,
      { conversationId: number; messageId: number }
    >({
      query: ({ conversationId, messageId }) => ({
        url: `/support/conversations/${conversationId}/messages/${messageId}`,
        method: "DELETE",
      }),
      transformResponse: (response: { data?: SupportMessage } | SupportMessage) =>
        unwrapData<SupportMessage>(response),
      invalidatesTags: (_result, _error, { conversationId }) => [
        { type: "SupportThread", id: conversationId },
        { type: "SupportConversations", id: "LIST" },
      ],
    }),
    updateSupportStatus: builder.mutation<
      { id: number; status: string },
      { id: number; status: string }
    >({
      query: ({ id, status }) => ({
        url: `/support/conversations/${id}/status`,
        method: "PATCH",
        body: { status },
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "SupportThread", id },
        { type: "SupportConversations", id: "LIST" },
      ],
    }),
    // ---- Referral & commission ------------------------------------------
    // Every call carries branch_id — the owner always operates on one branch's
    // programme at a time. Without one, index() is the cross-branch roll-up.
    getReferralBranches: builder.query<ReferralBranchSummary[], void>({
      query: () => "/referral",
      transformResponse: (response: { data?: { branches?: ReferralBranchSummary[] } } | unknown) =>
        unwrapData<{ branches?: ReferralBranchSummary[] }>(response)?.branches ?? [],
      providesTags: [{ type: "Referral", id: "BRANCHES" }],
    }),
    getReferralOverview: builder.query<ReferralOverview, number>({
      query: (branchId) => ({ url: "/referral", params: { branch_id: branchId } }),
      transformResponse: (response: { data?: ReferralOverview } | ReferralOverview) =>
        unwrapData<ReferralOverview>(response),
      providesTags: (_r, _e, branchId) => [{ type: "Referral", id: branchId }],
    }),
    updateReferralSettings: builder.mutation<
      ReferralSettings,
      { branch_id: number; body: Partial<ReferralSettings> }
    >({
      query: ({ branch_id, body }) => ({
        url: "/referral/settings",
        method: "PUT",
        body: { ...body, branch_id },
      }),
      transformResponse: (response: { data?: ReferralSettings } | ReferralSettings) =>
        unwrapData<ReferralSettings>(response),
      invalidatesTags: (_r, _e, { branch_id }) => [
        { type: "Referral", id: branch_id },
        { type: "Referral", id: "BRANCHES" },
        // The Branches page shows the same on/off switch — keep it in step.
        { type: "Branches", id: "LIST" },
      ],
    }),
    getReferralAgents: builder.query<
      Paginated<ReferralAgent>,
      {
        branch_id: number;
        search?: string;
        agent_status?: string;
        sort?: string;
        page?: number;
        per_page?: number;
      }
    >({
      query: (params) => ({ url: "/referral/agents", params }),
      providesTags: [{ type: "ReferralAgents", id: "LIST" }],
    }),
    getReferralAgent: builder.query<ReferralAgentDetail, { id: number; branch_id: number }>({
      query: ({ id, branch_id }) => ({ url: `/referral/agents/${id}`, params: { branch_id } }),
      transformResponse: (response: { data?: ReferralAgentDetail } | ReferralAgentDetail) =>
        unwrapData<ReferralAgentDetail>(response),
      providesTags: (_r, _e, { id }) => [{ type: "ReferralAgents", id }],
    }),
    updateReferralAgent: builder.mutation<
      ReferralAgent,
      {
        id: number;
        branch_id: number;
        user_type?: "user" | "agent";
        agent_status?: "active" | "suspended";
        commission_percent_override?: number | null;
        reason?: string;
      }
    >({
      query: ({ id, ...body }) => ({ url: `/referral/agents/${id}`, method: "PATCH", body }),
      transformResponse: (response: { data?: ReferralAgent } | ReferralAgent) =>
        unwrapData<ReferralAgent>(response),
      invalidatesTags: (_r, _e, { id, branch_id }) => [
        { type: "ReferralAgents", id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    regenerateReferralCode: builder.mutation<ReferralAgent, { id: number; branch_id: number }>({
      query: ({ id, branch_id }) => ({
        url: `/referral/agents/${id}/regenerate-code`,
        method: "POST",
        body: { branch_id },
      }),
      transformResponse: (response: { data?: ReferralAgent } | ReferralAgent) =>
        unwrapData<ReferralAgent>(response),
      invalidatesTags: (_r, _e, { id }) => [
        { type: "ReferralAgents", id },
        { type: "ReferralAgents", id: "LIST" },
      ],
    }),
    attachReferral: builder.mutation<
      unknown,
      {
        branch_id: number;
        user_id: number;
        referrer_id?: number;
        referral_code?: string;
        retroactive?: boolean;
        reason?: string;
      }
    >({
      query: (body) => ({ url: "/referral/attach", method: "POST", body }),
      // The agent drawer reads its team from the per-agent cache, so an attach
      // made from inside that drawer has to drop it too.
      invalidatesTags: (_r, _e, { branch_id, referrer_id }) => [
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
        ...(referrer_id ? [{ type: "ReferralAgents" as const, id: referrer_id }] : []),
      ],
    }),
    detachReferral: builder.mutation<
      unknown,
      { userId: number; branch_id: number; reason?: string }
    >({
      query: ({ userId, branch_id, reason }) => ({
        url: `/referral/users/${userId}/detach`,
        method: "POST",
        body: { branch_id, reason },
      }),
      invalidatesTags: (_r, _e, { branch_id }) => [
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    getCommissionEntries: builder.query<
      Paginated<CommissionEntry>,
      {
        branch_id: number;
        agent_id?: number;
        type?: string;
        status?: string;
        flagged?: boolean;
        from?: string;
        to?: string;
        page?: number;
        per_page?: number;
      }
    >({
      query: (params) => ({ url: "/referral/entries", params }),
      providesTags: [{ type: "CommissionEntries", id: "LIST" }],
    }),
    adjustCommission: builder.mutation<
      CommissionEntry,
      {
        branch_id: number;
        agent_id: number;
        amount: number;
        note: string;
        against_entry_id?: number;
      }
    >({
      query: (body) => ({ url: "/referral/adjust", method: "POST", body }),
      transformResponse: (response: { data?: CommissionEntry } | CommissionEntry) =>
        unwrapData<CommissionEntry>(response),
      invalidatesTags: (_r, _e, { agent_id, branch_id }) => [
        { type: "CommissionEntries", id: "LIST" },
        { type: "ReferralAgents", id: agent_id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    resolveCommissionEntry: builder.mutation<
      CommissionEntry,
      { id: number; branch_id: number; note?: string }
    >({
      query: ({ id, branch_id, note }) => ({
        url: `/referral/entries/${id}/resolve`,
        method: "POST",
        body: { branch_id, note },
      }),
      transformResponse: (response: { data?: CommissionEntry } | CommissionEntry) =>
        unwrapData<CommissionEntry>(response),
      invalidatesTags: (_r, _e, { branch_id }) => [
        { type: "CommissionEntries", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    getCommissionPayouts: builder.query<
      Paginated<CommissionPayout>,
      { branch_id: number; status?: string; agent_id?: number; page?: number; per_page?: number }
    >({
      query: (params) => ({ url: "/referral/payouts", params }),
      providesTags: [{ type: "CommissionPayouts", id: "LIST" }],
    }),
    /** Pay an agent ahead of their balance — settles at once, can go negative. */
    advanceCommissionPayout: builder.mutation<
      CommissionPayout,
      {
        branch_id: number;
        agent_id: number;
        amount: number;
        method: "upi" | "bank" | "play";
        notes: string;
        upi_id?: string;
        account_number?: string;
        ifsc_code?: string;
        account_name?: string;
      }
    >({
      query: (body) => ({ url: "/referral/payouts", method: "POST", body }),
      transformResponse: (response: { data?: CommissionPayout } | CommissionPayout) =>
        unwrapData<CommissionPayout>(response),
      invalidatesTags: (_r, _e, { agent_id, branch_id }) => [
        { type: "CommissionPayouts", id: "LIST" },
        { type: "CommissionEntries", id: "LIST" },
        { type: "ReferralAgents", id: agent_id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    processCommissionPayout: builder.mutation<
      CommissionPayout,
      { id: number; branch_id: number; status: "approved" | "rejected"; notes?: string }
    >({
      query: ({ id, ...body }) => ({ url: `/referral/payouts/${id}`, method: "PATCH", body }),
      transformResponse: (response: { data?: CommissionPayout } | CommissionPayout) =>
        unwrapData<CommissionPayout>(response),
      invalidatesTags: (_r, _e, { branch_id }) => [
        { type: "CommissionPayouts", id: "LIST" },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: branch_id },
      ],
    }),
    getReferralAudits: builder.query<
      Paginated<ReferralAudit>,
      { branch_id: number; user_id?: number; action?: string; page?: number; per_page?: number }
    >({
      query: (params) => ({ url: "/referral/audits", params }),
      providesTags: [{ type: "ReferralAudits", id: "LIST" }],
    }),
  }),
});

export const {
  useLoginMutation,
  useLogoutMutation,
  useGetCurrentUserQuery,
  useGetWalletStatusQuery,
  useGetMonthlyPayoutQuery,
  useGetDailyPayoutQuery,
  useGetWalletQuery,
  useGetWalletDailyQuery,
  useLazyGetCurrentUserQuery,
  useGetAccountsQuery,
  useGetAccountQuery,
  useCreateAccountMutation,
  useUpdateAccountMutation,
  useDeleteAccountMutation,
  useGetDepositsQuery,
  useGetDepositsPageQuery,
  useGetDepositSummaryQuery,
  useUpdateDepositStatusMutation,
  useGetWithdrawalsQuery,
  useGetWithdrawalsPageQuery,
  useGetWithdrawalSummaryQuery,
  useUpdateWithdrawalStatusMutation,
  useGetAppSettingsQuery,
  useSaveAppSettingsMutation,
  useGetBannersQuery,
  useUploadBannersMutation,
  useDeleteBannerMutation,
  useGetUsersQuery,
  useGetUsersPageQuery,
  useGetUserQuery,
  useCreateUserMutation,
  useUpdateUserMutation,
  useUpdateUserStatusMutation,
  useResetUserMpinMutation,
  useMoveUserBranchMutation,
  useGetBonusCodesQuery,
  useCreateBonusCodeMutation,
  useUpdateBonusCodeMutation,
  useDeleteBonusCodeMutation,
  useGenerateBonusCodeMutation,
  useGetBonusRedemptionsQuery,
  useUpdateBonusRedemptionMutation,
  useGetUserBonusHistoryQuery,
  useGetBranchesQuery,
  useCreateBranchMutation,
  useUpdateBranchMutation,
  useDeleteBranchMutation,
  useGetAdminsQuery,
  useGetAdminQuery,
  useCreateAdminMutation,
  useUpdateAdminMutation,
  useDeleteAdminMutation,
  useGetDashboardUserActivityQuery,
  useGetDashboardUserAveragesQuery,
  useGetGlobalSettingsQuery,
  useUpdateGlobalSettingsMutation,
  useUpdatePasswordMutation,
  useGetWinnerStreakBranchesQuery,
  useGetWinnerStreakQuery,
  useGetWinnerStreakPeriodQuery,
  useUpdateWinnerStreakSettingsMutation,
  useGetWinnerStreakHistoryQuery,
  useResetWinnerStreakMutation,
  useRecalculateWinnerStreakCycleMutation,
  useUpdateWinnerStreakEntryMutation,
  useGetTagsQuery,
  useCreateTagMutation,
  useUpdateTagMutation,
  useDeleteTagMutation,
  useSyncUserTagsMutation,
  useGetSupportConversationsQuery,
  useGetSupportThreadQuery,
  useSendSupportMessageMutation,
  useEditSupportMessageMutation,
  useDeleteSupportMessageMutation,
  useUpdateSupportStatusMutation,
  useGetWhatsAppAccountsQuery,
  useCreateWhatsAppAccountMutation,
  useUpdateWhatsAppAccountMutation,
  useDeleteWhatsAppAccountMutation,
  useSyncWhatsAppTemplatesMutation,
  useGetWhatsAppTemplatesQuery,
  useGetWhatsAppConversationsQuery,
  useGetWhatsAppThreadQuery,
  useSendWhatsAppMessageMutation,
  useGetReferralBranchesQuery,
  useGetReferralOverviewQuery,
  useUpdateReferralSettingsMutation,
  useGetReferralAgentsQuery,
  useGetReferralAgentQuery,
  useUpdateReferralAgentMutation,
  useRegenerateReferralCodeMutation,
  useAttachReferralMutation,
  useDetachReferralMutation,
  useGetCommissionEntriesQuery,
  useAdjustCommissionMutation,
  useResolveCommissionEntryMutation,
  useGetCommissionPayoutsQuery,
  useAdvanceCommissionPayoutMutation,
  useProcessCommissionPayoutMutation,
  useGetReferralAuditsQuery,
} = api;
