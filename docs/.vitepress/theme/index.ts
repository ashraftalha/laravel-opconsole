import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import './custom.css'

export default {
  extends: DefaultTheme,
  // The header shows the square mark as the logo image and the name as real text, so the
  // wordmark stays crisp and uses the site font (text inside an <img> SVG renders blurry).
  Layout: () => h(DefaultTheme.Layout, null, {
    'nav-bar-title-after': () => h('span', { class: 'oc-wordmark' }, [h('span', { class: 'oc-wordmark-op' }, 'Op'), 'Console']),
  }),
}
