export type Credentials = {
  phone: string;
  password: string;
};

export type AccountRecord = {
  id: number;
  name: string;
  holder_name: string;
  type: string;
  used_for?: string;
  account_number?: string;
  ifsc_code?: string;
  upi_id?: string;
  logo_path?: string;
  logo_url?: string | null;
  status?: string;
  notes?: string | null;
  deposit_limit?: number | null;
  min_deposit?: number | null;
  max_deposit?: number | null;
  created_at?: string;
  last_used_at?: string | null;
};

export type DepositRecord = {
  id: number;
  amount: string;
  status: string;
  play_id?: string | null;
  utr_number?: string;
  created_at: string;
  approved_at?: string | null;
  processing_seconds?: number | null;
  account_name?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  upi_id?: string | null;
  receipt_image_path?: string | null;
  receipt_image_url?: string | null;
  bonus?: {
    id: number;
    code: string;
    amount: number;
    reward_label?: string | null;
    status: "awaiting_deposit" | "pending" | "fulfilled" | "rejected";
  } | null;
  account_id?: number | string;
  account?:
    | {
        id?: number;
        name?: string | null;
        account_number?: string | null;
        ifsc_code?: string | null;
        upi_id?: string | null;
      }
    | null;
  notes?: string | null;
  user?: {
    id?: number;
    name?: string | null;
    phone?: string | null;
    status?: string | null;
    unique_number?: string | null;
  } | null;
};

export type WithdrawRecord = {
  id: number;
  amount: string;
  status: string;
  destination_type?: string;
  created_at: string;
  processed_at?: string | null;
  processing_seconds?: number | null;
  account_name?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  upi_id?: string | null;
  account_id?: number | string;
  account?:
    | {
        id?: number;
        name?: string | null;
        account_number?: string | null;
        ifsc_code?: string | null;
        upi_id?: string | null;
      }
    | null;
  user?: {
    id?: number;
    name?: string | null;
    phone?: string | null;
    status?: string | null;
    unique_number?: string | null;
  } | null;
  play_id?: string | null;
};

export type DepositQueryParams = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  start_date?: string;
  end_date?: string;
};

export type WithdrawQueryParams = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  start_date?: string;
  end_date?: string;
};

export type BannerRecord = {
  id: number;
  title?: string | null;
  image_url?: string | null;
  image_path?: string | null;
  created_at?: string;
  is_logo?: boolean | number;
};

export type AppSettings = {
  id?: number;
  deposit_offer_text?: string;
  withdrawal_offer_text?: string;
  instagram_link?: string;
  whatsapp_number?: string;
  whatsapp_link?: string;
  telegram_link?: string;
  withdrawal_wa?: string;
  deposit_wa?: string;
  bonus_deposit_enabled?: boolean;
  [key: string]: unknown;
};

export type Tag = {
  id: number;
  name: string;
  color: string;
  users_count?: number;
};

type SupportUserBrief = {
  id?: number;
  name?: string | null;
  phone?: string | null;
  unique_number?: string | null;
};

export type SupportMessage = {
  id: number;
  sender_type: "user" | "admin";
  body?: string | null;
  image_url?: string | null;
  audio_url?: string | null;
  created_at?: string | null;
  edited?: boolean;
  edited_at?: string | null;
  deleted?: boolean;
  deleted_at?: string | null;
};

export type SupportConversationSummary = {
  id: number;
  status: "open" | "closed";
  flagged?: boolean;
  awaiting_reply?: boolean;
  last_message_preview?: string | null;
  last_message_at?: string | null;
  unread_count: number;
  tags?: Tag[];
  user?: SupportUserBrief | null;
};

export type SupportThread = {
  conversation: {
    id: number;
    status: "open" | "closed";
    flagged?: boolean;
    awaiting_reply?: boolean;
    tags?: Tag[];
    user?: SupportUserBrief | null;
  };
  messages: SupportMessage[];
};

export type UserRecord = {
  id: number;
  name?: string | null;
  phone?: string | null;
  username?: string | null;
  unique_number?: string | null;
  play_id?: string | null;
  created_at?: string | null;
  last_seen_at?: string | null;
  status?: string | null;
  user_type?: "user" | "agent" | null;
  agent_status?: "active" | "suspended" | null;
  referral_code?: string | null;
  mpin?: string | number | null;
  mpin_locked?: boolean;
  mpin_failed_attempts?: number;
  mpin_locked_at?: string | null;
  branch_id?: number | null;
  deposit_approved_total?: number | null;
  withdrawal_approved_total?: number | null;
  profit_total?: number | null;
  branch?: {
    id?: number;
    name?: string | null;
    code?: string | null;
    domain?: string | null;
  } | null;
  tags?: Tag[];
};

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type SummaryTotals = {
  count: number;
  total: number;
  approvedTotal: number;
  rejectedTotal: number;
  approvedCount: number;
  rejectedCount: number;
  avgProcessingSeconds: number;
};

export type PaginatedResponse<T> = {
  data: T[];
  meta?: PaginationMeta;
  summary?: SummaryTotals;
  links?: {
    first?: string | null;
    last?: string | null;
    prev?: string | null;
    next?: string | null;
  };
};

export type UserStats = {
  active_now?: number;
  seen_24h?: number;
  new_24h?: number;
  total?: number;
};

export type UsersListResponse = PaginatedResponse<UserRecord> & {
  user_stats?: UserStats;
};

export type UsersQueryParams = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
};

export type WalletExpiryStatus = {
  is_expired: boolean;
  expires_at: string | null;
};

export type WhatsAppAccount = {
  id: number;
  display_name: string;
  phone_number?: string | null;
  status: "active" | "inactive";
};

export type WhatsAppTemplate = {
  id: number;
  whatsapp_account_id: number;
  name: string;
  language: string;
  category?: string | null;
  status?: string | null;
  components?: unknown;
};

export type WhatsAppMessage = {
  id: number;
  direction: "inbound" | "outbound";
  type: string;
  body?: string | null;
  media_url?: string | null;
  template_name?: string | null;
  status?: string | null;
  created_at?: string | null;
};

export type WhatsAppConversationSummary = {
  id: number;
  whatsapp_account_id: number;
  account_name?: string | null;
  contact_phone: string;
  contact_name?: string | null;
  last_message_preview?: string | null;
  last_message_at?: string | null;
  last_inbound_at?: string | null;
  unread_count: number;
};

export type WhatsAppThread = {
  conversation: {
    id: number;
    whatsapp_account_id: number;
    contact_phone: string;
    contact_name?: string | null;
    within_24h_window: boolean;
  };
  messages: WhatsAppMessage[];
};

export type BonusCodeFrequency = "once" | "daily" | "weekly" | "monthly" | "unlimited";

export type BonusCodeRecord = {
  id: number;
  code: string;
  branch_id?: number | null;
  title?: string | null;
  terms_text?: string | null;
  reward_amount: number;
  reward_label?: string | null;
  frequency: BonusCodeFrequency;
  per_user_limit: number;
  max_redemptions?: number | null;
  redeemed_count: number;
  remaining_redemptions?: number | null;
  starts_at?: string | null;
  expires_at?: string | null;
  status: "active" | "paused";
  effective_status: "active" | "paused" | "expired" | "exhausted" | "scheduled";
  auto_approve: boolean;
  requires_deposit: boolean;
  min_deposit: number;
  new_user_days?: number | null;
  tags?: Tag[];
  created_by?: { id: number; name?: string | null } | null;
  created_by_role?: string | null;
  created_at?: string;
  updated_at?: string;
};

export type BonusRedemptionRecord = {
  id: number;
  bonus_code_id: number;
  code: string;
  amount: number;
  reward_label?: string | null;
  status: "awaiting_deposit" | "pending" | "fulfilled" | "rejected";
  deposit_id?: number | null;
  redeemed_at?: string | null;
  fulfilled_at?: string | null;
  notes?: string | null;
  ip_address?: string | null;
  device_fingerprint?: string | null;
  user?: {
    id?: number;
    name?: string | null;
    phone?: string | null;
    unique_number?: string | null;
    play_id?: string | null;
  } | null;
  fulfilled_by?: { id: number; name?: string | null } | null;
  created_at?: string;
};

/* ---------------------------------------------------------------- *
 * Winner Streak
 * ---------------------------------------------------------------- */

export type WinnerStreakPeriod = "daily" | "weekly" | "monthly";

export type WinnerStreakSettings = {
  branch_id: number;
  period: WinnerStreakPeriod;
  enabled: boolean;
  tag_id: number | null;
  exclude_tag_id: number | null;
  top_n: number;
  loss_board_enabled: boolean;
  loss_board_public: boolean;
  min_turnover: number;
  min_transactions: number;
  reset_time: string;
  reset_weekday: number;
  reset_day_of_month: number;
  last_reset_at?: string | null;
  next_reset_at?: string | null;
  show_name: boolean;
  show_play_id: boolean;
  show_phone: boolean;
  show_amount: boolean;
  show_profit_loss: boolean;
  show_reward: boolean;
  mask_amount_bucket: boolean;
  mask_name: boolean;
  reward_amount: number;
  reward_label?: string | null;
  announce_push: boolean;
  winner_cooldown_cycles: number;
  quiet_hours_enabled: boolean;
  quiet_from: string;
  quiet_to: string;
};

export type WinnerStreakCycle = {
  id: number;
  branch_id: number;
  period: WinnerStreakPeriod;
  label?: string | null;
  status: "open" | "closed";
  starts_at?: string | null;
  ends_at?: string | null;
  closed_at?: string | null;
  participants_count: number;
  announced: boolean;
};

/** A live (unfrozen) leaderboard row straight from the calculator. */
export type WinnerStreakLiveRow = {
  user_id: number;
  kind: "profit" | "loss";
  rank: number;
  display_name?: string | null;
  display_play_id?: string | null;
  display_phone?: string | null;
  deposit_total: number;
  withdrawal_total: number;
  bonus_total: number;
  net_amount: number;
  transactions_count: number;
};

/** A frozen row from a closed cycle. */
export type WinnerStreakEntry = {
  id: number;
  cycle_id: number;
  user_id?: number | null;
  kind: "profit" | "loss";
  rank: number;
  name?: string | null;
  play_id?: string | null;
  phone?: string | null;
  deposit_total: number;
  withdrawal_total: number;
  bonus_total: number;
  net_amount: number;
  transactions_count: number;
  reward_amount: number;
  reward_label?: string | null;
  reward_status: "pending" | "paid" | "skipped";
  paid_at?: string | null;
  notes?: string | null;
  shared_payout?: WinnerStreakSharedPayout | null;
};
export type WinnerStreakSharedPayout = {
  shared_with: number;
  destinations: string[];
};


export type WinnerStreakBoard = {
  profit: WinnerStreakLiveRow[];
  loss: WinnerStreakLiveRow[];
  participants: number;
};

export type WinnerStreakPeriodPayload = {
  settings: WinnerStreakSettings;
  cycle: WinnerStreakCycle;
  board: WinnerStreakBoard;
};

export type WinnerStreakOverview = {
  periods: Record<WinnerStreakPeriod, WinnerStreakPeriodPayload>;
  tags: Tag[];
  branch_id?: number;
};

export type WinnerStreakHistoryCycle = WinnerStreakCycle & {
  profit: WinnerStreakEntry[];
  loss: WinnerStreakEntry[];
};

// ---- Referral & commission -------------------------------------------------

/** One rung of the commission tier ladder. */
export type ReferralTier = {
  label: string;
  min_volume: number;
  percent: number;
};

export type ReferralSettings = {
  branch_id?: number;
  enabled: boolean;
  commission_percent: number;
  tiers_enabled: boolean;
  tiers: ReferralTier[];
  level2_enabled: boolean;
  level2_percent: number;
  min_deposit_amount: number;
  first_deposit_only: boolean;
  holding_hours: number;
  monthly_cap_per_agent: number;
  per_deposit_cap: number;
  max_referrals_per_day: number;
  block_same_phone: boolean;
  block_shared_payout: boolean;
  washout_hours: number;
  auto_promote_to_agent: boolean;
  allow_self_signup_code: boolean;
  retroactive_on_attach: boolean;
  min_payout_amount: number;
  payout_to_bank_enabled: boolean;
  payout_to_play_enabled: boolean;
  notify_on_commission: boolean;
  notify_on_join: boolean;
  updated_at?: string | null;
};

/** The cached totals of an agent's Commission Account. */
export type CommissionAccount = {
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
};

export type ReferralAgent = {
  id: number;
  name: string;
  phone?: string | null;
  play_id?: string | null;
  unique_number?: string | null;
  branch_id: number;
  user_type: "user" | "agent";
  agent_status: "active" | "suspended";
  status: string;
  referral_code?: string | null;
  commission_percent_override?: number | null;
  referred_by?: number | null;
  referrer_name?: string | null;
  account?: CommissionAccount | null;
  created_at?: string | null;
  last_seen_at?: string | null;
};

export type CommissionEntryType = "accrual" | "adjustment" | "payout" | "bonus";
export type CommissionEntryStatus = "pending" | "available" | "paid" | "void";

export type CommissionEntry = {
  id: number;
  agent_id: number;
  agent_name?: string | null;
  type: CommissionEntryType;
  status: CommissionEntryStatus;
  level: number;
  from_user_id?: number | null;
  from_name?: string | null;
  from_play_id?: string | null;
  deposit_id?: number | null;
  payout_id?: number | null;
  base_amount: number;
  percent: number;
  tier_label?: string | null;
  amount: number;
  available_at?: string | null;
  released_at?: string | null;
  deposit_status_at_accrual?: string | null;
  /** Diverges from the accrual status when a deposit was later undone. */
  deposit_status_now?: string | null;
  flagged_at?: string | null;
  flag_reason?: string | null;
  resolved_at?: string | null;
  note?: string | null;
  created_at?: string | null;
};

export type ReferralTeamMember = {
  id: number;
  name: string;
  phone?: string | null;
  play_id?: string | null;
  status?: string;
  referral_source?: string | null;
  joined_at?: string | null;
  commission_from?: string | null;
  deposit_total: number;
  commission_earned: number;
};

export type CommissionPayout = {
  id: number;
  agent_id: number;
  agent_name?: string | null;
  amount: number;
  method: "upi" | "bank" | "play";
  status: "pending" | "approved" | "rejected";
  upi_id?: string | null;
  account_number?: string | null;
  ifsc_code?: string | null;
  account_name?: string | null;
  notes?: string | null;
  processed_by?: number | null;
  processed_at?: string | null;
  created_at?: string | null;
};

export type ReferralAudit = {
  id: number;
  user_id: number;
  user_name?: string | null;
  action: string;
  old_referrer_id?: number | null;
  new_referrer_id?: number | null;
  actor_type: string;
  actor_id?: number | null;
  actor_name?: string | null;
  reason?: string | null;
  meta?: Record<string, unknown> | null;
  created_at?: string | null;
};

export type ReferralOverviewStats = {
  enabled: boolean;
  agents: number;
  active_agents: number;
  referred_users: number;
  /** available + pending — what the branch currently owes its agents. */
  liability: number;
  available_total: number;
  pending_total: number;
  lifetime_earned: number;
  lifetime_paid: number;
  earned_this_month: number;
  flagged_entries: number;
  pending_payouts: number;
  pending_payout_total: number;
};

export type ReferralLeaderRow = {
  agent_id: number;
  name?: string | null;
  play_id?: string | null;
  team_count: number;
  team_deposit_total: number;
  lifetime_earned: number;
  tier_label?: string | null;
};

export type ReferralOverview = {
  branch_id?: number;
  overview: ReferralOverviewStats;
  settings: ReferralSettings;
  leaderboard: ReferralLeaderRow[];
};

export type ReferralAgentDetail = {
  agent: ReferralAgent;
  team: ReferralTeamMember[];
  entries: CommissionEntry[];
  payouts: CommissionPayout[];
};

export type PageMeta = {
  current_page: number;
  last_page: number;
  per_page?: number;
  total: number;
  flagged_total?: number;
};

export type Paginated<T> = { data: T[]; meta: PageMeta };
