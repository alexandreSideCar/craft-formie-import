# Craft Formie Import

CSV import tool for Formie submissions in Craft CMS 5.

![Packagist Version](https://img.shields.io/packagist/v/sidecar/craft-formie-import)
![License](https://img.shields.io/packagist/l/sidecar/craft-formie-import)

## Features

- **CSV import** — Import submissions from CSV files into any Formie form
- **Auto field mapping** — Automatic column-to-field matching by label or handle, with accent-normalized fuzzy matching
- **Multi-form support** — Import to a single form or multiple forms from one CSV file
- **Duplicate detection** — Skip duplicates based on configurable unique field combinations
- **Spam filtering** — Optionally skip rows marked as spam in the CSV
- **Dry run mode** — Test your import before committing to the database
- **Console commands** — List forms, generate mappings, and import via CLI
- **Environment migration** — Preserve the exported dates, IP, spam flags and status, re-link uploaded files, and re-run safely for a delta (`--since`)
- **Translations** — English and French included

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- [Formie](https://verbb.io/craft-plugins/formie) 3.0+

## Installation

```bash
composer require sidecar/craft-formie-import
php craft plugin/install craft-formie-import
```

## Usage

### Web Interface

1. Go to **Formie Import** in the Craft CP sidebar
2. Select a target form (or "All forms" for multi-form import)
3. Upload your CSV file and click **Analyze CSV**
4. Review the auto-generated column mapping and adjust if needed
5. Configure options: duplicate detection fields, spam filtering
6. Click **Dry Run (test)** to preview results, or **Import** to proceed

### Console Commands

```bash
# List all forms and their fields
php craft craft-formie-import/formie/list-forms

# Generate a mapping file for a form
php craft craft-formie-import/formie/generate-mapping path/to/file.csv --form=myFormHandle

# Import a CSV file
php craft craft-formie-import/formie/import-csv path/to/file.csv --form=myFormHandle

# Import with options
php craft craft-formie-import/formie/import-csv path/to/file.csv \
  --form=myFormHandle \
  --uniqueFields=email,phone \
  --skipSpam \
  --dryRun
```

### Moving submissions between environments

A Formie export round-trips cleanly when the target form has the same fields:

```bash
php craft craft-formie-import/formie/import-csv path/to/export.csv \
  --form=myFormHandle \
  --preserveMeta \
  --uniqueFields="Date Created" \
  --since=2026-09-04 \
  --filesDir=/path/to/uploaded/files
```

- `--preserveMeta` keeps the exported `Date Created`, `Date Updated`, `IP Address`, `Is Spam?`, `Spam Reason`, `Spam Type`, `Is Incomplete?` and `Status` on the created submissions instead of stamping them with the import time. Also available as the "Preserve metadata" switch in the CP.
- `--uniqueFields` accepts the metadata columns `Date Created` and `IP Address` next to field handles, so a re-run skips submissions already imported.
- `--since` ignores rows created before a date (read in the system timezone), to import only a delta.
- File upload columns hold the asset URL or file name. Each file is looked up by name in the field's upload volume; when it is not there and `--filesDir` holds a file of that name, it is uploaded to the volume's root folder. Files that cannot be resolved are reported as warnings and left out of the field. Note that a file upload field with a *filename format* renames files on save, so a file keeps its exported name only when the submission's other values are imported too.
- Sub-field columns (`Name: First Name`, `Name: Last Name`, …) each feed their own sub-field.

## CSV Format

The plugin expects a CSV exported from Formie. Meta columns (`ID`, `Form ID`, `Form Name`, `User ID`, `IP Address`, `Is Incomplete?`, `Is Spam?`, etc.) are recognized automatically and excluded from field mapping.

Data columns are matched to form fields by label or handle. Sub-fields use colon notation (e.g., `Name: First Name`).

## License

MIT

## Credits

Developed by [Side-Car](https://side-car.ca/).
