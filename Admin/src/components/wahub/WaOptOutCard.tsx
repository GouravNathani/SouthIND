import { useState } from "react";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { waErrorCode, waErrorMessage } from "@/components/wahub/waFormat";
import { useWaHubEnabled } from "@/hooks/useWaHubEnabled";
import { useGetWaUserOptOutQuery, useSetWaUserOptOutMutation } from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";

/**
 * "Exclude from WhatsApp" for one user. A STOP reply excludes the user by
 * itself; the admin can exclude by hand too. Excluded = no event or campaign
 * message from this branch's number. Hidden while the hub is switched off.
 */
export default function WaOptOutCard({ userId }: { userId: number }) {
  const hubOn = useWaHubEnabled();
  const { data, isLoading, error } = useGetWaUserOptOutQuery(userId, { skip: !hubOn });
  const [save, { isLoading: saving }] = useSetWaUserOptOutMutation();
  const [confirm, setConfirm] = useState<null | boolean>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  if (!hubOn || waErrorCode(error) === "wa_hub_disabled") return null;

  const apply = async (excluded: boolean) => {
    setSaveError(null);
    try {
      await save({ id: userId, excluded }).unwrap();
      setConfirm(null);
    } catch (err) {
      setSaveError(waErrorMessage(err, "Could not change WhatsApp for this user."));
    }
  };

  const excluded = data?.excluded === true;
  const byStop = excluded && data?.reason === "stop_reply";
  const when = data?.excluded_at ? ` on ${formatDateTime(data.excluded_at)}` : "";

  return (
    <Card>
      <div className="flex min-w-0 items-start justify-between gap-3">
        <CardTitle>WhatsApp</CardTitle>
        {data?.has_phone ? (
          <Badge tone={excluded ? "neg" : "pos"}>{excluded ? "Excluded" : "Receiving"}</Badge>
        ) : null}
      </div>

      {isLoading ? (
        <Skeleton className="h-16 w-full" />
      ) : error ? (
        <ErrorNote>{waErrorMessage(error, "Could not load WhatsApp status.")}</ErrorNote>
      ) : !data?.has_phone ? (
        <p className="text-sm text-muted">This user has no phone number.</p>
      ) : (
        <>
          <p className="text-sm text-muted">
            {byStop
              ? `The user replied STOP${when}. No WhatsApp message goes to them from this branch until they reply START or you include them again.`
              : excluded
                ? `Excluded by an admin${when}. No WhatsApp message goes to them from this branch. Their START reply does not undo this.`
                : "Gets event messages (deposit, withdrawal…) and campaigns from this branch's number. A STOP reply excludes them by itself."}
          </p>

          {saveError && confirm === null ? <div className="mt-3"><ErrorNote>{saveError}</ErrorNote></div> : null}

          <div className="mt-3">
            <Button
              variant={excluded ? "primary" : "danger"}
              loading={saving && confirm === null}
              onClick={() => (byStop ? setConfirm(false) : excluded ? void apply(false) : setConfirm(true))}
            >
              {excluded ? "Include in WhatsApp" : "Exclude from WhatsApp"}
            </Button>
          </div>
        </>
      )}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm ? "Exclude from WhatsApp?" : "Include this user again?"}
        confirmLabel={confirm ? "Exclude" : "Include"}
        tone={confirm ? "danger" : "primary"}
        loading={saving}
        error={saveError}
        onConfirm={() => void apply(Boolean(confirm))}
        onCancel={() => {
          setConfirm(null);
          setSaveError(null);
        }}
      >
        {confirm
          ? "No event or campaign message will go to this user from this branch's number until you include them again."
          : "This user replied STOP. Send them messages again only if they asked for it."}
      </ConfirmDialog>
    </Card>
  );
}
