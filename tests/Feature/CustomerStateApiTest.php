<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
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
            ->assertJsonCount(0, 'data.cart.items');

        $this->deleteJson('/api/customer-state/favorites/'.$product->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.favorites');
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
}
