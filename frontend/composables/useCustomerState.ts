import type { CatalogProduct } from '~/composables/useCatalogApi';

type CustomerProduct = {
    product_id: number;
    slug: string;
    sku: string;
    product_name: string;
};

type CartItem = CustomerProduct & {
    quantity: number;
};

type CustomerState = {
    cart: {
        id: number | null;
        items: CartItem[];
    };
    favorites: CustomerProduct[];
};

type Customer = {
    id: number;
    name: string;
    email: string;
};

type SessionData = {
    user: Customer | null;
    customer_state: CustomerState | null;
};

const STORAGE_KEY = 'stockflow-customer-state';

export const useCustomerState = () => {
    const config = useRuntimeConfig();
    const apiBase = config.public.apiBase;
    const user = useState<Customer | null>('customer-user', () => null);
    const customerState = useState<CustomerState | null>('customer-state', () => null);
    const guestState = useState<CustomerState>('guest-customer-state', emptyState);
    const initialized = useState('customer-state-initialized', () => false);

    const state = computed(() => customerState.value ?? guestState.value);
    const cartCount = computed(() => state.value.cart.items.reduce((total, item) => total + item.quantity, 0));
    const favoriteCount = computed(() => state.value.favorites.length);

    const initialize = async () => {
        if (initialized.value || import.meta.server) {
            return;
        }

        guestState.value = readGuestState();

        try {
            const response = await request<{ data: SessionData }>('/api/session');
            user.value = response.data.user;
            customerState.value = response.data.customer_state;
        } finally {
            initialized.value = true;
        }
    };

    const login = async (email: string, password: string) => {
        const response = await request<{ data: SessionData }>('/api/session/login', {
            method: 'POST',
            body: { email, password },
            mutation: true,
        });

        await adoptGuestState(response.data);
    };

    const register = async (name: string, email: string, password: string) => {
        const response = await request<{ data: SessionData }>('/api/session/register', {
            method: 'POST',
            body: {
                name,
                email,
                password,
                password_confirmation: password,
            },
            mutation: true,
        });

        await adoptGuestState(response.data);
    };

    const logout = async () => {
        await request('/api/session', { method: 'DELETE', mutation: true });
        user.value = null;
        customerState.value = null;
    };

    const addCartItem = async (product: CatalogProduct) => {
        const currentQuantity =
            state.value.cart.items.find((item) => item.product_id === product.id)?.quantity ?? 0;

        await setCartItem(product, currentQuantity + 1);
    };

    const setCartItem = async (product: CatalogProduct | CustomerProduct, quantity: number) => {
        if (quantity < 1) {
            await removeCartItem(productId(product));
            return;
        }

        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>(
                    `/api/customer-state/cart/items/${productId(product)}`,
                    {
                        method: 'PUT',
                        body: { quantity },
                        mutation: true,
                    },
                )
            ).data;
            return;
        }

        const item = toCustomerProduct(product);
        const existingItem = guestState.value.cart.items.find((entry) => entry.product_id === item.product_id);

        if (existingItem) {
            existingItem.quantity = quantity;
        } else {
            guestState.value.cart.items.push({ ...item, quantity });
        }

        persistGuestState();
    };

    const removeCartItem = async (productId: number) => {
        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>(`/api/customer-state/cart/items/${productId}`, {
                    method: 'DELETE',
                    mutation: true,
                })
            ).data;
            return;
        }

        guestState.value.cart.items = guestState.value.cart.items.filter((item) => item.product_id !== productId);
        persistGuestState();
    };

    const toggleFavorite = async (product: CatalogProduct | CustomerProduct) => {
        const item = toCustomerProduct(product);
        const favorite = isFavorite(item.product_id);

        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>(`/api/customer-state/favorites/${item.product_id}`, {
                    method: favorite ? 'DELETE' : 'PUT',
                    mutation: true,
                })
            ).data;
            return;
        }

        guestState.value.favorites = favorite
            ? guestState.value.favorites.filter((entry) => entry.product_id !== item.product_id)
            : [...guestState.value.favorites, item];
        persistGuestState();
    };

    const isFavorite = (productId: number) =>
        state.value.favorites.some((favorite) => favorite.product_id === productId);

    const adoptGuestState = async (session: SessionData) => {
        user.value = session.user;
        customerState.value = session.customer_state;

        if (!user.value || (!guestState.value.cart.items.length && !guestState.value.favorites.length)) {
            return;
        }

        customerState.value = (
            await request<{ data: CustomerState }>('/api/customer-state/merge', {
                method: 'POST',
                body: {
                    cart_items: guestState.value.cart.items.map(({ product_id, quantity }) => ({
                        product_id,
                        quantity,
                    })),
                    favorite_product_ids: guestState.value.favorites.map(({ product_id }) => product_id),
                },
                mutation: true,
            })
        ).data;
        guestState.value = emptyState();
        persistGuestState();
    };

    const persistGuestState = () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(guestState.value));
    };

    const request = async <T = unknown>(
        path: string,
        options: { method?: 'GET' | 'POST' | 'PUT' | 'DELETE'; body?: object; mutation?: boolean } = {},
    ) => {
        const headers: Record<string, string> = {};

        if (options.mutation) {
            const csrf = await $fetch<{ csrf_token: string }>('/api/session/csrf', {
                baseURL: apiBase,
                credentials: 'include',
            });
            headers['X-CSRF-TOKEN'] = csrf.csrf_token;
        }

        return $fetch<T>(path, {
            baseURL: apiBase,
            credentials: 'include',
            method: options.method,
            body: options.body,
            headers,
        });
    };

    return {
        user: readonly(user),
        state: readonly(state),
        initialized: readonly(initialized),
        cartCount,
        favoriteCount,
        initialize,
        login,
        register,
        logout,
        addCartItem,
        setCartItem,
        removeCartItem,
        toggleFavorite,
        isFavorite,
    };
};

function emptyState(): CustomerState {
    return {
        cart: {
            id: null,
            items: [],
        },
        favorites: [],
    };
}

function readGuestState(): CustomerState {
    const stored = localStorage.getItem(STORAGE_KEY);

    if (!stored) {
        return emptyState();
    }

    try {
        return JSON.parse(stored) as CustomerState;
    } catch {
        return emptyState();
    }
}

function toCustomerProduct(product: CatalogProduct | CustomerProduct): CustomerProduct {
    if ('product_id' in product) {
        return product;
    }

    return {
        product_id: product.id,
        slug: product.slug,
        sku: product.sku,
        product_name: product.name,
    };
}

function productId(product: CatalogProduct | CustomerProduct): number {
    return 'product_id' in product ? product.product_id : product.id;
}
