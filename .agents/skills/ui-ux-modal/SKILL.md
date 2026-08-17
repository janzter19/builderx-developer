---
name: ui-ux-modal
description: Strictly design and implement accessible shadcn/ui dialogs and modals in BuilderX interfaces, including responsive sizing, equal spacing, readable long content, sticky regions, body-owned actions, validation states, and non-nested surface hierarchy. Use when adding, changing, or reviewing modal or dialog interactions.
---

# UI/UX Modal

Use this skill for modal and dialog work in the BuilderX React/shadcn interface. Prefer the existing shadcn/ui primitives and project tokens over custom modal markup or a new visual template.

## Strict implementation rules

- Use `Dialog`, `DialogTrigger`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose`, and the existing Lucide icon set where applicable.
- Keep the dialog accessible: provide a meaningful title and description, preserve focus management, support Escape and keyboard navigation, and make the close action discoverable.
- Keep width and height responsive. Use `max-h-[calc(100dvh-2rem)]`, `w-[calc(100vw-2rem)]`, and a constrained internal scroll region for long content. Never allow the page behind the modal to become the modal's content scroll container.
- Always separate the dialog into exactly three structural regions: header, independently scrollable body, and footer. Use an opaque `bg-popover` surface with a stacking layer for sticky header/footer regions so content does not show through while scrolling.
- If the body contains tabs, render the tab menu as its first body element directly below the dialog header. Status, progress, validation, and workflow content must appear below the tab menu, never above it.
- When the body uses uniform padding, extend the opaque sticky tab-header surface through the top padding so the menu meets the dialog header without a visual gap.
- Give the scrollable body uniform padding, normally `p-6`. The first and last body content must have the same inset as the left and right content; do not use asymmetric `px-*`/`py-*` spacing for the final layout.
- The shared `DialogFooter` has default negative margins and default `sm:justify-end`. When the dialog uses `DialogContent` with `p-0`, explicitly override it with `m-0 w-full rounded-none`; when the footer needs a left label and right action, explicitly include `sm:justify-between`.
- Operational controls belong in the scrollable body. The footer must contain only the persistent footer label/status and the explicit Close/Cancel action unless the user specifically requests a submit action there.
- Keep the footer label anchored left and the Close/Cancel action anchored right on desktop. Allow intentional stacking on narrow screens only when side-by-side controls would clip or overflow.
- Do not put a bordered `Card` inside a bordered dialog or another bordered box. Use spacing, typography, separators, or a different background surface for internal grouping.
- Keep action labels explicit. Use a clear primary action, a secondary cancel/close action, and inline validation or error text near the field that needs attention.
- Preserve the existing route, state, and persistence behavior. A visual modal change must not silently change the underlying CRUD or form contract.

## Required verification checklist

- Open and close the dialog with the trigger, close control, Escape, and keyboard navigation.
- Confirm the title and description are exposed to assistive technology and focus returns to the trigger after close.
- Test long content and confirm only the body scrolls while header and footer remain visible.
- Test narrow/mobile widths and confirm equal body insets, no clipped controls, and no horizontal overflow.
- Confirm the last body object has bottom spacing equal to the body’s other insets and the footer aligns to the same content edges.
- Confirm footer label is on the left and Close/Cancel is on the right at desktop widths.
- Test validation errors, cancel, submit, and pending/disabled action states where applicable.
- Confirm there are no nested bordered boxes, inherited negative-margin surprises, console errors, or accidental page-scroll regressions.
- Run the relevant route check and frontend build after implementation.
