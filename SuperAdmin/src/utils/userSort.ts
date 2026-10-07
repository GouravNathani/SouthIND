/** Sort orders of the users list; the API applies them (`sort_by`, `reverse`). */
export type UserSort = "newest" | "last_used" | "name" | "profit";

/** `short` is the phone label, where four pills share one row with the reverse button. */
export const USER_SORTS: readonly { value: UserSort; label: string; short?: string }[] = [
  { value: "newest", label: "Newest" },
  { value: "last_used", label: "Last seen", short: "Seen" },
  { value: "name", label: "Name" },
  { value: "profit", label: "Profit" },
];

const ORDER_LABELS: Record<UserSort, [normal: string, reversed: string]> = {
  newest: ["Newest first", "Oldest first"],
  last_used: ["Recently online first", "Never or longest offline first"],
  name: ["A → Z, unnamed last", "Unnamed first, Z → A"],
  profit: ["Highest profit first", "Biggest loss first"],
};

/** What the list shows first, so a reversed order reads plainly. */
export const userOrderLabel = (sort: UserSort, reversed: boolean) =>
  ORDER_LABELS[sort][reversed ? 1 : 0];
