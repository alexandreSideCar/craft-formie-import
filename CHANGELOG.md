# Release Notes for Formie Import

## 2.0.0 - Unreleased

> Formie Import 2 requires Formie 4. Stay on Formie Import 1.x for Formie 3.

### Changed
- Formie Import now requires Formie 4.0 or later.

### Added
- Formie 4 exports are read as is: `Yes` / `No` flags, and a Name field exported as a single column is split into first and last name (with a warning) when the target field stores them separately.
- Formie 3 exports can still be imported, to bring submissions over to a Formie 4 site.
- File upload fields without their own upload location use the one set in the Formie form defaults.

### Fixed
- Duplicate detection now also looks at spam and incomplete submissions, so re-running an import with `--skipSpam=0` no longer creates the spam rows again.

## 1.1.1 - 2026-09-23

### Fixed
- File upload look-up matches file names in both Unicode normalization forms (macOS stores accented names decomposed, exports and Linux hosts compose them).

## 1.1.0 - 2026-09-23

### Added
- `--preserveMeta` (console) and the "Preserve metadata" switch (CP): keep the exported dates, IP address, spam flags and status on imported submissions.
- File upload fields: exported file names/URLs are resolved to assets in the field's upload volume, or uploaded from `--filesDir`; unresolved files are reported as warnings.
- `--since` to import only rows created from a given date.
- `Date Created` and `IP Address` accepted as anti-duplicate keys, so a delta import can be re-run safely.
- Warnings are listed in the console output and on the CP results page.

### Fixed
- Sub-field columns (`Name: First Name`, `Name: Last Name`, …) each feed their own sub-field instead of the last column overwriting the field value.
- README: console commands are routed under the plugin handle (`craft-formie-import/formie/...`).
