import { useEffect, useState } from "react";
import { AnimatePresence, motion, useMotionValue, useSpring, useTransform } from "motion/react";
import {
  IconBell, IconChat, IconCheck, IconDeposit, IconGlobe, IconHistory,
  IconHome, IconSearch, IconSettings, IconTrophy, IconWithdraw,
} from "./icons";

/* ── demo data ──────────────────────────────────────────────────────────── */

const NAV = [
  { key: "home", label: "Dashboard", Icon: IconHome },
  { key: "deposit", label: "Deposit", Icon: IconDeposit },
  { key: "withdraw", label: "Withdraw", Icon: IconWithdraw },
  { key: "history", label: "History", Icon: IconHistory },
  { key: "winners", label: "Winners", Icon: IconTrophy },
  { key: "chat", label: "Support", Icon: IconChat, badge: 2 },
];

const TABS = NAV.slice(0, 5);

/* The hero always resolves to a DARK, accent-tinted gradient so white text is
   legible in every theme — including Ledger's light mode, where it reads as a
   deliberate dark hero card against the pale page rather than an accident. */
const hero = (tint: string) =>
  `linear-gradient(118deg,
     color-mix(in srgb, ${tint} 80%, #0a0b0d) 0%,
     color-mix(in srgb, ${tint} 34%, #0a0b0d) 58%,
     #101216 100%)`;

const SLIDES = [
  {
    tag: "Limited time",
    title: "Get up to 10% extra on every deposit",
    body: "Instant credit, 24×7 approvals. Minimum ₹500.",
    grad: hero("var(--accent)"),
  },
  {
    tag: "Fast payouts",
    title: "Withdrawals settled in under 10 minutes",
    body: "Straight to your bank or UPI, any day of the week.",
    grad: hero("var(--accent-2)"),
  },
  {
    tag: "Refer & earn",
    title: "Invite friends, earn lifetime commission",
    body: "Track your whole team from the Account page.",
    grad: hero("color-mix(in srgb, var(--accent) 55%, var(--accent-2))"),
  },
];

const TX = [
  { id: "D-48122", name: "Deposit · HDFC ****4417", meta: "UPI · 2 min ago", amt: 25000, dir: "in", state: ["Approved", "pos"] },
  { id: "W-19043", name: "Withdrawal · SBI ****9902", meta: "IMPS · 41 min ago", amt: 12500, dir: "out", state: ["Processing", "warn"] },
  { id: "D-48097", name: "Deposit · ICICI ****1180", meta: "UPI · 3 h ago", amt: 5000, dir: "in", state: ["Approved", "pos"] },
  { id: "W-19011", name: "Withdrawal · Axis ****6620", meta: "NEFT · Yesterday", amt: 30000, dir: "out", state: ["Approved", "pos"] },
  { id: "D-47980", name: "Deposit · Paytm ****3345", meta: "UPI · Yesterday", amt: 1500, dir: "in", state: ["Rejected", "neg"] },
] as const;

const WINNERS = [
  { rank: 1, name: "Karthik R.", amt: 184200 },
  { rank: 2, name: "Meenakshi S.", amt: 121750 },
  { rank: 3, name: "Arjun P.", amt: 96400 },
  { rank: 4, name: "Divya N.", amt: 71900 },
  { rank: 5, name: "Suresh K.", amt: 58300 },
];

const inr = (n: number) => n.toLocaleString("en-IN");

/* A rejected transaction never moved money, so it must not render as a green
   credit — it goes muted and struck instead of signed. */
function Amount({ dir, amt, status }: { dir: string; amt: number; status: string }) {
  const dead = status === "Rejected";
  return (
    <span className="tx-amt" data-dir={dead ? "dead" : dir}>
      {dead ? "" : dir === "in" ? "+" : "−"}₹{inr(amt)}
    </span>
  );
}

/* ── animated number ────────────────────────────────────────────────────── */

function Counter({ value, delay = 0 }: { value: number; delay?: number }) {
  // Deliberately NOT from zero. A money panel that flashes ₹0 on every load
  // reads as "your balance is gone" — start close and tally the last stretch.
  const from = Math.round(value * 0.88);
  const mv = useMotionValue(from);
  const spring = useSpring(mv, { stiffness: 55, damping: 20, mass: 0.9 });
  const text = useTransform(spring, (v) => inr(Math.round(v)));

  useEffect(() => {
    const t = setTimeout(() => mv.set(value), delay);
    return () => clearTimeout(t);
  }, [value, delay, mv]);

  return <motion.span>{text}</motion.span>;
}

/* ── shared motion presets ──────────────────────────────────────────────── */

const rise = {
  hidden: { opacity: 0, y: 14 },
  show: { opacity: 1, y: 0, transition: { duration: 0.34, ease: [0.22, 1, 0.36, 1] as const } },
};

const stagger = { show: { transition: { staggerChildren: 0.045, delayChildren: 0.06 } } };

const press = { whileTap: { scale: 0.972 }, transition: { duration: 0.12 } };

/* ── screen ─────────────────────────────────────────────────────────────── */

export default function Dashboard() {
  const [active, setActive] = useState("home");
  const [railOpen, setRailOpen] = useState(false);
  const [slide, setSlide] = useState(0);

  useEffect(() => {
    const t = setInterval(() => setSlide((s) => (s + 1) % SLIDES.length), 4200);
    return () => clearInterval(t);
  }, []);

  return (
    <div className="app">
      {/* ── icon rail (desktop) ── */}
      <motion.nav
        className="rail"
        initial={false}
        animate={{ width: railOpen ? "var(--rail-w-open)" : "var(--rail-w)" }}
        transition={{ type: "spring", stiffness: 320, damping: 34 }}
        onMouseEnter={() => setRailOpen(true)}
        onMouseLeave={() => setRailOpen(false)}
      >
        <div className="rail-logo">
          <div className="rail-mark">S</div>
          <AnimatePresence>
            {railOpen && (
              <motion.div
                className="rail-word"
                initial={{ opacity: 0, x: -6 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -6 }}
                transition={{ duration: 0.18 }}
              >
                South<em>IND</em>
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {NAV.map(({ key, label, Icon, badge }) => (
          <button
            key={key}
            className="rail-item"
            data-active={active === key}
            onClick={() => setActive(key)}
            title={label}
          >
            {active === key && (
              <motion.span className="rail-active-bg" layoutId="rail-active"
                transition={{ type: "spring", stiffness: 420, damping: 38 }} />
            )}
            <Icon />
            <AnimatePresence>
              {railOpen && (
                <motion.span
                  className="rail-label"
                  initial={{ opacity: 0, x: -6 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: -6 }}
                  transition={{ duration: 0.16 }}
                >
                  {label}
                </motion.span>
              )}
            </AnimatePresence>
            {badge && railOpen && <span className="rail-badge">{badge}</span>}
          </button>
        ))}

        <div className="rail-spacer" />

        <button className="rail-item" title="Settings">
          <IconSettings />
          <AnimatePresence>
            {railOpen && (
              <motion.span className="rail-label"
                initial={{ opacity: 0, x: -6 }} animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -6 }} transition={{ duration: 0.16 }}>
                Settings
              </motion.span>
            )}
          </AnimatePresence>
        </button>
      </motion.nav>

      {/* ── main column ── */}
      <div className="main">
        <header className="topbar">
          <div>
            <div className="topbar-title">Dashboard</div>
            <div className="topbar-sub">Welcome back, Ravi · Chennai Branch</div>
          </div>

          <div className="topbar-right">
            <div className="balance-chip">
              <small>Balance</small>
              <b>₹<Counter value={48250} delay={220} /></b>
            </div>
            <button className="icon-btn" title="Search"><IconSearch /></button>
            <button className="icon-btn" title="Notifications"><IconBell /><span className="dot" /></button>
            <button className="icon-btn" title="Language"><IconGlobe /></button>
            <button className="avatar" title="Ravi Kumar">RK</button>
          </div>
        </header>

        <motion.main className="content" variants={stagger} initial="hidden" animate="show">
          {/* ── left column ── */}
          <div className="col">
            <motion.div className="banner" variants={rise}>
              <AnimatePresence mode="popLayout">
                <motion.div
                  key={slide}
                  className="banner-slide"
                  style={{ background: SLIDES[slide].grad, color: "#fff" }}
                  initial={{ opacity: 0, scale: 1.04 }}
                  animate={{ opacity: 1, scale: 1 }}
                  exit={{ opacity: 0, scale: 0.99 }}
                  transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
                >
                  <span className="banner-tag">{SLIDES[slide].tag}</span>
                  <h2>{SLIDES[slide].title}</h2>
                  <p>{SLIDES[slide].body}</p>
                </motion.div>
              </AnimatePresence>
              <div className="banner-dots">
                {SLIDES.map((_, i) => (
                  <button key={i} className="banner-dot" data-active={i === slide}
                    onClick={() => setSlide(i)} aria-label={`Slide ${i + 1}`} />
                ))}
              </div>
            </motion.div>

            <motion.div className="stats" variants={rise}>
              {[
                { label: "Deposited (30d)", val: 184500, tone: "pos" },
                { label: "Withdrawn (30d)", val: 136200, tone: undefined },
                { label: "Net position", val: 48300, tone: "pos" },
              ].map((s, i) => (
                <div className="card stat" key={s.label}>
                  <div className="eyebrow">{s.label}</div>
                  <div className="stat-val" data-tone={s.tone}>
                    ₹<Counter value={s.val} delay={340 + i * 110} />
                  </div>
                </div>
              ))}
            </motion.div>

            <motion.section className="card b-activity" variants={rise}>
              <div className="card-head">
                <h3>Recent activity</h3>
                <div className="spacer" />
                <button className="link-btn">View all →</button>
              </div>

              {/* desktop: real table */}
              <table className="tbl">
                <thead>
                  <tr><th>Transaction</th><th>Reference</th><th>Status</th><th>Amount</th></tr>
                </thead>
                <tbody>
                  {TX.map((t) => (
                    <tr key={t.id}>
                      <td>
                        <div className="tx-who">
                          <span className="tx-icon" data-dir={t.dir}>
                            {t.dir === "in" ? <IconDeposit size={17} /> : <IconWithdraw size={17} />}
                          </span>
                          <span>
                            <div className="tx-name">{t.name}</div>
                            <div className="tx-meta">{t.meta}</div>
                          </span>
                        </div>
                      </td>
                      <td style={{ fontFamily: "var(--font-mono)", color: "var(--text-muted)", fontSize: 12 }}>{t.id}</td>
                      <td><span className="badge" data-tone={t.state[1]}>{t.state[0]}</span></td>
                      <td><Amount dir={t.dir} amt={t.amt} status={t.state[0]} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* mobile: same rows as cards */}
              <div className="tx-cards">
                {TX.map((t) => (
                  <div className="tx-card" key={t.id}>
                    <span className="tx-icon" data-dir={t.dir}>
                      {t.dir === "in" ? <IconDeposit size={17} /> : <IconWithdraw size={17} />}
                    </span>
                    <span className="grow">
                      <div className="tx-name">{t.name}</div>
                      <div className="tx-meta">{t.meta}</div>
                    </span>
                    <span className="right">
                      <Amount dir={t.dir} amt={t.amt} status={t.state[0]} />
                      <div style={{ marginTop: 4 }}><span className="badge" data-tone={t.state[1]}>{t.state[0]}</span></div>
                    </span>
                  </div>
                ))}
              </div>
            </motion.section>
          </div>

          {/* ── right column ── */}
          <div className="col">
            <motion.section className="card balance-card" variants={rise}>
              <div className="eyebrow">Available balance</div>
              <div className="balance-amount">
                <span className="cur">₹</span><Counter value={48250} delay={160} />
              </div>
              <div className="balance-delta">
                <IconCheck size={14} />
                <b>+₹12,400</b> <span>this week</span>
              </div>

              <div className="balance-actions">
                <motion.button className="btn btn-primary btn-lg" {...press}>
                  <span className="btn-stack">Deposit<small>up to 10% extra</small></span>
                </motion.button>
                <motion.button className="btn btn-ghost btn-lg" {...press}>
                  <span className="btn-stack">Withdraw<small>up to 5% extra</small></span>
                </motion.button>
              </div>
            </motion.section>

            <motion.section className="card b-winners" variants={rise}>
              <div className="card-head">
                <IconTrophy size={17} />
                <h3>Top winners this week</h3>
              </div>
              {WINNERS.map((w, i) => (
                <motion.div
                  className="winner-row"
                  key={w.rank}
                  initial={{ opacity: 0, x: 12 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: 0.45 + i * 0.06, duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                >
                  <span className="winner-rank" data-top={w.rank <= 3 ? w.rank : undefined}>{w.rank}</span>
                  <span className="winner-name">{w.name}</span>
                  <span className="winner-amt">₹{inr(w.amt)}</span>
                </motion.div>
              ))}
            </motion.section>

            <motion.section className="card card-pad b-help" variants={rise}
              style={{ display: "flex", alignItems: "center", gap: 14 }}>
              <span className="tx-icon" style={{ color: "var(--accent)" }}><IconChat size={18} /></span>
              <span style={{ flex: 1 }}>
                <div style={{ fontSize: 13, fontWeight: 600 }}>Need help?</div>
                <div style={{ fontSize: 11.5, color: "var(--text-faint)", marginTop: 2 }}>
                  Support replies in ~2 min
                </div>
              </span>
              <motion.button className="btn btn-ghost" style={{ height: 38, padding: "0 16px" }} {...press}>
                Chat
              </motion.button>
            </motion.section>
          </div>
        </motion.main>

        {/* ── mobile-only chrome ── */}
        <button className="fab" title="Support chat"><IconChat size={23} /></button>

        <nav className="tabbar">
          <div className="tabbar-inner">
            {TABS.map(({ key, label, Icon }) => (
              <button key={key} className="tab" data-active={active === key} onClick={() => setActive(key)}>
                {active === key && (
                  <motion.span className="tab-pill" layoutId="tab-active"
                    transition={{ type: "spring", stiffness: 420, damping: 38 }} />
                )}
                <Icon size={21} />
                {label}
              </button>
            ))}
          </div>
        </nav>
      </div>
    </div>
  );
}
