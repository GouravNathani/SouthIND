import { useRef, useState, type ChangeEvent } from "react";
import { useNavigate } from "react-router-dom";
import SuperShell from "@/components/SuperShell";
import AccountForm, { toFormValues, type AccountFormValues } from "@/components/AccountForm";
import Button from "@/components/ui/Button";
import { IconBack, IconClose, IconUpload } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useCreateAccountMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";

export default function NewAccountPage() {
  const navigate = useNavigate();
  const [createAccount, { isLoading, error: mutationError }] = useCreateAccountMutation();
  useSessionGuard(mutationError);

  const [values, setValues] = useState<AccountFormValues>(toFormValues());
  const [logo, setLogo] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const handleLogo = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      setError("The logo or QR must be an image file.");
      event.target.value = "";
      return;
    }
    setError(null);
    setLogo(file);
    setLogoPreview(URL.createObjectURL(file));
  };

  const clearLogo = () => {
    setLogo(null);
    setLogoPreview(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const submit = async () => {
    setError(null);

    // Multipart only when there is a file — a JSON body is easier for the
    // backend to validate, and most accounts have no image at all.
    const payload = logo
      ? (() => {
          const form = new FormData();
          for (const [key, value] of Object.entries(values)) {
            if (value !== "") form.append(key, value);
          }
          form.append("logo", logo);
          return form;
        })()
      : {
          ...values,
          min_deposit: values.min_deposit ? Number(values.min_deposit) : undefined,
          max_deposit: values.max_deposit ? Number(values.max_deposit) : undefined,
        };

    try {
      const created = await createAccount(payload).unwrap();
      // The create endpoint answers with the raw Laravel resource, so the record
      // may arrive wrapped in `data` — unlike the queries, which unwrap already.
      const createdId =
        created?.id ?? (created as { data?: { id?: number } } | undefined)?.data?.id;
      navigate(createdId ? `/accounts/${createdId}` : "/accounts");
    } catch (err) {
      setError(resolveErrorMessage(err, "Could not create this account."));
    }
  };

  const isQr = values.type.toLowerCase() === "qr";

  return (
    <SuperShell
      title="New account"
      subtitle="Users can deposit into this once it is active"
      action={
        <Button size="sm" variant="secondary" onClick={() => navigate("/accounts")}>
          <IconBack size={16} />
          Accounts
        </Button>
      }
    >
      <AccountForm
        values={values}
        onChange={setValues}
        onSubmit={() => void submit()}
        saving={isLoading}
        error={error}
        submitLabel="Create account"
        logoSlot={
          <div className="min-w-0">
            <p className="mb-1.5 text-xs font-medium text-muted">
              {isQr ? "QR image" : "Logo (optional)"}
            </p>
            {logoPreview ? (
              <div className="relative w-fit max-w-full">
                <img
                  src={logoPreview}
                  alt=""
                  className="max-h-52 max-w-full rounded-md border border-border object-contain"
                />
                <button
                  type="button"
                  onClick={clearLogo}
                  aria-label="Remove image"
                  className="absolute top-2 right-2 grid size-8 place-items-center rounded-full border border-border bg-surface-solid text-muted"
                >
                  <IconClose size={16} />
                </button>
              </div>
            ) : (
              <label className="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-dashed border-border px-4 py-6 text-center">
                <span className="text-muted">
                  <IconUpload size={20} />
                </span>
                <span className="text-sm font-medium text-text">
                  {isQr ? "Upload the QR to show users" : "Upload a bank or wallet logo"}
                </span>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/*"
                  className="sr-only"
                  onChange={handleLogo}
                />
              </label>
            )}
          </div>
        }
      />
    </SuperShell>
  );
}
