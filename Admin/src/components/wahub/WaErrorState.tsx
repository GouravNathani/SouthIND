import type { ReactNode } from "react";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/Feedback";
import { IconRefresh } from "@/components/icons";
import {
  WA_OFF_MESSAGE,
  WA_UNAVAILABLE_MESSAGE,
  isWaUnavailable,
  waErrorCode,
  waErrorMessage,
} from "@/components/wahub/waFormat";

/**
 * Whole-section failure: hub switched off, hub unreachable, or anything else.
 * The first two get their own friendly wording rather than an HTTP message.
 */
export default function WaErrorState({
  error,
  onRetry,
  retrying,
  offTitle = WA_OFF_MESSAGE.replace(/\.$/, ""),
  offBody = "Ask Super Admin to switch it on in Global settings.",
  offAction,
  fallback = "Could not load WhatsApp.",
}: {
  error: unknown;
  onRetry?: () => void;
  retrying?: boolean;
  offTitle?: string;
  offBody?: string;
  offAction?: ReactNode;
  fallback?: string;
}) {
  const off = waErrorCode(error) === "wa_hub_disabled";
  const unavailable = !off && isWaUnavailable(error);

  const retry = onRetry ? (
    <Button variant="secondary" size="sm" loading={retrying} onClick={onRetry}>
      <IconRefresh size={16} />
      Try again
    </Button>
  ) : undefined;

  return (
    <Card>
      {off ? (
        <EmptyState title={offTitle} body={offBody} action={offAction} />
      ) : unavailable ? (
        <EmptyState
          title={WA_UNAVAILABLE_MESSAGE.replace(/\.$/, "")}
          body="The connection to WhatsApp dropped for a moment. Nothing was lost."
          action={retry}
        />
      ) : (
        <EmptyState title="Something went wrong" body={waErrorMessage(error, fallback)} action={retry} />
      )}
    </Card>
  );
}
