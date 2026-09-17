<?php

namespace MichielRoos\H5p\Controller;


use Exception;
use InvalidArgumentException;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Exception\StopActionException;
use TYPO3\CMS\Extbase\Mvc\Exception\NoSuchArgumentException;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use H5P_Plugin;
use H5PContentValidator;
use H5PCore;
use H5PStorage;
use H5PValidator;
use H5peditor;
use MichielRoos\H5p\Adapter\Core\CoreFactory;
use MichielRoos\H5p\Adapter\Core\FileStorage;
use MichielRoos\H5p\Adapter\Core\Framework;
use MichielRoos\H5p\Adapter\Editor\EditorAjax;
use MichielRoos\H5p\Adapter\Editor\EditorStorage;
use MichielRoos\H5p\Domain\Model\Content;
use MichielRoos\H5p\Domain\Model\Library;
use MichielRoos\H5p\Domain\Repository\ContentRepository;
use MichielRoos\H5p\Domain\Repository\LibraryRepository;
use MichielRoos\H5p\Property\TypeConverter\UploadedFileReferenceConverter;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Pagination\QueryResultPaginator;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\View\ViewInterface;

/**
 * Module 'H5P' for the 'h5p' extension.
 */
class H5pModuleController extends ActionController
{
    public string $perms_clause;
    protected bool $h5pContentAllowedOnPage = false;
    protected string $relativePath;
    protected array $pageRecord = [];
    protected bool $isAccessibleForCurrentUser = false;
    protected int $id;

    protected int $limit = 20;

    /**
     * @var FileStorage
     */
    private FileStorage $h5pFileStorage;

    /**
     * @var CoreFactory
     */
    private CoreFactory $h5pCore;

    /**
     * @var Framework
     */
    private Framework $h5pFramework;

    /**
     * @var H5PContentValidator
     */
    private H5PContentValidator $h5pContentValidator;

    /**
     * @var string
     */
    private string $language;

    /**
     * @var H5peditor
     */
    private H5peditor $h5pEditor;

    private ModuleTemplate $moduleTemplate;
    private int $itemsPerPage = 50;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer          $pageRenderer,
        private readonly IconFactory           $iconFactory,
        private readonly BackendUriBuilder     $backendUriBuilder
    )
    {
    }

    /**
     * Initializes the Module
     *
     * @return void
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle(LocalizationUtility::translate('LLL:EXT:h5p/Resources/Private/Language/BackendModule.xlf:mlang_tabs_tab'));

        $this->id = (int)($GLOBALS['TYPO3_REQUEST']->getParsedBody()['id'] ?? $GLOBALS['TYPO3_REQUEST']->getQueryParams()['id'] ?? null);
        $backendUser = $this->getBackendUser();
        $this->perms_clause = $backendUser->getPagePermsClause(1);
        $this->pageRecord = BackendUtility::readPageAccess($this->id, $this->perms_clause);
        $this->isAccessibleForCurrentUser = ($this->id && is_array($this->pageRecord)) || (!$this->id && $this->isCurrentUserAdmin());

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:h5p/Resources/Private/Language/locallang.xlf');
        if ($this->isAccessibleForCurrentUser) {
            $this->moduleTemplate->getDocHeaderComponent()->setMetaInformation($this->pageRecord);
        }

        // don't access in workspace
        if ($backendUser->workspace !== 0) {
            $this->isAccessibleForCurrentUser = false;
        }

        // Get extension configuration
        $allowContentOnStandardPages = false;
        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('h5p');
        if (!isset($extConf['onlyAllowRecordsInSysfolders']) || (int)$extConf['onlyAllowRecordsInSysfolders'] === 0) {
            $allowContentOnStandardPages = true;
        }
        $dokType                       = $this->pageRecord['doktype'] ?? 0;
        $pageIsSysfolder               = (int)$dokType === 254;
        $this->h5pContentAllowedOnPage = $allowContentOnStandardPages || $pageIsSysfolder;

        $this->language = ($this->getLanguageService()->lang === 'default') ? 'en' : $this->getLanguageService()->lang;

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();
        $this->h5pFramework = GeneralUtility::makeInstance(Framework::class);
        $this->h5pFramework->setStorage($storage); // Storage nachträglich setzen
        $this->h5pFileStorage = GeneralUtility::makeInstance(FileStorage::class, $storage);
        $this->h5pCore = GeneralUtility::makeInstance(CoreFactory::class, $this->h5pFramework, $this->h5pFileStorage, '', $this->language);
        $this->h5pContentValidator = GeneralUtility::makeInstance(H5PContentValidator::class, $this->h5pFramework, $this->h5pCore);
        $editorAjax = GeneralUtility::makeInstance(EditorAjax::class);
        $editorStorage = GeneralUtility::makeInstance(EditorStorage::class);
        $this->h5pEditor = GeneralUtility::makeInstance(H5peditor::class, $this->h5pCore, $editorStorage, $editorAjax);
    }

    /**
     * Returns the current BE user.
     *
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Determines whether the current user is admin.
     *
     * @return bool Whether the current user is admin
     */
    protected function isCurrentUserAdmin(): bool
    {
        return (bool)$this->getBackendUser()->user['admin'];
    }

    /**
     * Returns an instance of LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        return $languageService;
    }

    /**
     * Initialize the view
     * @todo v12: Change signature to TYPO3Fluid\Fluid\View\ViewInterface when extbase ViewInterface is dropped.
     *
     * @param ViewInterface $view The view
     * @return void
     */
    public function initializeView(ViewInterface $view): void
    {
        $view->assignMultiple([
            'dateFormat' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'],
            'timeFormat' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
        ]);

        $this->registerDocheaderButtons();
        $this->generateMenu();
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    /**
     * Registers the Icons into the docheader
     *
     * @return void
     * @throws InvalidArgumentException
     */
    protected function registerDocheaderButtons(): void
    {
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $currentRequest = $this->request;
        $moduleName = $currentRequest->getPluginName();
        $getVars = $this->request->getArguments();

        $extensionName = $currentRequest->getControllerExtensionName();
        if (count($getVars) === 0) {
            $modulePrefix = strtolower('tx_' . $extensionName . '_' . $moduleName);
            $getVars = ['id', 'M', $modulePrefix];
        }
// fix this later...
//        $shortcutButton = $buttonBar->makeShortcutButton()
//            ->setModuleName($moduleName)
//            ->setGetVariables($getVars);
//        $buttonBar->addButton($shortcutButton);

        if ($this->h5pContentAllowedOnPage && in_array($this->request->getControllerActionName(), ['content', 'index', 'show'])) {
            $title = $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.new');
            $icon = $this->iconFactory->getIcon('actions-document-new', IconSize::SMALL);
            $addUserButton = $buttonBar->makeLinkButton()
                ->setHref($this->getHref('H5pModule', 'new'))
                ->setTitle($title)
                ->setIcon($icon);
            $buttonBar->addButton($addUserButton);
        }

        if (in_array($this->request->getControllerActionName(), ['show'])) {
            $title = $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.edit');
            $icon = $this->iconFactory->getIcon('actions-document-open', IconSize::SMALL);
            $addUserButton = $buttonBar->makeLinkButton()
                ->setHref($this->getHref('H5pModule', 'edit', ['contentId' => $this->request->getArgument('contentId')]))
                ->setTitle($title)
                ->setIcon($icon);
            $buttonBar->addButton($addUserButton);
        }
    }

    /**
     * Creates te URI for a backend action
     *
     * @param string $controller
     * @param string $action
     * @param array $parameters
     * @return string
     */
    protected function getHref(string $controller, string $action, array $parameters = []): string
    {
        $this->uriBuilder->setRequest($this->request);
        return $this->uriBuilder->reset()->uriFor($action, $parameters, $controller);
    }

    /**
     * Generates the action menu
     */
    protected function generateMenu()
    {
        $menuItems = [
            'choose' => [
                'controller' => 'H5pModule',
                'action'     => 'content',
                'label'      => $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.choose')
            ]
        ];
        if ($this->isCurrentUserAdmin()) {
            $menuItems['index'] = [
                'controller' => 'H5pModule',
                'action'     => 'index',
                'label'      => $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.index')
            ];
        }
        $menuItems['content'] = [
            'controller' => 'H5pModule',
            'action'     => 'content',
            'label'      => $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.content')
        ];
        if ($this->h5pContentAllowedOnPage) {
            $menuItems['new'] = [
                'controller' => 'H5pModule',
                'action'     => 'new',
                'label'      => $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.new')
            ];
        }
        $menuItems['libraries'] = [
            'controller' => 'H5pModule',
            'action'     => 'libraries',
            'label'      => $this->getLanguageService()->sL('LLL:EXT:h5p/Resources/Private/Language/locallang.xlf:module.menu.libraries')
        ];
        $this->uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('IndexedSearchModuleMenu');

        foreach ($menuItems as $menuItem) {
            $isActive = $this->request->getControllerActionName() === $menuItem['action'];
            $menuItem = $menu->makeMenuItem()
                ->setTitle($menuItem['label'])
                ->setHref($this->uriBuilder->uriFor($menuItem['action']))
                ->setActive($isActive);
            $menu->addMenuItem($menuItem);
        }

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * Shows a list of h5p content
     *
     * @param int $currentPage
     * @return ResponseInterface
     */
    public function indexAction(int $currentPage = 1): ResponseInterface
    {
        $contentRepository = GeneralUtility::makeInstance(ContentRepository::class);
        $content = $contentRepository->findAll();

        $paginator = new QueryResultPaginator($content, $currentPage, $this->itemsPerPage);
        $pagination = new SimplePagination($paginator);


        $this->moduleTemplate->assignMultiple([
            'action'                  => 'index',
            'paginator'               => $paginator,
            'pagination'              => $pagination,
            'h5pContentAllowedOnPage' => $this->h5pContentAllowedOnPage,
            'id'                      => $this->id,
            'h5pContent'              => $content
        ]);

        return $this->moduleTemplate->renderResponse("H5pModule/Index");
    }

    /**
     * Shows a list of h5p content on selected page
     *
     * @param int $currentPage
     * @return ResponseInterface
     */
    public function contentAction(int $currentPage = 1): ResponseInterface
    {
        $contentRepository = GeneralUtility::makeInstance(ContentRepository::class);
        $content = $contentRepository->findBy(['pid' => $this->id]);

        $paginator = new QueryResultPaginator($content, $currentPage, $this->itemsPerPage);
        $pagination = new SimplePagination($paginator);

        $this->moduleTemplate->assignMultiple([
            'action'                  => 'content',
            'h5pContentAllowedOnPage' => $this->h5pContentAllowedOnPage,
            'id'                      => $this->id,
            'h5pContent'              => $content,
            'paginator'               => $paginator,
            'pagination'              => $pagination,
        ]);
        return $this->moduleTemplate->renderResponse("H5pModule/Content");
    }

    /**
     * Renders the available libraries
     *
     * @param int $currentPage
     * @return ResponseInterface
     * @throws Exception
     */
    public function librariesAction(int $currentPage = 1): ResponseInterface
    {
        $libraryRepository = GeneralUtility::makeInstance(LibraryRepository::class);
        $libraries = $libraryRepository->findAll();

        // Check if any libraries need an update
        $librariesThatNeedUpdate = [];
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();
        if ($storage !== null) {
            foreach ($libraries as $library) {
                try {
                    $libraryJson = $storage->getFile('/h5p/libraries/' . $library->getFolderName() . '/library.json');
                    if ($libraryJson instanceof FileInterface && $libraryJson->getSize() > 0) {
                        $libraryContent = json_decode($libraryJson->getContents(), true);
                        $preloadedCss = self::pathsToCsv($libraryContent, 'preloadedCss');
                        $preloadedJs = self::pathsToCsv($libraryContent, 'preloadedJs');
                        if (($preloadedCss !== '' && $library->getPreloadedCss() !== $preloadedCss)
                            || ($preloadedJs !== '' && $library->getPreloadedJs() !== $preloadedJs)) {
                            $library->setPreloadedCss($preloadedCss);
                            $library->setPreloadedJs($preloadedJs);
                            $librariesThatNeedUpdate[] = $library;
                        }
                    }
                } catch (\Exception $e) {
                }
            }
        }
        if (count($librariesThatNeedUpdate) > 0) {
            $persistenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
            foreach ($librariesThatNeedUpdate as $library) {
                $persistenceManager->add($library);
            }
            $persistenceManager->persistAll();
        }

        $paginator = new QueryResultPaginator($libraries, $currentPage, $this->itemsPerPage);
        $pagination = new SimplePagination($paginator);

        $this->moduleTemplate->assignMultiple([
            'action'     => 'libraries',
            'id'         => $this->id,
            'libraries'  => $libraries,
            'paginator'  => $paginator,
            'pagination' => $pagination,
        ]);

        return $this->moduleTemplate->renderResponse("H5pModule/Libraries");
    }

    /**
     * Convert list of file paths to csv
     *
     * @param array $library
     *  Library data as found in library.json files
     * @param string $key
     *  Key that should be found in $libraryData
     *
     * @return string
     *  file paths separated by ', '
     */
    private static function pathsToCsv(array $library, string $key): string
    {
        if (isset($library[$key])) {
            $paths = [];
            foreach ($library[$key] as $file) {
                $paths[] = $file['path'];
            }
            return implode(', ', $paths);
        }
        return '';
    }

    /**
     * Create action
     *
     * @throws StopActionException
     * @throws NoSuchArgumentException
     */
    public function createAction(): ResponseInterface
    {
        // Import: Wurde eine .h5p-Datei hochgeladen, ersetzt sie den Editor-Zweig.
        // Das Formular bietet beides an ("Upload" / "Create"), bisher wertete der
        // Controller aber nur den Editor aus - eine hochgeladene Datei lief ins
        // Leere und endete in "Invalid library".
        $paket = $this->ermittleHochgeladenesPaket();
        if ($paket !== null) {
            return $this->importierePaket($paket);
        }

        // Keep track of the old library and params
        $oldLibrary = null;
        $oldParams = null;
        $content = [
            'disable' => H5PCore::DISABLE_NONE
        ];

        // Get library
        $content['library'] = H5PCore::libraryFromString($this->request->getArgument('library'));
        if (!$content['library']) {
            $this->h5pCore->h5pF->setErrorMessage('Invalid library.');
            return new ForwardResponse('new');
        }
        if ($this->h5pCore->h5pF->libraryHasUpgrade($content['library'])) {
            // We do not allow storing old content due to security concerns
            $this->h5pCore->h5pF->setErrorMessage('Something unexpected happened. We were unable to save this content.');
            $this->addFlashMessage('Something unexpected happened. We were unable to save this content.');
            return new ForwardResponse('new');
        }

        // Check if library exists.
        $content['library']['libraryId'] = $this->h5pCore->h5pF->getLibraryId($content['library']['machineName'], $content['library']['majorVersion'],
            $content['library']['minorVersion']);
        if (!$content['library']['libraryId']) {
            $this->h5pCore->h5pF->setErrorMessage('No such library.');
            $this->addFlashMessage('No such library.');
            return new ForwardResponse('new');
        }

        // Check parameters
        $content['params'] = $this->request->getArgument('parameters');
        if ($content['params'] === null) {
            return false;
        }
        $params = json_decode($content['params']);
        if ($params === null) {
            $this->h5pCore->h5pF->setErrorMessage('Invalid parameters.');
            $this->addFlashMessage('Invalid parameters.');
            return new ForwardResponse('new');
        }

        $content['params'] = json_encode($params->params);
        $content['metadata'] = $params->metadata;

        // Trim title and check length
        $trimmed_title = empty($content['metadata']->title) ? '' : trim($content['metadata']->title);
        if ($trimmed_title === '') {
            $this->addFlashMessage('Missing title');
            return new ForwardResponse('new');
        }

        if (strlen($trimmed_title) > 255) {
            $this->addFlashMessage('Title is too long. Must be 256 letters or shorter.', '', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('new');
        }

        $this->setDisabledContentFeatures($this->h5pCore, $content);

        try {
            // Save new content
            $content['id'] = $this->h5pCore->saveContent($content);
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), $e->getCode(), ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('new');
        }

        // Move images and find all content dependencies
        $this->h5pEditor->processParameters($content['id'], $content['library'], $params->params, $oldLibrary, $oldParams);

        // Used to generate the slug
        $content['title'] = $content['metadata']->title;

        // Store content dependencies
        // Muss vor filterParameters() stehen: Ist der Export aktiv, baut H5PExport
        // daraus die h5p.json und braucht Felder, die dieser Controller bisher nicht
        // gesetzt hat.
        $content = $this->ergaenzeExportFelder($content);

        $this->h5pCore->filterParameters($content);

        $this->addFlashMessage('Content stored successfully.');
        return (new ForwardResponse('show'))->withControllerName('H5pModule')->withExtensionName('h5p')->withArguments(['contentId' => $content['id']]);
    }

    /**
     * Extract disabled content features from input post.
     *
     * @param H5PCore $core
     * @param $content
     * @return void
     */
    private function setDisabledContentFeatures(H5PCore $core, &$content): void
    {
        $set = [
            H5PCore::DISPLAY_OPTION_FRAME     => (bool)$this->request->getArgument('frame'),
            H5PCore::DISPLAY_OPTION_DOWNLOAD  => (bool)$this->request->getArgument('download'),
            H5PCore::DISPLAY_OPTION_EMBED     => (bool)$this->request->getArgument('embed'),
            H5PCore::DISPLAY_OPTION_COPYRIGHT => (bool)$this->request->getArgument('copyright')
        ];
        $content['disable'] = $core->getStorableDisplayOptions($set, $content['disable']);
    }

    /**
     * Update action
     *
     * @throws StopActionException
     * @throws NoSuchArgumentException
     */
    public function updateAction(): ResponseInterface
    {
        // Import: Wurde eine .h5p-Datei hochgeladen, ersetzt sie den Editor-Zweig.
        // Das Formular bietet beides an ("Upload" / "Create"), bisher wertete der
        // Controller aber nur den Editor aus - eine hochgeladene Datei lief ins
        // Leere und endete in "Invalid library".
        $paket = $this->ermittleHochgeladenesPaket();
        if ($paket !== null) {
            return $this->importierePaket($paket, (int)($this->request->hasArgument('contentId') ? $this->request->getArgument('contentId') : 0));
        }

        // Content id
        $contentId = null;
        if ($this->request->hasArgument('contentId')) {
            $contentId = $this->request->getArgument('contentId');
        }

        // Keep track of the old library and params
        $oldLibrary = null;
        $oldParams = null;
        $content = [
            'disable' => H5PCore::DISABLE_NONE
        ];

        // Get library
        $content['library'] = H5PCore::libraryFromString($this->request->getArgument('library'));
        if (!$content['library']) {
            $this->h5pCore->h5pF->setErrorMessage('Invalid library.');
            return new ForwardResponse('new');
        }
        if ($this->h5pCore->h5pF->libraryHasUpgrade($content['library'])) {
            // We do not allow storing old content due to security concerns
            $this->h5pCore->h5pF->setErrorMessage('Something unexpected happened. We were unable to save this content.');
            $this->addFlashMessage('Something unexpected happened. We were unable to save this content.');
            return new ForwardResponse('new');
        }

        // Check if library exists.
        $content['library']['libraryId'] = $this->h5pCore->h5pF->getLibraryId($content['library']['machineName'], $content['library']['majorVersion'],
            $content['library']['minorVersion']);
        if (!$content['library']['libraryId']) {
            $this->h5pCore->h5pF->setErrorMessage('No such library.');
            $this->addFlashMessage('No such library.');
            return new ForwardResponse('new');
        }

        // Check parameters
        $content['params'] = $this->request->getArgument('parameters');
        if ($content['params'] === null) {
            return false;
        }
        $params = json_decode($content['params']);
        if ($params === null) {
            $this->h5pCore->h5pF->setErrorMessage('Invalid parameters.');
            $this->addFlashMessage('Invalid parameters.');
            return new ForwardResponse('new');
        }

        $content['params'] = json_encode($params->params);
        $content['metadata'] = $params->metadata;

        // Trim title and check length
        $trimmed_title = empty($content['metadata']->title) ? '' : trim($content['metadata']->title);
        if ($trimmed_title === '') {
            $this->addFlashMessage('Missing title');
            return new ForwardResponse('new');
        }

        if (strlen($trimmed_title) > 255) {
            $this->addFlashMessage('Title is too long. Must be 256 letters or shorter.', '', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('new');
        }

        $this->setDisabledContentFeatures($this->h5pCore, $content);

        try {
            // Save new content
            $content['id'] = $contentId;
            $content['id'] = $this->h5pCore->saveContent($content, $contentId);
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), $e->getCode(), ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('new');
        }

        // Move images and find all content dependencies
        $this->h5pEditor->processParameters($content['id'], $content['library'], $params->params, $oldLibrary, $oldParams);

        // Used to generate the slug
        $content['title'] = $content['metadata']->title;

        // Store content dependencies
        // Muss vor filterParameters() stehen: Ist der Export aktiv, baut H5PExport
        // daraus die h5p.json und braucht Felder, die dieser Controller bisher nicht
        // gesetzt hat.
        $content = $this->ergaenzeExportFelder($content);

        $this->h5pCore->filterParameters($content);

        $this->addFlashMessage('Content stored successfully.');
        return (new ForwardResponse('show'))->withControllerName('H5pModule')->withExtensionName('h5p')->withArguments(['contentId' => $content['id']]);
    }

    /**
     * Edit action
     * @param int $contentId
     * @throws RouteNotFoundException
     */
    public function editAction(int $contentId): ResponseInterface
    {
        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration',
            'H5PIntegration = ' . json_encode($this->getEditorSettings($this->getCoreSettings())) . ';', false, false, true
        );

        if ($contentId > 0) {
            $contentRepository = GeneralUtility::makeInstance(ContentRepository::class);
            $content = $contentRepository->findByUid($contentId);

            if (!$content instanceof Content) {
                $this->addFlashMessage(sprintf('Content element with id %d not found', $contentId), 'Record not found', ContextualFeedbackSeverity::ERROR);
                $this->redirect('error', 'H5pModule', 'h5p');
            }

            // load JS and CSS requirements
            $contentLibrary = $content->getLibrary();
            if ($contentLibrary instanceof Library) {
                $contentLibraryArray = $contentLibrary->toAssocArray();
                $this->moduleTemplate->assign('library',
                    sprintf('%s %d.%d', $contentLibraryArray['machineName'], $contentLibraryArray['majorVersion'], $contentLibraryArray['minorVersion']));
            }
            $this->moduleTemplate->assign('content', $content);
            $parameters = (array)json_decode($content->getFiltered());
            $this->moduleTemplate->assign(
                'displayOptions',
                $this->bereiteAnzeigeoptionenAuf($this->h5pCore->getDisplayOptionsForEdit($content->getDisable()))
            );
            $parameters = $this->injectMetadataIntoParameters($parameters, $content);
            $parameters = json_encode($parameters, JSON_THROW_ON_ERROR);
            // Unbreak wrongly encoded parameters (Content.php updateFromContentData())
            $parameters = str_replace([
                '"globalBackgroundSelector":[]',
                '"slideBackgroundSelector":[]',
                '"image":[]'
            ], [
                '"globalBackgroundSelector":{}',
                '"slideBackgroundSelector":{}',
                '"image":{}'
            ], $parameters);
            $this->moduleTemplate->assign('parameters', $parameters);
        }

        $this->embedEditorScriptsAndStyles();
        return $this->moduleTemplate->renderResponse("H5pModule/Edit");
    }

    /**
     * @param $settings
     * @return mixed
     * @throws RouteNotFoundException|NoSuchArgumentException
     * @throws InvalidFileException
     */
    public function getEditorSettings($settings)
    {
        $webEditorPath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-editor/');
        $webCorePath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');

        $cacheBuster = '?v=' . Framework::$version;

        // Add JavaScript settings
        $settings['editor'] = [
            'filesPath'          => '/fileadmin/h5p/editor',
            'fileIcon'           => [
                'path'   => $webEditorPath . 'images/binary-file.png',
                'width'  => 50,
                'height' => 50,
            ],
            'ajaxPath'           => (string)$this->backendUriBuilder->buildUriFromRoute('h5p_editor_action', ['action' => 'h5p_']),
            'libraryUrl'         => $webEditorPath,
            'copyrightSemantics' => $this->h5pContentValidator->getCopyrightSemantics(),
            'metadataSemantics'  => $this->h5pContentValidator->getMetadataSemantics(),
            'assets'             => [],
            'deleteMessage'      => 'Are you sure you wish to delete this content?',
            'apiVersion'         => CoreFactory::$coreApi,
            'language'           => $this->language
        ];

        foreach (H5PCore::$styles as $style) {
            $settings['editor']['assets']['css'][] = $webCorePath . $style . $cacheBuster;
        }
        foreach (H5PCore::$scripts as $script) {
            $settings['editor']['assets']['js'][] = $webCorePath . $script . $cacheBuster;
        }

        foreach (H5peditor::$styles as $style) {
            $settings['editor']['assets']['css'][] = $webEditorPath . $style . $cacheBuster;
        }
        foreach (H5peditor::$scripts as $script) {
            if (strpos($script, 'h5peditor-editor') === false) {
                $settings['editor']['assets']['js'][] = $webEditorPath . $script . $cacheBuster;
            }
        }

        $id = null;
        if ($this->request->hasArgument('contentId')) {
            $id = $this->request->getArgument('contentId');
        }
        if ($id !== null) {
            $settings['editor']['nodeVersionId'] = $id;
        }
        return $settings;
    }

    /**
     * Get generic h5p settings
     *
     * @return array;
     * @throws RouteNotFoundException|Exception|InvalidFileException
     */
    public function getCoreSettings(): array
    {
        $backendUser = $this->getBackendUser()->user;

        $absoluteWebPath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');

        $url = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');

        $cacheBuster = '?v=' . Framework::$version;

        $settings = [
            'baseUrl'            => $url,
            'url'                => '/fileadmin/h5p',
            'postUserStatistics' => false,
            'ajax'               => [
                'setFinished'     => (string)$this->backendUriBuilder->buildUriFromRoute('h5p_editor_action', ['type' => 'setFinished', 'action' => 'h5p_']),
                'contentUserData' => (string)$this->backendUriBuilder->buildUriFromRoute('h5p_editor_action', [
                    'type'           => 'contentUserData',
                    'action'         => 'h5p_',
                    'content_id'     => ':contentId',
                    'data_type'      => ':dataType',
                    'sub_content_id' => ':subContentId'
                ]),
            ],
            'saveFreq'           => $this->h5pFramework->getOption('save_content_state') ? $this->h5pFramework->getOption('save_content_frequency') : false,
            'siteUrl'            => $url,
            'l10n'               => [
                'H5P' => $this->h5pCore->getLocalization(),
            ],
            'hubIsEnabled'       => (int)$this->h5pFramework->getOption('hub_is_enabled') === 1,
            'reportingIsEnabled' => (int)$this->h5pFramework->getOption('enable_lrs_content_types') === 1,
            'libraryConfig'      => $this->h5pFramework->getLibraryConfig(),
            'crossorigin'        => defined('H5P_CROSSORIGIN') ? H5P_CROSSORIGIN : null,
            'pluginCacheBuster'  => $cacheBuster,
            'libraryUrl'         => $absoluteWebPath . 'js',
            'contents'           => []
        ];

        if ($backendUser['uid']) {
            $settings['user'] = [
                'name' => $backendUser['realName'],
                'mail' => $backendUser['email']
            ];
        }

        $webCorePath = $absoluteWebPath;
        foreach (H5PCore::$styles as $style) {
            $settings['core']['styles'][] = $webCorePath . $style . $cacheBuster;
        }
        foreach (H5PCore::$scripts as $script) {
            $settings['core']['scripts'][] = $webCorePath . $script . $cacheBuster;
        }
        $settings['loadedJs'] = [];
        $settings['loadedCss'] = [];

        return $settings;
    }

    /**
     * @param array $parameters
     * @param Content $content
     * @return array
     */
    private function injectMetadataIntoParameters(array $parameters, Content $content): array
    {
        $metadata = [
            'title'          => $content->getTitle(),
            'authors'        => json_decode($content->getAuthors(), true),
            'source'         => $content->getSource(),
            'yearFrom'       => $content->getYearFrom(),
            'yearTo'         => $content->getYearTo(),
            'license'        => $content->getLicense(),
            'licenseVersion' => $content->getLicenseVersion(),
            'licenseExtras'  => $content->getLicenseExtras(),
            'authorComments' => $content->getAuthorComments(),
            'changes'        => json_decode($content->getChanges(), true)
        ];

        $parameters['metadata'] = $metadata;
        return $parameters;
    }

    /**
     * Embed scripts and styles
     * @throws InvalidFileException
     */
    protected function embedEditorScriptsAndStyles(): void
    {
        $webCorePath   = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');
        $webEditorPath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-editor/');
        $webScriptPath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/JavaScript/');

        $paths = [
            'h5p-jquery'              => $webCorePath . 'js/jquery.js',
            'h5p'                     => $webCorePath . 'js/h5p.js',
            'h5p-event-dispatcher'    => $webCorePath . 'js/h5p-event-dispatcher.js',
            'h5p-x-api-event'         => $webCorePath . 'js/h5p-x-api-event.js',
            'h5p-x-api'               => $webCorePath . 'js/h5p-x-api.js',
            'h5p-content-type'        => $webCorePath . 'js/h5p-content-type.js',
            'h5p-confirmation-dialog' => $webCorePath . 'js/h5p-confirmation-dialog.js',
            'h5p-action-bar'          => $webCorePath . 'js/h5p-action-bar.js',
            'h5peditor-editor'        => $webEditorPath . 'scripts/h5peditor-editor.js',
            'h5peditor-init'          => $webEditorPath . 'scripts/h5peditor-init.js',
            'h5p-display-options'     => $webCorePath . 'js/h5p-display-options.js',
            'TYPO3/CMS/H5p/editor'    => $webScriptPath . 'editor.js',
        ];

        $languageFile = ExtensionManagementUtility::extPath('h5p') . 'Resources/Public/Lib/h5p-editor/language/' . $this->language . '.js';
        if (file_exists($languageFile)) {
            $paths['h5peditor-editor-language'] = $webEditorPath . 'language/' . $this->language . '.js';
        } else {
            $paths['h5peditor-editor-language'] = $webEditorPath . 'language/en.js';
        }

        foreach ($paths as $name => $path) {
            $this->pageRenderer->addJsFile($path, 'text/javascript', false, false, '', true);
        }

        foreach (H5PCore::$styles as $style) {
            $this->pageRenderer->addCssFile($webCorePath . $style, 'stylesheet', 'all', '', false, false, '', true);
        }
        foreach (H5peditor::$styles as $style) {
            $this->pageRenderer->addCssFile($webEditorPath . $style, 'stylesheet', 'all', '', false, false, '', true);
        }
        //$this->pageRenderer->loadRequireJsModule('TYPO3/CMS/H5p/editor');
    }

    /**
     * Consent action
     */
    public function consentAction(): ResponseInterface
    {
        if ($this->request->getArgument('collectStatistics')) {
            $this->h5pFramework->setOption('track_user', 1);
            $this->addFlashMessage('Usage tracking has been enabled.', 'Tracking enabled');
        }
        $this->h5pFramework->setOption('hub_is_enabled', 1);
        $this->addFlashMessage('The hub has been enabled.', 'H5P hub enabled');
        return new ForwardResponse('new');
    }

    /**
     * New action / upload form
     * @param int $contentId
     * @return ResponseInterface
     * @throws NoSuchArgumentException
     * @throws RouteNotFoundException
     * @throws InvalidFileException
     */
    public function newAction(int $contentId = 0): ResponseInterface
    {
        $this->moduleTemplate->assign('didConsent', (int)$this->h5pFramework->getOption('hub_is_enabled') === 1);
        $this->moduleTemplate->assign('h5pContentAllowedOnPage', $this->h5pContentAllowedOnPage);

        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration',
            'H5PIntegration = ' . json_encode($this->getEditorSettings($this->getCoreSettings())) . ';', false, false, true
        );

        if ($contentId > 0) {
            $contentRepository = GeneralUtility::makeInstance(ContentRepository::class);
            $content = $contentRepository->findByUid($contentId);

            if (!$content instanceof Content) {
                $this->addFlashMessage(sprintf('Content element with id %d not found', $contentId), 'Record not found', ContextualFeedbackSeverity::ERROR);
                $this->redirect('error');
            }

            // load JS and CSS requirements
            $contentLibrary = $content->getLibrary()->toAssocArray();
            $this->moduleTemplate->assign('library',
                sprintf('%s %d.%d', $contentLibrary['machineName'], $contentLibrary['majorVersion'], $contentLibrary['minorVersion']));
            $this->moduleTemplate->assign('parameters', $content->getFiltered());
        }

        $this->embedEditorScriptsAndStyles();
        return $this->moduleTemplate->renderResponse("H5pModule/New");
    }

    /**
     * Show action
     * @param int $contentId
     * @return ResponseInterface
     * @throws RouteNotFoundException
     * @throws InvalidFileException
     */
    public function showAction(int $contentId): ResponseInterface
    {
        $contentRepository = GeneralUtility::makeInstance(ContentRepository::class);
        $content = $contentRepository->findByUid($contentId);

        if (!$content instanceof Content) {
            $this->addFlashMessage(sprintf('Content element with id %d not found', $contentId), 'Record not found', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('error');
        }

        if (!$content->getLibrary()) {
            $this->addFlashMessage('Content element has no H5P library', 'H5P library not found on content', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('error');
        }

        $relativeCorePath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');

        foreach (H5PCore::$scripts as $script) {
            $this->pageRenderer->addJsFooterFile($relativeCorePath . $script, 'text/javascript', false, false, '', true);
        }
        foreach (H5PCore::$styles as $style) {
            $this->pageRenderer->addCssFile($relativeCorePath . $style, 'stylesheet', 'all', '', false, false, '', true);
        }

        $contentSettings = $this->getContentSettings($content);
        $contentSettings['displayOptions'] = [];
        $contentSettings['displayOptions']['frame'] = true;
        $contentSettings['displayOptions']['export'] = false;
        $contentSettings['displayOptions']['embed'] = false;
        $contentSettings['displayOptions']['copyright'] = false;
        $contentSettings['displayOptions']['icon'] = true;
        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration contents',
            'H5PIntegration.contents[\'cid-' . $content->getUid() . '\'] = ' . json_encode($contentSettings) . ';', false, true, true
        );

        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration',
            'H5PIntegration = ' . json_encode($this->getCoreSettings()) . ';', false, true, true
        );

        if ($content->getEmbedType() !== 'iframe') {
            // load JS and CSS requirements
            $contentLibrary = $content->getLibrary()->toAssocArray();

            // JS and CSS required by all libraries
            $contentLibraryWithDependencies = $this->h5pCore->loadLibrary($contentLibrary['machineName'], $contentLibrary['majorVersion'],
                $contentLibrary['minorVersion']);
            $this->h5pCore->findLibraryDependencies($dependencies, $contentLibraryWithDependencies);
            if (is_array($dependencies)) {
                $dependencies = $this->h5pCore->orderDependenciesByWeight($dependencies);
                foreach ($dependencies as $key => $dependency) {
                    if (strpos($key, 'preloaded-') !== 0) {
                        continue;
                    }
                    $this->loadJsAndCss($dependency['library']);
                }
            }

            // JS and CSS required by the content
            $contentDependencies = $this->h5pFramework->loadContentDependencies($content->getUid(), 'preloaded');
            foreach ($contentDependencies as $dependency) {
                $this->loadJsAndCss($dependency);
            }

            // JS and CSS required by the main Library of the content
            $this->loadJsAndCss($contentLibrary);
        }

        $this->moduleTemplate->assign('content', $content);
        return $this->moduleTemplate->renderResponse("H5pModule/Show");
    }

    /**
     * Get content settings
     *
     * @return array;
     * @throws Exception
     */
    public function getContentSettings(Content $content): array
    {
        $settings = [
            'url'            => '/fileadmin/h5p',
            'library'        => sprintf(
                '%s %d.%d.%d',
                $content->getLibrary()->getMachineName(),
                $content->getLibrary()->getMajorVersion(),
                $content->getLibrary()->getMinorVersion(),
                $content->getLibrary()->getPatchVersion()
            ),
            'jsonContent'    => $content->getFiltered(),
            'fullScreen'     => false,
            'exportUrl'      => '/path/to/download.h5p',
            'embedCode'      => '',
            'resizeCode'     => '',
            'mainId'         => $content->getUid(),
            'title'          => $content->getTitle(),
            'displayOptions' => [
                'frame'     => false,
                'export'    => false,
                'embed'     => false,
                'copyright' => false,
                'icon'      => false
            ],
            'metadata'       => [
                'title' => $content->getTitle()
            ]
        ];

        if ($content->getEmbedType() === 'iframe') {
            $contentLibrary = $content->getLibrary()->toAssocArray();
            $dependencyLibrary = $this->h5pCore->loadLibrary($contentLibrary['machineName'], $contentLibrary['majorVersion'], $contentLibrary['minorVersion']);
            $this->h5pCore->findLibraryDependencies($dependencies, $dependencyLibrary);
            if (is_array($dependencies)) {
                $dependencies = $this->h5pCore->orderDependenciesByWeight($dependencies);
                foreach ($dependencies as $key => $dependency) {
                    if (strpos($key, 'preloaded-') !== 0) {
                        continue;
                    }
                    $this->setJsAndCss($dependency['library'], $settings);
                }
            }

            $contentDependencies = $this->h5pFramework->loadContentDependencies($content->getUid(), 'preloaded');
            foreach ($contentDependencies as $dependency) {
                $this->setJsAndCss($dependency, $settings);
            }

            $this->setJsAndCss($contentLibrary, $settings);
        }

        return $settings;
    }

    /**
     * Set JS and CSS
     * @param array $library
     * @param array $settings
     */
    private function setJsAndCss(array $library, array &$settings): void
    {
        $name = $library['machineName'] . '-' . $library['majorVersion'] . '.' . $library['minorVersion'];
        $preloadCss = explode(',', $library['preloadedCss']);
        $preloadJs = explode(',', $library['preloadedJs']);

        if (!array_key_exists('scripts', $settings)) {
            $settings['scripts'] = [];
        }

        if (!array_key_exists('styles', $settings)) {
            $settings['styles'] = [];
        }

        foreach ($preloadJs as $js) {
            $js = trim($js);
            if ($js) {
                $settings['scripts'][] = '/fileadmin/h5p/libraries/' . $name . '/' . $js;
            }
        }
        foreach ($preloadCss as $css) {
            $css = trim($css);
            if ($css) {
                $settings['styles'][] = '/fileadmin/h5p/libraries/' . $name . '/' . $css;
            }
        }
    }

    /**
     * Load JS and CSS
     * @param array $library
     */
    private function loadJsAndCss(array $library): void
    {
        $name = $library['machineName'] . '-' . $library['majorVersion'] . '.' . $library['minorVersion'];
        $preloadCss = explode(',', $library['preloadedCss']);
        $preloadJs = explode(',', $library['preloadedJs']);

        foreach ($preloadJs as $js) {
            $js = trim($js);
            if ($js) {
                $this->pageRenderer->addJsFile('/fileadmin/h5p/libraries/' . $name . '/' . $js, 'text/javascript', false, false, '', true);
            }
        }
        foreach ($preloadCss as $css) {
            $css = trim($css);
            if ($css) {
                $this->pageRenderer->addCssFile('/fileadmin/h5p/libraries/' . $name . '/' . $css, 'stylesheet', 'all', '', false, false, '', true);
            }
        }
    }

    /**
     * Bestaetigungsseite vor dem Loeschen eines Inhalts.
     *
     * Bewusst zweistufig statt ein Klick plus JavaScript-Rueckfrage: Das Backend von
     * TYPO3 13 setzt eine strenge CSP, inline-onclick liefe dort ins Leere - und bei
     * einer nicht umkehrbaren Aktion ist eine eigene Seite mit allen Angaben ohnehin
     * die ehrlichere Loesung.
     */
    public function deleteContentAction(int $contentId): ResponseInterface
    {
        $content = GeneralUtility::makeInstance(ContentRepository::class)->findOneByUid($contentId);
        if ($content === null) {
            $this->addFlashMessage('H5P-Inhalt nicht gefunden.', '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index', null, null, ['id' => $this->id]);
        }

        $this->moduleTemplate->assignMultiple([
            'action'      => 'deleteContent',
            'id'          => $this->id,
            'content'     => $content,
            'usedOnPages' => $this->findContentElementsUsing($contentId),
        ]);

        return $this->moduleTemplate->renderResponse('H5pModule/DeleteContent');
    }

    /**
     * Loescht einen Inhalt samt Dateien, Abhaengigkeiten und Exportdatei.
     *
     * Geht bewusst ueber H5PStorage::deletePackage(), damit Reihenfolge und Umfang
     * aus dem H5P-Kern kommen und nicht hier nachgebaut werden.
     */
    public function deleteContentConfirmAction(int $contentId): ResponseInterface
    {
        $content = GeneralUtility::makeInstance(ContentRepository::class)->findOneByUid($contentId);
        if ($content === null) {
            $this->addFlashMessage('H5P-Inhalt nicht gefunden.', '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index', null, null, ['id' => $this->id]);
        }

        $title = $content->getTitle();
        (new H5PStorage($this->h5pFramework, $this->h5pCore))->deletePackage([
            'id'   => $contentId,
            'slug' => $content->getSlug(),
        ]);

        $this->addFlashMessage(
            sprintf('H5P-Inhalt "%s" wurde mit allen Dateien geloescht.', $title),
            '',
            ContextualFeedbackSeverity::OK
        );

        return $this->redirect('index', null, null, ['id' => $this->id]);
    }

    /**
     * Bestaetigungsseite vor dem Loeschen einer Bibliothek.
     */
    public function deleteLibraryAction(int $libraryId): ResponseInterface
    {
        $library = GeneralUtility::makeInstance(LibraryRepository::class)->findOneByUid($libraryId);
        if ($library === null) {
            $this->addFlashMessage('Bibliothek nicht gefunden.', '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('libraries', null, null, ['id' => $this->id]);
        }

        $usage = $this->getLibraryUsageCounts($libraryId);

        $this->moduleTemplate->assignMultiple([
            'action'          => 'deleteLibrary',
            'id'              => $this->id,
            'library'         => $library,
            'usedByContent'   => $usage['content'],
            'usedByLibraries' => $usage['libraries'],
        ]);

        return $this->moduleTemplate->renderResponse('H5pModule/DeleteLibrary');
    }

    /**
     * Loescht eine Bibliothek samt Ordner, Abhaengigkeiten und Uebersetzungen.
     *
     * Verweigert die Ausfuehrung, solange die Bibliothek benutzt wird - sonst blieben
     * Inhalte zurueck, die sich nicht mehr darstellen lassen. Die Pruefung steht hier
     * und nicht nur im Template, damit auch ein direkt aufgerufener Link nichts
     * kaputt machen kann.
     */
    public function deleteLibraryConfirmAction(int $libraryId): ResponseInterface
    {
        $library = GeneralUtility::makeInstance(LibraryRepository::class)->findOneByUid($libraryId);
        if ($library === null) {
            $this->addFlashMessage('Bibliothek nicht gefunden.', '', ContextualFeedbackSeverity::ERROR);
            return $this->redirect('libraries', null, null, ['id' => $this->id]);
        }

        $usage = $this->getLibraryUsageCounts($libraryId);
        if ($usage['content'] > 0 || $usage['libraries'] > 0) {
            $this->addFlashMessage(
                sprintf(
                    'Bibliothek "%s" wird noch benutzt (%d Inhalte, %d Bibliotheken) und wurde nicht geloescht.',
                    $library->getTitle(),
                    $usage['content'],
                    $usage['libraries']
                ),
                '',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('libraries', null, null, ['id' => $this->id]);
        }

        $title = $library->getTitle() . ' ' . $library->getMajorVersion() . '.' . $library->getMinorVersion();

        // Erst die Dateien, dann die Datenbank: andersherum liesse sich der
        // Ordnername nach dem Loeschen des Datensatzes nicht mehr ermitteln.
        $this->h5pCore->fs->deleteLibrary([
            'machineName'  => $library->getMachineName(),
            'majorVersion' => $library->getMajorVersion(),
            'minorVersion' => $library->getMinorVersion(),
        ]);
        $this->h5pCore->h5pF->deleteLibrary($libraryId);

        $this->addFlashMessage(
            sprintf('Bibliothek "%s" wurde mit allen Dateien geloescht.', $title),
            '',
            ContextualFeedbackSeverity::OK
        );

        return $this->redirect('libraries', null, null, ['id' => $this->id]);
    }

    /**
     * Zaehlt, wodurch eine Bibliothek noch belegt ist.
     *
     * @return array{content: int, libraries: int}
     */
    private function getLibraryUsageCounts(int $libraryId): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $contentQuery = $connectionPool->getQueryBuilderForTable('tx_h5p_domain_model_contentdependency');
        $contentQuery->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $contentCount = (int)$contentQuery
            ->addSelectLiteral('COUNT(DISTINCT ' . $contentQuery->quoteIdentifier('content') . ')')
            ->from('tx_h5p_domain_model_contentdependency')
            ->where($contentQuery->expr()->eq('library', $contentQuery->createNamedParameter($libraryId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        $libraryQuery = $connectionPool->getQueryBuilderForTable('tx_h5p_domain_model_librarydependency');
        $libraryQuery->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $libraryCount = (int)$libraryQuery
            ->count('uid')
            ->from('tx_h5p_domain_model_librarydependency')
            ->where($libraryQuery->expr()->eq('required_library', $libraryQuery->createNamedParameter($libraryId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return ['content' => $contentCount, 'libraries' => $libraryCount];
    }

    /**
     * Sucht Content-Elemente, die diesen H5P-Inhalt einbinden - damit auf der
     * Bestaetigungsseite sichtbar ist, was nach dem Loeschen ins Leere zeigt.
     *
     * @return list<array{uid: int, pid: int, header: string}>
     */
    private function findContentElementsUsing(int $contentId): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('uid', 'pid', 'header')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_h5p_content',
                    $queryBuilder->createNamedParameter($contentId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(20)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Ergaenzt das Content-Array um die Felder, die H5PExport erwartet.
     *
     * Dieser Controller baut $content von Hand zusammen, waehrend H5P sonst von
     * H5PCore::loadContent() ausgeht. Solange der Export abgeschaltet war, fiel das
     * nicht auf; mit aktivem Export brach createExportFile() reihenweise ab:
     *
     *   h5p.classes.php:1856  $content['embedType']          -> Undefined array key
     *   h5p.classes.php:1862  $content['library']['name']    -> Undefined array key
     *
     * Ergaenzt wird deshalb genau die Form, die loadContent() (h5p.classes.php:2220)
     * liefert. $content['dependencies'] und ['filtered'] setzt filterParameters()
     * selbst, bevor der Exporter laeuft.
     */
    private function ergaenzeExportFelder(array $content): array
    {
        $libraryId = (int)($content['library']['libraryId'] ?? 0);
        $library = $libraryId > 0
            ? GeneralUtility::makeInstance(LibraryRepository::class)->findOneByUid($libraryId)
            : null;

        // H5PExport liest den Bibliotheksnamen als 'name', libraryFromString() liefert
        // ihn aber als 'machineName'.
        $content['library']['name'] = (string)($content['library']['name']
            ?? $content['library']['machineName']
            ?? '');

        if ($library !== null) {
            $content['library']['embedTypes'] = (string)$library->getEmbedTypes();
            $content['library']['fullscreen'] = $library->isFullscreen();
        }

        $content['embedType'] = H5PCore::determineEmbedType(
            (string)($content['embedType'] ?? 'div'),
            (string)($content['library']['embedTypes'] ?? '')
        );

        // Die Metadaten kommen als stdClass aus dem Editor, H5PExport greift aber mit
        // Array-Syntax darauf zu. Das wirft zwar nicht, liefe aber ins Leere: Lizenz
        // und Autoren fehlten dann stillschweigend in der h5p.json.
        if (is_object($content['metadata'] ?? null)) {
            $content['metadata'] = json_decode(json_encode($content['metadata']), true) ?? [];
        }

        return $content;
    }

    /**
     * Uebersetzt H5Ps Anzeigeoptionen in eine Form, mit der das Template arbeiten kann.
     *
     * Zwei Fallen stecken in der Rohform:
     *
     * 1. Die Schluessel sind die Konstantenwerte, und ausgerechnet der Download heisst
     *    intern "export" (H5PCore::DISPLAY_OPTION_DOWNLOAD). Das Template fragte bisher
     *    displayOptions.download ab - der Haken konnte also nie vorbelegt werden.
     *
     * 2. getDisplayOptionsForEdit() laesst einen Schluessel ganz WEG, wenn die globale
     *    Einstellung die Option nicht dem Autor ueberlaesst (also bei "immer" oder
     *    "nie anzeigen"). "fehlt" und "aus" sind damit zweierlei, im Template aber
     *    nicht unterscheidbar.
     *
     * Deshalb je Option ein Paar aus "steuerbar" und "aktiv". Ist eine Option nicht
     * steuerbar, blendet das Template sie aus, statt eine wirkungslose Checkbox zu
     * zeigen.
     *
     * @param array<string, bool> $rohdaten
     * @return array<string, array{steuerbar: bool, aktiv: bool}>
     */
    private function bereiteAnzeigeoptionenAuf(array $rohdaten): array
    {
        $zuordnung = [
            'frame'     => H5PCore::DISPLAY_OPTION_FRAME,
            'download'  => H5PCore::DISPLAY_OPTION_DOWNLOAD,
            'embed'     => H5PCore::DISPLAY_OPTION_EMBED,
            'copyright' => H5PCore::DISPLAY_OPTION_COPYRIGHT,
        ];

        $aufbereitet = [];
        foreach ($zuordnung as $name => $schluessel) {
            $aufbereitet[$name] = [
                'steuerbar' => array_key_exists($schluessel, $rohdaten),
                'aktiv'     => (bool)($rohdaten[$schluessel] ?? false),
            ];
        }

        return $aufbereitet;
    }

    /**
     * Pfad zur hochgeladenen .h5p-Datei, oder null wenn keine hochgeladen wurde.
     *
     * Das Feld heisst im Formular h5p_file (siehe New.html / Edit.html). Die Datei
     * wird an die Stelle verschoben, an der H5PValidator sie erwartet - der Pfad
     * kommt aus Framework::getUploadedH5pPath().
     */
    private function ermittleHochgeladenesPaket(): ?string
    {
        $datei = $this->request->getUploadedFiles()['h5p_file'] ?? null;
        if (!$datei instanceof UploadedFileInterface || $datei->getError() !== UPLOAD_ERR_OK) {
            return null;
        }
        if ($datei->getSize() === 0) {
            return null;
        }

        $ziel = $this->h5pFramework->getUploadedH5pPath();
        $datei->moveTo($ziel);

        return $ziel;
    }

    /**
     * Importiert ein hochgeladenes .h5p-Paket.
     *
     * Geht ueber die beiden Kern-Klassen, damit Pruefung und Speicherung dieselbe
     * Logik nutzen wie in jeder anderen H5P-Plattform:
     *
     *   H5PValidator::isValidPackage()  entpackt, prueft Struktur und Dateitypen
     *   H5PStorage::savePackage()       legt Bibliotheken und Inhalt an
     *
     * @param string $paketPfad Pfad zur hochgeladenen Datei
     * @param int $contentId Vorhandenen Inhalt ersetzen, 0 fuer einen neuen
     */
    private function importierePaket(string $paketPfad, int $contentId = 0): ResponseInterface
    {
        $validator = GeneralUtility::makeInstance(H5PValidator::class, $this->h5pFramework, $this->h5pCore);

        if (!$validator->isValidPackage()) {
            // Framework::setErrorMessage() legt Objekte mit code und message ab,
            // keine Zeichenketten - ein (string)-Cast darauf ist ein Fatal.
            foreach ((array)$this->h5pFramework->getMessages('error') as $meldung) {
                $text = is_object($meldung) ? (string)($meldung->message ?? '') : (string)$meldung;
                if ($text !== '') {
                    $this->addFlashMessage($text, '', ContextualFeedbackSeverity::ERROR);
                }
            }
            @unlink($paketPfad);
            return new ForwardResponse('new');
        }

        // Braucht das Paket Bibliotheken, die noch fehlen, muss der Benutzer sie
        // installieren duerfen - das entscheidet Framework::hasPermission().
        if (!$this->h5pCore->mayUpdateLibraries() && $this->paketBrauchtNeueBibliotheken()) {
            $this->addFlashMessage(
                'Dieses Paket enthält Inhaltstypen, die hier noch nicht installiert sind. '
                . 'Das Installieren von Bibliotheken ist Administratoren vorbehalten.',
                '',
                ContextualFeedbackSeverity::ERROR
            );
            @unlink($paketPfad);
            return new ForwardResponse('new');
        }

        // Die Metadaten stehen in der h5p.json des Pakets; H5PValidator hat sie beim
        // Pruefen nach mainJsonData gelegt. savePackage() selbst setzt sie NICHT,
        // reicht ein uebergebenes Array aber durch - sonst fehlten Titel und Lizenz.
        $vorgabe = ['metadata' => (object)((array)($this->h5pCore->mainJsonData ?? []))];
        if ($contentId > 0) {
            $vorgabe['id'] = $contentId;
        }

        $storage = GeneralUtility::makeInstance(H5PStorage::class, $this->h5pFramework, $this->h5pCore);
        $storage->savePackage($vorgabe);

        $neueId = (int)($storage->contentId ?: $contentId);
        @unlink($paketPfad);

        // savePackage() legt Inhalt und Dateien an, baut aber KEINE Abhaengigkeiten:
        // die entstehen erst in filterParameters(), und das ruft beim Import niemand.
        // Ohne sie laedt der Inhalt im Frontend seine Bibliotheken nicht.
        // filterParameters() schreibt nebenbei auch Slug und gefilterte Parameter und
        // erzeugt - falls eingeschaltet - gleich die Exportdatei.
        if ($neueId > 0) {
            $this->baueAbhaengigkeitenAuf($neueId);
        }

        if ($neueId <= 0) {
            $this->addFlashMessage('Das Paket konnte nicht gespeichert werden.', '', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('new');
        }

        $this->addFlashMessage(
            $contentId > 0
                ? 'Inhalt wurde aus der hochgeladenen Datei ersetzt.'
                : 'Inhalt wurde aus der hochgeladenen Datei angelegt.',
            '',
            ContextualFeedbackSeverity::OK
        );

        return (new ForwardResponse('show'))
            ->withControllerName('H5pModule')
            ->withExtensionName('h5p')
            ->withArguments(['contentId' => $neueId]);
    }

    /**
     * Laesst H5P die Abhaengigkeiten eines frisch importierten Inhalts aufbauen.
     */
    private function baueAbhaengigkeitenAuf(int $contentId): void
    {
        $content = GeneralUtility::makeInstance(ContentRepository::class)->findOneByUid($contentId);
        if (!$content instanceof Content) {
            return;
        }

        $library = $content->getLibrary();
        if (!$library instanceof Library) {
            return;
        }

        $daten = [
            'id'       => $contentId,
            'slug'     => $content->getSlug(),
            'filtered' => '',
            'params'   => $content->getParameters(),
            'title'    => $content->getTitle(),
            'library'  => [
                'libraryId'    => $library->getUid(),
                'machineName'  => $library->getMachineName(),
                'majorVersion' => $library->getMajorVersion(),
                'minorVersion' => $library->getMinorVersion(),
            ],
        ];
        $daten = $this->ergaenzeExportFelder($daten);

        $this->h5pCore->filterParameters($daten);
    }

    /**
     * Enthaelt das gerade gepruefte Paket Bibliotheken, die noch nicht installiert sind?
     *
     * H5PValidator legt die gefundenen Bibliotheken in H5PCore::$librariesJsonData ab.
     */
    private function paketBrauchtNeueBibliotheken(): bool
    {
        foreach ((array)($this->h5pCore->librariesJsonData ?? []) as $bibliothek) {
            $vorhanden = $this->h5pFramework->getLibraryId(
                $bibliothek['machineName'] ?? '',
                $bibliothek['majorVersion'] ?? null,
                $bibliothek['minorVersion'] ?? null
            );
            if (!$vorhanden) {
                return true;
            }
        }

        return false;
    }

    /**
     * Error action
     */
    public function errorAction(): ResponseInterface
    {
        return $this->moduleTemplate->renderResponse("H5pModule/Error");
    }

    /**
     * Set type converter configuration for Package upload
     * @param string $argumentName
     */
    protected function setTypeConverterConfigurationForPackageUpload($argumentName): void
    {
        /** @var PropertyMappingConfiguration $newExampleConfiguration */
        $newExampleConfiguration = $this->arguments[$argumentName]->getPropertyMappingConfiguration();
        $newExampleConfiguration->forProperty('package')
            ->setTypeConverterOptions(
                UploadedFileReferenceConverter::class,
                [
                    UploadedFileReferenceConverter::CONFIGURATION_ALLOWED_FILE_EXTENSIONS => 'h5p',
                    UploadedFileReferenceConverter::CONFIGURATION_UPLOAD_FOLDER           => '1:/h5p/packages/',
                ]
            );
    }
}
