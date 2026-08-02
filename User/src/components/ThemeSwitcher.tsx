import { SKINS, useTheme } from "@/theme/ThemeProvider";

/** Skin picker + light/dark toggle. Lives on the Account page and nowhere else. */
export default function ThemeSwitcher() {
  const { skin, mode, setSkin, toggleMode } = useTheme();

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-3 gap-2">
        {SKINS.map((option) => {
          const active = option.id === skin;
          return (
            <button
              key={option.id}
              type="button"
              onClick={() => setSkin(option.id)}
              aria-pressed={active}
              className="rounded-md border px-3 py-2 text-left transition-colors"
              style={{
                borderColor: active ? "var(--accent)" : "var(--border)",
                background: active ? "var(--accent-soft)" : "var(--surface)",
              }}
            >
              <span className="block text-sm font-semibold text-text">{option.label}</span>
              <span className="block text-xs text-muted">{option.hint}</span>
            </button>
          );
        })}
      </div>

      <button
        type="button"
        onClick={toggleMode}
        className="self-start rounded-full border border-border bg-surface px-4 py-2 text-sm text-text"
      >
        {mode === "dark" ? "Switch to light" : "Switch to dark"}
      </button>
    </div>
  );
}
