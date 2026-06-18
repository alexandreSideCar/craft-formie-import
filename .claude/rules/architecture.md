<!-- craftcms-claude-skills -->
# Architecture

## Service-oriented

Business logic lives in `services/FormieImportService.php`, registered as the `import` component in `Plugin::config()`. Controllers (web and console) parse input, delegate to the service, and shape the response. Don't put import logic in controllers.

## Two entry surfaces

- **CP web flow** — `controllers/FormieImportController.php` drives the three-step UI (upload → mapping → run) backed by the Twig templates in `templates/formie-import/`. Routes are registered in `Plugin::registerCpRoutes()`; add new CP URLs there.
- **Console** — `console/controllers/FormieController.php` exposes the import for CLI/automation. The console controller namespace is only set when `Craft::$app instanceof \craft\console\Application`.

Keep both surfaces calling the same service methods so behavior stays consistent.

## Formie integration

The plugin depends on `verbb/formie` ^3.0. When working with forms, fields, and submissions, use Formie's own services and element types rather than reaching into the database directly. Resolve forms by handle/ID through Formie's API and reuse field handles for CSV column mapping.

## Schema & project config

`schemaVersion` is `1.0.0`. If you add migrations or persisted settings, bump it and follow Craft's project-config rules — see the `migrations` guidance in the `craftcms` skill.
