import { Children, type ButtonHTMLAttributes, type ReactNode } from "react";

type Variant = "primary" | "secondary" | "ghost" | "danger";
type Size = "sm" | "md" | "lg";

const VARIANT: Record<Variant, string> = {
  primary: "bg-accent text-on-accent hover:bg-accent-hover",
  secondary: "border border-border bg-surface text-text hover:border-border-strong",
  ghost: "text-muted hover:bg-surface hover:text-text",
  // A tinted fill rather than solid --neg: white on the dark skins' bright red is
  // under 3:1, while --neg on its own 14% tint clears 4.5:1 in every skin.
  danger: "border border-neg/35 bg-neg/14 text-neg hover:bg-neg/22",
};

const SIZE: Record<Size, string> = {
  sm: "h-9 px-3.5 text-[13px]",
  md: "h-11 px-5 text-sm",
  lg: "h-12 px-6 text-[15px]",
};

/**
 * Icons have to be direct flex children of the button: Tailwind's preflight
 * makes every <svg> display:block, so an icon sharing a wrapper with its label
 * lands on a line of its own. Only runs of text get the truncating span.
 */
const layoutChildren = (children: ReactNode) => {
  const out: ReactNode[] = [];
  let text = "";
  const flush = () => {
    if (!text.trim()) {
      text = "";
      return;
    }
    out.push(
      <span key={`text-${out.length}`} className="min-w-0 truncate">
        {text.trim()}
      </span>
    );
    text = "";
  };
  Children.toArray(children).forEach((child) => {
    if (typeof child === "string" || typeof child === "number") {
      text += String(child);
    } else {
      flush();
      out.push(child);
    }
  });
  flush();
  return out;
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
        "inline-flex min-w-0 shrink-0 items-center justify-center gap-2 rounded-full font-semibold whitespace-nowrap",
        "transition-[background,border-color,opacity,transform] duration-150 active:scale-[0.98]",
        "disabled:cursor-not-allowed disabled:opacity-55 disabled:active:scale-100 [&_svg]:shrink-0",
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
          className="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      ) : null}
      {layoutChildren(children)}
    </button>
  );
}
