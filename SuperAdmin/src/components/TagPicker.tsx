import { useEffect, useState } from "react";
import { useGetTagsQuery, useSyncUserTagsMutation } from "@/services/api";
import type { Tag } from "@/types/api";
import { IconCheck } from "@/components/icons";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { resolveErrorMessage } from "@/utils/errors";

/**
 * Toggles branch tags on a user. Saves on every toggle rather than behind a
 * button — tagging is how admins triage, and a tag that needed a second click
 * to persist would silently not persist.
 */
export default function TagPicker({ userId, tags }: { userId: number; tags?: Tag[] }) {
  const { data: allTags = [], isLoading } = useGetTagsQuery();
  const [syncTags, { isLoading: isSaving }] = useSyncUserTagsMutation();
  const [selected, setSelected] = useState<number[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setSelected((tags ?? []).map((tag) => tag.id));
  }, [tags]);

  const toggle = async (tagId: number) => {
    const next = selected.includes(tagId)
      ? selected.filter((id) => id !== tagId)
      : [...selected, tagId];

    setSelected(next);
    setError(null);
    try {
      await syncTags({ id: userId, tag_ids: next }).unwrap();
    } catch (err) {
      setSelected(selected); // roll back to what the server still believes
      setError(resolveErrorMessage(err, "Could not update tags."));
    }
  };

  if (isLoading) return <Skeleton className="h-10 w-full" />;

  if (!allTags.length) {
    return <p className="text-xs text-faint">This branch has no tags yet.</p>;
  }

  return (
    <div className="min-w-0 space-y-2">
      <ul className="flex flex-wrap gap-2">
        {allTags.map((tag) => {
          const active = selected.includes(tag.id);
          return (
            <li key={tag.id} className="max-w-full">
              <button
                type="button"
                disabled={isSaving}
                onClick={() => void toggle(tag.id)}
                aria-pressed={active}
                className="inline-flex max-w-full items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                style={{
                  borderColor: active ? tag.color : "var(--border)",
                  background: active ? `color-mix(in srgb, ${tag.color} 18%, transparent)` : "var(--surface-2)",
                  color: active ? tag.color : "var(--text-muted)",
                }}
              >
                {active ? <IconCheck size={12} /> : null}
                <span className="truncate">{tag.name}</span>
              </button>
            </li>
          );
        })}
      </ul>
      {error ? <ErrorNote>{error}</ErrorNote> : null}
    </div>
  );
}
