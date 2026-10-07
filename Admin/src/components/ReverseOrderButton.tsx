import { IconSortSwap } from "@/components/icons";

/**
 * Small icon toggle that turns the list's current order around (newest ↔
 * oldest, A–Z ↔ Z–A …). Field height, so it lines up beside a Segmented row;
 * tinted while on, so a reversed list is never mistaken for the normal one.
 */
export default function ReverseOrderButton({
  reversed,
  onToggle,
}: {
  reversed: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-pressed={reversed}
      aria-label="Reverse order"
      title={reversed ? "Reversed — tap for the normal order" : "Reverse order"}
      className={[
        "inline-flex size-11 shrink-0 items-center justify-center rounded-md border",
        "transition-[background,border-color,color] duration-150",
        reversed
          ? "border-accent-line bg-accent-soft text-accent"
          : "border-border bg-surface-2 text-muted hover:border-border-strong hover:text-text",
      ].join(" ")}
    >
      <IconSortSwap size={18} />
    </button>
  );
}
