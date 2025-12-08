<?php

use MichielRoos\H5p\Controller\AjaxController;
use MichielRoos\H5p\Controller\ViewController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die('¯\_(ツ)_/¯');

ExtensionUtility::configurePlugin(
    'h5p',
    'view',
    [
        ViewController::class => 'index',
    ],
    [
        ViewController::class => 'index',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

ExtensionUtility::configurePlugin(
    'h5p',
    'statistics',
    [
        ViewController::class => 'statistics',
    ],
    [
        ViewController::class => 'statistics',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

ExtensionUtility::configurePlugin(
    'h5p',
    'ajax',
    [
        AjaxController::class => 'index,finish,contentUserData',
    ],
    [
        AjaxController::class => 'index,finish,contentUserData',
    ]
);

call_user_func(
    function ($extKey) {
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
        if (!isset($extConf['onlyAllowRecordsInSysfolders']) || (int)$extConf['onlyAllowRecordsInSysfolders'] === 0) {
            $GLOBALS['TCA']['tx_h5p_domain_model_content']['ctrl']['security']['ignorePageTypeRestriction'] = true;
        }
    },
    'h5p'
);
