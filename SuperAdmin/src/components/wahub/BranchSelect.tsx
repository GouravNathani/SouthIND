import { Select } from "@/components/ui/Field";
import { useBranch } from "@/components/BranchContext";

/**
 * Branch filter for the WhatsApp lists. It is the panel-wide branch choice (the
 * same one as the header picker), so the two never disagree; "All branches"
 * unless the owner has picked one. The list is data-driven, so it stays a
 * Select drawn with the app's own chevron.
 */
export default function BranchSelect({ className }: { className?: string }) {
  const { branches, branchId, setBranchId, isLoading } = useBranch();

  return (
    <div className={["min-w-0", className ?? ""].join(" ")}>
      <Select
        label="Branch"
        value={branchId === null ? "all" : String(branchId)}
        disabled={isLoading}
        onChange={(event) => setBranchId(event.target.value === "all" ? null : Number(event.target.value))}
      >
        <option value="all">All branches</option>
        {branches.map((branch) => (
          <option key={branch.id} value={branch.id}>
            {branch.name}
          </option>
        ))}
      </Select>
    </div>
  );
}
