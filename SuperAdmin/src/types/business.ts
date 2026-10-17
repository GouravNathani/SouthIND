/** GET /super/dashboard/business — see BusinessDashboardController. */

export type BusinessMonth = {
  month: string;
  partial: boolean;
  signups: number;
  signup_growth: number | null;
  first_deposits: number;
  active: number;
  active_growth: number | null;
  new_active: number;
  retained: number;
  reactivated: number;
  churned: number;
  churn_rate: number | null;
  retention_rate: number | null;
  deposits_total: number;
  deposits_count: number;
  depositors: number;
  deposit_growth: number | null;
  withdrawals_total: number;
  withdrawals_count: number;
  net: number;
  avg_per_depositor: number;
};

export type BusinessPeriod = {
  deposits: number;
  withdrawals: number;
  net: number;
  active: number;
  signups: number;
  first_deposits: number;
};

export type BusinessPlayer = {
  id: number;
  name: string | null;
  play_id: string | null;
  phone: string | null;
  status: string | null;
  branch: string | null;
  last_activity_at: string | null;
  days_inactive: number | null;
  lifetime_deposits: number;
  lifetime_withdrawals: number;
  lifetime_net: number;
  deposits_count: number;
  month_deposits?: number;
  month_deposits_count?: number;
};

export type BusinessCohort = {
  month: string;
  size: number;
  depositors: number;
  conversion_rate: number | null;
  retention: Array<number | null>;
};

export type BusinessBranch = {
  id: number;
  name: string;
  code: string;
  is_active: boolean;
  users: number;
  signups_month: number;
  active_30d: number;
  lost: number;
  deposits_month: number;
  withdrawals_month: number;
  net_month: number;
};

export type BusinessDashboard = {
  generated_at: string;
  branch_id: number | null;
  rules: { lost_after_days: number; at_risk_after_days: number };
  snapshot: {
    total_users: number;
    banned_users: number;
    agents: number;
    depositors: number;
    never_deposited: number;
    conversion_rate: number | null;
    active_today: number;
    active_7d: number;
    active_30d: number;
    at_risk: number;
    lost: number;
    lost_90d: number;
    lost_net_value: number;
    seen_24h: number;
    seen_7d: number;
    signups_30d: number;
    signups_30d_no_deposit: number;
    lifetime_deposits: number;
    lifetime_withdrawals: number;
    lifetime_net: number;
    avg_deposit: number;
    avg_net_per_depositor: number;
  };
  month_to_date: {
    day: number;
    current: BusinessPeriod;
    previous: BusinessPeriod;
    growth: Record<keyof BusinessPeriod, number | null>;
  };
  months: BusinessMonth[];
  cohorts: BusinessCohort[];
  peak_hours: number[];
  lost_players: BusinessPlayer[];
  at_risk_players: BusinessPlayer[];
  top_players: BusinessPlayer[];
  branches: BusinessBranch[];
};
