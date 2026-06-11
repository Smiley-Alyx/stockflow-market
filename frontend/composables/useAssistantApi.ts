import type { CatalogProduct } from '~/composables/useCatalogApi';

type ApiResource<T> = {
    data: T;
};

export type AssistantResponse = {
    message: string;
    conversation_id: string | null;
    products: CatalogProduct[];
    provider: {
        code: string;
        name: string;
    };
};

export const useAssistantApi = () => {
    const config = useRuntimeConfig();
    const apiBase = config.public.apiBase;

    const ask = async (message: string, conversationId: string | null) => {
        const csrf = await $fetch<{ csrf_token: string }>('/api/session/csrf', {
            baseURL: apiBase,
            credentials: 'include',
        });
        const response = await $fetch<ApiResource<AssistantResponse>>('/api/assistant/respond', {
            baseURL: apiBase,
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': csrf.csrf_token,
            },
            body: {
                message,
                conversation_id: conversationId,
            },
        });

        return response.data;
    };

    return {
        ask,
    };
};
