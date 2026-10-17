import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Input } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconEdit, IconPlus, IconRefresh, IconTrash, IconWhatsApp } from "@/components/icons";
import { WaNotice } from "@/components/wahub/WaPill";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useWaHubEnabled } from "@/hooks/useWaHubEnabled";
import {
  useAddDefaultClubsMutation,
  useCreateClubMutation,
  useDeleteClubMutation,
  useGetClubsQuery,
  useRefreshClubsMutation,
  useUpdateClubMutation,
} from "@/services/api";
import type { Club } from "@/types/api";
import { formatDateTime } from "@/utils/dateTime";
import { resolveErrorMessage } from "@/utils/errors";
import { inr } from "@/utils/format";

/** Colours offered for a club; any hex also works through the API. */
const CLUB_COLORS = ["#0ea5e9", "#f59e0b", "#64748b", "#10b981", "#6366f1", "#ec4899", "#ef4444", "#8b5cf6"];

type Draft = { id: number | null; name: string; color: string; days: string };

const EMPTY_DRAFT: Draft = { id: null, name: "", color: CLUB_COLORS[4], days: "" };

/**
 * The branch's clubs. The admin only names them and sets the day count; the
 * system sorts every user into the highest club they reach (approved deposit or
 * withdrawal days in the club window), every night and right after approvals.
 */
export default function ClubsPage() {
  const navigate = useNavigate();
  const waHubOn = useWaHubEnabled();

  const clubs = useGetClubsQuery(undefined, { refetchOnMountOrArgChange: true });
  const [createClub, createState] = useCreateClubMutation();
  const [updateClub, updateState] = useUpdateClubMutation();
  const [deleteClub, deleteState] = useDeleteClubMutation();
  const [addDefaults, defaultsState] = useAddDefaultClubsMutation();
  const [refreshClubs, refreshState] = useRefreshClubsMutation();
  useSessionGuard(clubs.error);

  const [draft, setDraft] = useState<Draft | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<Club | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const rows = clubs.data?.data ?? [];
  const meta = clubs.data?.meta;
  const windowDays = meta?.window_days ?? 30;

  const run = async (action: () => Promise<unknown>, done: string, failed: string) => {
    setActionError(null);
    setNotice(null);
    try {
      await action();
      setNotice(done);
    } catch (err) {
      setActionError(resolveErrorMessage(err, failed));
    }
  };

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!draft) return;
    setFormError(null);
    const days = Number(draft.days);
    if (!draft.name.trim()) return setFormError("Give the club a name.");
    if (!Number.isInteger(days) || days < 1) return setFormError("Days must be a whole number, 1 or more.");

    const body = { name: draft.name.trim(), color: draft.color, min_active_days: days };
    try {
      if (draft.id) await updateClub({ id: draft.id, ...body }).unwrap();
      else await createClub(body).unwrap();
      setNotice(draft.id ? "Club saved. Users were sorted again." : "Club added. Users were sorted again.");
      setDraft(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save the club."));
    }
  };

  const handleDelete = async () => {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await deleteClub(deleting.id).unwrap();
      setNotice(`${deleting.name} deleted.`);
      setDeleting(null);
    } catch (err) {
      setDeleteError(resolveErrorMessage(err, "Could not delete the club."));
    }
  };

  return (
    <AdminShell
      title="Clubs"
      subtitle="Users grouped by activity, automatically"
      action={
        !draft ? (
          <Button size="sm" onClick={() => setDraft({ ...EMPTY_DRAFT })}>
            <IconPlus size={16} />
            New club
          </Button>
        ) : undefined
      }
    >
      <div className="space-y-4">
        <WaNotice tone="info">
          The system puts each user in the highest club they reach: days with an approved deposit or withdrawal in the
          last {windowDays} days. It updates every night and right after each approval. Users below the lowest club are
          in no club.
        </WaNotice>

        {notice ? <WaNotice tone="pos">{notice}</WaNotice> : null}
        {actionError ? <ErrorNote>{actionError}</ErrorNote> : null}

        {clubs.isLoading ? (
          <Skeleton className="h-48 w-full" />
        ) : clubs.error && !clubs.data ? (
          <ErrorNote>{resolveErrorMessage(clubs.error, "Could not load clubs.")}</ErrorNote>
        ) : (
          <>
            {meta ? (
              <Card>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <dl className="grid flex-1 grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                    {[
                      ["Users", inr(meta.users_total)],
                      ["In a club", inr(meta.members_total)],
                      ["Not in a club", inr(meta.without_club)],
                      ["Window", `${meta.window_days} days`],
                    ].map(([label, value]) => (
                      <div key={label} className="min-w-0">
                        <dt className="text-xs text-muted">{label}</dt>
                        <dd className="tabular mt-1 truncate text-xl font-semibold text-text">{value}</dd>
                      </div>
                    ))}
                  </dl>
                  <Button
                    size="sm"
                    variant="secondary"
                    loading={refreshState.isLoading}
                    onClick={() => void run(() => refreshClubs().unwrap(), "Users sorted again.", "Could not sort users.")}
                  >
                    <IconRefresh size={16} />
                    Sort now
                  </Button>
                </div>
                <p className="mt-3 text-xs text-faint">
                  {meta.refreshed_at ? `Last sorted ${formatDateTime(meta.refreshed_at)}` : "Not sorted yet"}
                </p>
              </Card>
            ) : null}

            {draft ? (
              <Card>
                <CardTitle hint={`1 to ${windowDays} days`}>{draft.id ? "Edit club" : "New club"}</CardTitle>
                <form className="space-y-4" onSubmit={handleSave}>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                      label="Name"
                      value={draft.name}
                      maxLength={60}
                      placeholder="e.g. Diamond Club"
                      onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                    />
                    <Input
                      label="Active days needed"
                      type="number"
                      inputMode="numeric"
                      min={1}
                      max={windowDays}
                      value={draft.days}
                      placeholder="e.g. 21"
                      onChange={(event) => setDraft({ ...draft, days: event.target.value })}
                    />
                  </div>
                  <div>
                    <span className="mb-1.5 block text-xs font-medium text-muted">Colour</span>
                    <div role="radiogroup" aria-label="Colour" className="flex flex-wrap gap-2">
                      {CLUB_COLORS.map((color) => (
                        <button
                          key={color}
                          type="button"
                          role="radio"
                          aria-checked={draft.color === color}
                          aria-label={color}
                          onClick={() => setDraft({ ...draft, color })}
                          className={[
                            "h-8 w-8 rounded-full border-2 transition",
                            draft.color === color ? "scale-110 border-text" : "border-surface shadow-sm",
                          ].join(" ")}
                          style={{ backgroundColor: color }}
                        />
                      ))}
                    </div>
                  </div>
                  {formError ? <ErrorNote>{formError}</ErrorNote> : null}
                  <div className="flex flex-wrap gap-2">
                    <Button type="submit" loading={createState.isLoading || updateState.isLoading}>
                      {draft.id ? "Save club" : "Add club"}
                    </Button>
                    <Button variant="secondary" onClick={() => setDraft(null)}>
                      Cancel
                    </Button>
                  </div>
                </form>
              </Card>
            ) : null}

            {rows.length === 0 ? (
              <Card padded={false}>
                <EmptyState
                  title="No clubs yet"
                  body="Start with the four default clubs: Diamond (21 days), Gold (14), Silver (7) and Comeback (3). You can rename or change them later."
                  action={
                    <Button
                      loading={defaultsState.isLoading}
                      onClick={() => void run(() => addDefaults().unwrap(), "Default clubs added.", "Could not add the clubs.")}
                    >
                      <IconPlus size={16} />
                      Add default clubs
                    </Button>
                  }
                />
              </Card>
            ) : (
              <ul className="grid gap-3 md:grid-cols-2">
                {rows.map((club) => (
                  <li key={club.id}>
                    <Card className="flex h-full flex-col gap-4">
                      <div className="flex min-w-0 items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                          <span aria-hidden className="h-10 w-10 shrink-0 rounded-lg" style={{ backgroundColor: club.color }} />
                          <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-text">{club.name}</p>
                            <p className="text-xs text-muted">
                              {club.min_active_days}+ active days in {windowDays}
                            </p>
                          </div>
                        </div>
                        <p className="shrink-0 text-right">
                          <span className="tabular block text-xl font-semibold text-text">{inr(club.members_count)}</span>
                          <span className="text-xs text-faint">users</span>
                        </p>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="secondary" onClick={() => navigate(`/users?club=${club.id}`)}>
                          View users
                        </Button>
                        {waHubOn ? (
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => navigate(`/whatsapp-hub?tab=send&club=${club.id}`)}
                          >
                            <IconWhatsApp size={16} />
                            WhatsApp
                          </Button>
                        ) : null}
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setFormError(null);
                            setDraft({ id: club.id, name: club.name, color: club.color, days: String(club.min_active_days) });
                          }}
                        >
                          <IconEdit size={16} />
                          Edit
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setDeleting(club)}>
                          <IconTrash size={16} />
                          Delete
                        </Button>
                      </div>
                    </Card>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>

      <ConfirmDialog
        open={deleting !== null}
        title={`Delete ${deleting?.name ?? "club"}?`}
        confirmLabel="Delete"
        tone="danger"
        loading={deleteState.isLoading}
        error={deleteError}
        onConfirm={() => void handleDelete()}
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
      >
        <p>{inr(deleting?.members_count ?? 0)} user(s) move to the next club they reach, or to no club.</p>
      </ConfirmDialog>
    </AdminShell>
  );
}
