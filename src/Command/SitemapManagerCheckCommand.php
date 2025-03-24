<?php declare(strict_types=1);

namespace SitemapManager\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class SitemapManagerCheckCommand extends Command
{
    protected static $defaultName = 'sitemap-manager:config:check';
    
    private SystemConfigService $systemConfigService;
    private EntityRepository $salesChannelRepository;
    
    public function __construct(
        SystemConfigService $systemConfigService,
        EntityRepository $salesChannelRepository
    ) {
        parent::__construct();
        $this->systemConfigService = $systemConfigService;
        $this->salesChannelRepository = $salesChannelRepository;
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Checks the current configuration of the SitemapManager plugin')
            ->addArgument('sales-channel-id', InputArgument::OPTIONAL, 'Sales channel ID to check')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Entity type to check (product, category, landing_page)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all configuration values')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command shows the sitemap manager configuration:

<info>php %command.full_name%</info>         Show configuration for default sales channel
<info>php %command.full_name% {salesChannelId}</info>   Show configuration for specific sales channel
<info>php %command.full_name% --entity=product</info>   Show only product configuration
<info>php %command.full_name% --all</info>   Show all configuration values
EOF
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SitemapManager Configuration Check');
        
        $salesChannelId = $input->getArgument('sales-channel-id');
        $entityType = $input->getOption('entity');
        $showAll = $input->getOption('all');
        
        if (!$salesChannelId) {
            $salesChannelId = $this->getDefaultSalesChannelId();
            
            if (!$salesChannelId) {
                $io->error('No sales channel found. Please specify a sales channel ID.');
                return Command::FAILURE;
            }
        }
        
        // Get sales channel information
        $salesChannel = $this->getSalesChannelDetails($salesChannelId);
        
        if (!$salesChannel) {
            $io->error(sprintf('Sales channel with ID "%s" not found.', $salesChannelId));
            return Command::FAILURE;
        }
        
        $io->section(sprintf('Configuration for Sales Channel: %s (%s)', $salesChannel['name'], $salesChannelId));
        
        // Define config keys by entity type
        $configSections = [
            'product' => [
                'modifyProductUrls',
                'productChangeFrequency',
                'productPriority',
                'excludeProductNumbers',
                'excludeOutOfStockProducts'
            ],
            'category' => [
                'modifyCategoryUrls',
                'categoryChangeFrequency',
                'categoryPriority',
                'excludeCategoryIds'
            ],
            'landing_page' => [
                'modifyLandingPageUrls',
                'landingPageChangeFrequency',
                'landingPagePriority',
                'excludeLandingPageIds'
            ],
            'general' => [
                'enableBackup',
                'enableLogging'
            ]
        ];
        
        // Output configuration based on entity type filter
        if ($entityType && isset($configSections[$entityType])) {
            $this->outputConfigSection($io, $salesChannelId, $entityType, $configSections[$entityType]);
        } elseif ($entityType) {
            $io->error(sprintf('Unknown entity type "%s". Allowed values: product, category, landing_page, general', $entityType));
            return Command::FAILURE;
        } else {
            foreach ($configSections as $section => $keys) {
                $this->outputConfigSection($io, $salesChannelId, $section, $keys, $showAll);
            }
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Output configuration section
     */
    private function outputConfigSection(SymfonyStyle $io, string $salesChannelId, string $section, array $keys, bool $showAll = false): void
    {
        $io->section(ucfirst($section) . ' Settings');
        
        $rows = [];
        foreach ($keys as $key) {
            $value = $this->systemConfigService->get(
                'SitemapManager.config.' . $key,
                $salesChannelId
            );
            
            // Format the value for display
            $displayValue = $this->formatValue($value);
            
            // Only show if it has a value or if --all option is used
            if ($showAll || $value !== null) {
                $rows[] = [$key, $displayValue];
            }
        }
        
        if (empty($rows)) {
            $io->writeln('<comment>No configuration set for this section</comment>');
        } else {
            $io->table(['Setting', 'Value'], $rows);
        }
    }
    
    /**
     * Format configuration value for display
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return '<comment>null</comment>';
        } elseif (is_bool($value)) {
            return $value ? '<info>true</info>' : '<fg=red>false</>';
        } elseif (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        } elseif (is_object($value)) {
            return get_class($value);
        } elseif ($value === '') {
            return '<comment>empty string</comment>';
        } else {
            return (string) $value;
        }
    }
    
    /**
     * Get default sales channel ID
     */
    private function getDefaultSalesChannelId(): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);
        
        $salesChannels = $this->salesChannelRepository->search($criteria, Context::createDefaultContext());
        
        if ($salesChannels->count() === 0) {
            return null;
        }
        
        return $salesChannels->first()->getId();
    }
    
    /**
     * Get sales channel details
     */
    private function getSalesChannelDetails(string $salesChannelId): ?array
    {
        $criteria = new Criteria([$salesChannelId]);
        $salesChannel = $this->salesChannelRepository->search($criteria, Context::createDefaultContext())->first();
        
        if (!$salesChannel) {
            return null;
        }
        
        return [
            'id' => $salesChannel->getId(),
            'name' => $salesChannel->getName()
        ];
    }
}