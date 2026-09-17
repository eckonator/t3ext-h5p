<?php

declare(strict_types=1);

namespace MichielRoos\H5p\Command;

use H5PExport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use MichielRoos\H5p\Adapter\Core\CoreFactory;
use MichielRoos\H5p\Adapter\Core\FileStorage;
use MichielRoos\H5p\Adapter\Core\Framework;

/**
 * Erzeugt die .h5p-Exportdateien fuer vorhandene Inhalte.
 *
 * Der Export entsteht sonst nur beim Speichern eines Inhalts. Fuer den Bestand,
 * der seit dem Einschalten von "enableExport" nicht angefasst wurde, fehlt die
 * Datei - der Reuse-Knopf im Frontend liefe ins Leere. Dieses Kommando holt das
 * einmalig nach.
 */
#[AsCommand(
    name: 'h5p:generate-exports',
    description: 'Erzeugt fehlende .h5p-Exportdateien fuer vorhandene Inhalte.'
)]
final class GenerateExportsCommand extends Command
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('alle', null, InputOption::VALUE_NONE,
                'Auch vorhandene Exportdateien neu erzeugen, nicht nur fehlende.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'Nur so viele Inhalte bearbeiten - zum Antesten.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alle = (bool)$input->getOption('alle');
        $limit = (int)$input->getOption('limit');

        $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getDefaultStorage();
        $fileStorage = GeneralUtility::makeInstance(FileStorage::class, $storage);
        $framework = GeneralUtility::makeInstance(Framework::class);
        $framework->setStorage($storage);

        // Export hier bewusst erzwingen: Das Kommando wird ja gerade deshalb
        // aufgerufen. Ob der Export im Alltag laeuft, steuert enableExport.
        $core = new CoreFactory($framework, $fileStorage, '', 'de', true);
        $exporter = new H5PExport($framework, $core);

        $inhalte = $this->ladeInhalte($limit);
        $io->title(sprintf('Exportdateien erzeugen (%d Inhalte)', count($inhalte)));
        $io->writeln($alle ? 'Modus: alle neu erzeugen' : 'Modus: nur fehlende ergaenzen');
        $io->newLine();

        $erzeugt = $uebersprungen = $fehler = 0;
        $io->progressStart(count($inhalte));

        foreach ($inhalte as $row) {
            $io->progressAdvance();
            $dateiname = ($row['slug'] !== '' ? $row['slug'] . '-' : '') . $row['uid'] . '.h5p';

            if (!$alle && $fileStorage->hasExport($dateiname)) {
                $uebersprungen++;
                continue;
            }

            try {
                $ergebnis = $exporter->createExportFile($this->baueContentArray($row));
                if ($ergebnis === false) {
                    $fehler++;
                    continue;
                }
                $erzeugt++;
            } catch (\Throwable $e) {
                $fehler++;
                $io->newLine();
                $io->warning(sprintf('uid %d (%s): %s', $row['uid'], $row['title'], $e->getMessage()));
            }
        }

        $io->progressFinish();
        $io->writeln(sprintf('  erzeugt:       %d', $erzeugt));
        $io->writeln(sprintf('  uebersprungen: %d (Datei war schon da)', $uebersprungen));
        $io->writeln(sprintf('  fehlgeschlagen: %d', $fehler));

        if ($fehler > 0) {
            $io->warning('Nicht alle Exporte konnten erzeugt werden - siehe Meldungen oben.');
            return Command::FAILURE;
        }

        $io->success('Fertig.');
        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ladeInhalte(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_h5p_domain_model_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->select('uid', 'title', 'slug', 'parameters', 'filtered', 'embed_type', 'library')
            ->from('tx_h5p_domain_model_content')
            ->orderBy('uid');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Baut die Form, die H5PExport erwartet - dieselbe, die H5PCore::loadContent()
     * liefert. Die Abhaengigkeiten kommen aus contentdependency, so wie sie
     * filterParameters() beim Speichern hinterlegt hat.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function baueContentArray(array $row): array
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_h5p_domain_model_library');

        $library = $connection->executeQuery(
            'SELECT machine_name, major_version, minor_version, embed_types FROM tx_h5p_domain_model_library WHERE uid = ?',
            [(int)$row['library']]
        )->fetchAssociative() ?: ['machine_name' => '', 'major_version' => 0, 'minor_version' => 0, 'embed_types' => ''];

        $dependencies = $connection->executeQuery(
            'SELECT d.dependency_type, l.machine_name, l.major_version, l.minor_version
             FROM tx_h5p_domain_model_contentdependency d
             JOIN tx_h5p_domain_model_library l ON l.uid = d.library
             WHERE d.content = ? AND d.deleted = 0
             ORDER BY d.weight',
            [(int)$row['uid']]
        )->fetchAllAssociative();

        return [
            'id'         => (int)$row['uid'],
            'title'      => (string)$row['title'],
            'slug'       => (string)$row['slug'],
            'filtered'   => $row['filtered'] !== '' ? $row['filtered'] : $row['parameters'],
            'embedType'  => $row['embed_type'] !== '' ? $row['embed_type'] : 'div',
            'language'   => 'de',
            'metadata'   => ['license' => 'U'],
            'library'    => [
                'name'         => (string)$library['machine_name'],
                'majorVersion' => (int)$library['major_version'],
                'minorVersion' => (int)$library['minor_version'],
                'embedTypes'   => (string)$library['embed_types'],
            ],
            'dependencies' => array_map(
                static fn(array $d): array => [
                    'type'    => $d['dependency_type'],
                    'library' => [
                        'machineName'  => $d['machine_name'],
                        'majorVersion' => (int)$d['major_version'],
                        'minorVersion' => (int)$d['minor_version'],
                    ],
                ],
                $dependencies
            ),
        ];
    }
}
