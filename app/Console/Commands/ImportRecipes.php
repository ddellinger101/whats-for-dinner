<?php

namespace App\Console\Commands;

use App\Enums\CategoryTag;
use App\Enums\IngredientsStatus;
use App\Enums\MealType;
use App\Enums\ProteinType;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Spec 4.7: bring the source spreadsheet in with the fields it has (name,
 * protein, links, times_made) and ingredients_status = not_yet_added. Nothing
 * is blocked or hidden while ingredients are missing.
 */
class ImportRecipes extends Command
{
    protected $signature = 'recipes:import
        {path=storage/app/import/whats_for_dinner.xlsx}
        {--dry-run : Report what would be imported without writing}';

    protected $description = 'Import recipes from the source spreadsheet';

    /** Sheet name to protein type; the workbook is organised one sheet per protein. */
    private const SHEETS = [
        'Chicken' => ProteinType::Chicken,
        'Beef' => ProteinType::Beef,
        'Pork' => ProteinType::Pork,
        'Seafood' => ProteinType::Seafood,
        // The workbook's catch-all sheet; these dishes have no stated protein.
        'Other' => ProteinType::Vegetarian,
    ];

    public function handle(): int
    {
        $path = base_path($this->argument('path'));

        if (! is_file($path)) {
            $this->error("Spreadsheet not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $seen = [];
        $created = 0;
        $updated = 0;
        $duplicates = [];
        $ketoTagged = [];
        $noLink = 0;

        foreach (self::SHEETS as $sheetName => $protein) {
            $sheet = $book->getSheetByName($sheetName);

            if (! $sheet) {
                $this->warn("Sheet missing, skipped: {$sheetName}");

                continue;
            }

            $rows = $sheet->toArray(null, true, false, false);
            array_shift($rows); // header row

            foreach ($rows as $row) {
                $name = trim((string) ($row[0] ?? ''));

                if ($name === '') {
                    continue;
                }

                $key = Str::lower($name);

                if (isset($seen[$key])) {
                    $duplicates[] = "{$name} ({$seen[$key]} -> {$sheetName})";

                    continue;
                }

                $seen[$key] = $sheetName;

                $links = $this->parseLinks((string) ($row[2] ?? ''));

                if ($links === []) {
                    $noLink++;
                }

                // The workbook has no keto column, so infer it from the dish name
                // and link slugs. Reported below for review; it is only a starting
                // value and is editable per recipe.
                $isKeto = $this->looksKeto($name, $links);

                if ($isKeto) {
                    $ketoTagged[] = $name;
                }

                $attributes = [
                    'protein_type' => $protein,
                    'meal_type' => MealType::Dinner,
                    'times_made' => (int) ($row[1] ?? 0),
                    'recipe_links' => $links,
                    'is_keto' => $isKeto,
                    'category_tags' => $isKeto ? [CategoryTag::Keto->value] : [],
                    'ingredients_status' => IngredientsStatus::NotYetAdded,
                    'created_from_import' => true,
                    'source' => \App\Enums\RecipeSource::Spreadsheet,
                ];

                if ($dryRun) {
                    $created++;

                    continue;
                }

                $recipe = Recipe::where('name', $name)->first();

                if ($recipe) {
                    $recipe->update($attributes);
                    $updated++;
                } else {
                    Recipe::create($attributes + ['name' => $name]);
                    $created++;
                }
            }
        }

        // PhpSpreadsheet holds the whole workbook in memory; release it before
        // reporting so repeated runs in one process do not stack up.
        $book->disconnectWorksheets();
        unset($book, $reader);

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing written' : 'Import complete');
        $this->table(['Result', 'Count'], [
            ['Created', $created],
            ['Updated', $updated],
            ['No recipe link (manual entry needed)', $noLink],
            ['Tagged keto by inference', count($ketoTagged)],
            ['Duplicate names skipped', count($duplicates)],
        ]);

        if ($ketoTagged !== []) {
            $this->newLine();
            $this->line('Inferred keto — review these:');
            foreach ($ketoTagged as $n) {
                $this->line("  - {$n}");
            }
        }

        if ($duplicates !== []) {
            $this->newLine();
            $this->warn('Skipped duplicate dish names:');
            foreach ($duplicates as $d) {
                $this->line("  - {$d}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Spec 4.7: multiple links are semicolon-separated and stored as a list,
     * tried in order by the ingredient importer until one succeeds.
     *
     * @return list<string>
     */
    private function parseLinks(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(';', $raw)),
            fn ($link) => $link !== '' && Str::startsWith($link, ['http://', 'https://']),
        ));
    }

    /**
     * @param  list<string>  $links
     */
    private function looksKeto(string $name, array $links): bool
    {
        return Str::contains(Str::lower($name.' '.implode(' ', $links)), 'keto');
    }
}
