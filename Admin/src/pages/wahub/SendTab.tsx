import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import type { Tag, UserRecord, WaAudience, WaCampaign, WaCampaignPreview, WaConnection } from "@/types/api";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import Segmented from "@/components/ui/Segmented";
import Pagination from "@/components/Pagination";
import { Input, Textarea } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconAlert, IconCheck, IconClock, IconClose, IconSearch, IconSend } from "@/components/icons";
import CampaignList from "@/components/wahub/CampaignList";
import VariableChips from "@/components/wahub/VariableChips";
import { WaNotice } from "@/components/wahub/WaPill";
import WaErrorState from "@/components/wahub/WaErrorState";
import {
  CAMPAIGN_RULES_HINT,
  coinsLabel,
  count,
  istLocalNow,
  istLocalToIso,
  maskNumber,
  toPaginationMeta,
  waErrorCode,
  waErrorMessage,
} from "@/components/wahub/waFormat";
import { useWaCoinRate } from "@/components/wahub/useWaCoinRate";
import { DEFAULT_CAMPAIGN_VARIABLES } from "@/pages/wahub/TemplatesTab";
import { usePageVisible } from "@/hooks/usePageVisible";
import {
  useCancelWaCampaignMutation,
  useCreateWaCampaignMutation,
  useGetClubsQuery,
  useGetTagsQuery,
  useGetUsersQuery,
  useGetWaCampaignsQuery,
  useGetWaTemplatesQuery,
  usePreviewWaCampaignMutation,
} from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";

type Source = "template" | "new";
type AudienceType = "tags" | "clubs" | "users";

const AUDIENCE_HINT: Record<AudienceType, string> = {
  tags: "Pick at least one tag.",
  clubs: "Pick at least one club.",
  users: "Pick at least one user.",
};

/** Ids from `?club=1,2` / `?tag=3` (Clubs page and tag shortcuts open the Send tab with them). */
const idsFrom = (value: string | null): number[] =>
  (value ?? "")
    .split(",")
    .map((part) => Number(part))
    .filter((id) => Number.isInteger(id) && id > 0);
type When = "now" | "later";
type PickedUser = { id: number; name: string; detail: string };

const SEARCH_DEBOUNCE_MS = 350;
const USER_RESULTS = 10;

const skippedTotal = (preview: WaCampaignPreview) =>
  (preview.skipped?.no_phone ?? 0) + (preview.skipped?.duplicate ?? 0) + (preview.skipped?.inactive ?? 0);

/** "3 no phone, 2 duplicate, 1 inactive" — zero buckets left out. */
const skippedDetail = (preview: WaCampaignPreview) =>
  [
    [preview.skipped?.no_phone ?? 0, "no phone"],
    [preview.skipped?.duplicate ?? 0, "duplicate"],
    [preview.skipped?.inactive ?? 0, "inactive"],
  ]
    .filter(([n]) => Number(n) > 0)
    .map(([n, label]) => `${count(Number(n))} ${label}`)
    .join(", ");

export default function SendTab({
  connection,
  onOpenTemplates,
}: {
  connection: WaConnection;
  onOpenTemplates: () => void;
}) {
  return (
    <div className="space-y-4">
      <ComposeCard connection={connection} onOpenTemplates={onOpenTemplates} />
      <CampaignsSection />
    </div>
  );
}

// ── Compose ─────────────────────────────────────────────────────────────────

function ComposeCard({ connection, onOpenTemplates }: { connection: WaConnection; onOpenTemplates: () => void }) {
  const rate = useWaCoinRate();
  const templates = useGetWaTemplatesQuery();
  const tags = useGetTagsQuery();
  const clubs = useGetClubsQuery();
  const [params] = useSearchParams();

  const templateRows = useMemo(() => templates.data?.templates ?? [], [templates.data]);
  const variables = templates.data?.variables?.length ? templates.data.variables : DEFAULT_CAMPAIGN_VARIABLES;

  // Message
  const [sourceChoice, setSourceChoice] = useState<Source | null>(null);
  const source: Source = sourceChoice ?? (templateRows.length ? "template" : "new");
  const [templateId, setTemplateId] = useState<number | null>(null);
  const [body, setBody] = useState("");
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [title, setTitle] = useState("");
  const [titleIsAuto, setTitleIsAuto] = useState(true);

  // Audience
  const [audienceType, setAudienceType] = useState<AudienceType>(() =>
    idsFrom(params.get("club")).length ? "clubs" : "tags"
  );
  const [tagIds, setTagIds] = useState<number[]>(() => idsFrom(params.get("tag")));
  const [clubIds, setClubIds] = useState<number[]>(() => idsFrom(params.get("club")));
  const [picked, setPicked] = useState<PickedUser[]>([]);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");

  // When
  const [when, setWhen] = useState<When>("now");
  const [sendAt, setSendAt] = useState("");

  // Preview / submit
  const [preview, setPreview] = useState<{ key: string; data: WaCampaignPreview } | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [requestPreview, previewState] = usePreviewWaCampaignMutation();
  const [createCampaign, createState] = useCreateWaCampaignMutation();

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  const users = useGetUsersQuery(
    { per_page: USER_RESULTS, search: debouncedSearch || undefined },
    { skip: audienceType !== "users" }
  );

  const audience: WaAudience | null =
    audienceType === "tags"
      ? tagIds.length
        ? { type: "tags", tag_ids: [...tagIds].sort((a, b) => a - b) }
        : null
      : audienceType === "clubs"
        ? clubIds.length
          ? { type: "clubs", club_ids: [...clubIds].sort((a, b) => a - b) }
          : null
        : picked.length
          ? { type: "users", user_ids: picked.map((user) => user.id).sort((a, b) => a - b) }
          : null;
  const audienceKey = audience ? JSON.stringify(audience) : "";
  const freshPreview = preview && preview.key === audienceKey ? preview.data : null;

  const selectedTemplate = templateRows.find((template) => template.id === templateId) ?? null;
  const hasMessage = source === "template" ? Boolean(selectedTemplate) : body.trim().length > 0;

  const chooseTemplate = (id: number) => {
    setTemplateId(id);
    const template = templateRows.find((entry) => entry.id === id);
    if (template && (titleIsAuto || !title.trim())) {
      setTitle(template.name);
      setTitleIsAuto(true);
    }
  };

  const toggleTag = (id: number) =>
    setTagIds((prev) => (prev.includes(id) ? prev.filter((entry) => entry !== id) : [...prev, id]));

  const toggleClub = (id: number) =>
    setClubIds((prev) => (prev.includes(id) ? prev.filter((entry) => entry !== id) : [...prev, id]));

  const toggleUser = (user: UserRecord) =>
    setPicked((prev) =>
      prev.some((entry) => entry.id === user.id)
        ? prev.filter((entry) => entry.id !== user.id)
        : [
            ...prev,
            {
              id: user.id,
              name: user.name || "Unnamed",
              detail: [user.play_id, user.phone ? maskNumber(user.phone) : null].filter(Boolean).join(" · "),
            },
          ]
    );

  const runPreview = async (): Promise<WaCampaignPreview | null> => {
    if (!audience) return null;
    setPreviewError(null);
    try {
      const data = await requestPreview({ audience }).unwrap();
      setPreview({ key: audienceKey, data });
      return data;
    } catch (err) {
      setPreviewError(waErrorMessage(err, "Could not count the audience."));
      return null;
    }
  };

  const validate = (): string | null => {
    if (!hasMessage) return source === "template" ? "Pick a saved template." : "Write the message.";
    if (!audience) return AUDIENCE_HINT[audienceType];
    if (!title.trim()) return "Give the campaign a name.";
    if (when === "later") {
      if (!sendAt) return "Pick a date and time.";
      if (sendAt <= istLocalNow()) return "Pick a time in the future.";
    }
    return null;
  };

  const handleReview = async () => {
    setNotice(null);
    setSubmitError(null);
    const problem = validate();
    setFormError(problem);
    if (problem) return;
    const counted = freshPreview ?? (await runPreview());
    if (!counted) return;
    if (counted.total <= 0) {
      setFormError("Nobody in this audience can get a WhatsApp message.");
      return;
    }
    setConfirming(true);
  };

  const handleSend = async () => {
    if (!audience) return;
    setSubmitError(null);
    try {
      const campaign = await createCampaign({
        title: title.trim(),
        audience,
        ...(source === "template" && templateId ? { template_id: templateId } : { body: body.trim() }),
        ...(when === "later" ? { send_at: istLocalToIso(sendAt) } : {}),
      }).unwrap();
      setConfirming(false);
      setNotice(
        when === "later"
          ? `“${campaign.title || title.trim()}” is scheduled for ${formatDateTime(campaign.send_at ?? istLocalToIso(sendAt))}.`
          : `“${campaign.title || title.trim()}” has started. Progress shows below.`
      );
      setBody("");
      setTemplateId(null);
      setTitle("");
      setTitleIsAuto(true);
      setTagIds([]);
    setClubIds([]);
      setPicked([]);
      setSearch("");
      setSendAt("");
      setWhen("now");
      setPreview(null);
    } catch (err) {
      if (waErrorCode(err) === "unknown_variable") {
        setConfirming(false);
        setBodyError(waErrorMessage(err, ""));
        return;
      }
      setSubmitError(waErrorMessage(err, "Could not create the campaign."));
    }
  };

  const tagRows: Tag[] = tags.data ?? [];
  const clubRows = clubs.data?.data ?? [];
  const userRows = users.data?.data ?? [];
  const userTotal = users.data?.meta?.total;

  return (
    <Card>
      <CardTitle hint={`${coinsLabel(rate)} per message sent`}>New campaign</CardTitle>
      <div className="space-y-5">
        {connection.status !== "connected" ? (
          <WaNotice tone="warn" icon={<IconAlert size={14} />}>
            WhatsApp is not connected. Connect it on the Connect tab first — messages to a disconnected number are
            skipped.
          </WaNotice>
        ) : null}

        {/* ── Message ── */}
        <section className="space-y-3">
          <Segmented
            label="Message"
            value={source}
            onChange={(next) => {
              setSourceChoice(next);
              setFormError(null);
            }}
            options={[
              { value: "template", label: "Saved template" },
              { value: "new", label: "Write new" },
            ]}
            className="sm:max-w-sm"
          />

          {source === "template" ? (
            templates.isLoading ? (
              <Skeleton className="h-24 w-full" />
            ) : templates.error && !templates.data ? (
              <ErrorNote>{waErrorMessage(templates.error, "Could not load templates.")}</ErrorNote>
            ) : templateRows.length ? (
              <ul className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Saved templates">
                {templateRows.map((template) => {
                  const active = template.id === templateId;
                  return (
                    <li key={template.id} className="min-w-0">
                      <button
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => chooseTemplate(template.id)}
                        className={[
                          "flex h-full w-full min-w-0 flex-col rounded-md border p-3 text-left transition-colors",
                          active ? "border-accent-line bg-accent-soft" : "border-border bg-surface-2 hover:border-border-strong",
                        ].join(" ")}
                      >
                        <span className="flex w-full min-w-0 items-center gap-2">
                          <span
                            className={[
                              "grid size-4 shrink-0 place-items-center rounded-full border",
                              active ? "border-accent bg-accent text-on-accent" : "border-border-strong",
                            ].join(" ")}
                          >
                            {active ? <IconCheck size={10} /> : null}
                          </span>
                          <span className={["min-w-0 truncate text-sm font-semibold", active ? "text-accent" : "text-text"].join(" ")}>
                            {template.name}
                          </span>
                        </span>
                        <span className="mt-1.5 line-clamp-2 text-xs break-words text-muted">{template.body}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <div className="rounded-md border border-dashed border-border">
                <EmptyState
                  title="No saved templates yet"
                  body="Write the message here, or save a reusable one first."
                  action={
                    <div className="flex flex-wrap justify-center gap-2">
                      <Button size="sm" onClick={() => setSourceChoice("new")}>
                        Write new
                      </Button>
                      <Button size="sm" variant="secondary" onClick={onOpenTemplates}>
                        Create template
                      </Button>
                    </div>
                  }
                />
              </div>
            )
          ) : (
            <div className="space-y-3">
              <Textarea
                ref={textareaRef}
                rows={6}
                maxLength={2000}
                aria-label="Message text"
                placeholder="Hi {{name}}, …"
                value={body}
                onChange={(event) => {
                  setBody(event.target.value);
                  setBodyError(null);
                }}
                error={bodyError}
              />
              <VariableChips
                variables={variables}
                textareaRef={textareaRef}
                value={body}
                onChange={(next) => {
                  setBody(next);
                  setBodyError(null);
                }}
              />
            </div>
          )}
          {source === "template" && bodyError ? <ErrorNote>{bodyError}</ErrorNote> : null}
        </section>

        {/* ── Audience ── */}
        <section className="space-y-3 border-t border-border pt-5">
          <Segmented
            label="Send to"
            value={audienceType}
            onChange={(next) => {
              setAudienceType(next);
              setFormError(null);
            }}
            options={[
              { value: "tags", label: "Tags" },
              { value: "clubs", label: "Clubs" },
              { value: "users", label: "Users" },
            ]}
            className="sm:max-w-sm"
          />

          {audienceType === "clubs" ? (
            clubs.isLoading ? (
              <Skeleton className="h-10 w-full" />
            ) : clubs.error ? (
              <ErrorNote>{waErrorMessage(clubs.error, "Could not load clubs.")}</ErrorNote>
            ) : clubRows.length ? (
              <ul className="flex min-w-0 flex-wrap gap-2">
                {clubRows.map((club) => {
                  const active = clubIds.includes(club.id);
                  return (
                    <li key={club.id} className="max-w-full">
                      <button
                        type="button"
                        onClick={() => toggleClub(club.id)}
                        aria-pressed={active}
                        className="inline-flex max-w-full items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium"
                        style={{
                          borderColor: active ? club.color : "var(--border)",
                          background: active ? `color-mix(in srgb, ${club.color} 18%, transparent)` : "var(--surface-2)",
                          color: active ? club.color : "var(--text-muted)",
                        }}
                      >
                        {active ? <IconCheck size={12} /> : (
                          <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: club.color }} />
                        )}
                        <span className="truncate">{club.name}</span>
                        <span className="tabular opacity-70">{count(club.members_count)}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <p className="text-xs text-muted">This branch has no clubs yet. Add them on the Clubs page.</p>
            )
          ) : audienceType === "tags" ? (
            tags.isLoading ? (
              <Skeleton className="h-10 w-full" />
            ) : tags.error ? (
              <ErrorNote>{waErrorMessage(tags.error, "Could not load tags.")}</ErrorNote>
            ) : tagRows.length ? (
              <ul className="flex min-w-0 flex-wrap gap-2">
                {tagRows.map((tag) => {
                  const active = tagIds.includes(tag.id);
                  return (
                    <li key={tag.id} className="max-w-full">
                      <button
                        type="button"
                        onClick={() => toggleTag(tag.id)}
                        aria-pressed={active}
                        className="inline-flex max-w-full items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium"
                        style={{
                          borderColor: active ? tag.color : "var(--border)",
                          background: active ? `color-mix(in srgb, ${tag.color} 18%, transparent)` : "var(--surface-2)",
                          color: active ? tag.color : "var(--text-muted)",
                        }}
                      >
                        {active ? <IconCheck size={12} /> : (
                          <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: tag.color }} />
                        )}
                        <span className="truncate">{tag.name}</span>
                        {typeof tag.users_count === "number" ? (
                          <span className="tabular opacity-70">{count(tag.users_count)}</span>
                        ) : null}
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <p className="text-xs text-faint">This branch has no tags yet. Tag users from their page, or pick users instead.</p>
            )
          ) : (
            <div className="space-y-3">
              {picked.length ? (
                <ul className="flex min-w-0 flex-wrap gap-1.5" aria-label="Selected users">
                  {picked.map((user) => (
                    <li key={user.id} className="max-w-full">
                      <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-accent-soft py-1 pr-1 pl-3 text-xs font-medium text-accent">
                        <span className="truncate">{user.name}</span>
                        <button
                          type="button"
                          onClick={() => setPicked((prev) => prev.filter((entry) => entry.id !== user.id))}
                          aria-label={`Remove ${user.name}`}
                          className="grid size-5 shrink-0 place-items-center rounded-full hover:bg-accent-soft"
                        >
                          <IconClose size={12} />
                        </button>
                      </span>
                    </li>
                  ))}
                  <li>
                    <button
                      type="button"
                      onClick={() => setPicked([])}
                      className="rounded-full px-2 py-1 text-xs font-medium text-muted hover:text-text"
                    >
                      Clear all
                    </button>
                  </li>
                </ul>
              ) : null}

              <div className="relative">
                <span className="pointer-events-none absolute top-0 left-3.5 flex h-11 items-center text-faint">
                  <IconSearch size={16} />
                </span>
                <Input
                  aria-label="Search users"
                  placeholder="Search name, phone or play ID"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  className="pl-10"
                />
              </div>

              {users.isFetching && !users.data ? (
                <Skeleton className="h-40 w-full" />
              ) : users.error ? (
                <ErrorNote>{waErrorMessage(users.error, "Could not search users.")}</ErrorNote>
              ) : userRows.length ? (
                <>
                  <ul className="divide-y divide-border overflow-hidden rounded-md border border-border">
                    {userRows.map((user) => {
                      const active = picked.some((entry) => entry.id === user.id);
                      return (
                        <li key={user.id}>
                          <button
                            type="button"
                            role="checkbox"
                            aria-checked={active}
                            onClick={() => toggleUser(user)}
                            className={[
                              "flex w-full min-w-0 items-center gap-3 px-3 py-2.5 text-left transition-colors",
                              active ? "bg-accent-soft" : "hover:bg-surface-2",
                            ].join(" ")}
                          >
                            <span
                              className={[
                                "grid size-5 shrink-0 place-items-center rounded-sm border",
                                active ? "border-accent bg-accent text-on-accent" : "border-border-strong",
                              ].join(" ")}
                            >
                              {active ? <IconCheck size={12} /> : null}
                            </span>
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-sm font-medium text-text">{user.name || "Unnamed"}</span>
                              <span className="tabular block truncate text-xs text-faint">
                                {[user.play_id, user.phone ? maskNumber(user.phone) : "No phone"].filter(Boolean).join(" · ")}
                              </span>
                            </span>
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                  {typeof userTotal === "number" && userTotal > userRows.length ? (
                    <p className="text-xs text-faint">
                      Showing {userRows.length} of {count(userTotal)} — search to find others.
                    </p>
                  ) : null}
                </>
              ) : (
                <p className="text-xs text-faint">No users match “{debouncedSearch}”.</p>
              )}
            </div>
          )}

          <div className="flex min-w-0 flex-wrap items-center gap-3">
            <Button
              size="sm"
              variant="secondary"
              onClick={() => void runPreview()}
              loading={previewState.isLoading}
              disabled={!audience}
            >
              Preview audience
            </Button>
            {!audience ? <span className="text-xs text-faint">Pick {audienceType} to see who gets it.</span> : null}
          </div>

          {previewError ? <ErrorNote>{previewError}</ErrorNote> : null}

          {freshPreview ? (
            <div className="rounded-md border border-border bg-surface-2 p-3">
              <p className="tabular text-sm text-text">
                <span className="font-semibold">{count(freshPreview.total)} users</span>
                {skippedTotal(freshPreview) ? (
                  <span className="text-muted">
                    {" "}
                    · {count(skippedTotal(freshPreview))} skipped ({skippedDetail(freshPreview)})
                  </span>
                ) : null}
                <span className="text-muted"> · {coinsLabel(freshPreview.coins)}</span>
              </p>
              {freshPreview.sample?.length ? (
                <ul className="mt-2 space-y-1">
                  {freshPreview.sample.map((row) => (
                    <li key={row.id} className="flex min-w-0 items-center justify-between gap-3 text-xs">
                      <span className="min-w-0 truncate text-muted">{row.name || "Unnamed"}</span>
                      <span className="tabular shrink-0 text-faint">{maskNumber(row.phone_masked)}</span>
                    </li>
                  ))}
                  {freshPreview.total > freshPreview.sample.length ? (
                    <li className="text-xs text-faint">
                      and {count(freshPreview.total - freshPreview.sample.length)} more
                    </li>
                  ) : null}
                </ul>
              ) : null}
            </div>
          ) : null}
        </section>

        {/* ── When ── */}
        <section className="space-y-3 border-t border-border pt-5">
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Campaign name"
              placeholder="e.g. Weekend offer"
              maxLength={80}
              value={title}
              onChange={(event) => {
                setTitle(event.target.value);
                setTitleIsAuto(false);
              }}
              hint="Only admins see this."
            />
            <Segmented
              label="When"
              value={when}
              onChange={setWhen}
              options={[
                { value: "now", label: "Send now" },
                { value: "later", label: "Schedule" },
              ]}
            />
            {when === "later" ? (
              <Input
                label="Date and time (IST)"
                type="datetime-local"
                min={istLocalNow(5)}
                value={sendAt}
                onChange={(event) => setSendAt(event.target.value)}
                className="sm:col-start-2"
              />
            ) : null}
          </div>

          <WaNotice tone="neutral" icon={<IconClock size={14} />}>
            {CAMPAIGN_RULES_HINT}
          </WaNotice>

          {formError ? <ErrorNote>{formError}</ErrorNote> : null}
          {notice ? <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p> : null}

          <Button onClick={() => void handleReview()} loading={previewState.isLoading && !confirming}>
            {when === "later" ? <IconClock size={16} /> : <IconSend size={16} />}
            {when === "later" ? "Schedule" : "Send now"}
          </Button>
        </section>
      </div>

      <ConfirmDialog
        open={confirming && Boolean(freshPreview)}
        title={
          when === "later"
            ? `Schedule for ${formatDateTime(istLocalToIso(sendAt))}?`
            : `Send to ${count(freshPreview?.total)} users now?`
        }
        confirmLabel={when === "later" ? "Schedule" : `Send to ${count(freshPreview?.total)}`}
        loading={createState.isLoading}
        error={submitError}
        onConfirm={() => void handleSend()}
        onCancel={() => {
          setConfirming(false);
          setSubmitError(null);
        }}
      >
        {freshPreview ? (
          <>
            <p>
              <span className="font-semibold text-text">{coinsLabel(freshPreview.coins)}</span> —{" "}
              {coinsLabel(freshPreview.coins_per_message ?? rate)} for each message that is sent, to{" "}
              {count(freshPreview.total)} users.
              {skippedTotal(freshPreview) ? ` ${count(skippedTotal(freshPreview))} are skipped (${skippedDetail(freshPreview)}).` : ""}
            </p>
            <p className="text-xs text-faint">Goes out between 9 AM and 9 PM IST, a few messages a minute.</p>
          </>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}

// ── Campaign list ───────────────────────────────────────────────────────────

const CAMPAIGNS_PER_PAGE = 10;
const CAMPAIGN_POLL_MS = 15000;

function CampaignsSection() {
  const [page, setPage] = useState(1);
  const visible = usePageVisible();
  const [active, setActive] = useState(false);
  const campaigns = useGetWaCampaignsQuery(
    { page, per_page: CAMPAIGNS_PER_PAGE },
    { pollingInterval: active && visible ? CAMPAIGN_POLL_MS : 0, refetchOnMountOrArgChange: true }
  );
  const rows = useMemo(() => campaigns.data?.data ?? [], [campaigns.data]);

  // Refresh progress only while something is still going out.
  useEffect(
    () => setActive(rows.some((campaign) => campaign.status === "running" || campaign.status === "scheduled")),
    [rows]
  );

  const [cancelling, setCancelling] = useState<WaCampaign | null>(null);
  const [cancelCampaign, cancelState] = useCancelWaCampaignMutation();
  const [cancelError, setCancelError] = useState<string | null>(null);

  const handleCancel = async () => {
    if (!cancelling) return;
    setCancelError(null);
    try {
      await cancelCampaign(cancelling.id).unwrap();
      setCancelling(null);
    } catch (err) {
      setCancelError(waErrorMessage(err, "Could not cancel this campaign."));
    }
  };

  return (
    <section className="space-y-3">
      <h2 className="text-[13px] font-semibold tracking-wide text-muted uppercase">Campaigns</h2>
      {campaigns.error && !campaigns.data ? (
        <WaErrorState
          error={campaigns.error}
          onRetry={() => void campaigns.refetch()}
          retrying={campaigns.isFetching}
          fallback="Could not load campaigns."
        />
      ) : (
        <>
          <CampaignList
            rows={rows}
            loading={campaigns.isLoading}
            onCancel={(campaign) => {
              setCancelError(null);
              setCancelling(campaign);
            }}
            emptyBody="Campaigns you send or schedule show up here with their progress."
          />
          <Pagination meta={toPaginationMeta(campaigns.data?.meta)} page={page} onPage={setPage} />
        </>
      )}

      <ConfirmDialog
        open={cancelling !== null}
        title="Cancel this campaign?"
        confirmLabel="Cancel campaign"
        cancelLabel="Keep it"
        tone="danger"
        loading={cancelState.isLoading}
        error={cancelError}
        onConfirm={() => void handleCancel()}
        onCancel={() => setCancelling(null)}
      >
        <p>
          Messages already sent stay sent. The {count(cancelling?.counts?.pending)} still waiting are not sent and cost
          nothing.
        </p>
      </ConfirmDialog>
    </section>
  );
}
