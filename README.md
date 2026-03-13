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
php craft formie/list-forms

# Generate a mapping file for a form
php craft formie/generate-mapping path/to/file.csv --form=myFormHandle

# Import a CSV file
php craft formie/import-csv path/to/file.csv --form=myFormHandle

# Import with options
php craft formie/import-csv path/to/file.csv \
  --form=myFormHandle \
  --uniqueFields=email,phone \
  --skipSpam \
  --dryRun
```

## CSV Format

The plugin expects a CSV exported from Formie. Meta columns (`ID`, `Form ID`, `Form Name`, `User ID`, `IP Address`, `Is Incomplete?`, `Is Spam?`, etc.) are recognized automatically and excluded from field mapping.

Data columns are matched to form fields by label or handle. Sub-fields use colon notation (e.g., `Name: First Name`).

## License

MIT

## Credits

Developed by [Side-Car](https://side-car.ca/).
