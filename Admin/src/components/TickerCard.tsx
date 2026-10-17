import { useEffect, useState, type FormEvent } from "react";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Switch from "@/components/ui/Switch";
import { Textarea } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { useGetTickerQuery, useUpdateTickerMutation } from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";
import { resolveErrorMessage } from "@/utils/errors";

/**
 * The running strip on this branch's user home page, e.g. "Live Casino Not
 * Allowed". Switching it off hides the whole strip.
 */
export default function TickerCard() {
  const ticker = useGetTickerQuery();
  const [save, { isLoading: saving }] = useUpdateTickerMutation();
  const [enabled, setEnabled] = useState(false);
  const [text, setText] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!ticker.data) return;
    setEnabled(ticker.data.enabled);
    setText(ticker.data.text);
  }, [ticker.data]);

  // Staff accounts cannot change the strip: show nothing to them.
  if ((ticker.error as { status?: number } | undefined)?.status === 403) return null;

  const max = ticker.data?.max_length ?? 300;
  const dirty = ticker.data ? ticker.data.enabled !== enabled || ticker.data.text !== text.trim() : false;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSaved(false);
    if (enabled && !text.trim()) {
      setError("Write the text to show, or switch the strip off.");
      return;
    }
    try {
      await save({ enabled, text: text.trim() }).unwrap();
      setSaved(true);
    } catch (err) {
      setError(resolveErrorMessage(err, "Could not save the strip."));
    }
  };

  return (
    <Card>
      <CardTitle hint={ticker.data?.updated_at ? `Saved ${formatDateTime(ticker.data.updated_at)}` : undefined}>
        Home page strip
      </CardTitle>
      <form className="space-y-3" onSubmit={handleSubmit}>
        <div className="flex items-center justify-between gap-4">
          <p className="min-w-0 text-xs text-muted">
            A moving line of text at the top of every user&apos;s home page in this branch. Off hides it completely.
          </p>
          <Switch checked={enabled} onChange={setEnabled} label="Show running strip" showState />
        </div>
        <Textarea
          label="Text"
          value={text}
          maxLength={max}
          rows={2}
          placeholder="e.g. Live Casino Not Allowed"
          hint={`${text.trim().length} / ${max}`}
          onChange={(event) => setText(event.target.value)}
        />
        {enabled && text.trim() ? (
          <div className="overflow-hidden rounded-md bg-accent py-1.5 text-xs font-semibold text-on-accent">
            <span className="ticker-run whitespace-nowrap">{text.trim()}</span>
          </div>
        ) : null}
        {error ? <ErrorNote>{error}</ErrorNote> : null}
        {saved && !dirty ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">Saved. Users see it on their next home visit.</p>
        ) : null}
        <Button type="submit" loading={saving} disabled={!dirty && !saving}>
          Save strip
        </Button>
      </form>
    </Card>
  );
}
