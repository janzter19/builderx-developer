const refreshScrollStorageKey = 'builderx:refresh-scroll-position'

type SavedScrollPosition = {
  href: string
  left: number
  top: number
  elements: Array<{
    selector: string
    left: number
    top: number
  }>
}

function elementSelector(element: HTMLElement): string {
  const parts: string[] = []
  let current: HTMLElement | null = element

  while (current) {
    let index = 1
    let sibling = current.previousElementSibling
    while (sibling) {
      index += 1
      sibling = sibling.previousElementSibling
    }
    parts.unshift(`${current.tagName.toLowerCase()}:nth-child(${index})`)
    current = current.parentElement
  }

  return parts.join(' > ')
}

function scrollableElements(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('*')).filter((element) => {
    const style = window.getComputedStyle(element)
    const overflowY = ['auto', 'scroll', 'overlay'].includes(style.overflowY)
    const overflowX = ['auto', 'scroll', 'overlay'].includes(style.overflowX)

    return (overflowY && element.scrollHeight > element.clientHeight) ||
      (overflowX && element.scrollWidth > element.clientWidth)
  })
}

export function reloadPagePreservingScroll(): void {
  try {
    sessionStorage.setItem(refreshScrollStorageKey, JSON.stringify({
      href: window.location.href,
      left: window.scrollX,
      top: window.scrollY,
      elements: scrollableElements().map((element) => ({
        selector: elementSelector(element),
        left: element.scrollLeft,
        top: element.scrollTop,
      })),
    } satisfies SavedScrollPosition))
  } catch {
    // A blocked sessionStorage must not prevent the requested refresh.
  }

  // Replace the current document in-place. Some embedded browser surfaces
  // handle Location.reload() as a new navigation target instead of reloading
  // the active tab; replace() keeps the current tab, query, and hash.
  window.location.replace(window.location.href)
}

export function restoreScrollPositionAfterRefresh(): void {
  let savedPosition: SavedScrollPosition | null = null

  try {
    const storedPosition = sessionStorage.getItem(refreshScrollStorageKey)
    sessionStorage.removeItem(refreshScrollStorageKey)
    if (storedPosition) {
      const parsedPosition: unknown = JSON.parse(storedPosition)
      if (
        typeof parsedPosition === 'object' &&
        parsedPosition !== null &&
        'href' in parsedPosition &&
        'left' in parsedPosition &&
        'top' in parsedPosition &&
        'elements' in parsedPosition &&
        typeof parsedPosition.href === 'string' &&
        typeof parsedPosition.left === 'number' &&
        typeof parsedPosition.top === 'number' &&
        Array.isArray(parsedPosition.elements)
      ) {
        savedPosition = parsedPosition as SavedScrollPosition
      }
    }
  } catch {
    return
  }

  if (!savedPosition || savedPosition.href !== window.location.href) return

  let attempts = 0
  const restore = () => {
    window.scrollTo(savedPosition.left, savedPosition.top)
    for (const target of savedPosition.elements) {
      document.querySelector<HTMLElement>(target.selector)?.scrollTo(target.left, target.top)
    }
    attempts += 1

    const windowNeedsRestore = Math.abs(window.scrollX - savedPosition.left) > 2 ||
      Math.abs(window.scrollY - savedPosition.top) > 2
    const elementNeedsRestore = savedPosition.elements.some((target) => {
      const element = document.querySelector<HTMLElement>(target.selector)
      return element !== null && (
        Math.abs(element.scrollLeft - target.left) > 2 ||
        Math.abs(element.scrollTop - target.top) > 2
      )
    })

    if (attempts < 8 && (windowNeedsRestore || elementNeedsRestore)) {
      window.requestAnimationFrame(restore)
    }
  }

  window.requestAnimationFrame(restore)
}
