<?php

namespace Database\Seeders;

use App\Models\InvestmentItem;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Investment catalog for customer quotations.
 *
 * IMPORTANT: hardware prices and tier maintenance fees below are PLACEHOLDER
 * market estimates (Kampala retail, September 2026). Review and adjust every
 * figure with real supplier quotes before customer-facing use. Re-running is
 * safe: items match on code, maintenance only fills tiers still at default.
 */
class InvestmentCatalogSeeder extends Seeder
{
    /** @var array<string, float> annual maintenance flat fee per plan tier (UGX) */
    public const TIER_MAINTENANCE_UGX = [
        'essential' => 150000,
        'professional' => 350000,
        'enterprise' => 600000,
    ];

    public function run(): void
    {
        $order = 0;
        foreach ($this->items() as $item) {
            InvestmentItem::updateOrCreate(
                ['code' => $item['code']],
                [...$item, 'sort_order' => $order += 10],
            );
        }

        foreach (self::TIER_MAINTENANCE_UGX as $slug => $fee) {
            Plan::query()
                ->where('slug', $slug)
                ->where('maintenance_fee_ugx', 0)
                ->update(['maintenance_fee_ugx' => $fee]);
        }

        $this->command?->info('Seeded investment catalog and tier maintenance fees.');
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return [
            [
                'code' => 'pos-terminal-15',
                'category' => 'Computers',
                'name' => 'POS Terminal 15.6" Touch',
                'specs' => 'Intel i3 or better, 8GB RAM, 256GB SSD, Windows 11 Pro. Touchscreen for counter selling; WiFi + LAN.',
                'price_ugx' => 2900000,
            ],
            [
                'code' => 'desktop-i5',
                'category' => 'Computers',
                'name' => 'Desktop PC Core i5',
                'specs' => 'Intel Core i5 12th gen or better, 8GB RAM, 512GB SSD, Windows 11 Pro. Back-office and POS use.',
                'price_ugx' => 1850000,
            ],
            [
                'code' => 'laptop-i5',
                'category' => 'Computers',
                'name' => 'Laptop Core i5',
                'specs' => 'Intel Core i5 or better, 8GB RAM, 512GB SSD, Windows 11 Pro. For owners and managers on the move.',
                'price_ugx' => 2400000,
            ],
            [
                'code' => 'thermal-printer-80',
                'category' => 'Printers',
                'name' => 'Thermal Receipt Printer 80mm',
                'specs' => '80mm direct thermal, USB + LAN, auto cutter. Prints POS receipts; no ink needed.',
                'price_ugx' => 480000,
            ],
            [
                'code' => 'label-printer',
                'category' => 'Printers',
                'name' => 'Barcode Label Printer',
                'specs' => 'Direct thermal labels for shelves and products. USB + LAN.',
                'price_ugx' => 750000,
            ],
            [
                'code' => 'scanner-wired',
                'category' => 'Scanners',
                'name' => 'Wired Barcode Scanner',
                'specs' => '1D/2D handheld scanner, USB plug-and-play. For fixed tills.',
                'price_ugx' => 280000,
            ],
            [
                'code' => 'scanner-wireless',
                'category' => 'Scanners',
                'name' => 'Wireless Barcode Scanner',
                'specs' => '1D/2D Bluetooth scanner with charging cradle. For shelves and warehouses.',
                'price_ugx' => 450000,
            ],
            [
                'code' => 'cash-drawer',
                'category' => 'Accessories',
                'name' => 'Cash Drawer',
                'specs' => '5-bill 8-coin metal drawer, opens on receipt print (RJ11).',
                'price_ugx' => 320000,
            ],
            [
                'code' => 'receipt-rolls-box',
                'category' => 'Accessories',
                'name' => 'Thermal Paper Rolls (Box of 20)',
                'specs' => '80mm x 80mm BPA-free rolls. Consumable - reorder as needed.',
                'price_ugx' => 120000,
            ],
            [
                'code' => 'ups-650',
                'category' => 'Power',
                'name' => 'UPS 650VA',
                'specs' => 'Keeps till + router alive through short outages. For single-till shops.',
                'price_ugx' => 380000,
            ],
            [
                'code' => 'ups-1500',
                'category' => 'Power',
                'name' => 'UPS 1500VA',
                'specs' => 'Longer backup for till, printer and network gear. For busy shops.',
                'price_ugx' => 850000,
            ],
            [
                'code' => 'router-dualband',
                'category' => 'Networking',
                'name' => 'Dual-band WiFi Router',
                'specs' => '2.4GHz + 5GHz, 4 LAN ports. Connects tills, printers and phones. Needs an ISP line or MiFi.',
                'price_ugx' => 280000,
            ],
            [
                'code' => 'router-pro',
                'category' => 'Networking',
                'name' => 'Pro Business Router',
                'specs' => 'Gigabit ports, guest WiFi for customers, VPN support. For multi-device shops.',
                'price_ugx' => 650000,
            ],
            [
                'code' => 'switch-8port',
                'category' => 'Networking',
                'name' => '8-port Network Switch',
                'specs' => 'Gigabit switch for wired tills and printers.',
                'price_ugx' => 180000,
            ],
            [
                'code' => 'setup-service',
                'category' => 'Services',
                'name' => 'Installation & Setup (per branch)',
                'specs' => 'On-site installation, network setup, printer pairing, staff training (half day).',
                'price_ugx' => 350000,
            ],
        ];
    }
}
