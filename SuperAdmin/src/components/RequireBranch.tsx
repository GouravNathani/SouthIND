import type { ReactNode } from "react";
import SuperShell from "@/components/SuperShell";
import { useBranch } from "@/components/BranchContext";
import Card from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/Feedback";

/**
 * Gate for the pages the backend scopes to exactly one branch — the referral
 * programme and the winner streak both take branch_id on every call, so "All
 * branches" has no meaning there. Rather than silently querying branch 0, the
 * page asks for a choice.
 */
export default function RequireBranch({
  title,
  children,
}: {
  title: string;
  children: (branchId: number) => ReactNode;
}) {
  const { branchId, branches } = useBranch();

  if (branchId === null) {
    return (
      <SuperShell title={title} subtitle="Pick a branch to continue">
        <Card>
          <EmptyState
            title="This one is per branch"
            body={
              branches.length
                ? "Choose a branch in the header — these settings and balances differ for each."
                : "Create a branch first; there is nothing to configure yet."
            }
          />
        </Card>
      </SuperShell>
    );
  }

  return <>{children(branchId)}</>;
}
