import type {
  BaseQueryApi,
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
} from "@reduxjs/toolkit/query";
import { createApi, fetchBaseQuery } from "@reduxjs/toolkit/query/react";
import { isSupportChatOffError } from "../utils/errors";
import { withInFlightDedup } from "./dedupeBaseQuery";

import type {
  AccountRecord,
  AppSettings,
  BannerRecord,
  Credentials,
  DepositRecord,
  SummaryTotals,
  PaginatedResponse,
  DepositQueryParams,
  SupportConversationSummary,
  SupportMessage,
  SupportThread,
  Tag,
  BonusCodeRecord,
  BonusRedemptionRecord,
  UserRecord,
  UsersQueryParams,
  UsersListResponse,
  WalletExpiryStatus,
  WithdrawQueryParams,
  WithdrawRecord,
  WhatsAppAccount,
  WhatsAppTemplate,
  WhatsAppConversationSummary,
  WhatsAppThread,
  WhatsAppMessage,
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
  ReferralOverview,
  ReferralSettings,
} from "../types/api";

const TOKEN_KEY = "sind-admin-token";
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
    "Tags",
    "SupportConversations",
    "SupportThread",
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
    login: builder.mutation<{ token: string }, Credentials>({
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
    registerPushSubscription: builder.mutation<
      { message: string; id?: number },
      {
        endpoint: string;
        keys: { p256dh: string; auth: string };
        content_encoding?: string;
      }
    >({
      query: (body) => ({
        url: "/push/subscribe",
        method: "POST",
        body,
      }),
    }),
    unregisterPushSubscription: builder.mutation<
      { message: string },
      { endpoint: string }
    >({
      query: (body) => ({
        url: "/push/subscribe",
        method: "DELETE",
        body,
      }),
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
      { id: number; body: FormData | Partial<AccountRecord> }
    >({
      query: ({ id, body }) => {
        // PHP only parses multipart bodies on POST, so an image replacement
        // goes out as POST and Laravel routes it as PATCH via `_method`.
        if (body instanceof FormData) {
          body.set("_method", "PATCH");
          return { url: `/accounts/${id}`, method: "POST", body };
        }
        return { url: `/accounts/${id}`, method: "PATCH", body };
      },
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
    getDeposits: builder.query<DepositRecord[], void>({
      query: () => "/deposits",
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
    getDepositSummary: builder.query<SummaryTotals, DepositQueryParams | void>({
      query: (params) => {
        const queryParams: Record<string, string | number> = {};
        if (params?.page) queryParams.page = params.page;
        if (params?.per_page) queryParams.per_page = params.per_page;
        if (params?.search) queryParams.search = params.search;
        if (params?.status) queryParams.status = params.status;
        if (params?.start_date) queryParams.start_date = params.start_date;
        if (params?.end_date) queryParams.end_date = params.end_date;
        return {
          url: "/deposits",
          params: queryParams,
        };
      },
      transformResponse: (response: unknown) => extractSummary(response),
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
    getWithdrawals: builder.query<WithdrawRecord[], void>({
      query: () => "/withdrawals",
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
              ...withdrawals.map(({ id }) => ({ type: "Withdrawal" as const, id })),
              { type: "Withdrawals" as const, id: "LIST" },
            ]
          : [{ type: "Withdrawals" as const, id: "LIST" }];
      },
    }),
    getWithdrawalSummary: builder.query<SummaryTotals, WithdrawQueryParams | void>({
      query: (params) => {
        const queryParams: Record<string, string | number> = {};
        if (params?.page) queryParams.page = params.page;
        if (params?.per_page) queryParams.per_page = params.per_page;
        if (params?.search) queryParams.search = params.search;
        if (params?.status) queryParams.status = params.status;
        if (params?.start_date) queryParams.start_date = params.start_date;
        if (params?.end_date) queryParams.end_date = params.end_date;
        return {
          url: "/withdrawals",
          params: queryParams,
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
      { id?: number; body: Record<string, string | boolean> }
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
    getUsers: builder.query<
      UsersListResponse,
      UsersQueryParams | void
    >({
      query: (params) => {
        const queryParams: Record<string, string | number> = {};
        if (params?.page) queryParams.page = params.page;
        if (params?.per_page) queryParams.per_page = params.per_page;
        if (params?.search) queryParams.search = params.search;
        if (params?.status) queryParams.status = params.status;
        return {
          url: "/users",
          params: queryParams,
        };
      },
      transformResponse: (
        response: UsersListResponse | UserRecord[],
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
    updateUserStatus: builder.mutation<
      UserRecord,
      { id: number; status: string }
    >({
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
    resetUserMpin: builder.mutation<
      Record<string, unknown>,
      { id: number }
    >({
      query: ({ id }) => ({
        url: `/users/${id}/mpin`,
        method: "POST",
      }),
      invalidatesTags: (_result, _error, { id }) => [
        { type: "User", id },
        { type: "Users", id: "LIST" },
      ],
    }),
    getTags: builder.query<Tag[], void>({
      query: () => "/tags",
      transformResponse: (response: { data?: Tag[] } | Tag[]) =>
        unwrapData<Tag[]>(response),
      providesTags: [{ type: "Tags", id: "LIST" }],
    }),
    createTag: builder.mutation<Tag, { name: string; color: string }>({
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
        { type: "SupportConversations", id: "LIST" },
        "SupportThread",
      ],
    }),
    getWinnerStreak: builder.query<WinnerStreakOverview, void>({
      query: () => "/winner-streak",
      transformResponse: (response: { data?: WinnerStreakOverview } | WinnerStreakOverview) =>
        unwrapData<WinnerStreakOverview>(response),
      providesTags: [{ type: "WinnerStreak", id: "LIST" }],
    }),
    getWinnerStreakPeriod: builder.query<WinnerStreakPeriodPayload, WinnerStreakPeriod>({
      query: (period) => `/winner-streak/${period}`,
      transformResponse: (
        response: { data?: WinnerStreakPeriodPayload } | WinnerStreakPeriodPayload,
      ) => unwrapData<WinnerStreakPeriodPayload>(response),
      providesTags: (_r, _e, period) => [{ type: "WinnerStreak", id: period }],
    }),
    updateWinnerStreakSettings: builder.mutation<
      WinnerStreakSettings,
      { period: WinnerStreakPeriod; body: Partial<WinnerStreakSettings> }
    >({
      query: ({ period, body }) => ({
        url: `/winner-streak/${period}`,
        method: "PUT",
        body,
      }),
      transformResponse: (response: { data?: WinnerStreakSettings } | WinnerStreakSettings) =>
        unwrapData<WinnerStreakSettings>(response),
      invalidatesTags: [{ type: "WinnerStreak", id: "LIST" }],
    }),
    getWinnerStreakHistory: builder.query<
      WinnerStreakHistoryCycle[],
      { period: WinnerStreakPeriod; limit?: number }
    >({
      query: ({ period, limit }) => ({
        url: `/winner-streak/${period}/history`,
        params: limit ? { limit } : undefined,
      }),
      transformResponse: (
        response: { data?: WinnerStreakHistoryCycle[] } | WinnerStreakHistoryCycle[],
      ) => unwrapData<WinnerStreakHistoryCycle[]>(response),
      providesTags: (_r, _e, { period }) => [{ type: "WinnerStreakHistory", id: period }],
    }),
    resetWinnerStreak: builder.mutation<{ id: number }, WinnerStreakPeriod>({
      query: (period) => ({
        url: `/winner-streak/${period}/reset`,
        method: "POST",
      }),
      transformResponse: (response: { data?: { id: number } } | { id: number }) =>
        unwrapData<{ id: number }>(response),
      invalidatesTags: (_r, _e, period) => [
        { type: "WinnerStreak", id: "LIST" },
        { type: "WinnerStreakHistory", id: period },
      ],
    }),
    recalculateWinnerStreakCycle: builder.mutation<
      { added: number; updated: number; kept_paid: number; removed: number },
      { cycleId: number; period: WinnerStreakPeriod }
    >({
      query: ({ cycleId }) => ({
        url: `/winner-streak/cycles/${cycleId}/recalculate`,
        method: "POST",
      }),
      transformResponse: (response: { data?: never } | never) => unwrapData(response),
      invalidatesTags: (_r, _e, { period }) => [
        { type: "WinnerStreakHistory", id: period },
        { type: "WinnerStreak", id: "LIST" },
      ],
    }),
    updateWinnerStreakEntry: builder.mutation<
      WinnerStreakEntry,
      { id: number; period: WinnerStreakPeriod; reward_status?: string; notes?: string | null }
    >({
      query: ({ id, reward_status, notes }) => ({
        url: `/winner-streak/entries/${id}`,
        method: "PATCH",
        body: { reward_status, notes },
      }),
      transformResponse: (response: { data?: WinnerStreakEntry } | WinnerStreakEntry) =>
        unwrapData<WinnerStreakEntry>(response),
      invalidatesTags: (_r, _e, { period }) => [{ type: "WinnerStreakHistory", id: period }],
    }),
    getWhatsAppAccounts: builder.query<WhatsAppAccount[], void>({
      query: () => "/whatsapp/accounts",
      transformResponse: (response: { data?: WhatsAppAccount[] } | WhatsAppAccount[]) =>
        unwrapData<WhatsAppAccount[]>(response),
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
    getBonusCodes: builder.query<BonusCodeRecord[], { search?: string; status?: string } | void>({
      query: (params) => ({
        url: "/bonus-codes",
        params: {
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.status ? { status: params.status } : {}),
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
    generateBonusCode: builder.mutation<string[], { length?: number; prefix?: string; count?: number } | void>({
      query: (body) => ({ url: "/bonus-codes/generate", method: "POST", body: body ?? {} }),
      transformResponse: (response: { data?: string[] } | string[]) => unwrapData<string[]>(response),
    }),
    getBonusRedemptions: builder.query<
      BonusRedemptionRecord[],
      { status?: string; search?: string; bonus_code_id?: number } | void
    >({
      query: (params) => ({
        url: "/bonus-codes/redemptions",
        params: {
          ...(params?.status ? { status: params.status } : {}),
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.bonus_code_id ? { bonus_code_id: params.bonus_code_id } : {}),
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
    getSupportConversations: builder.query<
      SupportConversationSummary[],
      { status?: string; search?: string; tag_id?: number } | void
    >({
      query: (params) => ({
        url: "/support/conversations",
        params: {
          ...(params?.status ? { status: params.status } : {}),
          ...(params?.search ? { search: params.search } : {}),
          ...(params?.tag_id ? { tag_id: params.tag_id } : {}),
        },
      }),
      transformResponse: (
        response: { data?: SupportConversationSummary[] } | SupportConversationSummary[],
      ) => unwrapData<SupportConversationSummary[]>(response),
      onQueryStarted: (_arg, { dispatch, queryFulfilled }) =>
        markSupportChatOffOnRefusal(queryFulfilled, dispatch),
      providesTags: [{ type: "SupportConversations", id: "LIST" }],
    }),
    getSupportThread: builder.query<SupportThread, number>({
      query: (id) => `/support/conversations/${id}`,
      transformResponse: (response: { data?: SupportThread } | SupportThread) =>
        unwrapData<SupportThread>(response),
      onQueryStarted: (_arg, { dispatch, queryFulfilled }) =>
        markSupportChatOffOnRefusal(queryFulfilled, dispatch),
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
      onQueryStarted: (_arg, { dispatch, queryFulfilled }) =>
        markSupportChatOffOnRefusal(queryFulfilled, dispatch),
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
    getReferralOverview: builder.query<ReferralOverview, void>({
      query: () => "/referral",
      transformResponse: (response: { data?: ReferralOverview } | ReferralOverview) =>
        unwrapData<ReferralOverview>(response),
      providesTags: [{ type: "Referral", id: "OVERVIEW" }],
    }),
    updateReferralSettings: builder.mutation<ReferralSettings, Partial<ReferralSettings>>({
      query: (body) => ({ url: "/referral/settings", method: "PUT", body }),
      transformResponse: (response: { data?: ReferralSettings } | ReferralSettings) =>
        unwrapData<ReferralSettings>(response),
      invalidatesTags: [{ type: "Referral", id: "OVERVIEW" }],
    }),
    getReferralAgents: builder.query<
      Paginated<ReferralAgent>,
      { search?: string; agent_status?: string; sort?: string; page?: number; per_page?: number } | void
    >({
      query: (params) => ({ url: "/referral/agents", params: params ?? undefined }),
      providesTags: [{ type: "ReferralAgents", id: "LIST" }],
    }),
    getReferralAgent: builder.query<ReferralAgentDetail, number>({
      query: (id) => `/referral/agents/${id}`,
      transformResponse: (response: { data?: ReferralAgentDetail } | ReferralAgentDetail) =>
        unwrapData<ReferralAgentDetail>(response),
      providesTags: (_r, _e, id) => [{ type: "ReferralAgents", id }],
    }),
    updateReferralAgent: builder.mutation<
      ReferralAgent,
      {
        id: number;
        user_type?: "user" | "agent";
        agent_status?: "active" | "suspended";
        commission_percent_override?: number | null;
        reason?: string;
      }
    >({
      query: ({ id, ...body }) => ({ url: `/referral/agents/${id}`, method: "PATCH", body }),
      transformResponse: (response: { data?: ReferralAgent } | ReferralAgent) =>
        unwrapData<ReferralAgent>(response),
      invalidatesTags: (_r, _e, { id }) => [
        { type: "ReferralAgents", id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    regenerateReferralCode: builder.mutation<ReferralAgent, number>({
      query: (id) => ({ url: `/referral/agents/${id}/regenerate-code`, method: "POST" }),
      transformResponse: (response: { data?: ReferralAgent } | ReferralAgent) =>
        unwrapData<ReferralAgent>(response),
      invalidatesTags: (_r, _e, id) => [
        { type: "ReferralAgents", id },
        { type: "ReferralAgents", id: "LIST" },
      ],
    }),
    attachReferral: builder.mutation<
      unknown,
      {
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
      invalidatesTags: (_r, _e, { referrer_id }) => [
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
        ...(referrer_id ? [{ type: "ReferralAgents" as const, id: referrer_id }] : []),
      ],
    }),
    detachReferral: builder.mutation<unknown, { userId: number; reason?: string }>({
      query: ({ userId, reason }) => ({
        url: `/referral/users/${userId}/detach`,
        method: "POST",
        body: { reason },
      }),
      invalidatesTags: [
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    getCommissionEntries: builder.query<
      Paginated<CommissionEntry>,
      {
        agent_id?: number;
        type?: string;
        status?: string;
        flagged?: boolean;
        from?: string;
        to?: string;
        page?: number;
        per_page?: number;
      } | void
    >({
      query: (params) => ({ url: "/referral/entries", params: params ?? undefined }),
      providesTags: [{ type: "CommissionEntries", id: "LIST" }],
    }),
    adjustCommission: builder.mutation<
      CommissionEntry,
      { agent_id: number; amount: number; note: string; against_entry_id?: number }
    >({
      query: (body) => ({ url: "/referral/adjust", method: "POST", body }),
      transformResponse: (response: { data?: CommissionEntry } | CommissionEntry) =>
        unwrapData<CommissionEntry>(response),
      invalidatesTags: (_r, _e, { agent_id }) => [
        { type: "CommissionEntries", id: "LIST" },
        { type: "ReferralAgents", id: agent_id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    resolveCommissionEntry: builder.mutation<CommissionEntry, { id: number; note?: string }>({
      query: ({ id, note }) => ({
        url: `/referral/entries/${id}/resolve`,
        method: "POST",
        body: { note },
      }),
      transformResponse: (response: { data?: CommissionEntry } | CommissionEntry) =>
        unwrapData<CommissionEntry>(response),
      invalidatesTags: [
        { type: "CommissionEntries", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    getCommissionPayouts: builder.query<
      Paginated<CommissionPayout>,
      { status?: string; agent_id?: number; page?: number; per_page?: number } | void
    >({
      query: (params) => ({ url: "/referral/payouts", params: params ?? undefined }),
      providesTags: [{ type: "CommissionPayouts", id: "LIST" }],
    }),
    /** Pay an agent ahead of their balance — settles at once, can go negative. */
    advanceCommissionPayout: builder.mutation<
      CommissionPayout,
      {
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
      invalidatesTags: (_r, _e, { agent_id }) => [
        { type: "CommissionPayouts", id: "LIST" },
        { type: "CommissionEntries", id: "LIST" },
        { type: "ReferralAgents", id: agent_id },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    processCommissionPayout: builder.mutation<
      CommissionPayout,
      { id: number; status: "approved" | "rejected"; notes?: string }
    >({
      query: ({ id, ...body }) => ({ url: `/referral/payouts/${id}`, method: "PATCH", body }),
      transformResponse: (response: { data?: CommissionPayout } | CommissionPayout) =>
        unwrapData<CommissionPayout>(response),
      invalidatesTags: [
        { type: "CommissionPayouts", id: "LIST" },
        { type: "ReferralAgents", id: "LIST" },
        { type: "Referral", id: "OVERVIEW" },
      ],
    }),
    getReferralAudits: builder.query<
      Paginated<ReferralAudit>,
      { user_id?: number; action?: string; page?: number; per_page?: number } | void
    >({
      query: (params) => ({ url: "/referral/audits", params: params ?? undefined }),
      providesTags: [{ type: "ReferralAudits", id: "LIST" }],
    }),
  }),
});

/**
 * Once the super admin switches support chat off, the API answers chat calls
 * with 403 `support_chat_disabled`. Note that in the /me cache straight away so
 * every chat poller (sidebar badge, Support page) stops now instead of at the
 * next /me poll; that poll also switches it back on.
 */
function markSupportChatOffOnRefusal(
  queryFulfilled: Promise<unknown>,
  dispatch: BaseQueryApi["dispatch"],
): Promise<void> {
  return queryFulfilled.then(
    () => undefined,
    (result: { error?: unknown } | undefined) => {
      if (isSupportChatOffError(result?.error)) {
        dispatch(markSupportChatOff());
      }
    },
  );
}

function markSupportChatOff() {
  return api.util.updateQueryData("getCurrentUser", undefined, (draft) => {
    draft.features = {
      ...(draft.features as Record<string, unknown> | undefined),
      support_chat: false,
    };
  });
}

export const {
  useLoginMutation,
  useLogoutMutation,
  useGetCurrentUserQuery,
  useGetWalletStatusQuery,
  useLazyGetCurrentUserQuery,
  useRegisterPushSubscriptionMutation,
  useUnregisterPushSubscriptionMutation,
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
  useGetUserQuery,
  useCreateUserMutation,
  useUpdateUserMutation,
  useUpdateUserStatusMutation,
  useResetUserMpinMutation,
  useGetBonusCodesQuery,
  useCreateBonusCodeMutation,
  useUpdateBonusCodeMutation,
  useDeleteBonusCodeMutation,
  useGenerateBonusCodeMutation,
  useGetBonusRedemptionsQuery,
  useUpdateBonusRedemptionMutation,
  useGetUserBonusHistoryQuery,
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
  useGetWinnerStreakQuery,
  useGetWinnerStreakPeriodQuery,
  useUpdateWinnerStreakSettingsMutation,
  useGetWinnerStreakHistoryQuery,
  useResetWinnerStreakMutation,
  useRecalculateWinnerStreakCycleMutation,
  useUpdateWinnerStreakEntryMutation,
  useGetWhatsAppAccountsQuery,
  useGetWhatsAppTemplatesQuery,
  useGetWhatsAppConversationsQuery,
  useGetWhatsAppThreadQuery,
  useSendWhatsAppMessageMutation,
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
