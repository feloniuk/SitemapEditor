!function(e){var t={};function n(i){if(t[i])return t[i].exports;var a=t[i]={i:i,l:!1,exports:{}};return e[i].call(a.exports,a,a.exports,n),a.l=!0,a.exports}n.m=e,n.c=t,n.d=function(e,t,i){n.o(e,t)||Object.defineProperty(e,t,{enumerable:!0,get:i})},n.r=function(e){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},n.t=function(e,t){if(1&t&&(e=n(e)),8&t)return e;if(4&t&&"object"==typeof e&&e&&e.__esModule)return e;var i=Object.create(null);if(n.r(i),Object.defineProperty(i,"default",{enumerable:!0,value:e}),2&t&&"string"!=typeof e)for(var a in e)n.d(i,a,function(t){return e[t]}.bind(null,a));return i},n.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return n.d(t,"a",t),t},n.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},n.p=(window.__sw__.assetPath + '/bundles/sitemapeditor/'),n(n.s="wI8S")}({wI8S:function(e,t,n){"use strict";n.r(t);var i=Shopware,a=i.Component,s=i.Data.Criteria;a.register("sw-sitemap-url-list",{template:'{% block sw_sitemap_url_list %}\n<sw-page class="sw-sitemap-url-list">\n    <template slot="smart-bar-header">\n        <h2>{{ $tc(\'sitemap-editor.general.mainMenuItemGeneral\') }}</h2>\n    </template>\n\n    <template slot="content">\n        <sw-card-view>\n            <sw-card v-if="!isLoading || salesChannels.length" :isLoading="isLoading" :title="$tc(\'sitemap-editor.list.cardTitle\')">\n                <div class="sw-sitemap-url-list__toolbar">\n                    <sw-container columns="1fr 1fr" gap="0px 30px">\n                        <div>\n                            <sw-entity-single-select\n                                :label="$tc(\'sitemap-editor.list.salesChannelLabel\')"\n                                entity="sales_channel"\n                                v-model="selectedSalesChannelId"\n                                @change="onSalesChannelChange">\n                            </sw-entity-single-select>\n                        </div>\n                        <div>\n                            <sw-select-field\n                                :label="$tc(\'sitemap-editor.list.typeLabel\')"\n                                v-model="selectedType"\n                                @change="onTypeChange">\n                                <option v-for="option in typeOptions" :key="option.value" :value="option.value">\n                                    {{ option.label }}\n                                </option>\n                            </sw-select-field>\n                        </div>\n                    </sw-container>\n                </div>\n\n                <div class="sw-sitemap-url-list__search">\n                    <sw-simple-search-field\n                        v-model="searchTerm"\n                        @search-term-change="onSearch"\n                        :placeholder="$tc(\'sitemap-editor.list.searchPlaceholder\')">\n                    </sw-simple-search-field>\n                </div>\n\n                <sw-data-grid\n                    :dataSource="filteredUrls"\n                    :columns="[\n                        { property: \'loc\', label: \'URL\', rawData: true },\n                        { property: \'type\', label: \'Type\', rawData: true },\n                        { property: \'changefreq\', label: \'Change Frequency\', rawData: true },\n                        { property: \'priority\', label: \'Priority\', rawData: true },\n                        { property: \'lastmod\', label: \'Last Modified\', rawData: true }\n                    ]"\n                    :showSelection="false"\n                    :showActions="false"\n                    :isLoading="isLoading">\n                    <template #column-loc="{ item }">\n                        <a :href="item.loc" target="_blank">{{ item.loc }}</a>\n                    </template>\n                </sw-data-grid>\n\n                <div v-if="totalUrls > limit" class="sw-sitemap-url-list__info">\n                    {{ $tc(\'sitemap-editor.list.limitInfo\', 0, { shown: limit, total: totalUrls }) }}\n                </div>\n            </sw-card>\n\n            {% if salesChannel.customFields.sitemap_editor_url_list %}\n                <h2>Sitemap URLs ({{ salesChannel.customFields.sitemap_editor_url_count }})</h2>\n                <p>Last updated: {{ salesChannel.customFields.sitemap_editor_last_updated|date }}</p>\n                \n                {% set urls = salesChannel.customFields.sitemap_editor_url_list|json_decode %}\n                <ul>\n                    {% for url in urls %}\n                        <li>\n                            <a href="{{ url.loc }}" target="_blank">{{ url.loc }}</a>\n                            {% if url.lastmod %}<small>(Last modified: {{ url.lastmod }})</small>{% endif %}\n                        </li>\n                    {% endfor %}\n                </ul>\n            {% endif %}\n        </sw-card-view>\n    </template>\n</sw-page>\n{% endblock %}',inject:["repositoryFactory","syncService","httpClient"],data:function(){return{isLoading:!0,salesChannels:[],selectedSalesChannelId:null,urls:[],totalUrls:0,limit:100,searchTerm:"",selectedType:"all",typeOptions:[{value:"all",label:"All Types"},{value:"product",label:"Products"},{value:"category",label:"Categories"},{value:"landing_page",label:"Landing Pages"}]}},computed:{salesChannelRepository:function(){return this.repositoryFactory.create("sales_channel")},salesChannelCriteria:function(){var e=new s;return e.addAssociation("domains"),e},filteredUrls:function(){if(!this.searchTerm)return this.urls;var e=this.searchTerm.toLowerCase();return this.urls.filter((function(t){return t.loc.toLowerCase().includes(e)||t.identifier&&t.identifier.toLowerCase().includes(e)||t.type.toLowerCase().includes(e)}))}},created:function(){this.createdComponent()},methods:{createdComponent:function(){var e=this;this.isLoading=!0,this.salesChannelRepository.search(this.salesChannelCriteria).then((function(t){e.salesChannels=t,t.length>0?(e.selectedSalesChannelId=t[0].id,e.fetchSitemapUrls()):e.isLoading=!1})).catch((function(t){e.isLoading=!1,e.createNotificationError({title:"Error",message:t.message||"An error occurred while loading sales channels"})}))},onSalesChannelChange:function(){this.selectedSalesChannelId&&this.fetchSitemapUrls()},onTypeChange:function(){this.fetchSitemapUrls()},fetchSitemapUrls:function(){var e=this;this.isLoading=!0,this.urls=[];"all"!==this.selectedType&&this.selectedType;var t="_action/sitemap-editor/urls/".concat(this.selectedSalesChannelId,"?limit=").concat(this.limit);this.httpClient.get(t,{headers:this.syncService.getHeaders(),params:{type:this.selectedType}}).then((function(t){e.urls=t.data.urls||[],e.totalUrls=t.data.total||0,e.isLoading=!1})).catch((function(t){var n,i;e.isLoading=!1,e.createNotificationError({title:"Error",message:(null===(n=t.response)||void 0===n||null===(i=n.data)||void 0===i?void 0:i.error)||"An error occurred while loading sitemap URLs"})}))},onSearch:function(){}}}),Shopware.Module.register("sw-sitemap-editor",{type:"plugin",name:"sitemap-editor.general.mainMenuItemGeneral",title:"sitemap-editor.general.mainMenuItemGeneral",description:"sitemap-editor.general.description",version:"1.0.0",targetVersion:"1.0.0",color:"#9AA8B5",icon:"default-action-settings",routes:{index:{component:"sw-sitemap-url-list",path:"index",meta:{parentPath:"sw.settings.index"}}},settingsItem:{group:"shop",to:"sw.sitemap-editor.index",icon:"default-action-settings",privilege:"system.system_config"}})}});
//# sourceMappingURL=sitemap-editor.js.map