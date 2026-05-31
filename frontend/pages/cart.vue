<script setup lang="ts">
const customer = useCustomerState();
const checkoutPending = ref(false);
const checkoutMessage = ref('');
const checkoutError = ref('');

const cart = computed(() => customer.state.value.cart);
const allSelected = computed(
    () => cart.value.items.length > 0 && cart.value.items.every((item) => item.is_selected),
);

const toggleAll = (event: Event) => {
    customer.selectAllCartItems((event.target as HTMLInputElement).checked);
};

const toggleItem = (productId: number, event: Event) => {
    customer.selectCartItem(productId, (event.target as HTMLInputElement).checked);
};

const checkout = async (selectedOnly: boolean) => {
    checkoutPending.value = true;
    checkoutMessage.value = '';
    checkoutError.value = '';

    try {
        const order = await customer.checkout(selectedOnly);
        checkoutMessage.value = `Создан черновик заказа №${order.id}.`;
    } catch {
        checkoutError.value = 'Не удалось создать заказ. Проверьте выбранные товары и доступность API.';
    } finally {
        checkoutPending.value = false;
    }
};

function formatMoney(amountMinor: number, currency: string | null): string {
    if (!currency) {
        return `${(amountMinor / 100).toLocaleString('ru-RU')} у. е.`;
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
    }).format(amountMinor / 100);
}

onMounted(() => customer.initialize());

useHead({
    title: 'Корзина | StockFlow Market',
    htmlAttrs: {
        lang: 'ru',
    },
});
</script>

<template lang="pug">
main.shell.cart-shell
    header.cart-header
        NuxtLink.brand(to="/")
            span.brand-mark SF
            span StockFlow Market
        NuxtLink.back-link(to="/") Вернуться в каталог

    section.cart-title
        div
            p.eyebrow Оформление заказа
            h1 Корзина
            p.lede Выберите нужные товары или оформите заказ целиком.
        span.cart-mode {{ customer.user.value ? 'Корзина аккаунта' : 'Гостевая корзина' }}

    section.cart-layout
        article.panel.cart-items-panel
            .panel-heading
                h2 Товары
                span {{ cart.summary.items_count }} шт.

            label.select-all(v-if="cart.items.length")
                input(type="checkbox" :checked="allSelected" @change="toggleAll")
                span Выбрать все товары

            ul.cart-page-list(v-if="cart.items.length")
                li(v-for="item in cart.items" :key="item.product_id")
                    input(
                        type="checkbox"
                        :checked="item.is_selected"
                        :aria-label="`Выбрать ${item.product_name}`"
                        @change="toggleItem(item.product_id, $event)"
                    )
                    .cart-product-image
                        img(v-if="item.image_url" :src="item.image_url" :alt="item.product_name")
                        span(v-else) SF
                    .cart-product-copy
                        strong {{ item.product_name }}
                        code {{ item.sku }}
                        b(v-if="item.price") {{ formatMoney(item.price.amount_minor, item.price.currency) }}
                        small(v-else) Цена не указана
                    .quantity-actions
                        button(type="button" @click="customer.setCartItem(item, item.quantity - 1)") −
                        span {{ item.quantity }}
                        button(type="button" @click="customer.setCartItem(item, item.quantity + 1)") +
                    button.cart-remove(type="button" @click="customer.removeCartItem(item.product_id)") Убрать

            p.empty-state(v-else) Корзина пока пуста. Добавьте товары из каталога.

            template(v-if="cart.removed_items.length")
                .removed-heading
                    h2 Удалённые товары
                    span Можно восстановить
                ul.removed-list
                    li(v-for="item in cart.removed_items" :key="item.product_id")
                        div
                            strong {{ item.product_name }}
                            code {{ item.sku }}
                        button(type="button" @click="customer.restoreCartItem(item.product_id)") Восстановить

        aside.panel.cart-summary-panel
            .panel-heading
                h2 Итого
                span {{ cart.summary.selected_items_count }} шт. выбрано
            dl.cart-totals
                dt Все товары
                dd {{ formatMoney(cart.summary.amount_minor, cart.summary.currency) }}
                dt Выбрано
                dd {{ formatMoney(cart.summary.selected_amount_minor, cart.summary.currency) }}
            button.primary-button(
                type="button"
                :disabled="checkoutPending || cart.summary.selected_items_count === 0"
                @click="checkout(true)"
            ) Купить выбранное
            button.secondary-button(
                type="button"
                :disabled="checkoutPending || cart.items.length === 0"
                @click="checkout(false)"
            ) Купить всё
            p.account-note(v-if="!customer.user.value")
                | Корзина сохранена в этом браузере. После входа товары будут перенесены в аккаунт.
            p.checkout-message(v-if="checkoutMessage") {{ checkoutMessage }}
            p.form-error(v-if="checkoutError") {{ checkoutError }}
</template>
