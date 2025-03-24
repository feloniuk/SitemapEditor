<?php declare(strict_types=1);

namespace SitemapEditor\Core\Content\Sitemap\Provider;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SitemapUrlEditorProvider extends AbstractUrlProvider
{
    private AbstractUrlProvider $decoratedUrlProvider;
    private Connection $connection;
    private SystemConfigService $systemConfigService;

    public function __construct(
        AbstractUrlProvider $decoratedUrlProvider,
        Connection $connection,
        SystemConfigService $systemConfigService
    ) {
        $this->decoratedUrlProvider = $decoratedUrlProvider;
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
    }

    public function getDecorated(): AbstractUrlProvider
    {
        return $this->decoratedUrlProvider;
    }

    public function getName(): string
    {
        return $this->getDecorated()->getName();
    }

    /**
     * Get URLs from the original provider and modify them based on configuration
     */
    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        try {
            // Get the original URLs
            $urlResult = $this->getDecorated()->getUrls($context, $limit, $offset);
            $urls = $urlResult->getUrls();
            
            // If modification is not enabled for this sales channel, return the original URLs
            $modifyProductUrls = $this->getConfig('modifyProductUrls', $context->getSalesChannelId());
            if (!$modifyProductUrls) {
                return $urlResult;
            }
            
            // Get excluded product numbers from configuration
            $excludedProductNumbers = $this->getExcludedProductNumbers($context->getSalesChannelId());
            
            // Get excluded product IDs by product numbers
            $excludedProductIds = [];
            if (!empty($excludedProductNumbers)) {
                $excludedProductIds = $this->getProductIdsByProductNumbers($excludedProductNumbers);
            }
            
            // Check if we should exclude out of stock products
            $excludeOutOfStock = $this->getConfig('excludeOutOfStockProducts', $context->getSalesChannelId());
            $outOfStockProductIds = [];
            if ($excludeOutOfStock) {
                $outOfStockProductIds = $this->getOutOfStockProductIds();
            }
            
            // Get custom change frequency and priority from configuration
            $changeFreq = $this->getConfig('productChangeFrequency', $context->getSalesChannelId());
            $priority = (float) $this->getConfig('productPriority', $context->getSalesChannelId());
            
            // Filter and modify URLs
            $filteredUrls = [];
            foreach ($urls as $url) {
                // Skip excluded products
                if (in_array($url->getIdentifier(), $excludedProductIds)) {
                    continue;
                }
                
                // Skip out of stock products if configured
                if ($excludeOutOfStock && in_array($url->getIdentifier(), $outOfStockProductIds)) {
                    continue;
                }
                
                // Modify change frequency and priority if this is a product URL
                if ($url->getResource() === 'product') {
                    $url->setChangefreq($changeFreq);
                    $url->setPriority($priority);
                }
                
                $filteredUrls[] = $url;
            }

            // print_r('<pre>');
            // var_dump($filteredUrls);
            // print_r('</pre>');

            return new UrlResult($filteredUrls, $urlResult->getNextOffset());
        } catch (\Exception $e) {
            // Log the error
            // Return an empty result
            return new UrlResult([], null);
        }
    }
    
    /**
     * Get system configuration value
     */
    private function getConfig(string $key, string $salesChannelId): mixed
    {
        return $this->systemConfigService->get(
            'SitemapEditor.config.' . $key,
            $salesChannelId
        );
    }
    
    /**
     * Get excluded product numbers from configuration and split them into an array
     */
    private function getExcludedProductNumbers(string $salesChannelId): array
    {
        $excludedNumbersString = $this->getConfig('excludedProductNumbers', $salesChannelId);
        if (empty($excludedNumbersString)) {
            return [];
        }
        
        $excludedNumbers = explode(',', $excludedNumbersString);
        return array_map('trim', $excludedNumbers);
    }
    
    /**
     * Get product IDs by product numbers
     */
    private function getProductIdsByProductNumbers(array $productNumbers): array
    {
        if (empty($productNumbers)) {
            return [];
        }
        
        $result = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) as id FROM product WHERE product_number IN (:productNumbers)',
            ['productNumbers' => $productNumbers],
            ['productNumbers' => Connection::PARAM_STR_ARRAY]
        );
        
        return array_column($result, 'id');
    }
    
    /**
     * Get IDs of products that are out of stock
     */
    private function getOutOfStockProductIds(): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) as id FROM product WHERE available_stock <= 0 OR available = 0'
        );
        
        return array_column($result, 'id');
    }
    
    /**
     * Override the getSeoUrls method to implement advanced filtering at the database level
     */
    protected function getSeoUrls(array $ids, string $routeName, SalesChannelContext $context, Connection $connection): array
    {
        // If we need to exclude out of stock products at the database level
        $excludeOutOfStock = $this->getConfig('excludeOutOfStockProducts', $context->getSalesChannelId());
        
        if ($routeName === 'frontend.detail.page' && $excludeOutOfStock) {
            // Add a JOIN to filter out products that are out of stock
            $sql = 'SELECT LOWER(HEX(seo_url.foreign_key)) as foreign_key, seo_url.seo_path_info
                    FROM seo_url
                    INNER JOIN product ON product.id = seo_url.foreign_key
                    WHERE seo_url.foreign_key IN (:ids)
                    AND seo_url.route_name = :routeName
                    AND seo_url.is_canonical = 1
                    AND seo_url.is_deleted = 0
                    AND seo_url.language_id = :languageId
                    AND (seo_url.sales_channel_id = :salesChannelId OR seo_url.sales_channel_id IS NULL)
                    AND product.available_stock > 0
                    AND product.available = 1';
        } else {
            // Standard query
            $sql = 'SELECT LOWER(HEX(foreign_key)) as foreign_key, seo_path_info
                    FROM seo_url WHERE foreign_key IN (:ids)
                    AND route_name = :routeName
                    AND is_canonical = 1
                    AND is_deleted = 0
                    AND language_id = :languageId
                    AND (sales_channel_id = :salesChannelId OR sales_channel_id IS NULL)';
        }
                
        return $connection->fetchAllAssociative(
            $sql,
            [
                'routeName' => $routeName,
                'languageId' => Uuid::fromHexToBytes($context->getSalesChannel()->getLanguageId()),
                'salesChannelId' => Uuid::fromHexToBytes($context->getSalesChannelId()),
                'ids' => Uuid::fromHexToBytesList(array_values($ids)),
            ],
            [
                'ids' => Connection::PARAM_STR_ARRAY,
            ]
        );
    }
}