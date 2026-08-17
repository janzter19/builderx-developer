---
name: ui-ux-main
description: Design and implement the main BuilderX page shell with shadcn/ui Sidebar, header, content workspace, responsive columns, and a sticky footer. Use when creating or reviewing the primary layout, page frame, workspace structure, or persistent footer labels.
---

# UI/UX Main Layout

Use this skill for the shared BuilderX page frame. Reuse the existing shadcn/ui shell and tokens so the sidebar, header, workspace, scroll regions, and footer behave consistently across Phase Builder and Phase Manager.

## Main layout rules

- Use the shadcn `Sidebar`, `SidebarProvider`, `SidebarInset`, and `SidebarTrigger` for the application shell.
- Set the application shell to `h-svh min-h-0 overflow-hidden` so the document does not gain an unnecessary browser scrollbar; let the header, workspace, and footer divide the viewport through flex sizing.
- Keep the main workspace full width. Do not add an arbitrary centered `max-width` around the primary page content.
- Keep the header aligned with the sidebar boundary and use a consistent border, height, padding, breadcrumb, and action area.
- Let the main area own page scrolling. For cards with long content, create an independent `overflow-y-auto` region so persistent card controls remain visible.
- Size long workspaces from the available flex height rather than `100vh` offsets that also include the header, footer, and padding. Keep overflow on the intended content region with `min-h-0`, `flex-1`, and `overscroll-contain`.
- Use responsive grid columns for secondary panels; let the primary workspace take the remaining width and avoid fixed widths that cause clipping.
- Do not stack bordered cards or boxes inside another bordered surface. Use spacing, typography, separators, or a different background surface for internal grouping.

## Sticky footer

- Add a page-level `<footer>` as a sibling of the main content inside `SidebarInset`; it belongs to the application shell, not to an individual card or tab panel.
- Keep the footer visible with an opaque surface, top separator, and `sticky bottom-0 z-10`. The main content should remain the scrolling region while the shell footer stays outside that scroll container.
- Use a responsive `flex` row with `justify-between`: place the informational text label on the left and the secondary text label on the right.
- Keep both labels readable and aligned at desktop widths. Stack them cleanly on narrow screens with `flex-col items-stretch` and a small gap.
- Do not replace the text labels with icon-only controls. Add icons only when they clarify a real action or navigation affordance.

## Verification checklist

- Check sidebar collapse, header alignment, breadcrumb readability, and the full-width workspace at desktop and narrow widths.
- Confirm long content scrolls without hiding the header or footer.
- Confirm the footer background is opaque, the left and right labels are present, and the mobile layout does not clip either label.
- Check that no nested bordered cards were introduced and run the relevant frontend build and route check.
