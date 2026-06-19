<?php

namespace App\Http\Controllers;

use App\Support\FoodDiary;
use Illuminate\Http\Request;

/**
 * The food wiki -- your most-eaten foods and patterns, computed from your logged meals. The same data
 * the coach pulls via my_foods, in a browsable view.
 */
class FoodController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        return view('foods.index', ['foods' => FoodDiary::topFoods($profile, 30)]);
    }
}
