import { useRef, useState, type ChangeEvent } from "react";
import AdminShell from "@/components/AdminShell";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconTrash, IconUpload } from "@/components/icons";
import { useSessionGuard } from "@/hooks/useSessionGuard";
import { useDeleteBannerMutation, useGetBannersQuery, useUploadBannersMutation } from "@/services/api";
import { resolveErrorMessage } from "@/utils/errors";
import { formatDateTime } from "@/utils/dateTime";
import { resolveUploadUrl } from "@/utils/receipt";

const MAX_BANNER_BYTES = 5 * 1024 * 1024;

export default function BannerPage() {
  const { data: banners = [], isLoading, error } = useGetBannersQuery();
  const [uploadBanners, { isLoading: isUploading }] = useUploadBannersMutation();
  const [deleteBanner, { isLoading: isDeleting }] = useDeleteBannerMutation();
  useSessionGuard(error);

  const [pending, setPending] = useState<File[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const handleFiles = (event: ChangeEvent<HTMLInputElement>) => {
    const files = [...(event.target.files ?? [])];
    event.target.value = "";
    if (!files.length) return;

    const nonImage = files.find((file) => !file.type.startsWith("image/"));
    if (nonImage) {
      setFormError("Banners must be image files.");
      return;
    }
    const tooBig = files.find((file) => file.size > MAX_BANNER_BYTES);
    if (tooBig) {
      setFormError(`${tooBig.name} is over 5 MB — compress it before uploading.`);
      return;
    }

    setFormError(null);
    setPending((prev) => [...prev, ...files]);
  };

  const upload = async () => {
    if (!pending.length) return;
    setFormError(null);

    const formData = new FormData();
    // The endpoint accepts a batch; sending them one at a time would leave the
    // carousel half-updated if a later file failed.
    for (const file of pending) formData.append("images[]", file);

    try {
      await uploadBanners(formData).unwrap();
      setPending([]);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not upload these banners."));
    }
  };

  const remove = async (id: number) => {
    setFormError(null);
    try {
      await deleteBanner(id).unwrap();
      setConfirmingId(null);
    } catch (err) {
      setFormError(resolveErrorMessage(err, "Could not delete this banner."));
    }
  };

  return (
    <AdminShell title="Banners" subtitle={`${banners.length} live in the user app`}>
      <div className="space-y-4">
        <Card>
          <CardTitle>Upload</CardTitle>

          <label className="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-dashed border-border px-4 py-8 text-center">
            <span className="text-muted">
              <IconUpload size={22} />
            </span>
            <span className="text-sm font-medium text-text">Choose banner images</span>
            <span className="text-xs text-faint">
              Wide images work best — the carousel is 16:6. Max 5 MB each.
            </span>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              multiple
              className="sr-only"
              onChange={handleFiles}
            />
          </label>

          {pending.length ? (
            <div className="mt-3 space-y-3">
              <ul className="flex flex-wrap gap-2">
                {pending.map((file, index) => (
                  <li
                    key={`${file.name}-${index}`}
                    className="inline-flex max-w-full items-center gap-2 rounded-full border border-border bg-surface-2 px-3 py-1.5"
                  >
                    <span className="min-w-0 truncate text-xs text-text">{file.name}</span>
                    <button
                      type="button"
                      aria-label={`Remove ${file.name}`}
                      onClick={() => setPending((prev) => prev.filter((_, i) => i !== index))}
                      className="shrink-0 text-xs text-faint"
                    >
                      ✕
                    </button>
                  </li>
                ))}
              </ul>
              <Button loading={isUploading} onClick={() => void upload()}>
                Upload {pending.length} banner{pending.length === 1 ? "" : "s"}
              </Button>
            </div>
          ) : null}

          {formError ? <div className="mt-3">
            <ErrorNote>{formError}</ErrorNote>
          </div> : null}
        </Card>

        {isLoading ? (
          <Skeleton className="h-48 w-full" />
        ) : banners.length ? (
          <ul className="grid gap-3 sm:grid-cols-2">
            {banners.map((banner) => {
              const url = resolveUploadUrl(banner.image_url ?? banner.image_path);
              return (
                <li key={banner.id} className="min-w-0">
                  <Card padded={false} className="overflow-hidden">
                    {url ? (
                      <img
                        src={url}
                        alt={banner.title ?? ""}
                        loading="lazy"
                        className="w-full object-cover"
                        style={{ aspectRatio: "16 / 6" }}
                      />
                    ) : (
                      <div className="grid h-32 place-items-center text-xs text-faint">
                        Image unavailable
                      </div>
                    )}

                    <div className="flex min-w-0 items-center gap-3 p-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-text">
                          {banner.title || `Banner #${banner.id}`}
                        </p>
                        <p className="truncate text-xs text-faint">
                          {banner.created_at ? formatDateTime(banner.created_at) : ""}
                        </p>
                      </div>

                      {confirmingId === banner.id ? (
                        <span className="flex shrink-0 items-center gap-2">
                          <Button
                            size="sm"
                            variant="danger"
                            loading={isDeleting}
                            onClick={() => void remove(banner.id)}
                          >
                            Delete
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setConfirmingId(null)}>
                            Cancel
                          </Button>
                        </span>
                      ) : (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setConfirmingId(banner.id)}
                        >
                          <IconTrash size={16} />
                          Remove
                        </Button>
                      )}
                    </div>
                  </Card>
                </li>
              );
            })}
          </ul>
        ) : (
          <Card>
            <EmptyState
              title="No banners yet"
              body="The carousel is hidden in the user app until at least one banner is live."
            />
          </Card>
        )}
      </div>
    </AdminShell>
  );
}
