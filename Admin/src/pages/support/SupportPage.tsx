import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import AdminShell from "@/components/AdminShell";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import Badge from "@/components/ui/Badge";
import { Input, Select } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconSend, IconUpload } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useSupportChatEnabled } from "@/hooks/useSupportChatEnabled";
import {
  useGetSupportConversationsQuery,
  useGetSupportThreadQuery,
  useSendSupportMessageMutation,
  useUpdateSupportStatusMutation,
} from "@/services/api";
import type { SupportMessage } from "@/types/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";

const LIST_POLL_MS = 15000;
const THREAD_POLL_MS = 8000;
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
const SEARCH_DEBOUNCE_MS = 350;

const clock = (value?: string | null) => {
  if (!value) return "";
  const ts = Date.parse(value);
  return Number.isNaN(ts)
    ? ""
    : new Date(ts).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
};

export default function SupportPage() {
  const [status, setStatus] = useState("open");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [activeId, setActiveId] = useState<number | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
  }, [search]);

  // While the super admin has support chat switched off, nothing here polls.
  const supportChatOn = useSupportChatEnabled();
  const list = useGetSupportConversationsQuery(
    { status: status || undefined, search: debouncedSearch || undefined },
    { skip: !supportChatOn, pollingInterval: LIST_POLL_MS, refetchOnMountOrArgChange: true }
  );
  useSessionGuard(list.error);

  const conversations = list.data ?? [];

  // Land on the first conversation so the pane is never an empty column, but
  // never yank the admin off a thread they are already reading.
  useEffect(() => {
    if (activeId === null && conversations.length) setActiveId(conversations[0].id);
  }, [activeId, conversations]);

  if (!supportChatOn) {
    return (
      <AdminShell title="Support" subtitle="Off">
        <Card>
          <EmptyState
            title="Support chat is off"
            body="The super admin has switched support chat off, so users cannot open it. Old chats are kept and come back when it is switched on again."
          />
        </Card>
      </AdminShell>
    );
  }

  return (
    <AdminShell title="Support" subtitle={`${conversations.length} conversations`}>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-3">
          <Card>
            <div className="grid gap-3">
              <Input
                label="Search"
                placeholder="Name, phone, play ID"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
              />
              <Select label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="">All</option>
              </Select>
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
                          {conversation.user?.name ?? "Unknown user"}
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
                      <span className="mt-0.5 block truncate text-xs text-faint">
                        {conversation.user?.unique_number ?? conversation.user?.phone ?? ""}
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
          {activeId ? (
            <Thread conversationId={activeId} />
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

function Thread({ conversationId }: { conversationId: number }) {
  const { data, isLoading, error } = useGetSupportThreadQuery(conversationId, {
    pollingInterval: THREAD_POLL_MS,
  });
  const [sendMessage, { isLoading: isSending }] = useSendSupportMessageMutation();
  const [updateStatus, { isLoading: isClosing }] = useUpdateSupportStatusMutation();
  useSessionGuard(error);

  const [draft, setDraft] = useState("");
  const [image, setImage] = useState<string | null>(null);
  const [sendError, setSendError] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const messages = data?.messages ?? [];
  const conversation = data?.conversation;

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: "end" });
  }, [messages.length]);

  useEffect(() => {
    setDraft("");
    setImage(null);
    setSendError(null);
  }, [conversationId]);

  const handleImage = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    if (!file.type.startsWith("image/")) return setSendError("Only images can be attached.");
    if (file.size > MAX_IMAGE_BYTES) return setSendError("That image is over 4 MB.");

    const reader = new FileReader();
    reader.onload = () => {
      setSendError(null);
      setImage(reader.result as string);
    };
    reader.readAsDataURL(file);
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const body = draft.trim();
    if (!body && !image) return;

    setSendError(null);
    try {
      await sendMessage({
        id: conversationId,
        body: body || undefined,
        image: image ?? undefined,
      }).unwrap();
      setDraft("");
      setImage(null);
    } catch (err) {
      setSendError(resolveErrorMessage(err, "Could not send that message."));
    }
  };

  const toggleStatus = async () => {
    if (!conversation) return;
    setSendError(null);
    try {
      await updateStatus({
        id: conversationId,
        status: conversation.status === "open" ? "closed" : "open",
      }).unwrap();
    } catch (err) {
      setSendError(resolveErrorMessage(err, "Could not change the status."));
    }
  };

  if (isLoading && !data) return <Skeleton className="h-[60dvh] w-full" />;

  return (
    <Card className="flex min-h-[60dvh] flex-col" padded={false}>
      <div className="flex min-w-0 items-center gap-3 border-b border-border p-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-text">
            {conversation?.user?.name ?? "Unknown user"}
          </p>
          <p className="truncate text-xs text-faint">
            {conversation?.user?.unique_number ?? conversation?.user?.phone ?? ""}
          </p>
        </div>
        <Badge tone={conversation?.status === "open" ? "pos" : "neutral"}>
          {conversation?.status ?? "—"}
        </Badge>
        <Button size="sm" variant="secondary" loading={isClosing} onClick={() => void toggleStatus()}>
          {conversation?.status === "open" ? "Close" : "Reopen"}
        </Button>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-3">
        {messages.length ? (
          <ul className="flex min-w-0 flex-col gap-2">
            {messages.map((message) => (
              <Bubble key={message.id} message={message} />
            ))}
          </ul>
        ) : (
          <EmptyState title="No messages yet" body="Send the first one below." />
        )}
        <div ref={endRef} />
      </div>

      {sendError ? (
        <div className="px-3">
          <ErrorNote>{sendError}</ErrorNote>
        </div>
      ) : null}

      {image ? (
        <div className="mx-3 mb-2 flex min-w-0 items-center gap-3 rounded-md border border-border bg-surface-2 p-2">
          <img src={image} alt="" className="size-12 shrink-0 rounded object-cover" />
          <span className="min-w-0 flex-1 truncate text-xs text-muted">Image ready to send</span>
          <Button size="sm" variant="ghost" onClick={() => setImage(null)}>
            Remove
          </Button>
        </div>
      ) : null}

      <form className="flex min-w-0 items-center gap-2 border-t border-border p-3" onSubmit={submit}>
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          aria-label="Attach an image"
          className="grid size-11 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted"
        >
          <IconUpload />
        </button>
        <input ref={fileInputRef} type="file" accept="image/*" className="sr-only" onChange={handleImage} />

        <input
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          placeholder="Type a reply"
          className="h-11 min-w-0 flex-1 rounded-full border border-border bg-surface-2 px-4 text-sm text-text placeholder:text-faint focus:border-accent focus:outline-none"
        />

        <button
          type="submit"
          disabled={isSending || (!draft.trim() && !image)}
          aria-label="Send"
          className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-on-accent disabled:opacity-55"
        >
          <IconSend />
        </button>
      </form>
    </Card>
  );
}

function Bubble({ message }: { message: SupportMessage }) {
  const outgoing = message.sender_type === "admin";

  if (message.deleted) {
    return (
      <li
        className={[
          "max-w-[82%] min-w-0 rounded-md border border-dashed border-border px-3 py-2 text-xs text-faint italic",
          outgoing ? "self-end" : "self-start",
        ].join(" ")}
      >
        Message deleted
      </li>
    );
  }

  return (
    <li
      className={["max-w-[82%] min-w-0 rounded-md px-3 py-2 text-sm", outgoing ? "self-end" : "self-start"].join(" ")}
      style={{
        background: outgoing ? "var(--accent-soft)" : "var(--surface-2)",
        border: outgoing ? "1px solid var(--accent-line)" : "1px solid var(--border)",
      }}
    >
      {message.image_url ? (
        <img
          src={message.image_url}
          alt=""
          loading="lazy"
          className="mb-1.5 max-h-64 w-full rounded object-contain"
        />
      ) : null}
      {message.audio_url ? (
        <audio controls src={message.audio_url} className="mb-1.5 w-full max-w-full" />
      ) : null}
      {message.body ? <p className="break-words whitespace-pre-wrap">{message.body}</p> : null}
      <p className="mt-1 text-right text-[10.5px] text-faint" title={formatDateTime(message.created_at)}>
        {clock(message.created_at)}
        {message.edited ? " · edited" : ""}
      </p>
    </li>
  );
}
