type ApiResource<T> = {
    data: T;
};

export type CatalogCategory = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    children: CatalogCategory[];
};

export type CatalogProduct = {
    id: number;
    name: string;
    slug: string;
    sku: string;
    description: string | null;
    status: 'draft' | 'published' | 'archived';
    published_at: string | null;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    attributes: Array<{
        name: string;
        value: string;
    }>;
};

export const useCatalogApi = () => {
    const config = useRuntimeConfig();
    const apiBase = import.meta.server ? config.apiBase : config.public.apiBase;

    const fetchCategoryTree = async () => {
        const response = await $fetch<ApiResource<CatalogCategory[]>>('/api/catalog/categories/tree', {
            baseURL: apiBase,
        });

        return response.data;
    };

    const fetchProduct = async (slug: string) => {
        try {
            const response = await $fetch<ApiResource<CatalogProduct>>(
                `/api/catalog/products/${encodeURIComponent(slug)}`,
                {
                    baseURL: apiBase,
                },
            );

            return response.data;
        } catch (error) {
            if (isNotFoundError(error)) {
                return null;
            }

            throw error;
        }
    };

    return {
        fetchCategoryTree,
        fetchProduct,
    };
};

function isNotFoundError(error: unknown): boolean {
    return typeof error === 'object' && error !== null && 'statusCode' in error && error.statusCode === 404;
}
