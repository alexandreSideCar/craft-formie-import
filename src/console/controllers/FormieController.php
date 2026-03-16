<?php

namespace sidecar\craftformieimport\console\controllers;

use Craft;
use craft\console\Controller;
use sidecar\craftformieimport\Plugin;
use verbb\formie\Formie;
use yii\console\ExitCode;

class FormieController extends Controller
{
    public string $form = '';
    public string $uniqueFields = '';
    public string $delimiter = ',';
    public string $formNameFilter = '';
    public bool $skipSpam = true;
    public string $mapping = '';
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'form', 'uniqueFields', 'delimiter', 'formNameFilter', 'skipSpam', 'mapping', 'dryRun',
        ]);
    }

    public function actionListForms(): int
    {
        $forms = Formie::$plugin->getForms()->getAllForms();

        if (empty($forms)) {
            $this->stdout("No forms found.\n");
            return ExitCode::OK;
        }

        foreach ($forms as $form) {
            $this->stdout("\n--- {$form->title} (handle: {$form->handle}) ---\n");
            foreach ($form->getCustomFields() as $field) {
                $type = (new \ReflectionClass($field))->getShortName();
                $this->stdout("  {$field->handle} ({$type}) — \"{$field->label}\"\n");
            }
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    public function actionGenerateMapping(string $file): int
    {
        if (!$this->form) {
            $this->stderr("--form is required.\n");
            return ExitCode::USAGE;
        }

        $form = Formie::$plugin->getForms()->getFormByHandle($this->form);
        if (!$form) {
            $this->stderr("Form \"{$this->form}\" not found.\n");
            return ExitCode::DATAERR;
        }

        if (!file_exists($file)) {
            $this->stderr("File not found: {$file}\n");
            return ExitCode::DATAERR;
        }

        $service = Plugin::getInstance()->import;
        $headers = $service->readCsvHeaders($file, $this->delimiter);

        if (!$headers) {
            $this->stderr("Cannot read CSV headers.\n");
            return ExitCode::DATAERR;
        }

        $fields = $form->getCustomFields();
        $validHandles = array_map(fn($f) => $f->handle, $fields);
        $mapping = $service->buildAutoMapping($headers, $fields);

        foreach ($headers as $col) {
            if (in_array($col, $service::META_COLUMNS)) {
                continue;
            }
            if (!isset($mapping[$col])) {
                $mapping[$col] = '';
            }
        }

        $outputFile = pathinfo($file, PATHINFO_DIRNAME) . '/mapping-' . $this->form . '.json';
        file_put_contents($outputFile, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->stdout("Mapping saved to: {$outputFile}\n\n");
        $autoMapped = 0;
        foreach ($mapping as $col => $handle) {
            if ($handle) {
                $this->stdout("  \"{$col}\" → {$handle} (auto)\n");
                $autoMapped++;
            } else {
                $this->stdout("  \"{$col}\" → ??? (needs mapping)\n");
            }
        }
        $this->stdout("\nAuto-mapped: {$autoMapped}/" . count($mapping) . "\n");
        $this->stdout("Form fields: " . implode(', ', $validHandles) . "\n");

        return ExitCode::OK;
    }

    public function actionImportCsv(string $file): int
    {
        if (!$this->form) {
            $this->stderr("--form is required.\n");
            return ExitCode::USAGE;
        }

        if (!file_exists($file)) {
            $this->stderr("File not found: {$file}\n");
            return ExitCode::DATAERR;
        }

        $form = Formie::$plugin->getForms()->getFormByHandle($this->form);
        if (!$form) {
            $this->stderr("Form \"{$this->form}\" not found.\n");
            return ExitCode::DATAERR;
        }

        $service = Plugin::getInstance()->import;

        $columnMapping = [];
        if ($this->mapping) {
            if (!file_exists($this->mapping)) {
                $this->stderr("Mapping file not found: {$this->mapping}\n");
                return ExitCode::DATAERR;
            }
            $columnMapping = json_decode(file_get_contents($this->mapping), true);
            if (!is_array($columnMapping)) {
                $this->stderr("Invalid mapping JSON.\n");
                return ExitCode::DATAERR;
            }
        } else {
            $headers = $service->readCsvHeaders($file, $this->delimiter);
            $columnMapping = $service->buildAutoMapping($headers, $form->getCustomFields());
            $this->stdout("Auto-mapped " . count($columnMapping) . " columns:\n");
            foreach ($columnMapping as $csvCol => $handle) {
                $this->stdout("  \"{$csvCol}\" → {$handle}\n");
            }
        }

        if ($this->dryRun) {
            $this->stdout("\n*** DRY RUN ***\n");
        }

        $result = $service->importFromCsv(
            $file,
            $this->form,
            $columnMapping,
            $this->uniqueFields,
            $this->formNameFilter,
            $this->skipSpam,
            $this->dryRun,
            $this->delimiter
        );

        $this->stdout("\n--- Results ---\n");
        $this->stdout("Imported: {$result['imported']}\n");
        $this->stdout("Skipped (duplicates): {$result['skipped']}\n");
        if ($result['skippedSpam'] > 0) {
            $this->stdout("Skipped (spam): {$result['skippedSpam']}\n");
        }
        if ($result['skippedForm'] > 0) {
            $this->stdout("Skipped (other forms): {$result['skippedForm']}\n");
        }
        if ($result['errors'] > 0) {
            $this->stdout("Errors: {$result['errors']}\n");
            foreach ($result['errorMessages'] as $msg) {
                $this->stderr("  {$msg}\n");
            }
        }

        return ExitCode::OK;
    }
}
