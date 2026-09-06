<?php

namespace App\Console\Commands;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Support\PantryStaples;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Puts the spice rack in the pantry and stops it reaching the grocery list.
 *
 * Two jobs, because the archive predates the rack. The jars themselves become
 * ingredient rows with a standing in-stock flag and no expiry. Separately,
 * every row already in the database that turns out to be one of those jars
 * under another name — "Ground cumin", "Dried thyme", "Red pepper flakes" —
 * is marked a staple too. Without that second pass those rows keep their
 * recipe links and go on generating grocery lines for spices already in the
 * house, which is the whole complaint.
 *
 * Alias rows are deliberately not merged into their canonical jar. Merging
 * means moving recipe pivots, use-by windows and grocery history, and the only
 * thing it would buy is a tidier ingredient table — the marking alone is what
 * stops the lines.
 */
class SyncPantryStaples extends Command
{
    protected $signature = 'pantry:staples
        {--dry-run : Show what would change without writing}';

    protected $description = 'Stock the spice rack in the pantry and keep it off the grocery list';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        $created = [];
        $adopted = [];
        $aliased = [];
        $stocked = 0;

        foreach (PantryStaples::all() as $staple) {
            $ingredient = $this->findByName($staple->name);

            // A phrase that is not a jar gets no row of its own here. If a
            // recipe ever names it the resolver will make one; conjuring an
            // ingredient attached to nothing helps nobody.
            if (! $ingredient && ! $staple->onRack) {
                continue;
            }

            if (! $ingredient) {
                $created[] = $staple->name;

                if (! $dryRun) {
                    $ingredient = Ingredient::create([
                        'name' => $staple->name,
                        'category' => IngredientCategory::PantryDry,
                        'shelf_life_days' => IngredientCategory::PantryDry->defaultShelfLifeDays(),
                        'is_staple' => true,
                    ]);
                }
            } elseif (! $ingredient->is_staple) {
                $adopted[] = $ingredient->name;

                if (! $dryRun) {
                    $ingredient->update(['is_staple' => true]);
                }
            }

            // A flag with no expiry is what puts it on the pantry screen
            // without ever landing in "use these up". Skipped for a phrase
            // that is not itself a jar, which would otherwise show on the rack
            // as a thing the household owns.
            if (! $dryRun && $ingredient && $staple->onRack) {
                InventoryFlag::updateOrCreate(
                    ['ingredient_id' => $ingredient->id],
                    [
                        'has_stock' => true,
                        'quantity' => null,
                        'acquired_on' => $today,
                        'expires_on' => null,
                        'last_updated' => now(),
                    ],
                );
            }

            $stocked += $staple->onRack ? 1 : 0;
        }

        $aliased = $this->markMatchingRows($dryRun);

        $this->report($dryRun, $created, $adopted, $aliased, $stocked);

        return self::SUCCESS;
    }

    /**
     * Rows the archive already holds that turn out to be a jar under another
     * name — "Ground cumin", "Dried thyme", "Kosher salt and fresh ground
     * black pepper".
     *
     * Every row is asked rather than each jar's aliases being looked up,
     * because salt and pepper are matched by rule rather than by a listed
     * alias and a name-driven query would miss all sixteen spellings of them.
     * A few hundred rows is nothing to walk.
     *
     * They keep their own identity and their recipe links; all they gain is
     * the standing that keeps them off the list.
     *
     * @return list<string>
     */
    private function markMatchingRows(bool $dryRun): array
    {
        $marked = [];

        foreach (Ingredient::where('is_staple', false)->orderBy('name')->get() as $row) {
            $staple = PantryStaples::match($row->name);

            // Anything called fresh returns null here, which is the rule that
            // keeps fresh herbs on the grocery list.
            if (! $staple) {
                continue;
            }

            $marked[] = "{$row->name} -> {$staple->name}";

            if (! $dryRun) {
                $row->update(['is_staple' => true]);
            }
        }

        return $marked;
    }

    private function findByName(string $name): ?Ingredient
    {
        return Ingredient::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * @param  list<string>  $created
     * @param  list<string>  $adopted
     * @param  list<string>  $aliased
     */
    private function report(bool $dryRun, array $created, array $adopted, array $aliased, int $stocked): void
    {
        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing written' : 'Spice rack synced');

        $this->table(['Result', 'Count'], [
            ['Jars on the rack', $stocked],
            ['New ingredient rows', count($created)],
            ['Existing rows adopted', count($adopted)],
            ['Other spellings marked', count($aliased)],
        ]);

        foreach ([
            'Created' => $created,
            'Adopted (already in the archive under the jar\'s own name)' => $adopted,
            'Marked as the same jar under another name' => $aliased,
        ] as $heading => $rows) {
            if ($rows === []) {
                continue;
            }

            $this->newLine();
            $this->line($heading.':');
            foreach ($rows as $row) {
                $this->line("  - {$row}");
            }
        }
    }
}
