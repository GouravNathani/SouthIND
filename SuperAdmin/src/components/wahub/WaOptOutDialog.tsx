import { useState } from "react";
import Badge from "@/components/ui/Badge";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Skeleton } from "@/components/ui/Feedback";
import { waErrorMessage } from "@/components/wahub/waFormat";
import { useGetWaUserOptOutQuery, useSetWaUserOptOutMutation } from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";

/**
 * "Exclude from WhatsApp" for one user, from the Users list. A STOP reply
 * excludes the user by itself; this excludes / includes them by hand.
 * Excluded = no event or campaign message from their branch's number.
 */
export default function WaOptOutDialog({
  user,
  onClose,
}: {
  user: { id: number; name?: string | null };
  onClose: () => void;
}) {
  const { data, isLoading, error } = useGetWaUserOptOutQuery(user.id, { refetchOnMountOrArgChange: true });
  const [save, { isLoading: saving }] = useSetWaUserOptOutMutation();
  const [saveError, setSaveError] = useState<string | null>(null);

  const ready = Boolean(data?.has_phone) && !error;
  const excluded = data?.excluded === true;
  const byStop = excluded && data?.reason === "stop_reply";
  const when = data?.excluded_at ? ` on ${formatDateTime(data.excluded_at)}` : "";

  const confirm = async () => {
    if (!ready) return onClose();
    setSaveError(null);
    try {
      await save({ id: user.id, excluded: !excluded }).unwrap();
      onClose();
    } catch (err) {
      setSaveError(waErrorMessage(err, "Could not change WhatsApp for this user."));
    }
  };

  return (
    <ConfirmDialog
      open
      title={`WhatsApp · ${user.name || "User"}`}
      confirmLabel={!ready ? "Close" : excluded ? "Include in WhatsApp" : "Exclude from WhatsApp"}
      cancelLabel={ready ? "Cancel" : "Back"}
      tone={ready && !excluded ? "danger" : "primary"}
      loading={saving}
      error={saveError ?? (error ? waErrorMessage(error, "Could not load WhatsApp status.") : null)}
      onConfirm={() => void confirm()}
      onCancel={onClose}
    >
      {isLoading ? (
        <Skeleton className="h-12 w-full" />
      ) : error ? null : !data?.has_phone ? (
        "This user has no phone number."
      ) : (
        <span className="block space-y-2">
          <Badge tone={excluded ? "neg" : "pos"}>{excluded ? "Excluded" : "Receiving"}</Badge>
          <span className="block">
            {byStop
              ? `The user replied STOP${when}. Include them again only if they asked for it.`
              : excluded
                ? `Excluded by an admin${when}. Their START reply does not undo this.`
                : "Gets event messages and campaigns from their branch's number. A STOP reply excludes them by itself."}
          </span>
        </span>
      )}
    </ConfirmDialog>
  );
}
