<script setup lang="ts">
const customer = useCustomerState();
const searchInput = ref('');
const favorites = computed(() => customer.state.value.favorites);

const submitSearch = () => {
    const query = searchInput.value.trim();

    navigateTo({
        path: '/search/',
        query: query ? { q: query } : {},
    });
};

function formatMoney(amountMinor: number, currency: string): string {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amountMinor / 100);
}

onMounted(() => customer.initialize());

useHead({
    title: 'Избранное | StockFlow Market',
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
        span Избранное

    section.market-page-heading
        div
            p.eyebrow Сохранённые товары
            h1 Избранное
            p Соберите список покупок и добавьте нужные товары в корзину.
        span.cart-mode {{ favorites.length }} товаров

    section.favorites-content.market-page-content
        .favorites-grid(v-if="favorites.length")
            article.favorite-product-card(v-for="item in favorites" :key="item.product_id")
                button.favorite-button.active(
                    type="button"
                    aria-label="Убрать из избранного"
                    @click="customer.toggleFavorite(item)"
                ) ♥
                NuxtLink.product-card-media(:to="item.url ?? '/catalog/'")
                    img(v-if="item.image_url" :src="item.image_url" :alt="item.product_name")
                    span(v-else) SF
                .product-card-copy
                    small {{ item.sku }}
                    NuxtLink.product-card-title(:to="item.url ?? '/catalog/'") {{ item.product_name }}
                    .favorite-product-bottom
                        strong(v-if="item.price") {{ formatMoney(item.price.amount_minor, item.price.currency) }}
                        small(v-else) Цена по запросу
                        button.cart-add(type="button" aria-label="Добавить в корзину" @click="customer.addCartItem(item)") +
        .market-empty-state(v-else)
            strong В избранном пока ничего нет
            p Отмечайте товары сердцем, чтобы быстро вернуться к ним позже.
            NuxtLink.hero-primary(to="/catalog/") Перейти в каталог

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Сохраняйте товары и возвращайтесь к ним в удобное время.
        NuxtLink(to="/cart/") Перейти в корзину
</template>
