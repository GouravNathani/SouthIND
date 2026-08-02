import { useEffect, useState, type FormEvent } from "react";
import AdminShell from "@/components/AdminShell";
import Card from "@/components/ui/Card";
import Badge from "@/components/ui/Badge";
import { Input, Select } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconSend } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import {
  useGetWhatsAppAccountsQuery,
  useGetWhatsAppConversationsQuery,
  useGetWhatsAppTemplatesQuery,
  useGetWhatsAppThreadQuery,
  useSendWhatsAppMessageMutation,
} from "@/services/api";
import type { WhatsAppMessage } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";

const LIST_POLL_MS = 20000;
const THREAD_POLL_MS = 10000;
const SEARCH_DEBOUNCE_MS = 350;

export default function WhatsAppInboxPage() {
  const accounts = useGetWhatsAppAccountsQuery();
  const [accountId, setAccountId] = useState<number | null>(null);
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [activeId, setActiveId] = useState<number | null>(null);

  useSessionGuard(accounts.error);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    if (accountId === null && accounts.data?.length) setAccountId(accounts.data[0].id);
  }, [accountId, accounts.data]);

  const list = useGetWhatsAppConversationsQuery(
    {
      whatsapp_account_id: accountId ?? undefined,
      search: debouncedSearch || undefined,
    },
    { pollingInterval: LIST_POLL_MS, skip: accountId === null }
  );

  const conversations = list.data ?? [];

  useEffect(() => {
    if (activeId === null && conversations.length) setActiveId(conversations[0].id);
  }, [activeId, conversations]);

  if (accounts.isLoading) {
    return (
      <AdminShell title="WhatsApp">
        <Skeleton className="h-64 w-full" />
      </AdminShell>
    );
  }

  if (!accounts.data?.length) {
    return (
      <AdminShell title="WhatsApp">
        <Card>
          <EmptyState
            title="No WhatsApp account connected"
            body="A super admin connects the business account before this inbox has anything to show."
          />
        </Card>
      </AdminShell>
    );
  }

  return (
    <AdminShell title="WhatsApp" subtitle={`${conversations.length} conversations`}>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-3">
          <Card>
            <div className="grid gap-3">
              <Select
                label="Account"
                value={String(accountId ?? "")}
                onChange={(event) => {
                  setAccountId(Number(event.target.value));
                  setActiveId(null);
                }}
              >
                {accounts.data.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.display_name}
                    {account.phone_number ? ` · ${account.phone_number}` : ""}
                  </option>
                ))}
              </Select>
              <Input
                label="Search"
                placeholder="Name or phone"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
          </Card>

          {list.isLoading && !conversations.length ? (
            <Skeleton className="h-64 w-full" />
          ) : conversations.length ? (
            <ul className="min-w-0 space-y-2">
              {conversations.map((conversation) => {
                const active = conversation.id === activeId;
                return (
                  <li key={conversation.id} className="min-w-0">
                    <button
                      type="button"
                      onClick={() => setActiveId(conversation.id)}
                      className="w-full min-w-0 rounded-lg border p-3 text-left transition-colors"
                      style={{
                        borderColor: active ? "var(--accent)" : "var(--border)",
                        background: active ? "var(--accent-soft)" : "var(--surface)",
                      }}
                    >
                      <span className="flex min-w-0 items-center gap-2">
                        <span className="min-w-0 flex-1 truncate text-sm font-medium text-text">
                          {conversation.contact_name ?? conversation.contact_phone}
                        </span>
                        {conversation.unread_count > 0 ? (
                          <span
                            className="tabular shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                            style={{ background: "var(--accent-2)", color: "var(--text-on-accent)" }}
                          >
                            {conversation.unread_count}
                          </span>
                        ) : null}
                      </span>
                      <span className="mt-1 block truncate text-xs text-muted">
                        {conversation.last_message_preview ?? "No messages yet"}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          ) : (
            <Card>
              <EmptyState title="No conversations" body="Nothing matches these filters." />
            </Card>
          )}
        </div>

        <div className="min-w-0">
          {activeId && accountId ? (
            <Thread conversationId={activeId} accountId={accountId} />
          ) : (
            <Card>
              <EmptyState title="Pick a conversation" body="Select one on the left to reply." />
            </Card>
          )}
        </div>
      </div>
    </AdminShell>
  );
}

function Thread({ conversationId, accountId }: { conversationId: number; accountId: number }) {
  const { data, isLoading, error } = useGetWhatsAppThreadQuery(conversationId, {
    pollingInterval: THREAD_POLL_MS,
  });
  const templates = useGetWhatsAppTemplatesQuery({ whatsapp_account_id: accountId });
  const [sendMessage, { isLoading: isSending }] = useSendWhatsAppMessageMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState("");
  const [templateName, setTemplateName] = useState("");
  const [sendError, setSendError] = useState<string | null>(null);

  useEffect(() => {
    setDraft("");
    setTemplateName("");
    setSendError(null);
  }, [conversationId]);

  const conversation = data?.conversation;
  const messages = data?.messages ?? [];

  // WhatsApp only allows free-form replies within 24h of the last inbound
  // message. Outside it the API rejects anything but an approved template, so
  // the composer switches instead of letting the admin type into a void.
  const inWindow = Boolean(conversation?.within_24h_window);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!conversation) return;

    setSendError(null);
    try {
      await sendMessage({
        whatsapp_account_id: conversation.whatsapp_account_id,
        to: conversation.contact_phone,
        ...(inWindow ? { message: draft.trim() } : { template_name: templateName, language: "en" }),
      }).unwrap();
      setDraft("");
    } catch (err) {
      setSendError(resolveErrorMessage(err, "Could not send that message."));
    }
  };

  if (isLoading && !data) return <Skeleton className="h-[60dvh] w-full" />;

  return (
    <Card className="flex min-h-[60dvh] flex-col" padded={false}>
      <div className="flex min-w-0 items-center gap-3 border-b border-border p-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-text">
            {conversation?.contact_name ?? conversation?.contact_phone ?? "—"}
          </p>
          <p className="tabular truncate text-xs text-faint">{conversation?.contact_phone}</p>
        </div>
        <Badge tone={inWindow ? "pos" : "warn"}>{inWindow ? "24h window open" : "Template only"}</Badge>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-3">
        {messages.length ? (
          <ul className="flex min-w-0 flex-col gap-2">
            {messages.map((message) => (
              <Bubble key={message.id} message={message} />
            ))}
          </ul>
        ) : (
          <EmptyState title="No messages yet" body="Nothing has been exchanged on this number." />
        )}
      </div>

      {sendError ? (
        <div className="px-3">
          <ErrorNote>{sendError}</ErrorNote>
        </div>
      ) : null}

      <form className="flex min-w-0 items-center gap-2 border-t border-border p-3" onSubmit={submit}>
        {inWindow ? (
          <input
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder="Type a reply"
            className="h-11 min-w-0 flex-1 rounded-full border border-border bg-surface-2 px-4 text-sm text-text placeholder:text-faint focus:border-accent focus:outline-none"
          />
        ) : (
          <select
            value={templateName}
            onChange={(event) => setTemplateName(event.target.value)}
            className="h-11 min-w-0 flex-1 rounded-full border border-border bg-surface-2 px-4 text-sm text-text focus:border-accent focus:outline-none"
          >
            <option value="">Choose an approved template…</option>
            {(templates.data ?? []).map((template) => (
              <option key={template.id} value={template.name}>
                {template.name} ({template.language})
              </option>
            ))}
          </select>
        )}

        <button
          type="submit"
          disabled={isSending || (inWindow ? !draft.trim() : !templateName)}
          aria-label="Send"
          className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-on-accent disabled:opacity-55"
        >
          <IconSend />
        </button>
      </form>
    </Card>
  );
}

function Bubble({ message }: { message: WhatsAppMessage }) {
  const outgoing = message.direction === "outbound";

  return (
    <li
      className={["max-w-[82%] min-w-0 rounded-md px-3 py-2 text-sm", outgoing ? "self-end" : "self-start"].join(" ")}
      style={{
        background: outgoing ? "var(--accent-soft)" : "var(--surface-2)",
        border: outgoing ? "1px solid var(--accent-line)" : "1px solid var(--border)",
      }}
    >
      {message.media_url ? (
        <img
          src={message.media_url}
          alt=""
          loading="lazy"
          className="mb-1.5 max-h-64 w-full rounded object-contain"
        />
      ) : null}
      {message.template_name ? (
        <p className="mb-1 text-[11px] font-semibold tracking-wide text-accent uppercase">
          Template · {message.template_name}
        </p>
      ) : null}
      {message.body ? <p className="break-words whitespace-pre-wrap">{message.body}</p> : null}
      <p className="mt-1 text-right text-[10.5px] text-faint">
        {formatDateTime(message.created_at)}
        {message.status ? ` · ${message.status}` : ""}
      </p>
    </li>
  );
}
