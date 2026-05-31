<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Pricing\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_state_is_merged_on_registration_and_available_in_a_new_session(): void
    {
        $scanner = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $terminal = $this->createProduct('Checkout Terminal', 'checkout-terminal', 'TERM-001');

        $this->postJson('/api/session/register', [
            'name' => 'Alexandra',
            'email' => 'alexandra@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->postJson('/api/customer-state/merge', [
            'cart_items' => [
                ['product_id' => $scanner->id, 'quantity' => 2],
            ],
            'favorite_product_ids' => [$scanner->id, $terminal->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.cart.items.0.product_id', $scanner->id)
            ->assertJsonPath('data.cart.items.0.quantity', 2)
            ->assertJsonCount(2, 'data.favorites');

        $this->deleteJson('/api/session')->assertOk();

        $this->postJson('/api/session/login', [
            'email' => 'alexandra@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer_state.cart.items.0.product_id', $scanner->id)
            ->assertJsonPath('data.customer_state.cart.items.0.quantity', 2)
            ->assertJsonCount(2, 'data.customer_state.favorites');
    }

    public function test_authenticated_customer_can_update_cart_and_favorites(): void
    {
        $product = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');

        $this->postJson('/api/session/register', [
            'name' => 'Alexandra',
            'email' => 'alexandra@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->putJson('/api/customer-state/cart/items/'.$product->id, ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.cart.items.0.quantity', 3);

        $this->putJson('/api/customer-state/favorites/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.favorites.0.product_id', $product->id);

        $this->deleteJson('/api/customer-state/cart/items/'.$product->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.cart.items')
            ->assertJsonCount(1, 'data.cart.removed_items');

        $this->deleteJson('/api/customer-state/favorites/'.$product->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.favorites');
    }

    public function test_customer_can_select_remove_restore_and_checkout_cart_items(): void
    {
        $scanner = $this->createProduct('Wireless Scanner', 'wireless-scanner', 'SCAN-001');
        $terminal = $this->createProduct('Checkout Terminal', 'checkout-terminal', 'TERM-001');
        $this->createPrice($scanner, 'retail', 1000);
        $this->createPrice($scanner, 'sale', 800);
        $this->createPrice($terminal, 'retail', 2000);

        $this->postJson('/api/session/register', [
            'name' => 'Alexandra',
            'email' => 'alexandra@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->putJson('/api/customer-state/cart/items/'.$scanner->id, ['quantity' => 2])->assertOk();
        $cart = $this->putJson('/api/customer-state/cart/items/'.$terminal->id, ['quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.cart.summary.items_count', 3)
            ->assertJsonPath('data.cart.summary.amount_minor', 3600)
            ->json('data.cart');

        $this->putJson('/api/customer-state/cart/items/'.$terminal->id.'/selection', ['is_selected' => false])
            ->assertOk()
            ->assertJsonPath('data.cart.summary.selected_items_count', 2)
            ->assertJsonPath('data.cart.summary.selected_amount_minor', 1600);

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
            'product_ids' => [$scanner->id],
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.price_type', 'sale')
            ->assertJsonPath('data.total_amount_minor', 1600);

        $this->deleteJson('/api/customer-state/cart/items/'.$scanner->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.cart.items')
            ->assertJsonCount(1, 'data.cart.removed_items')
            ->assertJsonPath('data.cart.summary.amount_minor', 2000);

        $this->putJson('/api/customer-state/cart/items/'.$scanner->id.'/restore')
            ->assertOk()
            ->assertJsonCount(2, 'data.cart.items')
            ->assertJsonCount(0, 'data.cart.removed_items');

        $this->putJson('/api/customer-state/cart/selection', ['is_selected' => true])
            ->assertOk()
            ->assertJsonPath('data.cart.summary.selected_items_count', 3)
            ->assertJsonPath('data.cart.summary.selected_amount_minor', 3600);

        $this->postJson('/api/orders/draft', [
            'cart_id' => $cart['id'],
            'product_ids' => [$scanner->id, $terminal->id],
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.total_amount_minor', 3600);
    }

    public function test_customer_state_mutations_reject_unknown_products(): void
    {
        $this->postJson('/api/session/register', [
            'name' => 'Alexandra',
            'email' => 'alexandra@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->putJson('/api/customer-state/cart/items/999', ['quantity' => 1])->assertNotFound();
        $this->putJson('/api/customer-state/favorites/999')->assertNotFound();
    }

    private function createProduct(string $name, string $slug, string $sku): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'devices'],
            ['name' => 'Devices', 'is_active' => true],
        );

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function createPrice(Product $product, string $type, int $amountMinor): ProductPrice
    {
        return ProductPrice::query()->create([
            'product_id' => $product->id,
            'price_type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
        ]);
    }
}
