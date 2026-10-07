import ReverseOrderButton from "@/components/ReverseOrderButton";
import Segmented from "@/components/ui/Segmented";
import { USER_SORTS, userOrderLabel, type UserSort } from "@/utils/userSort";

/** Sort pills for the users list, its reverse button, and a line saying what comes first. */
export default function UserSortControl({
  value,
  reversed,
  onChange,
  onReverse,
  withProfit = true,
  className = "",
}: {
  value: UserSort;
  reversed: boolean;
  onChange: (value: UserSort) => void;
  onReverse: () => void;
  /** Off for admins who may not see deposit totals: the API ignores that sort for them. */
  withProfit?: boolean;
  className?: string;
}) {
  const options = USER_SORTS.filter((option) => withProfit || option.value !== "profit").map((option) => ({
    value: option.value,
    label: option.short ? (
      <>
        <span className="sm:hidden">{option.short}</span>
        <span className="hidden sm:inline">{option.label}</span>
      </>
    ) : (
      option.label
    ),
  }));

  return (
    <div className={["min-w-0", className].join(" ")}>
      <div className="flex min-w-0 items-end gap-2">
        <Segmented label="Sort" value={value} onChange={onChange} options={options} className="flex-1" />
        <ReverseOrderButton reversed={reversed} onToggle={onReverse} />
      </div>
      <span className="mt-1.5 block text-xs text-faint">{userOrderLabel(value, reversed)}</span>
    </div>
  );
}
