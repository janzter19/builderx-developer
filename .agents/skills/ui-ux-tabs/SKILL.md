---
name: ui-ux-tabs
description: Design and implement accessible shadcn/ui tabs in BuilderX interfaces, including fit-content tab triggers, Lucide icons, card-header placement, sticky tab headers, independently scrollable content, and responsive behavior. Use when adding, rearranging, or reviewing tabs, tab menus, or tab panels.
---

# UI/UX Tabs

Use this skill for tab navigation and tab-panel work in the BuilderX React/shadcn interface. Reuse the existing shadcn/ui Tabs primitives and the project’s established layout rules instead of inventing a parallel tab template.

## Implementation rules

- Use `Tabs`, `TabsList`, `TabsTrigger`, and `TabsContent` from the project’s shadcn/ui implementation.
- Place a card-level tab menu in the card header above the tab content. The header must remain visible when the tab body scrolls: use a sticky header such as `sticky top-0 z-10` with an opaque `bg-card` or equivalent surface.
- When tabs are inside a modal, the tab menu must be the first body element immediately below the modal header. Do not place status banners, workflow steps, alerts, descriptions, or other content above the tab menu; keep modal title and description in the dialog header only.
- The sticky modal tab header must cancel the body’s top inset when needed (for example with `-mt-6` alongside `-mx-6`) so no blank band or underlying content appears between the dialog header and tab menu.
- Keep the tab menu fit-content. Use `w-fit`, `flex-none`, and wrapping or horizontal overflow as needed; do not stretch every trigger across the panel unless equal-width controls are explicitly required.
- Add an appropriate existing Lucide icon to each tab trigger when one is available, keeping the icon paired with a readable text label.
- Put long tab content in its own constrained vertical scroll region (`overflow-y-auto`) instead of relying only on the outer page scroll. Do not hide the menu behind the page scroll.
- Place tab-specific actions in an action bar outside the tab's scrollable content region but still inside the owning form. Use a shrink-safe opaque surface and top separator so the action remains visible while long `TabsContent` scrolls.
- Render a tab-specific action only for the tab it submits; do not expose that submit button on unrelated tabs and do not duplicate it in the shared/page footer. Keep shared footers informational unless an action truly applies to every tab.
- Keep the tab action bar separate from the page-level footer defined by `ui-ux-main`. Use a responsive `flex` row, clear spacing, and a full-width button only when needed on narrow screens; make the action bar sticky only when the surrounding layout requires it.
- Use `type="submit"` when the tab content is connected to a real form action. If the target has no save endpoint yet, use a non-submitting button until the persistence contract is implemented; do not send an invalid POST.
- Keep active, hover, focus-visible, disabled, and narrow-screen states legible and keyboard accessible. Do not remove the visible focus indicator.
- Do not place nested bordered cards or boxes inside a bordered tab card. Use spacing, typography, separators, or a different background surface for internal grouping.
- Preserve selected-tab state, route/query behavior, and any existing form persistence when changing the visual structure.

## Verification checklist

- Switch every tab and confirm the correct panel is displayed without stale content.
- Confirm the menu stays visible while a long panel scrolls and that its surface is opaque.
- Confirm triggers fit their labels, icons render, and the menu remains usable at narrow widths.
- Confirm the tab-specific action stays outside the scrollable body, appears only for its owning tab, remains visible below long content, and stacks cleanly on mobile.
- Test keyboard focus, activation, disabled states, and any selected-tab read-back or URL state.
- Check the route in the browser, inspect the scroll container, and run the relevant frontend build.
