import { useTranslation } from "react-i18next";
import { SUPPORTED_LANGUAGES } from "@/i18n";
import { IconChevronDown } from "@/components/icons";

/**
 * Compact language selector. Persists via i18next's localStorage detector
 * (see src/i18n), so the pick survives reloads and applies app-wide.
 */
export default function LanguageSwitcher({ className = "" }: { className?: string }) {
  const { i18n } = useTranslation();
  const current = i18n.resolvedLanguage ?? i18n.language ?? "en";

  return (
    <label className={`relative inline-flex min-w-0 items-center ${className}`}>
      <span className="sr-only">Language</span>
      <select
        value={current}
        onChange={(event) => void i18n.changeLanguage(event.target.value)}
        aria-label="Select language"
        className="h-9 max-w-[10rem] cursor-pointer appearance-none truncate rounded-full border border-border bg-surface pr-8 pl-3.5 text-[13px] font-medium text-text outline-none focus:border-accent"
      >
        {SUPPORTED_LANGUAGES.map((lang) => (
          <option key={lang.code} value={lang.code}>
            {lang.native}
          </option>
        ))}
      </select>
      <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-muted">
        <IconChevronDown size={14} />
      </span>
    </label>
  );
}
