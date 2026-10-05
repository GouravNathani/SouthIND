import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import AdminShell from "@/components/AdminShell";
import AccountForm, {
  toAccountFormData,
  toFormValues,
  type AccountFormValues,
} from "@/components/AccountForm";
import AccountImageField from "@/components/AccountImageField";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconBack, IconTrash } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useDeleteAccountMutation, useGetAccountQuery, useUpdateAccountMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { resolveUploadUrl } from "@/utils/receipt";

export default function AccountDetailPage() {
  const navigate = useNavigate();
  const { id } = useParams();
  const accountId = Number(id);

  const { data: account, isLoading, error } = useGetAccountQuery(accountId, {
    skip: !Number.isFinite(accountId),
  });
  const [updateAccount, { isLoading: isSaving }] = useUpdateAccountMutation();
  const [deleteAccount, { isLoading: isDeleting }] = useDeleteAccountMutation();
  useSessionGuard(error);

  const [values, setValues] = useState<AccountFormValues>(toFormValues());
  const [image, setImage] = useState<File | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  useEffect(() => {
    if (account) setValues(toFormValues(account));
  }, [account]);

  const back = (
    <Button size="sm" variant="secondary" onClick={() => navigate("/accounts")}>
      <IconBack size={16} />
      Accounts
    </Button>
  );

  if (isLoading) {
    return (
      <AdminShell title="Account" action={back}>
        <Skeleton className="h-72 w-full" />
      </AdminShell>
    );
  }

  if (!account) {
    return (
      <AdminShell title="Account" action={back}>
        <Card>
          {error ? <ErrorNote>{resolveErrorMessage(error, "Could not load this account.")}</ErrorNote> : null}
          <EmptyState title="Account not found" body="It may have been deleted by another admin." />
        </Card>
      </AdminShell>
    );
  }

  const save = async () => {
    setFormError(null);
    setNotice(null);
    try {
      await updateAccount({
        id: account.id,
        body: image
          ? toAccountFormData(values, image)
          : {
              ...values,
              min_deposit: values.min_deposit ? Number(values.min_deposit) : null,
              max_deposit: values.max_deposit ? Number(values.max_deposit) : null,
            },
      }).unwrap();
      setImage(null);
      setNotice("Account saved.");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not save this account."));
    }
  };

  const remove = async () => {
    setFormError(null);
    try {
      await deleteAccount(account.id).unwrap();
      navigate("/accounts");
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not delete this account."));
    }
  };

  const logoUrl = resolveUploadUrl(account.logo_url ?? account.logo_path);

  return (
    <AdminShell title={account.name} subtitle={account.holder_name} action={back}>
      <div className="space-y-4">
        {notice ? (
          <p className="rounded-md bg-accent-soft px-3 py-2 text-xs text-accent">{notice}</p>
        ) : null}

        <AccountForm
          values={values}
          onChange={setValues}
          onSubmit={() => void save()}
          saving={isSaving}
          error={formError}
          submitLabel="Save changes"
          logoSlot={
            <AccountImageField
              isQr={values.type.toLowerCase() === "qr"}
              file={image}
              onChange={setImage}
              onError={setFormError}
              currentUrl={logoUrl}
            />
          }
          footer={
            confirmingDelete ? (
              <span className="flex flex-wrap items-center gap-2">
                <span className="text-xs text-muted">Delete this account?</span>
                <Button variant="danger" loading={isDeleting} onClick={() => void remove()}>
                  Yes, delete
                </Button>
                <Button variant="ghost" onClick={() => setConfirmingDelete(false)}>
                  Cancel
                </Button>
              </span>
            ) : (
              <Button
                type="button"
                variant="ghost"
                onClick={() => setConfirmingDelete(true)}
              >
                <IconTrash size={16} />
                Delete
              </Button>
            )
          }
        />
      </div>
    </AdminShell>
  );
}
