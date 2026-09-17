<?php

namespace MichielRoos\H5p\Adapter\Core;


use H5PCore;
use H5PFileStorage;
use H5PFrameworkInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Throwable;

/**
 * Class CoreFactory
 */
class CoreFactory extends H5PCore implements SingletonInterface
{
    /**
     * Constructor for the H5PCore
     *
     * @param H5PFrameworkInterface $H5PFramework
     *  The frameworks implementation of the H5PFrameworkInterface
     * @param string|H5PFileStorage $path H5P file storage directory or class.
     * @param string $url To file storage directory.
     * @param string $language code. Defaults to english.
     * @param bool|null $export Export aktiviert? null = aus der Extension-Konfiguration lesen.
     */
    public function __construct(
        H5PFrameworkInterface $H5PFramework,
        H5PFileStorage $path,
        string $url = '',
        string $language = 'en',
        ?bool $export = null
    ) {
        parent::__construct($H5PFramework, $path, $url, $language, $export ?? self::isExportEnabled());
    }

    /**
     * Liest den Export-Schalter aus der Extension-Konfiguration (ext_conf_template.txt).
     *
     * Bewusst hier zentral und nicht an jeder der Aufrufstellen: der Export laeuft bei
     * JEDEM Speichern eines Inhalts und erzeugt dabei ein ZIP. Faellt das zu teuer aus,
     * soll er sich ohne Deployment wieder abschalten lassen.
     *
     * Default ist aus - nach dem Ausrollen bewusst einschalten.
     */
    private static function isExportEnabled(): bool
    {
        try {
            return (bool)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('h5p', 'enableExport');
        } catch (Throwable) {
            // Nicht konfiguriert oder Konfiguration nicht lesbar: Export bleibt aus.
            return false;
        }
    }

    /**
     * @param array $dependencies
     * @return array
     */
    public function orderDependenciesByWeight(array $dependencies): array
    {
        uasort($dependencies, static function ($a, $b) {
            if ($a['weight'] === $b['weight']) {
                return 0;
            }
            return ($a['weight'] > $b['weight']) ? 1 : -1;
        });

        return $dependencies;
    }
}
