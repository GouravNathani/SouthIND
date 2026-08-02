import type { ButtonHTMLAttributes, ReactNode } from "react";

type Variant = "primary" | "secondary" | "ghost" | "danger";
type Size = "sm" | "md" | "lg";

const VARIANT: Record<Variant, string> = {
  primary: "bg-accent text-on-accent hover:bg-accent-hover",
  secondary: "border border-border bg-surface text-text hover:border-border-strong",
  ghost: "text-muted hover:bg-surface hover:text-text",
  danger: "bg-neg text-white hover:opacity-90",
};

const SIZE: Record<Size, string> = {
  sm: "h-9 px-3.5 text-[13px]",
  md: "h-11 px-5 text-sm",
  lg: "h-12 px-6 text-[15px]",
};

export default function Button({
  variant = "primary",
  size = "md",
  block,
  loading,
  children,
  className = "",
  disabled,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant;
  size?: Size;
  block?: boolean;
  loading?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      {...rest}
      disabled={disabled || loading}
      className={[
        "inline-flex shrink-0 items-center justify-center gap-2 rounded-full font-semibold",
        "transition-[background,border-color,opacity,transform] duration-150 active:scale-[0.98]",
        "disabled:cursor-not-allowed disabled:opacity-55 disabled:active:scale-100",
        VARIANT[variant],
        SIZE[size],
        block ? "w-full" : "",
        className,
      ].join(" ")}
      style={{ transitionTimingFunction: "var(--ease)" }}
    >
      {loading ? (
        <span
          aria-hidden
          className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      ) : null}
      <span className="truncate">{children}</span>
    </button>
  );
}
