import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Button from "@/components/ui/Button";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconSend, IconUpload } from "@/components/icons";
import {
  useGetAppSettingsQuery,
  useGetSupportChatQuery,
  useSendSupportMessageMutation,
  type SupportChatMessage,
} from "@/services/api";
import { getApiErrorMessage, isSupportChatOffError } from "@/utils/apiError";

const POLL_INTERVAL_MS = 8000;
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

const dayLabel = (value?: string | null) => {
  if (!value) return "";
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return "";
  return new Date(ts).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
};

const clockLabel = (value?: string | null) => {
  if (!value) return "";
  const ts = Date.parse(value);
  if (Number.isNaN(ts)) return "";
  return new Date(ts).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
};

export default function ChatPage() {
  const { t } = useTranslation();
  // The super admin can switch support chat off; then nothing here polls.
  const { data: appSettings } = useGetAppSettingsQuery();
  const [refusedByServer, setRefusedByServer] = useState(false);
  const chatOn = appSettings?.support_chat_enabled !== false && !refusedByServer;
  const { data, isLoading, error, isFetching } = useGetSupportChatQuery(undefined, {
    skip: !chatOn,
    pollingInterval: POLL_INTERVAL_MS,
    refetchOnMountOrArgChange: true,
  });
  useEffect(() => {
    if (!isFetching && isSupportChatOffError(error)) setRefusedByServer(true);
  }, [error, isFetching]);
  const [sendMessage, { isLoading: sending }] = useSendSupportMessageMutation();

  const [draft, setDraft] = useState("");
  const [image, setImage] = useState<string | null>(null);
  const [sendError, setSendError] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const messages = useMemo(() => data?.messages ?? [], [data?.messages]);

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: "end" });
  }, [messages.length]);

  const handleImage = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      setSendError(t("chat.imageOnly"));
      return;
    }
    if (file.size > MAX_IMAGE_BYTES) {
      setSendError(t("chat.imageTooLarge"));
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      setSendError(null);
      setImage(reader.result as string);
    };
    reader.readAsDataURL(file);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const body = draft.trim();
    if (!body && !image) return;

    setSendError(null);
    try {
      await sendMessage({ body: body || undefined, image: image ?? undefined }).unwrap();
      setDraft("");
      setImage(null);
    } catch (err) {
      setSendError(getApiErrorMessage(err, t("common.unexpected")));
    }
  };

  if (!chatOn) {
    return (
      <AppShell title={t("common.support")}>
        <EmptyState title={t("chat.off")} />
      </AppShell>
    );
  }

  return (
    <AppShell fill title={t("common.support")} subtitle={data?.conversation?.status ?? undefined}>
      <div className="flex min-h-0 flex-1 flex-col gap-3">
        <div className="min-h-0 min-w-0 flex-1 overflow-y-auto rounded-lg border border-border bg-surface p-3">
          {isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 4 }, (_, i) => (
                <Skeleton key={i} className="h-12 w-2/3" />
              ))}
            </div>
          ) : error ? (
            <ErrorNote>{getApiErrorMessage(error, t("chat.error"))}</ErrorNote>
          ) : messages.length ? (
            <ul className="flex min-w-0 flex-col gap-2">
              {messages.map((message, index) => (
                <MessageBubble
                  key={message.id}
                  message={message}
                  showDay={dayLabel(message.created_at) !== dayLabel(messages[index - 1]?.created_at)}
                />
              ))}
            </ul>
          ) : (
            <EmptyState title={t("chat.empty")} body={t("chat.emptyBody")} />
          )}
          <div ref={endRef} />
        </div>

        {sendError ? <ErrorNote>{sendError}</ErrorNote> : null}

        {image ? (
          <div className="flex min-w-0 items-center gap-3 rounded-md border border-border bg-surface p-2">
            <img src={image} alt="" className="size-12 shrink-0 rounded-sm object-cover" />
            <span className="min-w-0 flex-1 truncate text-xs text-muted">{t("chat.imageReady")}</span>
            <Button size="sm" variant="ghost" type="button" onClick={() => setImage(null)}>
              {t("common.remove")}
            </Button>
          </div>
        ) : null}

        <form className="flex min-w-0 items-center gap-2" onSubmit={handleSubmit}>
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            aria-label={t("chat.attach")}
            className="grid size-11 shrink-0 place-items-center rounded-full border border-border bg-surface text-muted"
          >
            <IconUpload />
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            className="sr-only"
            onChange={handleImage}
          />

          <input
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder={t("chat.placeholder")}
            className="h-11 min-w-0 flex-1 rounded-full border border-border bg-surface-2 px-4 text-sm text-text placeholder:text-faint focus:border-accent focus:outline-none"
          />

          <button
            type="submit"
            disabled={sending || (!draft.trim() && !image)}
            aria-label={t("chat.send")}
            className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-on-accent disabled:opacity-55"
          >
            <IconSend />
          </button>
        </form>
      </div>
    </AppShell>
  );
}

function MessageBubble({
  message,
  showDay,
}: {
  message: SupportChatMessage;
  showDay: boolean;
}) {
  const outgoing = message.sender_type === "user";

  return (
    <>
      {showDay ? (
        <li className="my-1 self-center rounded-full bg-surface-2 px-3 py-1 text-[10.5px] tracking-wide text-faint uppercase">
          {dayLabel(message.created_at)}
        </li>
      ) : null}

      <li
        className={[
          "max-w-[82%] min-w-0 rounded-md px-3 py-2 text-sm",
          outgoing ? "self-end" : "self-start",
        ].join(" ")}
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
            className="mb-1.5 max-h-64 w-full rounded-sm object-contain"
          />
        ) : null}
        {message.audio_url ? (
          <audio controls src={message.audio_url} className="mb-1.5 w-full max-w-full" />
        ) : null}
        {message.body ? <p className="break-words whitespace-pre-wrap">{message.body}</p> : null}
        <p className="mt-1 text-right text-[10.5px] text-faint">
          {clockLabel(message.created_at)}
          {message.edited ? " · edited" : ""}
        </p>
      </li>
    </>
  );
}
