<script setup lang="ts">
defineOptions({ name: 'CatalogRoute' });

const route = useRoute();
const catalogApi = useCatalogApi();
const normalizedPath = computed(() => `${route.path.replace(/\/+$/g, '')}/`);
const candidateSlug = computed(() => {
    if (normalizedPath.value.includes('/filter/')) {
        return '';
    }

    return normalizedPath.value.split('/').filter(Boolean).at(-1) ?? '';
});
const { data: candidateProduct } = await useAsyncData(
    'catalog-route-product',
    async () => (candidateSlug.value ? catalogApi.fetchProduct(candidateSlug.value) : null),
    {
        watch: [candidateSlug],
    },
);
const isProductPage = computed(() => candidateProduct.value?.url === normalizedPath.value);
</script>

<template lang="pug">
ProductMarketplace(v-if="isProductPage")
CatalogMarketplace(v-else)
</template>
