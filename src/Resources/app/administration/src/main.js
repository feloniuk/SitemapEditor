const { Component, Data } = Shopware;
const { Criteria } = Data;

import template from './sitemap-url-list.html.twig';

Component.register('sw-sitemap-url-list', {
    template,

    inject: [
        'repositoryFactory',
        'syncService',
        'httpClient'
    ],

    data() {
        return {
            isLoading: true,
            salesChannels: [],
            selectedSalesChannelId: null,
            urls: [],
            totalUrls: 0,
            limit: 100,
            searchTerm: '',
            selectedType: 'all',
            typeOptions: [
                { value: 'all', label: 'All Types' },
                { value: 'product', label: 'Products' },
                { value: 'category', label: 'Categories' },
                { value: 'landing_page', label: 'Landing Pages' }
            ]
        };
    },

    computed: {
        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        salesChannelCriteria() {
            const criteria = new Criteria();
            criteria.addAssociation('domains');
            return criteria;
        },

        filteredUrls() {
            if (!this.searchTerm) {
                return this.urls;
            }

            const searchTerm = this.searchTerm.toLowerCase();
            return this.urls.filter(url => {
                return url.loc.toLowerCase().includes(searchTerm) ||
                       (url.identifier && url.identifier.toLowerCase().includes(searchTerm)) ||
                       url.type.toLowerCase().includes(searchTerm);
            });
        }
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.isLoading = true;

            // Получаем список всех sales channels
            this.salesChannelRepository.search(this.salesChannelCriteria)
                .then((result) => {
                    this.salesChannels = result;

                    if (result.length > 0) {
                        // По умолчанию выбираем первый sales channel
                        this.selectedSalesChannelId = result[0].id;
                        this.fetchSitemapUrls();
                    } else {
                        this.isLoading = false;
                    }
                })
                .catch((error) => {
                    this.isLoading = false;
                    this.createNotificationError({
                        title: 'Error',
                        message: error.message || 'An error occurred while loading sales channels'
                    });
                });
        },

        onSalesChannelChange() {
            if (this.selectedSalesChannelId) {
                this.fetchSitemapUrls();
            }
        },

        onTypeChange() {
            this.fetchSitemapUrls();
        },

        fetchSitemapUrls() {
            this.isLoading = true;
            this.urls = [];

            let types = '';
            if (this.selectedType !== 'all') {
                types = this.selectedType;
            }

            const apiUrl = `_action/sitemap-editor/urls/${this.selectedSalesChannelId}?limit=${this.limit}`;
            
            this.httpClient.get(
                apiUrl,
                {
                    headers: this.syncService.getHeaders(),
                    params: {
                        type: this.selectedType
                    }
                }
            ).then((response) => {
                this.urls = response.data.urls || [];
                this.totalUrls = response.data.total || 0;
                this.isLoading = false;
            }).catch((error) => {
                this.isLoading = false;
                this.createNotificationError({
                    title: 'Error',
                    message: error.response?.data?.error || 'An error occurred while loading sitemap URLs'
                });
            });
        },

        onSearch() {
            // Фильтрация происходит в computed свойстве filteredUrls
        }
    }
});

// Регистрация модуля
Shopware.Module.register('sw-sitemap-editor', {
    type: 'plugin',
    name: 'sitemap-editor.general.mainMenuItemGeneral',
    title: 'sitemap-editor.general.mainMenuItemGeneral',
    description: 'sitemap-editor.general.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#9AA8B5',
    icon: 'default-action-settings',

    routes: {
        index: {
            component: 'sw-sitemap-url-list',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },

    settingsItem: {
        group: 'shop',
        to: 'sw.sitemap-editor.index',
        icon: 'default-action-settings',
        privilege: 'system.system_config'
    }
});