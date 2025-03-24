<?php declare(strict_types=1);

namespace SitemapManager\Core\Content\Sitemap\Provider;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Provider\ProductUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository; // Change this import
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;

class ProductSitemapUrlProvider extends AbstractUrlProvider
{
    private ProductUrlProvider $decorated;
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private EntityRepository $productRepository; // Change the type hint here
    private LoggerInterface $logger;

    public function __construct(
        ProductUrlProvider $decorated,
        Connection $connection,
        SystemConfigService $systemConfigService,
        EntityRepository $productRepository, // Change the type hint here
        LoggerInterface $logger
    ) {
        $this->decorated = $decorated;
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    // Rest of the class remains the same
    public function getDecorated(): AbstractUrlProvider
    {
        return $this->decorated;
    }

    public function getName(): string
    {
        return 'product';
    }

    /**
     * Get URLs from the original provider and modify them based on configuration
     */
    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        try {
            $this->logger->debug('ProductSitemapUrlProvider::getUrls called', [
                'salesChannelId' => $context->getSalesChannelId(),
                'limit' => $limit,
                'offset' => $offset
            ]);
            
            // If modification is not enabled, return original URLs
            $modifyProductUrls = $this->getConfig('modifyProductUrls', $context->getSalesChannelId());
            
            if (!$modifyProductUrls) {
                $this->logger->debug('Product URL modification disabled, returning original URLs');
                return $this->getDecorated()->getUrls($context, $limit, $offset);
            }
            
            // Get original URLs
            $urlResult = $this->getDecorated()->getUrls($context, $limit, $offset);
            $originalUrls = $urlResult->getUrls();
            
            $this->logger->debug('Original product URLs retrieved', [
                'count' => count($originalUrls)
            ]);
            
            // Get excluded product numbers
            $excludedProductNumbers = $this->getExcludedProductNumbers($context->getSalesChannelId());
            
            // Get product IDs from product numbers
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
            
            // Get change frequency and priority from configuration
            $changeFreq = $this->getConfig('productChangeFrequency', $context->getSalesChannelId());
            $priority = (float) $this->getConfig('productPriority', $context->getSalesChannelId());
            
            // Filter and modify URLs
            $modifiedUrls = [];
            foreach ($originalUrls as $url) {
                // Skip excluded products
                if (in_array($url->getIdentifier(), $excludedProductIds)) {
                    continue;
                }
                
                // Skip out of stock products if configured
                if ($excludeOutOfStock && in_array($url->getIdentifier(), $outOfStockProductIds)) {
                    continue;
                }
                
                // Modify change frequency and priority
                if (!empty($changeFreq)) {
                    $url->setChangefreq($changeFreq);
                }
                
                if ($priority > 0) {
                    $url->setPriority($priority);
                }
                
                $modifiedUrls[] = $url;
            }
            
            $this->logger->debug('Product URLs modified', [
                'originalCount' => count($originalUrls),
                'modifiedCount' => count($modifiedUrls)
            ]);
            
            return new UrlResult($modifiedUrls, $urlResult->getNextOffset());
        } catch (\Exception $e) {
            $this->logger->error('Error in ProductSitemapUrlProvider', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return original result if possible, otherwise empty result
            return isset($urlResult) 
                ? $urlResult 
                : $this->getDecorated()->getUrls($context, $limit, $offset);
        }
    }
    
    /**
     * Get configuration value
     */
    private function getConfig(string $key, string $salesChannelId): mixed
    {
        return $this->systemConfigService->get(
            'SitemapManager.config.' . $key,
            $salesChannelId
        );
    }
    
    /**
     * Get excluded product numbers from configuration
     */
    private function getExcludedProductNumbers(string $salesChannelId): array
    {
        $excludedNumbersString = $this->getConfig('excludeProductNumbers', $salesChannelId);
        
        if (empty($excludedNumbersString)) {
            return [];
        }
        
        return array_map('trim', explode(',', $excludedNumbersString));
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
                'SELECT LOWER(HEX(id)) as id FROM product WHERE available_stock <= 0 AND is_closeout = 1'
            );
            
            return array_column($result, 'id');
        } catch (\Exception $e) {
            $this->logger->error('Error fetching out of stock product IDs', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}