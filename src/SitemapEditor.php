<?php declare(strict_types=1);

namespace SitemapEditor;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SitemapEditor extends Plugin
{
    /**
     * Группы кастомфилдов
     */
    private $groups = [
        'sitemap_editor_urls'
    ];

    /**
     * Кастомфилды для плагина
     */
    private $fields = [
        [
            'name' => 'sitemap_editor_urls',
            'global' => true,
            'config' => [
                'label' => [
                    'en-GB' => 'Sitemap URLs',
                    'de-DE' => 'Sitemap URLs',
                    'ru-RU' => 'URL карты сайта'
                ]
            ],
            'relations' => [
                [
                    'entityName' => 'sales_channel'
                ]
            ],
            'customFields' => [
                [
                    'name' => 'sitemap_editor_url_list',
                    'type' => CustomFieldTypes::JSON,
                    'config' => [
                        'label' => [
                            'en-GB' => 'URL List',
                            'de-DE' => 'URL-Liste',
                            'ru-RU' => 'Список URL'
                        ],
                        'componentName' => 'sw-code-editor',
                        'customFieldType' => 'textEditor',
                        'customFieldPosition' => 0,
                    ]
                ],
                [
                    'name' => 'sitemap_editor_last_updated',
                    'type' => CustomFieldTypes::DATETIME,
                    'config' => [
                        'label' => [
                            'en-GB' => 'Last Update',
                            'de-DE' => 'Letzte Aktualisierung',
                            'ru-RU' => 'Последнее обновление'
                        ],
                        'type' => 'date',
                        'dateType' => 'datetime',
                        'customFieldType' => 'date',
                        'customFieldPosition' => 1,
                    ]
                ],
                [
                    'name' => 'sitemap_editor_url_count',
                    'type' => CustomFieldTypes::INT,
                    'config' => [
                        'label' => [
                            'en-GB' => 'URL Count',
                            'de-DE' => 'URL-Anzahl',
                            'ru-RU' => 'Количество URL'
                        ],
                        'componentName' => 'sw-field',
                        'customFieldType' => 'number',
                        'customFieldPosition' => 2,
                        'numberType' => 'int'
                    ]
                ],
            ]
        ]
    ];

    /**
     * Удаление кастомфилдов
     */
    private function removeFields($context): void
    {
        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = (new Criteria())->addFilter(new EqualsAnyFilter('name', $this->groups));
        $results = $customFieldSetRepository->search($criteria, $context->getContext())->getEntities();

        $ids = [];

        foreach ($results as $result) {
            $id = ['id' => $result->get('id')];
            array_push($ids, $id);
        }

        if (!empty($ids)) {
            $customFieldSetRepository->delete($ids, $context->getContext());
        }
    }

    /**
     * Добавление кастомфилдов
     */
    private function addFields($context): void
    {
        /** @var EntityRepositoryInterface $customFieldSetRepository */
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $customFieldsGroups = $this->getAllCustomFieldsGroups();
        $addedCustomFieldsGroups = [];
        
        foreach ($customFieldsGroups as $group) {
            if (!$this->customFieldGroupIsExist($group['name'], $context)) {
                array_push($addedCustomFieldsGroups, $group);
            }
        }

        if ($addedCustomFieldsGroups) {
            $customFieldSetRepository->create($addedCustomFieldsGroups, $context->getContext());
        }
    }

    /**
     * Проверка существования группы кастомфилдов
     */
    private function customFieldGroupIsExist(string $name, $context)
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', $name));
        $results = $customFieldSetRepository->search($criteria, $context->getContext())->getEntities()->first();

        return $results;
    }

    /**
     * Получение всех групп кастомфилдов
     */
    private function getAllCustomFieldsGroups()
    {
        return $this->fields;
    }

    /**
     * При установке плагина
     */
    public function install(InstallContext $context): void
    {
        $this->addFields($context);
        
        parent::install($context);
    }

    /**
     * При активации плагина
     */
    public function activate(ActivateContext $context): void
    {
        $this->addFields($context);

        parent::activate($context);
    }

    /**
     * При обновлении плагина
     */
    public function update(UpdateContext $context): void
    {
        $this->removeFields($context);
        $this->addFields($context);

        parent::update($context);
    }

    /**
     * При деактивации плагина
     */
    public function deactivate(DeactivateContext $context): void
    {
        // Не удаляем кастомфилды при деактивации, чтобы сохранить данные
        
        parent::deactivate($context);
    }

    /**
     * При удалении плагина
     */
    public function uninstall(UninstallContext $context): void
    {
        // Удаляем кастомфилды только если выбрано не сохранять данные
        if (!$context->keepUserData()) {
            $this->removeFields($context);
        }
        

        parent::uninstall($context);
    }

    /**
     * Регистрация ресурсов администратора
     */
    public function getAdministrationEntryPath(): string
    {
        return 'Resources/app/administration/src/main.js';
    }

    /**
     * Сборка контейнера
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}