import { IconWhatsApp } from "@/components/icons";
import {
  buildWhatsappLink,
  canShareFiles,
  loadShareFile,
  normalizeWhatsappNumber,
  shareImageWithMessage,
  type ShareImage,
} from "@/utils/whatsapp";

/**
 * Opens WhatsApp with the request already typed, to the branch's number from
 * Settings (or a chat picker when none is set). A real link rather than a
 * Button: phones only hand wa.me to the app on a navigation. Clicks stop here
 * so the queue row underneath does not open as well.
 *
 * With an image (a deposit's payment proof), phones open the share sheet
 * instead so WhatsApp receives the picture with the details as its caption;
 * the chat is picked there, as a shared file cannot be addressed to a number.
 * Desktop keeps the link and adds the image's URL to the text.
 */
export default function WhatsAppShare({
  number,
  message,
  label = "Share",
  image,
}: {
  number?: string | null;
  message: string;
  label?: string;
  image?: ShareImage | null;
}) {
  const text = image ? `${message}\nProof: ${image.url}` : message;
  const link = buildWhatsappLink(normalizeWhatsappNumber(number), text);

  return (
    <a
      href={link}
      target="_blank"
      rel="noreferrer"
      onPointerDown={() => {
        if (image && canShareFiles()) void loadShareFile(image);
      }}
      onClick={(event) => {
        event.stopPropagation();
        if (!image || !canShareFiles()) return;
        event.preventDefault();
        void shareImageWithMessage(image, message, link);
      }}
      onKeyDown={(event) => event.stopPropagation()}
      aria-label="Share on WhatsApp"
      className="inline-flex h-9 min-w-0 shrink-0 items-center justify-center gap-2 rounded-full border border-border bg-surface px-3.5 text-[13px] font-semibold whitespace-nowrap text-text transition-[border-color] duration-150 hover:border-border-strong [&_svg]:shrink-0"
    >
      <IconWhatsApp size={16} />
      <span className="min-w-0 truncate">{label}</span>
    </a>
  );
}
