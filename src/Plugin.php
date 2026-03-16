<?php

namespace sidecar\craftformieimport;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use sidecar\craftformieimport\services\FormieImportService;
use yii\base\Event;

/**
 * @property-read FormieImportService $import
 * @method static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSection = true;
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'import' => ['class' => FormieImportService::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Console controllers
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'sidecar\\craftformieimport\\console\\controllers';
        }

        $this->registerCpRoutes();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('craft-formie-import', 'Formie Import');
        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['craft-formie-import'] = 'craft-formie-import/formie-import/index';
                $event->rules['craft-formie-import/mapping'] = 'craft-formie-import/formie-import/mapping';
                $event->rules['craft-formie-import/run'] = 'craft-formie-import/formie-import/run';
            }
        );
    }
}
