<!-- craftcms-claude-skills -->
# Security

This plugin accepts **uploaded CSV files** and writes **Formie submissions** — both the file boundary and the write path need defensive handling.

## Controllers

- Require an authenticated CP user. Gate import actions behind a permission check (`requirePermission`) or at minimum `requireLogin` / `requireCpRequest`.
- State-changing actions (the `run` step, anything that writes submissions) must be POST-only (`requirePostRequest`) and CSRF-protected. Do not disable CSRF validation.
- Never trust mapping input from the request — validate that mapped column → field handles actually exist on the target Formie form before writing.

## File handling

- Validate uploaded files are CSV by content/extension and enforce a size limit. Don't move uploads into a web-accessible path.
- Stream/parse large CSVs rather than loading everything into memory.
- Treat every cell as untrusted: sanitize/validate before assigning to field values, and let Formie's own validation run on each submission rather than bypassing it.

## Data writes

- Run imports through Formie's submission save path so element validation, events, and integrations fire normally.
- For large imports, prefer the console command or a queue job over a single long web request.
- Use parameterized queries / Craft's query builder — never string-concatenate SQL.
