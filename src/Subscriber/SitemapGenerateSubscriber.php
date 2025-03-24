<?php declare(strict_types=1);

namespace SitemapEditor\Subscriber;

use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\Sitemap\Event\SitemapGeneratedEvent;
use Shopware\Core\Content\Sitemap\Event\SitemapSalesChannelCriteriaEvent;
use Shopware\Core\Content\Sitemap\Event\SitemapSalesChannelContextEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class SitemapGenerateSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private FilesystemOperator $filesystem;
    private EntityRepository $salesChannelRepository;
    private string $sitemapDirectory = 'sitemap';

    public function __construct(
        LoggerInterface $logger,
        FilesystemOperator $publicFilesystem,
        EntityRepository $salesChannelRepository
    ) {
        $this->logger = $logger;
        $this->filesystem = $publicFilesystem;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SitemapGeneratedEvent::class => 'onSitemapGenerated',
            SitemapSalesChannelCriteriaEvent::class => 'onSitemapSalesChannelCriteria',
            SitemapSalesChannelContextEvent::class => 'onSitemapSalesChannelContext',
        ];
    }

    public function onSitemapGenerated(SitemapGeneratedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $languageId = $salesChannelContext->getLanguageId();
        
        $this->logger->info(
            'Sitemap generation completed for sales channel',
            [
                'salesChannelId' => $salesChannelId,
                'languageId' => $languageId
            ]
        );
        
        // Получаем URL из файлов sitemap
        $sitemapUrls = $this->getSitemapUrls($salesChannelId, $languageId);
        
        // Сохраняем URL в кастомфилды sales channel
        $this->saveSitemapUrlsToCustomFields($salesChannelId, $sitemapUrls, $salesChannelContext->getContext());
    }

    public function onSitemapSalesChannelCriteria(SitemapSalesChannelCriteriaEvent $event): void
    {
        $criteria = $event->getCriteria();
        $criteria->addAssociation('translations');
        
        $this->logger->debug(
            'Sitemap criteria modified',
            [
                'event' => 'SitemapSalesChannelCriteriaEvent',
                // 'salesChannelId' => 'SitemapSalesChannelCriteriaEvent' // Use this if available
            ]
        );
    }

    public function onSitemapSalesChannelContext(SitemapSalesChannelContextEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        
        $this->logger->debug(
            'Sitemap context event',
            [
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                'salesChannelName' => $salesChannelContext->getSalesChannel()->getName(),
            ]
        );
    }

    /**
     * Получает все URL из файлов sitemap.xml для конкретного sales channel
     */
    private function getSitemapUrls(string $salesChannelId, string $languageId): array
    {
        $urls = [];
        
        try {
            // Структура файлов: sitemap/salesChannelId_languageId_index.xml
            $filePattern = sprintf('%s_%s_*.xml', $salesChannelId, $languageId);
            
            // Получаем список файлов в директории sitemap
            $pattern = '/^' . preg_quote($salesChannelId . '_' . $languageId . '_', '/') . '.*\.xml$/';
            $files = $this->filesystem->listContents($this->sitemapDirectory)
                ->filter(function ($file) use ($pattern) {
                    return $file['type'] === 'file' && preg_match($pattern, $file['path']);
                })
                ->toArray();
                
            foreach ($files as $file) {
                $content = $this->filesystem->read($this->sitemapDirectory . '/' . $file['path']);
                
                // Парсим XML и извлекаем все URL
                $xml = simplexml_load_string($content);
                if ($xml) {
                    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
                    $urlNodes = $xml->xpath('//s:url');
                    
                    foreach ($urlNodes as $urlNode) {
                        $loc = (string)$urlNode->loc;
                        
                        if (!empty($loc)) {
                            $urlInfo = [
                                'loc' => $loc,
                                'lastmod' => (string)$urlNode->lastmod,
                                'changefreq' => (string)$urlNode->changefreq,
                                'priority' => (string)$urlNode->priority
                            ];
                            
                            $urls[] = $urlInfo;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error while searching sitemap files',
                [
                    'error' => $e->getMessage(),
                    'salesChannelId' => $salesChannelId
                ]
            );
        }
        
        $this->logger->info(
            'Sitemap URLs collected',
            [
                'salesChannelId' => $salesChannelId,
                'urlCount' => count($urls)
            ]
        );
        
        return $urls;
    }
    
    /**
     * Сохраняет URLs в кастомфилды sales channel
     */
    private function saveSitemapUrlsToCustomFields(string $salesChannelId, array $urls, Context $context): void
    {
        try {
            // Сохраняем URL в кастомфилды sales channel
            $this->salesChannelRepository->update([
                [
                    'id' => $salesChannelId,
                    'customFields' => [
                        'sitemap_editor_url_list' => json_encode($urls),
                        'sitemap_editor_last_updated' => (new \DateTime())->format(\DateTime::ATOM),
                        'sitemap_editor_url_count' => count($urls)
                    ]
                ]
            ], $context);
            
            $this->logger->info(
                'Sitemap URLs saved to custom fields',
                [
                    'salesChannelId' => $salesChannelId,
                    'urlCount' => count($urls)
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error while saving sitemap URLs to custom fields',
                [
                    'error' => $e->getMessage(),
                    'salesChannelId' => $salesChannelId
                ]
            );
        }
    }
    
}