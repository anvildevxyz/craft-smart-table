<?php

namespace anvildevxyz\smarttable\assetbundles;

use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SmartTableFieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildevxyz/smarttable/web';

        $this->depends = [
            CpAsset::class,
            CkeditorAsset::class,
        ];

        $this->js = [
            'js/smart-table-field.js',
        ];

        $this->css = [
            'css/smart-table-field.css',
        ];

        parent::init();
    }
}
