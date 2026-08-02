import { useState, type FormEvent } from "react";
import SuperShell from "@/components/SuperShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import DataTable, { type Column } from "@/components/ui/DataTable";
import { Input } from "@/components/ui/Field";
import { ErrorNote } from "@/components/ui/Feedback";
import { IconPlus, IconRefresh, IconTrash } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useCreateWhatsAppAccountMutation,
  useDeleteWhatsAppAccountMutation,
  useGetWhatsAppAccountsQuery,
  useSyncWhatsAppTemplatesMutation,
  useUpdateWhatsAppAccountMutation,
} from "@/services/api";
import type { WhatsAppAccount } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";

const emptyDraft = {
  display_name: "",
  waba_id: "",
  phone_number_id: "",
  phone_number: "",
  access_token: "",
  webhook_verify_token: "",
};

export default function WhatsAppAccountsPage() {
  const { data: accounts = [], isFetching, error } = useGetWhatsAppAccountsQuery();
  const [createAccount, { isLoading: isCreating }] = useCreateWhatsAppAccountMutation();
  const [updateAccount, { isLoading: isUpdating }] = useUpdateWhatsAppAccountMutation();
  const [deleteAccount, { isLoading: isDeleting }] = useDeleteWhatsAppAccountMutation();
  const [syncTemplates, { isLoading: isSyncing }] = useSyncWhatsAppTemplatesMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState(emptyDraft);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);

  const patch = (partial: Partial<typeof draft>) => setDraft((prev) => ({ ...prev, ...partial }));

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    setNotice(null);

    if (!draft.display_name.trim()) return setFormError("Give the account a name staff will recognise.");
    if (!draft.waba_id.trim() || !draft.phone_number_id.trim()) {
      return setFormError("WABA id and phone number id both come from Meta Business Manager.");
    }
    if (!draft.access_token.trim()) return setFormError("A permanent access token is required.");

    try {
      await createAccount({
        display_name: draft.display_name.trim(),
        waba_id: draft.waba_id.trim(),
        phone_number_id: draft.phone_number_id.trim(),
        phone_number: draft.phone_number.trim(),
        access_token: draft.access_token.trim(),
        webhook_verify_token: draft.webhook_verify_token.trim(),
      }).unwrap();
      setDraft(emptyDraft);
      setNotice("Account connected. Sync its templates before staff try to use them.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not connect this account."));
    }
  };

  const toggleActive = async (account: WhatsAppAccount) => {
    setFormError(null);
    try {
      await updateAccount({
        id: account.id,
        body: { status: account.status === "active" ? "inactive" : "active" },
      }).unwrap();
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not update this account."));
    }
  };

  const sync = async (id: number) => {
    setFormError(null);
    setNotice(null);
    try {
      const result = await syncTemplates(id).unwrap();
      setNotice(`Synced ${result.synced_count} template${result.synced_count === 1 ? "" : "s"} from Meta.`);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not sync templates."));
    }
  };

  const remove = async (id: number) => {
    setFormError(null);
    try {
      await deleteAccount(id).unwrap();
      setConfirmingId(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not delete this account."));
    }
  };

  const columns: Column<WhatsAppAccount>[] = [
    {
      key: "account",
      header: "Account",
      render: (row) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-text">{row.display_name}</span>
          <span className="tabular block truncate text-xs text-faint">{row.phone_number ?? ""}</span>
        </span>
      ),
    },
    {
      key: "ids",
      header: "WABA / phone id",
      render: (row) => (
        <span className="tabular block truncate text-xs">
          {row.waba_id} · {row.phone_number_id}
        </span>
      ),
      secondary: true,
    },
    {
      key: "templates",
      header: "Templates",
      render: (row) => <span className="tabular">{row.templates_count ?? 0}</span>,
    },
    {
      key: "token",
      header: "Token",
      // A connected account with no stored token fails only at send time, so it
      // is surfaced here rather than discovered by an admin mid-conversation.
      render: (row) => (
        <Badge tone={row.has_token ? "pos" : "neg"}>{row.has_token ? "Stored" : "Missing"}</Badge>
      ),
    },
    {
      key: "status",
      header: "Status",
      render: (row) => (
        <Badge tone={row.status === "active" ? "pos" : "neutral"}>{row.status}</Badge>
      ),
    },
    {
      key: "created",
      header: "Connected",
      render: (row) => (
        <span className="whitespace-nowrap">{row.created_at ? formatDateTime(row.created_at) : "—"}</span>
      ),
      secondary: true,
    },
    {
      key: "actions",
      header: "",
      align: "right",
      render: (row) => (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          <Button size="sm" variant="ghost" loading={isSyncing} onClick={() => void sync(row.id)}>
            <IconRefresh size={16} />
            Templates
          </Button>
          <Button size="sm" variant="secondary" loading={isUpdating} onClick={() => void toggleActive(row)}>
            {row.status === "active" ? "Disable" : "Enable"}
          </Button>
          {confirmingId === row.id ? (
            <>
              <Button size="sm" variant="danger" loading={isDeleting} onClick={() => void remove(row.id)}>
                Delete
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setConfirmingId(null)}>
                Cancel
              </Button>
            </>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => setConfirmingId(row.id)}>
              <IconTrash size={16} />
            </Button>
          )}
        </span>
      ),
    },
  ];

  return (
    <SuperShell title="WhatsApp accounts" subtitle={`${accounts.length} connected`}>
      <div className="space-y-4">
        <Card>
          <CardTitle hint="From Meta Business Manager">Connect an account</CardTitle>
          <form className="space-y-4" onSubmit={create}>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Input
                label="Display name"
                value={draft.display_name}
                onChange={(event) => patch({ display_name: event.target.value })}
                hint="What branch staff see in the inbox picker."
                required
              />
              <Input
                label="Phone number"
                value={draft.phone_number}
                onChange={(event) => patch({ phone_number: event.target.value })}
                placeholder="+919000000000"
              />
              <Input
                label="WABA id"
                value={draft.waba_id}
                onChange={(event) => patch({ waba_id: event.target.value.trim() })}
                required
              />
              <Input
                label="Phone number id"
                value={draft.phone_number_id}
                onChange={(event) => patch({ phone_number_id: event.target.value.trim() })}
                required
              />
              <Input
                label="Access token"
                type="password"
                value={draft.access_token}
                onChange={(event) => patch({ access_token: event.target.value })}
                hint="Permanent token. Stored encrypted and never shown again."
                required
              />
              <Input
                label="Webhook verify token"
                value={draft.webhook_verify_token}
                onChange={(event) => patch({ webhook_verify_token: event.target.value })}
                hint="Must match what you set on Meta's webhook."
              />
            </div>

            {formError ? <ErrorNote>{formError}</ErrorNote> : null}
            {notice ? (
              <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
            ) : null}

            <Button type="submit" loading={isCreating}>
              <IconPlus size={16} />
              Connect
            </Button>
          </form>
        </Card>

        <DataTable
          columns={columns}
          rows={accounts}
          keyOf={(row) => row.id}
          loading={isFetching && !accounts.length}
          emptyTitle="No WhatsApp account connected"
          emptyBody="Branch inboxes stay empty until one is connected here."
        />
      </div>
    </SuperShell>
  );
}
