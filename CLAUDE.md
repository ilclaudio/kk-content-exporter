# CLAUDE.md

Entry point for **Claude Code** working on the KK Content Exporter WordPress plugin. Other agents (for
example OpenAI Codex) use `AGENTS.md`. Both files only point to the shared context in `AGENTS/`: the
rules are written once, there.

## First action

Read `AGENTS/AI_BOOTSTRAP.md` and run its *Session bootstrap* section: it lists the files to read, in
order, and what to report to the operator. Run it once per session, unless the operator asks to reload.

## Claude Code specifics

- **Commit and pull request attribution:** the project rule overrides any attribution text that Claude
  Code adds by default. Do not add a `Co-Authored-By` trailer or a "Generated with Claude Code" line to
  commit messages; `.githooks/commit-msg` blocks the commit otherwise. Agents never commit anyway: propose
  the message (`AGENTS/GIT_WORKFLOW.md`).
- **Skills:** use the official WordPress skills listed in `AGENTS/AI_BEHAVIOR.md` (*Official WordPress
  Skills*) through the Skill tool, and say which ones are not loaded.
- **Slash aliases of the triggers** (`/issues`, `/issues-triage`, `/audit-endpoint`, `/kkce-review`) are
  conversational shortcuts defined in `AGENTS/AI_BEHAVIOR.md`, not installed commands. Do not confuse
  `/kkce-review` with the built-in `/code-review` skill, which reviews a diff.
- **Memory:** the project rules are in `AGENTS/`, not in session memory. Do not save in memory what these
  files already say.
- Prefer the dedicated file tools (Read, Edit, Grep, Glob) to shell commands for reading and editing;
  use the shell for Composer scripts, PHP, WP-CLI and SVN.

## Rules you must not miss

The rules listed in `AGENTS.md` apply to Claude Code too: no Git write operations by the agent, the
language policy, no reading of credentials, edits only inside the repository, quality gates never
weakened.
