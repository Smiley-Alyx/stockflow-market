import type { CatalogProduct } from '~/composables/useCatalogApi';

type ApiResource<T> = {
    data: T;
};

export type OpenAiAssistantResponse = {
    message: string;
    response_id: string | null;
    products: CatalogProduct[];
};

export const useAssistantApi = () => {
    const config = useRuntimeConfig();
    const apiBase = config.public.apiBase;

    const askOpenAi = async (message: string, previousResponseId: string | null) => {
        const csrf = await $fetch<{ csrf_token: string }>('/api/session/csrf', {
            baseURL: apiBase,
            credentials: 'include',
        });
        const response = await $fetch<ApiResource<OpenAiAssistantResponse>>('/api/assistant/openai', {
            baseURL: apiBase,
            method: 'POST',
            credentials: 'include',
            headers: {
                'X-CSRF-TOKEN': csrf.csrf_token,
            },
            body: {
                message,
                previous_response_id: previousResponseId,
            },
        });

        return response.data;
    };

    return {
        askOpenAi,
    };
};
