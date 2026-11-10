import { h, nextTick, onMounted, watch } from 'vue'
import { useRoute } from 'vitepress'
import DefaultTheme from 'vitepress/theme'
import mediumZoom from 'medium-zoom'
import ScreenshotSlider from './components/ScreenshotSlider.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('ScreenshotSlider', ScreenshotSlider)
  },
  // The header shows the square mark as the logo image and the name as real text, so the
  // wordmark stays crisp and uses the site font (text inside an <img> SVG renders blurry).
  Layout: () => h(DefaultTheme.Layout, null, {
    'nav-bar-title-after': () => h('span', { class: 'oc-wordmark' }, [h('span', { class: 'oc-wordmark-op' }, 'Op'), 'Console']),
  }),
  setup() {
    // Click any screenshot to see it full size; re-attached after each client-side navigation.
    const route = useRoute()
    const zoom = () => mediumZoom('.oc-shot img, .oc-slide img', { background: 'var(--vp-c-bg)', margin: 24 })
    onMounted(zoom)
    watch(() => route.path, () => nextTick(zoom))
  },
}
