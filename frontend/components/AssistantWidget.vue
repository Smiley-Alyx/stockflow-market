<script setup lang="ts">
import type { CatalogProduct } from '~/composables/useCatalogApi';

type AssistantMessage = {
    id: number;
    role: 'assistant' | 'user';
    text: string;
    products?: CatalogProduct[];
    comparison?: boolean;
};

type SpeechRecognitionInstance = {
    lang: string;
    interimResults: boolean;
    continuous: boolean;
    onresult: ((event: { results: ArrayLike<{ 0: { transcript: string } }> }) => void) | null;
    onerror: (() => void) | null;
    onend: (() => void) | null;
    start: () => void;
    stop: () => void;
};

type SpeechRecognitionConstructor = new () => SpeechRecognitionInstance;

const catalogApi = useCatalogApi();
const customer = useCustomerState();
const open = ref(false);
const input = ref('');
const pending = ref(false);
const listening = ref(false);
const voiceAvailable = ref(false);
const messageList = ref<HTMLElement | null>(null);
const productCache = ref<CatalogProduct[] | null>(null);
const recognition = shallowRef<SpeechRecognitionInstance | null>(null);
const messages = ref<AssistantMessage[]>(initialMessages());

const quickPrompts = [
    'Что подарить коллеге?',
    'Товары для работы до 10 000',
    'Покажи хиты продаж',
];

const followUpPrompts = computed(() => {
    const products = [...messages.value].reverse().find((message) => message.products?.length)?.products ?? [];

    if (products.length >= 2) {
        return ['Сравни первые два', 'Только в наличии', 'Покажи дешевле'];
    }

    return quickPrompts;
});

watch(
    () => messages.value.length,
    async () => {
        await nextTick();
        messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: 'smooth' });
    },
);

const toggle = () => {
    open.value = !open.value;
};

const reset = () => {
    messages.value = initialMessages();
    input.value = '';
};

const submit = async (prompt = input.value) => {
    const text = prompt.trim();

    if (!text || pending.value) {
        return;
    }

    input.value = '';
    messages.value.push({ id: Date.now(), role: 'user', text });
    pending.value = true;

    try {
        await respond(text);
    } catch {
        messages.value.push({
            id: Date.now() + 1,
            role: 'assistant',
            text: 'Не удалось получить данные каталога. Попробуйте ещё раз или перейдите в обычный поиск.',
        });
    } finally {
        pending.value = false;
    }
};

const respond = async (text: string) => {
    const normalized = normalize(text);
    const previousProducts = [...messages.value].reverse().find((message) => message.products?.length)?.products ?? [];

    if (/сравн|отлич|разниц/.test(normalized) && previousProducts.length >= 2) {
        const products = previousProducts.slice(0, 2);
        messages.value.push({
            id: Date.now() + 1,
            role: 'assistant',
            text: comparisonText(products),
            products,
            comparison: true,
        });
        return;
    }

    if (/только в наличии|покажи дешевле/.test(normalized) && previousProducts.length) {
        const inStockOnly = /только в наличии/.test(normalized);
        const result = previousProducts
            .filter((product) => !inStockOnly || product.availability.in_stock)
            .sort((left, right) => priceValue(left) - priceValue(right));

        messages.value.push({
            id: Date.now() + 1,
            role: 'assistant',
            text: result.length
                ? inStockOnly
                    ? 'Оставил товары, которые сейчас есть на складах.'
                    : 'Переставил варианты от более доступных к дорогим.'
                : 'Среди этой подборки сейчас нет товаров в наличии.',
            products: result,
        });
        return;
    }

    const products = await loadProducts();
    const maxPrice = extractMaxPrice(normalized);
    const inStockOnly = /в наличии|достав|забрать|сегодня|быстр/.test(normalized);
    const cheaper = /дешев|бюджет|недорог/.test(normalized);
    const ranked = products
        .filter((product) => !inStockOnly || product.availability.in_stock)
        .filter((product) => maxPrice === null || !product.price || product.price.amount_minor <= maxPrice * 100)
        .map((product) => ({ product, score: scoreProduct(product, normalized) }))
        .sort((left, right) => {
            if (cheaper && left.score === right.score) {
                return priceValue(left.product) - priceValue(right.product);
            }

            return right.score - left.score;
        });
    const hasMeaningfulMatch = ranked.some((entry) => entry.score > 0);
    const result = (hasMeaningfulMatch ? ranked.filter((entry) => entry.score > 0) : ranked).slice(0, 5).map((entry) => entry.product);

    messages.value.push({
        id: Date.now() + 1,
        role: 'assistant',
        text: result.length
            ? resultText(text, result, maxPrice, inStockOnly, hasMeaningfulMatch)
            : 'Под эти условия товаров не нашлось. Попробуйте увеличить бюджет или убрать одно из ограничений.',
        products: result,
    });
};

const loadProducts = async () => {
    if (!productCache.value) {
        productCache.value = (await catalogApi.fetchProducts({ per_page: 100 })).products;
    }

    return productCache.value;
};

const startVoice = () => {
    if (!recognition.value || listening.value) {
        return;
    }

    listening.value = true;
    recognition.value.start();
};

function initialMessages(): AssistantMessage[] {
    return [
        {
            id: 1,
            role: 'assistant',
            text: 'Здравствуйте! Я помогу подобрать товар по задаче, бюджету и характеристикам. Что вы ищете?',
        },
    ];
}

function normalize(value: string): string {
    const synonyms: Record<string, string> = {
        кеды: 'кроссовки',
        ноут: 'ноутбук',
        комп: 'компьютер',
        мобила: 'телефон',
        смартфон: 'телефон',
        подарок: 'подарить',
        рабочий: 'работа',
        офисный: 'работа',
    };

    return value
        .toLocaleLowerCase('ru-RU')
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => synonyms[word] ?? word)
        .join(' ');
}

function searchableText(product: CatalogProduct): string {
    return normalize([
        product.name,
        product.short_description,
        product.description,
        product.category?.name,
        product.brand?.name,
        ...product.attributes.flatMap((attribute) => [attribute.name, attribute.value]),
    ].filter(Boolean).join(' '));
}

function scoreProduct(product: CatalogProduct, query: string): number {
    const haystack = searchableText(product);
    const ignored = new Set([
        'и', 'в', 'на', 'для', 'до', 'от', 'из', 'мне', 'хочу', 'ищу', 'нужен', 'нужна', 'нужно',
        'покажи', 'подбери', 'товар', 'товары', 'первый', 'первые', 'два', 'только',
    ]);
    const tokens = query.split(' ').filter((token) => token.length > 2 && !ignored.has(token) && !/^\d+$/.test(token));
    let score = tokens.reduce((total, token) => total + (haystack.includes(token) ? 3 : 0), 0);

    if (/хит|популяр|лучш/.test(query)) {
        score += (product.rating ?? 0) + Math.min(product.rating_count / 10, 5);
    }

    if (/подар|коллег|универсаль/.test(query) && product.price && product.price.amount_minor <= 1500000) {
        score += 2;
    }

    if (/работ|офис/.test(query) && /ноутбук|клавиатур|мыш|наушник|кресл|ламп/.test(haystack)) {
        score += 6;
    }

    if (product.availability.in_stock) {
        score += 0.5;
    }

    return score;
}

function extractMaxPrice(query: string): number | null {
    const match = query.match(/(?:до|не дороже|бюджет)\s*([\d\s]+(?:[.,]\d+)?)\s*(тыс|тысяч|к)?/);

    if (!match) {
        return null;
    }

    const amount = Number(match[1]?.replace(/\s/g, '').replace(',', '.'));
    return Math.round(amount * (match[2] ? 1000 : 1));
}

function priceValue(product: CatalogProduct): number {
    return product.price?.amount_minor ?? Number.MAX_SAFE_INTEGER;
}

function resultText(
    query: string,
    products: CatalogProduct[],
    maxPrice: number | null,
    inStockOnly: boolean,
    meaningful: boolean,
): string {
    const conditions = [
        maxPrice ? `в бюджете до ${maxPrice.toLocaleString('ru-RU')} ₽` : '',
        inStockOnly ? 'с наличием на складах' : '',
    ].filter(Boolean).join(' и ');

    if (!meaningful) {
        return `Точного совпадения по запросу «${query}» нет. Показываю наиболее популярные доступные варианты. Уточните категорию или характеристики, и я сужу подборку.`;
    }

    return `Нашёл ${products.length} подходящих вариантов${conditions ? ` ${conditions}` : ''}. Первые позиции лучше всего совпадают с вашим запросом.`;
}

function comparisonText(products: CatalogProduct[]): string {
    const [first, second] = products;
    const firstPrice = first?.price?.amount_minor ?? 0;
    const secondPrice = second?.price?.amount_minor ?? 0;
    const cheaper = firstPrice <= secondPrice ? first : second;
    const betterRated = (first?.rating ?? 0) >= (second?.rating ?? 0) ? first : second;

    return `${cheaper?.name} выгоднее по цене, а ${betterRated?.name} лидирует по рейтингу. Ниже собрал ключевые характеристики рядом для быстрого выбора.`;
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

onMounted(() => {
    customer.initialize();
    const browserWindow = window as typeof window & {
        SpeechRecognition?: SpeechRecognitionConstructor;
        webkitSpeechRecognition?: SpeechRecognitionConstructor;
    };
    const Recognition = browserWindow.SpeechRecognition ?? browserWindow.webkitSpeechRecognition;

    if (!Recognition) {
        return;
    }

    voiceAvailable.value = true;
    recognition.value = new Recognition();
    recognition.value.lang = 'ru-RU';
    recognition.value.interimResults = false;
    recognition.value.continuous = false;
    recognition.value.onresult = (event) => {
        const transcript = event.results[0]?.[0]?.transcript;

        if (transcript) {
            input.value = transcript;
            submit(transcript);
        }
    };
    recognition.value.onerror = () => {
        listening.value = false;
    };
    recognition.value.onend = () => {
        listening.value = false;
    };
});

onBeforeUnmount(() => recognition.value?.stop());
</script>

<template lang="pug">
.assistant-layer
    transition(name="assistant-panel")
        section.assistant-panel(v-if="open" aria-label="AI-ассистент магазина")
            header.assistant-header
                .assistant-identity
                    span.assistant-avatar AI
                    span
                        strong Помощник по покупкам
                        small
                            i
                            | Онлайн
                .assistant-header-actions
                    button(type="button" aria-label="Начать заново" title="Начать заново" @click="reset") ↺
                    button(type="button" aria-label="Закрыть помощника" @click="open = false") ×

            .assistant-messages(ref="messageList" aria-live="polite")
                article.assistant-message(
                    v-for="message in messages"
                    :key="message.id"
                    :class="[`is-${message.role}`, { 'has-products': message.products?.length }]"
                )
                    p {{ message.text }}
                    .assistant-products(v-if="message.products?.length" :class="{ comparison: message.comparison }")
                        article.assistant-product(v-for="product in message.products" :key="product.id")
                            NuxtLink.assistant-product-image(:to="product.url ?? '/catalog/'" @click="open = false")
                                img(v-if="product.image_url" :src="product.image_url" :alt="product.name")
                                span(v-else) SF
                            .assistant-product-copy
                                small {{ product.category?.name ?? 'Каталог' }}
                                NuxtLink(:to="product.url ?? '/catalog/'" @click="open = false") {{ product.name }}
                                .assistant-product-meta
                                    strong {{ formatMoney(product) }}
                                    span(v-if="product.rating") ★ {{ product.rating.toFixed(1) }}
                                ul(v-if="message.comparison")
                                    li(v-for="attribute in product.attributes.slice(0, 3)" :key="attribute.name")
                                        span {{ attribute.name }}
                                        b {{ attribute.value }}
                                button(
                                    type="button"
                                    :disabled="!product.availability.in_stock"
                                    @click="customer.addCartItem(product)"
                                ) {{ product.availability.in_stock ? 'В корзину' : 'Нет в наличии' }}

                article.assistant-message.is-assistant.assistant-typing(v-if="pending")
                    span
                    span
                    span

            .assistant-suggestions(v-if="!pending")
                button(v-for="prompt in followUpPrompts" :key="prompt" type="button" @click="submit(prompt)") {{ prompt }}

            form.assistant-input(@submit.prevent="submit()")
                textarea(
                    v-model="input"
                    rows="1"
                    placeholder="Опишите, что вы ищете…"
                    aria-label="Сообщение ассистенту"
                    @keydown.enter.exact.prevent="submit()"
                )
                button.assistant-voice(
                    v-if="voiceAvailable"
                    type="button"
                    :class="{ listening }"
                    :aria-label="listening ? 'Идёт запись' : 'Голосовой ввод'"
                    @click="startVoice"
                ) ●
                button.assistant-send(type="submit" :disabled="!input.trim() || pending" aria-label="Отправить") ↑
            p.assistant-disclaimer AI может ошибаться. Проверяйте характеристики товара.

    button.assistant-launcher(
        type="button"
        :class="{ open }"
        :aria-expanded="open"
        aria-label="Открыть AI-ассистента"
        @click="toggle"
    )
        span(v-if="open") ×
        template(v-else)
            b AI
            span Спросить помощника
</template>
