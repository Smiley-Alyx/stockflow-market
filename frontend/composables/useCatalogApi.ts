type ApiResource<T> = {
    data: T;
};

type ApiPaginatedResource<T, M = PaginationMeta> = ApiResource<T> & {
    meta: M;
};

type PaginationMeta = {
    current_page: number;
    last_page?: number;
    per_page: number;
    total: number;
};

export type CatalogCategory = {
    id: number;
    name: string;
    slug: string;
    url: string | null;
    description: string | null;
    image_url: string | null;
    children: CatalogCategory[];
};

export type CatalogProduct = {
    id: number;
    name: string;
    slug: string;
    url: string | null;
    sku: string;
    description: string | null;
    short_description: string | null;
    image_url: string | null;
    rating: number | null;
    rating_count: number;
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
        city_code?: string | null;
    } | null;
    category: {
        id: number;
        name: string;
        slug: string;
        path?: string;
        url?: string;
        image_url?: string | null;
        breadcrumbs?: CatalogBreadcrumb[];
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
    offers: Array<{
        id: number;
        name: string;
        sku: string;
        status: 'active' | 'inactive';
        image_url: string | null;
        attributes: Record<string, string>;
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

export type HomepageCity = {
    code: string;
    name: string;
    warehouses: Array<{
        id: number;
        code: string;
        name: string;
    }>;
};

export type HomepageBlock = {
    id: number;
    type: 'banner' | 'recommended_products' | 'bestseller_products' | 'new_products' | 'cities' | 'description';
    title: string;
    position: number;
    content: {
        headline?: string;
        text?: string;
        button_label?: string;
        button_url?: string;
        image_url?: string | null;
        products?: CatalogProduct[];
        cities?: HomepageCity[];
    };
};

export type CatalogFilterValue = {
    value: string;
    count: number;
    url: string;
};

export type CatalogBreadcrumb = {
    id: number;
    name: string;
    slug: string;
    url: string;
};

export type CatalogMeta = PaginationMeta & {
    query: string | null;
    category: string | null;
    category_path: string | null;
    canonical_url: string;
    breadcrumbs: CatalogBreadcrumb[];
    filter_url: string;
    price_range: {
        min: number | null;
        max: number | null;
        selected_min: number | null;
        selected_max: number | null;
        url: string;
        url_template: string;
    };
    city_code: string | null;
    sort: 'newest' | 'price_asc' | 'price_desc' | 'rating_asc' | 'rating_desc';
    filters: Record<string, CatalogFilterValue[]>;
    status?: 'degraded';
    reason?: string;
};

export type CatalogProductList = {
    products: CatalogProduct[];
    meta: CatalogMeta;
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

    const fetchProducts = async (params: CatalogQueryParams = {}) => {
        const response = await $fetch<ApiPaginatedResource<CatalogProduct[], CatalogMeta>>('/api/catalog/products', {
            baseURL: apiBase,
            query: params,
        });

        return {
            products: response.data,
            meta: response.meta,
        };
    };

    const fetchCatalogPath = async (path = '', params: CatalogQueryParams = {}) => {
        const endpoint = path ? `/catalog/${path.replace(/^\/+|\/+$/g, '')}/` : '/api/catalog/products';
        const response = await $fetch<ApiPaginatedResource<CatalogProduct[], CatalogMeta>>(endpoint, {
            baseURL: apiBase,
            query: params,
        });

        return {
            products: response.data,
            meta: response.meta,
        };
    };

    const fetchHomepage = async () => {
        const response = await $fetch<ApiResource<HomepageBlock[]>>('/api/homepage', {
            baseURL: apiBase,
        });

        return response.data;
    };

    return {
        fetchCategoryTree,
        fetchProduct,
        fetchProducts,
        fetchCatalogPath,
        fetchHomepage,
    };
};

export type CatalogQueryParams = {
    category?: string;
    q?: string;
    sort?: string;
    page?: number;
    per_page?: number;
    in_stock?: boolean | number;
    city_code?: string;
};

function isNotFoundError(error: unknown): boolean {
    return typeof error === 'object' && error !== null && 'statusCode' in error && error.statusCode === 404;
}
