import { useEffect, useState, type ReactNode } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import {
  useGetCurrentUserQuery,
  useGetDepositsQuery,
  useGetWithdrawalsQuery,
  useLogoutMutation,
} from "@/services/api";
import { useTheme } from "@/theme/ThemeProvider";
import { useSupportChatEnabled } from "@/hooks/useSupportChatEnabled";
import { BrandMark } from "@/components/Brand";
import {
  IconAccounts,
  IconBanner,
  IconBonus,
  IconChat,
  IconClose,
  IconDeposit,
  IconHome,
  IconLogout,
  IconMenu,
  IconMoon,
  IconSettings,
  IconSun,
  IconTrophy,
  IconUser,
  IconUsers,
  IconWhatsApp,
  IconWithdraw,
} from "@/components/icons";

const TOKEN_KEY = "sind-admin-token";
const QUEUE_POLL_MS = 30000;

type NavEntry = {
  to: string;
  label: string;
  Icon: (props: { size?: number }) => ReactNode;
  /** Which pending queue, if any, drives this item's badge. */
  queue?: "deposit" | "withdraw";
  /** Items that also earn a slot in the mobile bottom bar. */
  tab?: boolean;
};

export const NAV: NavEntry[] = [
  { to: "/dashboard", label: "Home", Icon: IconHome, tab: true },
  { to: "/deposit", label: "Deposit", Icon: IconDeposit, queue: "deposit", tab: true },
  { to: "/withdraw", label: "Withdraw", Icon: IconWithdraw, queue: "withdraw", tab: true },
  { to: "/users", label: "Users", Icon: IconUsers, tab: true },
  { to: "/referral", label: "Agents", Icon: IconUser },
  { to: "/accounts", label: "Accounts", Icon: IconAccounts },
  { to: "/banner", label: "Banner", Icon: IconBanner },
  { to: "/bonus", label: "Bonus", Icon: IconBonus },
  { to: "/winner-streak", label: "Winner Streak", Icon: IconTrophy },
  { to: "/support", label: "Support", Icon: IconChat },
  { to: "/whatsapp", label: "WhatsApp", Icon: IconWhatsApp },
  { to: "/setting", label: "Setting", Icon: IconSettings },
];

const TABS = NAV.filter((entry) => entry.tab);

const isPending = (status?: string | null) => (status ?? "").toLowerCase() === "pending";

/**
 * Admin chrome. Unlike the User app's rail this sidebar is always expanded on
 * desktop — an operator reads twelve labels all day and should never have to
 * hover to find one. Below lg it collapses into a drawer plus a four-item bar
 * for the queues admins actually live in.
 */
export default function AdminShell({
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
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [logout] = useLogoutMutation();

  // /me carries features.support_chat; refreshed at most every five minutes as
  // the admin moves between pages, so switching chat off needs no extra polling.
  useGetCurrentUserQuery(undefined, { refetchOnMountOrArgChange: 300 });
  const supportChatOn = useSupportChatEnabled();
  const nav = supportChatOn ? NAV : NAV.filter((entry) => entry.to !== "/support");

  // Queue badges. Polled rather than pushed: a stale "3 pending" badge is the
  // difference between a payout going out in a minute and in an hour.
  const { data: deposits = [] } = useGetDepositsQuery(undefined, {
    pollingInterval: QUEUE_POLL_MS,
  });
  const { data: withdrawals = [] } = useGetWithdrawalsQuery(undefined, {
    pollingInterval: QUEUE_POLL_MS,
  });

  const badges = {
    deposit: deposits.filter((record) => isPending(record.status)).length,
    withdraw: withdrawals.filter((record) => isPending(record.status)).length,
  };

  // A drawer that survives navigation hides the page the user just asked for.
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
    <ul className="min-w-0 space-y-0.5">
      {nav.map(({ to, label, Icon, queue }) => {
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
                  ? { background: "var(--accent-soft)", boxShadow: "inset 0 0 0 1px var(--accent-line)" }
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
  );

  const brand = (
    <div className="flex min-w-0 items-center gap-3 px-3 py-3">
      <BrandMark size={36} />
      <span className="min-w-0 truncate text-[13px] font-bold tracking-[0.13em] uppercase">
        South<span className="text-accent">IND</span>
        <span className="ml-1.5 text-muted">Admin</span>
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

  return (
    <div className="min-h-dvh w-full overflow-x-hidden">
      {/* ── Desktop sidebar ─────────────────────────────────────────────── */}
      <nav
        aria-label="Main"
        className="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-border bg-bg-elev p-3 lg:flex"
      >
        {brand}
        <div className="min-h-0 flex-1 overflow-y-auto">{navList}</div>
        {footerControls}
      </nav>

      {/* ── Mobile drawer ───────────────────────────────────────────────── */}
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

      {/* ── Content ─────────────────────────────────────────────────────── */}
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
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl min-w-0 flex-1 px-4 pt-4 pb-24 lg:pb-10">
          {children}
        </main>
      </div>

      {/* ── Mobile queue bar ────────────────────────────────────────────── */}
      <nav
        aria-label="Queues"
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
