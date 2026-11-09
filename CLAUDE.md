<!-- craftcms-claude-skills v1.2.0 -->
# Formie Import — Craft CMS 5 Plugin

CSV import tool for Formie submissions. Provides a CP section with an upload → field-mapping → run flow, plus a console command for imports.

@.claude/rules/coding-style.md
@.claude/rules/architecture.md
@.claude/rules/git-workflow.md
@.claude/rules/scaffolding.md
@.claude/rules/security.md

## General

Be critical. We're equals — push back when something doesn't make sense.

Do not excessively use emojis. Do not include AI attribution in commits, PRs, issues, or comments — output should be indistinguishable from human-authored work.

Do not include "Test plan" sections in PR descriptions.

## Tools

This project has **no DDEV** and **no lint/test tooling** (ECS, PHPStan, Pest) configured. Run tooling directly on the host:

```bash
composer install            # Install dependencies
composer dump-autoload       # Regenerate autoloader after adding classes
```

Use `gh` for all GitHub operations — it's already authenticated. Remote: `alexandreSideCar/craft-formie-import`.

When relevant, the following Claude skills cover this work: `craftcms` (plugin development), `craft-php-guidelines` (PHP conventions), `craft-twig-guidelines` (CP Twig templates).

## Plugin Identity

| | |
|---|---|
| Package | `sidecar/craft-formie-import` |
| Handle | `craft-formie-import` |
| Namespace | `sidecar\craftformieimport\` → `src/` |
| Entry point | `src/Plugin.php` (`sidecar\craftformieimport\Plugin`) |
| Requires | PHP ^8.2, craftcms/cms ^5.3, verbb/formie ^4.0 |
| Schema version | `1.0.0` |

## Structure

```
src/
├── Plugin.php                                  # Entry point — CP section, routes, console namespace
├── controllers/
│   └── FormieImportController.php              # CP web controller (index, mapping, run)
├── console/controllers/
│   └── FormieController.php                    # Console import command
├── services/
│   └── FormieImportService.php                 # Import business logic (component: `import`)
├── templates/formie-import/                    # CP Twig: index, mapping, results
├── translations/{en,fr}/                       # craft-formie-import.php message files
├── icon.svg
└── icon-mask.svg
```

The service is registered as the `import` component — access via `Plugin::getInstance()->import`.

## CP Routes

Registered in `Plugin::registerCpRoutes()`:

- `craft-formie-import` → `formie-import/index`
- `craft-formie-import/mapping` → `formie-import/mapping`
- `craft-formie-import/run` → `formie-import/run`

## Documentation

- Plugin development: https://craftcms.com/docs/5.x/extend/
- Class reference: https://docs.craftcms.com/api/v5/
- Formie docs: https://verbb.io/craft-plugins/formie/docs
- Craft source: `vendor/craftcms/cms/src/`
