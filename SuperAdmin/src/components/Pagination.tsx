import Button from "@/components/ui/Button";
import type { PaginationMeta } from "@/types/api";

export default function Pagination({
  meta,
  page,
  onPage,
}: {
  meta?: PaginationMeta;
  page: number;
  onPage: (next: number) => void;
}) {
  const lastPage = meta?.last_page ?? 1;
  if (lastPage <= 1) return null;

  return (
    <div className="flex min-w-0 items-center justify-between gap-3">
      <Button
        size="sm"
        variant="secondary"
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
      >
        Previous
      </Button>
      <span className="tabular truncate text-xs text-muted">
        Page {meta?.current_page ?? page} of {lastPage}
        {meta?.total !== undefined ? ` · ${meta.total} total` : ""}
      </span>
      <Button
        size="sm"
        variant="secondary"
        disabled={page >= lastPage}
        onClick={() => onPage(page + 1)}
      >
        Next
      </Button>
    </div>
  );
}
