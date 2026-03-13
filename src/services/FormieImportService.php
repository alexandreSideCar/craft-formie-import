<?php

namespace sidecar\craftformieimport\services;

use Craft;
use verbb\formie\Formie;
use verbb\formie\elements\Submission;
use yii\base\Component;

class FormieImportService extends Component
{
    public const META_COLUMNS = [
        'ID', 'Form ID', 'Form Name', 'User ID', 'IP Address',
        'Is Incomplete?', 'Is Spam?', 'Spam Reason', 'Spam Type', 'Title',
        'Date Created', 'Date Updated', 'Date Deleted', 'Trashed', 'Status',
    ];

    public function readCsvHeaders(string $filePath, string $delimiter = ','): array
    {
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return [];
        }
        $headers = fgetcsv($fh, 0, $delimiter);
        fclose($fh);

        if (!$headers) {
            return [];
        }

        return array_map(fn($h) => trim($h, "\xEF\xBB\xBF \t\n\r"), $headers);
    }

    public function getFormNamesFromCsv(string $filePath, string $delimiter = ','): array
    {
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return [];
        }

        $headers = fgetcsv($fh, 0, $delimiter);
        if (!$headers) {
            fclose($fh);
            return [];
        }

        $headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF \t\n\r"), $headers);
        $formNameIdx = array_search('Form Name', $headers);

        if ($formNameIdx === false) {
            fclose($fh);
            return [];
        }

        $formNames = [];
        while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
            if (isset($data[$formNameIdx]) && $data[$formNameIdx] !== '') {
                $formNames[$data[$formNameIdx]] = true;
            }
        }
        fclose($fh);

        return array_keys($formNames);
    }

    public function buildAutoMapping(array $csvColumns, array $formFields): array
    {
        $mapping = [];

        $labelToHandle = [];
        $subFieldMap = [];
        foreach ($formFields as $field) {
            $labelToHandle[mb_strtolower(trim($field->label))] = $field->handle;
            $labelToHandle[mb_strtolower(trim($field->handle))] = $field->handle;

            if ($field instanceof \verbb\formie\fields\Name) {
                $subFieldMap[mb_strtolower(trim($field->label))] = $field->handle;
            }
        }

        foreach ($csvColumns as $col) {
            if (in_array($col, self::META_COLUMNS)) {
                continue;
            }

            $colLower = mb_strtolower(trim($col));

            if (isset($labelToHandle[$colLower])) {
                $mapping[$col] = $labelToHandle[$colLower];
                continue;
            }

            if (str_contains($col, ':')) {
                $parts = explode(':', $col, 2);
                $parentLabel = mb_strtolower(trim($parts[0]));
                if (isset($subFieldMap[$parentLabel]) || isset($labelToHandle[$parentLabel])) {
                    $handle = $subFieldMap[$parentLabel] ?? $labelToHandle[$parentLabel];
                    $mapping[$col] = $handle;
                    continue;
                }
            }

            $colNormalized = $this->normalizeString($colLower);
            foreach ($formFields as $field) {
                $nameNormalized = $this->normalizeString(mb_strtolower(trim($field->label)));
                if ($colNormalized === $nameNormalized) {
                    $mapping[$col] = $field->handle;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function buildDuplicateKey(array $fieldValues, array $uniqueFields): ?string
    {
        $parts = [];
        foreach ($uniqueFields as $handle) {
            $val = $fieldValues[$handle] ?? '';
            $parts[] = mb_strtolower(trim((string)$val));
        }

        $nonEmpty = array_filter($parts, fn($p) => $p !== '');
        if (empty($nonEmpty)) {
            return null;
        }

        return implode('||', $parts);
    }

    public function importFromCsv(
        string $filePath,
        string $formHandle,
        array $columnMapping,
        string $uniqueFields = '',
        string $formNameFilter = '',
        bool $skipSpam = true,
        bool $dryRun = false,
        string $delimiter = ','
    ): array {
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'skippedSpam' => 0,
            'skippedForm' => 0,
            'errors' => 0,
            'errorMessages' => [],
            'totalRows' => 0,
        ];

        $form = Formie::$plugin->getForms()->getFormByHandle($formHandle);
        if (!$form) {
            $result['errorMessages'][] = "Form \"{$formHandle}\" not found.";
            return $result;
        }

        $fh = fopen($filePath, 'r');
        if (!$fh) {
            $result['errorMessages'][] = "Cannot open file.";
            return $result;
        }

        $headers = fgetcsv($fh, 0, $delimiter);
        if (!$headers) {
            fclose($fh);
            $result['errorMessages'][] = "CSV is empty or invalid.";
            return $result;
        }

        $headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF \t\n\r"), $headers);
        $formFields = $form->getCustomFields();
        $validHandles = array_map(fn($f) => $f->handle, $formFields);

        $columnMapping = array_filter($columnMapping, fn($v) => $v !== '' && $v !== null);

        $uniqueFieldsArray = [];
        if ($uniqueFields !== '') {
            $requested = array_map('trim', explode(',', $uniqueFields));
            foreach ($requested as $uf) {
                if (in_array($uf, $validHandles)) {
                    $uniqueFieldsArray[] = $uf;
                }
            }
        }

        $existingKeys = [];
        if (!empty($uniqueFieldsArray)) {
            $existing = Submission::find()->form($formHandle)->all();
            foreach ($existing as $sub) {
                $subValues = [];
                foreach ($uniqueFieldsArray as $uf) {
                    $val = $sub->getFieldValue($uf);
                    $subValues[$uf] = ($val !== null) ? (string)$val : '';
                }
                $key = $this->buildDuplicateKey($subValues, $uniqueFieldsArray);
                if ($key !== null) {
                    $existingKeys[$key] = true;
                }
            }
        }

        $row = 1;
        while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
            $row++;
            $result['totalRows']++;

            if (count($data) !== count($headers)) {
                $result['errors']++;
                $result['errorMessages'][] = "Row {$row}: column count mismatch.";
                continue;
            }

            $rowData = array_combine($headers, $data);

            if ($formNameFilter && isset($rowData['Form Name'])) {
                if ($rowData['Form Name'] !== $formNameFilter) {
                    $result['skippedForm']++;
                    continue;
                }
            }

            if ($skipSpam && isset($rowData['Is Spam?']) && $rowData['Is Spam?'] === '1') {
                $result['skippedSpam']++;
                continue;
            }

            $fieldValues = [];
            foreach ($rowData as $csvCol => $value) {
                $fieldHandle = $columnMapping[$csvCol] ?? $csvCol;
                if (in_array($fieldHandle, $validHandles) && $value !== '') {
                    $fieldValues[$fieldHandle] = trim($value);
                }
            }

            if (!empty($uniqueFieldsArray)) {
                $key = $this->buildDuplicateKey($fieldValues, $uniqueFieldsArray);
                if ($key !== null && isset($existingKeys[$key])) {
                    $result['skipped']++;
                    continue;
                }
            }

            if ($dryRun) {
                if (!empty($uniqueFieldsArray)) {
                    $key = $this->buildDuplicateKey($fieldValues, $uniqueFieldsArray);
                    if ($key !== null) {
                        $existingKeys[$key] = true;
                    }
                }
                $result['imported']++;
                continue;
            }

            $submission = new Submission();
            $submission->setForm($form);
            $submission->title = $rowData['Title'] ?? date('Y-m-d H:i:s');

            foreach ($fieldValues as $handle => $value) {
                $submission->setFieldValue($handle, $value);
            }

            if (!Craft::$app->getElements()->saveElement($submission)) {
                $result['errors']++;
                $result['errorMessages'][] = "Row {$row}: " . json_encode($submission->getErrors());
                continue;
            }

            if (!empty($uniqueFieldsArray)) {
                $key = $this->buildDuplicateKey($fieldValues, $uniqueFieldsArray);
                if ($key !== null) {
                    $existingKeys[$key] = true;
                }
            }

            $result['imported']++;
        }

        fclose($fh);
        return $result;
    }

    private function normalizeString(string $str): string
    {
        $str = \Normalizer::normalize($str, \Normalizer::FORM_D);
        $str = preg_replace('/[\x{0300}-\x{036f}]/u', '', $str);
        return mb_strtolower(trim($str));
    }
}
