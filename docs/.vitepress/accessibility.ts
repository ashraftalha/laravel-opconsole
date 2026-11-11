import type { Plugin } from 'vite'

/** Narrow fixes for VitePress 1.6's default-theme markup, shared by SSR and the client.
 * Keep the theme's layout and behavior. Recheck these patches when upgrading VitePress;
 * fail the build if upstream markup changes rather than silently losing the fixes.
 */
export function accessibleTheme(): Plugin {
  return {
    name: 'opconsole-accessible-theme',
    enforce: 'pre',
    transform(source, id) {
      if (!id.includes('/vitepress/dist/client/theme-default/components/') || !id.endsWith('.vue')) return
      const patch = (before: string, after: string) => {
        if (!source.includes(before)) throw new Error(`Accessibility patch needs review: ${id}: ${before}`)
        source = source.replace(before, after)
      }
      if (id.endsWith('/VPHome.vue')) {
        patch('class="VPHome"', 'class="VPHome" role="main"')
      } else if (id.endsWith('/VPSidebarItem.vue')) {
        patch("e.key !== 'Enter'", "e.key !== 'Enter' && e.key !== ' '")
        patch('!props.item.link && toggle()', "if ('key' in e) e.preventDefault()\n  !props.item.link && toggle()")
        patch(':role="itemRole"', ':role="itemRole"\n      :aria-expanded="collapsible ? !collapsed : undefined"')
        // For unlinked group headings, the parent is already the toggle button.
        patch('role="button"\n        aria-label="toggle section"', ':role="item.link ? \'button\' : undefined"\n        :aria-hidden="item.link ? undefined : true"\n        aria-label="toggle section"')
        patch('tabindex="0"', ':tabindex="item.link ? 0 : -1"')
      } else if (id.endsWith('/VPLocalSearchBox.vue')) {
        // The trap activates after the input has focus, so by default closing returns focus to the
        // removed input (i.e. <body>). Return it to the control that opened search instead.
        patch('const { activate } = useFocusTrap(el, {', 'const returnFocusTo = inBrowser ? document.activeElement : null\nconst { activate } = useFocusTrap(el, {\n  setReturnFocus: (previous) => returnFocusTo instanceof HTMLElement && returnFocusTo !== document.body && returnFocusTo.isConnected ? returnFocusTo : previous,')
        // Icon-only key hints: aria-label is ignored on <kbd> without a role.
        for (const key of ['navigateUp', 'navigateDown', 'select']) {
          patch(`<kbd :aria-label="translate('modal.footer.${key}KeyAriaLabel')">`, `<kbd role="img" :aria-label="translate('modal.footer.${key}KeyAriaLabel')">`)
        }
        patch('role="button"\n      :aria-owns="results?.length ? \'localsearch-list\' : undefined"\n      aria-expanded="true"\n      aria-haspopup="listbox"', 'role="dialog"\n      aria-modal="true"')
        patch('ref="searchInput"', 'ref="searchInput"\n            role="combobox"\n            aria-haspopup="listbox"\n            :aria-expanded="!!results?.length"')
        patch(':id="\'localsearch-item-\' + index"\n            :aria-selected="selectedIndex === index ? \'true\' : \'false\'"\n            role="option"', 'role="presentation"')
        patch(':href="p.id"', ':href="p.id"\n              :id="\'localsearch-item-\' + index"\n              :aria-selected="selectedIndex === index ? \'true\' : \'false\'"\n              role="option"')
      } else return
      return source
    },
  }
}
