<?php

namespace App\Production;

use App\Models\Recipe;
use Illuminate\Support\Collection;

class BuildingDetails extends Collection
{
    /**
     * @var \App\Models\Recipe
     */
    protected $recipe;

    protected $qty;

    protected $belt_speed;

    public static function calc(Recipe $recipe, $qty, $belt_speed = 720): static
    {
        return (new static)
            ->setRecipe($recipe)
            ->setQty($qty)
            ->setBeltSpeed($belt_speed)
            ->getBuildingDetails();
    }

    protected function setRecipe(Recipe $recipe): static
    {
        $this->recipe = $recipe;

        return $this;
    }

    protected function setQty($qty): static
    {
        $this->qty = $qty;

        return $this;
    }

    protected function setBeltSpeed($belt_speed): static
    {
        $this->belt_speed = $belt_speed;

        return $this;
    }

    protected function getBuildingDetails(): static
    {
        $this->items = $this->recipe->building->variants->map(function ($variant) {
            // calc number of buildings needed
            $num_buildings = 1 * ceil($this->qty / $this->recipe->base_per_min / $variant->multiplier);

            // calc the clock speed for the buildings
            $clock_speed = 1 * round(100 * $this->qty / $num_buildings / $this->recipe->base_per_min / $variant->multiplier, 4);

            // calc the power_usage for the buildings
            $power_usage = 1 * round(1 * $num_buildings * $variant->calculatePowerUsage($clock_speed / 100), 6);

            // calc the build cost
            $build_cost = $variant->recipe->map(function ($ingredient) use ($num_buildings) {
                return [$ingredient->name => $ingredient->pivot->qty * $num_buildings];
            })->collapse();

            // calculate the max belt load
            $belt_load_in = $this->recipe->ingredients->map(function ($ingredient) use ($num_buildings,$clock_speed,$variant) {
                return $ingredient->pivot->base_qty * $num_buildings * $clock_speed * $variant->multiplier / 100;
            })->max();

            // calc the number of rows needed
            $rows = max(ceil($belt_load_in / $this->belt_speed), 1, ceil($this->qty / $this->belt_speed));

            // calc the footprint
            //$rows = ceil($num_buildings/16); // max 16 buildings per row
            $buildings_per_row = min($num_buildings, ceil($num_buildings/$rows) );

            $footprint = [
                'monogram' => $this->recipe->building->name[0],
                'belt_speed' => $this->belt_speed,
                'belt_load' => $belt_load_in,
                'rows' => $rows,
                'num_buildings' => $num_buildings,
                'buildings_per_row' => $buildings_per_row,
                'building_length' => $this->recipe->building->length,
                'building_length_foundations' => ceil($this->recipe->building->length/8),
                'building_width' => $this->recipe->building->width,
                'length_m' => $length = $rows * $this->recipe->building->length,
                'length_foundations' => $length_foundations = ceil($length/8) + ($rows > 1 ? (ceil(2*($rows+1.2))) : 2),
                'width_m' => $width = $this->recipe->building->width * $buildings_per_row,
                'width_foundations' => $width_foundations = ( ceil($width/8) + 4),
                'height_m' => $height = $this->recipe->building->height,
                'height_walls' => $height_walls = ceil($height/4) + 1,
                'foundations' => $foundations = $length_foundations * $width_foundations,
                'walls' => $height_walls * (2*($length_foundations + $width_foundations))
            ];

            return [
                "{$this->recipe->building->name} ($variant->name)" => ['variant' => $variant->name] +
                    compact('num_buildings', 'clock_speed', 'power_usage', 'build_cost','footprint'),
            ];
        })->collapse()->all();

        return $this;
    }
}