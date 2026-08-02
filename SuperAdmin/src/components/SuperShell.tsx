import { useEffect, useState, type ReactNode } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import {
  useGetDepositsQuery,
  useGetWalletStatusQuery,
  useGetWithdrawalsQuery,
  useLogoutMutation,
} from "@/services/api";
import { useBranch } from "@/components/BranchContext";
import { useTheme } from "@/theme/ThemeProvider";
import {
  IconAccounts,
  IconAlert,
  IconBanner,
  IconBonus,
  IconBranch,
  IconChat,
  IconClose,
  IconDeposit,
  IconGlobe2,
  IconHome,
  IconLogout,
  IconMenu,
  IconMoon,
  IconReport,
  IconShield,
  IconSun,
  IconTrophy,
  IconUser,
  IconUsers,
  IconWallet,
  IconWhatsApp,
  IconWithdraw,
} from "@/components/icons";
import { formatDateTime } from "@/utils/dateTime";

const TOKEN_KEY = "sind-super-token";
const QUEUE_POLL_MS = 30000;

type NavEntry = {
  to: string;
  label: string;
  Icon: (props: { size?: number }) => ReactNode;
  queue?: "deposit" | "withdraw";
  tab?: boolean;
  /** Grouping header this item sits under in the sidebar. */
  group: "Operate" | "Network" | "Money" | "Configure";
};

export const NAV: NavEntry[] = [
  { to: "/dashboard", label: "Home", Icon: IconHome, tab: true, group: "Operate" },
  { to: "/deposit", label: "Deposit", Icon: IconDeposit, queue: "deposit", tab: true, group: "Operate" },
  { to: "/withdraw", label: "Withdraw", Icon: IconWithdraw, queue: "withdraw", tab: true, group: "Operate" },
  { to: "/support", label: "Support", Icon: IconChat, group: "Operate" },

  { to: "/branches", label: "Branches", Icon: IconBranch, tab: true, group: "Network" },
  { to: "/admins", label: "Admins", Icon: IconShield, group: "Network" },
  { to: "/users", label: "Users", Icon: IconUsers, group: "Network" },
  { to: "/agents", label: "Agents", Icon: IconUser, group: "Network" },

  { to: "/wallet", label: "Wallet", Icon: IconWallet, group: "Money" },
  { to: "/payout", label: "Payout report", Icon: IconReport, group: "Money" },
  { to: "/accounts", label: "Accounts", Icon: IconAccounts, group: "Money" },

  { to: "/banner", label: "Banner", Icon: IconBanner, group: "Configure" },
  { to: "/bonus", label: "Bonus", Icon: IconBonus, group: "Configure" },
  { to: "/winner-streak", label: "Winner Streak", Icon: IconTrophy, group: "Configure" },
  { to: "/whatsapp", label: "WhatsApp", Icon: IconWhatsApp, group: "Configure" },
  { to: "/settings", label: "Global settings", Icon: IconGlobe2, group: "Configure" },
];

const GROUPS = ["Operate", "Network", "Money", "Configure"] as const;
const TABS = NAV.filter((entry) => entry.tab);

const isPending = (status?: string | null) => (status ?? "").toLowerCase() === "pending";

/**
 * Super admin chrome. Same skeleton as the branch panel, three things different:
 * a branch switcher in the header (every page below is branch-scoped), grouped
 * navigation because sixteen flat items is a wall, and the Midnight skin so the
 * two panels are never mistaken for each other at a glance.
 */
export default function SuperShell({
  title,
  subtitle,
  action,
  children,
}: {
  title: string;
  subtitle?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  const navigate = useNavigate();
  const location = useLocation();
  const { mode, toggleMode } = useTheme();
  const { branches, branchId, setBranchId, isLoading: branchesLoading } = useBranch();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [logout] = useLogoutMutation();

  const { data: deposits = [] } = useGetDepositsQuery(undefined, { pollingInterval: QUEUE_POLL_MS });
  const { data: withdrawals = [] } = useGetWithdrawalsQuery(undefined, {
    pollingInterval: QUEUE_POLL_MS,
  });
  const { data: wallet } = useGetWalletStatusQuery();

  const badges = {
    deposit: deposits.filter((record) => isPending(record.status)).length,
    withdraw: withdrawals.filter((record) => isPending(record.status)).length,
  };

  useEffect(() => setDrawerOpen(false), [location.pathname]);

  useEffect(() => {
    if (!drawerOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setDrawerOpen(false);
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [drawerOpen]);

  const handleLogout = async () => {
    try {
      await logout().unwrap();
    } catch {
      /* the local session is cleared either way */
    }
    sessionStorage.removeItem(TOKEN_KEY);
    navigate("/", { replace: true });
  };

  const navList = (
    <div className="min-w-0 space-y-4">
      {GROUPS.map((group) => (
        <div key={group} className="min-w-0">
          <p className="px-3 pb-1 text-[10.5px] font-semibold tracking-wide text-faint uppercase">
            {group}
          </p>
          <ul className="min-w-0 space-y-0.5">
            {NAV.filter((entry) => entry.group === group).map(({ to, label, Icon, queue }) => {
              const count = queue ? badges[queue] : 0;
              return (
                <li key={to} className="min-w-0">
                  <NavLink
                    to={to}
                    className={({ isActive }) =>
                      [
                        "flex h-10 min-w-0 items-center gap-3 rounded-md px-3 text-sm transition-colors",
                        isActive ? "text-accent" : "text-muted hover:bg-surface-2 hover:text-text",
                      ].join(" ")
                    }
                    style={({ isActive }) =>
                      isActive
                        ? {
                            background: "var(--accent-soft)",
                            boxShadow: "inset 0 0 0 1px var(--accent-line)",
                          }
                        : undefined
                    }
                  >
                    <span className="shrink-0">
                      <Icon size={18} />
                    </span>
                    <span className="min-w-0 flex-1 truncate font-medium">{label}</span>
                    {count > 0 ? (
                      <span
                        className="tabular shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                        style={{ background: "var(--accent-2)", color: "var(--text-on-accent)" }}
                      >
                        {count > 99 ? "99+" : count}
                      </span>
                    ) : null}
                  </NavLink>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );

  const brand = (
    <div className="flex min-w-0 items-center gap-3 px-3 py-3">
      <span
        className="grid size-9 shrink-0 place-items-center rounded-md bg-accent text-sm font-extrabold text-on-accent"
        style={{ boxShadow: "var(--sh-glow)" }}
      >
        S
      </span>
      <span className="min-w-0 truncate text-[13px] font-bold tracking-[0.13em] uppercase">
        South<span className="text-accent">IND</span>
        <span className="ml-1.5 text-muted">Super</span>
      </span>
    </div>
  );

  const footerControls = (
    <div className="space-y-1 border-t border-border pt-2">
      <button
        type="button"
        onClick={toggleMode}
        className="flex h-10 w-full min-w-0 items-center gap-3 rounded-md px-3 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-text"
      >
        <span className="shrink-0">{mode === "dark" ? <IconSun /> : <IconMoon />}</span>
        <span className="truncate">{mode === "dark" ? "Light mode" : "Dark mode"}</span>
      </button>
      <button
        type="button"
        onClick={handleLogout}
        className="flex h-10 w-full min-w-0 items-center gap-3 rounded-md px-3 text-sm text-muted transition-colors hover:bg-surface-2 hover:text-text"
      >
        <span className="shrink-0">
          <IconLogout />
        </span>
        <span className="truncate">Log out</span>
      </button>
    </div>
  );

  const branchPicker = (
    <select
      value={branchId === null ? "all" : String(branchId)}
      onChange={(event) =>
        setBranchId(event.target.value === "all" ? null : Number(event.target.value))
      }
      aria-label="Branch"
      disabled={branchesLoading}
      className="h-9 max-w-[11rem] min-w-0 shrink truncate rounded-full border border-border bg-surface px-3 text-[13px] font-medium text-text focus:border-accent focus:outline-none"
    >
      <option value="all">All branches</option>
      {branches.map((branch) => (
        <option key={branch.id} value={branch.id}>
          {branch.name}
        </option>
      ))}
    </select>
  );

  return (
    <div className="min-h-dvh w-full overflow-x-hidden">
      <nav
        aria-label="Main"
        className="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-border bg-bg-elev p-3 lg:flex"
      >
        {brand}
        <div className="min-h-0 flex-1 overflow-y-auto">{navList}</div>
        {footerControls}
      </nav>

      {drawerOpen ? (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Close menu"
            onClick={() => setDrawerOpen(false)}
            className="absolute inset-0 bg-black/55"
          />
          <nav
            aria-label="Main"
            className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-border bg-bg-elev p-3 shadow-lg"
          >
            <div className="flex items-start justify-between gap-2">
              {brand}
              <button
                type="button"
                onClick={() => setDrawerOpen(false)}
                aria-label="Close menu"
                className="mt-4 grid size-9 shrink-0 place-items-center rounded-full border border-border text-muted"
              >
                <IconClose size={16} />
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">{navList}</div>
            {footerControls}
          </nav>
        </div>
      ) : null}

      <div className="flex min-h-dvh min-w-0 flex-col lg:pl-64">
        <header className="pt-safe sticky top-0 z-30 border-b border-border bg-bg-elev/85 backdrop-blur">
          <div className="mx-auto flex h-16 w-full max-w-6xl min-w-0 items-center gap-3 px-4">
            <button
              type="button"
              onClick={() => setDrawerOpen(true)}
              aria-label="Open menu"
              className="grid size-9 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted lg:hidden"
            >
              <IconMenu size={18} />
            </button>

            <div className="min-w-0 flex-1">
              <h1 className="truncate text-base font-semibold">{title}</h1>
              {subtitle ? <p className="truncate text-xs text-muted">{subtitle}</p> : null}
            </div>

            {action}
            {branchPicker}
          </div>

          {/* The wallet funds outbound messaging for every branch — when it lapses,
              WhatsApp and push stop silently, so it gets a banner, not a page. */}
          {wallet?.is_expired ? (
            <div
              className="flex min-w-0 items-center gap-2 px-4 py-2 text-xs"
              style={{ background: "color-mix(in srgb, var(--neg) 16%, transparent)", color: "var(--neg)" }}
            >
              <IconAlert size={14} />
              <span className="min-w-0 truncate">
                Messaging wallet expired
                {wallet.expires_at ? ` on ${formatDateTime(wallet.expires_at)}` : ""} — outbound
                WhatsApp and push are paused.
              </span>
            </div>
          ) : null}
        </header>

        <main className="mx-auto w-full max-w-6xl min-w-0 flex-1 px-4 pt-4 pb-24 lg:pb-10">
          {children}
        </main>
      </div>

      <nav
        aria-label="Shortcuts"
        className="pb-safe fixed inset-x-0 bottom-0 z-40 border-t border-border bg-bg-elev/95 backdrop-blur lg:hidden"
      >
        <ul className="mx-auto flex w-full max-w-6xl">
          {TABS.map(({ to, label, Icon, queue }) => {
            const count = queue ? badges[queue] : 0;
            return (
              <li key={to} className="min-w-0 flex-1">
                <NavLink
                  to={to}
                  className={({ isActive }) =>
                    [
                      "relative flex h-14 flex-col items-center justify-center gap-1 px-1",
                      isActive ? "text-accent" : "text-muted",
                    ].join(" ")
                  }
                >
                  <Icon size={20} />
                  {count > 0 ? (
                    <span
                      className="tabular absolute top-1.5 right-1/2 translate-x-4 rounded-full px-1.5 text-[10px] font-bold"
                      style={{ background: "var(--accent-2)", color: "var(--text-on-accent)" }}
                    >
                      {count > 9 ? "9+" : count}
                    </span>
                  ) : null}
                  <span className="w-full truncate text-center text-[10.5px] font-medium">
                    {label}
                  </span>
                </NavLink>
              </li>
            );
          })}
        </ul>
      </nav>
    </div>
  );
}
