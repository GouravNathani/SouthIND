import { useRef, useState, type FormEvent } from "react";
import type { WaTemplate, WaVariable } from "@/types/api";
import Card, { CardTitle } from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import ConfirmDialog from "@/components/ui/ConfirmDialog";
import { Input, Textarea } from "@/components/ui/Field";
import { EmptyState, ErrorNote, Skeleton } from "@/components/ui/Feedback";
import { IconEdit, IconPlus, IconTrash } from "@/components/icons";
import VariableChips from "@/components/wahub/VariableChips";
import WaErrorState from "@/components/wahub/WaErrorState";
import { waErrorCode, waErrorMessage } from "@/components/wahub/waFormat";
import {
  useCreateWaTemplateMutation,
  useDeleteWaTemplateMutation,
  useGetWaTemplatesQuery,
  useUpdateWaTemplateMutation,
} from "@/services/api";
import { formatDateTime } from "@/utils/dateTime";

/** Campaign variables, used when the templates endpoint sends none. */
export const DEFAULT_CAMPAIGN_VARIABLES: WaVariable[] = [
  { key: "name", label: "Name", secret: false, sample: "Ravi" },
  { key: "play_id", label: "Play ID", secret: false, sample: "SI10234" },
  { key: "branch_name", label: "Branch", secret: false, sample: "SouthIND" },
  { key: "date", label: "Date", secret: false, sample: "07-10-2026" },
];

/** Saved campaign texts, so a regular offer is not retyped every time. */
export default function TemplatesTab() {
  const templates = useGetWaTemplatesQuery(undefined, { refetchOnMountOrArgChange: true });
  const [editing, setEditing] = useState<WaTemplate | "new" | null>(null);
  const [deleting, setDeleting] = useState<WaTemplate | null>(null);
  const [deleteTemplate, deleteState] = useDeleteWaTemplateMutation();
  const [deleteError, setDeleteError] = useState<string | null>(null);

  if (templates.isLoading) {
    return (
      <div className="space-y-2">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-24 w-full" />
      </div>
    );
  }

  if (templates.error && !templates.data) {
    return (
      <WaErrorState
        error={templates.error}
        onRetry={() => void templates.refetch()}
        retrying={templates.isFetching}
        fallback="Could not load templates."
      />
    );
  }

  const rows = templates.data?.templates ?? [];
  const variables = templates.data?.variables?.length ? templates.data.variables : DEFAULT_CAMPAIGN_VARIABLES;

  const handleDelete = async () => {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await deleteTemplate(deleting.id).unwrap();
      if (editing !== "new" && editing?.id === deleting.id) setEditing(null);
      setDeleting(null);
    } catch (err) {
      setDeleteError(waErrorMessage(err, "Could not delete this template."));
    }
  };

  return (
    <div className="space-y-4">
      {editing ? (
        <TemplateForm
          key={editing === "new" ? "new" : editing.id}
          template={editing === "new" ? null : editing}
          variables={variables}
          onDone={() => setEditing(null)}
        />
      ) : (
        <div className="flex min-w-0 items-center justify-between gap-3">
          <p className="min-w-0 text-xs text-muted">
            {rows.length ? `${rows.length} saved` : "Save a text once, reuse it for every campaign."}
          </p>
          <Button size="sm" onClick={() => setEditing("new")}>
            <IconPlus size={16} />
            New template
          </Button>
        </div>
      )}

      {rows.length ? (
        <ul className="grid gap-2 md:grid-cols-2">
          {rows.map((template) => (
            <li key={template.id} className="min-w-0">
              <Card className="flex h-full flex-col">
                <div className="flex min-w-0 items-start justify-between gap-3">
                  <p className="min-w-0 truncate text-sm font-semibold text-text">{template.name}</p>
                  <span className="tabular shrink-0 text-xs text-faint">
                    Used {template.use_count ?? 0}×
                  </span>
                </div>
                <p className="mt-2 line-clamp-3 min-w-0 flex-1 text-sm break-words whitespace-pre-wrap text-muted">
                  {template.body}
                </p>
                <div className="mt-3 flex min-w-0 items-center justify-between gap-2 border-t border-border pt-3">
                  <span className="min-w-0 truncate text-xs text-faint">
                    {template.updated_at ? `Updated ${formatDateTime(template.updated_at)}` : ""}
                  </span>
                  <div className="flex shrink-0 items-center gap-1">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(template)}>
                      <IconEdit size={15} />
                      Edit
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setDeleteError(null);
                        setDeleting(template);
                      }}
                      aria-label={`Delete ${template.name}`}
                    >
                      <IconTrash size={15} />
                    </Button>
                  </div>
                </div>
              </Card>
            </li>
          ))}
        </ul>
      ) : editing ? null : (
        <Card>
          <EmptyState
            title="No templates yet"
            body="Templates are reusable campaign texts with {{name}} and other variables filled in per user."
          />
        </Card>
      )}

      <ConfirmDialog
        open={deleting !== null}
        title="Delete this template?"
        confirmLabel="Delete"
        tone="danger"
        loading={deleteState.isLoading}
        error={deleteError}
        onConfirm={() => void handleDelete()}
        onCancel={() => setDeleting(null)}
      >
        <p>“{deleting?.name}” is removed. Campaigns already sent are not affected.</p>
      </ConfirmDialog>
    </div>
  );
}

function TemplateForm({
  template,
  variables,
  onDone,
}: {
  template: WaTemplate | null;
  variables: WaVariable[];
  onDone: () => void;
}) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [name, setName] = useState(template?.name ?? "");
  const [body, setBody] = useState(template?.body ?? "");
  const [create, createState] = useCreateWaTemplateMutation();
  const [update, updateState] = useUpdateWaTemplateMutation();
  const [error, setError] = useState<{ message: string; code?: string } | null>(null);

  const saving = createState.isLoading || updateState.isLoading;
  const canSave = name.trim().length > 0 && body.trim().length > 0;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const payload = { name: name.trim(), body: body.trim() };
    try {
      if (template) await update({ id: template.id, ...payload }).unwrap();
      else await create(payload).unwrap();
      onDone();
    } catch (err) {
      setError({ message: waErrorMessage(err, "Could not save this template."), code: waErrorCode(err) });
    }
  };

  const nameError = error?.code === "name_taken" ? error.message : null;
  const bodyError = error?.code === "unknown_variable" ? error.message : null;

  return (
    <Card>
      <CardTitle>{template ? "Edit template" : "New template"}</CardTitle>
      <form className="space-y-3" onSubmit={handleSubmit}>
        <Input
          label="Name"
          placeholder="e.g. Weekend offer"
          maxLength={80}
          value={name}
          onChange={(event) => {
            setName(event.target.value);
            if (nameError) setError(null);
          }}
          error={nameError}
          required
        />
        <Textarea
          ref={textareaRef}
          label="Message"
          rows={6}
          maxLength={2000}
          placeholder="Hi {{name}}, …"
          value={body}
          onChange={(event) => {
            setBody(event.target.value);
            if (bodyError) setError(null);
          }}
          error={bodyError}
        />
        <VariableChips variables={variables} textareaRef={textareaRef} value={body} onChange={setBody} />
        {error && !nameError && !bodyError ? <ErrorNote>{error.message}</ErrorNote> : null}
        <div className="flex flex-wrap gap-2">
          <Button type="submit" size="sm" loading={saving} disabled={!canSave}>
            {template ? "Save changes" : "Save template"}
          </Button>
          <Button type="button" size="sm" variant="ghost" onClick={onDone} disabled={saving}>
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  );
}
