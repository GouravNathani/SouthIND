import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { useGetBranchesQuery } from "@/services/api";
import type { BranchRecord } from "@/types/api";

const STORAGE_KEY = "sind_super_branch";
const TOKEN_KEY = "sind-super-token";

type BranchContextValue = {
  branches: BranchRecord[];
  /** null means "all branches" — valid for reports, invalid for anything that writes. */
  branchId: number | null;
  setBranchId: (id: number | null) => void;
  branch: BranchRecord | null;
  isLoading: boolean;
};

const BranchContext = createContext<BranchContextValue | null>(null);

const readStored = (): number | null => {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw || raw === "all") return null;
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? parsed : null;
  } catch {
    return null;
  }
};

/**
 * Which branch the super admin is currently looking at. Every list, queue and
 * form in this panel is branch-scoped on the backend, so the choice lives once
 * here rather than as a prop threaded through every page — and it persists,
 * because an operator working one branch all afternoon should not re-pick it
 * on every navigation.
 */
export function BranchProvider({ children }: { children: ReactNode }) {
  // The provider wraps the router, so it also mounts on the login screen —
  // asking for branches without a token just logs a 401 for every visitor.
  const { data: branches = [], isLoading } = useGetBranchesQuery(undefined, {
    skip: !sessionStorage.getItem(TOKEN_KEY),
  });
  const [branchId, setBranchIdState] = useState<number | null>(readStored);

  // A remembered branch that has since been deleted would silently scope every
  // query to nothing — fall back to "all" rather than showing empty lists.
  useEffect(() => {
    if (branchId === null || !branches.length) return;
    if (!branches.some((branch) => branch.id === branchId)) setBranchIdState(null);
  }, [branchId, branches]);

  const setBranchId = (id: number | null) => {
    setBranchIdState(id);
    try {
      localStorage.setItem(STORAGE_KEY, id === null ? "all" : String(id));
    } catch {
      /* the choice simply won't survive a reload */
    }
  };

  const value = useMemo<BranchContextValue>(
    () => ({
      branches,
      branchId,
      setBranchId,
      branch: branches.find((entry) => entry.id === branchId) ?? null,
      isLoading,
    }),
    [branches, branchId, isLoading]
  );

  return <BranchContext.Provider value={value}>{children}</BranchContext.Provider>;
}

export function useBranch() {
  const context = useContext(BranchContext);
  if (!context) throw new Error("useBranch must be used inside <BranchProvider>");
  return context;
}
