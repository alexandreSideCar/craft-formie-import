<?php

namespace sidecar\craftformieimport\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use sidecar\craftformieimport\Plugin;
use verbb\formie\Formie;
use yii\web\Response;

class FormieImportController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $forms = Formie::$plugin->getForms()->getAllForms();

        return $this->renderTemplate('craft-formie-import/formie-import/index', [
            'forms' => $forms,
        ]);
    }

    public function actionMapping(): Response
    {
        $this->requirePostRequest();

        $formHandle = $this->request->getBodyParam('formHandle');
        $isAllForms = ($formHandle === '_all');

        $csvFile = UploadedFile::getInstanceByName('csvFile');
        if (!$csvFile) {
            Craft::$app->getSession()->setError(Craft::t('craft-formie-import', 'Please upload a CSV file.'));
            return $this->redirect('craft-formie-import');
        }

        $allowedExtensions = ['csv', 'txt'];
        if (!in_array(strtolower($csvFile->getExtension()), $allowedExtensions)) {
            Craft::$app->getSession()->setError(Craft::t('craft-formie-import', 'Only CSV files are allowed.'));
            return $this->redirect('craft-formie-import');
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . '/formie-import-' . uniqid() . '.csv';
        $csvFile->saveAs($tempPath);

        $service = Plugin::$plugin->import;
        $headers = $service->readCsvHeaders($tempPath);
        $formNames = $service->getFormNamesFromCsv($tempPath);

        $fh = fopen($tempPath, 'r');
        $rowCount = -1;
        while (fgetcsv($fh) !== false) {
            $rowCount++;
        }
        fclose($fh);

        $dataColumns = array_filter($headers, fn($h) => !in_array($h, $service::META_COLUMNS));

        if ($isAllForms) {
            $allForms = Formie::$plugin->getForms()->getAllForms();
            $formsMap = [];
            $allMappings = [];
            $allFormFields = [];

            foreach ($formNames as $csvFormName) {
                foreach ($allForms as $f) {
                    if ($f->handle === $csvFormName || $f->title === $csvFormName) {
                        $formsMap[$csvFormName] = $f;
                        $fields = $f->getCustomFields();
                        $allFormFields[$f->handle] = $fields;
                        $allMappings[$f->handle] = $service->buildAutoMapping($headers, $fields);
                        break;
                    }
                }
            }

            $allFieldOptions = [];
            foreach ($allFormFields as $fields) {
                foreach ($fields as $field) {
                    $allFieldOptions[$field->handle] = $field->label . ' (' . $field->handle . ')';
                }
            }

            $combinedMapping = [];
            foreach ($allMappings as $mapping) {
                foreach ($mapping as $col => $handle) {
                    if (!isset($combinedMapping[$col]) || $combinedMapping[$col] === '') {
                        $combinedMapping[$col] = $handle;
                    }
                }
            }

            return $this->renderTemplate('craft-formie-import/formie-import/mapping', [
                'form' => null,
                'isAllForms' => true,
                'formsMap' => $formsMap,
                'allFormFields' => $allFormFields,
                'allFieldOptions' => $allFieldOptions,
                'formFields' => [],
                'headers' => $headers,
                'dataColumns' => $dataColumns,
                'autoMapping' => $combinedMapping,
                'formNames' => $formNames,
                'rowCount' => $rowCount,
                'tempPath' => $tempPath,
            ]);
        }

        $form = Formie::$plugin->getForms()->getFormByHandle($formHandle);
        if (!$form) {
            Craft::$app->getSession()->setError(Craft::t('craft-formie-import', 'Form not found.'));
            return $this->redirect('craft-formie-import');
        }

        $formFields = $form->getCustomFields();
        $autoMapping = $service->buildAutoMapping($headers, $formFields);

        return $this->renderTemplate('craft-formie-import/formie-import/mapping', [
            'form' => $form,
            'isAllForms' => false,
            'formFields' => $formFields,
            'headers' => $headers,
            'dataColumns' => $dataColumns,
            'autoMapping' => $autoMapping,
            'formNames' => $formNames,
            'rowCount' => $rowCount,
            'tempPath' => $tempPath,
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $formHandle = $this->request->getBodyParam('formHandle');
        $isAllForms = ($formHandle === '_all');
        $tempPath = $this->request->getBodyParam('tempPath');
        $uniqueFieldsParam = $this->request->getBodyParam('uniqueFields', []);
        $uniqueFields = is_array($uniqueFieldsParam) ? implode(',', $uniqueFieldsParam) : (string)$uniqueFieldsParam;
        $formNameFilter = $this->request->getBodyParam('formNameFilter', '');
        $skipSpam = (bool)$this->request->getBodyParam('skipSpam', true);
        $dryRun = (bool)$this->request->getBodyParam('dryRun', false);
        $mappingData = $this->request->getBodyParam('mapping', []);

        $baseTmpPath = realpath(Craft::$app->getPath()->getTempPath());
        if (!$tempPath || !file_exists($tempPath) || strpos(realpath($tempPath), $baseTmpPath) !== 0) {
            Craft::$app->getSession()->setError(Craft::t('craft-formie-import', 'CSV file expired. Please upload again.'));
            return $this->redirect('craft-formie-import');
        }

        $service = Plugin::$plugin->import;

        if ($isAllForms) {
            $allForms = Formie::$plugin->getForms()->getAllForms();
            $formNames = $service->getFormNamesFromCsv($tempPath);

            $formsMap = [];
            foreach ($formNames as $csvFormName) {
                foreach ($allForms as $f) {
                    if ($f->handle === $csvFormName || $f->title === $csvFormName) {
                        $formsMap[$csvFormName] = $f;
                        break;
                    }
                }
            }

            $combinedResult = [
                'imported' => 0,
                'skipped' => 0,
                'skippedSpam' => 0,
                'skippedForm' => 0,
                'errors' => 0,
                'errorMessages' => [],
                'totalRows' => 0,
                'perForm' => [],
            ];

            foreach ($formsMap as $csvFormName => $form) {
                $result = $service->importFromCsv(
                    $tempPath,
                    $form->handle,
                    $mappingData,
                    $uniqueFields,
                    $csvFormName,
                    $skipSpam,
                    $dryRun
                );

                $combinedResult['imported'] += $result['imported'];
                $combinedResult['skipped'] += $result['skipped'];
                $combinedResult['skippedSpam'] += $result['skippedSpam'];
                $combinedResult['errors'] += $result['errors'];
                $combinedResult['totalRows'] += $result['totalRows'];
                $combinedResult['errorMessages'] = array_merge($combinedResult['errorMessages'], $result['errorMessages']);
                $combinedResult['perForm'][$csvFormName] = $result;
            }

            if (!$dryRun && file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $this->renderTemplate('craft-formie-import/formie-import/results', [
                'form' => null,
                'isAllForms' => true,
                'formsMap' => $formsMap,
                'result' => $combinedResult,
                'dryRun' => $dryRun,
                'tempPath' => $tempPath,
                'formHandle' => $formHandle,
                'uniqueFields' => $uniqueFields,
                'formNameFilter' => $formNameFilter,
                'skipSpam' => $skipSpam,
                'mapping' => $mappingData,
            ]);
        }

        $form = Formie::$plugin->getForms()->getFormByHandle($formHandle);
        if (!$form) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            Craft::$app->getSession()->setError(Craft::t('craft-formie-import', 'Form not found.'));
            return $this->redirect('craft-formie-import');
        }

        $result = $service->importFromCsv(
            $tempPath,
            $formHandle,
            $mappingData,
            $uniqueFields,
            $formNameFilter,
            $skipSpam,
            $dryRun
        );

        if (!$dryRun && file_exists($tempPath)) {
            unlink($tempPath);
        }

        return $this->renderTemplate('craft-formie-import/formie-import/results', [
            'form' => $form,
            'isAllForms' => false,
            'result' => $result,
            'dryRun' => $dryRun,
            'tempPath' => $tempPath,
            'formHandle' => $formHandle,
            'uniqueFields' => $uniqueFields,
            'formNameFilter' => $formNameFilter,
            'skipSpam' => $skipSpam,
            'mapping' => $mappingData,
        ]);
    }
}
