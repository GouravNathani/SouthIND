import { useState, type ReactNode } from "react";
import { NavLink, useLocation } from "react-router-dom";
import {
  IconChat,
  IconDeposit,
  IconHistory,
  IconHome,
  IconMoon,
  IconSun,
  IconTrophy,
  IconUser,
  IconWithdraw,
} from "@/components/icons";
import { useTheme } from "@/theme/ThemeProvider";

type NavEntry = {
  to: string;
  label: string;
  Icon: (props: { size?: number }) => ReactNode;
  /** Bottom tab bars hold five items comfortably; the rest live on the rail only. */
  tab?: boolean;
};

export const NAV: NavEntry[] = [
  { to: "/dashboard", label: "Home", Icon: IconHome, tab: true },
  { to: "/deposit", label: "Deposit", Icon: IconDeposit, tab: true },
  { to: "/withdrawal", label: "Withdraw", Icon: IconWithdraw, tab: true },
  { to: "/history", label: "History", Icon: IconHistory, tab: true },
  { to: "/account", label: "Account", Icon: IconUser, tab: true },
  { to: "/winners", label: "Winners", Icon: IconTrophy },
  { to: "/chat", label: "Support", Icon: IconChat },
];

const TABS = NAV.filter((entry) => entry.tab);

/**
 * App chrome. Two layouts from one tree:
 *
 *   ≥ lg — a 76px icon rail that expands to 232px on hover. The rail is FIXED and
 *          the 76px gutter is reserved by padding on the content, so expanding it
 *          floats an elevated panel over the page instead of resizing a grid
 *          column. That is deliberate: animating a layout column re-flows every
 *          card next to it and the wide rail bleeds into the content it overlaps.
 *   < lg — a bottom tab bar, safe-area aware.
 */
export default function AppShell({
  children,
  title,
  subtitle,
  action,
  fill,
}: {
  children: ReactNode;
  title?: string;
  subtitle?: string;
  action?: ReactNode;
  /** Let the page own the leftover height instead of growing with its content
   *  — the chat transcript needs the viewport, not the document. */
  fill?: boolean;
}) {
  const [railOpen, setRailOpen] = useState(false);
  const { mode, toggleMode } = useTheme();
  const location = useLocation();

  const activeEntry = NAV.find((entry) => location.pathname.startsWith(entry.to));

  return (
    <div className="min-h-dvh w-full overflow-x-hidden">
      {/* ── Desktop rail ────────────────────────────────────────────────── */}
      <nav
        onMouseEnter={() => setRailOpen(true)}
        onMouseLeave={() => setRailOpen(false)}
        aria-label="Main"
        data-open={railOpen}
        className={[
          "fixed inset-y-0 left-0 z-40 hidden flex-col gap-1.5 overflow-hidden",
          "border-r border-border bg-bg-elev p-3 transition-[width,box-shadow] duration-200 lg:flex",
          railOpen ? "w-[232px] shadow-lg" : "w-[76px]",
        ].join(" ")}
        style={{ transitionTimingFunction: "var(--ease)" }}
      >
        <div className="flex items-center gap-3 px-1.5 pt-1 pb-5 whitespace-nowrap">
          <span
            className="grid size-[34px] shrink-0 place-items-center rounded-md bg-accent text-[15px] font-extrabold text-on-accent"
            style={{ boxShadow: "var(--sh-glow)" }}
          >
            S
          </span>
          <span
            className={[
              "text-[13px] font-bold tracking-[0.13em] uppercase transition-opacity duration-150",
              railOpen ? "opacity-100" : "opacity-0",
            ].join(" ")}
          >
            South<span className="text-accent">IND</span>
          </span>
        </div>

        {NAV.map(({ to, label, Icon }) => (
          <NavLink
            key={to}
            to={to}
            title={label}
            className={({ isActive }) =>
              [
                "relative flex h-[46px] shrink-0 items-center gap-3.5 rounded-md px-3 whitespace-nowrap",
                "transition-colors duration-150",
                isActive ? "text-accent" : "text-muted hover:bg-surface hover:text-text",
              ].join(" ")
            }
            style={({ isActive }) =>
              isActive
                ? { background: "var(--accent-soft)", boxShadow: "inset 0 0 0 1px var(--accent-line)" }
                : undefined
            }
          >
            <span className="shrink-0">
              <Icon />
            </span>
            <span
              className={[
                "overflow-hidden text-[13.5px] font-medium transition-opacity duration-150",
                railOpen ? "opacity-100" : "opacity-0",
              ].join(" ")}
            >
              {label}
            </span>
          </NavLink>
        ))}

        <div className="flex-1" />

        <button
          type="button"
          onClick={toggleMode}
          title={mode === "dark" ? "Light mode" : "Dark mode"}
          className="flex h-[46px] shrink-0 items-center gap-3.5 rounded-md px-3 whitespace-nowrap text-muted transition-colors duration-150 hover:bg-surface hover:text-text"
        >
          <span className="shrink-0">{mode === "dark" ? <IconSun /> : <IconMoon />}</span>
          <span
            className={[
              "overflow-hidden text-[13.5px] font-medium transition-opacity duration-150",
              railOpen ? "opacity-100" : "opacity-0",
            ].join(" ")}
          >
            {mode === "dark" ? "Light mode" : "Dark mode"}
          </span>
        </button>
      </nav>

      {/* ── Content column ─────────────────────────────────────────────── */}
      <div className="flex min-h-dvh min-w-0 flex-col lg:pl-[76px]">
        <header className="pt-safe sticky top-0 z-30 border-b border-border bg-bg-elev/85 backdrop-blur">
          <div className="mx-auto flex h-16 w-full max-w-5xl min-w-0 items-center gap-3 px-4">
            <div className="min-w-0 flex-1">
              <h1 className="truncate text-base font-semibold">
                {title ?? activeEntry?.label ?? "SouthIND"}
              </h1>
              {subtitle ? <p className="truncate text-xs text-muted">{subtitle}</p> : null}
            </div>

            {action}

            <button
              type="button"
              onClick={toggleMode}
              aria-label={mode === "dark" ? "Switch to light mode" : "Switch to dark mode"}
              className="grid size-9 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted lg:hidden"
            >
              {mode === "dark" ? <IconSun /> : <IconMoon />}
            </button>
          </div>
        </header>

        <main
          className={[
            "mx-auto w-full max-w-5xl min-w-0 flex-1 px-4 pt-4 pb-28 lg:pb-10",
            fill ? "flex min-h-0 flex-col" : "",
          ].join(" ")}
        >
          {children}
        </main>
      </div>

      {/* ── Mobile tab bar ─────────────────────────────────────────────── */}
      <nav
        aria-label="Main"
        className="pb-safe fixed inset-x-0 bottom-0 z-40 border-t border-border bg-bg-elev/95 backdrop-blur lg:hidden"
      >
        <ul className="mx-auto flex w-full max-w-5xl">
          {TABS.map(({ to, label, Icon }) => (
            <li key={to} className="min-w-0 flex-1">
              <NavLink
                to={to}
                className={({ isActive }) =>
                  [
                    "flex h-14 flex-col items-center justify-center gap-1 px-1",
                    isActive ? "text-accent" : "text-muted",
                  ].join(" ")
                }
              >
                <Icon size={20} />
                <span className="w-full truncate text-center text-[10.5px] font-medium">
                  {label}
                </span>
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
