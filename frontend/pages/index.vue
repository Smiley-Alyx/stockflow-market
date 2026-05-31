<script setup lang="ts">
import type { CatalogCategory } from '~/composables/useCatalogApi';

const catalogApi = useCatalogApi();
const customer = useCustomerState();
const productSlug = ref('wireless-scanner');
const productSlugInput = ref(productSlug.value);
const selectedCategorySlug = ref('');
const authMode = ref<'login' | 'register'>('login');
const authName = ref('');
const authEmail = ref('');
const authPassword = ref('');
const authError = ref('');
const authPending = ref(false);

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
            per_page: 12,
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
const selectedImageUrl = ref<string | null>(null);

watch(
    product,
    (nextProduct) => {
        selectedImageUrl.value = nextProduct?.image_url ?? nextProduct?.gallery[0]?.url ?? null;
    },
    { immediate: true },
);

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

const submitAuth = async () => {
    authPending.value = true;
    authError.value = '';

    try {
        if (authMode.value === 'register') {
            await customer.register(authName.value, authEmail.value, authPassword.value);
        } else {
            await customer.login(authEmail.value, authPassword.value);
        }

        authPassword.value = '';
    } catch {
        authError.value = 'Не удалось войти. Проверьте данные формы.';
    } finally {
        authPending.value = false;
    }
};

const logout = async () => {
    authError.value = '';

    try {
        await customer.logout();
    } catch {
        authError.value = 'Не удалось завершить сессию.';
    }
};

function countCategories(nodes: CatalogCategory[]): number {
    return nodes.reduce((total, category) => total + 1 + countCategories(category.children), 0);
}

function flattenCategories(nodes: CatalogCategory[]): CatalogCategory[] {
    return nodes.flatMap((category) => [category, ...flattenCategories(category.children)]);
}

function formatMoney(amountMinor: number, currency: string): string {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
    }).format(amountMinor / 100);
}

function formatFileSize(size: number | null): string {
    if (size === null) {
        return '';
    }

    return size < 1024 ? `${size} Б` : `${(size / 1024).toFixed(1)} КБ`;
}

function documentType(type: string): string {
    return {
        instruction: 'Инструкция',
        certificate: 'Сертификат',
        attachment: 'Файл',
    }[type] ?? 'Файл';
}

onMounted(() => customer.initialize());

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
                NuxtLink.counter(to="/cart/") Корзина {{ customer.cartCount }}
                span.counter Избранное {{ customer.favoriteCount }}
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
        article.panel.product-card-panel
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
                    button.product-select(type="button" @click="productSlugInput = item.slug; productSlug = item.slug")
                        span {{ item.category?.name ?? 'Без категории' }}
                        strong {{ item.name }}
                        code {{ item.sku }}
                    .product-actions
                        button(type="button" @click="customer.addCartItem(item)") В корзину
                        button(type="button" @click="customer.toggleFavorite(item)")
                            | {{ customer.isFavorite(item.id) ? 'Убрать из избранного' : 'В избранное' }}

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

            .product-card(v-if="product")
                .product-media
                    .product-main-image
                        img(v-if="selectedImageUrl" :src="selectedImageUrl" :alt="product.name")
                        span(v-else) Изображение не загружено
                    .product-gallery(v-if="product.image_url || product.gallery.length")
                        button(
                            v-if="product.image_url"
                            type="button"
                            :class="{ active: selectedImageUrl === product.image_url }"
                            @click="selectedImageUrl = product.image_url"
                        )
                            img(:src="product.image_url" :alt="product.name")
                        button(
                            v-for="image in product.gallery"
                            :key="image.id"
                            type="button"
                            :class="{ active: selectedImageUrl === image.url }"
                            @click="selectedImageUrl = image.url"
                        )
                            img(v-if="image.url" :src="image.url" :alt="image.title ?? product.name")

                .product-core
                    span.product-category {{ product.category?.name ?? 'Без категории' }}
                    h3 {{ product.name }}
                    p.product-brand(v-if="product.brand") Бренд: {{ product.brand.name }}
                    code Артикул: {{ product.sku }}
                    p.product-short {{ product.short_description ?? 'Краткое описание товара не заполнено.' }}
                    .product-price(v-if="product.price")
                        strong {{ formatMoney(product.price.amount_minor, product.price.currency) }}
                        del(v-if="product.price.has_discount")
                            | {{ formatMoney(product.price.original_amount_minor, product.price.currency) }}
                        span(v-if="product.price.has_discount") −{{ product.price.discount_percent }}%
                    p.empty-state(v-else) Цена пока не указана.
                    dl.attribute-list.compact(v-if="product.card_attributes.length")
                        template(v-for="attribute in product.card_attributes" :key="attribute.name")
                            dt {{ attribute.name }}
                            dd {{ attribute.value }}
                    .product-actions
                        button(type="button" @click="customer.addCartItem(product)") Добавить в корзину
                        button(type="button" @click="customer.toggleFavorite(product)")
                            | {{ customer.isFavorite(product.id) ? 'Убрать из избранного' : 'В избранное' }}

                section.product-details
                    h3 Описание
                    p {{ product.description ?? 'Описание товара не заполнено.' }}

                section.product-details(v-if="product.attributes.length")
                    h3 Характеристики
                    dl.attribute-list
                        template(v-for="attribute in product.attributes" :key="attribute.name")
                            dt {{ attribute.name }}
                            dd {{ attribute.value }}

                section.product-details
                    h3 Наличие на складах
                    ul.warehouse-list(v-if="product.warehouses.length")
                        li(v-for="warehouse in product.warehouses" :key="warehouse.warehouse_id")
                            div
                                strong {{ warehouse.warehouse_name }}
                                span {{ warehouse.city_name }}
                            b(:class="{ available: warehouse.in_stock }")
                                | {{ warehouse.in_stock ? `${warehouse.available_quantity} шт.` : 'Нет в наличии' }}
                    p.empty-state(v-else) Информация по складам пока отсутствует.

                section.product-details(v-if="product.documents.length")
                    h3 Документы
                    ul.document-list
                        li(v-for="document in product.documents" :key="document.id")
                            a(:href="document.url ?? undefined" target="_blank" rel="noreferrer")
                                strong {{ documentType(document.type) }}
                                span {{ document.title }}
                                small(v-if="document.size") {{ formatFileSize(document.size) }}

            p.empty-state(v-else-if="productError")
                | Backend API недоступен для запроса выбранного товара.

            p.empty-state(v-else)
                | Товар не найден или backend API пока недоступен для выбранного slug.

        article.panel
            .panel-heading
                h2 Корзина
                span {{ customer.user.value ? 'аккаунт' : 'локально' }}

            ul.customer-list(v-if="customer.state.value.cart.items.length")
                li(v-for="item in customer.state.value.cart.items" :key="item.product_id")
                    div
                        strong {{ item.product_name }}
                        code {{ item.sku }}
                    .quantity-actions
                        button(type="button" @click="customer.setCartItem(item, item.quantity - 1)") −
                        span {{ item.quantity }}
                        button(type="button" @click="customer.setCartItem(item, item.quantity + 1)") +
                        button.danger(type="button" @click="customer.removeCartItem(item.product_id)") Удалить

            p.empty-state(v-else) Корзина пока пуста.

        article.panel
            .panel-heading
                h2 Избранное
                span {{ customer.favoriteCount }}

            ul.customer-list(v-if="customer.state.value.favorites.length")
                li(v-for="item in customer.state.value.favorites" :key="item.product_id")
                    div
                        strong {{ item.product_name }}
                        code {{ item.sku }}
                    button.danger(type="button" @click="customer.toggleFavorite(item)") Убрать

            p.empty-state(v-else) В избранном пока ничего нет.

        article.panel.account-panel
            .panel-heading
                h2 Аккаунт
                span {{ customer.user.value ? 'серверное хранение' : 'гостевой режим' }}

            template(v-if="customer.user.value")
                p.account-user
                    strong {{ customer.user.value.name }}
                    span {{ customer.user.value.email }}
                p.account-note Корзина и избранное доступны после входа с другого устройства.
                button.primary-button(type="button" @click="logout") Выйти

            template(v-else)
                .mode-switch
                    button(type="button" :class="{ active: authMode === 'login' }" @click="authMode = 'login'")
                        | Вход
                    button(type="button" :class="{ active: authMode === 'register' }" @click="authMode = 'register'")
                        | Регистрация
                form.auth-form(@submit.prevent="submitAuth")
                    input(
                        v-if="authMode === 'register'"
                        v-model="authName"
                        type="text"
                        name="name"
                        autocomplete="name"
                        placeholder="Имя"
                        required
                    )
                    input(v-model="authEmail" type="email" name="email" autocomplete="email" placeholder="Email" required)
                    input(
                        v-model="authPassword"
                        type="password"
                        name="password"
                        :autocomplete="authMode === 'register' ? 'new-password' : 'current-password'"
                        placeholder="Пароль"
                        minlength="8"
                        required
                    )
                    button.primary-button(type="submit" :disabled="authPending")
                        | {{ authMode === 'register' ? 'Зарегистрироваться' : 'Войти' }}
                p.account-note Гостевые позиции будут перенесены в аккаунт после входа.
                p.form-error(v-if="authError") {{ authError }}
</template>
