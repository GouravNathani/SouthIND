import { useId, type KeyboardEvent, type ReactNode } from "react";

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
}: {
  label?: string;
  value: T;
  onChange: (value: T) => void;
  options: readonly SegmentedOption<T>[];
  hint?: ReactNode;
  disabled?: boolean;
  className?: string;
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

  return (
    <div className={["min-w-0", className].join(" ")}>
      {label ? (
        <span id={labelId} className="mb-1.5 block text-xs font-medium text-muted">
          {label}
        </span>
      ) : null}
      <div
        role="radiogroup"
        aria-labelledby={label ? labelId : undefined}
        className={[
          "flex h-11 min-w-0 gap-1 rounded-md border border-border bg-surface-2 p-1",
          disabled ? "opacity-60" : "",
        ].join(" ")}
      >
        {options.map((option, index) => {
          const active = option.value === value;
          return (
            <button
              key={option.value}
              type="button"
              role="radio"
              aria-checked={active}
              tabIndex={index === tabStop ? 0 : -1}
              disabled={disabled}
              onClick={() => onChange(option.value)}
              onKeyDown={(event) => onKeyDown(event, index)}
              className={[
                "min-w-0 flex-1 truncate rounded-sm px-1.5 text-[13px] font-medium whitespace-nowrap",
                "transition-colors duration-150 focus-visible:outline-offset-0 disabled:cursor-not-allowed",
                active ? "bg-accent-soft text-accent shadow-[inset_0_0_0_1px_var(--accent-line)]" : "text-muted hover:text-text",
              ].join(" ")}
            >
              {option.label}
            </button>
          );
        })}
      </div>
      {hint ? <span className="mt-1.5 block text-xs text-faint">{hint}</span> : null}
    </div>
  );
}
