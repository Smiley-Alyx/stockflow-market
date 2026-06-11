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
        const response = await $fetch<ApiResource<OpenAiAssistantResponse>>('/api/assistant/openai', {
            baseURL: apiBase,
            method: 'POST',
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
