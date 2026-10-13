<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<\App\Models\Category> */
class CategoryFactory extends Factory {
    protected $model = \App\Models\Category::class;
    public function definition(): array { return ['name'=>fake()->unique()->word(),'is_active'=>true]; }
}