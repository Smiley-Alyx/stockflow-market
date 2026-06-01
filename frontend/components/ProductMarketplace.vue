<script setup lang="ts">
import type { CatalogCategory, CatalogProduct } from '~/composables/useCatalogApi';

defineOptions({ name: 'ProductMarketplace' });

const route = useRoute();
const catalogApi = useCatalogApi();
const customer = useCustomerState();
const searchInput = ref('');
const selectedImageUrl = ref<string | null>(null);
const selectedOfferId = ref<number | null>(null);
const selectedCityCode = ref('');
const quantity = ref(1);

const slug = computed(() => route.path.split('/').filter(Boolean).at(-1) ?? '');
const { data: categories } = await useAsyncData('product-page-categories', () => catalogApi.fetchCategoryTree());
const {
    data: product,
    error: productError,
    pending: productPending,
} = await useAsyncData(
    'product-page-card',
    () => catalogApi.fetchProduct(slug.value),
    {
        watch: [slug],
    },
);

const rootCategories = computed(() => (categories.value ?? []).filter((category) => category.image_url));
const breadcrumbs = computed(() => product.value?.category?.breadcrumbs ?? []);
const selectedOffer = computed(() => product.value?.offers.find((offer) => offer.id === selectedOfferId.value) ?? null);
const visibleWarehouses = computed(() => {
    if (!selectedCityCode.value) {
        return product.value?.warehouses ?? [];
    }

    return product.value?.warehouses.filter((warehouse) => warehouse.city_code === selectedCityCode.value) ?? [];
});
const warehouseCities = computed(() => {
    const cities = new Map<string, string>();

    for (const warehouse of product.value?.warehouses ?? []) {
        if (warehouse.city_code && warehouse.city_name) {
            cities.set(warehouse.city_code, warehouse.city_name);
        }
    }

    return [...cities.entries()].map(([code, name]) => ({ code, name }));
});
const gallery = computed(() => {
    if (!product.value) {
        return [];
    }

    return [
        ...(product.value.image_url ? [{ id: 'main', title: product.value.name, url: product.value.image_url }] : []),
        ...product.value.gallery.map((image) => ({
            id: String(image.id),
            title: image.title ?? product.value?.name ?? '',
            url: image.url,
        })),
    ];
});

watch(
    product,
    (nextProduct) => {
        selectedImageUrl.value = nextProduct?.image_url ?? nextProduct?.gallery[0]?.url ?? null;
        selectedOfferId.value = null;
    },
    { immediate: true },
);

const submitSearch = () => {
    const query = searchInput.value.trim();

    navigateTo({
        path: '/search/',
        query: query ? { q: query } : {},
    });
};

const selectOffer = (offer: CatalogProduct['offers'][number]) => {
    selectedOfferId.value = offer.id;

    if (offer.image_url) {
        selectedImageUrl.value = offer.image_url;
    }
};

const addToCart = async () => {
    if (!product.value) {
        return;
    }

    const currentQuantity =
        customer.state.value.cart.items.find((item) => item.product_id === product.value?.id)?.quantity ?? 0;

    await customer.setCartItem(product.value, currentQuantity + quantity.value);
};

function formatMoney(amountMinor: number, currency: string): string {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amountMinor / 100);
}

function formatFileSize(size: number | null): string {
    if (size === null) {
        return '';
    }

    return size < 1024 ? `${size} Б` : `${(size / 1024).toFixed(1)} КБ`;
}

function documentLabel(type: string): string {
    return {
        instruction: 'Инструкция',
        certificate: 'Сертификат',
        attachment: 'Материал',
    }[type] ?? 'Документ';
}

function filterLabel(name: string): string {
    return {
        battery_life: 'Время работы',
        color: 'Цвет',
        connection: 'Подключение',
        kit: 'Комплектация',
        material: 'Материал',
        power: 'Мощность',
        size: 'Размер',
        voltage: 'Напряжение',
        warranty: 'Гарантия',
        weight: 'Вес',
    }[name] ?? name;
}

onMounted(() => customer.initialize());

useHead(() => ({
    title: product.value ? `${product.value.name} | StockFlow Market` : 'Товар | StockFlow Market',
    htmlAttrs: {
        lang: 'ru',
    },
}));
</script>

<template lang="pug">
main.product-page-shell
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
                    placeholder="Найти в каталоге"
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

        nav.category-nav(aria-label="Категории каталога")
            NuxtLink.category-nav-all(to="/catalog/") Все категории
            NuxtLink(v-for="category in rootCategories" :key="category.id" :to="category.url ?? '/catalog/'")
                | {{ category.name }}

    section.catalog-breadcrumbs(aria-label="Хлебные крошки")
        NuxtLink(to="/") Главная
        span /
        NuxtLink(to="/catalog/") Каталог
        template(v-for="crumb in breadcrumbs" :key="crumb.id")
            span /
            NuxtLink(:to="crumb.url") {{ crumb.name }}
        template(v-if="product")
            span /
            span {{ product.name }}

    p.product-page-message(v-if="productPending") Загружаем карточку товара…
    p.product-page-message(v-else-if="productError || !product") Товар не найден или временно недоступен.

    template(v-else)
        section.product-page-main
            .product-gallery-column
                .product-thumbnail-list(v-if="gallery.length > 1 || product.offers.length")
                    button(
                        v-for="image in gallery"
                        :key="image.id"
                        type="button"
                        :class="{ active: selectedImageUrl === image.url }"
                        @click="selectedImageUrl = image.url"
                    )
                        img(v-if="image.url" :src="image.url" :alt="image.title")
                    button(
                        v-for="offer in product.offers"
                        :key="`offer-${offer.id}`"
                        type="button"
                        :class="{ active: selectedOfferId === offer.id }"
                        @click="selectOffer(offer)"
                    )
                        img(v-if="offer.image_url" :src="offer.image_url" :alt="offer.name")
                .product-page-image
                    img(v-if="selectedImageUrl" :src="selectedImageUrl" :alt="product.name")
                    span(v-else) SF

            .product-page-copy
                p.product-brand-line
                    span {{ product.brand?.name ?? 'StockFlow Market' }}
                    code {{ product.sku }}
                h1 {{ product.name }}
                .product-page-rating
                    b ★ {{ product.rating?.toFixed(1) ?? 'Новинка' }}
                    span {{ product.rating_count }} оценок
                    span Артикул: {{ selectedOffer?.sku ?? product.sku }}
                p.product-page-short {{ product.short_description }}

                section.offer-selector(v-if="product.offers.length")
                    h2 Варианты товара
                    .offer-grid
                        button(
                            v-for="offer in product.offers"
                            :key="offer.id"
                            type="button"
                            :class="{ active: selectedOfferId === offer.id }"
                            @click="selectOffer(offer)"
                        )
                            img(v-if="offer.image_url" :src="offer.image_url" :alt="offer.name")
                            span
                                strong {{ offer.name }}
                                small {{ offer.sku }}

                ul.product-key-attributes(v-if="product.card_attributes.length")
                    li(v-for="attribute in product.card_attributes" :key="attribute.name")
                        span {{ filterLabel(attribute.name) }}
                        b {{ attribute.value }}

            aside.product-buy-box
                template(v-if="product.price")
                    span.buy-box-caption Цена
                    del(v-if="product.price.has_discount")
                        | {{ formatMoney(product.price.original_amount_minor, product.price.currency) }}
                    strong.product-page-price {{ formatMoney(product.price.amount_minor, product.price.currency) }}
                    span.discount-badge(v-if="product.price.has_discount") Выгода {{ product.price.discount_percent }}%
                p.buy-box-stock(:class="{ unavailable: !product.availability.in_stock }")
                    | {{ product.availability.in_stock ? `В наличии: ${product.availability.available_quantity} шт.` : 'Нет в наличии' }}
                .quantity-picker
                    button(type="button" :disabled="quantity === 1" @click="quantity--") −
                    b {{ quantity }}
                    button(type="button" @click="quantity++") +
                button.buy-button(type="button" :disabled="!product.availability.in_stock" @click="addToCart")
                    | Добавить в корзину
                button.favorite-wide-button(type="button" @click="customer.toggleFavorite(product)")
                    | {{ customer.isFavorite(product.id) ? '♥ В избранном' : '♡ Добавить в избранное' }}
                ul.buy-box-benefits
                    li Доставка от 24 часов
                    li Возврат без лишних вопросов
                    li Оплата при оформлении заказа

        section.product-info-grid
            article.product-info-main
                nav.product-info-tabs
                    a(href="#description") Описание
                    a(href="#attributes") Характеристики
                    a(href="#warehouses") Наличие
                section.product-info-section#description
                    p.eyebrow О товаре
                    h2 Описание
                    p {{ product.description ?? product.short_description }}
                section.product-info-section#attributes(v-if="product.attributes.length")
                    p.eyebrow Подробности
                    h2 Характеристики
                    dl.product-attribute-table
                        template(v-for="attribute in product.attributes" :key="attribute.name")
                            dt {{ filterLabel(attribute.name) }}
                            dd {{ attribute.value }}
                section.product-info-section(v-if="product.documents.length")
                    p.eyebrow Материалы
                    h2 Документы
                    ul.product-document-list
                        li(v-for="document in product.documents" :key="document.id")
                            a(:href="document.url ?? undefined" target="_blank" rel="noreferrer")
                                span.document-mark PDF
                                span
                                    strong {{ documentLabel(document.type) }}
                                    small {{ document.title }}
                                b(v-if="document.size") {{ formatFileSize(document.size) }}

            aside.product-stock-panel#warehouses
                p.eyebrow Самовывоз и доставка
                h2 Наличие на складах
                label.stock-city-select
                    span Город
                    select(v-model="selectedCityCode")
                        option(value="") Все города
                        option(v-for="city in warehouseCities" :key="city.code" :value="city.code") {{ city.name }}
                ul.product-warehouse-list
                    li(v-for="warehouse in visibleWarehouses" :key="warehouse.warehouse_id")
                        span
                            strong {{ warehouse.warehouse_name }}
                            small {{ warehouse.warehouse_code }}
                        b(:class="{ unavailable: !warehouse.in_stock }")
                            | {{ warehouse.in_stock ? `${warehouse.available_quantity} шт.` : 'Нет' }}

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Товары для дома, работы и отдыха с актуальными остатками на складах.
        NuxtLink(to="/cart/") Перейти в корзину
</template>
