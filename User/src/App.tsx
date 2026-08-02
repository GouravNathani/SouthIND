import ThemeSwitcher from "@/components/ThemeSwitcher";
import { BUILD_VERSION } from "@/config/env";

// Phase 2a scaffold screen. Replaced by the router + AppShell in Phase 2b/2d.
export default function App() {
  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-xl flex-col gap-6 p-6">
      <header>
        <h1 className="font-sans text-2xl font-semibold text-text">SouthIND</h1>
        <p className="text-sm text-muted">Scaffold · build {BUILD_VERSION}</p>
      </header>

      <section className="rounded-lg border border-border bg-surface p-5 shadow-md">
        <h2 className="mb-3 text-sm font-semibold tracking-wide text-muted uppercase">Theme</h2>
        <ThemeSwitcher />
      </section>

      <section className="rounded-lg border border-border bg-surface p-5 shadow-md">
        <p className="text-xs text-muted">Balance</p>
        <p className="tabular text-3xl font-semibold text-accent">₹1,24,500</p>
        <div className="mt-4 flex gap-2">
          <button className="rounded-full bg-accent px-4 py-2 text-sm font-semibold text-on-accent">
            Deposit
          </button>
          <button className="rounded-full border border-border bg-surface-2 px-4 py-2 text-sm text-text">
            Withdraw
          </button>
        </div>
      </section>
    </main>
  );
}
