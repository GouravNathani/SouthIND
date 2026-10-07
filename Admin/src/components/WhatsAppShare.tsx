import { IconWhatsApp } from "@/components/icons";
import { buildWhatsappLink, normalizeWhatsappNumber } from "@/utils/whatsapp";

/**
 * Opens WhatsApp with the request already typed, to the branch's number from
 * Settings (or a chat picker when none is set). A real link rather than a
 * Button: phones only hand wa.me to the app on a navigation. Clicks stop here
 * so the queue row underneath does not open as well.
 */
export default function WhatsAppShare({
  number,
  message,
  label = "Share",
}: {
  number?: string | null;
  message: string;
  label?: string;
}) {
  return (
    <a
      href={buildWhatsappLink(normalizeWhatsappNumber(number), message)}
      target="_blank"
      rel="noreferrer"
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => event.stopPropagation()}
      aria-label="Share on WhatsApp"
      className="inline-flex h-9 min-w-0 shrink-0 items-center justify-center gap-2 rounded-full border border-border bg-surface px-3.5 text-[13px] font-semibold whitespace-nowrap text-text transition-[border-color] duration-150 hover:border-border-strong [&_svg]:shrink-0"
    >
      <IconWhatsApp size={16} />
      <span className="min-w-0 truncate">{label}</span>
    </a>
  );
}
