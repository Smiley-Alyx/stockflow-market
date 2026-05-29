<script setup lang="ts">
import type { CatalogCategory } from '~/composables/useCatalogApi';

const catalogApi = useCatalogApi();
const productSlug = ref('wireless-scanner');
const productSlugInput = ref(productSlug.value);
const selectedCategorySlug = ref('');

const { data: categories, error: categoriesError, pending: categoriesPending } = await useAsyncData(
    'catalog-categories',
    () => catalogApi.fetchCategoryTree(),
);
const {
    data: productList,
    error: productListError,
    pending: productListPending,
} = await useAsyncData(
    'catalog-products',
    () =>
        catalogApi.fetchProducts({
            category: selectedCategorySlug.value || undefined,
            per_page: 6,
        }),
    {
        watch: [selectedCategorySlug],
    },
);
const {
    data: product,
    error: productError,
    pending: productPending,
    refresh: refreshProduct,
} = await useAsyncData('catalog-product', () => catalogApi.fetchProduct(productSlug.value), {
    watch: [productSlug],
});

const visibleCategories = computed(() => categories.value?.slice(0, 4) ?? []);
const categoryOptions = computed(() => flattenCategories(categories.value ?? []));
const categoryCount = computed(() => countCategories(categories.value ?? []));
const selectedCategory = computed(
    () => categoryOptions.value.find((category) => category.slug === selectedCategorySlug.value) ?? null,
);
const products = computed(() => productList.value?.products ?? []);
const productTotal = computed(() => productList.value?.meta.total ?? 0);
const publishedCount = computed(() => (productListPending.value ? '...' : productTotal.value.toString()));
const catalogStatus = computed(() => {
    if (categoriesError.value || productListError.value) {
        return 'API offline';
    }

    return categoriesPending.value || productListPending.value ? 'API loading' : 'API ready';
});

const productStatus = computed(() => {
    if (productPending.value) {
        return 'Загрузка';
    }

    if (productError.value) {
        return 'Ошибка API';
    }

    return product.value?.status ?? 'Не найден';
});

const loadProduct = async () => {
    const nextSlug = productSlugInput.value.trim();

    if (!nextSlug) {
        return;
    }

    if (nextSlug === productSlug.value) {
        await refreshProduct();
        return;
    }

    productSlug.value = nextSlug;
};

function countCategories(nodes: CatalogCategory[]): number {
    return nodes.reduce((total, category) => total + 1 + countCategories(category.children), 0);
}

function flattenCategories(nodes: CatalogCategory[]): CatalogCategory[] {
    return nodes.flatMap((category) => [category, ...flattenCategories(category.children)]);
}

useHead({
    htmlAttrs: {
        lang: 'ru',
    },
});
</script>

<template lang="pug">
main.shell
    section.overview
        header.topbar
            .brand-block
                .brand
                    span.brand-mark SF
                    span StockFlow Market
                p Gateway shell

            .topbar-actions
                a(href="/api/catalog/products?per_page=10") Catalog API
                span.status(:class="{ 'status-muted': categoriesError || productListError }")
                    | {{ catalogStatus }}

        .hero
            .hero-copy
                p.eyebrow Marketplace operations
                h1 Каталог, остатки и поиск в одном рабочем контуре
                p.lede.
                    Минимальный SSR-интерфейс для проверки публичного слоя StockFlow Market и будущих
                    операционных сценариев.

            .metrics(aria-label="Состояние витрины")
                article
                    span Каталог
                    strong {{ categoryCount }}
                article
                    span Опубликовано
                    strong {{ publishedCount }}
                article
                    span Товар
                    strong {{ productStatus }}
                article
                    span Rendering
                    strong SSR

    section.content-grid(aria-label="Операционные данные")
        article.panel
            .panel-heading
                h2 Категории
                span read API

            .filter-row(aria-label="Фильтр каталога")
                button(
                    type="button"
                    :class="{ active: selectedCategorySlug === '' }"
                    @click="selectedCategorySlug = ''"
                ) Все
                button(
                    v-for="category in categoryOptions.slice(0, 5)"
                    :key="category.id"
                    type="button"
                    :class="{ active: selectedCategorySlug === category.slug }"
                    @click="selectedCategorySlug = category.slug"
                ) {{ category.name }}

            ul.category-list(v-if="visibleCategories.length")
                li(v-for="category in visibleCategories" :key="category.id")
                    div
                        span {{ category.name }}
                        small {{ category.children.length }} вложенных
                    code {{ category.slug }}

            p.empty-state(v-else)
                | Категории появятся после миграций, сидов и доступности backend API.

        article.panel.product-list-panel
            .panel-heading
                h2 Витрина
                span {{ selectedCategory?.name ?? 'все категории' }}

            ul.product-list(v-if="products.length")
                li(v-for="item in products" :key="item.id")
                    button(type="button" @click="productSlugInput = item.slug; productSlug = item.slug")
                        span {{ item.category?.name ?? 'Без категории' }}
                        strong {{ item.name }}
                        code {{ item.sku }}

            p.empty-state(v-else-if="productListError")
                | Backend API недоступен для списка опубликованных товаров.

            p.empty-state(v-else)
                | Опубликованные товары появятся после наполнения каталога.

        article.panel
            .panel-heading
                h2 Товар
                span read API

            form.lookup-form(@submit.prevent="loadProduct")
                label(for="product-slug") Slug товара
                div
                    input(id="product-slug" v-model="productSlugInput" name="product-slug" autocomplete="off")
                    button(type="submit" :disabled="productPending") Найти

            .product-summary(v-if="product")
                div
                    span {{ product.category?.name ?? 'Без категории' }}
                    strong {{ product.name }}
                    code {{ product.sku }}
                p {{ product.description ?? 'Описание товара не заполнено.' }}
                dl.attribute-list(v-if="product.attributes.length")
                    template(v-for="attribute in product.attributes" :key="attribute.name")
                        dt {{ attribute.name }}
                        dd {{ attribute.value }}

            p.empty-state(v-else-if="productError")
                | Backend API недоступен для запроса выбранного товара.

            p.empty-state(v-else)
                | Товар не найден или backend API пока недоступен для выбранного slug.
</template>
