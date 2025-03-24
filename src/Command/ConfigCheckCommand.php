<?php declare(strict_types=1);

namespace SitemapEditor\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigCheckCommand extends Command
{
    protected static $defaultName = 'sitemap-editor:config:check';
    
    private SystemConfigService $systemConfigService;
    
    public function __construct(SystemConfigService $systemConfigService)
    {
        parent::__construct();
        $this->systemConfigService = $systemConfigService;
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Checks the current configuration of the SitemapEditor plugin')
            ->addArgument('sales-channel-id', InputArgument::OPTIONAL, 'Sales channel ID to check');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $salesChannelId = $input->getArgument('sales-channel-id');
        
        $configKeys = [
            'modifyProductUrls',
            'productChangeFrequency',
            'productPriority',
            'excludedProductNumbers',
            'excludeOutOfStockProducts'
        ];
        
        $output->writeln('SitemapEditor Configuration:');
        
        foreach ($configKeys as $key) {
            $value = $this->systemConfigService->get(
                'SitemapEditor.config.' . $key,
                $salesChannelId
            );
            
            $output->writeln(sprintf('- %s: %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }
        
        return Command::SUCCESS;
    }
}