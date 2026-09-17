<?php

namespace MichielRoos\H5p\Adapter\Editor;

use H5PEditorAjaxInterface;
use MichielRoos\H5p\Domain\Model\Library;
use MichielRoos\H5p\Domain\Model\LibraryTranslation;
use MichielRoos\H5p\Domain\Repository\ContentTypeCacheEntryRepository;
use MichielRoos\H5p\Domain\Repository\LibraryRepository;
use MichielRoos\H5p\Domain\Repository\LibraryTranslationRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

class EditorAjax implements H5PEditorAjaxInterface
{
    /**
     * @var LibraryRepository
     */
    protected $libraryRepository;

    /**
     * @var ContentTypeCacheEntryRepository
     */
    protected $contentTypeCacheEntryRepository;

    /**
     * @var LibraryTranslationRepository|object
     */
    private $libraryTranslationRepository;

    /**
     * EditorAjax constructor.
     */
    public function __construct()
    {
        $this->libraryRepository = GeneralUtility::makeInstance(LibraryRepository::class);
        $this->libraryTranslationRepository = GeneralUtility::makeInstance(LibraryTranslationRepository::class);
        $this->contentTypeCacheEntryRepository = GeneralUtility::makeInstance(ContentTypeCacheEntryRepository::class);
    }

    /**
     * Gets latest library versions that exists locally
     *
     * @return array Latest version of all local libraries
     */
    public function getLatestLibraryVersions()
    {
        $this->libraryRepository->setDefaultOrderings([
            'title'        => QueryInterface::ORDER_DESCENDING,
            'majorVersion' => QueryInterface::ORDER_DESCENDING,
            'minorVersion' => QueryInterface::ORDER_DESCENDING
        ]);

        $librariesOrderedByMajorAndMinorVersion = $this->libraryRepository->findBy(['runnable' => 1]);

        $versionInformation = [];
        /** @var Library $library */
        foreach ($librariesOrderedByMajorAndMinorVersion as $library) {
            $title = $library->getTitle();
            if (array_key_exists($title, $versionInformation)) {
                continue;
            }
            $versionInformation[$title] = (object)[
                'id'            => $library->getUid(),
                'machine_name'  => $library->getMachineName(),
                'title'         => $title,
                'major_version' => $library->getMajorVersion(),
                'minor_version' => $library->getMinorVersion(),
                'patch_version' => $library->getPatchVersion(),
                'patch_version_in_folder_name' => $library->getPatchVersion(),
                'restricted'    => $library->isRestricted(),
                'has_icon'      => $library->isHasIcon()
            ];
        }

        return $versionInformation;
    }

    /**
     * Get locally stored Content Type Cache. If machine name is provided
     * it will only get the given content type from the cache
     *
     * @param $machineName
     *
     * @return array|object|null Returns results from querying the database
     */
    public function getContentTypeCache($machineName = NULL)
    {
        if ($machineName != null) {
            return $this->contentTypeCacheEntryRepository->findOneBy(['machineName' => $machineName]);
        }

        return $this->contentTypeCacheEntryRepository->getContentTypeCacheObjects();
    }

    /**
     * Gets recently used libraries for the current author
     *
     * @return array machine names. The first element in the array is the
     * most recently used.
     */
    public function getAuthorsRecentlyUsedLibraries()
    {
        // TODO: Implement getAuthorsRecentlyUsedLibraries() method.
        return [];
    }

    /**
     * Checks if the provided token is valid for this endpoint
     *
     * @param string $token The token that will be validated for.
     *
     * @return bool True if successful validation
     */
    public function validateEditorToken($token)
    {
        // TODO
        return true;
    }

    /**
     * Get translations for a language for a list of libraries
     *
     * Sammel-Endpunkt des Editors: Beim Umschalten der Inhaltssprache fragt
     * h5peditor-form.js alle noch nicht zwischengespeicherten Bibliotheken auf
     * einmal ab (Endpunkt "translations").
     *
     * Erwartetes Rueckgabeformat, abgeleitet aus dem Konsumenten
     * vendor/h5p/h5p-editor/scripts/h5peditor-form.js:
     *
     *     for (let lib in res.data) {
     *         ns.libraryCache[lib].translation[lang] = JSON.parse(res.data[lib]).semantics;
     *     }
     *
     * Daraus folgt:
     * - Der Schluessel muss exakt der hereingereichte Uber-Name sein, sonst
     *   findet das JS seinen libraryCache-Eintrag nicht.
     * - Der Wert ist der ROHE JSON-String der Sprachdatei, nicht dekodiert -
     *   das JSON.parse() macht der Editor selbst.
     * - Bibliotheken ohne Uebersetzung werden weggelassen statt mit null
     *   geliefert, sonst liefe JSON.parse(null) auf der Gegenseite auf.
     *
     * @param array $libraries An array of libraries, in the form "<machineName> <majorVersion>.<minorVersion>
     * @param string $language_code
     * @return array
     */
    public function getTranslations($libraries, $language_code)
    {
        $translations = [];

        foreach ((array)$libraries as $libraryString) {
            $libraryString = (string)$libraryString;

            if (!preg_match('/^(\S+)\s+(\d+)\.(\d+)$/', trim($libraryString), $matches)) {
                continue;
            }

            $library = $this->libraryRepository->findOneByMachinenameMajorVersionAndMinorVersion(
                $matches[1],
                (int)$matches[2],
                (int)$matches[3]
            );
            if (!$library instanceof Library) {
                continue;
            }

            $translation = $this->libraryTranslationRepository->findOneByLibraryAndLanguage(
                $library,
                $language_code
            );
            if (!$translation instanceof LibraryTranslation) {
                continue;
            }

            // Schluessel bewusst unveraendert uebernehmen (siehe Docblock).
            $translations[$libraryString] = $translation->getTranslation();
        }

        return $translations;
    }
}
