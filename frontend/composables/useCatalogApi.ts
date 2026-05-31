type ApiResource<T> = {
    data: T;
};

type ApiPaginatedResource<T> = ApiResource<T> & {
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
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
    short_description: string | null;
    image_url: string | null;
    status: 'draft' | 'published' | 'archived';
    availability: {
        in_stock: boolean;
        available_quantity: number;
    };
    published_at: string | null;
    price: {
        has_discount: boolean;
        amount_minor: number;
        original_amount_minor: number;
        discount_amount_minor: number;
        discount_percent: number;
        currency: string;
    } | null;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    brand: {
        id: number;
        name: string;
        slug: string;
        logo_url: string | null;
    } | null;
    attributes: Array<{
        name: string;
        value: string;
    }>;
    card_attributes: Array<{
        name: string;
        value: string;
    }>;
    gallery: Array<{
        id: number;
        file_id: number;
        title: string | null;
        url: string | null;
    }>;
    documents: Array<{
        id: number;
        file_id: number;
        type: 'instruction' | 'certificate' | 'attachment';
        title: string;
        url: string | null;
        mime_type: string | null;
        size: number | null;
    }>;
    warehouses: Array<{
        warehouse_id: number;
        warehouse_code: string | null;
        warehouse_name: string | null;
        city_code: string | null;
        city_name: string | null;
        available_quantity: number;
        in_stock: boolean;
    }>;
};

export type CatalogProductList = {
    products: CatalogProduct[];
    meta: ApiPaginatedResource<CatalogProduct[]>['meta'];
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

    const fetchProducts = async (params: { category?: string; page?: number; per_page?: number } = {}) => {
        const response = await $fetch<ApiPaginatedResource<CatalogProduct[]>>('/api/catalog/products', {
            baseURL: apiBase,
            query: params,
        });

        return {
            products: response.data,
            meta: response.meta,
        };
    };

    return {
        fetchCategoryTree,
        fetchProduct,
        fetchProducts,
    };
};

function isNotFoundError(error: unknown): boolean {
    return typeof error === 'object' && error !== null && 'statusCode' in error && error.statusCode === 404;
}
