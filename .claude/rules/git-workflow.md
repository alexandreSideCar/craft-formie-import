<!-- craftcms-claude-skills -->
# Git Workflow

This is a small solo plugin — **commit directly to `main`**. No PR or branch requirement unless you specifically want one for a larger change.

## Commits

- Only commit or push when explicitly asked.
- Write concise, imperative subject lines describing what changed and why (the existing history is a good model — e.g. "Security & performance hardening").
- Group related changes into one commit; don't bundle unrelated work.
- **No AI attribution** in commit messages — no Co-Authored-By trailers, no "generated with" lines. Output should read as human-authored.

## GitHub

Use `gh` for any GitHub operations (it's authenticated). Remote: `alexandreSideCar/craft-formie-import`.

- No "Test plan" sections in PR descriptions.
- No AI attribution in PR descriptions, issues, or comments.
