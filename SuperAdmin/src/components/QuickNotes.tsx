import { useEffect, useState } from "react";
import { IconClose, IconPlus } from "@/components/icons";

const STORAGE_KEY = "sind_admin_quick_notes";
const MAX_NOTES = 12;

const read = (): string[] => {
  try {
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "[]");
    return Array.isArray(parsed) ? parsed.filter((note): note is string => typeof note === "string") : [];
  } catch {
    return [];
  }
};

/**
 * Reusable rejection/approval reasons. Admins type the same six sentences all
 * day ("UTR not found", "amount mismatch"); saving them once turns a decision
 * into one tap and keeps the wording consistent across operators.
 */
export default function QuickNotes({
  value,
  onPick,
}: {
  value: string;
  onPick: (note: string) => void;
}) {
  const [notes, setNotes] = useState<string[]>([]);

  useEffect(() => setNotes(read()), []);

  const persist = (next: string[]) => {
    setNotes(next);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  };

  const saveCurrent = () => {
    const trimmed = value.trim();
    if (!trimmed || notes.includes(trimmed)) return;
    persist([trimmed, ...notes].slice(0, MAX_NOTES));
  };

  return (
    <div className="min-w-0">
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-muted">Quick notes</span>
        <button
          type="button"
          onClick={saveCurrent}
          disabled={!value.trim()}
          className="inline-flex items-center gap-1 text-xs font-semibold text-accent disabled:opacity-50"
        >
          <IconPlus size={14} />
          Save current
        </button>
      </div>

      {notes.length ? (
        <ul className="flex flex-wrap gap-2">
          {notes.map((note) => (
            <li key={note} className="max-w-full">
              <span className="inline-flex max-w-full items-center gap-1 rounded-full border border-border bg-surface-2 py-1 pr-1 pl-3">
                <button
                  type="button"
                  onClick={() => onPick(note)}
                  className="min-w-0 truncate text-xs text-text"
                >
                  {note}
                </button>
                <button
                  type="button"
                  aria-label={`Remove note ${note}`}
                  onClick={() => persist(notes.filter((entry) => entry !== note))}
                  className="grid size-5 shrink-0 place-items-center rounded-full text-faint"
                >
                  <IconClose size={12} />
                </button>
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-faint">Save a note to reuse it on the next decision.</p>
      )}
    </div>
  );
}
