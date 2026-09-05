# What's For Dinner

A private meal-planning app for one household. Plan the week's meals on a Sunday,
get recipe suggestions that use up perishables already in the fridge, keep a
grocery list that builds itself, and track what was worth cooking again.

The full behavioural specification lives in [SPEC.md](SPEC.md); it is the source
of truth, and section references throughout the code point back to it.

## Stack

| | |
|---|---|
| Framework | Laravel 13 on PHP 8.3 |
| Database | SQLite locally, MySQL in production |
| Frontend | Blade + Tailwind CSS 4, built with Vite |
| Hosting | Cloudways, served at `chef.dustindellinger.com` |

Mobile-first by requirement, not by preference: the app is used standing in a
kitchen, so touch targets and layout are designed for a phone and scaled up.

## Local setup

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

`.env` needs Google OAuth credentials for the calendar push (section 4.8). They
are never committed.

## Importing the recipe archive

The source spreadsheet holds 143 dishes across five per-protein sheets. It is
personal data and is not committed; place it at
`storage/app/import/whats_for_dinner.xlsx`.

```sh
php artisan recipes:import --dry-run   # report without writing
php artisan recipes:import             # idempotent; safe to re-run
php artisan recipes:inspect            # dump the workbook's structure
```

Recipes import immediately with `ingredients_status = not_yet_added`, so nothing
is blocked or hidden while ingredients are still missing (section 4.7).

## Tests

```sh
php artisan test
```

The importer tests skip themselves when the source spreadsheet is absent.

## Notes on data

- Two tables are not named in section 3 but are required by the business logic:
  `ingredient_use_by_windows` (section 4.1 needs a per-ingredient, per-week
  window with an editable purchase date) and `household_settings` (the "kids
  this weekend" toggle, shopping day, and active diet mode).
- The workbook has no keto column, so `is_keto` is inferred from dish names and
  link slugs on import. Treat those as starting values to review, not as fact.
- Recipe images are outside the original spec: either scraped from the recipe
  link or photographed in the kitchen. A photo you took is never overwritten by
  a later scrape.
