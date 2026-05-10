<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Classic Beef Burger', 'description' => 'Grilled beef, lettuce, pickles, sauce.', 'price' => '8.99', 'stock' => 50],
            ['name' => 'Crispy Chicken Wrap', 'description' => 'Fried chicken, slaw, chipotle mayo.', 'price' => '7.50', 'stock' => 40],
            ['name' => 'Margherita Pizza slice', 'description' => 'San Marzano tomato, buffalo mozzarella.', 'price' => '5.75', 'stock' => 30],
            ['name' => 'Caesar Salad', 'description' => 'Romaine, parmesan, croutons, dressing.', 'price' => '6.25', 'stock' => 25],
            ['name' => 'Fries (large)', 'description' => 'Sea salt, crisp cut.', 'price' => '3.50', 'stock' => 100],
            ['name' => 'Iced Latte', 'description' => 'Cold brew + milk.', 'price' => '4.25', 'stock' => 60],
            ['name' => 'Chocolate Brownie', 'description' => 'Warm, nut-free.', 'price' => '3.10', 'stock' => 35],
            ['name' => 'Sparkling Water', 'description' => '500ml.', 'price' => '2.00', 'stock' => 80],
        ];

        foreach ($items as $row) {
            Product::query()->updateOrCreate(
                ['name' => $row['name']],
                [
                    'description' => $row['description'],
                    'price' => $row['price'],
                    'stock' => $row['stock'],
                    'active' => true,
                ]
            );
        }
    }
}
