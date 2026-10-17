import { useEffect, useState } from "react";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Switch from "@/components/ui/Switch";
import { Textarea } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { useGetTickersQuery, useUpdateAllTickersMutation, useUpdateTickerMutation } from "@/services/api";
import type { BranchTicker } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";

type Draft = { enabled: boolean; text: string };

/**
 * The running strip on the user home page ("Live Casino Not Allowed"), per
 * branch. Branch admins set their own; here any branch, or all at once.
 */
export default function TickersCard() {
  const tickers = useGetTickersQuery();
  const [saveOne] = useUpdateTickerMutation();
  const [saveAll, saveAllState] = useUpdateAllTickersMutation();
  const [drafts, setDrafts] = useState<Record<number, Draft>>({});
  const [allDraft, setAllDraft] = useState<Draft>({ enabled: true, text: "" });
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!tickers.data) return;
    setDrafts(Object.fromEntries(tickers.data.map((row) => [row.branch_id, { enabled: row.enabled, text: row.text }])));
  }, [tickers.data]);

  const rows = tickers.data ?? [];
  const max = rows[0]?.max_length ?? 300;
  const valid = (draft: Draft) => !draft.enabled || draft.text.trim() !== "";
  const changed = (row: BranchTicker) => {
    const draft = drafts[row.branch_id];
    return draft ? draft.enabled !== row.enabled || draft.text.trim() !== row.text : false;
  };

  const save = async (row: BranchTicker) => {
    const draft = drafts[row.branch_id];
    if (!draft) return;
    setError(null);
    setNotice(null);
    if (!valid(draft)) return setError(`${row.branch_name}: write the text, or switch the strip off.`);
    setBusy(row.branch_id);
    try {
      await saveOne({ branchId: row.branch_id, enabled: draft.enabled, text: draft.text.trim() }).unwrap();
      setNotice(`${row.branch_name} saved.`);
    } catch (err) {
      setError(resolveErrorMessage(err, `Could not save ${row.branch_name}.`));
    } finally {
      setBusy(null);
    }
  };

  const applyAll = async () => {
    setError(null);
    setNotice(null);
    if (!valid(allDraft)) return setError("Write the text, or switch the strip off.");
    try {
      await saveAll({ enabled: allDraft.enabled, text: allDraft.text.trim() }).unwrap();
      setNotice("Saved for every branch.");
    } catch (err) {
      setError(resolveErrorMessage(err, "Could not save for every branch."));
    }
  };

  return (
    <Card>
      <CardTitle hint="User home page">Running strip</CardTitle>
      <div className="space-y-4">
        <div className="space-y-3 rounded-md bg-accent-soft p-3">
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm font-medium text-text">Same for all branches</p>
            <Switch
              checked={allDraft.enabled}
              onChange={(enabled) => setAllDraft((prev) => ({ ...prev, enabled }))}
              label="Show on all branches"
              showState
            />
          </div>
          <Textarea
            value={allDraft.text}
            maxLength={max}
            rows={2}
            placeholder="e.g. Live Casino Not Allowed"
            hint={`${allDraft.text.trim().length} / ${max}`}
            onChange={(event) => setAllDraft((prev) => ({ ...prev, text: event.target.value }))}
          />
          <Button size="sm" loading={saveAllState.isLoading} onClick={() => void applyAll()}>
            Apply to all branches
          </Button>
        </div>

        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {notice ? <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p> : null}

        {tickers.error && !tickers.data ? (
          <ErrorNote>{resolveErrorMessage(tickers.error, "Could not load the strips.")}</ErrorNote>
        ) : (
          <ul className="space-y-3">
            {rows.map((row) => {
              const draft = drafts[row.branch_id] ?? { enabled: row.enabled, text: row.text };
              return (
                <li key={row.branch_id} className="space-y-3 rounded-md border border-border p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold text-text">{row.branch_name}</p>
                      {row.updated_by ? <p className="text-xs text-faint">Last set by {row.updated_by}</p> : null}
                    </div>
                    <Switch
                      checked={draft.enabled}
                      onChange={(enabled) => setDrafts((prev) => ({ ...prev, [row.branch_id]: { ...draft, enabled } }))}
                      label={`Strip for ${row.branch_name}`}
                      showState
                    />
                  </div>
                  <Textarea
                    value={draft.text}
                    maxLength={max}
                    rows={2}
                    placeholder="e.g. Live Casino Not Allowed"
                    onChange={(event) =>
                      setDrafts((prev) => ({ ...prev, [row.branch_id]: { ...draft, text: event.target.value } }))
                    }
                  />
                  <Button
                    size="sm"
                    variant="secondary"
                    loading={busy === row.branch_id}
                    disabled={!changed(row)}
                    onClick={() => void save(row)}
                  >
                    Save {row.branch_name}
                  </Button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Card>
  );
}
