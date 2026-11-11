<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Home page screenshot carousel. A native scroll-snap track (so touch swipe and trackpads work
 * as usual) with arrows, dots, arrow-key support and autoplay that pauses on hover, focus and
 * hidden tabs, and never runs for visitors who prefer reduced motion.
 */
const props = withDefaults(defineProps<{
  slides: { src: string; alt: string; caption: string; width?: number; height?: number }[]
  interval?: number
}>(), { interval: 5000 })

const track = ref<HTMLElement | null>(null)
const current = ref(0)
const paused = ref(false)
const userPaused = ref(false)
let timer: ReturnType<typeof setInterval> | undefined
const reducedMotion = ref(false)
let observer: IntersectionObserver | undefined

function go(index: number) {
  const el = track.value
  if (!el) return
  const count = props.slides.length
  const target = ((index % count) + count) % count
  el.scrollTo({ left: target * el.clientWidth, behavior: reducedMotion.value ? 'auto' : 'smooth' })
  current.value = target
}

const next = () => go(current.value + 1)
const prev = () => go(current.value - 1)

function onKey(event: KeyboardEvent) {
  if (event.key === 'ArrowRight') { event.preventDefault(); next() }
  if (event.key === 'ArrowLeft') { event.preventDefault(); prev() }
}

function start() {
  stop()
  if (reducedMotion.value) return
  timer = setInterval(() => { if (!paused.value && !userPaused.value && !document.hidden) next() }, props.interval)
}

function stop() {
  if (timer) clearInterval(timer)
  timer = undefined
}

onMounted(() => {
  reducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  // Keep the active dot in sync when the visitor swipes or scrolls the track directly.
  observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (entry.isIntersecting && entry.intersectionRatio > 0.6) {
        current.value = Number((entry.target as HTMLElement).dataset.index)
      }
    }
  }, { root: track.value, threshold: [0.6] })
  track.value?.querySelectorAll('.oc-slide').forEach(slide => observer!.observe(slide))
  start()
})

onBeforeUnmount(() => {
  stop()
  observer?.disconnect()
})
</script>

<template>
  <section
    class="oc-slider"
    aria-roledescription="carousel"
    aria-label="OpConsole screenshots"
    @mouseenter="paused = true"
    @mouseleave="paused = false"
    @focusin="paused = true"
    @focusout="paused = false"
    @keydown="onKey"
  >
    <div class="oc-slider-viewport">
      <div ref="track" class="oc-slider-track" tabindex="0" :aria-live="paused || userPaused || reducedMotion ? 'polite' : 'off'">
        <div
          v-for="(slide, i) in slides"
          :key="slide.src"
          class="oc-slide"
          role="group"
          aria-roledescription="slide"
          :aria-label="`${i + 1} of ${slides.length}: ${slide.caption}`"
          :data-index="i"
        >
          <div class="oc-slide-frame">
            <img
              :src="slide.src"
              :alt="slide.alt"
              :width="slide.width ?? 1920"
              :height="slide.height ?? 1245"
              :loading="i === 0 ? 'eager' : 'lazy'"
            >
          </div>
          <div class="oc-slide-caption">{{ slide.caption }}</div>
        </div>
      </div>

      <button class="oc-slider-arrow is-prev" type="button" aria-label="Previous screenshot" @click="prev">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
      </button>
      <button class="oc-slider-arrow is-next" type="button" aria-label="Next screenshot" @click="next">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
      </button>
    </div>

    <div class="oc-slider-controls">
      <div class="oc-slider-dots">
        <button
          v-for="(slide, i) in slides"
          :key="slide.src"
          type="button"
          class="oc-slider-dot"
          :class="{ 'is-active': i === current }"
          :aria-label="`Show screenshot ${i + 1}: ${slide.caption}`"
          :aria-current="i === current ? 'true' : undefined"
          @click="go(i)"
        />
      </div>
      <button type="button" class="oc-slider-pause" @click="userPaused = !userPaused">
        {{ userPaused ? 'Resume slideshow' : 'Pause slideshow' }}
      </button>
      <span class="oc-slider-count" aria-hidden="true">{{ current + 1 }} / {{ slides.length }}</span>
    </div>
  </section>
</template>

<style scoped>
.oc-slider {
  margin: 24px 0 8px;
}

.oc-slider-viewport {
  position: relative;
}

.oc-slider-track {
  display: flex;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  scrollbar-width: none;
  border-radius: 12px;
  outline-offset: 4px;
}

.oc-slider-track::-webkit-scrollbar {
  display: none;
}

.oc-slide {
  flex: 0 0 100%;
  margin: 0;
  scroll-snap-align: center;
}

/* One frame size for every slide; taller shots (Operations) are shown whole, centred. */
.oc-slide-frame {
  aspect-ratio: 1920 / 1245;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  overflow: hidden;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  background: #f2f5fa;
}

.oc-slide-frame img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: top center;
  cursor: zoom-in;
}

.oc-slide-caption {
  margin-top: 10px;
  font-size: 14px;
  color: var(--vp-c-text-2);
  text-align: center;
}

.oc-slider-arrow {
  position: absolute;
  top: calc(50% - 22px);
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 50%;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  box-shadow: 0 6px 18px -8px rgba(15, 23, 42, 0.35);
  opacity: 0.92;
  transition: opacity 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.oc-slider-arrow:hover {
  opacity: 1;
  border-color: var(--vp-c-brand-1);
  color: var(--vp-c-brand-1);
}

.oc-slider-arrow.is-prev { left: -20px; }
.oc-slider-arrow.is-next { right: -20px; }

.oc-slider-controls {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 6px;
}

.oc-slider-dots {
  display: flex;
  gap: 4px;
}

/* 24px hit area around an 8px dot. */
.oc-slider-dot {
  width: 24px;
  height: 24px;
  padding: 0;
  border: 0;
  background: none;
  position: relative;
}

.oc-slider-dot::after {
  content: "";
  position: absolute;
  inset: 8px;
  border-radius: 50%;
  background: var(--vp-c-divider);
  transition: background-color 0.2s ease, transform 0.2s ease;
}

.oc-slider-dot:hover::after {
  background: var(--vp-c-text-3);
}

.oc-slider-dot.is-active::after {
  background: var(--vp-c-brand-1);
  transform: scale(1.25);
}

.oc-slider-pause {
  padding: 4px 8px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 4px;
  font-size: 13px;
}

.oc-slider-count {
  min-width: 3.5em;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  color: var(--vp-c-text-3);
}

@media (max-width: 768px) {
  .oc-slider-arrow { display: none; }
}

@media (prefers-reduced-motion: reduce) {
  .oc-slider-arrow,
  .oc-slider-dot::after {
    transition: none;
  }
}
</style>
