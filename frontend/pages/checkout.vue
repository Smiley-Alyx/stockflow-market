<script setup lang="ts">
const route = useRoute();
const customer = useCustomerState();
const searchInput = ref('');
const orderId = computed(() => Number(route.query.order));
const paymentMethod = ref('');
const commonDeliveryService = ref('');
const separateItemIds = ref<number[]>([]);
const separateDeliveryServices = reactive<Record<number, string>>({});
const submitPending = ref(false);
const submitMessage = ref('');
const submitError = ref('');
const hydrated = ref(false);
const address = reactive({
    recipient_name: '',
    recipient_phone: '',
    country_code: 'RU',
    city: '',
    postal_code: '',
    address_line_1: '',
    address_line_2: '',
});

const { data: checkout, error } = await useAsyncData(
    () => `checkout-${orderId.value}`,
    () => customer.fetchCheckout(orderId.value),
);
const order = computed(() => checkout.value?.order ?? null);
const options = computed(() => checkout.value?.options ?? { payment_methods: [], delivery_services: [] });
const shipments = computed(() => {
    if (!order.value) {
        return [];
    }

    const result = order.value.items
        .filter((item) => !separateItemIds.value.includes(item.id))
        .map((item) => ({
            order_item_id: item.id,
            quantity: item.quantity,
        }));
    const groups = result.length
        ? [{ delivery_service: commonDeliveryService.value, items: result }]
        : [];

    separateItemIds.value.forEach((itemId) => {
        const item = order.value?.items.find((entry) => entry.id === itemId);

        if (item) {
            groups.push({
                delivery_service: separateDeliveryServices[itemId] ?? commonDeliveryService.value,
                items: [{ order_item_id: item.id, quantity: item.quantity }],
            });
        }
    });

    return groups;
});

const submitSearch = () => {
    const query = searchInput.value.trim();

    navigateTo({
        path: '/search/',
        query: query ? { q: query } : {},
    });
};

watch(
    checkout,
    (value) => {
        if (!value || hydrated.value) {
            return;
        }

        Object.assign(address, Object.fromEntries(
            Object.entries(value.order.address).map(([key, entry]) => [key, entry ?? '']),
        ));
        paymentMethod.value = value.order.payment_method ?? value.options.payment_methods[0]?.code ?? '';
        commonDeliveryService.value = value.options.delivery_services[0]?.code ?? '';

        value.order.shipments.forEach((shipment) => {
            if (shipment.items.length === 1) {
                const itemId = shipment.items[0]?.order_item_id;

                if (itemId) {
                    separateItemIds.value.push(itemId);
                    separateDeliveryServices[itemId] = shipment.delivery_service;
                }
            } else {
                commonDeliveryService.value = shipment.delivery_service;
            }
        });
        hydrated.value = true;
    },
    { immediate: true },
);

const submit = async () => {
    submitPending.value = true;
    submitMessage.value = '';
    submitError.value = '';

    try {
        await customer.configureCheckout(orderId.value, {
            payment_method: paymentMethod.value,
            address,
            shipments: shipments.value,
        });
        await customer.confirmOrder(orderId.value);
        submitMessage.value = `Заказ №${orderId.value} оформлен.`;
    } catch {
        submitError.value = 'Не удалось сохранить оформление. Проверьте адрес и распределение доставок.';
    } finally {
        submitPending.value = false;
    }
};

function formatMoney(amountMinor: number, currency: string | null): string {
    if (!currency) {
        return `${(amountMinor / 100).toLocaleString('ru-RU')} у. е.`;
    }

    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
    }).format(amountMinor / 100);
}

useHead({
    title: 'Оформление заказа | StockFlow Market',
    htmlAttrs: {
        lang: 'ru',
    },
});
</script>

<template lang="pug">
main.market-page-shell
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
        nav.category-nav(aria-label="Разделы магазина")
            NuxtLink.category-nav-all(to="/catalog/") Все категории
            NuxtLink(to="/catalog/") Каталог
            NuxtLink(to="/favorites/") Избранное

    section.catalog-breadcrumbs(aria-label="Хлебные крошки")
        NuxtLink(to="/") Главная
        span /
        NuxtLink(to="/cart/") Корзина
        span /
        span Оформление

    section.market-page-heading
        div
            p.eyebrow Заказ №{{ orderId }}
            h1 Оформление заказа
            p Укажите получателя, выберите оплату и удобную службу доставки.
        ol.checkout-steps
            li.active 1. Корзина
            li.active 2. Доставка и оплата
            li 3. Готово

    p.form-error(v-if="error") Не удалось загрузить заказ.

    form.checkout-layout.market-page-content(v-else-if="order" @submit.prevent="submit")
        .checkout-main
            section.panel.checkout-section
                .panel-heading
                    h2 Адрес доставки
                    span Получатель
                .checkout-fields
                    label
                        span Имя получателя
                        input(v-model="address.recipient_name" type="text" required)
                    label
                        span Телефон
                        input(v-model="address.recipient_phone" type="tel" required)
                    label
                        span Страна
                        input(v-model="address.country_code" type="text" minlength="2" maxlength="2" required)
                    label
                        span Город
                        input(v-model="address.city" type="text" required)
                    label
                        span Индекс
                        input(v-model="address.postal_code" type="text" required)
                    label.checkout-field-wide
                        span Адрес
                        input(v-model="address.address_line_1" type="text" required)
                    label.checkout-field-wide
                        span Квартира, офис или комментарий
                        input(v-model="address.address_line_2" type="text")

            section.panel.checkout-section
                .panel-heading
                    h2 Способ оплаты
                    span Один способ для заказа
                .checkout-options
                    label(v-for="option in options.payment_methods" :key="option.code")
                        input(v-model="paymentMethod" type="radio" name="payment-method" :value="option.code" required)
                        span {{ option.name }}

            section.panel.checkout-section
                .panel-heading
                    h2 Доставка
                    span Можно разделить позиции
                label.checkout-service
                    span Служба для общей доставки
                    select(v-model="commonDeliveryService" required)
                        option(v-for="option in options.delivery_services" :key="option.code" :value="option.code")
                            | {{ option.name }}
                ul.checkout-item-list
                    li(v-for="item in order.items" :key="item.id")
                        div
                            strong {{ item.product_name }}
                            code {{ item.sku }} · {{ item.quantity }} шт.
                        label
                            input(v-model="separateItemIds" type="checkbox" :value="item.id")
                            span Доставить отдельно
                        select(
                            v-if="separateItemIds.includes(item.id)"
                            v-model="separateDeliveryServices[item.id]"
                            required
                        )
                            option(
                                v-for="option in options.delivery_services"
                                :key="option.code"
                                :value="option.code"
                            ) {{ option.name }}

        aside.panel.cart-summary-panel
            .panel-heading
                h2 Ваш заказ
                span {{ order.items.length }} поз.
            strong.checkout-total {{ formatMoney(order.total_amount_minor, order.currency) }}
            p.account-note {{ order.items.reduce((total, item) => total + item.quantity, 0) }} товаров, доставок после разделения: {{ shipments.length }}
            button.primary-button(type="submit" :disabled="submitPending") Подтвердить заказ
            p.checkout-message(v-if="submitMessage") {{ submitMessage }}
            p.form-error(v-if="submitError") {{ submitError }}

    footer.market-footer
        NuxtLink.market-logo(to="/")
            span.brand-mark SF
            span
                b StockFlow
                small market
        p Безопасное оформление заказа с выбором доставки и оплаты.
        NuxtLink(to="/cart/") Вернуться в корзину
</template>
