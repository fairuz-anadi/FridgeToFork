<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use Illuminate\Database\Seeder;

/**
 * The starting ingredient vocabulary: aisle for the shopping list, per-100g
 * macros for the local nutrition estimator, aliases so "capsicum" and
 * "bell pepper" land on the same row.
 *
 * Columns: name, aisle, kcal, protein, carbs, fat, staple, aliases
 */
class IngredientSeeder extends Seeder
{
    private const INGREDIENTS = [
        // Produce
        ['Onion', 'produce', 40, 1.1, 9.3, 0.1, true, ['onions', 'red onion', 'yellow onion']],
        ['Garlic', 'produce', 149, 6.4, 33.1, 0.5, true, ['garlic clove', 'garlic cloves']],
        ['Tomato', 'produce', 18, 0.9, 3.9, 0.2, true, ['tomatoes', 'plum tomato']],
        ['Potato', 'produce', 77, 2.0, 17.5, 0.1, true, ['potatoes']],
        ['Carrot', 'produce', 41, 0.9, 9.6, 0.2, true, ['carrots']],
        ['Bell Pepper', 'produce', 31, 1.0, 6.0, 0.3, false, ['capsicum', 'red pepper', 'green pepper']],
        ['Spinach', 'produce', 23, 2.9, 3.6, 0.4, false, ['baby spinach']],
        ['Mushroom', 'produce', 22, 3.1, 3.3, 0.3, false, ['mushrooms', 'button mushrooms']],
        ['Ginger', 'produce', 80, 1.8, 17.8, 0.8, true, ['fresh ginger', 'ginger root']],
        ['Green Chilli', 'produce', 40, 1.9, 8.8, 0.4, false, ['green chili', 'chilli', 'chili', 'green chillies', 'chillies', 'chilies']],
        ['Lemon', 'produce', 29, 1.1, 9.3, 0.3, true, ['lemons', 'lemon juice']],
        ['Lime', 'produce', 30, 0.7, 10.5, 0.2, false, ['limes', 'lime juice']],
        ['Coriander', 'produce', 23, 2.1, 3.7, 0.5, false, ['cilantro', 'fresh coriander']],
        ['Basil', 'produce', 23, 3.2, 2.6, 0.6, false, ['fresh basil']],
        ['Parsley', 'produce', 36, 3.0, 6.3, 0.8, false, ['flat leaf parsley']],
        ['Spring Onion', 'produce', 32, 1.8, 7.3, 0.2, false, ['scallion', 'scallions', 'green onion']],
        ['Cucumber', 'produce', 15, 0.7, 3.6, 0.1, false, ['cucumbers']],
        ['Avocado', 'produce', 160, 2.0, 8.5, 14.7, false, ['avocados']],
        ['Broccoli', 'produce', 34, 2.8, 6.6, 0.4, false, []],
        ['Cauliflower', 'produce', 25, 1.9, 5.0, 0.3, false, []],
        ['Cabbage', 'produce', 25, 1.3, 5.8, 0.1, false, []],
        ['Aubergine', 'produce', 25, 1.0, 5.9, 0.2, false, ['eggplant', 'brinjal']],
        ['Courgette', 'produce', 17, 1.2, 3.1, 0.3, false, ['zucchini']],
        ['Sweet Potato', 'produce', 86, 1.6, 20.1, 0.1, false, ['sweet potatoes']],
        ['Apple', 'produce', 52, 0.3, 13.8, 0.2, false, ['apples']],
        ['Banana', 'produce', 89, 1.1, 22.8, 0.3, false, ['bananas']],
        ['Orange', 'produce', 47, 0.9, 11.8, 0.1, false, ['oranges', 'malta']],
        ['Mango', 'produce', 60, 0.8, 15.0, 0.4, false, ['mangoes']],
        ['Peas', 'produce', 81, 5.4, 14.5, 0.4, false, ['green peas', 'frozen peas']],
        ['Sweetcorn', 'produce', 86, 3.3, 19.0, 1.4, false, ['corn', 'sweet corn']],

        // Meat & fish
        ['Chicken Breast', 'meat', 165, 31.0, 0.0, 3.6, true, ['chicken breasts', 'chicken']],
        ['Chicken Thigh', 'meat', 209, 26.0, 0.0, 10.9, false, ['chicken thighs']],
        ['Beef Mince', 'meat', 254, 17.2, 0.0, 20.0, false, ['ground beef', 'minced beef']],
        ['Lamb', 'meat', 294, 25.0, 0.0, 21.0, false, ['lamb shoulder', 'mutton']],
        ['Pork', 'meat', 242, 27.3, 0.0, 14.0, false, ['pork shoulder']],
        ['Bacon', 'meat', 541, 37.0, 1.4, 42.0, false, ['rashers bacon', 'streaky bacon']],
        ['Prawns', 'seafood', 99, 24.0, 0.2, 0.3, false, ['shrimp', 'shrimps']],
        ['Salmon', 'seafood', 208, 20.4, 0.0, 13.4, false, ['salmon fillet']],
        ['White Fish', 'seafood', 96, 20.4, 0.0, 1.4, false, ['cod', 'haddock', 'tilapia', 'fish']],
        ['Tuna', 'seafood', 132, 28.0, 0.0, 1.3, false, ['canned tuna']],

        // Dairy & eggs
        ['Egg', 'dairy', 143, 12.6, 0.7, 9.5, true, ['eggs']],
        ['Milk', 'dairy', 61, 3.2, 4.8, 3.3, true, ['whole milk', 'semi skimmed milk']],
        ['Butter', 'dairy', 717, 0.9, 0.1, 81.1, true, ['unsalted butter']],
        ['Yoghurt', 'dairy', 59, 10.0, 3.6, 0.4, false, ['yogurt', 'greek yoghurt', 'curd']],
        ['Cheddar Cheese', 'dairy', 403, 25.0, 1.3, 33.1, false, ['cheddar', 'cheese']],
        ['Parmesan', 'dairy', 431, 38.0, 4.1, 29.0, false, ['parmesan cheese', 'parmigiano']],
        ['Mozzarella', 'dairy', 280, 28.0, 3.1, 17.0, false, ['mozzarella cheese']],
        ['Cream', 'dairy', 340, 2.1, 2.8, 36.0, false, ['double cream', 'heavy cream']],
        ['Coconut Milk', 'pantry', 230, 2.3, 5.5, 24.0, false, []],

        // Pantry staples
        ['Rice', 'pantry', 365, 7.1, 80.0, 0.7, true, ['basmati rice', 'white rice', 'jasmine rice']],
        ['Pasta', 'pantry', 371, 13.0, 74.7, 1.5, true, ['spaghetti', 'penne', 'macaroni']],
        ['Flour', 'pantry', 364, 10.3, 76.3, 1.0, true, ['plain flour', 'all-purpose flour', 'all purpose flour']],
        ['Bread', 'bakery', 265, 9.0, 49.0, 3.2, true, ['white bread', 'bread slices', 'bread rolls', 'pita', 'pita bread']],
        ['Sugar', 'pantry', 387, 0.0, 100.0, 0.0, true, ['caster sugar', 'granulated sugar']],
        ['Salt', 'pantry', 0, 0.0, 0.0, 0.0, true, ['sea salt', 'table salt']],
        ['Water', 'pantry', 0, 0.0, 0.0, 0.0, true, ['cold water', 'warm water', 'boiling water']],
        ['Black Pepper', 'pantry', 251, 10.4, 64.0, 3.3, true, ['pepper', 'ground black pepper']],
        ['Olive Oil', 'pantry', 884, 0.0, 0.0, 100.0, true, ['extra virgin olive oil']],
        ['Vegetable Oil', 'pantry', 884, 0.0, 0.0, 100.0, true, ['sunflower oil', 'cooking oil', 'oil']],
        ['Soy Sauce', 'pantry', 53, 8.1, 4.9, 0.6, false, ['light soy sauce', 'dark soy sauce']],
        ['Vinegar', 'pantry', 21, 0.0, 0.9, 0.0, false, ['white vinegar', 'rice vinegar']],
        ['Honey', 'pantry', 304, 0.3, 82.4, 0.0, false, []],
        ['Tomato Paste', 'pantry', 82, 4.3, 18.9, 0.5, false, ['tomato puree', 'tomato purée']],
        ['Chopped Tomatoes', 'pantry', 32, 1.6, 7.0, 0.3, true, ['tinned tomatoes', 'canned tomatoes']],
        ['Chickpeas', 'pantry', 164, 8.9, 27.4, 2.6, false, ['garbanzo beans', 'chana']],
        ['Lentils', 'pantry', 116, 9.0, 20.1, 0.4, false, ['red lentils', 'dal', 'daal']],
        ['Black Beans', 'pantry', 132, 8.9, 23.7, 0.5, false, ['kidney beans']],
        ['Stock', 'pantry', 4, 0.6, 0.4, 0.1, true, ['chicken stock', 'vegetable stock', 'broth', 'stock cube']],
        ['Tofu', 'pantry', 76, 8.1, 1.9, 4.8, false, ['firm tofu']],
        ['Peanut Butter', 'pantry', 588, 25.1, 20.0, 50.4, false, []],
        ['Almonds', 'pantry', 579, 21.2, 21.6, 49.9, false, ['almond']],
        ['Cashews', 'pantry', 553, 18.2, 30.2, 43.9, false, ['cashew nuts']],
        ['Oats', 'pantry', 389, 16.9, 66.3, 6.9, false, ['rolled oats', 'porridge oats']],
        ['Noodles', 'pantry', 138, 4.5, 25.0, 2.1, false, ['egg noodles', 'rice noodles']],
        ['Tortilla', 'bakery', 218, 5.7, 36.0, 5.8, false, ['tortillas', 'flour tortilla']],
        ['Breadcrumbs', 'pantry', 395, 13.4, 71.9, 5.3, false, ['panko']],
        ['Baking Powder', 'pantry', 53, 0.0, 27.7, 0.0, false, []],
        ['Yeast', 'pantry', 325, 40.4, 41.2, 7.6, false, ['dried yeast', 'instant yeast']],

        // Spices
        ['Cumin', 'spices', 375, 17.8, 44.2, 22.3, true, ['ground cumin', 'cumin seeds', 'jeera']],
        ['Coriander Powder', 'spices', 298, 12.4, 55.0, 17.8, false, ['ground coriander']],
        ['Turmeric', 'spices', 354, 7.8, 64.9, 9.9, true, ['ground turmeric', 'haldi']],
        ['Chilli Powder', 'spices', 282, 13.5, 49.7, 14.3, true, ['chili powder', 'red chilli powder']],
        ['Paprika', 'spices', 282, 14.1, 54.0, 12.9, false, ['smoked paprika']],
        ['Garam Masala', 'spices', 379, 14.0, 45.0, 15.0, false, []],
        ['Cinnamon', 'spices', 247, 4.0, 80.6, 1.2, false, ['ground cinnamon', 'cinnamon stick', 'cinnamon sticks']],
        ['Oregano', 'spices', 265, 9.0, 68.9, 4.3, false, ['dried oregano']],
        ['Thyme', 'spices', 276, 9.1, 63.9, 7.4, false, ['dried thyme']],
        ['Bay Leaf', 'spices', 313, 7.6, 75.0, 8.4, false, ['bay leaves']],
        ['Cardamom', 'spices', 311, 10.8, 68.5, 6.7, false, ['green cardamom', 'elaichi', 'cardamom pods']],
        ['Curry Powder', 'spices', 325, 12.7, 55.8, 13.8, false, []],
        ['Chocolate', 'pantry', 546, 4.9, 61.0, 31.0, false, ['dark chocolate', 'cocoa']],
        ['Vanilla', 'pantry', 288, 0.1, 12.7, 0.1, false, ['vanilla extract', 'vanilla essence']],
    ];

    public function run(): void
    {
        foreach (self::INGREDIENTS as [$name, $aisle, $kcal, $protein, $carbs, $fat, $staple, $aliases]) {
            Ingredient::updateOrCreate(
                ['slug' => Ingredient::slugify($name)],
                [
                    'name' => $name,
                    'aisle' => $aisle,
                    'calories_per_100g' => $kcal,
                    'protein_per_100g' => $protein,
                    'carbs_per_100g' => $carbs,
                    'fat_per_100g' => $fat,
                    'is_staple' => $staple,
                    'aliases' => $aliases,
                ]
            );
        }
    }
}
