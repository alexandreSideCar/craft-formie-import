<!-- craftcms-claude-skills -->
# Coding Style

Follow Craft CMS 5 PHP conventions. The `craft-php-guidelines` skill covers these in depth — load it when writing or reviewing PHP.

## Essentials

- `declare(strict_types=1);` at the top of every PHP file.
- Typed properties and return types everywhere, including `void`. Use short nullable notation (`?string`).
- PHPDoc blocks on classes and public methods. Document thrown exceptions with `@throws`.
- Section header comment blocks (`// ========`) to separate public/protected/private and logical groups in larger classes.
- Early returns over nested conditionals. Prefer `match` over `switch`.
- Use `Craft::t('craft-formie-import', '...')` for all user-facing strings, and add the keys to both `src/translations/en/` and `src/translations/fr/`.

## Project conventions

- Namespace root is `sidecar\craftformieimport\` mapped to `src/`. Match existing file casing and structure.
- Access the import service via `Plugin::getInstance()->import`, not by re-instantiating it.
- Keep business logic in `services/`; controllers stay thin (validate input, call the service, return a response).
- Use `DateTimeHelper` for date handling, not raw `DateTime`/Carbon.
