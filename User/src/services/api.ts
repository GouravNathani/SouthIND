import { createApi, fetchBaseQuery } from "@reduxjs/toolkit/query/react";
import { withInFlightDedup } from "./dedupeBaseQuery";
import { API_BASE, BRANCH_CODE } from "@/config/env";
import { getAuthToken, getStoredUser } from "@/utils/auth";

export interface DepositAccount {
  id: number;
  name: string;
  holder_name: string;
  type: string;
  upi_id?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  used_for: string;
  logo_path?: string | null;
  logo_url?: string | null;
  notes?: string | null;
  is_active: boolean;
}

export interface DepositRecord {
  id: number;
  amount: string;
  status: string;
  destination_type?: string | null;
  upi_id?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  account_name?: string | null;
  notes?: string | null;
  processed_at?: string | null;
  created_at: string;
}

export interface WithdrawalRecord {
  id: number;
  amount: string;
  status: string;
  destination_type?: string | null;
  upi_id?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  account_name?: string | null;
  notes?: string | null;
  processed_at?: string | null;
  created_at: string;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
}

export type HistoryResponse<T> = {
  data: T[];
  meta: PaginationMeta | null;
  pending?: T[];
  recent_success?: T[];
  recent_failed?: T[];
  recent_pending?: T[];
};

export interface BannerRecord {
  id: number;
  image_path?: string | null;
  image_url?: string | null;
  is_active?: boolean;
}

export interface AppSettings {
  deposit_offer_text?: string | null;
  withdrawal_offer_text?: string | null;
  instagram_link?: string | null;
  whatsapp_link?: string | null;
  whatsapp_number?: string | null;
  telegram_link?: string | null;
  min_deposit_amount?: number | string | null;
  min_withdrawal_amount?: number | string | null;
  bonus_deposit_enabled?: boolean;
}

export interface GlobalSettings {
  instagram_link?: string | null;
  telegram_link?: string | null;
  whatsapp_link?: string | null;
  user_panel_maintenance_enabled?: boolean;
}

export interface LoginRequest {
  phone?: string;
  mpin: string;
  branch_code?: string;
  user_id?: string;
}

export interface LoginResponse extends Record<string, unknown> {
  token?: string;
  user?: Record<string, unknown>;
  data?: Record<string, unknown>;
  message?: string;
}

export interface BranchCheckResponse {
  needs_branch_code?: boolean;
  branches?: Array<{ id?: number; name?: string | null; code?: string | null }>;
}

export interface CreateDepositPayload {
  account_id: number;
  amount: number;
  utr_number?: string;
  play_id: string;
  notes?: string;
  proof_image: string;
  bonus_code?: string;
}

export interface BonusCodePreview {
  code: string;
  title?: string | null;
  reward_amount: number;
  reward_label?: string | null;
  terms_text?: string | null;
  requires_deposit: boolean;
  min_deposit: number;
}

export interface BonusRedemptionEntry {
  id: number;
  code: string;
  amount: number;
  reward_label?: string | null;
  status: "awaiting_deposit" | "pending" | "fulfilled" | "rejected";
  deposit_id?: number | null;
  redeemed_at?: string | null;
  created_at?: string;
}

export interface CreateWithdrawalPayload {
  amount: number;
  play_id: string;
  notes?: string;
  destination_type?: "upi" | "bank";
  account_number?: string;
  ifsc_code?: string;
  account_name?: string;
  upi_id?: string;
}

export interface SupportChatMessage {
  id: number;
  sender_type: "user" | "admin";
  body?: string | null;
  image_url?: string | null;
  audio_url?: string | null;
  created_at?: string | null;
  edited?: boolean;
}

export interface SupportChatData {
  conversation: { id: number; status: string };
  messages: SupportChatMessage[];
}

/**
 * Winner Streak. Every field here is optional because the branch admin chooses
 * which ones get published — a missing key means "not shown", not "unknown".
 */
export interface WinnerStreakWinner {
  rank: number;
  kind: "profit" | "loss";
  name?: string;
  play_id?: string;
  phone?: string | null;
  amount?: string;
  result?: "profit" | "loss";
  reward?: string;
  reward_label?: string | null;
}

export interface WinnerStreakCycleView {
  label?: string | null;
  closed_at?: string | null;
  winners: WinnerStreakWinner[];
  losers: WinnerStreakWinner[];
}

export interface WinnerStreakRibbonItem {
  period: "daily" | "weekly" | "monthly";
  title: string;
  rank?: number | null;
  name: string;
  play_id?: string | null;
  amount?: string | null;
}

export interface WinnerStreakFeed {
  periods: Partial<
    Record<
      "daily" | "weekly" | "monthly",
      { title: string; next_reset_at?: string | null; cycles: WinnerStreakCycleView[] }
    >
  >;
  ribbon: WinnerStreakRibbonItem[];
}

// ---- Referral / Commission Account -----------------------------------------
// The app calls this simply "Account" — the word "wallet" is reserved for the
// external message-billing wallet on the backend and never shown to a user.

export interface CommissionAccountSummary {
  available_balance: number;
  pending_balance: number;
  lifetime_earned: number;
  lifetime_paid: number;
  lifetime_adjusted: number;
  team_count: number;
  team_active_count: number;
  team_deposit_total: number;
  tier_label?: string | null;
  tier_percent?: number | null;
  status: "active" | "suspended";
  last_accrual_at?: string | null;
}

export interface ReferralTierView {
  label: string;
  min_volume: number;
  percent: number;
}

export interface ReferralAccountView {
  enabled: boolean;
  is_agent?: boolean;
  agent_status?: "active" | "suspended";
  referral_code?: string | null;
  account?: CommissionAccountSummary;
  earned_this_month?: number;
  rate?: { percent: number; label?: string | null; source: string };
  next_tier?: (ReferralTierView & { remaining: number }) | null;
  programme?: {
    enabled: boolean;
    min_payout_amount: number;
    holding_hours: number;
    payout_to_bank_enabled: boolean;
    payout_to_play_enabled: boolean;
    level2_enabled: boolean;
    level2_percent: number;
    tiers: ReferralTierView[];
  };
}

export interface ReferralTeamMemberView {
  id: number;
  name: string;
  phone?: string | null;
  play_id?: string | null;
  joined_at?: string | null;
  deposit_total: number;
  commission_earned: number;
  is_active: boolean;
}

export interface CommissionEntryView {
  id: number;
  type: "accrual" | "adjustment" | "payout" | "bonus";
  status: "pending" | "available" | "paid" | "void";
  level: number;
  from_name?: string | null;
  from_play_id?: string | null;
  base_amount: number;
  percent: number;
  amount: number;
  available_at?: string | null;
  created_at?: string | null;
}

export interface CommissionPayoutView {
  id: number;
  amount: number;
  method: "upi" | "bank" | "play";
  status: "pending" | "approved" | "rejected";
  notes?: string | null;
  created_at?: string | null;
  processed_at?: string | null;
}

type ApiEnvelope<T> = {
  data?: T;
  meta?: PaginationMeta;
  message?: string;
};

const baseQuery = fetchBaseQuery({
  baseUrl: API_BASE,
  prepareHeaders: (headers) => {
    const token = getAuthToken();
    if (token) headers.set("Authorization", `Bearer ${token}`);
    return headers;
  },
});

const resolveStoredBranchId = () => {
  if (typeof window === "undefined") return null;

  const storedUser = getStoredUser();
  const raw =
    (storedUser?.branch_id as unknown) ??
    (storedUser?.branchId as unknown) ??
    (storedUser?.branch as { id?: unknown } | undefined)?.id;

  if (typeof raw === "number" && Number.isFinite(raw)) return raw;
  if (typeof raw === "string" && raw.trim().length) {
    const parsed = Number(raw);
    if (Number.isFinite(parsed)) return parsed;
  }
  return null;
};

/**
 * Public endpoints (banners, app-settings) are branch-scoped but unauthenticated,
 * so the branch has to travel in the query string. Preference order: the logged-in
 * user's branch, then a build-baked branch code, then the hostname the app is
 * served from (each branch gets its own domain).
 */
const withBranchCode = (url: string) => {
  const storedBranchId = resolveStoredBranchId();
  if (storedBranchId) return `${url}?branch_id=${encodeURIComponent(String(storedBranchId))}`;
  if (BRANCH_CODE) return `${url}?branch_code=${encodeURIComponent(BRANCH_CODE)}`;

  if (typeof window !== "undefined") {
    const host = window.location.hostname;
    if (host) return `${url}?domain=${encodeURIComponent(host)}`;
  }
  return url;
};

export const sindApi = createApi({
  reducerPath: "sindApi",
  baseQuery: withInFlightDedup(baseQuery),
  tagTypes: [
    "Auth",
    "Deposits",
    "DepositAccounts",
    "Withdrawals",
    "Banners",
    "AppSettings",
    "SupportChat",
    "Bonus",
    "WinnerStreak",
    "Referral",
  ],
  endpoints: (builder) => ({
    login: builder.mutation<LoginResponse, LoginRequest>({
      query: (body) => ({ url: "auth/mpin-login", method: "POST", body }),
    }),
    checkBranch: builder.mutation<BranchCheckResponse, { phone: string }>({
      query: (body) => ({ url: "auth/check-branch", method: "POST", body }),
    }),
    logout: builder.mutation<{ message?: string }, void>({
      query: () => ({ url: "auth/logout", method: "POST" }),
    }),
    verifyMpin: builder.mutation<{ message?: string }, { mpin: string }>({
      query: (body) => ({ url: "auth/mpin/verify", method: "POST", body }),
    }),
    changeMpin: builder.mutation<
      { message?: string },
      { current_mpin: string; mpin: string; mpin_confirmation: string }
    >({
      query: (body) => ({ url: "auth/mpin/change", method: "POST", body }),
    }),
    getAuthMe: builder.query<Record<string, unknown> | null, void>({
      query: () => "auth/me",
      transformResponse: (response: ApiEnvelope<Record<string, unknown>> = {}) =>
        response.data ?? null,
      providesTags: [{ type: "Auth", id: "ME" }],
    }),
    getDepositAccounts: builder.query<DepositAccount[], void>({
      query: () => ({ url: "accounts" }),
      transformResponse: (response: ApiEnvelope<DepositAccount[]> = {}) => response.data ?? [],
      providesTags: (result) =>
        result
          ? [
              ...result.map((account) => ({ type: "DepositAccounts" as const, id: account.id })),
              { type: "DepositAccounts" as const, id: "LIST" },
            ]
          : [{ type: "DepositAccounts" as const, id: "LIST" }],
    }),
    getDeposits: builder.query<HistoryResponse<DepositRecord>, void>({
      query: () => ({ url: "deposits" }),
      transformResponse: (
        response: ApiEnvelope<DepositRecord[]> & {
          pending?: DepositRecord[];
          recent_success?: DepositRecord[];
          recent_failed?: DepositRecord[];
          recent_pending?: DepositRecord[];
        } = {}
      ) => ({
        data: response.data ?? [],
        meta: response.meta ?? null,
        pending: Array.isArray(response.pending) ? response.pending : [],
        recent_success: Array.isArray(response.recent_success) ? response.recent_success : [],
        recent_failed: Array.isArray(response.recent_failed) ? response.recent_failed : [],
        recent_pending: Array.isArray(response.recent_pending) ? response.recent_pending : [],
      }),
      providesTags: (result) =>
        result?.data
          ? [
              ...result.data.map((record) => ({ type: "Deposits" as const, id: record.id })),
              { type: "Deposits" as const, id: "LIST" },
            ]
          : [{ type: "Deposits" as const, id: "LIST" }],
    }),
    createDeposit: builder.mutation<ApiEnvelope<DepositRecord>, CreateDepositPayload>({
      query: (body) => ({ url: "deposits", method: "POST", body }),
      invalidatesTags: [{ type: "Deposits", id: "LIST" }],
    }),
    getWithdrawals: builder.query<HistoryResponse<WithdrawalRecord>, void>({
      query: () => ({ url: "withdrawals" }),
      transformResponse: (
        response: ApiEnvelope<WithdrawalRecord[]> & {
          pending?: WithdrawalRecord[];
          recent_success?: WithdrawalRecord[];
          recent_failed?: WithdrawalRecord[];
          recent_pending?: WithdrawalRecord[];
        } = {}
      ) => ({
        data: response.data ?? [],
        meta: response.meta ?? null,
        pending: Array.isArray(response.pending) ? response.pending : [],
        recent_success: Array.isArray(response.recent_success) ? response.recent_success : [],
        recent_failed: Array.isArray(response.recent_failed) ? response.recent_failed : [],
        recent_pending: Array.isArray(response.recent_pending) ? response.recent_pending : [],
      }),
      providesTags: (result) =>
        result?.data
          ? [
              ...result.data.map((record) => ({ type: "Withdrawals" as const, id: record.id })),
              { type: "Withdrawals" as const, id: "LIST" },
            ]
          : [{ type: "Withdrawals" as const, id: "LIST" }],
    }),
    createWithdrawal: builder.mutation<ApiEnvelope<WithdrawalRecord>, CreateWithdrawalPayload>({
      query: (body) => ({ url: "withdrawals", method: "POST", body }),
      invalidatesTags: [{ type: "Withdrawals", id: "LIST" }],
    }),
    cancelWithdrawal: builder.mutation<ApiEnvelope<WithdrawalRecord>, number>({
      query: (withdrawalId) => ({ url: `withdrawals/${withdrawalId}/cancel`, method: "POST" }),
      invalidatesTags: (_result, _error, id) => [
        { type: "Withdrawals", id },
        { type: "Withdrawals", id: "LIST" },
      ],
    }),
    getBanners: builder.query<BannerRecord[], void>({
      query: () => withBranchCode("banners"),
      transformResponse: (response: ApiEnvelope<BannerRecord[]> = {}) => response.data ?? [],
      providesTags: [{ type: "Banners", id: "LIST" }],
    }),
    getAppSettings: builder.query<AppSettings | null, void>({
      query: () => withBranchCode("app-settings"),
      transformResponse: (response: ApiEnvelope<AppSettings> = {}) => response.data ?? null,
      providesTags: [{ type: "AppSettings", id: "SINGLE" }],
    }),
    getGlobalSettings: builder.query<GlobalSettings | null, void>({
      query: () => "global-settings",
      transformResponse: (response: ApiEnvelope<GlobalSettings> = {}) => response.data ?? null,
    }),
    registerPushSubscription: builder.mutation<
      { message: string; id?: number },
      { endpoint: string; keys: { p256dh: string; auth: string }; content_encoding?: string }
    >({
      query: (body) => ({ url: "push/subscribe", method: "POST", body }),
    }),
    unregisterPushSubscription: builder.mutation<{ message: string }, { endpoint: string }>({
      query: (body) => ({ url: "push/subscribe", method: "DELETE", body }),
    }),
    getBonusRedemptions: builder.query<BonusRedemptionEntry[], void>({
      query: () => ({ url: "bonus/redemptions" }),
      transformResponse: (response: ApiEnvelope<BonusRedemptionEntry[]> = {}) => response.data ?? [],
      providesTags: [{ type: "Bonus", id: "LIST" }],
    }),
    checkBonusCode: builder.mutation<BonusCodePreview, { code: string; amount?: number }>({
      query: (body) => ({ url: "bonus/check", method: "POST", body }),
      transformResponse: (response: ApiEnvelope<BonusCodePreview> = {}) =>
        response.data as BonusCodePreview,
    }),
    redeemBonusCode: builder.mutation<
      ApiEnvelope<BonusRedemptionEntry> & { message?: string },
      { code: string }
    >({
      query: (body) => ({ url: "bonus/redeem", method: "POST", body }),
      invalidatesTags: [{ type: "Bonus", id: "LIST" }],
    }),
    getSupportChat: builder.query<SupportChatData | null, void>({
      query: () => ({ url: "support/chat" }),
      transformResponse: (response: ApiEnvelope<SupportChatData> = {}) => response.data ?? null,
      providesTags: [{ type: "SupportChat", id: "STATE" }],
    }),
    sendSupportMessage: builder.mutation<
      ApiEnvelope<SupportChatMessage>,
      { body?: string; image?: string; audio?: string }
    >({
      query: (body) => ({ url: "support/chat", method: "POST", body }),
      invalidatesTags: [{ type: "SupportChat", id: "STATE" }],
    }),
    getWinnerStreak: builder.query<WinnerStreakFeed | null, void>({
      query: () => ({ url: "winner-streak/recent" }),
      transformResponse: (response: ApiEnvelope<WinnerStreakFeed> = {}) => response.data ?? null,
      providesTags: [{ type: "WinnerStreak", id: "FEED" }],
    }),
    getReferralAccount: builder.query<ReferralAccountView | null, void>({
      query: () => ({ url: "referral/account" }),
      transformResponse: (response: ApiEnvelope<ReferralAccountView> = {}) => response.data ?? null,
      providesTags: [{ type: "Referral", id: "ACCOUNT" }],
    }),
    getReferralTeam: builder.query<ReferralTeamMemberView[], void>({
      query: () => ({ url: "referral/team" }),
      transformResponse: (response: ApiEnvelope<ReferralTeamMemberView[]> = {}) =>
        response.data ?? [],
      providesTags: [{ type: "Referral", id: "TEAM" }],
    }),
    getReferralEntries: builder.query<CommissionEntryView[], { page?: number } | void>({
      query: (params) => ({ url: "referral/entries", params: params ?? undefined }),
      transformResponse: (response: ApiEnvelope<CommissionEntryView[]> = {}) => response.data ?? [],
      providesTags: [{ type: "Referral", id: "ENTRIES" }],
    }),
    getReferralPayouts: builder.query<CommissionPayoutView[], void>({
      query: () => ({ url: "referral/payouts" }),
      transformResponse: (response: ApiEnvelope<CommissionPayoutView[]> = {}) => response.data ?? [],
      providesTags: [{ type: "Referral", id: "PAYOUTS" }],
    }),
    requestReferralPayout: builder.mutation<
      ApiEnvelope<CommissionPayoutView>,
      {
        amount: number;
        method: "upi" | "bank" | "play";
        upi_id?: string;
        account_number?: string;
        ifsc_code?: string;
        account_name?: string;
      }
    >({
      query: (body) => ({ url: "referral/payouts", method: "POST", body }),
      invalidatesTags: [
        { type: "Referral", id: "ACCOUNT" },
        { type: "Referral", id: "PAYOUTS" },
        { type: "Referral", id: "ENTRIES" },
      ],
    }),
    applyReferralCode: builder.mutation<
      ApiEnvelope<{ referrer_name?: string }>,
      { referral_code: string }
    >({
      query: (body) => ({ url: "referral/apply-code", method: "POST", body }),
      invalidatesTags: [{ type: "Referral", id: "ACCOUNT" }],
    }),
  }),
});

export const {
  useLoginMutation,
  useCheckBranchMutation,
  useLogoutMutation,
  useVerifyMpinMutation,
  useChangeMpinMutation,
  useGetAuthMeQuery,
  useGetDepositAccountsQuery,
  useGetDepositsQuery,
  useCreateDepositMutation,
  useGetWithdrawalsQuery,
  useCreateWithdrawalMutation,
  useCancelWithdrawalMutation,
  useGetBannersQuery,
  useGetAppSettingsQuery,
  useGetGlobalSettingsQuery,
  useRegisterPushSubscriptionMutation,
  useUnregisterPushSubscriptionMutation,
  useGetBonusRedemptionsQuery,
  useCheckBonusCodeMutation,
  useRedeemBonusCodeMutation,
  useGetSupportChatQuery,
  useSendSupportMessageMutation,
  useGetWinnerStreakQuery,
  useGetReferralAccountQuery,
  useGetReferralTeamQuery,
  useGetReferralEntriesQuery,
  useGetReferralPayoutsQuery,
  useRequestReferralPayoutMutation,
  useApplyReferralCodeMutation,
} = sindApi;
