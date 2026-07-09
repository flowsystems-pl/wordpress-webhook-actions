# Flowsystems Webhook Actions — contributor guide

WordPress webhook delivery plugin. PHP backend (PSR-4 under `src/`, WordPress
conventions) + Vue 3 admin SPA (`admin/src/`, built to `admin/dist/`).

## Code conventions

### File-size budget (refactor before it hurts)

Keep a source file focused on one responsibility. **Soft budget: ~800 lines**
for a PHP class or a Vue component. Crossing it is a smell, not a crime — it
means the file has probably accreted a second responsibility that wants its own
collaborator.

When a file grows past the budget, prefer **extracting cohesive collaborators**
over adding to it:

- Pull a self-contained concern into its own class/component with a narrow public
  API, and delegate to it. Keep the original as the coordinator.
- Move shared pure helpers to a small static utility so logic lives in one place.
- Preserve the public API — callers should not have to change.

Worked example: the AI Builder plan executor was split into
`src/Services/Ai/PlanExecutor.php` (state machine) + `BuildLedger` (idempotent
reuse of already-built objects), `StepReverter` (undo mechanics),
`ProbeInterpreter`, and `StepResult` (shared result helpers). Same behaviour,
each piece independently testable.

A **non-blocking hook** (`.claude/hooks/warn-large-file.sh`, wired in the project
`.claude/settings.json`) nudges when an edited source file crosses the budget.
It never blocks the edit. Tune the threshold via `FSWA_FILE_LINE_BUDGET`.

### General

- Match the surrounding code's style, naming and comment density.
- Backend is PHP with WordPress APIs; the admin SPA is Vue 3 Composition API +
  Tailwind. Rebuild the SPA after Vue changes: `nvm use 20.20.2 && npm run build`
  in `admin/`.
- `admin/dist/`, `vendor/` and `svn/` are generated/vendored — never hand-edit.
