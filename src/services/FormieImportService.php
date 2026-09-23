<?php

namespace sidecar\craftformieimport\services;

use Craft;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use Throwable;
use verbb\formie\base\FieldInterface;
use verbb\formie\base\SingleNestedField;
use verbb\formie\elements\Submission;
use verbb\formie\fields\FileUpload;
use verbb\formie\Formie;
use yii\base\Component;

/**
 * Imports Formie submissions from a CSV exported by Formie (Submissions → Export).
 *
 * Besides the field columns, the export carries submission metadata (ID, IP,
 * spam flags, status, dates). With `preserveMeta` on, that metadata is written
 * back onto the created submissions so a CSV round trip keeps the original
 * dates, IPs and statuses — which is what makes the plugin usable to move
 * submissions between environments. File upload columns hold asset URLs; they
 * are resolved back to assets by file name in the field's upload volume, or
 * uploaded from `filesDir` when the file is not there yet.
 */
class FormieImportService extends Component
{
    // Constants
    // =========================================================================

    /**
     * Hard cap on rows processed per import to bound a synchronous request.
     * Larger files should use the console command.
     */
    public const MAX_ROWS = 50000;

    /**
     * Columns of a Formie export that are submission metadata, not field values.
     */
    public const META_COLUMNS = [
        'ID', 'Form ID', 'Form Name', 'User ID', 'IP Address',
        'Is Incomplete?', 'Is Spam?', 'Spam Reason', 'Spam Type', 'Title',
        'Date Created', 'Date Updated', 'Date Deleted', 'Trashed', 'Status',
    ];

    /**
     * Metadata columns accepted as anti-duplicate keys, next to field handles.
     * Combined with `since`, they make an import safe to re-run for a delta.
     */
    public const META_UNIQUE_FIELDS = ['Date Created', 'IP Address'];

    // Public Methods
    // =========================================================================

    /**
     * @return string[]
     */
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

    /**
     * Imports the rows of a Formie CSV export into one form.
     *
     * @param string $filePath CSV file path
     * @param string $formHandle Target form handle
     * @param array $columnMapping CSV column → field handle
     * @param string $uniqueFields Comma-separated field handles and/or META_UNIQUE_FIELDS; a row matching an existing submission on all of them is skipped
     * @param string $formNameFilter Only import rows whose "Form Name" equals this (multi-form CSV)
     * @param bool $skipSpam Skip rows flagged "Is Spam?"
     * @param bool $dryRun Count without saving
     * @param string $delimiter CSV delimiter
     * @param bool $preserveMeta Keep the exported dates, IP, spam flags and status on the created submissions
     * @param string $since Skip rows whose "Date Created" is before this date (any format DateTimeHelper understands)
     * @param string $filesDir Folder holding the uploaded files, used when a file upload asset is not in the volume yet
     * @return array{imported:int,skipped:int,skippedSpam:int,skippedForm:int,skippedSince:int,errors:int,errorMessages:string[],warnings:string[],totalRows:int}
     */
    public function importFromCsv(
        string $filePath,
        string $formHandle,
        array $columnMapping,
        string $uniqueFields = '',
        string $formNameFilter = '',
        bool $skipSpam = true,
        bool $dryRun = false,
        string $delimiter = ',',
        bool $preserveMeta = false,
        string $since = '',
        string $filesDir = ''
    ): array {
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'skippedSpam' => 0,
            'skippedForm' => 0,
            'skippedSince' => 0,
            'errors' => 0,
            'errorMessages' => [],
            'warnings' => [],
            'totalRows' => 0,
        ];

        $form = Formie::$plugin->getForms()->getFormByHandle($formHandle);
        if (!$form) {
            $result['errorMessages'][] = "Form \"{$formHandle}\" not found.";
            return $result;
        }

        $sinceDate = null;
        if ($since !== '') {
            $sinceDate = $this->_parseDate($since, true);
            if ($sinceDate === null) {
                $result['errorMessages'][] = "Invalid --since date \"{$since}\".";
                return $result;
            }
        }

        if ($filesDir !== '' && !is_dir($filesDir)) {
            $result['errorMessages'][] = "Files directory not found: {$filesDir}";
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
        $fieldsByHandle = [];
        foreach ($formFields as $field) {
            $fieldsByHandle[$field->handle] = $field;
        }

        $columnMapping = array_filter($columnMapping, fn($v) => $v !== '' && $v !== null);

        $uniqueFieldsArray = [];
        if ($uniqueFields !== '') {
            $requested = array_map('trim', explode(',', $uniqueFields));
            foreach ($requested as $uf) {
                if (in_array($uf, $validHandles, true) || in_array($uf, self::META_UNIQUE_FIELDS, true)) {
                    $uniqueFieldsArray[] = $uf;
                }
            }
        }

        $existingKeys = [];
        if (!empty($uniqueFieldsArray)) {
            foreach (Submission::find()->form($formHandle)->batch(100) as $batch) {
                foreach ($batch as $sub) {
                    $subValues = [];
                    foreach ($uniqueFieldsArray as $uf) {
                        $subValues[$uf] = $this->_existingUniqueValue($sub, $uf);
                    }
                    $key = $this->buildDuplicateKey($subValues, $uniqueFieldsArray);
                    if ($key !== null) {
                        $existingKeys[$key] = true;
                    }
                }
            }
        }

        $row = 1;
        while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
            $row++;

            if ($result['totalRows'] >= self::MAX_ROWS) {
                $result['errorMessages'][] = "Row limit reached (" . self::MAX_ROWS . "). Remaining rows skipped — use the console command for larger files.";
                break;
            }

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

            $dateCreated = $this->_parseDate($rowData['Date Created'] ?? null);

            if ($sinceDate !== null && $dateCreated !== null && $dateCreated < $sinceDate) {
                $result['skippedSince']++;
                continue;
            }

            $fieldValues = [];
            foreach ($rowData as $csvCol => $value) {
                $fieldHandle = $columnMapping[$csvCol] ?? $csvCol;
                if (!in_array($fieldHandle, $validHandles, true) || $value === '') {
                    continue;
                }

                // "Name: First Name" columns feed one sub-field of a nested field
                $subHandle = $this->_subFieldHandle($fieldsByHandle[$fieldHandle], $csvCol);
                if ($subHandle !== null) {
                    if (!is_array($fieldValues[$fieldHandle] ?? null)) {
                        $fieldValues[$fieldHandle] = [];
                    }
                    $fieldValues[$fieldHandle][$subHandle] = trim($value);
                    continue;
                }

                $fieldValues[$fieldHandle] = trim($value);
            }

            $uniqueValues = $this->_rowUniqueValues($fieldValues, $rowData, $dateCreated, $uniqueFieldsArray);

            if (!empty($uniqueFieldsArray)) {
                $key = $this->buildDuplicateKey($uniqueValues, $uniqueFieldsArray);
                if ($key !== null && isset($existingKeys[$key])) {
                    $result['skipped']++;
                    continue;
                }
            }

            if ($dryRun) {
                if (!empty($uniqueFieldsArray)) {
                    $key = $this->buildDuplicateKey($uniqueValues, $uniqueFieldsArray);
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
                $field = $fieldsByHandle[$handle] ?? null;

                if ($field instanceof FileUpload) {
                    $assetIds = $this->_resolveFileUploadValue($field, $value, $filesDir, $row, $result['warnings']);
                    if (!empty($assetIds)) {
                        $submission->setFieldValue($handle, $assetIds);
                    }
                    continue;
                }

                $submission->setFieldValue($handle, $value);
            }

            if ($preserveMeta) {
                $this->_applyMeta($submission, $rowData, $dateCreated);
            }

            if (!Craft::$app->getElements()->saveElement($submission)) {
                $result['errors']++;
                $result['errorMessages'][] = "Row {$row}: " . json_encode($submission->getErrors());
                continue;
            }

            if ($preserveMeta) {
                $this->_syncDates($submission, $dateCreated, $this->_parseDate($rowData['Date Updated'] ?? null));
            }

            if (!empty($uniqueFieldsArray)) {
                $key = $this->buildDuplicateKey($uniqueValues, $uniqueFieldsArray);
                if ($key !== null) {
                    $existingKeys[$key] = true;
                }
            }

            $result['imported']++;
        }

        fclose($fh);
        return $result;
    }

    // Private Methods
    // =========================================================================

    private function buildDuplicateKey(array $fieldValues, array $uniqueFields): ?string
    {
        $parts = [];
        foreach ($uniqueFields as $handle) {
            $val = $fieldValues[$handle] ?? '';
            if (is_array($val)) {
                $val = implode(' ', array_filter(array_map('trim', $val), fn($v) => $v !== ''));
            }
            $parts[] = mb_strtolower(trim((string)$val));
        }

        $nonEmpty = array_filter($parts, fn($p) => $p !== '');
        if (empty($nonEmpty)) {
            return null;
        }

        return implode('||', $parts);
    }

    /**
     * Value of one anti-duplicate key on an existing submission.
     */
    private function _existingUniqueValue(Submission $submission, string $uniqueField): string
    {
        if ($uniqueField === 'Date Created') {
            return $submission->dateCreated ? (string)$submission->dateCreated->getTimestamp() : '';
        }

        if ($uniqueField === 'IP Address') {
            return (string)($submission->ipAddress ?? '');
        }

        $val = $submission->getFieldValue($uniqueField);

        return ($val !== null) ? (string)$val : '';
    }

    /**
     * Values of the anti-duplicate keys for a CSV row, field values and metadata alike.
     */
    private function _rowUniqueValues(array $fieldValues, array $rowData, ?DateTime $dateCreated, array $uniqueFields): array
    {
        $values = $fieldValues;

        foreach ($uniqueFields as $uniqueField) {
            if ($uniqueField === 'Date Created') {
                $values[$uniqueField] = $dateCreated ? (string)$dateCreated->getTimestamp() : '';
            } elseif ($uniqueField === 'IP Address') {
                $values[$uniqueField] = trim((string)($rowData['IP Address'] ?? ''));
            }
        }

        return $values;
    }

    /**
     * Copies the exported metadata onto a new submission before it is saved.
     */
    private function _applyMeta(Submission $submission, array $rowData, ?DateTime $dateCreated): void
    {
        if ($dateCreated !== null) {
            $submission->dateCreated = $dateCreated;
        }

        $ipAddress = trim((string)($rowData['IP Address'] ?? ''));
        if ($ipAddress !== '') {
            $submission->ipAddress = $ipAddress;
        }

        $submission->isIncomplete = ($rowData['Is Incomplete?'] ?? '') === '1';
        $submission->isSpam = ($rowData['Is Spam?'] ?? '') === '1';

        $spamReason = trim((string)($rowData['Spam Reason'] ?? ''));
        $submission->spamReason = $spamReason !== '' ? $spamReason : null;

        $spamClass = trim((string)($rowData['Spam Type'] ?? ''));
        $submission->spamClass = $spamClass !== '' ? $spamClass : null;

        $statusValue = trim((string)($rowData['Status'] ?? ''));
        if ($statusValue !== '') {
            $status = Formie::$plugin->getStatuses()->getStatusByHandle($statusValue);

            if ($status === null) {
                foreach (Formie::$plugin->getStatuses()->getAllStatuses() as $candidate) {
                    if (mb_strtolower($candidate->name) === mb_strtolower($statusValue)) {
                        $status = $candidate;
                        break;
                    }
                }
            }

            if ($status !== null) {
                $submission->setStatus($status);
            }
        }
    }

    /**
     * Re-applies the exported dates after save: Craft keeps `dateCreated` on the
     * element, but Formie's own submission record and both `dateUpdated` columns
     * are stamped with "now" during the save.
     */
    private function _syncDates(Submission $submission, ?DateTime $dateCreated, ?DateTime $dateUpdated): void
    {
        $columns = [];

        if ($dateCreated !== null) {
            $columns['dateCreated'] = Db::prepareDateForDb($dateCreated);
        }

        if ($dateUpdated !== null) {
            $columns['dateUpdated'] = Db::prepareDateForDb($dateUpdated);
        }

        if (empty($columns)) {
            return;
        }

        Db::update(Table::ELEMENTS, $columns, ['id' => $submission->id]);
        Db::update('{{%formie_submissions}}', $columns, ['id' => $submission->id]);
    }

    /**
     * Turns the exported value of a file upload field (asset URLs or file names,
     * comma-separated) back into asset IDs.
     *
     * Each file is looked up by name in the field's upload volume. When it is not
     * there and `filesDir` holds a file of that name, it is uploaded to the volume's
     * root folder. Files that cannot be resolved are reported as warnings and the
     * field is left without them.
     *
     * @param string[] $warnings
     * @return int[]
     */
    private function _resolveFileUploadValue(FileUpload $field, string $value, string $filesDir, int $row, array &$warnings): array
    {
        $volume = null;
        $source = (string)$field->uploadLocationSource;

        if (str_starts_with($source, 'volume:') || str_starts_with($source, 'folder:')) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid(explode(':', $source)[1]);
        }

        if ($volume === null) {
            $warnings[] = "Row {$row}: field \"{$field->handle}\" has no upload volume, files skipped.";
            return [];
        }

        $assetIds = [];

        foreach (array_filter(array_map('trim', explode(',', $value))) as $item) {
            $path = parse_url($item, PHP_URL_PATH) ?: $item;
            $filename = urldecode(basename($path));

            $asset = Asset::find()
                ->volumeId($volume->id)
                ->filename([$filename, AssetsHelper::prepareAssetName($filename)])
                ->one();

            if ($asset === null && $filesDir !== '') {
                $asset = $this->_uploadFile($volume->id, rtrim($filesDir, '/') . '/' . $filename, $filename, $row, $warnings);
            }

            if ($asset === null) {
                $warnings[] = "Row {$row}: file \"{$filename}\" not found in volume \"{$volume->handle}\"" . ($filesDir !== '' ? " nor in {$filesDir}" : '') . ", left out of \"{$field->handle}\".";
                continue;
            }

            $assetIds[] = $asset->id;
        }

        return $assetIds;
    }

    /**
     * Uploads a local file to the root folder of a volume.
     *
     * @param string[] $warnings
     */
    private function _uploadFile(int $volumeId, string $sourcePath, string $filename, int $row, array &$warnings): ?Asset
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volumeId);
        if ($folder === null) {
            $warnings[] = "Row {$row}: no root folder for volume #{$volumeId}, \"{$filename}\" not uploaded.";
            return null;
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('formie-import-', true) . '.' . pathinfo($filename, PATHINFO_EXTENSION);

        if (!copy($sourcePath, $tempPath)) {
            $warnings[] = "Row {$row}: cannot read \"{$sourcePath}\".";
            return null;
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->filename = AssetsHelper::prepareAssetName($filename);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volumeId);
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        try {
            if (!Craft::$app->getElements()->saveElement($asset)) {
                $warnings[] = "Row {$row}: upload of \"{$filename}\" failed: " . json_encode($asset->getErrors());
                return null;
            }
        } catch (Throwable $e) {
            $warnings[] = "Row {$row}: upload of \"{$filename}\" failed: " . $e->getMessage();
            return null;
        }

        return $asset;
    }

    /**
     * Resolves the sub-field a CSV column feeds, for nested fields such as Name
     * (multiple fields) or Address. Formie exports them as "Parent label: Sub label";
     * the sub label is matched against the sub-fields' labels and handles,
     * case- and accent-insensitively.
     */
    private function _subFieldHandle(FieldInterface $field, string $csvColumn): ?string
    {
        if (!$field instanceof SingleNestedField || !str_contains($csvColumn, ':')) {
            return null;
        }

        $subLabel = $this->normalizeString(substr($csvColumn, strrpos($csvColumn, ':') + 1));

        foreach ($field->getFields() as $subField) {
            if ($this->normalizeString((string)$subField->label) === $subLabel) {
                return $subField->handle;
            }
            if ($this->normalizeString((string)$subField->handle) === $subLabel) {
                return $subField->handle;
            }
        }

        return null;
    }

    /**
     * Parses a date: ISO 8601 with offset as exported by Formie, or anything DateTimeHelper
     * accepts. A value without timezone is read in the system timezone when
     * `$assumeSystemTimeZone` is set (user-typed dates), in UTC otherwise.
     */
    private function _parseDate(?string $value, bool $assumeSystemTimeZone = false): ?DateTime
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, $assumeSystemTimeZone);

        return $date instanceof DateTime ? $date : null;
    }

    private function normalizeString(string $str): string
    {
        $str = \Normalizer::normalize($str, \Normalizer::FORM_D);
        $str = preg_replace('/[\x{0300}-\x{036f}]/u', '', $str);
        return mb_strtolower(trim($str));
    }
}
