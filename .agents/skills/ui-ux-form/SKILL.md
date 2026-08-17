---
name: ui-ux-form
description: Design and implement accessible BuilderX forms with shadcn/ui controls, native validation, complete persisted upsert behavior, confirmation before every form submission, server-backed rehydration, and clear success or failure feedback. Use when adding, changing, or reviewing any form, submit action, or form-to-backend workflow.
---

# UI/UX Form

Use the existing shadcn/ui form controls and project tokens. Every form submission must pass browser validation and then open an accessible confirmation modal before the original native or React submit handler runs.

## Submission workflow

1. Keep the form's existing method, action, hidden fields, CSRF field, submitter, and handler unchanged.
2. Let native constraint validation run first (`required`, `maxLength`, input types, and server validation hints).
3. Intercept the valid submit at the shared form boundary and prevent the original action temporarily.
4. Show a shadcn `AlertDialog` with a meaningful title, concise action description, Cancel, and Confirm.
5. On Cancel or Escape, close the dialog and do not mutate form state or send a request.
6. On Confirm, replay the same form submission exactly once, preserving the original submitter where available.
7. Allow the existing form handler or native POST/GET navigation to continue. Do not duplicate fetches, bypass CSRF, or change database behavior.

## Persisted form workflow

For any form that creates or updates data, apply `database-transaction` as part of the same implementation:

1. Define one allow-listed mapping between UI field names and database column names. Explicitly convert naming styles such as `product-goal` to `product_goal`; never rely on fallback defaults after a save.
2. Submit both create and update through one complete server-side upsert keyed by the stable business key.
3. Keep the stable record key on update, check every ADODB write result, read back the saved keys and fields before commit, and roll back on any mismatch.
4. After redirect or refresh, rehydrate the form from the server/database payload and verify the visible control values match the committed row.

## Submission feedback

After the confirmed submission completes, provide exactly one result message:

- On success, show the existing BuilderX success toast with `role="status"` and polite live-region behavior. It should dismiss automatically without blocking the next action.
- On failure, show an accessible shadcn `AlertDialog` that explains the failure and provides an explicit Close action. Keep it open until the user dismisses it; do not hide failures in a transient toast.
- When a technical reason is available, keep it collapsed behind a clearly labeled `View more` control inside the failure modal; render long details in a scrollable, selectable text region.
- Preserve server flash messages across native redirects so the result is shown after the page reloads.
- Do not show both a success toast and an error modal for the same submission, and do not treat the confirmation modal as the result message.
- Keep field-level validation near the invalid field; use the failure modal for submission, authorization, persistence, or unexpected server errors.

## Implementation rules

- Prefer one shared form-level confirmation guard over duplicating modal state in every form.
- Use `AlertDialog`, `AlertDialogHeader`, `AlertDialogTitle`, `AlertDialogDescription`, `AlertDialogFooter`, `AlertDialogCancel`, and `AlertDialogAction` from the existing shadcn implementation.
- Preserve keyboard behavior: Enter submits the form into confirmation, Escape cancels, focus is trapped in the dialog, and focus returns to the submit control after closing.
- Keep confirmation copy action-specific when the form exposes an action label; otherwise use a safe generic message such as “Review this form before submitting.”
- Keep a tab-specific submit control inside its owning `<form>` but outside the tab's scrollable content region when long fields need independent scrolling. A `form` attribute may associate the control with the form when the action bar is rendered beside the form content; do not duplicate the control in a shared footer or expose it on unrelated tabs.
- Confirmation is required for native GET and POST forms as well as React `onSubmit` forms. Do not silently exempt a form because it uses a different handler.
- Do not use `form.submit()` to bypass confirmation from a user action. If replay is required after confirmation, use `requestSubmit()` with a one-shot internal bypass so the original submitter and handler are retained.
- Keep visual hierarchy flat: do not add a bordered card inside the confirmation dialog or another bordered form surface.
- Keep validation errors near their fields and never treat opening the modal as validation success.
- For persisted writes, also apply `database-transaction`: authorization, CSRF, ADODB transactions, parameterized SQL, audit logging, and direct read-back remain server responsibilities.
- Do not report a save as complete from a toast alone. The success toast follows a committed server result and the form must display the committed values after navigation or refresh.

## Verification checklist

- Submit representative forms with a mouse and with Enter; confirm the modal appears before any request or handler runs.
- Confirm Cancel and Escape do not send the form, and Confirm sends exactly one request.
- Confirm the original method, action, fields, CSRF token, selected submitter, React handler, and redirect behavior are unchanged.
- Test required-field and invalid-input states; invalid forms must not open the confirmation dialog until corrected.
- Test narrow screens and long confirmation text; keep the dialog readable and actions visible.
- Test successful submission feedback: one success toast appears and auto-dismisses.
- Test failed submission feedback: one informative failure modal appears, remains available until Close or Escape, and does not also show an error toast.
- When technical details are available, confirm `View more` reveals the real reason and `Hide details` collapses it without changing the failure state.
- Test persisted create and update separately; confirm both upsert paths commit, read back, redirect, and repopulate the same form values.
- Test UI/database key mapping explicitly; saved values must not be replaced by defaults after refresh.
- Run frontend type-check/build and focused route/browser checks after changes.
