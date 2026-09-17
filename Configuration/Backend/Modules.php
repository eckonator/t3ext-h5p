<?php

use MichielRoos\H5p\Controller\H5pModuleController;

return [
    'web_H5pManager' => [
        'parent'         => 'web',
        'position'       => ['after' => 'web_info'],
        'access'         => 'user,group',
        'workspaces'     => 'live',
        'path'           => '/module/web/h5p',
        'iconIdentifier' => 'h5p-logo',
        'labels'         => 'LLL:EXT:h5p/Resources/Private/Language/BackendModule.xlf',
        'extensionName'  => 'H5p',

        'controllerActions' => [
            // Ohne Eintrag hier erzeugt f:uri.action() eine LEERE URL - die Aktion
            // existiert fuer das Backend-Modul schlicht nicht.
            H5pModuleController::class => [
                'content', 'index', 'new', 'edit', 'create', 'libraries', 'show', 'update',
                'consent', 'error',
                'deleteContent', 'deleteContentConfirm', 'deleteLibrary', 'deleteLibraryConfirm',
            ],
        ],
//        'routes'         => [
//            '_default' => [
//                'target' => H5pModuleController::class . '::contentAction',
//            ],
//            'index'    => [
//                'target' => H5pModuleController::class . '::indexAction',
//            ],
//        ],
    ],
];
