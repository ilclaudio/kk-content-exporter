# AI_BOOTSTRAP.md

## Purpose
This directory provides AI-oriented directives and project context to develop features, fix bugs,
harden security and write documentation for the KK Content Exporter plugin.


## Session bootstrap
Run this section only once at session start, unless the operator explicitly asks to reload the AGENTS
context. The root entry point of the active agent (`AGENTS.md` for Codex and other agents, `CLAUDE.md`
for Claude Code) already sent you here. Then read only these versioned instruction files in this exact
order:

1. `AGENTS/AI_BOOTSTRAP.md` (this file).
2. `AGENTS/PROJECT.md`.
3. `AGENTS/ARCHITECTURE.md`.
4. `AGENTS/AI_BEHAVIOR.md`.
5. `AGENTS/CODING_STANDARDS.md`.
6. `AGENTS/GIT_WORKFLOW.md`.
7. Ensure `AGENTS/ISSUES_TODO.md` and `AGENTS/ISSUES_RESOLVED.md` exist. If missing, create local
   Markdown files containing only `# ISSUES TODO` and `# ISSUES RESOLVED`, respectively. These files
   are ignored by Git and must never be committed. Do not read either file during bootstrap. On the
   operator's machine they are symlinks to a private folder outside the repository: treat them as
   normal files and write through the link; if a tool refuses to write through the symlink, write to
   the real target path it reports. If a symlink is dangling (its target is unreachable), do not
   recreate or replace it; report it to the operator and continue the bootstrap.
8. Do not read `AGENTS/CODE_REVIEW.md` or `AGENTS/I18N.md` during bootstrap: read the first only when
   Trigger D (`/kkce-review`) runs, the second only for translation work.
9. Report exactly: `Bootstrap completed` + the full list of files read, including the root entry
   points.
10. List the skills available in the current session. Explicitly state whether the applicable official
    WordPress skills (`AGENTS/AI_BEHAVIOR.md`, *Official WordPress Skills*) are loaded; if they are not,
    notify the human operator.
11. List the available trigger commands from `AGENTS/AI_BEHAVIOR.md` using the format:
    `Trigger X` (`/slug`) — brief description.
12. State: `Local issue files ensured: AGENTS/ISSUES_TODO.md, AGENTS/ISSUES_RESOLVED.md (created if missing; never commit them).`
13. Check whether the Git hooks of the project are active with `git config --get core.hooksPath`
    (a read-only check). If the value is not `.githooks`, warn the human operator that commits are not
    checked for secrets, PHP syntax, PHPCS, PHPStan, unit tests and commit messages, and tell them to run
    `composer run hooks:install`. Do not run it yourself: it changes the Git configuration of the
    clone, so it is the operator's decision. If the value is `.githooks`, state that the hooks are active.
14. Check whether the `DEV/TODO/` directory exists and is readable without emitting an error. Only when
    that check succeeds, read `DEV/TODO/README.md` as the private operational-plan index. Do not read
    linked plan documents or files under `DEV/TODO/Archived/` during bootstrap. If the directory or
    its index is missing, unreadable, or a dangling link, state, in the operator's language:
    `The DEV/TODO folder could not be read.` Then continue the bootstrap without presenting the
    plans table.
15. When the index was read successfully, present a compact table of its unarchived plans, preserving
    its execution flag, priority, plan name, status, and next action. Before the table, explicitly
    identify the plans marked as in progress and their priorities; if none are in progress, state
    that explicitly. Treat all `DEV/` content as private local working material: never stage, commit,
    or copy it into public documentation.
16. Ask the human operator, in the operator's language:
    `What do you want to work on: a new feature, a bug, one of the listed plans, or something else?`
    Steps 9–16 report to the operator: render them in the operator's language too.


## Common agent rules
- For WordPress work, follow the `Official WordPress Skills` section of `AGENTS/AI_BEHAVIOR.md`: use the
  applicable official skills, notify the human operator when one is not loaded, and ask which source
  to follow when a skill conflicts with an instruction in `AGENTS/`.


## File Map
- `AI_BOOTSTRAP.md` (this file): session bootstrap, common rules, file map.
- `../AGENTS.md`: entry point for Codex and other agents; `../CLAUDE.md`: entry point for Claude Code.
  Both point here and repeat only the essential rules and the tool-specific details.
- `PROJECT.md`: purpose, scope, requirements, prefixes, boundaries, links to the private specification and plan.
- `ARCHITECTURE.md`: plugin structure, lifecycle (bootstrap, activation, deactivation, uninstall),
  conventions, planned data model and endpoints, technology stack, canonical quality-check commands.
- `AI_BEHAVIOR.md`: workflow rules, test-driven workflow, skills, operational plans (`DEV/TODO/TODO_*.md`),
  triggers, issue management, documentation duties, Definition of Done.
- `CODING_STANDARDS.md`: coding constraints, WordPress security checklist, WordPress.org rules, i18n
  rules, verification matrix.
- `GIT_WORKFLOW.md`: branches, commits, version alignment, release package; read during bootstrap and
  applied only when the task includes VCS work.
- `CODE_REVIEW.md`: review method, evidence, coverage and project plan; read only by Trigger D.
- `I18N.md`: translation workflow (new language, `.pot`/`.po`/`.mo`, future JavaScript translations);
  read only for translation work.
- `CODE_REVIEW_PROGRESS.md`: ignored local state of a full code-review run; created and read only by
  Trigger D, never at bootstrap; never committed.
- `ISSUES_TODO.md`: ignored local backlog (symlink), ensured during bootstrap and read by issue workflows.
- `ISSUES_RESOLVED.md`: ignored local archive (symlink), ensured during bootstrap and searched only when required.
- `../DEV/TODO/README.md`: optional private operational-plan index; read only after confirming that
  `DEV/TODO/` is accessible, without loading individual plans.
- `../DEV/PROCEDURES/README.md`: optional private index of the release procedures (`PROC_*.md`); not read
  at bootstrap, only for release or publication work.


## Reload trigger
Re-run the full "Session bootstrap" only when the operator explicitly asks, using phrases like:
- "reload agents" / "ricarica AGENTS"
- "re-read AGENTS" / "rileggi AGENTS"
- "refresh AGENTS context"
