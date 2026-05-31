<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('storage_files', function (Blueprint $table) {
            $table->id();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('source_url')->nullable();
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index('checksum');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->foreignId('image_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
        });

        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->foreignId('logo_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->foreignId('image_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
        });

        Schema::table('catalog_product_offers', function (Blueprint $table) {
            $table->foreignId('image_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
        });

        Schema::table('homepage_blocks', function (Blueprint $table) {
            $table->foreignId('image_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
        });

        $fileIds = [];
        $createFile = function (string $url) use (&$fileIds): int {
            if (isset($fileIds[$url])) {
                return $fileIds[$url];
            }

            return $fileIds[$url] = (int) DB::table('storage_files')->insertGetId([
                'source_url' => $url,
                'original_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'legacy-file',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        foreach (DB::table('catalog_products')->whereNotNull('image_url')->get(['id', 'image_url']) as $product) {
            DB::table('catalog_products')
                ->where('id', $product->id)
                ->update(['image_file_id' => $createFile($product->image_url)]);
        }

        foreach (DB::table('catalog_product_offers')->whereNotNull('image_url')->get(['id', 'image_url']) as $offer) {
            DB::table('catalog_product_offers')
                ->where('id', $offer->id)
                ->update(['image_file_id' => $createFile($offer->image_url)]);
        }

        foreach (DB::table('homepage_blocks')->whereNotNull('settings')->get(['id', 'settings']) as $block) {
            $settings = json_decode($block->settings, true);

            if (! is_array($settings) || ! isset($settings['image_url']) || ! is_string($settings['image_url'])) {
                continue;
            }

            $imageUrl = $settings['image_url'];
            unset($settings['image_url']);

            DB::table('homepage_blocks')
                ->where('id', $block->id)
                ->update([
                    'image_file_id' => $createFile($imageUrl),
                    'settings' => $settings === [] ? null : json_encode($settings),
                ]);
        }

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });

        Schema::table('catalog_product_offers', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('image_url')->nullable();
        });

        Schema::table('catalog_product_offers', function (Blueprint $table) {
            $table->string('image_url')->nullable();
        });

        foreach (DB::table('catalog_products')->whereNotNull('image_file_id')->get(['id', 'image_file_id']) as $product) {
            DB::table('catalog_products')
                ->where('id', $product->id)
                ->update([
                    'image_url' => DB::table('storage_files')->where('id', $product->image_file_id)->value('source_url'),
                ]);
        }

        foreach (DB::table('catalog_product_offers')->whereNotNull('image_file_id')->get(['id', 'image_file_id']) as $offer) {
            DB::table('catalog_product_offers')
                ->where('id', $offer->id)
                ->update([
                    'image_url' => DB::table('storage_files')->where('id', $offer->image_file_id)->value('source_url'),
                ]);
        }

        foreach (DB::table('homepage_blocks')->whereNotNull('image_file_id')->get(['id', 'image_file_id', 'settings']) as $block) {
            $settings = json_decode($block->settings, true) ?? [];
            $settings['image_url'] = DB::table('storage_files')->where('id', $block->image_file_id)->value('source_url');

            DB::table('homepage_blocks')
                ->where('id', $block->id)
                ->update(['settings' => json_encode($settings)]);
        }

        Schema::table('homepage_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_file_id');
        });

        Schema::table('catalog_product_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_file_id');
        });

        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_file_id');
        });

        Schema::table('catalog_brands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_file_id');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_file_id');
        });

        Schema::dropIfExists('storage_files');
    }
};
