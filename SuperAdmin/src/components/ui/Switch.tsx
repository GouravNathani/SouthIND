/**
 * An on/off switch for settings that apply the moment they are flipped. Focus
 * is the global :focus-visible outline only — the track never changes border on
 * focus, so the two can not stack into a double ring.
 */
export default function Switch({
  checked,
  onChange,
  label,
  disabled,
  showState = false,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  /** Accessible name; the switch itself has no visible text. */
  label: string;
  disabled?: boolean;
  /** Print "On" / "Off" beside the track. */
  showState?: boolean;
}) {
  return (
    <span className="inline-flex shrink-0 items-center gap-2">
      {showState ? (
        <span
          aria-hidden
          className={["w-6 text-right text-xs font-semibold", checked ? "text-accent" : "text-faint"].join(" ")}
        >
          {checked ? "On" : "Off"}
        </span>
      ) : null}
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-label={label}
        disabled={disabled}
        onClick={(event) => {
          event.stopPropagation();
          onChange(!checked);
        }}
        className={[
          "relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors duration-150",
          "disabled:cursor-not-allowed disabled:opacity-55",
          checked ? "bg-accent" : "bg-surface-2 shadow-[inset_0_0_0_1px_var(--border-strong)]",
        ].join(" ")}
        style={{ transitionTimingFunction: "var(--ease)" }}
      >
        <span
          aria-hidden
          className={[
            "block size-5 rounded-full shadow-sm transition-transform duration-150",
            checked ? "translate-x-6" : "translate-x-1",
          ].join(" ")}
          style={{ background: checked ? "var(--text-on-accent)" : "var(--text-muted)" }}
        />
      </button>
    </span>
  );
}
