<script setup lang="ts">
import type {
    CatalogCategory,
    CatalogFilterValue,
    CatalogProduct,
    CatalogQueryParams,
    HomepageCity,
} from '~/composables/useCatalogApi';

defineOptions({ name: 'CatalogMarketplace' });

const props = defineProps<{
    searchPage?: boolean;
}>();
const route = useRoute();
const catalogApi = useCatalogApi();
const customer = useCustomerState();
const searchInput = ref(queryValue('q'));
const priceFromInput = ref('');
const priceToInput = ref('');
const mobileFiltersOpen = ref(false);

const catalogPath = computed(() => {
    const path = route.params.path;

    return Array.isArray(path) ? path.join('/') : path ?? '';
});
const { data: categories } = await useAsyncData('catalog-page-categories', () => catalogApi.fetchCategoryTree());
const { data: homepage } = await useAsyncData('catalog-page-homepage', () => catalogApi.fetchHomepage());
const {
    data: catalog,
    error: catalogError,
    pending: catalogPending,
} = await useAsyncData(
    'catalog-page-products',
    () => catalogApi.fetchCatalogPath(catalogPath.value, catalogQuery()),
    {
        watch: [() => route.fullPath],
    },
);

const products = computed(() => catalog.value?.products ?? []);
const meta = computed(() => catalog.value?.meta);
const rootCategories = computed(() => (categories.value ?? []).filter((category) => category.image_url));
const selectedFilters = computed(() => parseSelectedFilters(catalogPath.value));
const filters = computed(() => Object.entries(meta.value?.filters ?? {}));
const cities = computed<HomepageCity[]>(() => homepage.value?.find((block) => block.type === 'cities')?.content.cities ?? []);
const currentPage = computed(() => meta.value?.current_page ?? 1);
const lastPage = computed(() => Math.max(1, Math.ceil((meta.value?.total ?? 0) / (meta.value?.per_page ?? 12))));
const pageNumbers = computed(() => {
    const first = Math.max(1, currentPage.value - 2);
    const last = Math.min(lastPage.value, currentPage.value + 2);

    return Array.from({ length: last - first + 1 }, (_, index) => first + index);
});
const currentCategoryName = computed(() => props.searchPage ? 'Поиск' : meta.value?.breadcrumbs.at(-1)?.name ?? 'Все товары');
const activeFilterCount = computed(
    () =>
        Object.values(selectedFilters.value.filters).reduce((total, values) => total + values.length, 0) +
        Number(selectedFilters.value.priceFrom !== null || selectedFilters.value.priceTo !== null) +
        Number(route.query.in_stock === '1') +
        Number(Boolean(route.query.city_code)),
);

watch(
    () => meta.value?.price_range,
    (priceRange) => {
        priceFromInput.value = formatPriceInput(priceRange?.selected_min);
        priceToInput.value = formatPriceInput(priceRange?.selected_max);
    },
    { immediate: true },
);

watch(
    () => route.query.q,
    () => {
        searchInput.value = queryValue('q');
    },
);

const submitSearch = () => {
    const query = searchInput.value.trim();

    if (props.searchPage) {
        updateQuery({ q: query || undefined, page: undefined });
        return;
    }

    navigateTo({
        path: '/search/',
        query: query ? { q: query } : {},
    });
};

const updateSort = (event: Event) => {
    updateQuery({ sort: (event.target as HTMLSelectElement).value, page: undefined });
};

const updateCity = (event: Event) => {
    updateQuery({ city_code: (event.target as HTMLSelectElement).value || undefined, page: undefined });
};

const toggleInStock = (event: Event) => {
    updateQuery({ in_stock: (event.target as HTMLInputElement).checked ? '1' : undefined, page: undefined });
};

const toggleFilter = (name: string, value: CatalogFilterValue) => {
    const nextFilters = structuredClone(selectedFilters.value.filters);
    const values = nextFilters[name] ?? [];

    nextFilters[name] = values.includes(value.value)
        ? values.filter((entry) => entry !== value.value)
        : [...values, value.value];

    if (!nextFilters[name].length) {
        delete nextFilters[name];
    }

    navigateFilterPath(nextFilters, selectedFilters.value.priceFrom, selectedFilters.value.priceTo);
};

const applyPrice = () => {
    navigateFilterPath(
        selectedFilters.value.filters,
        parsePriceInput(priceFromInput.value),
        parsePriceInput(priceToInput.value),
    );
};

const removeFilter = (name: string) => {
    const nextFilters = structuredClone(selectedFilters.value.filters);
    delete nextFilters[name];

    navigateFilterPath(nextFilters, selectedFilters.value.priceFrom, selectedFilters.value.priceTo);
};

const clearPrice = () => {
    navigateFilterPath(selectedFilters.value.filters, null, null);
};

const clearFilters = () => {
    navigateTo({
        path: meta.value?.canonical_url ?? '/catalog/',
        query: cleanQuery({
            q: route.query.q,
            sort: route.query.sort,
        }),
    });
};

const setPage = (page: number) => {
    updateQuery({ page: page === 1 ? undefined : String(page) });
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

function catalogQuery(): CatalogQueryParams {
    return {
        q: queryValue('q') || undefined,
        sort: queryValue('sort') || undefined,
        page: numberQuery('page'),
        per_page: 12,
        in_stock: route.query.in_stock === '1' ? 1 : undefined,
        city_code: queryValue('city_code') || undefined,
    };
}

function queryValue(name: string): string {
    const value = route.query[name];

    return Array.isArray(value) ? value[0] ?? '' : value ?? '';
}

function numberQuery(name: string): number | undefined {
    const value = Number(queryValue(name));

    return Number.isInteger(value) && value > 0 ? value : undefined;
}

function updateQuery(patch: Record<string, string | undefined>) {
    navigateTo({
        path: route.path,
        query: cleanQuery({
            ...route.query,
            ...patch,
        }),
    });
}

function cleanQuery(query: Record<string, unknown>): Record<string, string | string[]> {
    const entries: Array<[string, string | string[]]> = [];

    for (const [name, value] of Object.entries(query)) {
        if (typeof value === 'string' && value) {
            entries.push([name, value]);
        }

        if (Array.isArray(value)) {
            entries.push([name, value.filter((entry): entry is string => typeof entry === 'string' && Boolean(entry))]);
        }
    }

    return Object.fromEntries(entries);
}

function navigateFilterPath(filters: Record<string, string[]>, priceFrom: number | null, priceTo: number | null) {
    navigateTo({
        path: buildFilterPath(meta.value?.category_path ?? '', filters, priceFrom, priceTo),
        query: cleanQuery({
            q: route.query.q,
            sort: route.query.sort,
            in_stock: route.query.in_stock,
            city_code: route.query.city_code,
        }),
    });
}

function buildFilterPath(
    categoryPath: string,
    filters: Record<string, string[]>,
    priceFrom: number | null,
    priceTo: number | null,
): string {
    const base = categoryPath ? `/catalog/${categoryPath}/` : '/catalog/';
    const segments = Object.entries(filters)
        .sort(([left], [right]) => left.localeCompare(right))
        .flatMap(([name, values]) =>
            values.length
                ? [`${encodeURIComponent(name)}-is-${values.map((value) => encodeURIComponent(value)).sort().join('-or-')}`]
                : [],
        );

    if (priceFrom !== null || priceTo !== null) {
        segments.push(
            priceFrom !== null && priceTo !== null
                ? `price-from-${priceFrom}-to-${priceTo}`
                : priceFrom !== null
                  ? `price-from-${priceFrom}`
                  : `price-to-${priceTo}`,
        );
    }

    return segments.length ? `${base}filter/${segments.join('/')}/apply/` : base;
}

function parseSelectedFilters(path: string): {
    filters: Record<string, string[]>;
    priceFrom: number | null;
    priceTo: number | null;
} {
    const segments = path.split('/').filter(Boolean);
    const filterIndex = segments.indexOf('filter');
    const filterSegments = filterIndex === -1 ? [] : segments.slice(filterIndex + 1).filter((segment) => segment !== 'apply');
    const filters: Record<string, string[]> = {};
    let priceFrom: number | null = null;
    let priceTo: number | null = null;

    for (const segment of filterSegments) {
        const range = segment.match(/^price-from-(\d+)-to-(\d+)$/);
        const lowerBound = segment.match(/^price-from-(\d+)$/);
        const upperBound = segment.match(/^price-to-(\d+)$/);
        const filter = segment.match(/^([a-zA-Z0-9_-]+)-is-(.+)$/);

        if (range) {
            priceFrom = Number(range[1]);
            priceTo = Number(range[2]);
        } else if (lowerBound) {
            priceFrom = Number(lowerBound[1]);
        } else if (upperBound) {
            priceTo = Number(upperBound[1]);
        } else if (filter) {
            filters[decodeURIComponent(filter[1]!)] = filter[2]!.split('-or-').map((value) => decodeURIComponent(value));
        }
    }

    return { filters, priceFrom, priceTo };
}

function formatMoney(product: CatalogProduct): string {
    if (!product.price) {
        return 'Цена по запросу';
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: product.price.currency,
        maximumFractionDigits: 0,
    }).format(product.price.amount_minor / 100);
}

function formatPriceInput(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : String(Math.round(value / 100));
}

function parsePriceInput(value: string): number | null {
    const price = Number(value.replace(',', '.'));

    return Number.isFinite(price) && price >= 0 ? Math.round(price * 100) : null;
}

function ratingLabel(product: CatalogProduct): string {
    return product.rating ? product.rating.toFixed(1) : 'Новинка';
}

function filterLabel(name: string): string {
    return {
        battery_life: 'Время работы',
        color: 'Цвет',
        connection: 'Подключение',
        kit: 'Комплектация',
        material: 'Материал',
        power: 'Мощность',
        voltage: 'Напряжение',
        weight: 'Вес',
    }[name] ?? name;
}

onMounted(() => customer.initialize());

useHead(() => ({
    title: `${currentCategoryName.value} | StockFlow Market`,
    htmlAttrs: {
        lang: 'ru',
    },
}));
</script>

<template lang="pug">
main.catalog-shell
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
        template(v-if="searchPage")
            span /
            span Поиск
        template(v-for="crumb in meta?.breadcrumbs ?? []" :key="crumb.id")
            span /
            NuxtLink(:to="crumb.url") {{ crumb.name }}

    section.catalog-title-row
        div
            p.eyebrow StockFlow Market
            h1 {{ currentCategoryName }}
            p(v-if="meta?.query") Результаты по запросу «{{ meta.query }}»
            p(v-else) Выбирайте товары с актуальными ценами и остатками на складах.
        .catalog-title-stat
            strong {{ meta?.total ?? 0 }}
            span товаров найдено

    section.catalog-toolbar
        button.mobile-filter-button(type="button" @click="mobileFiltersOpen = !mobileFiltersOpen")
            | Фильтры
            b(v-if="activeFilterCount") {{ activeFilterCount }}
        label.toolbar-control
            span Город
            select(:value="route.query.city_code ?? ''" @change="updateCity")
                option(value="") Все города
                option(v-for="city in cities" :key="city.code" :value="city.code") {{ city.name }}
        label.toolbar-control.sort-control
            span Сортировка
            select(:value="meta?.sort ?? 'newest'" @change="updateSort")
                option(value="newest") Сначала новинки
                option(value="price_asc") Сначала дешевле
                option(value="price_desc") Сначала дороже
                option(value="rating_desc") По рейтингу
        span.toolbar-total {{ meta?.total ?? 0 }} товаров

    section.catalog-layout
        aside.catalog-filters(:class="{ open: mobileFiltersOpen }")
            .filter-heading
                h2 Фильтры
                button.text-button(v-if="activeFilterCount" type="button" @click="clearFilters") Сбросить

            label.stock-checkbox
                input(type="checkbox" :checked="route.query.in_stock === '1'" @change="toggleInStock")
                span Только товары в наличии

            section.filter-group
                h3 Цена
                .price-fields
                    label
                        span от
                        input(v-model="priceFromInput" type="number" min="0" inputmode="numeric")
                    label
                        span до
                        input(v-model="priceToInput" type="number" min="0" inputmode="numeric")
                button.apply-filter-button(type="button" @click="applyPrice") Применить

            section.filter-group(v-for="[name, values] in filters" :key="name")
                h3 {{ filterLabel(name) }}
                label.filter-option(v-for="value in values" :key="value.value")
                    input(
                        type="checkbox"
                        :checked="selectedFilters.filters[name]?.includes(value.value)"
                        @change="toggleFilter(name, value)"
                    )
                    span {{ value.value }}
                    small {{ value.count }}

            button.close-filter-button(type="button" @click="mobileFiltersOpen = false") Показать товары

        .catalog-results
            .active-filter-row(v-if="activeFilterCount")
                button(
                    v-for="[name, values] in Object.entries(selectedFilters.filters)"
                    :key="name"
                    type="button"
                    @click="removeFilter(name)"
                )
                    | {{ filterLabel(name) }}: {{ values.join(', ') }}
                button(
                    v-if="selectedFilters.priceFrom !== null || selectedFilters.priceTo !== null"
                    type="button"
                    @click="clearPrice"
                )
                    | Цена: {{ formatPriceInput(selectedFilters.priceFrom) || '0' }}–{{ formatPriceInput(selectedFilters.priceTo) || '∞' }}
                button(v-if="route.query.in_stock === '1'" type="button" @click="updateQuery({ in_stock: undefined, page: undefined })")
                    | В наличии
                button(v-if="route.query.city_code" type="button" @click="updateQuery({ city_code: undefined, page: undefined })")
                    | {{ cities.find((city) => city.code === route.query.city_code)?.name }}

            .catalog-product-grid(v-if="products.length")
                article.catalog-product-card(v-for="item in products" :key="item.id")
                    button.favorite-button(
                        type="button"
                        :class="{ active: customer.isFavorite(item.id) }"
                        :aria-label="customer.isFavorite(item.id) ? 'Убрать из избранного' : 'Добавить в избранное'"
                        @click="customer.toggleFavorite(item)"
                    ) {{ customer.isFavorite(item.id) ? '♥' : '♡' }}
                    NuxtLink.catalog-card-media(:to="item.url ?? `/api/catalog/products/${item.slug}`")
                        img(v-if="item.image_url" :src="item.image_url" :alt="item.name")
                        span(v-else) SF
                    .catalog-card-copy
                        small {{ item.category?.name ?? 'Каталог' }}
                        NuxtLink.catalog-card-title(:to="item.url ?? `/api/catalog/products/${item.slug}`")
                            h2 {{ item.name }}
                        .rating-line
                            span ★ {{ ratingLabel(item) }}
                            small {{ item.rating_count }} оценок
                        ul.catalog-card-attributes(v-if="item.attributes.length")
                            li(v-for="attribute in item.attributes.slice(0, 2)" :key="attribute.name")
                                span {{ filterLabel(attribute.name) }}
                                b {{ attribute.value }}
                        .catalog-card-bottom
                            div
                                del(v-if="item.price?.has_discount")
                                    | {{ new Intl.NumberFormat('ru-RU').format(item.price.original_amount_minor / 100) }} {{ item.price.currency }}
                                strong {{ formatMoney(item) }}
                                small(:class="{ muted: !item.availability.in_stock }")
                                    | {{ item.availability.in_stock ? 'В наличии' : 'Нет в наличии' }}
                            button.cart-add(type="button" aria-label="Добавить в корзину" @click="customer.addCartItem(item)") +

            p.catalog-message(v-else-if="catalogPending") Загружаем товары…
            p.catalog-message(v-else-if="catalogError") Не удалось загрузить каталог. Попробуйте обновить страницу.
            p.catalog-message(v-else) По выбранным условиям товаров не найдено.

            nav.pagination(v-if="lastPage > 1" aria-label="Страницы каталога")
                button(type="button" :disabled="currentPage === 1" @click="setPage(currentPage - 1)") Назад
                button(
                    v-for="page in pageNumbers"
                    :key="page"
                    type="button"
                    :class="{ active: currentPage === page }"
                    @click="setPage(page)"
                ) {{ page }}
                button(type="button" :disabled="currentPage === lastPage" @click="setPage(currentPage + 1)") Вперёд

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Каталог товаров для дома, работы и отдыха.
        NuxtLink(to="/cart/") Перейти в корзину
</template>
