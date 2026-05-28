<script setup lang="ts">
type CategoryNode = {
    id: number;
    name: string;
    slug: string;
    children?: CategoryNode[];
};

const config = useRuntimeConfig();
const apiBase = import.meta.server ? config.apiBase : config.public.apiBase;

const { data: categories, error } = await useAsyncData('catalog-categories', async () => {
    return await $fetch<CategoryNode[]>('/api/catalog/categories/tree', {
        baseURL: apiBase,
    });
});

const visibleCategories = computed(() => categories.value?.slice(0, 4) ?? []);
const catalogStatus = computed(() => (error.value ? 'API offline' : 'API ready'));

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

                <span class="status" :class="{ 'status-muted': error }">{{ catalogStatus }}</span>
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
                        <strong>{{ visibleCategories.length || '0' }}</strong>
                    </article>
                    <article>
                        <span>Gateway</span>
                        <strong>Laravel</strong>
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

            <article class="panel accent-panel">
                <div class="panel-heading">
                    <h2>Ближайший фокус</h2>
                    <span>frontend shell</span>
                </div>

                <div class="timeline">
                    <span>01</span>
                    <p>Подключить страницы каталога к read-моделям.</p>
                    <span>02</span>
                    <p>Добавить состояния загрузки, пустых данных и ошибок API.</p>
                    <span>03</span>
                    <p>Развести пользовательские и операционные сценарии.</p>
                </div>
            </article>
        </section>
    </main>
</template>
