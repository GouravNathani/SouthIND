import { useState, type ReactNode } from "react";
import { useNavigate } from "react-router-dom";
import Card, { CardTitle } from "@/components/ui/Card";
import Segmented from "@/components/ui/Segmented";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useGetBusinessDashboardQuery } from "@/services/businessApi";
import type { BusinessDashboard as Data, BusinessMonth, BusinessPlayer } from "@/types/business";
import { resolveErrorMessage } from "@/utils/errors";
import { compactInr, inr } from "@/utils/format";

/**
 * Super admin home: month-by-month growth, churn, new and lost players.
 * "Active" = an approved deposit or withdrawal; "left" = none for 30+ days.
 */
export default function BusinessDashboard({ branchId }: { branchId: number | null }) {
  const { data, isLoading, error } = useGetBusinessDashboardQuery(
    { branchId },
    { pollingInterval: 300_000 }
  );
  useSessionGuard(error);

  if (isLoading) return <Skeleton className="h-72 w-full" />;
  if (error || !data)
    return <ErrorNote>{resolveErrorMessage(error, "Could not load the business dashboard.")}</ErrorNote>;

  return (
    <section className="space-y-4">
      <div className="flex min-w-0 flex-wrap items-baseline justify-between gap-2 pt-2">
        <h2 className="text-lg font-semibold text-text">Business dashboard</h2>
        <p className="text-xs text-faint">
          Updated {new Date(data.generated_at).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" })}
          {" · "}refreshes every 5 min
        </p>
      </div>
      <MonthToDate data={data} />
      <PlayerHealth data={data} />
      <MonthByMonth months={data.months} />
      <PlayerLists data={data} />
      <div className="grid gap-3 lg:grid-cols-2">
        <Cohorts data={data} />
        <PeakHours hours={data.peak_hours} />
      </div>
      {data.branches.length ? <BranchTable data={data} /> : null}
      <p className="text-xs text-faint">
        Active = at least one approved deposit or withdrawal. Left = no approved transaction for{" "}
        {data.rules.lost_after_days}+ days. At risk = last one {data.rules.at_risk_after_days}–
        {data.rules.lost_after_days} days ago. Churn = active last month, not this month.
      </p>
    </section>
  );
}

/* ------------------------------------------------------------------ helpers */

const monthName = (label: string, withYear = false) => {
  const [y, m] = label.split("-").map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString(undefined, {
    month: "short",
    ...(withYear ? { year: "2-digit" } : {}),
  });
};

const pct = (value: number | null | undefined) =>
  value === null || value === undefined ? "—" : `${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;

/** ▲ 12.5% / ▼ 4% chip. `goodWhen` says which direction is good news. */
function Change({ value, goodWhen = "up" }: { value: number | null; goodWhen?: "up" | "down" | "none" }) {
  if (value === null) return <span className="text-xs text-faint">—</span>;
  const up = value > 0;
  const flat = value === 0;
  const good = goodWhen === "none" || flat ? null : (up && goodWhen === "up") || (!up && goodWhen === "down");
  const color = good === null ? "var(--text-muted)" : good ? "var(--pos)" : "var(--neg)";
  return (
    <span className="tabular text-xs font-semibold whitespace-nowrap" style={{ color }}>
      {flat ? "■" : up ? "▲" : "▼"} {pct(Math.abs(value))}
    </span>
  );
}

function Tile({ label, value, sub, tone }: { label: string; value: ReactNode; sub?: ReactNode; tone?: "pos" | "neg" | "warn" }) {
  return (
    <div className="min-w-0 rounded-md border border-border bg-surface-2 p-3">
      <p className="truncate text-xs text-muted">{label}</p>
      <p
        className="tabular mt-1 truncate text-xl font-semibold text-text"
        style={tone ? { color: `var(--${tone})` } : undefined}
      >
        {value}
      </p>
      {sub ? <div className="mt-1 min-w-0 text-xs text-faint">{sub}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------ month to date */

function MonthToDate({ data }: { data: Data }) {
  const { current, previous, growth, day } = data.month_to_date;
  const rows: Array<{ key: keyof typeof current; label: string; money?: boolean; goodWhen?: "up" | "down" | "none" }> = [
    { key: "deposits", label: "Deposits", money: true },
    { key: "withdrawals", label: "Withdrawals", money: true, goodWhen: "none" },
    { key: "net", label: "Net (P/L)", money: true },
    { key: "active", label: "Active players" },
    { key: "signups", label: "New users" },
    { key: "first_deposits", label: "First deposits" },
  ];
  return (
    <Card>
      <CardTitle hint={`Day 1–${day} vs last month`}>This month so far</CardTitle>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
        {rows.map((row) => (
          <Tile
            key={row.key}
            label={row.label}
            value={row.money ? compactInr(current[row.key]) : inr(current[row.key])}
            tone={row.key === "net" ? (current.net >= 0 ? "pos" : "neg") : undefined}
            sub={
              <span className="flex min-w-0 flex-wrap items-center gap-x-2">
                <Change value={growth[row.key]} goodWhen={row.goodWhen} />
                <span className="truncate">
                  was {row.money ? compactInr(previous[row.key]) : inr(previous[row.key])}
                </span>
              </span>
            }
          />
        ))}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------ player health */

function PlayerHealth({ data }: { data: Data }) {
  const s = data.snapshot;
  // The current month is still running, so its churn is not final: show the
  // last complete month.
  const lastFull = [...data.months].reverse().find((m) => !m.partial);
  return (
    <Card>
      <CardTitle hint={`${inr(s.total_users)} users`}>Players</CardTitle>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
        <Tile
          label="Active (30 days)"
          value={inr(s.active_30d)}
          sub={`7 days ${inr(s.active_7d)} · today ${inr(s.active_today)}`}
        />
        <Tile
          label="New users (30 days)"
          value={inr(s.signups_30d)}
          sub={`${inr(s.signups_30d_no_deposit)} not deposited yet`}
        />
        <Tile
          label="Users left"
          value={inr(s.lost)}
          tone={s.lost ? "neg" : undefined}
          sub={`${inr(s.lost_90d)} gone 90+ days`}
        />
        <Tile label="At risk" value={inr(s.at_risk)} tone={s.at_risk ? "warn" : undefined} sub="Quiet 14–30 days" />
        <Tile
          label={`Churn ${lastFull ? monthName(lastFull.month) : ""}`}
          value={pct(lastFull?.churn_rate)}
          sub={lastFull ? `${inr(lastFull.churned)} of ${inr(lastFull.retained + lastFull.churned)} stopped` : null}
        />
        <Tile
          label="Signup → deposit"
          value={pct(s.conversion_rate)}
          sub={`${inr(s.never_deposited)} never deposited`}
        />
        <Tile label="Opened app (24h)" value={inr(s.seen_24h)} sub={`7 days ${inr(s.seen_7d)}`} />
        <Tile
          label="Avg net per depositor"
          value={compactInr(s.avg_net_per_depositor)}
          sub={`Avg deposit ${compactInr(s.avg_deposit)}`}
        />
        <Tile label="Lifetime deposits" value={compactInr(s.lifetime_deposits)} />
        <Tile label="Lifetime withdrawals" value={compactInr(s.lifetime_withdrawals)} />
        <Tile
          label="Lifetime net"
          value={compactInr(s.lifetime_net)}
          tone={s.lifetime_net >= 0 ? "pos" : "neg"}
        />
        <Tile
          label="Net from users who left"
          value={compactInr(s.lost_net_value)}
          sub={`Banned ${inr(s.banned_users)} · agents ${inr(s.agents)}`}
        />
      </div>
    </Card>
  );
}

/* ----------------------------------------------------------- month by month */

function MonthByMonth({ months }: { months: BusinessMonth[] }) {
  // Hide leading empty months (before the book started).
  const firstUsed = months.findIndex((m) => m.active || m.signups || m.deposits_total);
  const shown = firstUsed === -1 ? months.slice(-1) : months.slice(firstUsed);
  const spansYears = new Set(shown.map((m) => m.month.slice(0, 4))).size > 1;
  const label = (m: BusinessMonth) => monthName(m.month, spansYears) + (m.partial ? "*" : "");

  return (
    <Card>
      <CardTitle hint="* this month, still running">Month by month</CardTitle>
      <div className="grid gap-4 lg:grid-cols-2">
        <Bars
          title="Active players"
          items={shown.map((m) => ({
            label: label(m),
            value: m.active,
            tip: [
              `${monthName(m.month, true)}: ${inr(m.active)} active`,
              `Growth ${pct(m.active_growth)}`,
              `New ${inr(m.new_active)} · returned ${inr(m.reactivated)} · stayed ${inr(m.retained)}`,
            ],
          }))}
        />
        <GainLoss months={shown} label={label} />
      </div>

      <div className="mt-4 -mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
        <table className="tabular w-full whitespace-nowrap min-w-[960px] text-right text-xs">
          <thead className="text-muted">
            <tr className="border-b border-border">
              {[
                "Month",
                "New users",
                "Active",
                "Growth",
                "New active",
                "Returned",
                "Left",
                "Churn",
                "Deposits",
                "Dep. growth",
                "Withdrawals",
                "Net",
                "1st deposits",
                "Avg / depositor",
              ].map((h, i) => (
                <th key={h} className={["py-2 pr-3 font-medium whitespace-nowrap", i === 0 ? "text-left" : ""].join(" ")}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {[...shown].reverse().map((m) => (
              <tr key={m.month} className="border-b border-border last:border-0">
                <td className="py-2 pr-3 text-left font-medium whitespace-nowrap text-text">
                  {monthName(m.month, true)}
                  {m.partial ? " *" : ""}
                </td>
                <td className="py-2 pr-3">
                  {inr(m.signups)} <Change value={m.signup_growth} />
                </td>
                <td className="py-2 pr-3 font-semibold text-text">{inr(m.active)}</td>
                <td className="py-2 pr-3">
                  <Change value={m.active_growth} />
                </td>
                <td className="py-2 pr-3">{inr(m.new_active)}</td>
                <td className="py-2 pr-3">{inr(m.reactivated)}</td>
                <td className="py-2 pr-3" style={{ color: m.churned ? "var(--neg)" : undefined }}>
                  {inr(m.churned)}
                </td>
                <td className="py-2 pr-3">{pct(m.churn_rate)}</td>
                <td className="py-2 pr-3 text-text">{compactInr(m.deposits_total)}</td>
                <td className="py-2 pr-3">
                  <Change value={m.deposit_growth} />
                </td>
                <td className="py-2 pr-3">{compactInr(m.withdrawals_total)}</td>
                <td className="py-2 pr-3 font-semibold" style={{ color: m.net >= 0 ? "var(--pos)" : "var(--neg)" }}>
                  {compactInr(m.net)}
                </td>
                <td className="py-2 pr-3">{inr(m.first_deposits)}</td>
                <td className="py-2 pr-3">{compactInr(m.avg_per_depositor)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

type BarItem = { label: string; value: number; tip: string[] };

/** Single-series column chart with a hover/tap tooltip. */
function Bars({ title, items, color = "var(--accent)" }: { title: string; items: BarItem[]; color?: string }) {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...items.map((i) => i.value));
  const active = hover === null ? null : items[hover];
  return (
    <div className="min-w-0">
      <div className="mb-2 flex min-w-0 items-baseline justify-between gap-2">
        <p className="text-sm font-medium text-text">{title}</p>
        <p className="truncate text-xs text-faint">{active ? active.tip[0] : `Peak ${inr(max)}`}</p>
      </div>
      <div className="relative" onMouseLeave={() => setHover(null)}>
        <div className="flex h-36 items-end gap-0.5 border-b border-border">
          {items.map((item, i) => (
            <button
              key={item.label + i}
              type="button"
              aria-label={item.tip.join(", ")}
              onMouseEnter={() => setHover(i)}
              onFocus={() => setHover(i)}
              onClick={() => setHover(i)}
              className="flex h-full min-w-0 flex-1 items-end"
            >
              <span
                className="block w-full rounded-t-[4px] transition-opacity"
                style={{
                  height: `${(item.value / max) * 100}%`,
                  minHeight: item.value ? 2 : 0,
                  background: color,
                  opacity: hover === null || hover === i ? 1 : 0.45,
                }}
              />
            </button>
          ))}
        </div>
        <div className="mt-1 flex gap-0.5">
          {items.map((item, i) => (
            <span key={item.label + i} className="min-w-0 flex-1 whitespace-nowrap text-center text-[10px] text-faint">
              {item.label}
            </span>
          ))}
        </div>
        {active ? (
          <div className="pointer-events-none mt-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs text-muted">
            {active.tip.slice(1).map((line) => (
              <p key={line}>{line}</p>
            ))}
          </div>
        ) : null}
      </div>
    </div>
  );
}

/** Players gained (new + returned) above the line, players who left below it. */
function GainLoss({ months, label }: { months: BusinessMonth[]; label: (m: BusinessMonth) => string }) {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...months.map((m) => Math.max(m.new_active + m.reactivated, m.churned)));
  const active = hover === null ? null : months[hover];
  return (
    <div className="min-w-0">
      <div className="mb-2 flex min-w-0 flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium text-text">Joined vs left</p>
        <span className="flex items-center gap-3 text-xs text-muted">
          <span className="flex items-center gap-1">
            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: "var(--pos)" }} /> New + returned
          </span>
          <span className="flex items-center gap-1">
            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: "var(--neg)" }} /> Left
          </span>
        </span>
      </div>
      <div onMouseLeave={() => setHover(null)}>
        <div className="flex h-36 gap-0.5">
          {months.map((m, i) => {
            const gained = m.new_active + m.reactivated;
            const dim = hover === null || hover === i ? 1 : 0.45;
            return (
              <button
                key={m.month}
                type="button"
                aria-label={`${monthName(m.month, true)}: ${gained} joined or returned, ${m.churned} left`}
                onMouseEnter={() => setHover(i)}
                onFocus={() => setHover(i)}
                onClick={() => setHover(i)}
                className="flex h-full min-w-0 flex-1 flex-col"
              >
                <span className="flex h-1/2 w-full items-end border-b border-border">
                  <span
                    className="block w-full rounded-t-[4px]"
                    style={{ height: `${(gained / max) * 100}%`, minHeight: gained ? 2 : 0, background: "var(--pos)", opacity: dim }}
                  />
                </span>
                <span className="flex h-1/2 w-full items-start">
                  <span
                    className="block w-full rounded-b-[4px]"
                    style={{ height: `${(m.churned / max) * 100}%`, minHeight: m.churned ? 2 : 0, background: "var(--neg)", opacity: dim }}
                  />
                </span>
              </button>
            );
          })}
        </div>
        <div className="mt-1 flex gap-0.5">
          {months.map((m) => (
            <span key={m.month} className="min-w-0 flex-1 truncate text-center text-[10px] text-faint">
              {label(m)}
            </span>
          ))}
        </div>
        {active ? (
          <div className="pointer-events-none mt-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs text-muted">
            <p className="font-medium text-text">{monthName(active.month, true)}</p>
            <p>
              New {inr(active.new_active)} · returned {inr(active.reactivated)} · left {inr(active.churned)} (churn{" "}
              {pct(active.churn_rate)})
            </p>
          </div>
        ) : null}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------- player lists */

type ListId = "lost" | "risk" | "top";

function PlayerLists({ data }: { data: Data }) {
  const navigate = useNavigate();
  const [list, setList] = useState<ListId>("lost");
  const rows: BusinessPlayer[] =
    list === "lost" ? data.lost_players : list === "risk" ? data.at_risk_players : data.top_players;
  const empty =
    list === "lost" ? "Nobody has left." : list === "risk" ? "Nobody is at risk." : "No deposits this month yet.";

  return (
    <Card>
      <CardTitle hint="Biggest players first">Players to call</CardTitle>
      <Segmented<ListId>
        value={list}
        onChange={setList}
        options={[
          { value: "lost", label: `Left (${inr(data.snapshot.lost)})` },
          { value: "risk", label: `At risk (${inr(data.snapshot.at_risk)})` },
          { value: "top", label: "Top this month" },
        ]}
      />
      {rows.length ? (
        <ul className="mt-3 min-w-0">
          {rows.map((p) => (
            <li key={p.id} className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0">
              <button
                type="button"
                onClick={() => navigate(`/users?search=${encodeURIComponent(p.play_id ?? p.phone ?? "")}`)}
                className="min-w-0 flex-1 text-left"
              >
                <p className="truncate text-sm font-medium text-text">
                  {p.name || "Player"} <span className="text-xs font-normal text-faint">{p.play_id}</span>
                </p>
                <p className="truncate text-xs text-faint">
                  {[
                    p.branch,
                    list === "top"
                      ? `${inr(p.month_deposits_count ?? 0)} deposits this month`
                      : p.days_inactive !== null
                        ? `last active ${p.days_inactive} days ago`
                        : null,
                    `${inr(p.deposits_count)} deposits lifetime`,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              </button>
              {p.phone ? (
                <a href={`tel:${p.phone}`} className="tabular hidden shrink-0 text-xs text-accent sm:block">
                  {p.phone}
                </a>
              ) : null}
              <div className="tabular shrink-0 text-right text-xs leading-snug">
                <span className="block font-semibold text-text">
                  {compactInr(list === "top" ? p.month_deposits : p.lifetime_deposits)}
                </span>
                <span className="block" style={{ color: p.lifetime_net >= 0 ? "var(--pos)" : "var(--neg)" }}>
                  net {compactInr(p.lifetime_net)}
                </span>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <p className="py-6 text-center text-sm text-muted">{empty}</p>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------------ cohorts */

function Cohorts({ data }: { data: Data }) {
  const width = Math.max(1, ...data.cohorts.map((c) => c.retention.length));
  return (
    <Card>
      <CardTitle hint="% of each month's signups active later">Signup cohorts</CardTitle>
      <div className="-mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
        <table className="tabular w-full whitespace-nowrap min-w-[480px] text-center text-xs">
          <thead className="text-muted">
            <tr>
              <th className="py-1.5 pr-2 text-left font-medium">Signed up</th>
              <th className="py-1.5 pr-2 font-medium">Users</th>
              <th className="py-1.5 pr-2 font-medium">Deposited</th>
              {Array.from({ length: width }, (_, k) => (
                <th key={k} className="py-1.5 font-medium">
                  M{k}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.cohorts.map((c) => (
              <tr key={c.month}>
                <td className="py-1 pr-2 text-left font-medium whitespace-nowrap text-text">{monthName(c.month, true)}</td>
                <td className="py-1 pr-2">{inr(c.size)}</td>
                <td className="py-1 pr-2">{pct(c.conversion_rate)}</td>
                {Array.from({ length: width }, (_, k) => {
                  const v = c.retention[k];
                  return (
                    <td key={k} className="p-0.5">
                      {v === undefined ? null : (
                        <span
                          className="block rounded-sm py-1 text-text"
                          style={{
                            background: `color-mix(in srgb, var(--accent) ${Math.round((v ?? 0) * 0.6)}%, transparent)`,
                          }}
                        >
                          {v === null ? "—" : `${Math.round(v)}%`}
                        </span>
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-2 text-xs text-faint">M0 = signup month, M1 = the month after, and so on.</p>
    </Card>
  );
}

/* --------------------------------------------------------------- peak hours */

function PeakHours({ hours }: { hours: number[] }) {
  const hourLabel = (h: number) => (h === 0 ? "12a" : h < 12 ? `${h}a` : h === 12 ? "12p" : `${h - 12}p`);
  return (
    <Card>
      <CardTitle hint="Last 30 days">Busiest hours</CardTitle>
      <Bars
        title="Deposit requests by hour"
        items={hours.map((n, h) => ({
          label: h % 6 === 0 ? hourLabel(h) : "",
          value: n,
          tip: [`${hourLabel(h)}–${hourLabel((h + 1) % 24)}: ${inr(n)} requests`],
        }))}
      />
    </Card>
  );
}

/* ------------------------------------------------------------------ branches */

function BranchTable({ data }: { data: Data }) {
  return (
    <Card>
      <CardTitle hint="This month">Branches</CardTitle>
      <div className="-mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
        <table className="tabular w-full whitespace-nowrap min-w-[640px] text-right text-xs">
          <thead className="text-muted">
            <tr className="border-b border-border">
              {["Branch", "Users", "New", "Active 30d", "Left", "Deposits", "Withdrawals", "Net"].map((h, i) => (
                <th key={h} className={["py-2 pr-3 font-medium", i === 0 ? "text-left" : ""].join(" ")}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.branches.map((b) => (
              <tr key={b.id} className="border-b border-border last:border-0">
                <td className="py-2 pr-3 text-left font-medium text-text">
                  {b.name}
                  {b.is_active ? "" : " (off)"}
                </td>
                <td className="py-2 pr-3">{inr(b.users)}</td>
                <td className="py-2 pr-3">{inr(b.signups_month)}</td>
                <td className="py-2 pr-3">{inr(b.active_30d)}</td>
                <td className="py-2 pr-3">{inr(b.lost)}</td>
                <td className="py-2 pr-3 text-text">{compactInr(b.deposits_month)}</td>
                <td className="py-2 pr-3">{compactInr(b.withdrawals_month)}</td>
                <td className="py-2 pr-3 font-semibold" style={{ color: b.net_month >= 0 ? "var(--pos)" : "var(--neg)" }}>
                  {compactInr(b.net_month)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
