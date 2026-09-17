<?php

declare(strict_types=1);

namespace MichielRoos\H5p\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Findet mehrfach vorhandene H5P-Inhalte und zeigt, welche davon niemand benutzt.
 *
 * Hintergrund: Bis zur Behebung der Namenskollision beim Feld "action" legte jedes
 * Speichern aus der Bearbeiten-Maske eine Kopie an, statt den Inhalt zu aendern.
 * Dieses Kommando macht sichtbar, was sich dabei angesammelt hat.
 *
 * Bewusst NUR lesend: Welche Fassung die richtige ist, kann nur die Redaktion
 * entscheiden. Geloescht wird ueber das Backend-Modul.
 */
#[AsCommand(
    name: 'h5p:find-duplicates',
    description: 'Listet mehrfach vorhandene H5P-Inhalte samt Verwendung auf (nur lesend).'
)]
final class FindDuplicateContentCommand extends Command
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'nur-ungenutzt',
            null,
            InputOption::VALUE_NONE,
            'Nur Gruppen zeigen, in denen mindestens eine Fassung nirgends eingebunden ist.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $nurUngenutzt = (bool)$input->getOption('nur-ungenutzt');

        $io->title('Mehrfach vorhandene H5P-Inhalte');

        $gruppen = $this->sammleGruppen();
        if ($gruppen === []) {
            $io->success('Keine Dubletten gefunden.');
            return Command::SUCCESS;
        }

        $verwendung = $this->zaehleVerwendung();

        $gezeigt = 0;
        $ungenutzteFassungen = 0;

        foreach ($gruppen as $titel => $fassungen) {
            $zeilen = [];
            $gruppeHatUngenutzte = false;

            foreach ($fassungen as $f) {
                $anzahl = $verwendung[(int)$f['uid']] ?? 0;
                if ($anzahl === 0) {
                    $gruppeHatUngenutzte = true;
                    $ungenutzteFassungen++;
                }
                $zeilen[] = [
                    $f['uid'],
                    $f['slug'],
                    date('Y-m-d', (int)$f['crdate']),
                    date('Y-m-d', (int)$f['tstamp']),
                    $anzahl === 0 ? 'nirgends' : $anzahl . '×',
                ];
            }

            if ($nurUngenutzt && !$gruppeHatUngenutzte) {
                continue;
            }

            $io->section(sprintf('%s (%d Fassungen)', $titel, count($fassungen)));
            $io->table(['uid', 'Slug', 'angelegt', 'geändert', 'eingebunden'], $zeilen);
            $gezeigt++;
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '%d Gruppen mit Dubletten, %d Fassungen insgesamt, davon %d nirgends eingebunden.',
            count($gruppen),
            array_sum(array_map('count', $gruppen)),
            $ungenutzteFassungen
        ));
        if ($nurUngenutzt) {
            $io->writeln(sprintf('Angezeigt: %d Gruppen mit mindestens einer ungenutzten Fassung.', $gezeigt));
        }
        $io->newLine();
        $io->note(
            '"eingebunden" zählt Content-Elemente, die über tx_h5p_content darauf zeigen. '
            . '"nirgends" heißt NICHT automatisch überflüssig: Der Inhalt kann verlinkt oder '
            . 'als Vorlage gedacht sein. Gelöscht wird im Backend-Modul, nicht hier.'
        );

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{uid: int, slug: string, crdate: int, tstamp: int}>>
     */
    private function sammleGruppen(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_h5p_domain_model_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'title', 'slug', 'crdate', 'tstamp')
            ->from('tx_h5p_domain_model_content')
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $nachTitel = [];
        foreach ($rows as $row) {
            $nachTitel[(string)$row['title']][] = $row;
        }

        return array_filter($nachTitel, static fn(array $f): bool => count($f) > 1);
    }

    /**
     * Wie oft ist ein Inhalt in tt_content eingebunden?
     *
     * @return array<int, int>
     */
    private function zaehleVerwendung(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('tx_h5p_content')
            ->addSelectLiteral('COUNT(*) AS anzahl')
            ->from('tt_content')
            ->where($queryBuilder->expr()->gt('tx_h5p_content', $queryBuilder->createNamedParameter(0)))
            ->groupBy('tx_h5p_content')
            ->executeQuery()
            ->fetchAllAssociative();

        $verwendung = [];
        foreach ($rows as $row) {
            $verwendung[(int)$row['tx_h5p_content']] = (int)$row['anzahl'];
        }

        return $verwendung;
    }
}
