<script setup lang="ts">
const customer = useCustomerState();
const searchInput = ref('');
const checkoutPending = ref(false);
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

const submitSearch = () => {
    const query = searchInput.value.trim();

    navigateTo({
        path: '/search/',
        query: query ? { q: query } : {},
    });
};

const checkout = async (selectedOnly: boolean) => {
    checkoutPending.value = true;
    checkoutError.value = '';

    try {
        const order = await customer.checkout(selectedOnly);
        await navigateTo(`/checkout/?order=${order.id}`);
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
main.market-page-shell
    header.market-header
        .header-main
            NuxtLink.market-logo(to="/")
                span.brand-mark SF
                span
                    b StockFlow
                    small market
            form.market-search(@submit.prevent="submitSearch")
                input(
                    v-model="searchInput"
                    type="search"
                    name="q"
                    placeholder="Найти товары, бренды и категории"
                    aria-label="Поиск по каталогу"
                )
                button(type="submit") Найти
            nav.header-actions(aria-label="Быстрые действия")
                NuxtLink.action-link(to="/favorites/")
                    span.action-icon ♡
                    span
                        small Избранное
                        b {{ customer.favoriteCount }}
                NuxtLink.action-link(to="/cart/")
                    span.action-icon ◼
                    span
                        small Корзина
                        b {{ customer.cartCount }}
        nav.category-nav(aria-label="Разделы магазина")
            NuxtLink.category-nav-all(to="/catalog/") Все категории
            NuxtLink(to="/catalog/") Каталог
            NuxtLink(to="/favorites/") Избранное

    section.catalog-breadcrumbs(aria-label="Хлебные крошки")
        NuxtLink(to="/") Главная
        span /
        span Корзина

    section.market-page-heading
        div
            p.eyebrow Оформление заказа
            h1 Корзина
            p Проверьте количество товаров и выберите позиции для покупки.
        span.cart-mode {{ customer.user.value ? 'Корзина аккаунта' : 'Гостевая корзина' }}

    section.cart-layout.market-page-content
        article.panel.cart-items-panel
            .panel-heading
                h2 Товары в корзине
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
                    NuxtLink.cart-product-image(:to="item.url ?? '/catalog/'")
                        img(v-if="item.image_url" :src="item.image_url" :alt="item.product_name")
                        span(v-else) SF
                    .cart-product-copy
                        NuxtLink.cart-product-name(:to="item.url ?? '/catalog/'") {{ item.product_name }}
                        code {{ item.sku }}
                        b(v-if="item.price") {{ formatMoney(item.price.amount_minor, item.price.currency) }}
                        small(v-else) Цена не указана
                    .quantity-actions
                        button(type="button" @click="customer.setCartItem(item, item.quantity - 1)") −
                        span {{ item.quantity }}
                        button(type="button" @click="customer.setCartItem(item, item.quantity + 1)") +
                    button.cart-remove(type="button" @click="customer.removeCartItem(item.product_id)") Убрать

            .market-empty-state(v-else)
                strong Корзина пока пуста
                p Добавьте товары из каталога, чтобы оформить доставку.
                NuxtLink.hero-primary(to="/catalog/") Перейти в каталог

            template(v-if="cart.removed_items.length")
                .removed-heading
                    h2 Недавно удалённые
                    span Можно восстановить
                ul.removed-list
                    li(v-for="item in cart.removed_items" :key="item.product_id")
                        div
                            strong {{ item.product_name }}
                            code {{ item.sku }}
                        button(type="button" @click="customer.restoreCartItem(item.product_id)") Восстановить

        aside.cart-side-column
            section.panel.cart-summary-panel
                .panel-heading
                    h2 Ваш заказ
                    span {{ cart.summary.selected_items_count }} шт. выбрано
                dl.cart-totals
                    dt Все товары
                    dd {{ formatMoney(cart.summary.amount_minor, cart.summary.currency) }}
                    dt К оплате
                    dd {{ formatMoney(cart.summary.selected_amount_minor, cart.summary.currency) }}
                button.primary-button(
                    type="button"
                    :disabled="checkoutPending || cart.summary.selected_items_count === 0"
                    @click="checkout(true)"
                ) Перейти к оформлению
                button.secondary-button(
                    type="button"
                    :disabled="checkoutPending || cart.items.length === 0"
                    @click="checkout(false)"
                ) Оформить все товары
                p.account-note(v-if="!customer.user.value")
                    | Корзина сохранена в этом браузере. После входа товары будут перенесены в аккаунт.
                p.form-error(v-if="checkoutError") {{ checkoutError }}

            section.cart-benefits
                article
                    b 24
                    span Доставка от 24 часов
                article
                    b ✓
                    span Оплата при оформлении
                article
                    b ↺
                    span Простой возврат

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Товары для дома, работы и отдыха с актуальными остатками на складах.
        NuxtLink(to="/catalog/") Вернуться в каталог
</template>
