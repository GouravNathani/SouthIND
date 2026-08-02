import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

export const SKINS = [
  { id: "midnight", label: "Midnight", hint: "Emerald neon" },
  { id: "temple", label: "Temple", hint: "South Indian gold" },
  { id: "ledger", label: "Ledger", hint: "Clean fintech" },
] as const;

export type Skin = (typeof SKINS)[number]["id"];
export type Mode = "dark" | "light";

const SKIN_KEY = "sind_admin_theme";
const MODE_KEY = "sind_admin_mode";

// Matches the theme_color the browser chrome should show for each skin/mode.
const THEME_COLOR: Record<Skin, Record<Mode, string>> = {
  midnight: { dark: "#07080b", light: "#f3f7f5" },
  temple: { dark: "#170d0a", light: "#faf4e8" },
  ledger: { dark: "#0d1117", light: "#f6f8fa" },
};

const isSkin = (value: string | null): value is Skin =>
  SKINS.some((skin) => skin.id === value);

const readSkin = (): Skin => {
  try {
    const stored = localStorage.getItem(SKIN_KEY);
    if (isSkin(stored)) return stored;
  } catch {
    /* private mode / storage disabled — fall through to the default */
  }
  return "midnight";
};

const readMode = (): Mode => {
  try {
    const stored = localStorage.getItem(MODE_KEY);
    if (stored === "dark" || stored === "light") return stored;
  } catch {
    /* ignore */
  }
  return window.matchMedia?.("(prefers-color-scheme: light)").matches ? "light" : "dark";
};

type ThemeContextValue = {
  skin: Skin;
  mode: Mode;
  setSkin: (skin: Skin) => void;
  setMode: (mode: Mode) => void;
  toggleMode: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [skin, setSkinState] = useState<Skin>(readSkin);
  const [mode, setModeState] = useState<Mode>(readMode);

  useEffect(() => {
    const root = document.documentElement;
    root.dataset.theme = skin;
    root.dataset.mode = mode;
    root.style.colorScheme = mode;

    const meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (meta) meta.content = THEME_COLOR[skin][mode];

    try {
      localStorage.setItem(SKIN_KEY, skin);
      localStorage.setItem(MODE_KEY, mode);
    } catch {
      /* ignore — the choice simply won't survive a reload */
    }
  }, [skin, mode]);

  const setSkin = useCallback((next: Skin) => setSkinState(next), []);
  const setMode = useCallback((next: Mode) => setModeState(next), []);
  const toggleMode = useCallback(
    () => setModeState((current) => (current === "dark" ? "light" : "dark")),
    []
  );

  const value = useMemo(
    () => ({ skin, mode, setSkin, setMode, toggleMode }),
    [skin, mode, setSkin, setMode, toggleMode]
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  const context = useContext(ThemeContext);
  if (!context) throw new Error("useTheme must be used inside <ThemeProvider>");
  return context;
}
