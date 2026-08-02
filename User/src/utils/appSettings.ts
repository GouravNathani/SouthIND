import type { AppSettings } from "@/services/api";

export const coerceToString = (value: unknown): string => {
  if (typeof value === "string") {
    return value.trim();
  }
  if (typeof value === "number") {
    return String(value);
  }
  return "";
};

export const deriveWhatsAppLink = (
  settings: AppSettings | null | undefined
): string | null => {
  const resolvedLink = coerceToString(settings?.whatsapp_link);
  if (resolvedLink) {
    return resolvedLink;
  }
  const whatsappNumber = coerceToString(settings?.whatsapp_number);
  if (whatsappNumber) {
    const digits = whatsappNumber.replace(/\D/g, "");
    return digits.length ? `https://wa.me/${digits}` : null;
  }
  return null;
};
