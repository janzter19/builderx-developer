# Project UI/UX Skills

## AI runtime boundary

- Do not add target-session controls, Desktop Codex send controls, deep-link message handoff, workers, queues, or pollers to Phase Builder.
- Phase Builder AI sending is currently disabled. Keep the existing visual control unchanged unless a future request explicitly authorizes a new AI transport.
- Do not add a worker, socket, queue, poller, heartbeat, status loop, or background retry for Phase Builder AI.

## Project-local skills

- Use [database-transaction](../../.agents/skills/database-transaction/SKILL.md) for all persisted form/tab writes, using one complete ADODB upsert for create/update, parameterized SQL, explicit write-result checks, audit logging, read-back verification, and server rehydration.
- Use [ui-ux-main](../../.agents/skills/ui-ux-main/SKILL.md) for the shared page shell, full-width workspace, header alignment, responsive columns, and sticky footer labels.
- Use [ui-ux-modal](../../.agents/skills/ui-ux-modal/SKILL.md) for accessible shadcn/ui dialogs and modals, including responsive sizing, scrollable bodies, sticky actions, and validation states.
- Use [ui-ux-tabs](../../.agents/skills/ui-ux-tabs/SKILL.md) for shadcn/ui tab menus and panels, including fit-content triggers, icons, sticky card headers, and independently scrollable content.
- Use [ui-ux-form](../../.agents/skills/ui-ux-form/SKILL.md) for all form layout and submission behavior; every valid form submit must open a confirmation modal before continuing, complete both upsert paths, rehydrate from saved server values, show a success toast after a committed result, and show an informational failure modal with optional `View more` technical details when submission fails.

## Main page shell

- Use the shared shadcn sidebar/inset/header structure and keep the primary workspace full width.
- BuilderX Phase Manager and Phase Builder must share the same full-width shell proportions, scroll boundary, and responsive outer spacing so navigation between them does not change the branded workspace geometry.
- Set the app shell to `h-svh min-h-0 overflow-hidden`; size the workspace from the remaining flex height instead of stacking `100vh` offsets with header/footer/padding.
- Keep the document free of unnecessary browser scrolling by placing overflow on the intended workspace or card content region, using `min-h-0`, `flex-1`, and `overscroll-contain`.
- Keep long card content independently scrollable so persistent controls stay visible.
- Use a sticky footer with a text label on the left and a text label on the right; stack both labels on narrow screens.

## Sticky card tabs

- Put card-level tab menus inside the card header, above the tab content.
- Keep the card header visible while content scrolls by using a sticky header with an opaque surface background and a clear stacking order.
- Give the tab content its own constrained vertical scroll region when the content is taller than the viewport; do not rely only on the outer page scroll.
- Keep tab labels fit-content and use existing icons when available.
- When a tab card has a submit action, add a tab-scoped sticky footer with the tab context label on the left and a submit button on the right; keep it separate from the global main-layout footer.
