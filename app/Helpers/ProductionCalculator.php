<?php

namespace App\Helpers;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Support\Str;
use InvalidArgumentException;
use function get_class;
use function round;

class ProductionCalculator
{
    protected $product;

    protected $qty;

    protected $parts = [];

    protected $byproducts = [];

    protected $recipes = [];

    protected $recipe_models = [];

    protected $yield = [];

    protected $power_usage = [];

    protected $build_cost = [];

    protected $recipe;

    protected $selected_variant;

    protected $belt_speed;

    public static function calc($ingredient, $qty = null, $recipe = null, $variant = "mk1")
    {
        if (is_string($ingredient)) {
            $ingredient = i($ingredient);
        }

        return (new static($ingredient, $qty, $recipe, $variant))->calculate();
    }

    public function __construct(Ingredient $ingredient, $qty = null, $recipe = null, $variant = "mk1")
    {
        $this->belt_speed = request('belt_speed',780);

        $this->product = $ingredient;

        $this->recipe = $recipe ? r($recipe) : $this->getRecipe($this->product);

        $this->qty = $qty ?? $this->recipe->base_per_min;

        $description = $this->recipe->description ?? "default";

        $this->yield = "{$ingredient->name} [{$description}] ($this->qty per min)";

        $this->selected_variant = $variant;
    }

    protected function calculate()
    {
        $this->parts[$this->getKeyName($this->recipe)] = $this->qty;

        $this->calculateSubRecipe($this->recipe, $this->qty);

        $this->calculateTotalPowerUsage();

        $this->calculateTotalBuildCost();

        return ProductionStats::make([
            "product" => $this->product->name,
            "recipe" => $this->recipe->description ?? "default",
            "yield" => $this->qty,
            "raw materials" => collect($this->parts)->filter(function ($val, $key) {
                return Str::of($key)->startsWith("1");
            })->all(),
            "parts per minute" => collect($this->parts)->sortKeys()->all(),
            "byproducts per minute" => collect($this->byproducts)->sortKeys()->all(),
            "power_usage_mw" => collect($this->power_usage),
            "build_cost" => $this->build_cost,
            "recipes" => collect($this->recipes)->sortKeys()->all(),
            "recipe_models" => $this->recipe_models,
        ]);
    }

    protected function calculateSubRecipe(Recipe $recipe, $qty)
    {
        $recipe_qty = isset($this->recipes[$recipe->product->name]) ?
            $this->recipes[$recipe->product->name]['qty_required'] + $qty : $qty;

        $this->recipe_models[$recipe->product->name] = $recipe;

        $this->recipes[$recipe->product->name] = [
            "recipe" => $recipe->description ?? "default",
            "inputs" => $recipe->ingredients->map(function ($ingredient) {
                return [
                    $ingredient->name => [
                        "base_qty" => $ingredient->pivot->base_qty,
                        "needed_qty" => 0,
                    ],
                ];
            })->collapse()->all(),
            "byproducts" => $recipe->byproducts->map(function ($ingredient) {
                $qty = (int) $ingredient->pivot->base_qty;

                return "{$ingredient->name} ({$qty} per min)";
            })->implode(", "),
            "qty_required" => 1 * $recipe_qty,
            "base_per_min" => 1 * $recipe->base_per_min,
            "building_overview" => $this->getBuildingOverview($recipe, $recipe_qty),
            "building_details" => $details = $this->getBuildingDetails($recipe, $recipe_qty),
            "selected_variant" => $this->selected_variant ?
                $details->keys()->filter(fn($key) => Str::of($key)->contains($this->selected_variant))->first() :
                $details->keys()->first(),
        ];

        if ($recipe->has('byproducts')) {
            $recipe->byproducts->each(function ($ingredient) use ($qty, $recipe) {
                if (isset($this->byproducts[$ingredient->name])) {
                    $this->byproducts[$ingredient->name] += ($qty / $recipe->base_per_min) *
                        $ingredient->pivot->base_qty;
                }
                else {
                    $this->byproducts[$ingredient->name] = ($qty / $recipe->base_per_min) *
                        $ingredient->pivot->base_qty;
                }
            });

            $this->byproducts = collect($this->byproducts)->transform(function ($value, $key) {
                return [$key => (int) $value];
            })->collapse()->all();
        }

        $recipe->ingredients->each(function ($ingredient) use ($qty, $recipe) {

            // how many times per minute we need to make the recipe
            $multiplier = $qty / $recipe->base_per_min;

            // how much of the ingredient we need to make per minute
            $sub_qty = (float) $multiplier * $ingredient->pivot->base_qty;

            // set the total inputs required for the recipe

            $this->recipes[$recipe->product->name]['inputs'][$ingredient->name]['needed_qty'] += $sub_qty;

            // if we have the ingredient in the parts list then increment it, otherwise add it
            $this->parts[$this->getKeyName($ingredient)] = ($this->parts[$this->getKeyName($ingredient)] ?? 0) +
                $sub_qty;

            // continue calculating until we get to all raw ingredients
            if (! $ingredient->isRaw()) {
                $this->calculateSubRecipe($this->getRecipe($ingredient), $sub_qty);
            }
        });
    }

    protected function getRecipe(Ingredient $ingredient)
    {
        $recipe = $ingredient->defaultRecipe();

        if (! $recipe) {
            return new Recipe;
        }

        return $recipe; //->firstWhere('alt_recipe',false);
    }

    protected function getKeyName($recipe): string
    {
        if (get_class($recipe) === Ingredient::class) {
            if( $recipe->isRaw() )
                return "{$recipe->tier} - {$recipe->name}";

            $recipe = $this->getRecipe($recipe);
        }

        return "{$recipe->tier} - {$recipe->product->name}";
    }

    public function getBuildingOverview($recipe, $qty)
    {
        return $this->getBuildingDetails($recipe, $qty)->map(function ($details, $building) {
            return [$building => "[x{$details['num_buildings']} {$details['clock_speed']}%] [{$details['power_usage']} MW]"];
        })->collapse();
    }

    public function getBuildingDetails($recipe, $qty)
    {
        return $recipe->building->variants->map(function ($variant) use ($qty, $recipe) {
            // calc number of buildings needed
            $num_buildings = 1 * ceil($qty / $recipe->base_per_min / $variant->multiplier);

            // calc the clock speed for the buildings
            $clock_speed = 1 * round(100 * $qty / $num_buildings / $recipe->base_per_min / $variant->multiplier, 4);

            // calc the power_usage for the buildings
            $power_usage = 1 * round(1 * $num_buildings * $variant->calculatePowerUsage($clock_speed / 100), 6);

            // calc the build cost
            $build_cost = $variant->recipe->map(function ($ingredient) use ($num_buildings) {
                return [$ingredient->name => $ingredient->pivot->qty * $num_buildings];
            })->collapse();

            // calculate the max belt load
            $belt_load_in = $recipe->ingredients->map(function ($ingredient) use ($num_buildings,$clock_speed,$variant) {
                return $ingredient->pivot->base_qty * $num_buildings * $clock_speed * $variant->multiplier / 100;
            })->max();

            // calc the number of rows needed
            $rows = max( ceil($belt_load_in/$this->belt_speed), max(1,floor($qty/$this->belt_speed)) );

            // calc the footprint
            //$rows = ceil($num_buildings/16); // max 16 buildings per row
            $buildings_per_row = min($num_buildings, ceil($num_buildings/$rows) );

            $footprint = [
                'monogram' => $recipe->building->name[0],
                'belt_speed' => $this->belt_speed,
                'belt_load' => $belt_load_in,
                'rows' => $rows,
                'num_buildings' => $num_buildings,
                'buildings_per_row' => $buildings_per_row,
                'building_length' => $recipe->building->length,
                'building_length_foundations' => 8 * (ceil($recipe->building->length/8)+2),
                'building_width' => $recipe->building->width,
                'length_m' => $length = $rows * (8 * (ceil($recipe->building->length/8)+2) ),
                'length_foundations' => $length_foundations = ( ceil($length/8) + 2 ),
                'width_m' => $width = $recipe->building->width * $buildings_per_row,
                'width_foundations' => $width_foundations = ( ceil($width/8) + 2),
                'height_m' => $height = $recipe->building->height,
                'height_walls' => $height_walls = ceil($height/4) + 1,
                'foundations' => $foundations = $length_foundations * $width_foundations,
                'walls' => $height_walls * $foundations
            ];

            return [
                "{$recipe->building->name} ($variant->name)" => ['variant' => $variant->name] +
                    compact('num_buildings', 'clock_speed', 'power_usage', 'build_cost','footprint'),
            ];
        })->collapse();
    }

    protected function calculateTotalPowerUsage()
    {
        $this->power_usage = collect($this->recipes)
            ->pluck('building_details')
            ->collapse()
            ->values()
            ->groupBy('variant')
            ->map(function ($group) {
                if (! is_numeric($group->sum('power_usage'))) {
                    dd($group->sum('power_usage'));
                }

                return 1 * round($group->sum('power_usage'), 6);
            });
    }

    protected function calculateTotalBuildCost()
    {
        $this->build_cost = collect($this->recipes)
            ->pluck('building_details')
            ->collapse()
            ->values()
            ->groupBy('variant')
            ->map(function ($group) {
                return $group->pluck('build_cost')->map(function ($row) {
                    return $row->map(function ($qty, $ingredient) {
                        return compact('qty', 'ingredient');
                    })->values();
                })->collapse()->groupBy("ingredient")->map(function ($group) {
                    return $group->sum('qty');
                });
            });
    }
}