<?php declare(strict_types=1);

namespace SitemapEditor\Controller\Api;

use Shopware\Core\Content\Sitemap\Service\SitemapExporter;
use Shopware\Core\Content\Sitemap\Service\SitemapLister;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"api"})
 */
class SitemapUrlController extends AbstractController
{
    private EntityRepositoryInterface $salesChannelRepository;
    private SitemapExporter $sitemapExporter;
    private SalesChannelContextFactory $salesChannelContextFactory;
    private SitemapLister $sitemapLister;
    private iterable $urlProviders;

    public function __construct(
        EntityRepositoryInterface $salesChannelRepository,
        SitemapExporter $sitemapExporter,
        SitemapLister $sitemapLister,
        SalesChannelContextFactory $salesChannelContextFactory,
        iterable $urlProviders
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->sitemapExporter = $sitemapExporter;
        $this->sitemapLister = $sitemapLister;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->urlProviders = $urlProviders;
    }

    /**
     * @Route("/api/_action/sitemap-editor/urls/{salesChannelId}", name="api.action.sitemap-editor.urls", methods={"GET"})
     */
    public function getSitemapUrls(string $salesChannelId, Request $request, Context $context): JsonResponse
    {
        // Загружаем объект sales channel
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');
        $criteria->addAssociation('language');
        $criteria->addAssociation('currency');

        /** @var SalesChannelEntity|null $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if (!$salesChannel) {
            return new JsonResponse(['error' => 'Sales channel not found'], 404);
        }

        // Получаем лимит из запроса или используем дефолтное значение
        $limit = $request->query->getInt('limit', 100);
        $offset = $request->query->getInt('offset', 0);
        
        // Создаем контекст sales channel для запроса URL
        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannelId
        );

        // Получаем URL из всех доступных провайдеров
        $urls = [];
        $total = 0;

        // Фильтр типа URL
        $requestedType = $request->query->get('type');

        // Перебираем все URL-провайдеры
        foreach ($this->urlProviders as $provider) {
            try {
                // Получаем название провайдера (тип URL)
                $type = $provider->getName();
                
                // Проверяем фильтр по типу URL из запроса
                if ($requestedType && $requestedType !== 'all' && $type !== $requestedType) {
                    continue;
                }
                
                // Получаем URL от провайдера
                $result = $provider->getUrls($salesChannelContext, $limit, $offset);
                $providerUrls = $result->getUrls();
                
                // Добавляем информацию о URL
                foreach ($providerUrls as $url) {
                    $urls[] = [
                        'loc' => $url->getLoc(),
                        'lastmod' => $url->getLastmod() ? $url->getLastmod()->format(\DateTimeInterface::ATOM) : null,
                        'changefreq' => $url->getChangefreq(),
                        'priority' => $url->getPriority(),
                        'resource' => $url->getResource(),
                        'identifier' => $url->getIdentifier(),
                        'type' => $type,
                    ];
                }
                
                $total += count($providerUrls);
            } catch (\Exception $e) {
                // В случае ошибки просто пропускаем этот тип URL
                continue;
            }
        }

        // Фильтрация по поисковому запросу, если указан
        $searchTerm = $request->query->get('search');
        if ($searchTerm) {
            $searchTerm = strtolower($searchTerm);
            $urls = array_filter($urls, function ($url) use ($searchTerm) {
                return strpos(strtolower($url['loc']), $searchTerm) !== false ||
                       (isset($url['identifier']) && strpos(strtolower($url['identifier']), $searchTerm) !== false) ||
                       strpos(strtolower($url['type']), $searchTerm) !== false;
            });
            $total = count($urls);
        }

        // Сортируем URLs по приоритету (от высокого к низкому)
        usort($urls, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return new JsonResponse([
            'total' => $total,
            'urls' => array_slice($urls, 0, $limit),
            'salesChannel' => [
                'id' => $salesChannel->getId(),
                'name' => $salesChannel->getName(),
                'domains' => $salesChannel->getDomains() ? $salesChannel->getDomains()->count() : 0,
            ]
        ]);
    }
}