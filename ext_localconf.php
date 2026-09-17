<?php

use MichielRoos\H5p\Controller\AjaxController;
use MichielRoos\H5p\Controller\ViewController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
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

// Eigenstaendige Ausgabe fuer den Einbetten-Knopf. Das Plugin wird nicht als
// Inhaltselement angeboten, sondern nur ueber den Seitentyp unten aufgerufen.
ExtensionUtility::configurePlugin(
    'h5p',
    'embedded',
    [
        ViewController::class => 'embedded',
    ],
    [
        ViewController::class => 'embedded',
    ],
    ExtensionUtility::PLUGIN_TYPE_PLUGIN
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

// Seitentyp fuer die eingebettete Ausgabe.
//
// Der Einbetten-Knopf gibt einen <iframe>-Schnipsel heraus, der auf genau diese
// Adresse zeigt. Sie muss ohne Kopf, Navigation und Fuss ausliefern - deshalb ein
// eigener typeNum mit eigenem PAGE-Objekt statt der normalen Seitenausgabe.
//
// Bewusst ueber addTypoScriptSetup() und nicht ueber ein statisches Template:
// Sonst funktioniert der Einbetten-Knopf erst, wenn ein Integrator daran denkt,
// das Template einzubinden - und genau das war hier nie geschehen.
ExtensionManagementUtility::addTypoScriptSetup(<<<'TYPOSCRIPT'
h5pEmbedded = PAGE
h5pEmbedded {
    typeNum = 723442

    config {
        disableAllHeaderCode = 0
        admPanel = 0
        debug = 0
        disablePrefixComment = 1
        metaCharset = utf-8
        # Der Schnipsel wird auf fremden Seiten eingebettet; absolute Pfade
        # verhindern, dass Assets relativ zur einbettenden Seite gesucht werden.
        absRefPrefix = auto
    }

    10 =< tt_content.list.20.h5p_embedded

    bodyTag = <body class="h5p-embedded">
}
TYPOSCRIPT);
