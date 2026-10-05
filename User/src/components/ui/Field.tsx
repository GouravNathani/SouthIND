import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from "react";
import { IconChevronDown } from "@/components/icons";

// One focus treatment for every control: the accent border plus a soft halo.
// The global :focus-visible outline sits in @layer base, so focus:outline-none
// here wins and the two never stack into a double ring.
const CONTROL =
  "w-full min-w-0 rounded-md border border-border bg-surface-2 px-3.5 text-sm text-text " +
  "placeholder:text-faint focus:border-accent focus:shadow-[0_0_0_3px_var(--accent-soft)] focus:outline-none " +
  "transition-[border-color,box-shadow] duration-150 disabled:opacity-60";

// h-11 matches the md Button, so a field and a button in one row line up.
const SINGLE_LINE = "h-11";

function Shell({
  label,
  hint,
  error,
  children,
}: {
  label?: string;
  hint?: ReactNode;
  error?: string | null;
  children: ReactNode;
}) {
  return (
    <label className="block min-w-0">
      {label ? <span className="mb-1.5 block text-xs font-medium text-muted">{label}</span> : null}
      {children}
      {error ? (
        <span className="mt-1.5 block text-xs text-neg">{error}</span>
      ) : hint ? (
        <span className="mt-1.5 block text-xs text-faint">{hint}</span>
      ) : null}
    </label>
  );
}

export function Input({
  label,
  hint,
  error,
  className = "",
  ...rest
}: InputHTMLAttributes<HTMLInputElement> & {
  label?: string;
  hint?: ReactNode;
  error?: string | null;
}) {
  return (
    <Shell label={label} hint={hint} error={error}>
      <input
        {...rest}
        className={[CONTROL, SINGLE_LINE, error ? "border-neg" : "", className].join(" ")}
      />
    </Shell>
  );
}

export function Textarea({
  label,
  hint,
  error,
  className = "",
  ...rest
}: TextareaHTMLAttributes<HTMLTextAreaElement> & {
  label?: string;
  hint?: ReactNode;
  error?: string | null;
}) {
  return (
    <Shell label={label} hint={hint} error={error}>
      <textarea {...rest} className={[CONTROL, "resize-none py-3", className].join(" ")} />
    </Shell>
  );
}

/** Native <select> for long or data-driven lists, drawn with the app's own chevron. */
export function Select({
  label,
  hint,
  error,
  className = "",
  children,
  ...rest
}: SelectHTMLAttributes<HTMLSelectElement> & {
  label?: string;
  hint?: ReactNode;
  error?: string | null;
}) {
  return (
    <Shell label={label} hint={hint} error={error}>
      <span className="relative block min-w-0">
        <select
          {...rest}
          className={[CONTROL, SINGLE_LINE, "cursor-pointer appearance-none truncate pr-10", className].join(" ")}
        >
          {children}
        </select>
        <span className="pointer-events-none absolute inset-y-0 right-3.5 flex items-center text-muted">
          <IconChevronDown size={16} />
        </span>
      </span>
    </Shell>
  );
}
