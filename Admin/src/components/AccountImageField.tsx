import { useEffect, useState, type ChangeEvent } from "react";
import { IconClose, IconUpload } from "@/components/icons";

/** Matches the backend's `scanner_image` rule (image, max:4096 KB). */
const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

/**
 * Picker for an account's QR or logo image. The parent owns the chosen file
 * (it goes out as `scanner_image`); this only previews it and rejects files
 * the backend would refuse anyway, so the admin hears why straight away.
 */
export default function AccountImageField({
  isQr,
  file,
  onChange,
  onError,
  currentUrl,
}: {
  isQr: boolean;
  file: File | null;
  onChange: (file: File | null) => void;
  onError: (message: string | null) => void;
  /** Image already saved on the account, shown until a replacement is picked. */
  currentUrl?: string | null;
}) {
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!file) {
      setPreview(null);
      return;
    }
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  const handlePick = (event: ChangeEvent<HTMLInputElement>) => {
    const picked = event.target.files?.[0];
    event.target.value = "";
    if (!picked) return;
    if (!picked.type.startsWith("image/")) {
      onError("The logo or QR must be an image file.");
      return;
    }
    if (picked.size > MAX_IMAGE_BYTES) {
      onError("The image must be 4 MB or smaller.");
      return;
    }
    onError(null);
    onChange(picked);
  };

  const shown = preview ?? currentUrl ?? null;
  const pickLabel = currentUrl
    ? isQr
      ? "Replace the QR"
      : "Replace the logo"
    : isQr
      ? "Upload the QR to show users"
      : "Upload a bank or wallet logo";

  return (
    <div className="min-w-0">
      <p className="mb-1.5 text-xs font-medium text-muted">
        {isQr ? "QR image" : "Logo (optional)"}
      </p>

      {shown ? (
        <div className="relative mb-3 w-fit max-w-full">
          <img
            src={shown}
            alt=""
            className="max-h-52 max-w-full rounded-md border border-border object-contain"
          />
          {preview ? (
            <button
              type="button"
              onClick={() => onChange(null)}
              aria-label="Remove image"
              className="absolute top-2 right-2 grid size-8 place-items-center rounded-full border border-border bg-surface-solid text-muted"
            >
              <IconClose size={16} />
            </button>
          ) : null}
        </div>
      ) : null}

      {preview ? (
        currentUrl ? (
          <p className="text-xs text-faint">The new image replaces the current one when you save.</p>
        ) : null
      ) : (
        <label className="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-dashed border-border px-4 py-6 text-center">
          <span className="text-muted">
            <IconUpload size={20} />
          </span>
          <span className="text-sm font-medium text-text">{pickLabel}</span>
          <span className="text-xs text-faint">PNG or JPG, up to 4 MB</span>
          <input
            type="file"
            accept="image/*"
            className="sr-only"
            onChange={handlePick}
          />
        </label>
      )}
    </div>
  );
}
