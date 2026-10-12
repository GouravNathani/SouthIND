import type { DepositRecord, WithdrawRecord } from "@/types/api";
import { money } from "@/utils/format";
import { resolveUploadUrl } from "@/utils/receipt";

/** Digits only. A bare 10-digit Indian mobile gets its 91 country code — wa.me needs one. */
export const normalizeWhatsappNumber = (value?: string | null) => {
  const digits = (value ?? "").replace(/\D/g, "");
  return digits.length === 10 ? `91${digits}` : digits;
};

/** With a number the chat opens straight to it; without one WhatsApp asks which chat to share into. */
export const buildWhatsappLink = (number: string, message: string) =>
  `https://wa.me/${number}?text=${encodeURIComponent(message)}`;

/** An image to send along with the message, e.g. the user's payment proof. */
export type ShareImage = { url: string; name: string };

/**
 * Phones and tablets whose share sheet takes a file: WhatsApp then gets the
 * image with the details as its caption. A wa.me link cannot carry a file, so
 * desktop keeps the link and sends the image's URL in the text instead.
 */
export const canShareFiles = () =>
  typeof navigator !== "undefined" &&
  typeof navigator.canShare === "function" &&
  typeof window.matchMedia === "function" &&
  window.matchMedia("(pointer: coarse)").matches;

const EXTENSIONS: Record<string, string> = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/gif": "gif",
  "image/heic": "heic",
};

const shareFiles = new Map<string, Promise<File | null>>();

/**
 * Downloads the image once. Started when the finger touches the button so the
 * file is usually ready when the tap lands; the backend's /storage files carry
 * `Access-Control-Allow-Origin: *`, so the panel origin may read them.
 *
 * The Hostinger CDN turns a JPEG into WebP for any request that accepts WebP
 * (the <img> on the page does), and WhatsApp may send a shared WebP as a
 * sticker. So skip the browser cache and ask for JPEG/PNG: the CDN then
 * returns the original file.
 */
export const loadShareFile = (image: ShareImage): Promise<File | null> => {
  const cached = shareFiles.get(image.url);
  if (cached) return cached;

  const pending = fetch(image.url, {
    cache: "no-store",
    headers: { Accept: "image/jpeg,image/png,image/*;q=0.8" },
  })
    .then((response) => (response.ok ? response.blob() : null))
    .then((blob) => {
      if (!blob || !blob.type.startsWith("image/")) return null;
      return new File([blob], `${image.name}.${EXTENSIONS[blob.type] ?? "jpg"}`, { type: blob.type });
    })
    .catch(() => null);
  shareFiles.set(image.url, pending);
  // A failed download is retried on the next tap rather than remembered.
  void pending.then((file) => {
    if (!file) shareFiles.delete(image.url);
  });
  return pending;
};

/**
 * Opens the share sheet with the image and the message. Falls back to the wa.me
 * link (text plus the image's URL) when the image cannot be read or shared.
 */
export const shareImageWithMessage = async (image: ShareImage, message: string, fallbackLink: string) => {
  const file = await loadShareFile(image);
  if (file && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({ files: [file], text: message });
      return;
    } catch (error) {
      // Closing the sheet is a choice, not a failure.
      if (error instanceof DOMException && error.name === "AbortError") return;
    }
  }

  const opened = window.open(fallbackLink, "_blank");
  if (opened) opened.opener = null;
  else window.location.assign(fallbackLink);
};

/** A bank account as a UPI address (90998898989@SBIN00001.ifsc.npci), payable from any UPI app. */
export const npciAddress = (accountNumber?: string | null, ifsc?: string | null) => {
  const account = (accountNumber ?? "").replace(/\s/g, "");
  const code = (ifsc ?? "").replace(/\s/g, "").toUpperCase();
  return account && code ? `${account}@${code}.ifsc.npci` : "";
};

type Line = [label: string, value: string | null | undefined];

/** Empty fields are left out, so a UPI request does not carry blank bank lines. */
const message = (title: string, lines: Line[]) =>
  [
    title,
    ...lines
      .filter(([, value]) => value?.trim())
      .map(([label, value]) => `${label}: ${value?.trim()}`),
  ].join("\n");

export const depositShareMessage = (deposit: DepositRecord) => {
  const accountNumber = deposit.account?.account_number ?? deposit.account_number;
  const ifsc = deposit.account?.ifsc_code ?? deposit.ifsc_code;
  return message("Deposit request", [
    ["User ID", deposit.play_id ?? deposit.user?.unique_number],
    ["Phone", deposit.user?.phone],
    ["Amount", money(deposit.amount)],
    ["Account", deposit.account?.name ?? deposit.account_name],
    ["Account No", accountNumber],
    ["IFSC", ifsc],
    ["A/C UPI", npciAddress(accountNumber, ifsc)],
    ["UPI", deposit.account?.upi_id ?? deposit.upi_id],
    ["UTR", deposit.utr_number],
  ]);
};

/** The payment proof the user attached, or null once it is gone (receipts are kept 7 days). */
export const depositShareImage = (deposit: DepositRecord): ShareImage | null => {
  const url = resolveUploadUrl(deposit.receipt_image_url ?? deposit.receipt_image_path);
  return url ? { url, name: `deposit-${deposit.id}-proof` } : null;
};

export const withdrawalShareMessage = (withdrawal: WithdrawRecord) =>
  message("Withdrawal request", [
    ["User ID", withdrawal.play_id ?? withdrawal.user?.unique_number],
    ["Phone", withdrawal.user?.phone],
    ["Amount", money(withdrawal.amount)],
    ["Account", withdrawal.account_name],
    ["Account No", withdrawal.account_number],
    ["IFSC", withdrawal.ifsc_code],
    ["A/C UPI", npciAddress(withdrawal.account_number, withdrawal.ifsc_code)],
    ["UPI", withdrawal.upi_id],
    ["Destination", withdrawal.destination_type],
  ]);
