<?php

namespace Database\Seeders;

use App\Domains\Catalog\Read\CatalogCacheKeys;
use App\Domains\Catalog\Read\CatalogProjectionService;
use App\Domains\Pricing\Models\Promotion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class MarketplaceSeeder extends Seeder
{
    private const PRODUCT_COUNT = 2000;

    /** @var array<string, int> */
    private array $fileIds = [];

    /** @var array<string, int> */
    private array $brandIds = [];

    /** @var array<string, int> */
    private array $categoryIds = [];

    /** @var array<string, int> */
    private array $productIds = [];

    /** @var array<int, string> */
    private array $productSkus = [];

    /** @var array<string, int> */
    private array $warehouseIds = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedUser();
            $this->seedBrands();
            $this->seedCategories();
            $this->seedProducts();
            $this->seedWarehouses();
            $this->seedStock();
            $this->seedPrices();
            $this->seedPromotions();
            $this->seedHomepage();
        });

        CatalogCacheKeys::invalidateCategoryTree();
        CatalogCacheKeys::invalidateProducts();

        foreach ($this->productIds as $productId) {
            app(CatalogProjectionService::class)->syncProduct($productId);
        }

        if (config('stockflow.seed.search_index')) {
            Artisan::call('search:index:rebuild');
        }
    }

    private function seedUser(): void
    {
        DB::table('users')->updateOrInsert(
            ['email' => 'demo@stockflow.test'],
            [
                'name' => 'Demo Customer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function seedBrands(): void
    {
        $brands = [
            ['nordwerk', 'Nordwerk', 'Надежная техника для кухни и дома.', '#12355B', '#48ACF0'],
            ['aurora', 'Aurora', 'Умная электроника для повседневных задач.', '#3B1C5A', '#C77DFF'],
            ['vertex', 'Vertex', 'Рабочие инструменты и оборудование для мастерской.', '#4A2C12', '#F6AE2D'],
            ['mellow', 'Mellow', 'Спокойные решения для уютного интерьера.', '#36513D', '#A7C957'],
            ['trailhead', 'Trailhead', 'Экипировка для тренировок и активного отдыха.', '#1D3557', '#E63946'],
            ['paperfox', 'Paperfox', 'Практичные товары для учебы и офиса.', '#593C8F', '#F7B801'],
        ];

        foreach ($brands as [$slug, $name, $description, $primary, $accent]) {
            $logoFileId = $this->svgFile("brand-{$slug}", $name, 'BRAND', $primary, $accent);

            DB::table('catalog_brands')->updateOrInsert(
                ['slug' => $slug],
                [
                    'logo_file_id' => $logoFileId,
                    'name' => $name,
                    'description' => $description,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $this->brandIds[$slug] = (int) DB::table('catalog_brands')->where('slug', $slug)->value('id');
        }
    }

    private function seedCategories(): void
    {
        $categories = [
            ['electronics', null, 'Электроника', 'Устройства для дома, работы и отдыха.', [], [], '#14213D', '#FCA311'],
            ['smart-home', 'electronics', 'Умный дом', 'Техника, которая помогает управлять домом.', ['connection', 'color'], ['connection', 'color'], '#003049', '#669BBC'],
            ['audio', 'electronics', 'Аудио', 'Наушники и акустика для музыки и звонков.', ['connection', 'color', 'battery_life'], ['connection', 'battery_life'], '#2B2D42', '#EF233C'],
            ['home', null, 'Дом и кухня', 'Полезные вещи для уютного и организованного дома.', [], [], '#344E41', '#DAD7CD'],
            ['kitchen', 'home', 'Кухня', 'Техника и аксессуары для ежедневной готовки.', ['material', 'power'], ['material', 'power'], '#6A040F', '#F48C06'],
            ['interior', 'home', 'Интерьер', 'Свет, текстиль и системы хранения.', ['material', 'color'], ['material', 'color'], '#606C38', '#DDA15E'],
            ['workshop', null, 'Мастерская', 'Инструменты для ремонта и домашних проектов.', [], [], '#3D405B', '#F2CC8F'],
            ['power-tools', 'workshop', 'Электроинструменты', 'Аккумуляторный инструмент и оснастка.', ['voltage', 'kit'], ['voltage', 'kit'], '#264653', '#E9C46A'],
            ['sport', null, 'Спорт', 'Инвентарь для тренировок дома и на улице.', [], [], '#1D3557', '#A8DADC'],
            ['fitness', 'sport', 'Фитнес', 'Базовый инвентарь для регулярных тренировок.', ['material', 'weight'], ['material', 'weight'], '#386641', '#A7C957'],
            ['office', null, 'Офис', 'Организация рабочего места и учебы.', [], [], '#5A189A', '#E0AAFF'],
            ['desk', 'office', 'Рабочее место', 'Аксессуары для удобного рабочего стола.', ['material', 'color'], ['material', 'color'], '#3C096C', '#FFB703'],
        ];

        foreach ($categories as [$slug, $parentSlug, $name, $description, $filters, $cardAttributes, $primary, $accent]) {
            $imageFileId = $this->svgFile("category-{$slug}", $name, 'CATEGORY', $primary, $accent);

            DB::table('catalog_categories')->updateOrInsert(
                ['slug' => $slug],
                [
                    'parent_id' => $parentSlug === null ? null : $this->categoryIds[$parentSlug],
                    'image_file_id' => $imageFileId,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => true,
                    'filterable_attributes' => json_encode($filters, JSON_UNESCAPED_UNICODE),
                    'card_attribute_names' => json_encode($cardAttributes, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $this->categoryIds[$slug] = (int) DB::table('catalog_categories')->where('slug', $slug)->value('id');
        }
    }

    private function seedProducts(): void
    {
        foreach ($this->products() as $index => $product) {
            $slug = $product['slug'];
            $imageFileId = $this->svgFile($product['image'] ?? "product-{$slug}", $product['image_title'] ?? $product['name'], $product['sku'], $product['colors'][0], $product['colors'][1]);

            DB::table('catalog_products')->updateOrInsert(
                ['slug' => $slug],
                [
                    'category_id' => $this->categoryIds[$product['category']],
                    'brand_id' => $this->brandIds[$product['brand']],
                    'image_file_id' => $imageFileId,
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'description' => $product['description'],
                    'short_description' => $product['short'],
                    'rating' => $product['rating'],
                    'rating_count' => $product['rating_count'],
                    'status' => 'published',
                    'published_at' => now()->subDays($index % 90),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $productId = (int) DB::table('catalog_products')->where('slug', $slug)->value('id');
            $this->productIds[$slug] = $productId;
            $this->productSkus[$productId] = $product['sku'];
            $this->seedAttributes($productId, $product['attributes']);
            $this->seedOffers($productId, $product);

            if ($product['gallery'] ?? true) {
                $this->seedGallery($productId, $product);
            }
        }
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function seedAttributes(int $productId, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            DB::table('catalog_product_attributes')->updateOrInsert(
                ['product_id' => $productId, 'name' => $name],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function seedOffers(int $productId, array $product): void
    {
        foreach ($product['offers'] ?? [] as $position => $offer) {
            $fileId = $this->svgFile(
                "offer-{$product['slug']}-{$position}",
                $product['name'],
                $offer['name'],
                $offer['colors'][0],
                $offer['colors'][1],
            );

            DB::table('catalog_product_offers')->updateOrInsert(
                ['sku' => $offer['sku']],
                [
                    'product_id' => $productId,
                    'image_file_id' => $fileId,
                    'name' => $offer['name'],
                    'status' => 'active',
                    'attributes' => json_encode($offer['attributes'], JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function seedGallery(int $productId, array $product): void
    {
        $fileId = $this->svgFile(
            "gallery-{$product['slug']}",
            $product['name'],
            'DETAIL VIEW',
            $product['colors'][1],
            $product['colors'][0],
        );

        DB::table('catalog_product_files')->updateOrInsert(
            ['product_id' => $productId, 'file_id' => $fileId, 'type' => 'gallery'],
            ['title' => 'Дополнительный вид', 'position' => 10, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function seedWarehouses(): void
    {
        $warehouses = [
            ['WAW-CENTRAL', 'Варшава, Центральный', 'waw', 'Варшава', 52.2297700, 21.0117800],
            ['WAW-WEST', 'Варшава, Западный', 'waw', 'Варшава', 52.2220900, 20.9069600],
            ['KRK-SOUTH', 'Краков, Южный', 'krk', 'Краков', 50.0413200, 19.9456200],
            ['GDN-PORT', 'Гданьск, Портовый', 'gdn', 'Гданьск', 54.3520500, 18.6463700],
            ['WRO-EAST', 'Вроцлав, Восточный', 'wro', 'Вроцлав', 51.1078800, 17.0385400],
            ['POZ-NORTH', 'Познань, Северный', 'poz', 'Познань', 52.4063700, 16.9251700],
            ['POZ-OUTLET', 'Познань, Аутлет', 'poz', 'Познань', 52.4331500, 16.9142300],
        ];

        foreach ($warehouses as [$code, $name, $cityCode, $cityName, $latitude, $longitude]) {
            DB::table('inventory_warehouses')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'city_code' => $cityCode,
                    'city_name' => $cityName,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $this->warehouseIds[$code] = (int) DB::table('inventory_warehouses')->where('code', $code)->value('id');
        }
    }

    private function seedStock(): void
    {
        $warehouseCodes = array_keys($this->warehouseIds);
        $rows = [];

        foreach (array_values($this->productIds) as $productIndex => $productId) {
            foreach ($warehouseCodes as $warehouseIndex => $warehouseCode) {
                $quantity = (($productIndex + 3) * ($warehouseIndex + 5)) % 34;

                $rows[] = [
                    'warehouse_id' => $this->warehouseIds[$warehouseCode],
                    'product_id' => $productId,
                    'sku' => $this->productSkus[$productId],
                    'on_hand_quantity' => $quantity,
                    'reserved_quantity' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventory_stock_items')->upsert(
                $chunk,
                ['warehouse_id', 'product_id'],
                ['sku', 'on_hand_quantity', 'reserved_quantity', 'updated_at'],
            );
        }
    }

    private function seedPrices(): void
    {
        $rows = [];

        foreach ($this->products() as $index => $product) {
            $productId = $this->productIds[$product['slug']];
            $retail = $product['price'];

            $rows[] = $this->price($productId, 'retail', null, 1, $retail);
            $rows[] = $this->price($productId, 'wholesale', null, 1, (int) round($retail * 0.84));
            $rows[] = $this->price($productId, 'partner', null, 1, (int) round($retail * 0.77));

            if ($index % 3 === 0) {
                $rows[] = $this->price($productId, 'sale', null, 1, (int) round($retail * 0.90));
            }

            if ($index % 4 === 0) {
                $rows[] = $this->price($productId, 'retail', 'waw', 1, $retail - 500);
            }

            if ($index % 5 === 0) {
                $rows[] = $this->price($productId, 'retail', 'krk', 1, $retail + 300);
            }
        }

        DB::table('pricing_product_prices')->whereIn('product_id', array_values($this->productIds))->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pricing_product_prices')->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function price(int $productId, string $type, ?string $cityCode, int $version, int $amount): array
    {
        return [
            'product_id' => $productId,
            'price_type' => $type,
            'city_code' => $cityCode,
            'price_version' => $version,
            'amount_minor' => $amount,
            'currency' => 'PLN',
            'is_active' => true,
            'active_from' => now()->subDays(14),
            'active_until' => null,
            'updated_at' => now(),
            'created_at' => now(),
        ];
    }

    private function seedPromotions(): void
    {
        $promotions = [
            ['WELCOME10', Promotion::TYPE_PERCENT, 10, null],
            ['HOME50', Promotion::TYPE_FIXED_AMOUNT, 5000, 'PLN'],
            ['SPORT15', Promotion::TYPE_PERCENT, 15, null],
        ];

        foreach ($promotions as [$code, $type, $value, $currency]) {
            DB::table('pricing_promotions')->updateOrInsert(
                ['code' => $code],
                [
                    'discount_type' => $type,
                    'discount_value' => $value,
                    'currency' => $currency,
                    'is_active' => true,
                    'starts_at' => now()->subDays(7),
                    'ends_at' => now()->addMonths(6),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    private function seedHomepage(): void
    {
        $blocks = [
            ['banner', 'Летнее обновление дома', 10, ['headline' => 'Полезные вещи для дома и мастерской', 'text' => 'Собрали товары, которые делают ежедневные задачи проще.', 'button_label' => 'Смотреть каталог', 'button_url' => '/catalog'], 'banner-summer', '#1D3557', '#E9C46A'],
            ['recommended_products', 'Рекомендуем', 20, null, null, null, null],
            ['bestseller_products', 'Покупают чаще всего', 30, null, null, null, null],
            ['new_products', 'Новинки', 40, null, null, null, null],
            ['cities', 'Наши склады', 50, ['text' => 'Выберите ближайший склад и проверьте наличие товаров.'], null, null, null],
            ['description', 'Stockflow Market', 60, ['text' => 'Демо-витрина маркетплейса с каталогом, городскими ценами и распределенными остатками по складам.'], null, null, null],
        ];

        foreach ($blocks as [$type, $title, $position, $settings, $image, $primary, $accent]) {
            $imageFileId = $image === null ? null : $this->svgFile($image, $title, 'STOCKFLOW MARKET', $primary, $accent);

            DB::table('homepage_blocks')->updateOrInsert(
                ['type' => $type, 'title' => $title],
                [
                    'image_file_id' => $imageFileId,
                    'position' => $position,
                    'is_active' => true,
                    'settings' => $settings === null ? null : json_encode($settings, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $this->syncBlockProducts('recommended_products', ['smart-hub-mini', 'kettle-steel-pro', 'cordless-drill-v18', 'desk-lamp-flex', 'yoga-mat-calm']);
        $this->syncBlockProducts('bestseller_products', ['headphones-wave', 'storage-baskets-set', 'toolkit-home-56', 'standing-desk-organizer', 'fitness-bands-set']);
        $this->syncBlockProducts('new_products', ['smart-plug-duo', 'speaker-room', 'coffee-grinder-burr', 'impact-driver-v18', 'cork-desk-mat']);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function syncBlockProducts(string $type, array $slugs): void
    {
        $blockId = (int) DB::table('homepage_blocks')->where('type', $type)->value('id');
        DB::table('homepage_block_products')->where('homepage_block_id', $blockId)->delete();

        foreach ($slugs as $position => $slug) {
            DB::table('homepage_block_products')->insert([
                'homepage_block_id' => $blockId,
                'product_id' => $this->productIds[$slug],
                'position' => ($position + 1) * 10,
            ]);
        }
    }

    private function svgFile(string $name, string $title, string $subtitle, string $primary, string $accent): int
    {
        if (isset($this->fileIds[$name])) {
            return $this->fileIds[$name];
        }

        $directory = public_path('images/seed');
        File::ensureDirectoryExists($directory);
        $path = "{$directory}/{$name}.svg";
        $svg = $this->svg($title, $subtitle, $primary, $accent);
        File::put($path, $svg);
        $sourceUrl = "/images/seed/{$name}.svg";

        $existingId = DB::table('storage_files')->where('source_url', $sourceUrl)->value('id');
        $values = [
            'source_url' => $sourceUrl,
            'original_name' => "{$name}.svg",
            'mime_type' => 'image/svg+xml',
            'size' => strlen($svg),
            'checksum' => hash('sha256', $svg),
            'metadata' => json_encode(['generated_by' => self::class]),
            'updated_at' => now(),
            'created_at' => now(),
        ];

        if ($existingId === null) {
            $existingId = DB::table('storage_files')->insertGetId($values);
        } else {
            DB::table('storage_files')->where('id', $existingId)->update($values);
        }

        return $this->fileIds[$name] = (int) $existingId;
    }

    private function svg(string $title, string $subtitle, string $primary, string $accent): string
    {
        $title = htmlspecialchars($title, ENT_XML1);
        $subtitle = htmlspecialchars($subtitle, ENT_XML1);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900" role="img" aria-labelledby="title desc">
  <title id="title">{$title}</title>
  <desc id="desc">{$subtitle}</desc>
  <defs>
    <linearGradient id="background" x1="0" x2="1" y1="0" y2="1">
      <stop stop-color="{$primary}"/>
      <stop offset="1" stop-color="{$accent}"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="900" rx="64" fill="url(#background)"/>
  <circle cx="980" cy="160" r="210" fill="#fff" fill-opacity=".16"/>
  <circle cx="160" cy="760" r="270" fill="#fff" fill-opacity=".10"/>
  <path d="M790 740c145-112 244-197 298-312" fill="none" stroke="#fff" stroke-opacity=".24" stroke-width="28" stroke-linecap="round"/>
  <rect x="96" y="106" width="90" height="12" rx="6" fill="#fff" fill-opacity=".78"/>
  <text x="96" y="178" fill="#fff" fill-opacity=".82" font-family="Arial, sans-serif" font-size="24" font-weight="700" letter-spacing="6">{$subtitle}</text>
  <text x="96" y="346" fill="#fff" font-family="Arial, sans-serif" font-size="68" font-weight="700">{$title}</text>
  <text x="96" y="412" fill="#fff" fill-opacity=".78" font-family="Arial, sans-serif" font-size="28">STOCKFLOW MARKET</text>
</svg>
SVG;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(): array
    {
        return array_merge([
            $this->product('smart-hub-mini', 'Aurora Smart Hub Mini', 'AUR-HUB-MINI', 'smart-home', 'aurora', 29900, 'Компактный центр управления умным домом.', 'Подключает датчики, розетки и сценарии в одном приложении.', ['Подключение' => 'Wi-Fi, Zigbee', 'Цвет' => 'Белый', 'Гарантия' => '24 месяца'], 4.82, 126, ['#3B1C5A', '#C77DFF']),
            $this->product('smart-plug-duo', 'Aurora Smart Plug Duo', 'AUR-PLUG-DUO', 'smart-home', 'aurora', 15900, 'Комплект из двух умных розеток.', 'Удаленное включение, расписания и контроль энергопотребления.', ['Подключение' => 'Wi-Fi', 'Цвет' => 'Белый', 'Комплектация' => '2 розетки'], 4.74, 89, ['#274C77', '#A3CEF1']),
            $this->product('headphones-wave', 'Aurora Wave ANC', 'AUR-WAVE-ANC', 'audio', 'aurora', 54900, 'Беспроводные наушники с шумоподавлением.', 'До 36 часов музыки и комфортная посадка для долгих поездок.', ['Подключение' => 'Bluetooth 5.3', 'Автономность' => '36 часов', 'Цвет' => 'Графит'], 4.91, 214, ['#22223B', '#9A8C98'], [
                ['name' => 'Графит', 'sku' => 'AUR-WAVE-ANC-GR', 'attributes' => ['Цвет' => 'Графит'], 'colors' => ['#22223B', '#9A8C98']],
                ['name' => 'Песочный', 'sku' => 'AUR-WAVE-ANC-SD', 'attributes' => ['Цвет' => 'Песочный'], 'colors' => ['#7F5539', '#DDB892']],
            ]),
            $this->product('speaker-room', 'Aurora Room Speaker', 'AUR-ROOM-SPK', 'audio', 'aurora', 38900, 'Домашняя Bluetooth-колонка с насыщенным звуком.', 'Подходит для кухни, гостиной и рабочего кабинета.', ['Подключение' => 'Bluetooth, AUX', 'Автономность' => '14 часов', 'Цвет' => 'Темно-синий'], 4.67, 71, ['#023047', '#219EBC']),
            $this->product('kettle-steel-pro', 'Nordwerk Steel Kettle Pro', 'NOR-KETTLE-PRO', 'kitchen', 'nordwerk', 24900, 'Стальной чайник с точной настройкой температуры.', 'Пять температурных режимов и поддержание тепла до часа.', ['Материал' => 'Нержавеющая сталь', 'Мощность' => '2200 Вт', 'Объем' => '1.7 л'], 4.88, 173, ['#495057', '#ADB5BD']),
            $this->product('coffee-grinder-burr', 'Nordwerk Burr Grinder', 'NOR-GRINDER-BURR', 'kitchen', 'nordwerk', 31900, 'Жерновая кофемолка для стабильного помола.', 'Регулировка степени помола для турки, воронки и френч-пресса.', ['Материал' => 'Сталь, пластик', 'Мощность' => '160 Вт', 'Режимы' => '18 степеней помола'], 4.71, 96, ['#432818', '#BB9457']),
            $this->product('pan-cast-iron', 'Nordwerk Cast Pan 28', 'NOR-PAN-28', 'kitchen', 'nordwerk', 18900, 'Чугунная сковорода для плиты и духовки.', 'Равномерно прогревается и подходит для ежедневной готовки.', ['Материал' => 'Чугун', 'Диаметр' => '28 см', 'Совместимость' => 'Все типы плит'], 4.79, 141, ['#212529', '#6C757D']),
            $this->product('desk-lamp-flex', 'Mellow Flex Lamp', 'MEL-LAMP-FLEX', 'interior', 'mellow', 17900, 'Настольная лампа с мягкой регулировкой света.', 'Три температуры света и гибкая стойка для рабочего стола.', ['Материал' => 'Металл', 'Цвет' => 'Шалфейный', 'Мощность' => '8 Вт'], 4.63, 58, ['#588157', '#DAD7CD']),
            $this->product('storage-baskets-set', 'Mellow Storage Baskets', 'MEL-BASKETS-3', 'interior', 'mellow', 12900, 'Набор текстильных корзин для хранения.', 'Три размера для шкафа, прихожей или детской комнаты.', ['Материал' => 'Хлопок, полиэстер', 'Цвет' => 'Бежевый', 'Комплектация' => '3 корзины'], 4.76, 202, ['#A98467', '#F0EAD2']),
            $this->product('blanket-soft-weave', 'Mellow Soft Weave Blanket', 'MEL-BLANKET', 'interior', 'mellow', 14900, 'Мягкий плед с фактурным плетением.', 'Легкий плед для дивана, кресла или прохладного вечера на террасе.', ['Материал' => 'Хлопок', 'Цвет' => 'Терракотовый', 'Размер' => '140 x 200 см'], 4.84, 117, ['#9C6644', '#DDB892']),
            $this->product('cordless-drill-v18', 'Vertex Cordless Drill V18', 'VER-DRILL-V18', 'power-tools', 'vertex', 69900, 'Аккумуляторная дрель-шуруповерт для ремонта.', 'Два аккумулятора и кейс для домашних и выездных работ.', ['Напряжение' => '18 В', 'Комплектация' => '2 аккумулятора, кейс', 'Крутящий момент' => '54 Нм'], 4.92, 186, ['#003049', '#F77F00']),
            $this->product('impact-driver-v18', 'Vertex Impact Driver V18', 'VER-IMPACT-V18', 'power-tools', 'vertex', 74900, 'Ударный гайковерт для крепежных работ.', 'Компактный корпус, подсветка зоны работы и надежный аккумулятор.', ['Напряжение' => '18 В', 'Комплектация' => 'Аккумулятор, кейс', 'Крутящий момент' => '180 Нм'], 4.86, 77, ['#264653', '#E9C46A']),
            $this->product('toolkit-home-56', 'Vertex Home Toolkit 56', 'VER-TOOLS-56', 'power-tools', 'vertex', 42900, 'Универсальный набор ручного инструмента.', 'Базовые инструменты для сборки мебели и мелкого ремонта.', ['Комплектация' => '56 предметов', 'Материал' => 'Хромованадиевая сталь', 'Кейс' => 'Да'], 4.81, 244, ['#2A9D8F', '#E9C46A']),
            $this->product('yoga-mat-calm', 'Trailhead Calm Yoga Mat', 'TRA-MAT-CALM', 'fitness', 'trailhead', 11900, 'Нескользящий коврик для йоги и растяжки.', 'Мягкий материал легко очищается и не скользит на полу.', ['Материал' => 'TPE', 'Цвет' => 'Оливковый', 'Размер' => '183 x 61 см'], 4.69, 132, ['#386641', '#A7C957']),
            $this->product('fitness-bands-set', 'Trailhead Fitness Bands', 'TRA-BANDS-5', 'fitness', 'trailhead', 8900, 'Комплект фитнес-резинок с разным сопротивлением.', 'Пять уровней нагрузки для разминки и силовых упражнений.', ['Материал' => 'Латекс', 'Комплектация' => '5 резинок', 'Вес' => '450 г'], 4.73, 167, ['#1D3557', '#E63946']),
            $this->product('dumbbell-adjustable', 'Trailhead Adjustable Dumbbell', 'TRA-DUMBBELL-10', 'fitness', 'trailhead', 34900, 'Разборная гантель для домашних тренировок.', 'Компактный набор дисков помогает менять нагрузку без лишнего инвентаря.', ['Материал' => 'Сталь', 'Вес' => 'До 10 кг', 'Комплектация' => 'Гриф, диски, замки'], 4.77, 93, ['#457B9D', '#E63946']),
            $this->product('standing-desk-organizer', 'Paperfox Desk Organizer', 'PAP-ORG-STAND', 'desk', 'paperfox', 10900, 'Вертикальный органайзер для документов и заметок.', 'Освобождает место на столе и держит важные бумаги под рукой.', ['Материал' => 'Металл', 'Цвет' => 'Сиреневый', 'Отделения' => '5'], 4.65, 104, ['#5A189A', '#E0AAFF']),
            $this->product('cork-desk-mat', 'Paperfox Cork Desk Mat', 'PAP-MAT-CORK', 'desk', 'paperfox', 9900, 'Пробковый коврик для рабочего стола.', 'Защищает поверхность стола и делает рабочее место аккуратнее.', ['Материал' => 'Пробка', 'Цвет' => 'Натуральный', 'Размер' => '80 x 40 см'], 4.72, 68, ['#7F4F24', '#DDA15E']),
            $this->product('notebook-grid-trio', 'Paperfox Grid Notebook Trio', 'PAP-NOTE-3', 'desk', 'paperfox', 5900, 'Набор тетрадей в точку для планирования.', 'Три спокойных оттенка и плотная бумага для заметок и схем.', ['Материал' => 'Бумага', 'Цвет' => 'Ассорти', 'Комплектация' => '3 тетради'], 4.61, 51, ['#7209B7', '#F7B801']),
            $this->product('monitor-stand-oak', 'Paperfox Monitor Stand Oak', 'PAP-STAND-OAK', 'desk', 'paperfox', 19900, 'Подставка для монитора с местом для мелочей.', 'Поднимает экран на удобную высоту и помогает организовать стол.', ['Материал' => 'Дуб, металл', 'Цвет' => 'Натуральный', 'Ширина' => '52 см'], 4.87, 119, ['#6F4518', '#BC8A5F']),
        ], $this->generatedProducts());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generatedProducts(): array
    {
        $templates = [
            ['smart-home', 'aurora', 'Датчик климата', 'AUR-CLIMATE', 12900, ['connection' => 'Zigbee', 'color' => 'Белый', 'room' => 'Для дома'], ['#003049', '#669BBC']],
            ['audio', 'aurora', 'Портативная колонка', 'AUR-PORTABLE', 21900, ['connection' => 'Bluetooth 5.3', 'battery_life' => '18 часов', 'color' => 'Темно-синий'], ['#023047', '#219EBC']],
            ['kitchen', 'nordwerk', 'Погружной блендер', 'NOR-BLENDER', 26900, ['material' => 'Сталь, пластик', 'power' => '900 Вт', 'kit' => '3 насадки'], ['#6A040F', '#F48C06']],
            ['interior', 'mellow', 'Настенная полка', 'MEL-SHELF', 11900, ['material' => 'Дерево', 'color' => 'Натуральный', 'width' => '60 см'], ['#606C38', '#DDA15E']],
            ['power-tools', 'vertex', 'Набор сверл', 'VER-DRILLS', 15900, ['voltage' => 'Для 18 В', 'kit' => '18 предметов', 'material' => 'Сталь'], ['#264653', '#E9C46A']],
            ['fitness', 'trailhead', 'Массажный ролик', 'TRA-ROLLER', 9900, ['material' => 'EVA', 'weight' => '650 г', 'color' => 'Синий'], ['#386641', '#A7C957']],
            ['desk', 'paperfox', 'Лоток для документов', 'PAP-TRAY', 7900, ['material' => 'Металл', 'color' => 'Графит', 'kit' => '2 уровня'], ['#3C096C', '#FFB703']],
        ];
        $products = [];

        for ($index = 20; $index < self::PRODUCT_COUNT; $index++) {
            [$category, $brand, $name, $skuPrefix, $price, $attributes, $colors] = $templates[$index % count($templates)];
            $number = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            $products[] = $this->product(
                slug: "catalog-{$category}-{$number}",
                name: "{$name} {$number}",
                sku: "{$skuPrefix}-{$number}",
                category: $category,
                brand: $brand,
                price: $price + ($index % 17) * 300,
                short: "{$name} из расширенного каталога.",
                description: "Серийная модель {$number} для проверки поиска, фильтрации, сортировки и складских сценариев.",
                attributes: $attributes,
                rating: 3.80 + ($index % 116) / 100,
                ratingCount: 12 + ($index * 7) % 480,
                colors: $colors,
            ) + [
                'gallery' => false,
                'image' => "series-{$category}",
                'image_title' => $name,
            ];
        }

        return $products;
    }

    /**
     * @param  array<string, string>  $attributes
     * @param  array<int, string>  $colors
     * @param  array<int, array<string, mixed>>  $offers
     * @return array<string, mixed>
     */
    private function product(string $slug, string $name, string $sku, string $category, string $brand, int $price, string $short, string $description, array $attributes, float $rating, int $ratingCount, array $colors, array $offers = []): array
    {
        $attributes = collect($attributes)
            ->mapWithKeys(fn (string $value, string $name): array => [$this->attributeKey($name) => $value])
            ->all();

        return compact('slug', 'name', 'sku', 'category', 'brand', 'price', 'short', 'description', 'attributes', 'rating', 'ratingCount', 'colors', 'offers') + [
            'rating_count' => $ratingCount,
        ];
    }

    private function attributeKey(string $name): string
    {
        return [
            'Автономность' => 'battery_life',
            'Вес' => 'weight',
            'Гарантия' => 'warranty',
            'Диаметр' => 'diameter',
            'Кейс' => 'case',
            'Комплектация' => 'kit',
            'Крутящий момент' => 'torque',
            'Материал' => 'material',
            'Мощность' => 'power',
            'Напряжение' => 'voltage',
            'Объем' => 'volume',
            'Отделения' => 'sections',
            'Подключение' => 'connection',
            'Режимы' => 'modes',
            'Размер' => 'size',
            'Совместимость' => 'compatibility',
            'Цвет' => 'color',
            'Ширина' => 'width',
        ][$name] ?? $name;
    }
}
