import type { RefObject } from "react";
import type { WaVariable } from "@/types/api";
import { IconLock } from "@/components/icons";

/**
 * Puts `{{key}}` where the caret is (or replaces the selection) and puts the
 * caret right after it, so an admin can build a message by tapping chips.
 */
export const insertVariable = (
  textarea: HTMLTextAreaElement | null,
  value: string,
  key: string,
  setValue: (next: string) => void
) => {
  const token = `{{${key}}}`;
  const start = textarea?.selectionStart ?? value.length;
  const end = textarea?.selectionEnd ?? value.length;
  setValue(value.slice(0, start) + token + value.slice(end));
  requestAnimationFrame(() => {
    if (!textarea) return;
    textarea.focus();
    const caret = start + token.length;
    textarea.setSelectionRange(caret, caret);
  });
};

export default function VariableChips({
  variables,
  textareaRef,
  value,
  onChange,
  disabled,
}: {
  variables: WaVariable[];
  textareaRef: RefObject<HTMLTextAreaElement | null>;
  value: string;
  onChange: (next: string) => void;
  disabled?: boolean;
}) {
  if (!variables.length) return null;
  const hasSecret = variables.some((variable) => variable.secret);

  return (
    <div className="min-w-0 space-y-1.5">
      <p className="text-xs font-medium text-muted">Tap to insert</p>
      <ul className="flex min-w-0 flex-wrap gap-1.5">
        {variables.map((variable) => (
          <li key={variable.key} className="max-w-full">
            <button
              type="button"
              disabled={disabled}
              // Keep the caret in the textarea on desktop: a mousedown would blur it first.
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => insertVariable(textareaRef.current, value, variable.key, onChange)}
              title={variable.sample ? `${variable.label} — e.g. ${variable.sample}` : variable.label}
              className={[
                "inline-flex max-w-full items-center gap-1 rounded-full border px-2.5 py-1 font-mono text-[11.5px] transition-colors",
                "disabled:cursor-not-allowed disabled:opacity-55",
                variable.secret
                  ? "border-warn/40 bg-warn/10 text-warn hover:bg-warn/16"
                  : "border-border bg-surface-2 text-text hover:border-accent-line hover:text-accent",
              ].join(" ")}
            >
              {variable.secret ? <IconLock size={12} /> : null}
              <span className="truncate">{`{{${variable.key}}}`}</span>
            </button>
          </li>
        ))}
      </ul>
      {hasSecret ? (
        <p className="flex items-center gap-1.5 text-xs text-faint">
          <IconLock size={12} />
          <span className="min-w-0">Locked values are sent to the user, hidden in history.</span>
        </p>
      ) : null}
    </div>
  );
}
