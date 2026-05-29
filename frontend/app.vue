<script setup lang="ts">
import type { CatalogCategory } from '~/composables/useCatalogApi';

const catalogApi = useCatalogApi();
const productSlug = ref('wireless-scanner');
const productSlugInput = ref(productSlug.value);

const { data: categories, error: categoriesError, pending: categoriesPending } = await useAsyncData(
    'catalog-categories',
    () => catalogApi.fetchCategoryTree(),
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
const categoryCount = computed(() => countCategories(categories.value ?? []));
const catalogStatus = computed(() => {
    if (categoriesError.value) {
        return 'API offline';
    }

    return categoriesPending.value ? 'API loading' : 'API ready';
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

useHead({
    htmlAttrs: {
        lang: 'ru',
    },
});
</script>

<template>
    <main class="shell">
        <section class="overview">
            <header class="topbar">
                <div class="brand">
                    <span class="brand-mark">SF</span>
                    <span>StockFlow Market</span>
                </div>

                <span class="status" :class="{ 'status-muted': categoriesError }">{{ catalogStatus }}</span>
            </header>

            <div class="hero">
                <div class="hero-copy">
                    <p class="eyebrow">Marketplace operations</p>
                    <h1>Каталог, остатки и поиск в одном рабочем контуре</h1>
                    <p class="lede">
                        Минимальный SSR-интерфейс для проверки публичного слоя StockFlow Market и будущих
                        операционных сценариев.
                    </p>
                </div>

                <div class="metrics" aria-label="Состояние витрины">
                    <article>
                        <span>Каталог</span>
                        <strong>{{ categoryCount }}</strong>
                    </article>
                    <article>
                        <span>Товар</span>
                        <strong>{{ productStatus }}</strong>
                    </article>
                    <article>
                        <span>Rendering</span>
                        <strong>SSR</strong>
                    </article>
                </div>
            </div>
        </section>

        <section class="content-grid" aria-label="Операционные данные">
            <article class="panel">
                <div class="panel-heading">
                    <h2>Категории</h2>
                    <span>read API</span>
                </div>

                <ul v-if="visibleCategories.length" class="category-list">
                    <li v-for="category in visibleCategories" :key="category.id">
                        <span>{{ category.name }}</span>
                        <code>{{ category.slug }}</code>
                    </li>
                </ul>

                <p v-else class="empty-state">
                    Категории появятся после миграций, сидов и доступности backend API.
                </p>
            </article>

            <article class="panel">
                <div class="panel-heading">
                    <h2>Товар</h2>
                    <span>read API</span>
                </div>

                <form class="lookup-form" @submit.prevent="loadProduct">
                    <label for="product-slug">Slug товара</label>
                    <div>
                        <input id="product-slug" v-model="productSlugInput" name="product-slug" autocomplete="off" />
                        <button type="submit" :disabled="productPending">Найти</button>
                    </div>
                </form>

                <div v-if="product" class="product-summary">
                    <div>
                        <span>{{ product.category?.name ?? 'Без категории' }}</span>
                        <strong>{{ product.name }}</strong>
                        <code>{{ product.sku }}</code>
                    </div>
                    <p>{{ product.description ?? 'Описание товара не заполнено.' }}</p>
                    <dl v-if="product.attributes.length" class="attribute-list">
                        <template v-for="attribute in product.attributes" :key="attribute.name">
                            <dt>{{ attribute.name }}</dt>
                            <dd>{{ attribute.value }}</dd>
                        </template>
                    </dl>
                </div>

                <p v-else-if="productError" class="empty-state">
                    Backend API недоступен для запроса выбранного товара.
                </p>

                <p v-else class="empty-state">
                    Товар не найден или backend API пока недоступен для выбранного slug.
                </p>
            </article>
        </section>
    </main>
</template>
