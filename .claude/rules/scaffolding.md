<!-- craftcms-claude-skills -->
# Scaffolding

Use Craft's generator to scaffold new plugin components rather than hand-writing boilerplate. It wires namespaces, base classes, and registration correctly.

```bash
php craft make controller          # Web/console controller
php craft make service             # Service component
php craft make migration           # Database migration
php craft make queue-job           # Queue job (use for large imports)
php craft make model               # Settings / data model
```

(No DDEV in this project — run `php craft …` directly from the repo / Craft install that has this plugin loaded.)

After adding a component:

- Register services as components in `Plugin::config()`.
- Register CP routes in `Plugin::registerCpRoutes()`.
- Run `composer dump-autoload` if the autoloader doesn't pick up a new class.

See the `craftcms` skill for generator details and registration patterns.
