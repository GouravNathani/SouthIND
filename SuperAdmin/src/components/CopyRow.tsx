import { useEffect, useState } from "react";
import { IconCheck, IconCopy } from "@/components/icons";

/** Clipboard with an execCommand fallback — the in-app browsers users open
 *  payment links from often refuse navigator.clipboard on http origins. */
const copyText = async (value: string) => {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }
    const textarea = document.createElement("textarea");
    textarea.value = value;
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand("copy");
    document.body.removeChild(textarea);
    return true;
  } catch {
    return false;
  }
};

export default function CopyRow({ label, value }: { label: string; value?: string | null }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const timer = window.setTimeout(() => setCopied(false), 1800);
    return () => window.clearTimeout(timer);
  }, [copied]);

  if (!value?.trim()) return null;

  return (
    <div className="flex min-w-0 items-center gap-3 border-b border-border py-2.5 last:border-0">
      <div className="min-w-0 flex-1">
        <p className="text-[11px] tracking-wide text-muted uppercase">{label}</p>
        <p className="tabular text-sm [overflow-wrap:anywhere] text-text">{value}</p>
      </div>
      <button
        type="button"
        onClick={async () => setCopied(await copyText(value.trim()))}
        className="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-surface-2 px-3 py-1.5 text-[11px] font-semibold text-muted"
        aria-label={`Copy ${label}`}
      >
        {copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
        {copied ? "Copied" : "Copy"}
      </button>
    </div>
  );
}
