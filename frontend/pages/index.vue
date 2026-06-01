<script setup lang="ts">
import type { CatalogCategory, CatalogProduct, HomepageBlock } from '~/composables/useCatalogApi';

const catalogApi = useCatalogApi();
const customer = useCustomerState();
const selectedCategorySlug = ref('');
const searchInput = ref('');
const searchQuery = ref('');
const selectedProductSlug = ref<string | null>(null);
const authMode = ref<'login' | 'register'>('login');
const authName = ref('');
const authEmail = ref('');
const authPassword = ref('');
const authError = ref('');
const authPending = ref(false);

const { data: categories, error: categoriesError } = await useAsyncData(
    'catalog-categories',
    () => catalogApi.fetchCategoryTree(),
);
const { data: homepage, error: homepageError } = await useAsyncData(
    'homepage-blocks',
    () => catalogApi.fetchHomepage(),
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
            q: searchQuery.value || undefined,
            per_page: 12,
        }),
    {
        watch: [selectedCategorySlug, searchQuery],
    },
);
const { data: selectedProduct, pending: selectedProductPending } = await useAsyncData(
    'selected-product',
    async () => (selectedProductSlug.value ? catalogApi.fetchProduct(selectedProductSlug.value) : null),
    {
        watch: [selectedProductSlug],
    },
);

const categoryOptions = computed(() => flattenCategories(categories.value ?? []).filter((category) => category.image_url));
const topCategories = computed(() => categoryOptions.value.slice(0, 7));
const featuredCategories = computed(() => categoryOptions.value.slice(0, 6));
const products = computed(() => productList.value?.products ?? []);
const productTotal = computed(() => productList.value?.meta.total ?? 0);
const banner = computed(() => blockByType('banner'));
const cities = computed(() => blockByType('cities')?.content.cities ?? []);
const description = computed(() => blockByType('description'));
const shelves = computed(() =>
    [
        { type: 'recommended_products', title: 'Для вас', subtitle: 'Подборка полезных товаров на каждый день' },
        { type: 'bestseller_products', title: 'Хиты продаж', subtitle: 'То, что уже выбирают чаще всего' },
        { type: 'new_products', title: 'Новинки', subtitle: 'Свежие поступления в каталоге' },
    ].map((shelf) => ({
        ...shelf,
        products: blockByType(shelf.type)?.content.products ?? [],
    })),
);
const catalogStatus = computed(() => {
    if (categoriesError.value || homepageError.value || productListError.value) {
        return 'Часть данных недоступна';
    }

    return 'Каталог обновлён';
});

const submitSearch = () => {
    searchQuery.value = searchInput.value.trim();
};

const showProduct = (product: CatalogProduct) => {
    selectedProductSlug.value = product.slug;
    nextTick(() => document.querySelector('#product-preview')?.scrollIntoView({ behavior: 'smooth' }));
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

function blockByType(type: string): HomepageBlock | undefined {
    return homepage.value?.find((block) => block.type === type);
}

function flattenCategories(nodes: CatalogCategory[]): CatalogCategory[] {
    return nodes.flatMap((category) => [category, ...flattenCategories(category.children)]);
}

function formatMoney(product: CatalogProduct): string {
    if (!product.price) {
        return 'Уточнить цену';
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: product.price.currency,
        maximumFractionDigits: 0,
    }).format(product.price.amount_minor / 100);
}

function ratingLabel(product: CatalogProduct): string {
    return product.rating ? product.rating.toFixed(1) : 'Новинка';
}

onMounted(() => customer.initialize());

useHead({
    title: 'StockFlow Market — товары для дома, работы и отдыха',
    htmlAttrs: {
        lang: 'ru',
    },
});
</script>

<template lang="pug">
main.market-shell
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
                a.action-link(href="#account")
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
            NuxtLink(
                v-for="category in topCategories"
                :key="category.id"
                :to="category.url ?? '/catalog/'"
                :class="{ active: selectedCategorySlug === category.slug }"
            ) {{ category.name }}

    section.market-hero
        .hero-marketplace-copy
            p.eyebrow {{ banner?.title ?? 'StockFlow Market' }}
            h1 {{ banner?.content.headline ?? 'Полезные товары для жизни и работы' }}
            p {{ banner?.content.text ?? 'Выбирайте товары с актуальными остатками на складах вашего города.' }}
            .hero-buttons
                NuxtLink.hero-primary(to="/catalog/")
                    | {{ banner?.content.button_label ?? 'Смотреть каталог' }}
                a.hero-secondary(href="#categories") Выбрать категорию
            ul.hero-points
                li Быстрая доставка
                li Проверенные бренды
                li 7 складов

        .hero-marketplace-media
            img(v-if="banner?.content.image_url" :src="banner.content.image_url" alt="")
            .hero-offer
                span Выгодно
                strong до −20%
                small на товары недели

    section.service-strip(aria-label="Преимущества магазина")
        article
            span.service-icon 24
            div
                strong Доставка от 24 часов
                small Со склада в вашем городе
        article
            span.service-icon ✓
            div
                strong Только нужное
                small Отобранные товары и бренды
        article
            span.service-icon 7
            div
                strong 7 точек выдачи
                small В пяти городах Польши
        article
            span.service-icon ↺
            div
                strong Простой возврат
                small Без лишних вопросов

    section.market-section#categories
        .section-heading
            div
                p.eyebrow Покупайте по разделам
                h2 Популярные категории
            NuxtLink.text-button(to="/catalog/") Смотреть весь каталог

        .category-showcase
            NuxtLink.category-tile(
                v-for="category in featuredCategories"
                :key="category.id"
                :to="category.url ?? '/catalog/'"
            )
                img(v-if="category.image_url" :src="category.image_url" :alt="category.name")
                span
                    strong {{ category.name }}
                    small {{ category.description }}

    section.market-section.product-shelf(
        v-for="shelf in shelves"
        :key="shelf.type"
        :aria-label="shelf.title"
    )
        .section-heading
            div
                p.eyebrow {{ shelf.subtitle }}
                h2 {{ shelf.title }}
            NuxtLink.text-button(to="/catalog/") Смотреть ещё

        .product-grid
            article.market-product-card(v-for="item in shelf.products" :key="item.id")
                button.favorite-button(
                    type="button"
                    :aria-label="customer.isFavorite(item.id) ? 'Убрать из избранного' : 'Добавить в избранное'"
                    :class="{ active: customer.isFavorite(item.id) }"
                    @click="customer.toggleFavorite(item)"
                ) {{ customer.isFavorite(item.id) ? '♥' : '♡' }}
                button.product-card-media(type="button" @click="showProduct(item)")
                    img(v-if="item.image_url" :src="item.image_url" :alt="item.name")
                    span(v-else) SF
                .product-card-copy
                    small {{ item.category?.name ?? 'Каталог' }}
                    button.product-card-title(type="button" @click="showProduct(item)") {{ item.name }}
                    .rating-line
                        span ★ {{ ratingLabel(item) }}
                        small {{ item.rating_count }} оценок
                    .product-card-bottom
                        div
                            strong {{ formatMoney(item) }}
                            small(v-if="item.availability.in_stock") В наличии
                        button.cart-add(type="button" aria-label="Добавить в корзину" @click="customer.addCartItem(item)") +

    section.market-section.catalog-panel#catalog
        .catalog-panel-heading
            div
                p.eyebrow {{ catalogStatus }}
                h2 Каталог товаров
                p(v-if="searchQuery") Результаты поиска по запросу «{{ searchQuery }}»
                p(v-else) {{ productTotal }} товаров с актуальными остатками
            button.text-button(v-if="selectedCategorySlug || searchQuery" type="button" @click="selectedCategorySlug = ''; searchInput = ''; searchQuery = ''")
                | Сбросить фильтры

        .filter-row
            button(type="button" :class="{ active: !selectedCategorySlug }" @click="selectedCategorySlug = ''") Все
            button(
                v-for="category in topCategories"
                :key="category.id"
                type="button"
                :class="{ active: selectedCategorySlug === category.slug }"
                @click="selectedCategorySlug = category.slug"
            ) {{ category.name }}

        .product-grid(v-if="products.length")
            article.market-product-card(v-for="item in products" :key="item.id")
                button.favorite-button(
                    type="button"
                    :class="{ active: customer.isFavorite(item.id) }"
                    @click="customer.toggleFavorite(item)"
                ) {{ customer.isFavorite(item.id) ? '♥' : '♡' }}
                button.product-card-media(type="button" @click="showProduct(item)")
                    img(v-if="item.image_url" :src="item.image_url" :alt="item.name")
                    span(v-else) SF
                .product-card-copy
                    small {{ item.category?.name ?? 'Каталог' }}
                    button.product-card-title(type="button" @click="showProduct(item)") {{ item.name }}
                    .rating-line
                        span ★ {{ ratingLabel(item) }}
                        small {{ item.rating_count }} оценок
                    .product-card-bottom
                        div
                            strong {{ formatMoney(item) }}
                            small(v-if="item.availability.in_stock") В наличии
                        button.cart-add(type="button" aria-label="Добавить в корзину" @click="customer.addCartItem(item)") +

        p.empty-state(v-else-if="productListPending") Загружаем товары…
        p.empty-state(v-else) По выбранным условиям товаров не найдено.

    section.product-preview#product-preview(v-if="selectedProduct || selectedProductPending")
        p.empty-state(v-if="selectedProductPending") Загружаем карточку товара…
        template(v-else-if="selectedProduct")
            .preview-media
                img(v-if="selectedProduct.image_url" :src="selectedProduct.image_url" :alt="selectedProduct.name")
            .preview-copy
                p.eyebrow {{ selectedProduct.category?.name ?? 'Каталог' }}
                h2 {{ selectedProduct.name }}
                p {{ selectedProduct.short_description }}
                .preview-price {{ formatMoney(selectedProduct) }}
                ul.preview-attributes
                    li(v-for="attribute in selectedProduct.card_attributes" :key="attribute.name")
                        span {{ attribute.name }}
                        b {{ attribute.value }}
                .hero-buttons
                    button.hero-primary(type="button" @click="customer.addCartItem(selectedProduct)") Добавить в корзину
                    button.hero-secondary(type="button" @click="customer.toggleFavorite(selectedProduct)")
                        | {{ customer.isFavorite(selectedProduct.id) ? 'Убрать из избранного' : 'В избранное' }}

    section.city-banner
        .city-banner-copy
            p.eyebrow Получайте быстрее
            h2 Склады рядом с вами
            p Выбирайте товары с актуальными остатками и забирайте заказ в удобном городе.
        ul.city-list
            li(v-for="city in cities" :key="city.code")
                strong {{ city.name }}
                span {{ city.warehouses.length }} {{ city.warehouses.length === 1 ? 'склад' : 'склада' }}

    section.brand-story
        div
            p.eyebrow О магазине
            h2 {{ description?.title ?? 'StockFlow Market' }}
        p {{ description?.content.text }}
        .story-metrics
            article
                strong 2 000+
                span товаров
            article
                strong 5
                span городов
            article
                strong 7
                span складов

    section.account-zone#account
        .account-intro
            p.eyebrow Личный кабинет
            h2 {{ customer.user.value ? `Здравствуйте, ${customer.user.value.name}` : 'Сохраняйте покупки и избранное' }}
            p(v-if="customer.user.value") Корзина и избранное синхронизированы с вашим аккаунтом.
            p(v-else) Войдите, чтобы корзина была доступна на любом устройстве.
        button.secondary-button(v-if="customer.user.value" type="button" @click="logout") Выйти
        form.account-inline-form(v-else @submit.prevent="submitAuth")
            input(
                v-if="authMode === 'register'"
                v-model="authName"
                type="text"
                name="name"
                placeholder="Имя"
                autocomplete="name"
                required
            )
            input(v-model="authEmail" type="email" name="email" placeholder="Email" autocomplete="email" required)
            input(
                v-model="authPassword"
                type="password"
                name="password"
                placeholder="Пароль"
                :autocomplete="authMode === 'register' ? 'new-password' : 'current-password'"
                minlength="8"
                required
            )
            button.hero-primary(type="submit" :disabled="authPending")
                | {{ authMode === 'register' ? 'Создать аккаунт' : 'Войти' }}
            button.text-button(type="button" @click="authMode = authMode === 'login' ? 'register' : 'login'")
                | {{ authMode === 'login' ? 'Регистрация' : 'Уже есть аккаунт' }}
        p.form-error(v-if="authError") {{ authError }}

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Товары для дома, работы и отдыха с актуальными остатками на складах.
        NuxtLink(to="/cart/") Перейти в корзину
</template>
