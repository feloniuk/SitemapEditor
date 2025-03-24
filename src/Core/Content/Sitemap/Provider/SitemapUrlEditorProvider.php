<?php declare(strict_types=1);

namespace SitemapEditor\Core\Content\Sitemap\Provider;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;

class SitemapUrlEditorProvider extends AbstractUrlProvider
{
    private AbstractUrlProvider $decoratedUrlProvider;
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        AbstractUrlProvider $decoratedUrlProvider,
        Connection $connection,
        SystemConfigService $systemConfigService,
        ?LoggerInterface $logger = null
    ) {
        $this->decoratedUrlProvider = $decoratedUrlProvider;
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
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
            // Log that this method was called
            $this->logger->info('SitemapUrlEditorProvider::getUrls called', [
                'salesChannelId' => $context->getSalesChannelId(),
                'limit' => $limit,
                'offset' => $offset
            ]);
            
            // Get the original URLs
            $urlResult = $this->getDecorated()->getUrls($context, $limit, $offset);
            $urls = $urlResult->getUrls();
            
            // If modification is not enabled for this sales channel, return the original URLs
            $modifyProductUrls = $this->getConfig('modifyProductUrls', $context->getSalesChannelId());
            $this->logger->info('Configuration check', [
                'modifyProductUrls' => $modifyProductUrls,
                'salesChannelId' => $context->getSalesChannelId()
            ]);
            
            if (!$modifyProductUrls) {
                $this->logger->info('URL modification disabled, returning original URLs');
                return $urlResult;
            }
            
            // Get excluded product numbers from configuration
            $excludedProductNumbers = $this->getExcludedProductNumbers($context->getSalesChannelId());
            
            // Get excluded product IDs by product numbers
            $excludedProductIds = [];
            if (!empty($excludedProductNumbers)) {
                $excludedProductIds = $this->getProductIdsByProductNumbers($excludedProductNumbers);
                $this->logger->info('Excluded product IDs', ['excludedProductIds' => $excludedProductIds]);
            }
            
            // Check if we should exclude out of stock products
            $excludeOutOfStock = $this->getConfig('excludeOutOfStockProducts', $context->getSalesChannelId());
            $outOfStockProductIds = [];
            if ($excludeOutOfStock) {
                $outOfStockProductIds = $this->getOutOfStockProductIds();
                $this->logger->info('Out of stock product IDs', [
                    'count' => count($outOfStockProductIds),
                    'first10' => array_slice($outOfStockProductIds, 0, 10)
                ]);
            }
            
            // Get custom change frequency and priority from configuration
            $changeFreq = $this->getConfig('productChangeFrequency', $context->getSalesChannelId());
            $priority = (float) $this->getConfig('productPriority', $context->getSalesChannelId());
            
            $this->logger->info('URL modification settings', [
                'changeFreq' => $changeFreq,
                'priority' => $priority
            ]);
            
            // Filter and modify URLs
            $filteredUrls = [];
            $excludedCount = 0;
            foreach ($urls as $url) {
                // Skip excluded products
                if (in_array($url->getIdentifier(), $excludedProductIds)) {
                    $excludedCount++;
                    continue;
                }
                
                // Skip out of stock products if configured
                if ($excludeOutOfStock && in_array($url->getIdentifier(), $outOfStockProductIds)) {
                    $excludedCount++;
                    continue;
                }
                
                // Modify change frequency and priority if this is a product URL
                if ($url->getResource() === 'product') {
                    $url->setChangefreq($changeFreq);
                    $url->setPriority($priority);
                }
                
                $filteredUrls[] = $url;
            }
            
            $this->logger->info('URL filtering results', [
                'originalCount' => count($urls),
                'filteredCount' => count($filteredUrls),
                'excludedCount' => $excludedCount
            ]);

            return new UrlResult($filteredUrls, $urlResult->getNextOffset());
        } catch (\Exception $e) {
            $this->logger->error('Error in SitemapUrlEditorProvider', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return the original result if possible, otherwise empty result
            return isset($urlResult) ? $urlResult : new UrlResult([], null);
        }
    }
    
    /**
     * Get system configuration value
     */
    private function getConfig(string $key, string $salesChannelId): mixed
    {
        $value = $this->systemConfigService->get(
            'SitemapEditor.config.' . $key,
            $salesChannelId
        );
        
        $this->logger->debug('Config retrieved', [
            'key' => $key,
            'salesChannelId' => $salesChannelId,
            'value' => $value
        ]);
        
        return $value;
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
        $result = array_map('trim', $excludedNumbers);
        
        $this->logger->debug('Excluded product numbers', [
            'salesChannelId' => $salesChannelId,
            'numbers' => $result
        ]);
        
        return $result;
    }
    
    /**
     * Get product IDs by product numbers
     */
    private function getProductIdsByProductNumbers(array $productNumbers): array
    {
        if (empty($productNumbers)) {
            return [];
        }
        
        try {
            $result = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) as id FROM product WHERE product_number IN (:productNumbers)',
                ['productNumbers' => $productNumbers],
                ['productNumbers' => Connection::PARAM_STR_ARRAY]
            );
            
            return array_column($result, 'id');
        } catch (\Exception $e) {
            $this->logger->error('Error fetching product IDs by numbers', [
                'error' => $e->getMessage(),
                'productNumbers' => $productNumbers
            ]);
            return [];
        }
    }
    
    /**
     * Get IDs of products that are out of stock
     */
    private function getOutOfStockProductIds(): array
    {
        try {
            $result = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) as id FROM product WHERE available_stock <= 0 OR available = 0'
            );
            
            return array_column($result, 'id');
        } catch (\Exception $e) {
            $this->logger->error('Error fetching out of stock product IDs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Override the getSeoUrls method to implement advanced filtering at the database level
     */
    protected function getSeoUrls(array $ids, string $routeName, SalesChannelContext $context, Connection $connection): array
    {
        $this->logger->debug('getSeoUrls called', [
            'routeName' => $routeName,
            'idCount' => count($ids),
            'salesChannelId' => $context->getSalesChannelId()
        ]);
        
        // If we need to exclude out of stock products at the database level
        $excludeOutOfStock = $this->getConfig('excludeOutOfStockProducts', $context->getSalesChannelId());
        
        try {
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
                    
            $result = $connection->fetchAllAssociative(
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
            
            $this->logger->debug('getSeoUrls result', [
                'resultCount' => count($result)
            ]);
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Error in getSeoUrls', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return parent::getSeoUrls($ids, $routeName, $context, $connection);
        }
    }
}