import { useEffect, useRef, useState } from "react";
import { useGetBannersQuery } from "@/services/api";
import { buildImageUrl } from "@/utils/media";

const AUTOPLAY_MS = 4200;
const SWIPE_THRESHOLD_PX = 50;

/**
 * Branch-published promo banners. The track is a flex row translated by index —
 * the wrapper clips it, so a wide slide can never widen the page.
 */
export default function BannerCarousel({ href }: { href?: string }) {
  const { data: banners = [] } = useGetBannersQuery();
  const [index, setIndex] = useState(0);
  const touchStartX = useRef<number | null>(null);

  const images = banners
    .map((item) =>
      typeof item?.image_url === "string" && item.image_url.trim().length
        ? item.image_url
        : buildImageUrl(item?.image_path)
    )
    .filter((url): url is string => Boolean(url));

  useEffect(() => {
    setIndex(0);
  }, [images.length]);

  useEffect(() => {
    if (images.length <= 1) return;
    const timer = window.setInterval(
      () => setIndex((prev) => (prev + 1) % images.length),
      AUTOPLAY_MS
    );
    return () => window.clearInterval(timer);
  }, [images.length]);

  if (!images.length) return null;

  const active = index % images.length;

  const carousel = (
    <div
      className="relative w-full overflow-hidden rounded-lg border border-border bg-surface-2"
      style={{ aspectRatio: "16 / 6" }}
      onTouchStart={(event) => {
        touchStartX.current = event.touches[0]?.clientX ?? null;
      }}
      onTouchEnd={(event) => {
        const startX = touchStartX.current;
        touchStartX.current = null;
        if (startX === null || images.length < 2) return;

        const deltaX = (event.changedTouches[0]?.clientX ?? startX) - startX;
        if (Math.abs(deltaX) <= SWIPE_THRESHOLD_PX) return;
        setIndex((prev) =>
          deltaX > 0 ? (prev - 1 + images.length) % images.length : (prev + 1) % images.length
        );
      }}
    >
      <div
        className="flex h-full w-full transition-transform duration-500"
        style={{ transform: `translateX(-${active * 100}%)`, transitionTimingFunction: "var(--ease)" }}
      >
        {images.map((src, slideIndex) => (
          <img
            key={`${src}-${slideIndex}`}
            src={src}
            alt=""
            loading="lazy"
            className="h-full w-full shrink-0 object-cover"
          />
        ))}
      </div>

      {images.length > 1 ? (
        <div className="absolute right-3 bottom-3 flex gap-1.5">
          {images.map((src, slideIndex) => (
            <button
              key={`dot-${src}-${slideIndex}`}
              type="button"
              aria-label={`Go to banner ${slideIndex + 1}`}
              onClick={(event) => {
                event.preventDefault();
                setIndex(slideIndex);
              }}
              className="size-2 rounded-full transition-colors"
              style={{
                background: slideIndex === active ? "var(--accent)" : "rgba(255,255,255,0.45)",
              }}
            />
          ))}
        </div>
      ) : null}
    </div>
  );

  return href ? (
    <a href={href} target="_blank" rel="noreferrer noopener" className="block min-w-0">
      {carousel}
    </a>
  ) : (
    carousel
  );
}
