import { useState } from "react";
import { useNavigate } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import AccountForm, {
  toAccountFormData,
  toFormValues,
  type AccountFormValues,
} from "@/components/AccountForm";
import AccountImageField from "@/components/AccountImageField";
import Button from "@/components/ui/Button";
import { IconBack } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useCreateAccountMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";

export default function NewAccountPage() {
  const navigate = useNavigate();
  const [createAccount, { isLoading, error: mutationError }] = useCreateAccountMutation();
  useSessionGuard(mutationError);

  const [values, setValues] = useState<AccountFormValues>(toFormValues());
  const [image, setImage] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);

  const isQr = values.type.toLowerCase() === "qr";

  const submit = async () => {
    setError(null);

    // The backend requires the image for a QR account; say so here instead of
    // letting the request come back with a validation error.
    if (isQr && !image) {
      setError("Upload the QR image before creating a QR account.");
      return;
    }

    // Multipart only when there is a file — a JSON body is easier for the
    // backend to validate, and most accounts have no image at all.
    const payload = image
      ? toAccountFormData(values, image)
      : {
          ...values,
          min_deposit: values.min_deposit ? Number(values.min_deposit) : undefined,
          max_deposit: values.max_deposit ? Number(values.max_deposit) : undefined,
        };

    try {
      const created = await createAccount(payload).unwrap();
      // The create endpoint answers `{ message, account }`, unlike the queries,
      // which unwrap already.
      const createdId =
        created?.id ??
        (created as { account?: { id?: number }; data?: { id?: number } } | undefined)?.account?.id ??
        (created as { data?: { id?: number } } | undefined)?.data?.id;
      navigate(createdId ? `/accounts/${createdId}` : "/accounts");
    } catch (err) {
      setError(resolveErrorMessage(err, "Could not create this account."));
    }
  };

  return (
    <AdminShell
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
          <AccountImageField isQr={isQr} file={image} onChange={setImage} onError={setError} />
        }
      />
    </AdminShell>
  );
}
