import { useEffect, useState } from "react";
import { motion } from "motion/react";
import Dashboard from "./Dashboard";

const THEMES = [
  { key: "midnight", label: "A · Midnight", dot: "#00e5a0", note: "Deep dark + neon" },
  { key: "temple", label: "B · Temple", dot: "#d4af37", note: "South Indian heritage" },
  { key: "ledger", label: "C · Ledger", dot: "#3b82f6", note: "Clean fintech" },
] as const;

const DEVICES = [
  { key: "desktop", label: "Desktop" },
  { key: "tablet", label: "Tablet" },
  { key: "mobile", label: "Mobile" },
] as const;

type ThemeKey = (typeof THEMES)[number]["key"];
type DeviceKey = (typeof DEVICES)[number]["key"];

function Segmented<T extends string>({
  items, value, onChange, layoutId,
}: {
  items: readonly { key: T; label: string; dot?: string }[];
  value: T;
  onChange: (v: T) => void;
  layoutId: string;
}) {
  return (
    <div className="seg">
      {items.map((it) => (
        <button key={it.key} className="seg-btn" data-active={value === it.key} onClick={() => onChange(it.key)}>
          {value === it.key && (
            <motion.span className="seg-pill" layoutId={layoutId}
              transition={{ type: "spring", stiffness: 400, damping: 34 }} />
          )}
          {it.dot && <span className="seg-dot" style={{ background: it.dot }} />}
          {it.label}
        </button>
      ))}
    </div>
  );
}

/* Initial state comes from the query string so every combination has a shareable
   link — ?theme=temple&device=mobile&mode=light */
function fromUrl<T extends string>(param: string, allowed: readonly T[], fallback: T): T {
  const v = new URLSearchParams(window.location.search).get(param) as T | null;
  return v && allowed.includes(v) ? v : fallback;
}

export default function App() {
  const [theme, setTheme] = useState<ThemeKey>(() =>
    fromUrl("theme", THEMES.map((t) => t.key), "midnight"));
  const [device, setDevice] = useState<DeviceKey>(() =>
    fromUrl("device", DEVICES.map((d) => d.key), "desktop"));
  const [mode, setMode] = useState<"dark" | "light">(() =>
    fromUrl("mode", ["dark", "light"] as const, "dark"));

  // Keep the URL in step with the switcher without adding history entries.
  useEffect(() => {
    const q = new URLSearchParams({ theme, device, mode });
    window.history.replaceState(null, "", `?${q}`);
  }, [theme, device, mode]);

  const active = THEMES.find((t) => t.key === theme)!;

  return (
    <div className="preview">
      <div className="preview-bar">
        <div className="preview-brand">SouthIND <span>theme preview</span></div>

        <span className="preview-label">Theme</span>
        <Segmented items={THEMES} value={theme} onChange={setTheme} layoutId="theme-pill" />

        <span className="preview-label">Screen</span>
        <Segmented items={DEVICES} value={device} onChange={setDevice} layoutId="device-pill" />

        <span className="preview-label">Mode</span>
        <Segmented
          items={[{ key: "dark" as const, label: "Dark" }, { key: "light" as const, label: "Light" }]}
          value={mode}
          onChange={setMode}
          layoutId="mode-pill"
        />

        <div className="preview-note">{active.note} · {mode}</div>
      </div>

      <div className="preview-stage">
        <div className="frame" data-device={device} data-theme={theme} data-mode={mode}>
          {/* Re-keying replays the entrance animations on every switch, so each
              variant is judged with its motion, not as a static screenshot. */}
          <Dashboard key={`${theme}-${mode}`} />
        </div>
      </div>
    </div>
  );
}
