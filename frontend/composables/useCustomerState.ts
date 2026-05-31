import type { CatalogProduct } from '~/composables/useCatalogApi';

type CustomerPrice = CatalogProduct['price'];

type CustomerProduct = {
    product_id: number;
    slug: string;
    sku: string;
    product_name: string;
    image_url: string | null;
    price: CustomerPrice;
};

type CartItem = CustomerProduct & {
    quantity: number;
    is_selected: boolean;
    removed_at: string | null;
    line_amount_minor: number | null;
};

type CartSummary = {
    items_count: number;
    selected_items_count: number;
    amount_minor: number;
    selected_amount_minor: number;
    currency: string | null;
};

type CustomerState = {
    cart: {
        id: number | null;
        items: CartItem[];
        removed_items: CartItem[];
        summary: CartSummary;
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

type DraftOrder = {
    id: number;
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

        guestState.value.cart.removed_items = guestState.value.cart.removed_items.filter(
            (entry) => entry.product_id !== item.product_id,
        );

        if (existingItem) {
            existingItem.quantity = quantity;
            existingItem.is_selected = true;
            existingItem.line_amount_minor = lineAmount(existingItem);
        } else {
            guestState.value.cart.items.push({
                ...item,
                quantity,
                is_selected: true,
                removed_at: null,
                line_amount_minor: item.price ? item.price.amount_minor * quantity : null,
            });
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

        const item = guestState.value.cart.items.find((entry) => entry.product_id === productId);

        if (item) {
            guestState.value.cart.items = guestState.value.cart.items.filter((entry) => entry.product_id !== productId);
            guestState.value.cart.removed_items = [
                ...guestState.value.cart.removed_items.filter((entry) => entry.product_id !== productId),
                { ...item, is_selected: false, removed_at: new Date().toISOString() },
            ];
            persistGuestState();
        }
    };

    const restoreCartItem = async (productId: number) => {
        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>(`/api/customer-state/cart/items/${productId}/restore`, {
                    method: 'PUT',
                    mutation: true,
                })
            ).data;
            return;
        }

        const item = guestState.value.cart.removed_items.find((entry) => entry.product_id === productId);

        if (item) {
            guestState.value.cart.removed_items = guestState.value.cart.removed_items.filter(
                (entry) => entry.product_id !== productId,
            );
            guestState.value.cart.items.push({ ...item, is_selected: true, removed_at: null });
            persistGuestState();
        }
    };

    const selectCartItem = async (productId: number, selected: boolean) => {
        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>(`/api/customer-state/cart/items/${productId}/selection`, {
                    method: 'PUT',
                    body: { selected },
                    mutation: true,
                })
            ).data;
            return;
        }

        const item = guestState.value.cart.items.find((entry) => entry.product_id === productId);

        if (item) {
            item.is_selected = selected;
            persistGuestState();
        }
    };

    const selectAllCartItems = async (selected: boolean) => {
        if (user.value) {
            customerState.value = (
                await request<{ data: CustomerState }>('/api/customer-state/cart/selection', {
                    method: 'PUT',
                    body: { selected },
                    mutation: true,
                })
            ).data;
            return;
        }

        guestState.value.cart.items.forEach((item) => {
            item.is_selected = selected;
        });
        persistGuestState();
    };

    const checkout = async (selectedOnly: boolean) => {
        const items = state.value.cart.items.filter((item) => !selectedOnly || item.is_selected);

        if (!items.length) {
            throw new Error('Cart is empty');
        }

        let cartId = state.value.cart.id;

        if (!user.value) {
            cartId = null;

            for (const item of items) {
                const response: { data: { id: number } } = await request('/api/cart/items', {
                    method: 'POST',
                    body: {
                        cart_id: cartId,
                        product_id: item.product_id,
                        quantity: item.quantity,
                    },
                    mutation: true,
                });
                cartId = response.data.id;
            }
        }

        const response = await request<{ data: DraftOrder }>('/api/orders/draft', {
            method: 'POST',
            body: {
                cart_id: cartId,
                product_ids: items.map((item) => item.product_id),
            },
            mutation: true,
        });

        return response.data;
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
        guestState.value.cart.summary = cartSummary(guestState.value.cart.items);
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
        restoreCartItem,
        selectCartItem,
        selectAllCartItems,
        checkout,
        toggleFavorite,
        isFavorite,
    };
};

function emptyState(): CustomerState {
    return {
        cart: {
            id: null,
            items: [],
            removed_items: [],
            summary: cartSummary([]),
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
        const state = JSON.parse(stored) as Partial<CustomerState>;
        const cart = state.cart ?? emptyState().cart;
        const items = (cart.items ?? []).map(normalizeCartItem);

        return {
            cart: {
                id: null,
                items,
                removed_items: (cart.removed_items ?? []).map(normalizeCartItem),
                summary: cartSummary(items),
            },
            favorites: (state.favorites ?? []).map(normalizeCustomerProduct),
        };
    } catch {
        return emptyState();
    }
}

function normalizeCartItem(item: Partial<CartItem> & Pick<CartItem, 'product_id' | 'slug' | 'sku' | 'product_name' | 'quantity'>): CartItem {
    const product = normalizeCustomerProduct(item);

    return {
        ...product,
        quantity: item.quantity,
        is_selected: item.is_selected ?? true,
        removed_at: item.removed_at ?? null,
        line_amount_minor: product.price ? product.price.amount_minor * item.quantity : null,
    };
}

function normalizeCustomerProduct(product: Partial<CustomerProduct> & Pick<CustomerProduct, 'product_id' | 'slug' | 'sku' | 'product_name'>): CustomerProduct {
    return {
        product_id: product.product_id,
        slug: product.slug,
        sku: product.sku,
        product_name: product.product_name,
        image_url: product.image_url ?? null,
        price: product.price ?? null,
    };
}

function toCustomerProduct(product: CatalogProduct | CustomerProduct): CustomerProduct {
    if ('product_id' in product) {
        return normalizeCustomerProduct(product);
    }

    return {
        product_id: product.id,
        slug: product.slug,
        sku: product.sku,
        product_name: product.name,
        image_url: product.image_url,
        price: product.price,
    };
}

function cartSummary(items: CartItem[]): CartSummary {
    const pricedItems = items.filter((item) => item.line_amount_minor !== null);
    const currencies = [...new Set(pricedItems.map((item) => item.price?.currency).filter(Boolean))];

    return {
        items_count: items.reduce((total, item) => total + item.quantity, 0),
        selected_items_count: items.filter((item) => item.is_selected).reduce((total, item) => total + item.quantity, 0),
        amount_minor: pricedItems.reduce((total, item) => total + (item.line_amount_minor ?? 0), 0),
        selected_amount_minor: pricedItems
            .filter((item) => item.is_selected)
            .reduce((total, item) => total + (item.line_amount_minor ?? 0), 0),
        currency: currencies.length === 1 ? currencies[0] ?? null : null,
    };
}

function lineAmount(item: CartItem): number | null {
    return item.price ? item.price.amount_minor * item.quantity : null;
}

function productId(product: CatalogProduct | CustomerProduct): number {
    return 'product_id' in product ? product.product_id : product.id;
}
