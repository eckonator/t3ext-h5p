<?php

namespace MichielRoos\H5p\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use H5PCore;
use MichielRoos\H5p\Adapter\Core\CoreFactory;
use MichielRoos\H5p\Adapter\Core\FileStorage;
use MichielRoos\H5p\Adapter\Core\Framework;
use MichielRoos\H5p\Domain\Model\Content;
use MichielRoos\H5p\Domain\Repository\ContentRepository;
use MichielRoos\H5p\Domain\Repository\ContentResultRepository;
use MichielRoos\H5p\Domain\Repository\PageRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Class ViewController
 */
class ViewController extends ActionController
{
    /**
     * Content repository
     *
     * @var ContentRepository
     */
    protected ContentRepository $contentRepository;

    /**
     * Content result repository
     *
     * @var ContentResultRepository
     */
    protected ContentResultRepository $contentResultRepository;

    /**
     * @var ContentObjectRenderer
     */
    private ContentObjectRenderer $contentObjectRenderer;

    /**
     * @var Framework
     */
    private Framework $h5pFramework;

    /**
     * @var PageRenderer
     */
    private PageRenderer $pageRenderer;

    /**
     * @var string
     */
    private string $language;

    /**
     * @var FileStorage
     */
    private FileStorage $h5pFileStorage;

    /**
     * @var CoreFactory
     */
    private CoreFactory $h5pCore;
    private LanguageServiceFactory $languageServiceFactory;

    public function __construct(
        ContentRepository $contentRepository,
        ContentResultRepository $contentResultRepository
    ) {
        $this->contentRepository = $contentRepository;
        $this->contentResultRepository = $contentResultRepository;
    }

    public function injectLanguageServiceFactory(LanguageServiceFactory $languageServiceFactory): void
    {
        $this->languageServiceFactory = $languageServiceFactory;
    }

    /**
     * Init
     */
    public function initializeAction(): void
    {
        $this->contentObjectRenderer = $this->request->getAttribute('currentContentObject');

        $this->language = ($this->getLanguageService()->lang === 'default') ? 'en' : $this->getLanguageService()->lang;

        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);

        $this->language = ($this->getLanguageService()->lang === 'default') ? 'en' : $this->getLanguageService()->lang;

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();
        $this->h5pFramework = GeneralUtility::makeInstance(Framework::class);
        $this->h5pFramework->setStorage($storage); // Storage nachträglich setzen
        $this->h5pFileStorage = GeneralUtility::makeInstance(FileStorage::class, $storage);
        $this->h5pCore = GeneralUtility::makeInstance(CoreFactory::class, $this->h5pFramework, $this->h5pFileStorage, $this->language);

        parent::initializeAction();
    }

    /**
     * Returns an instance of LanguageService
     *
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        if (!isset($this->languageServiceFactory)) {
            $this->languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        }

        $language = $this->request?->getAttribute('language')
            ?? $this->request?->getAttribute('site')?->getDefaultLanguage()
            ?? throw new \RuntimeException('SiteLanguage not available');
        return $this->languageServiceFactory->createFromSiteLanguage($language);
    }

    /**
     * Embedded action
     * @param int $contentId
     * @return ResponseInterface
     * @throws InvalidFileException
     */
    public function embeddedAction(int $contentId): ResponseInterface
    {

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();

        $this->h5pFramework = GeneralUtility::makeInstance(Framework::class);
        $this->h5pFramework->setStorage($storage); // Storage nachträglich setzen
        $this->h5pFileStorage = GeneralUtility::makeInstance(FileStorage::class, $storage);
        $this->h5pCore = GeneralUtility::makeInstance(CoreFactory::class, $this->h5pFramework, $this->h5pFileStorage, $this->language);

        $relativeCorePath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');

        foreach (H5PCore::$scripts as $script) {
            $this->pageRenderer->addJsFooterFile($relativeCorePath . $script, 'text/javascript', false, false, '', true);
        }
        foreach (H5PCore::$styles as $style) {
            $this->pageRenderer->addCssFile($relativeCorePath . $style);
        }

        /** @var Content $content */
        $content = $this->contentRepository->findByUid($contentId);

        if (!$content) {
            $this->view->assign('contentNotFound', true);
            return $this->htmlResponse(null);
        }

        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration',
            'H5PIntegration = ' . json_encode($this->getCoreSettings()) . ';', false, true, true
        );


        $contentSettings = $this->getContentSettings($content);
        $contentSettings['displayOptions'] = [];
        $contentSettings['displayOptions']['frame'] = $this->request->hasArgument('frame') && (bool)$this->request->getArgument('frame');
        //$contentSettings['displayOptions']['export'] = \H5PCore::DISABLE_DOWNLOAD;
        $contentSettings['displayOptions']['embed'] = $this->request->hasArgument('embed') && (bool)$this->request->getArgument('embed');
        $contentSettings['displayOptions']['copyright'] = $this->request->hasArgument('copyright') && (bool)$this->request->getArgument('copyright');
        $contentSettings['displayOptions']['icon'] = $this->request->hasArgument('icon') && (bool)$this->request->getArgument('icon');
        $this->pageRenderer->addJsInlineCode(
            'H5PIntegration contents cid-' . $content->getUid(),
            'H5PIntegration.contents[\'cid-' . $content->getUid() . '\'] = ' . json_encode($contentSettings) . ';', false, false, true
        );

        // load JS and CSS requirements
        $contentLibrary = $content->getLibrary()->toAssocArray();

        // JS and CSS required by all libraries
        $contentLibraryWithDependencies = $this->h5pCore->loadLibrary($contentLibrary['machineName'], $contentLibrary['majorVersion'], $contentLibrary['minorVersion']);
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
        $contentDependencies = $this->h5pFramework->loadContentDependencies($contentId, 'preloaded');
        foreach ($contentDependencies as $dependency) {
            $this->loadJsAndCss($dependency);
        }

        // JS and CSS required by the main Library of the content
        $this->loadJsAndCss($contentLibrary);


        $this->view->assign('content', $content);

        $this->pageRenderer->setTitle($content->getTitle());
        return $this->htmlResponse();
    }


    /**
     * Index action
     * @throws InvalidFileException
     */
    public function indexAction(): ResponseInterface
    {
        $data = $this->contentObjectRenderer->data;
        /** @var Content $content */
        $content = $this->contentRepository->findByUid($data['tx_h5p_content']);
        if (!$content) {
            $this->view->assign('contentNotFound', true);
            return $this->htmlResponse(null);
        }

        if (!$content instanceof Content) {
            $this->addFlashMessage(sprintf('Content element with id %d not found', $data['tx_h5p_content']), 'Record not found', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('error');
        }

        if (!$content->getLibrary()) {
            $this->addFlashMessage('Content element has no H5P library', 'H5P library not found on content', ContextualFeedbackSeverity::ERROR);
            return new ForwardResponse('error');
        }

        $cacheBuster = '?v=' . Framework::$version;

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
            'H5PIntegration contents cid-' . $content->getUid(),
            'H5PIntegration.contents[\'cid-' . $content->getUid() . '\'] = ' . json_encode($contentSettings) . ';', false, false, true
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

        $this->view->assign('content', $content);
        return $this->htmlResponse();
    }

    /**
     * Statistics action
     */
    public function statisticsAction(): ResponseInterface
    {
        if (!$GLOBALS['TSFE']->loginUser) {
            $this->view->assign('notLoggedIn', true);
            return $this->htmlResponse(null);
        }

        $user = $this->request->getAttribute('frontend.user')->user;

        $statistics = $this->contentResultRepository->findBy(['user' => (int)$user['uid']]);
        if (!$statistics) {
            $this->view->assign('statisticsNotFound', true);
            return $this->htmlResponse(null);
        }

        $pageIds = [];
        if (count($statistics)) {
            foreach ($statistics as $item) {
                $pageIds[$item->getPid()] = $item->getPid();
            }
        }

        if (!count($pageIds)) {
            $this->view->assign('statisticsNotFound', true);
            return $this->htmlResponse(null);
        }

        $statisticsByPage = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        $pages = $pageRepository->findByUids($pageIds);
        foreach ($pages as $page) {
            $statisticsByPage[$page->getUid()] = [
                'page' => $page,
                'statistics' => []
            ];
            foreach ($statistics as $item) {
                if ($item->getPid() === $page->getUid()) {
                    $statisticsByPage[$page->getUid()]['statistics'][] = $item;
                }
            }
        }

        $this->view->assign('dateFormat', $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy']);
        $this->view->assign('timeFormat', $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm']);
        $this->view->assign('statisticsByPage', $statisticsByPage);
        return $this->htmlResponse();
    }

    /**
     * Get generic h5p settings
     *
     * @return array;
     * @throws InvalidFileException
     */
    public function getCoreSettings(): array
    {
        $absoluteWebPath = PathUtility::getPublicResourceWebPath('EXT:h5p/Resources/Public/Lib/h5p-core/');

        $ajaxSetFinishedUri = $this->uriBuilder->reset()
            ->setArguments(['type' => 1561098634614])
            ->setCreateAbsoluteUri(true)
            ->uriFor('finish', [], 'Ajax', 'H5p', 'ajax');

        $url = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');

        $cacheBuster = '?v=' . Framework::$version;

        $settings = [
            'baseUrl' => $url,
            'url' => '/fileadmin/h5p',
            'postUserStatistics' => false,
            'ajax' => [
                'setFinished' => $ajaxSetFinishedUri,
                'contentUserData' => '',
            ],
            'saveFreq' => $this->h5pFramework->getOption('save_content_state') ? $this->h5pFramework->getOption('save_content_frequency') : false,
            'siteUrl' => $url,
            'l10n' => [
                'H5P' => $this->h5pCore->getLocalization(),
            ],
            'hubIsEnabled' => (int)$this->h5pFramework->getOption('hub_is_enabled') === 1,
            'reportingIsEnabled' => (int)$this->h5pFramework->getOption('enable_lrs_content_types') === 1,
            'libraryConfig' => $this->h5pFramework->getLibraryConfig(),
            'crossorigin' => defined('H5P_CROSSORIGIN') ? H5P_CROSSORIGIN : null,
            'pluginCacheBuster' => $cacheBuster,
            'libraryUrl' => $url . $absoluteWebPath . 'js',
            'contents' => []
        ];

        $frontendUser = $this->request->getAttribute('frontend.user');

        if ($frontendUser && !empty($frontendUser->user['uid'])) {
            $user = $frontendUser->user;

            $name = $user['first_name'];
            if (!empty($user['middle_name'])) {
                $name .= ' ' . $user['middle_name'];
            }
            if (!empty($user['last_name'])) {
                $name .= ' ' . $user['last_name'];
            }

            $settings['user'] = [
                'name' => $name,
                'mail' => $user['email']
            ];

            $settings['postUserStatistics'] =
                $this->h5pFramework->getOption('track_user') && (bool)$user['uid'];
        }

        foreach (H5PCore::$styles as $style) {
            $settings['core']['styles'][] = $absoluteWebPath . $style . $cacheBuster;
        }
        foreach (H5PCore::$scripts as $script) {
            $settings['core']['scripts'][] = $absoluteWebPath . $script . $cacheBuster;
        }
        $settings['loadedJs'] = [];
        $settings['loadedCss'] = [];

        return $settings;
    }

    /**
     * Get content settings
     *
     * @param Content $content
     * @return array;
     */
    public function getContentSettings(Content $content)
    {

        $embeddedUrl = GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . 'h5p/embed/' . $content->getUid();
        //$embeddedUrl = $this->uriBuilder->setTargetPageType(723442)->setArguments(['tx_h5p_embedded' => ['contentId' => $content->getUid()]])->setCreateAbsoluteUri(true)->buildFrontendUri();
        $embeddedUrl = '<iframe src="' . $embeddedUrl . '" width=":w" height=":h" frameborder="0" allowfullscreen="allowfullscreen" allow="geolocation *; microphone *; camera *; midi *; encrypted-media *" title="' . $content->getTitle() . '"></iframe>';

        $uriPrefix = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . $GLOBALS['TSFE']->absRefPrefix;
        $resizeCodeUrl = $uriPrefix . 'typo3conf/ext/h5p/Resources/Public/JavaScript/h5p-resizer.js';
        $resizeCodeUrl = '<script src="' . $resizeCodeUrl . '" charset="UTF-8"></script>';

        $settings = [
            'url' => '/fileadmin/h5p',
            'library' => sprintf(
                '%s %d.%d.%d',
                $content->getLibrary()->getMachineName(),
                $content->getLibrary()->getMajorVersion(),
                $content->getLibrary()->getMinorVersion(),
                $content->getLibrary()->getPatchVersion()
            ),
            'jsonContent' => $content->getFiltered(),
            'fullScreen' => false,
            'exportUrl' => '/path/to/download.h5p',
            'embedCode' => $embeddedUrl,
            'resizeCode' => $resizeCodeUrl,
            'mainId' => $content->getUid(),
            'title' => $content->getTitle(),
            'displayOptions' => [
                'frame' => false,
                'export' => false,
                'embed' => false,
                'copyright' => false,
                'icon' => false
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
        $cacheBuster = '?v=' . Framework::$version;

        if (!array_key_exists('scripts', $settings)) {
            $settings['scripts'] = [];
        }

        if (!array_key_exists('styles', $settings)) {
            $settings['styles'] = [];
        }

        foreach ($preloadJs as $js) {
            $js = trim($js);
            if ($js) {
                $settings['scripts'][] = '/fileadmin/h5p/libraries/' . $name . '/' . $js . $cacheBuster;
            }
        }
        foreach ($preloadCss as $css) {
            $css = trim($css);
            if ($css) {
                $settings['styles'][] = '/fileadmin/h5p/libraries/' . $name . '/' . $css . $cacheBuster;
            }
        }
    }

    /**
     * Load JS and CSS
     * @param array $library
     */
    private function loadJsAndCss($library): void
    {
        $name = $library['machineName'] . '-' . $library['majorVersion'] . '.' . $library['minorVersion'];
        $preloadCss = explode(',', $library['preloadedCss']);
        $preloadJs = explode(',', $library['preloadedJs']);

        foreach ($preloadJs as $js) {
            $js = trim($js);
            if ($js) {
                $this->pageRenderer->addJsFooterFile('/fileadmin/h5p/libraries/' . $name . '/' . $js, 'text/javascript', false, false, '', true);
            }
        }
        foreach ($preloadCss as $css) {
            $css = trim($css);
            if ($css) {
                $this->pageRenderer->addCssFile('/fileadmin/h5p/libraries/' . $name . '/' . $css);
            }
        }
    }
}
