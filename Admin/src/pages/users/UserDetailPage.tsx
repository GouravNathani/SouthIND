import { useEffect, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import CopyRow from "@/components/CopyRow";
import TagPicker from "@/components/TagPicker";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge, { statusTone } from "@/components/ui/Badge";
import { Input } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconBack } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetUserQuery,
  useResetUserMpinMutation,
  useUpdateUserMutation,
  useUpdateUserStatusMutation,
} from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime, formatLastSeen } from "@/utils/dateTime";
import { money } from "@/utils/format";

export default function UserDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams();
  const userId = Number(id);

  const { data: user, isLoading, error } = useGetUserQuery(userId, { skip: !Number.isFinite(userId) });
  const [updateUser, { isLoading: isSaving }] = useUpdateUserMutation();
  const [updateStatus, { isLoading: isChangingStatus }] = useUpdateUserStatusMutation();
  const [resetMpin, { isLoading: isResetting }] = useResetUserMpinMutation();
  useSessionGuard(error);

  const [name, setName] = useState("");
  const [playId, setPlayId] = useState("");
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    setName(user?.name ?? "");
    setPlayId(user?.play_id ?? "");
  }, [user?.name, user?.play_id]);

  const back = (
    <Button size="sm" variant="secondary" onClick={() => navigate("/users")}>
      <IconBack size={16} />
      Users
    </Button>
  );

  if (isLoading) {
    return (
      <AdminShell title="User" action={back}>
        <Skeleton className="h-72 w-full" />
      </AdminShell>
    );
  }

  if (!user) {
    return (
      <AdminShell title="User" action={back}>
        <Card>
          {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load this user.")}</ErrorNote> : null}
          <EmptyState title="User not found" body="The id may be wrong, or the user belongs to another branch." />
        </Card>
      </AdminShell>
    );
  }

  const banned = (user.status ?? "").toLowerCase() === "banned";

  const handleSave = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setNotice(null);
    try {
      await updateUser({
        id: user.id,
        body: { name: name.trim(), play_id: playId.trim() },
      }).unwrap();
      setNotice("Profile saved.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save this user."));
    }
  };

  const toggleBan = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await updateStatus({ id: user.id, status: banned ? "active" : "banned" }).unwrap();
      setNotice(banned ? "User unbanned." : "User banned.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not change the status."));
    }
  };

  const handleResetMpin = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await resetMpin({ id: user.id }).unwrap();
      setNotice("MPIN regenerated — read the new one below and pass it to the user.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not regenerate the MPIN."));
    }
  };

  return (
    <AdminShell title={user.name || "User"} subtitle={user.play_id ?? user.unique_number ?? undefined} action={back}>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="min-w-0 space-y-4">
          <Card>
            <div className="flex min-w-0 items-start justify-between gap-3">
              <CardTitle>Identity</CardTitle>
              <Badge tone={statusTone(user.status)}>{user.status ?? "unknown"}</Badge>
            </div>

            <CopyRow label="Phone" value={user.phone} />
            <CopyRow label="Play ID" value={user.play_id} />
            <CopyRow label="Unique number" value={user.unique_number} />
            <CopyRow label="MPIN" value={user.mpin ? String(user.mpin) : ""} />
            {user.referral_code ? <CopyRow label="Referral code" value={user.referral_code} /> : null}

            {user.mpin_locked ? (
              <p className="mt-3 rounded-md px-3 py-2 text-xs"
                 style={{ color: "var(--neg)", background: "color-mix(in srgb, var(--neg) 12%, transparent)" }}>
                Locked after {user.mpin_failed_attempts ?? "several"} wrong PIN attempts
                {user.mpin_locked_at ? ` on ${formatDateTime(user.mpin_locked_at)}` : ""}. Regenerate
                the MPIN to unlock.
              </p>
            ) : null}

            <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3 text-xs">
              <div className="min-w-0">
                <dt className="text-faint">Joined</dt>
                <dd className="truncate text-text">{formatDateTime(user.created_at)}</dd>
              </div>
              <div className="min-w-0">
                <dt className="text-faint">Last seen</dt>
                <dd className="truncate text-text">{formatLastSeen(user.last_seen_at)}</dd>
              </div>
              {/* null means this admin is not cleared to see lifetime totals —
                  rendering ₹0 there would read as "this user never deposited". */}
              <div className="min-w-0">
                <dt className="text-faint">Deposited</dt>
                <dd className="tabular truncate text-text">
                  {user.deposit_approved_total == null ? "Hidden" : money(user.deposit_approved_total)}
                </dd>
              </div>
              <div className="min-w-0">
                <dt className="text-faint">Withdrawn</dt>
                <dd className="tabular truncate text-text">
                  {user.withdrawal_approved_total == null
                    ? "Hidden"
                    : money(user.withdrawal_approved_total)}
                </dd>
              </div>
            </dl>
          </Card>

          <Card>
            <CardTitle>Tags</CardTitle>
            <TagPicker userId={user.id} tags={user.tags} />
          </Card>
        </div>

        <div className="min-w-0 space-y-4">
          <Card>
            <CardTitle>Edit</CardTitle>
            <form className="space-y-4" onSubmit={handleSave}>
              <Input label="Name" value={name} onChange={(event) => setName(event.target.value)} />
              <Input
                label="Play ID"
                value={playId}
                onChange={(event) => setPlayId(event.target.value.toUpperCase())}
              />

              {formError ? <ErrorNote>{formError}</ErrorNote> : null}
              {notice ? (
                <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
              ) : null}

              <Button type="submit" loading={isSaving}>
                Save
              </Button>
            </form>
          </Card>

          <Card>
            <CardTitle>Actions</CardTitle>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" loading={isResetting} onClick={() => void handleResetMpin()}>
                Regenerate MPIN
              </Button>
              <Button
                variant={banned ? "primary" : "danger"}
                loading={isChangingStatus}
                onClick={() => void toggleBan()}
              >
                {banned ? "Unban user" : "Ban user"}
              </Button>
            </div>
            <p className="mt-3 text-xs text-faint">
              Banning blocks login immediately and logs the user out of the app.
            </p>
          </Card>
        </div>
      </div>
    </AdminShell>
  );
}
