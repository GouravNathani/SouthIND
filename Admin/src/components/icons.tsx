/* Stroke icons, 24×24 grid, currentColor. Kept inline so the app makes zero
   external requests for chrome — the icon set came over from the design preview. */
type P = { size?: number };

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

export const IconUser = ({ size = 20 }: P) => (
  <svg {...base(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
);

export const IconLogout = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3" /><path d="m10 16 4-4-4-4" /><path d="M14 12H4" /></svg>
);

export const IconBack = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M19 12H5" /><path d="m11 6-6 6 6 6" /></svg>
);

export const IconClose = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
);

export const IconPlus = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M12 5v14" /><path d="M5 12h14" /></svg>
);

export const IconCopy = ({ size = 16 }: P) => (
  <svg {...base(size)}><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V6a2 2 0 0 1 2-2h9" /></svg>
);

export const IconUpload = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M12 16V4" /><path d="m7 9 5-5 5 5" /><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" /></svg>
);

export const IconAlert = ({ size = 18 }: P) => (
  <svg {...base(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5" /><path d="M12 16.2h.01" /></svg>
);

export const IconSun = ({ size = 18 }: P) => (
  <svg {...base(size)}>
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2v2" /><path d="M12 20v2" /><path d="M4.2 4.2l1.4 1.4" /><path d="M18.4 18.4l1.4 1.4" />
    <path d="M2 12h2" /><path d="M20 12h2" /><path d="M4.2 19.8l1.4-1.4" /><path d="M18.4 5.6l1.4-1.4" />
  </svg>
);

export const IconMoon = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5" /></svg>
);

export const IconSend = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M4.5 12 20 4l-6 16-3.2-6.4z" /></svg>
);

export const IconMenu = ({ size = 20 }: P) => (
  <svg {...base(size)}><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></svg>
);

export const IconUsers = ({ size = 20 }: P) => (
  <svg {...base(size)}>
    <circle cx="9" cy="8" r="3.4" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
    <path d="M16 5.2a3.4 3.4 0 0 1 0 6.6" /><path d="M17.5 14.2A6.5 6.5 0 0 1 21.5 20" />
  </svg>
);

export const IconAccounts = ({ size = 20 }: P) => (
  <svg {...base(size)}><rect x="3" y="6" width="18" height="12" rx="2" /><path d="M3 10h18" /><path d="M7 14h4" /></svg>
);

export const IconBanner = ({ size = 20 }: P) => (
  <svg {...base(size)}><rect x="3" y="6" width="18" height="12" rx="2" /><path d="m6 15 3.2-3.4 2.6 2.4 2.6-3.2L18 15" /></svg>
);

export const IconBonus = ({ size = 20 }: P) => (
  <svg {...base(size)}>
    <path d="M4 11h16v9H4z" /><path d="M2.5 7.5h19V11h-19z" /><path d="M12 7.5V20" />
    <path d="M12 7.5C10 7.5 8 6.6 8 5.2S9.6 3.2 12 7.5z" /><path d="M12 7.5c2 0 4-.9 4-2.3S14.4 3.2 12 7.5z" />
  </svg>
);

export const IconWhatsApp = ({ size = 20 }: P) => (
  <svg {...base(size)}>
    <path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5" />
    <path d="M9.3 8.8c.4 2.2 2.2 4 4.4 4.4" />
  </svg>
);

export const IconFilter = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M3 5h18" /><path d="M6.5 12h11" /><path d="M10 19h4" /></svg>
);

export const IconRefresh = ({ size = 18 }: P) => (
  <svg {...base(size)}><path d="M20 11a8 8 0 1 0-1.5 5.5" /><path d="M20 5v6h-6" /></svg>
);

export const IconEdit = ({ size = 16 }: P) => (
  <svg {...base(size)}><path d="M4 20h4L19 9l-4-4L4 16z" /><path d="m14.5 5.5 4 4" /></svg>
);

export const IconTrash = ({ size = 16 }: P) => (
  <svg {...base(size)}><path d="M4 7h16" /><path d="M9 7V5h6v2" /><path d="M6.5 7 7.5 20h9L17.5 7" /></svg>
);

/**
 * The brand mark: the heart cut out of the O in the SouthIND logo. Filled with
 * currentColor so it takes the active skin's accent wherever it is placed.
 */
export const IconHeart = ({ size = 20 }: P) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden>
    <path d="M12 21.2c-.34 0-.67-.12-.93-.34C7.5 17.9 2.4 13.6 2.4 9.1 2.4 6.1 4.7 3.8 7.6 3.8c1.7 0 3.3.8 4.4 2.2 1.1-1.4 2.7-2.2 4.4-2.2 2.9 0 5.2 2.3 5.2 5.3 0 4.5-5.1 8.8-8.67 11.76-.26.22-.59.34-.93.34z" />
  </svg>
);
