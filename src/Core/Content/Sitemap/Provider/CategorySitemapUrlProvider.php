<?php declare(strict_types=1);

namespace SitemapManager\Core\Content\Sitemap\Provider;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Provider\CategoryUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository; // Changed from EntityRepositoryInterface
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;

class CategorySitemapUrlProvider extends AbstractUrlProvider
{
    private CategoryUrlProvider $decorated;
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private EntityRepository $categoryRepository; // Changed type hint
    private LoggerInterface $logger;

    public function __construct(
        CategoryUrlProvider $decorated,
        Connection $connection,
        SystemConfigService $systemConfigService,
        EntityRepository $categoryRepository, // Changed type hint
        LoggerInterface $logger
    ) {
        $this->decorated = $decorated;
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
        $this->categoryRepository = $categoryRepository;
        $this->logger = $logger;
    }

    public function getDecorated(): AbstractUrlProvider
    {
        return $this->decorated;
    }

    public function getName(): string
    {
        return 'category';
    }

    /**
     * Get URLs from the original provider and modify them based on configuration
     */
    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        try {
            $this->logger->debug('CategorySitemapUrlProvider::getUrls called', [
                'salesChannelId' => $context->getSalesChannelId(),
                'limit' => $limit,
                'offset' => $offset
            ]);
            
            // If modification is not enabled, return original URLs
            $modifyCategoryUrls = $this->getConfig('modifyCategoryUrls', $context->getSalesChannelId());
            
            if (!$modifyCategoryUrls) {
                $this->logger->debug('Category URL modification disabled, returning original URLs');
                return $this->getDecorated()->getUrls($context, $limit, $offset);
            }
            
            // Get original URLs
            $urlResult = $this->getDecorated()->getUrls($context, $limit, $offset);
            $originalUrls = $urlResult->getUrls();
            
            $this->logger->debug('Original category URLs retrieved', [
                'count' => count($originalUrls)
            ]);
            
            // Get excluded category IDs
            $excludedCategoryIds = $this->getExcludedCategoryIds($context->getSalesChannelId());
            
            // Get change frequency and priority from configuration
            $changeFreq = $this->getConfig('categoryChangeFrequency', $context->getSalesChannelId());
            $priority = (float) $this->getConfig('categoryPriority', $context->getSalesChannelId());
            
            // Filter and modify URLs
            $modifiedUrls = [];
            foreach ($originalUrls as $url) {
                // Skip excluded categories
                if (in_array($url->getIdentifier(), $excludedCategoryIds)) {
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
            
            $this->logger->debug('Category URLs modified', [
                'originalCount' => count($originalUrls),
                'modifiedCount' => count($modifiedUrls)
            ]);
            
            return new UrlResult($modifiedUrls, $urlResult->getNextOffset());
        } catch (\Exception $e) {
            $this->logger->error('Error in CategorySitemapUrlProvider', [
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
     * Get excluded category IDs from configuration
     */
    private function getExcludedCategoryIds(string $salesChannelId): array
    {
        $excludedIdsString = $this->getConfig('excludeCategoryIds', $salesChannelId);
        
        if (empty($excludedIdsString)) {
            return [];
        }
        
        return array_map('trim', explode(',', $excludedIdsString));
    }
}