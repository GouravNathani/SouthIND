import type { HTMLAttributes, ReactNode } from "react";

export default function Card({
  children,
  className = "",
  padded = true,
  ...rest
}: HTMLAttributes<HTMLDivElement> & { children: ReactNode; padded?: boolean }) {
  return (
    <div
      {...rest}
      className={[
        "min-w-0 rounded-lg border border-border bg-surface shadow-sm",
        padded ? "p-4 sm:p-5" : "",
        className,
      ].join(" ")}
    >
      {children}
    </div>
  );
}

export function CardTitle({ children, hint }: { children: ReactNode; hint?: ReactNode }) {
  return (
    <div className="mb-3 flex min-w-0 items-baseline justify-between gap-3">
      <h2 className="truncate text-[13px] font-semibold tracking-wide text-muted uppercase">
        {children}
      </h2>
      {hint ? <span className="shrink-0 text-xs text-faint">{hint}</span> : null}
    </div>
  );
}
