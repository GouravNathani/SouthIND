import { Fragment, useId, type KeyboardEvent, type ReactNode } from "react";

export type SegmentedOption<T extends string> = { value: T; label: ReactNode };

/**
 * A row of mutually exclusive choices, for the short fixed lists (two to four
 * options) that a dropdown only hides behind an extra tap. Long or data-driven
 * lists stay on Select. Same height, radius and focus halo as the text fields,
 * so it sits in a form grid without looking like a different family.
 */
export default function Segmented<T extends string>({
  label,
  value,
  onChange,
  options,
  hint,
  disabled,
  className = "",
  breakAfter,
}: {
  label?: string;
  value: T;
  onChange: (value: T) => void;
  options: readonly SegmentedOption<T>[];
  hint?: ReactNode;
  disabled?: boolean;
  className?: string;
  /**
   * For lists too long for one row on a phone: while the group is narrower
   * than 23rem, a second row starts after this many options instead of every
   * label being cut to "Pe…". One row again once it fits.
   */
  breakAfter?: number;
}) {
  const labelId = useId();
  const activeIndex = options.findIndex((option) => option.value === value);
  // The one tab stop in the group: the chosen option, or the first if none is.
  const tabStop = activeIndex === -1 ? 0 : activeIndex;

  // Radio-group keyboard model: arrows move the choice, Tab leaves the group.
  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const forward = event.key === "ArrowRight" || event.key === "ArrowDown";
    const back = event.key === "ArrowLeft" || event.key === "ArrowUp";
    if (!forward && !back) return;
    event.preventDefault();
    const nextIndex = (index + (forward ? 1 : -1) + options.length) % options.length;
    onChange(options[nextIndex].value);
    event.currentTarget.parentElement
      ?.querySelectorAll<HTMLButtonElement>("[role=radio]")
      [nextIndex]?.focus();
  };

  const wraps = breakAfter !== undefined && breakAfter > 0 && breakAfter < options.length;

  return (
    <div className={["min-w-0", wraps ? "@container" : "", className].join(" ")}>
      {label ? (
        <span id={labelId} className="mb-1.5 block text-xs font-medium text-muted">
          {label}
        </span>
      ) : null}
      <div
        role="radiogroup"
        aria-labelledby={label ? labelId : undefined}
        className={[
          "flex min-w-0 gap-1 rounded-md border border-border bg-surface-2 p-1",
          // Wrapping rows are 34px each, the same as a button in the 44px single row.
          wraps ? "flex-wrap gap-y-0.5 @min-[23rem]:flex-nowrap" : "h-11",
          disabled ? "opacity-60" : "",
        ].join(" ")}
      >
        {options.map((option, index) => {
          const active = option.value === value;
          return (
            <Fragment key={option.value}>
              {wraps && index === breakAfter ? (
                <span aria-hidden className="basis-full @min-[23rem]:hidden" />
              ) : null}
              <button
                type="button"
                role="radio"
                aria-checked={active}
                tabIndex={index === tabStop ? 0 : -1}
                disabled={disabled}
                onClick={() => onChange(option.value)}
                onKeyDown={(event) => onKeyDown(event, index)}
                className={[
                  // Content-sized on phones so "Approved" is never cut to "Approv…" beside a
                  // short "All"; equal widths from sm up, where there is room for both.
                  // A wrapping group stays content-sized: equal widths are what overflow.
                  "min-w-0 flex-auto truncate rounded-sm px-2 text-[13px] font-medium whitespace-nowrap",
                  wraps ? "h-[34px]" : "sm:flex-1",
                  "transition-colors duration-150 focus-visible:outline-offset-0 disabled:cursor-not-allowed",
                  active ? "bg-accent-soft text-accent shadow-[inset_0_0_0_1px_var(--accent-line)]" : "text-muted hover:text-text",
                ].join(" ")}
              >
                {option.label}
              </button>
            </Fragment>
          );
        })}
      </div>
      {hint ? <span className="mt-1.5 block text-xs text-faint">{hint}</span> : null}
    </div>
  );
}
