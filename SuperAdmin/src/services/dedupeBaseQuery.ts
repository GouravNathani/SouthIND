import type {
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
} from "@reduxjs/toolkit/query";

/**
 * Wraps an RTK Query base query so a second *identical* mutating request cannot
 * be sent while the first is still in flight. This collapses rapid double-clicks
 * and slow-network double-submits into a single network call for every endpoint
 * at once, without touching individual buttons.
 *
 * GET requests are left alone (RTK Query already dedups in-flight queries by
 * cache key), and so are FormData uploads (their bodies can't be cheaply
 * compared, and those endpoints have their own server-side duplicate guards).
 * The in-flight entry is removed as soon as the request settles, so a legitimate
 * later retry of the same action still works.
 */
type DedupBaseQuery = BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError>;

const inFlight = new Map<string, ReturnType<DedupBaseQuery>>();

const stableStringify = (value: unknown): string => {
  if (value === null || typeof value !== "object") {
    return JSON.stringify(value ?? null);
  }
  if (Array.isArray(value)) {
    return `[${value.map(stableStringify).join(",")}]`;
  }
  const record = value as Record<string, unknown>;
  return `{${Object.keys(record)
    .sort()
    .map((key) => `${JSON.stringify(key)}:${stableStringify(record[key])}`)
    .join(",")}}`;
};

export const withInFlightDedup = <T extends DedupBaseQuery>(baseQuery: T): T => {
  const wrapped: DedupBaseQuery = (args, api, extraOptions) => {
    const request = typeof args === "string" ? { url: args } : args;
    const method = (request.method ?? "GET").toUpperCase();
    const body = (request as FetchArgs).body;

    // Only guard mutating requests; never collapse file uploads.
    if (method === "GET" || body instanceof FormData) {
      return baseQuery(args, api, extraOptions);
    }

    const key = `${method} ${request.url} ${stableStringify(body ?? null)}`;
    const existing = inFlight.get(key);
    if (existing) {
      return existing;
    }

    const promise = Promise.resolve(
      baseQuery(args, api, extraOptions),
    ).finally(() => {
      inFlight.delete(key);
    });
    inFlight.set(key, promise);
    return promise;
  };

  return wrapped as T;
};
