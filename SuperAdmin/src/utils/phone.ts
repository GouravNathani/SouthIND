export const sanitizePhone = (value: string) => value.replace(/\D/g, "").slice(0, 10);

const allSameDigit = (value: string) => value.split("").every((digit) => digit === value[0]);

const startsWithMobilePrefix = (value: string) => /^[6-9]/.test(value);

const hasTooManySameDigits = (value: string, limit: number) => {
  const counts: Record<string, number> = {};
  for (const digit of value) {
    counts[digit] = (counts[digit] ?? 0) + 1;
    if (counts[digit] >= limit) return true;
  }
  return false;
};

const KNOWN_JUNK = new Set(["1234567890", "0987654321"]);

/** 1122334455 and friends — typed to satisfy the field, never a real number. */
const isDoubleDigitPattern = (value: string) => {
  if (value.length !== 10) return false;
  for (let i = 0; i < value.length; i += 2) {
    if (value[i] !== value[i + 1]) return false;
  }
  return true;
};

/**
 * Validates an Indian mobile number before a user is generated. The pattern
 * rules matter as much as the length one: a junk number means the MPIN is
 * delivered to nobody and the account becomes a support ticket.
 *
 * Returns null when the number is acceptable, or the reason it is not.
 */
export const validateMobile = (raw: string): string | null => {
  const phone = sanitizePhone(raw);

  if (phone.length !== 10) return "Phone number must be exactly 10 digits.";
  if (!startsWithMobilePrefix(phone)) return "Phone number must start with 6, 7, 8 or 9.";
  if (
    allSameDigit(phone) ||
    KNOWN_JUNK.has(phone) ||
    hasTooManySameDigits(phone, 7) ||
    isDoubleDigitPattern(phone)
  ) {
    return "That phone pattern is not allowed. Use a real WhatsApp number.";
  }
  return null;
};
