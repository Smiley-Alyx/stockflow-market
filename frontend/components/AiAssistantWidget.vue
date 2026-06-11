<script setup lang="ts">
import type { CatalogProduct } from '~/composables/useCatalogApi';

type AssistantMessage = {
    id: number;
    role: 'assistant' | 'user';
    text: string;
    products?: CatalogProduct[];
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

const assistantApi = useAssistantApi();
const customer = useCustomerState();
const open = ref(false);
const input = ref('');
const pending = ref(false);
const listening = ref(false);
const voiceAvailable = ref(false);
const conversationId = ref<string | null>(null);
const providerName = ref('AI-провайдер');
const messageList = ref<HTMLElement | null>(null);
const recognition = shallowRef<SpeechRecognitionInstance | null>(null);
const messages = ref<AssistantMessage[]>(initialMessages());

const prompts = [
    'Подбери подарок коллеге, который любит музыку',
    'Что лучше взять для домашнего офиса?',
    'Помоги выбрать товар и объясни компромиссы',
];

watch(
    () => messages.value.length,
    async () => {
        await nextTick();
        messageList.value?.scrollTo({ top: messageList.value.scrollHeight, behavior: 'smooth' });
    },
);

const reset = () => {
    conversationId.value = null;
    providerName.value = 'AI-провайдер';
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
        const response = await assistantApi.ask(text, conversationId.value);
        conversationId.value = response.conversation_id;
        providerName.value = response.provider.name;
        messages.value.push({
            id: Date.now() + 1,
            role: 'assistant',
            text: response.message,
            products: response.products,
        });
    } catch (error) {
        messages.value.push({
            id: Date.now() + 1,
            role: 'assistant',
            text: errorMessage(error),
        });
    } finally {
        pending.value = false;
    }
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
            text: 'Здравствуйте! Я AI-консультант. Опишите задачу, а я уточню детали, найду товары по всему каталогу и объясню выбор.',
        },
    ];
}

function errorMessage(error: unknown): string {
    if (typeof error === 'object' && error !== null && 'data' in error) {
        const data = (error as { data?: { message?: unknown } }).data;

        if (typeof data?.message === 'string') {
            return data.message;
        }
    }

    return 'Не удалось получить ответ AI-провайдера. Проверьте его настройки и доступность.';
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
.assistant-layer.provider-assistant-layer
    transition(name="assistant-panel")
        section.assistant-panel.provider-assistant-panel(v-if="open" aria-label="AI-ассистент магазина")
            header.assistant-header.provider-assistant-header
                .assistant-identity
                    span.assistant-avatar.provider-assistant-avatar AI+
                    span
                        strong AI-консультант
                        small
                            i
                            | {{ providerName }}
                .assistant-header-actions
                    button(type="button" aria-label="Начать заново" title="Начать заново" @click="reset") ↺
                    button(type="button" aria-label="Закрыть помощника" @click="open = false") ×

            .assistant-messages(ref="messageList" aria-live="polite")
                article.assistant-message.provider-assistant-message(
                    v-for="message in messages"
                    :key="message.id"
                    :class="[`is-${message.role}`, { 'has-products': message.products?.length }]"
                )
                    AssistantRichText(:text="message.text")
                    .assistant-products(v-if="message.products?.length")
                        article.assistant-product(v-for="product in message.products.slice(0, 5)" :key="product.id")
                            NuxtLink.assistant-product-image(:to="product.url ?? '/catalog/'" @click="open = false")
                                img(v-if="product.image_url" :src="product.image_url" :alt="product.name")
                                span(v-else) SF
                            .assistant-product-copy
                                small {{ product.category?.name ?? 'Каталог' }}
                                NuxtLink(:to="product.url ?? '/catalog/'" @click="open = false") {{ product.name }}
                                .assistant-product-meta
                                    strong {{ formatMoney(product) }}
                                    span(v-if="product.rating") ★ {{ product.rating.toFixed(1) }}
                                button(
                                    type="button"
                                    :disabled="!product.availability.in_stock"
                                    @click="customer.addCartItem(product)"
                                ) {{ product.availability.in_stock ? 'В корзину' : 'Нет в наличии' }}

                article.assistant-message.is-assistant.assistant-typing(v-if="pending")
                    span
                    span
                    span

            .assistant-suggestions(v-if="messages.length === 1 && !pending")
                button(v-for="prompt in prompts" :key="prompt" type="button" @click="submit(prompt)") {{ prompt }}

            form.assistant-input(@submit.prevent="submit()")
                textarea(
                    v-model="input"
                    rows="1"
                    placeholder="Спросите о сложном выборе…"
                    aria-label="Сообщение AI-ассистенту"
                    @keydown.enter.exact.prevent="submit()"
                )
                button.assistant-voice(
                    v-if="voiceAvailable"
                    type="button"
                    :class="{ listening }"
                    :aria-label="listening ? 'Идёт запись' : 'Голосовой ввод'"
                    @click="startVoice"
                ) ●
                button.assistant-send.provider-assistant-send(
                    type="submit"
                    :disabled="!input.trim() || pending"
                    aria-label="Отправить"
                ) ↑
            p.assistant-disclaimer Ответ формирует выбранный AI-провайдер на основе актуального каталога.

    button.assistant-launcher.provider-assistant-launcher(
        type="button"
        :class="{ open }"
        :aria-expanded="open"
        aria-label="Открыть AI-ассистента"
        @click="open = !open"
    )
        span(v-if="open") ×
        template(v-else)
            b AI+
            span AI-консультант
</template>
