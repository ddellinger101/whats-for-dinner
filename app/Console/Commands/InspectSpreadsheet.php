<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Throwaway reconnaissance for the spec 4.7 migration: shows what the source
 * workbook actually contains before the importer is written against it.
 */
class InspectSpreadsheet extends Command
{
    protected $signature = 'recipes:inspect {path=storage/app/import/whats_for_dinner.xlsx} {--rows=5}';

    protected $description = 'Dump the structure of the source recipe spreadsheet';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));

        if (! is_file($path)) {
            $this->error("Not found: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        foreach ($book->getSheetNames() as $name) {
            $sheet = $book->getSheetByName($name);
            $rows = $sheet->toArray(null, true, false, false);
            $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('strval', $r)) !== ''));

            $this->newLine();
            $this->line("=== {$name} === ".count($rows).' non-empty rows');

            foreach (array_slice($rows, 0, (int) $this->option('rows') + 1) as $i => $row) {
                $cells = array_map(
                    fn ($c) => $c === null ? '-' : mb_strimwidth(trim((string) $c), 0, 46, '...'),
                    $row,
                );
                $this->line(str_pad((string) $i, 3).'| '.implode(' | ', $cells));
            }
        }

        return self::SUCCESS;
    }
}
