import type { DepositRecord, WithdrawRecord } from "@/types/api";
import { money } from "@/utils/format";

/** Digits only. A bare 10-digit Indian mobile gets its 91 country code — wa.me needs one. */
export const normalizeWhatsappNumber = (value?: string | null) => {
  const digits = (value ?? "").replace(/\D/g, "");
  return digits.length === 10 ? `91${digits}` : digits;
};

/** With a number the chat opens straight to it; without one WhatsApp asks which chat to share into. */
export const buildWhatsappLink = (number: string, message: string) =>
  `https://wa.me/${number}?text=${encodeURIComponent(message)}`;

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
