/* Stroke icons, 24×24 grid, currentColor. Kept inline so the preview has zero
   external requests — the real apps will use the same set. */
type P = { size?: number; className?: string };

const base = (size: number) => ({
  width: size,
  height: size,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.7,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
});

export const IconHome = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M3 10.2 12 3l9 7.2V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>
);

export const IconDeposit = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M12 4v12" /><path d="m7 11 5 5 5-5" /><path d="M4 20h16" /></svg>
);

export const IconWithdraw = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M12 20V8" /><path d="m7 13 5-5 5 5" /><path d="M4 4h16" /></svg>
);

export const IconHistory = ({ size = 20 }: P) => (
  <svg {...base(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.2 1.9" /></svg>
);

export const IconTrophy = ({ size = 20 }: P) => (
  <svg {...base(size)}>
    <path d="M7 4h10v5a5 5 0 0 1-10 0z" /><path d="M7 6H4.5a2.5 2.5 0 0 0 2.5 2.5" />
    <path d="M17 6h2.5A2.5 2.5 0 0 1 17 8.5" /><path d="M10 14h4l.6 4H9.4z" /><path d="M8 20h8" />
  </svg>
);

export const IconChat = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5" /></svg>
);

export const IconSettings = ({ size = 20 }: P) => (
  <svg {...base(size)}>
    <circle cx="12" cy="12" r="3" />
    <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.3 6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.9-1.2V2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H22a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
  </svg>
);

export const IconBell = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8" /><path d="M13.7 20a2 2 0 0 1-3.4 0" /></svg>
);

export const IconGlobe = ({ size = 18 }: P) => (
  <svg {...base(size)}><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18" /></svg>
);

export const IconSearch = ({ size = 18 }: P) => (
  <svg {...base(size)}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
);

export const IconCheck = ({ size = 16 }: P) => (
  <svg {...base(size)}><path d="m4 12.5 5 5L20 6.5" /></svg>
);
