export default defineNuxtConfig({
    ssr: true,
    compatibilityDate: '2026-05-29',
    telemetry: false,
    css: ['~/assets/css/main.styl'],
    runtimeConfig: {
        apiBase: process.env.NUXT_API_BASE || process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8080',
        public: {
            apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8080',
        },
    },
    app: {
        head: {
            title: 'StockFlow Market',
            meta: [
                {
                    name: 'description',
                    content: 'Операционный интерфейс StockFlow Market для каталога, заказов и поиска.',
                },
            ],
        },
    },
});
