# What's For Dinner — App Spec

## 1. Purpose

A meal-planning web app used primarily on Sundays to plan the week's meals, reduce food waste by suggesting recipes that use up perishable ingredients already in the fridge, manage a grocery list, and track a lightweight recipe rating history. Source data: an existing spreadsheet of ~150+ dishes organized by protein (Chicken, Beef, Pork, Seafood, Other), each with a name, times-made count, and (sometimes) a recipe link.

## 2. Design Requirements

- **Mobile-first, fully responsive.** This will mostly be used on phones and tablets (standing in the kitchen/at the table on a Sunday), with desktop as a secondary case. Layouts, tap targets, and the meal-slot/grocery-list interactions should be designed for touch first, then scale up gracefully to larger screens — not the other way around.
- **Sleek, modern visual style.** Clean typography, intentional spacing, a real design system (not default form-element styling) — this should feel like a polished consumer app, not an internal tool.

## 3. Data Model

### Recipe
| Field | Type | Notes |
|---|---|---|
| id | uuid | |
| name | string | |
| protein_type | enum | chicken, beef, pork, seafood, other, none |
| meal_type | enum | dinner, breakfast, lunch |
| category_tags | multi-select | Soup, Salad, Keto, Tapas, Comfort Food, Holiday, Grilling, Appetizer, Dessert |
| is_keto | boolean | drives diet-filter matching; may also be set via the Keto tag |
| recipe_links | array[string] | supports multiple links per recipe (semicolon-split on import) |
| base_servings | integer | servings the ingredient list is written for |
| rating | enum | thumbs_up, just_ok, thumbs_down, unrated |
| times_made | integer | migrated from spreadsheet, incremented going forward |
| ingredients_status | enum | not_yet_added, auto_imported, manually_entered |
| notes | text | free-form, used for "Just OK — here's how to improve it" notes |
| created_from_import | boolean | true for the 150 migrated rows |

### SimpleItem (new — for non-recipe meal components)
Covers things like "Turkey Sandwich," "grapes," "cucumber slices," "side salad" — items that get attached to a meal slot but don't have instructions, a protein type, or a rating.

| Field | Type | Notes |
|---|---|---|
| id | uuid | |
| name | string | e.g. "Turkey Sandwich," "Grapes" |
| grocery_breakdown | array[string] | optional list of underlying grocery items this name implies (see §4.5 for how this gets built) |
| meal_type_hint | enum | breakfast, lunch, dinner-side — just for sorting it into the right quick-pick list, not a hard rule |

### Ingredient (master list)
| Field | Type | Notes |
|---|---|---|
| id | uuid | |
| name | string | |
| category | enum | protein, dairy, produce, pantry_dry, jarred_canned, frozen, condiment |
| shelf_life_days | integer | typical days-good-for once purchased/opened; editable per ingredient |
| default_unit | string | e.g. lb, oz, cup, each |

### RecipeIngredient (junction)
- recipe_id, ingredient_id, quantity_per_serving, unit

### MealPlanEntry
Represents one slot (a day + breakfast/lunch/dinner). A slot can hold **multiple components** — e.g. Wednesday Dinner = [Tacos (recipe), Side Salad (simple item)]; Thursday Lunch = [Turkey Sandwich (simple item), Grapes (simple item), Cucumber Slices (simple item)].

- date, slot (breakfast/lunch/dinner), household_size_used
- **components: array of MealComponent**

### MealComponent
| Field | Type | Notes |
|---|---|---|
| id | uuid | |
| meal_plan_entry_id | uuid | parent slot |
| component_type | enum | recipe, simple_item |
| recipe_id | uuid, nullable | set when component_type = recipe |
| simple_item_id | uuid, nullable | set when component_type = simple_item |
| is_primary | boolean | true for the main dish (the recipe a dinner slot is "about"); false for sides/extras. Suggestion-engine logic (protein rotation, use-up boosting) only applies to primary components. |
| servings_needed | integer | inherited from the slot's household size, can be overridden per component |

### InventoryFlag
- ingredient_id, has_stock (boolean), note (e.g. "2 lbs ground beef in freezer"), last_updated
- **Scope for v1:** boolean flag only, not quantity tracking. If flagged true, the ingredient is skipped when auto-generating grocery list items. User manually clears the flag when stock runs out.

### GroceryListItem
- item_name, quantity, unit, source (auto_recipe / auto_simple_item / manual / repeater), status (needed/purchased), added_date, source_component_id (nullable, for traceability)

### RepeaterItem
- item_name, frequency_days, last_purchased_date, next_due_date (computed)

### WeeklyHouseholdSchedule
- day_of_week → servings_multiplier_context (kid days vs. non-kid days), editable since custody schedule can shift. Default: Mon/Tue = 2, Wed/Thu = 5, alternating weekends = 5, other weekend days = 2 (editable toggle for "kids this weekend").

## 4. Core Business Logic

### 4.1 Expiration / "use it up" tracking
- When a recipe is assigned as a **primary** component of a Meal Plan slot, every ingredient on that recipe is checked against the Ingredient master list for `shelf_life_days`.
- Perishable ingredients (category ≠ pantry_dry) get an implied **use-by window**: purchase date (defaults to the upcoming shopping day, editable) + shelf_life_days.
- This window is tracked per ingredient, per week, not per recipe — if two recipes both use sour cream, the window just extends/refreshes.
- **Scope of matching:** any ingredient with an open (not-yet-expired) use-by window from **anywhere earlier in the current week's plan** is eligible for matching — not just the immediately preceding day.
- Simple items don't participate in use-by tracking (no ingredient breakdown granular enough to be reliable) unless their `grocery_breakdown` happens to map to known perishable Ingredients — treat as a nice-to-have bonus match, not a requirement.

### 4.2 Suggestion ranking (used whenever a *primary* dinner slot is being filled)
Recipes are filtered and ranked in this order:
1. **Exclude** any recipe rated `thumbs_down` (greyed out / excluded from suggestions and disabled from selection in the UI; still visible in the full recipe browser for history, and can be un-greyed manually).
2. **Filter** by active diet mode — e.g. if Keto mode is on, hide non-keto recipes from suggestions (still browsable with an "off-diet" indicator if the user searches manually).
3. **Boost** recipes whose ingredients overlap with any open use-by window ingredient from earlier in the week. Show a "uses up: sour cream, cilantro" tag on boosted suggestions. More overlapping at-risk ingredients = higher boost.
4. **Deprioritize (not block)** recipes matching the protein type used by the primary component on the immediately preceding day.
5. **Sort remaining ties** by rating (`thumbs_up` above `just_ok`), then lightly deprioritize anything cooked within the last ~2-3 weeks (recency), unless it's also clearing a use-by ingredient, in which case waste-reduction wins.

This ranking only governs the **primary** component picker. Adding side/extra components (§4.5) is a separate, lighter-weight flow with no ranking logic.

### 4.3 Servings scaling
- Determine household size for the day from `WeeklyHouseholdSchedule` (2 or 5, editable).
- Compute the serving multiplier as `ceil(household_size / base_servings)` — i.e., round the **multiplier** up, not the final ingredient amounts (e.g., a recipe for 4 needed for 5 people scales as if serving 6).
- Apply that multiplier to every ingredient quantity for the grocery list and inventory deduction.
- Simple items scale by a plain per-person count (e.g. "Grapes" x5 people = one grocery line, quantity implicitly "enough for 5") rather than a recipe-style multiplier.

### 4.4 Ratings
- `thumbs_up`: fully in rotation.
- `just_ok`: stays in rotation, ranked below thumbs_up, with a notes field the user can fill in for how to improve it next time.
- `thumbs_down`: greyed out, excluded from suggestions and blocked from being freshly selected until manually un-greyed.
- Ratings and rotation logic apply to recipes only — simple items aren't rated.

### 4.5 Multi-item meal slots & grocery inference (new)
Any slot — breakfast, lunch, or dinner — can hold multiple components, not just one recipe:
- **Dinner** typically has one primary recipe plus optional simple-item sides (a vegetable, a salad).
- **Lunch/breakfast** typically consist entirely of simple items (Turkey Sandwich, Grapes, Cucumber Slices), since these don't run through the full recipe engine (per earlier decision — staples-checklist style).
- Adding a component to a slot is a quick picker: search/pick an existing SimpleItem or Recipe, or type a new name on the fly.

**Grocery list inference for simple items:**
- **Single-concept items** (Grapes, Cucumber Slices, Cheese Sticks) — the name itself becomes the grocery list line item directly. No breakdown needed.
- **Compound items** (Turkey Sandwich, Charcuterie Plate) — the first time this SimpleItem is created, the app prompts for a one-time quick breakdown (e.g. Turkey Sandwich → turkey, bread, cheese, mayo), stored in `grocery_breakdown`. After that, every future use of "Turkey Sandwich" auto-adds its saved breakdown to the grocery list with no re-entry needed. The breakdown is editable any time from the item's quick-edit view.
- If a user skips the breakdown prompt, the raw name is added to the grocery list as a single line (e.g. "Turkey Sandwich") as a fallback, editable later.
- All SimpleItems are saved to a reusable library so common lunch/side items only need to be defined once and can be quickly re-added week to week (this is effectively the "kids have the same lunch most days" shortcut).

### 4.6 Grocery list generation
- Ingredients/items are added to the Grocery List **immediately** when a component (recipe or simple item) is assigned to a slot — the list grows live throughout the week, not batched at the end.
- Before adding a recipe ingredient, check `InventoryFlag` for that ingredient — if flagged "have stock," skip adding it to the list (still shown in the recipe's ingredient view with a "from freezer" note).
- Manual items can be added directly to the Grocery List at any time, independent of meal planning.
- Repeater items surface on the Grocery List automatically once `next_due_date` arrives, independent of the meal plan.

### 4.7 Recipe ingredient sourcing (data migration)
- All ~150 migrated recipes import immediately with existing fields (name, protein, links, times_made) and `ingredients_status = not_yet_added` — nothing is blocked or hidden while ingredients are missing.
- The first time a recipe with missing ingredients is selected as a primary component, the app attempts an auto-import from the recipe link (parsing structured recipe data where available).
- If auto-import fails (unsupported site, broken link, no structured data — common with Pinterest/Instagram/YouTube links in the spreadsheet), fall back to a manual ingredient-entry form.
- Multiple links per recipe (semicolon-separated in the source spreadsheet) are stored as a list; auto-import tries them in order until one succeeds.

### 4.8 Google Calendar integration
- One-way push: assigning components to a Meal Plan slot creates/updates an event on the user's existing "Meals" calendar (event title reflects the primary recipe or, for slots with no primary recipe, a summary of the simple items). No read-back into the app (the app's Meal Plan is the source of truth).

## 5. Screens

### Home Screen
- Two large, tap-friendly buttons: **What's For Dinner** and **Meal Plan**. Designed mobile-first.

### What's For Dinner
- Shows tonight's planned meal: primary recipe summary, ingredients (scaled to tonight's household size), instructions/link, any side/extra components, and a quick-access rating control after the fact.

### Meal Plan
- Week grid: 7 days × 3 slots (breakfast, lunch, dinner), responsive — a scrollable day-by-day card view on phones, a fuller grid on tablets/desktop.
- Each slot shows its component(s) as small chips/cards; tapping "+" on a slot opens the add-component picker.
- Dinner slot, adding a primary recipe: opens the ranked recipe suggestion list (per §4.2); selecting a recipe fills the slot, pushes ingredients to the Grocery List, pushes the event to Google Calendar, and immediately re-ranks suggestions for the remaining empty dinner slots that week.
- Any slot, adding a side/extra: opens a lightweight search-or-create picker over the SimpleItem library (per §4.5).
- Breakfast/Lunch slots: same multi-item picker, defaulting to the staples/quick-pick library rather than the recipe suggestion engine.
- Diet filter toggle (e.g. Keto mode) available at the top of the screen.

### Grocery List
- Auto-added items (grouped by source recipe/component optionally), manual add field, repeater items with due dates, checkbox to mark purchased, and a way to toggle an ingredient's "have stock" flag directly from the list.

### Recipe Browser
- Filterable by protein type and category tags (Soup, Salad, Keto, Tapas, Comfort Food, Holiday, Grilling, Appetizer, Dessert), searchable, shows rating and times-made; thumbs-down recipes appear greyed out with an "un-grey" action.

## 6. Out of scope for v1 (future / v2)
- Walmart / Amazon account integration for auto-ordering repeater items.
- Full quantity-based inventory tracking (v1 uses a simple has-stock flag only).
- Two-way Google Calendar sync.

## 7. Open technical notes for implementation
- Recipe-link auto-import will need a recipe-parsing approach (e.g. checking for schema.org Recipe structured data on the page) with graceful fallback to manual entry — expect a meaningful fraction of the source links (Pinterest, Instagram, YouTube) to fail auto-import.
- Shelf-life defaults per ingredient category should ship with sensible starting values (editable per ingredient) — e.g. dairy ~10-14 days, fresh produce ~5-7 days, opened jarred goods ~14-30 days — since the source spreadsheet has no expiration data to migrate.
- Build with a mobile-first responsive framework/component library and a real design system (spacing scale, type scale, consistent component states) rather than default browser styling — this app's primary usage context is a phone/tablet in the kitchen.
