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
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Entfernt Ordner unter fileadmin/h5p, zu denen es keinen Datensatz mehr gibt.
 *
 * Hintergrund: Bis zum Loeschweg im Backend-Modul gab es keinen Weg, H5P-Inhalte
 * samt Dateien zu entfernen. Wurde ein Datensatz ueber das TYPO3-Listenmodul
 * geloescht, blieb sein Ordner liegen. Dieses Kommando raeumt genau solche
 * Altlasten auf - einmalig, nicht als Dauerlauf gedacht.
 *
 * Ohne --force wird NUR aufgelistet. Das ist Absicht: Der Bestand unter
 * fileadmin/h5p ist zweistellig gross, ein Fehlgriff waere teuer.
 */
#[AsCommand(
    name: 'h5p:cleanup-orphaned-files',
    description: 'Listet verwaiste H5P-Ordner in fileadmin/h5p auf und entfernt sie auf Wunsch.'
)]
final class CleanupOrphanedFilesCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Tatsaechlich loeschen. Ohne diese Option wird nur aufgelistet.'
            )
            ->addOption(
                'include-deleted',
                null,
                InputOption::VALUE_NONE,
                'Auch Ordner von weich geloeschten Datensaetzen entfernen. Achtung: '
                . 'solche Datensaetze lassen sich aus dem Papierkorb wiederherstellen, '
                . 'ihre Dateien danach nicht mehr.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool)$input->getOption('force');
        $includeDeleted = (bool)$input->getOption('include-deleted');

        $storage = $this->resourceFactory->getDefaultStorage();
        if (!$storage instanceof ResourceStorage) {
            $io->error('Kein Default-Storage gefunden.');
            return Command::FAILURE;
        }

        $io->title('Verwaiste H5P-Dateien');
        $io->writeln($force
            ? '<comment>Modus: LOESCHEN (--force)</comment>'
            : 'Modus: nur auflisten. Zum Loeschen --force anhaengen.');
        $io->newLine();

        $befunde = array_merge(
            $this->pruefeInhalte($storage, $includeDeleted),
            $this->pruefeBibliotheken($storage)
        );

        if ($befunde === []) {
            $io->success('Nichts zu tun: keine verwaisten Ordner gefunden.');
            return Command::SUCCESS;
        }

        $zuLoeschen = array_values(array_filter($befunde, static fn(array $b): bool => $b['loeschbar']));
        $uebersprungen = array_values(array_filter($befunde, static fn(array $b): bool => !$b['loeschbar']));

        if ($uebersprungen !== []) {
            $io->section('Wird NICHT angefasst');
            $io->table(
                ['Ordner', 'Groesse', 'Grund'],
                array_map(static fn(array $b): array => [$b['pfad'], $b['groesse'], $b['grund']], $uebersprungen)
            );
        }

        if ($zuLoeschen === []) {
            $io->success('Keine loeschbaren Ordner gefunden.');
            return Command::SUCCESS;
        }

        $io->section($force ? 'Wird geloescht' : 'Waere zu loeschen');
        $io->table(
            ['Ordner', 'Groesse', 'Grund'],
            array_map(static fn(array $b): array => [$b['pfad'], $b['groesse'], $b['grund']], $zuLoeschen)
        );
        $io->writeln(sprintf(
            '%d Ordner, zusammen %s',
            count($zuLoeschen),
            $this->formatiereBytes((int)array_sum(array_column($zuLoeschen, 'bytes')))
        ));
        $io->newLine();

        if (!$force) {
            $io->note('Es wurde nichts veraendert. Mit --force ausfuehren, um zu loeschen.');
            return Command::SUCCESS;
        }

        $geloescht = 0;
        foreach ($zuLoeschen as $befund) {
            try {
                $befund['ordner']->delete(true);
                $geloescht++;
                $io->writeln(sprintf('  geloescht: %s', $befund['pfad']));
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s konnte nicht geloescht werden: %s', $befund['pfad'], $e->getMessage()));
            }
        }

        $io->success(sprintf('%d von %d Ordnern entfernt.', $geloescht, count($zuLoeschen)));

        return $geloescht === count($zuLoeschen) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Inhaltsordner: der Ordnername ist die uid des Datensatzes.
     *
     * @return list<array{pfad: string, ordner: Folder, bytes: int, groesse: string, loeschbar: bool, grund: string}>
     */
    private function pruefeInhalte(ResourceStorage $storage, bool $includeDeleted): array
    {
        $rows = $this->connectionPool->getConnectionForTable('tx_h5p_domain_model_content')
            ->executeQuery('SELECT uid, deleted FROM tx_h5p_domain_model_content')
            ->fetchAllAssociative();

        $vorhanden = [];
        foreach ($rows as $row) {
            $vorhanden[(int)$row['uid']] = (int)$row['deleted'] === 1;
        }

        $befunde = [];
        foreach ($this->unterordner($storage, 'h5p/content/') as $ordner) {
            $name = $ordner->getName();

            if (!ctype_digit($name)) {
                $befunde[] = $this->befund($ordner, false, 'kein numerischer Ordnername – von Hand pruefen');
                continue;
            }

            $uid = (int)$name;
            if (!array_key_exists($uid, $vorhanden)) {
                $befunde[] = $this->befund($ordner, true, 'kein Datensatz mit dieser uid');
                continue;
            }

            if ($vorhanden[$uid] === true) {
                $befunde[] = $this->befund(
                    $ordner,
                    $includeDeleted,
                    $includeDeleted
                        ? 'Datensatz weich geloescht (--include-deleted)'
                        : 'Datensatz nur weich geloescht – wiederherstellbar, daher uebersprungen'
                );
            }
        }

        return $befunde;
    }

    /**
     * Bibliotheksordner heissen <machineName>-<major>.<minor>.
     *
     * @return list<array{pfad: string, ordner: Folder, bytes: int, groesse: string, loeschbar: bool, grund: string}>
     */
    private function pruefeBibliotheken(ResourceStorage $storage): array
    {
        $rows = $this->connectionPool->getConnectionForTable('tx_h5p_domain_model_library')
            ->executeQuery('SELECT machine_name, major_version, minor_version FROM tx_h5p_domain_model_library WHERE deleted = 0')
            ->fetchAllAssociative();

        $vorhanden = [];
        foreach ($rows as $row) {
            $vorhanden[$row['machine_name'] . '-' . $row['major_version'] . '.' . $row['minor_version']] = true;
        }

        $befunde = [];
        foreach ($this->unterordner($storage, 'h5p/libraries/') as $ordner) {
            if (!isset($vorhanden[$ordner->getName()])) {
                $befunde[] = $this->befund($ordner, true, 'keine Bibliothek mit diesem Namen');
            }
        }

        return $befunde;
    }

    /**
     * @return list<Folder>
     */
    private function unterordner(ResourceStorage $storage, string $pfad): array
    {
        if (!$storage->hasFolder($pfad)) {
            return [];
        }

        return array_values($storage->getFolder($pfad)->getSubfolders());
    }

    /**
     * @return array{pfad: string, ordner: Folder, bytes: int, groesse: string, loeschbar: bool, grund: string}
     */
    private function befund(Folder $ordner, bool $loeschbar, string $grund): array
    {
        $bytes = $this->ordnerGroesse($ordner);

        return [
            'pfad'      => $ordner->getIdentifier(),
            'ordner'    => $ordner,
            'bytes'     => $bytes,
            'groesse'   => $this->formatiereBytes($bytes),
            'loeschbar' => $loeschbar,
            'grund'     => $grund,
        ];
    }

    private function ordnerGroesse(Folder $ordner): int
    {
        $summe = 0;
        foreach ($ordner->getFiles() as $datei) {
            $summe += (int)$datei->getSize();
        }
        foreach ($ordner->getSubfolders() as $unterordner) {
            $summe += $this->ordnerGroesse($unterordner);
        }

        return $summe;
    }

    private function formatiereBytes(int $bytes): string
    {
        $wert = (float)$bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $einheit) {
            if ($wert < 1024 || $einheit === 'GB') {
                return $einheit === 'B'
                    ? sprintf('%d B', (int)$wert)
                    : sprintf('%.1f %s', $wert, $einheit);
            }
            $wert /= 1024;
        }

        return sprintf('%d B', $bytes);
    }
}
