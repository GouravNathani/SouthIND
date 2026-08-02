import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from "react";

const CONTROL =
  "w-full min-w-0 rounded-md border border-border bg-surface-2 px-3.5 py-3 text-sm text-text " +
  "placeholder:text-faint focus:border-accent focus:outline-none disabled:opacity-60";

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
        className={[CONTROL, error ? "border-neg" : "", className].join(" ")}
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
      <textarea {...rest} className={[CONTROL, "resize-none", className].join(" ")} />
    </Shell>
  );
}

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
      <select {...rest} className={[CONTROL, className].join(" ")}>
        {children}
      </select>
    </Shell>
  );
}
