<?php

namespace anvildevxyz\smarttable;

use anvildevxyz\smarttable\fields\SmartTableField;
use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Fields;
use craft\web\View;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public function init(): void
    {
        parent::init();

        // parent::init() already resolves controllerNamespace correctly for
        // both web (\controllers) and console (\console\controllers)
        // requests, as long as the subclass hasn't already set one — so no
        // explicit assignment here.

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SmartTableField::class;
            }
        );

        // Makes `{% from "smart-table/_macros.twig" import table %}` work
        // from any site template without the consuming project having to
        // copy the macro file into its own templates/ directory first.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['smart-table'] = __DIR__ . '/templates';
            }
        );
    }

    public static function displayName(): string
    {
        return Craft::t('smart-table', 'Smart Table');
    }
}
