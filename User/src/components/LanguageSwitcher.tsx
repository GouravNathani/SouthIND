import { useTranslation } from "react-i18next";
import { SUPPORTED_LANGUAGES } from "@/i18n";

/**
 * Compact language selector. Persists via i18next's localStorage detector
 * (see src/i18n), so the pick survives reloads and applies app-wide.
 */
export default function LanguageSwitcher({ className = "" }: { className?: string }) {
  const { i18n } = useTranslation();
  const current = i18n.resolvedLanguage ?? i18n.language ?? "en";

  return (
    <label className={`inline-flex min-w-0 items-center gap-2 ${className}`}>
      <span className="sr-only">Language</span>
      <select
        value={current}
        onChange={(event) => void i18n.changeLanguage(event.target.value)}
        aria-label="Select language"
        className="max-w-[9rem] truncate rounded-full border border-border bg-surface px-3 py-1.5 text-xs font-medium text-text outline-none focus:border-accent"
      >
        {SUPPORTED_LANGUAGES.map((lang) => (
          <option key={lang.code} value={lang.code}>
            {lang.native}
          </option>
        ))}
      </select>
    </label>
  );
}
