<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            ['sku' => 'WH-A1001', 'name' => 'Thermal Label Roll 4x6', 'location' => 'Aisle A-01', 'quantity' => 128, 'reorder_level' => 40, 'status' => 'in_stock'],
            ['sku' => 'WH-A1014', 'name' => 'Barcode Scanner Battery', 'location' => 'Aisle A-03', 'quantity' => 18, 'reorder_level' => 24, 'status' => 'low_stock'],
            ['sku' => 'WH-B2007', 'name' => 'Packing Tape Clear', 'location' => 'Aisle B-02', 'quantity' => 212, 'reorder_level' => 60, 'status' => 'in_stock'],
            ['sku' => 'WH-B2033', 'name' => 'Small Bubble Mailer', 'location' => 'Aisle B-05', 'quantity' => 0, 'reorder_level' => 100, 'status' => 'out_of_stock'],
            ['sku' => 'WH-C3042', 'name' => 'Poly Bag 12x15', 'location' => 'Aisle C-04', 'quantity' => 76, 'reorder_level' => 80, 'status' => 'low_stock'],
            ['sku' => 'WH-C3098', 'name' => 'FBA Box 18x14x8', 'location' => 'Aisle C-09', 'quantity' => 145, 'reorder_level' => 50, 'status' => 'in_stock'],
            ['sku' => 'WH-D4120', 'name' => 'Fragile Label Pack', 'location' => 'Aisle D-01', 'quantity' => 34, 'reorder_level' => 25, 'status' => 'in_stock'],
            ['sku' => 'WH-D4199', 'name' => 'Kraft Paper Roll', 'location' => 'Aisle D-08', 'quantity' => 9, 'reorder_level' => 20, 'status' => 'low_stock'],
            ['sku' => 'WH-E5005', 'name' => 'Pallet Wrap Film', 'location' => 'Bulk E-02', 'quantity' => 41, 'reorder_level' => 16, 'status' => 'in_stock'],
            ['sku' => 'WH-E5077', 'name' => 'Corner Protector Set', 'location' => 'Bulk E-06', 'quantity' => 0, 'reorder_level' => 30, 'status' => 'out_of_stock'],
        ];

        foreach ($items as $item) {
            InventoryItem::updateOrCreate(['sku' => $item['sku']], $item);
        }
    }
}
