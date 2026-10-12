<?php declare(strict_types=1);

namespace Af\PromotionFeedback\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'af:promotion-demo', description: 'Installs or removes the demo promotions used to test promo code error messages.')]
class PromotionDemoCommand extends Command
{
    /**
     * @var list<string>
     */
    private const DEMO_CODES = ['AF-EXPIRED', 'AF-INACTIVE', 'AF-FUTURE', 'AF-VALID'];

    public function __construct(
        private readonly EntityRepository $promotionRepository,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'One of: install, remove')
            ->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel id (32 chars hex). Defaults to the first active Storefront sales channel.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return match ((string) $input->getArgument('action')) {
            'install' => $this->install($io, (string) ($input->getOption('sales-channel-id') ?? '')),
            'remove' => $this->remove($io),
            default => (static function () use ($io): int {
                $io->error('Action must be one of: install, remove');

                return self::INVALID;
            })(),
        };
    }

    private function install(SymfonyStyle $io, string $salesChannelIdOption): int
    {
        $context = Context::createDefaultContext();

        $salesChannelId = $salesChannelIdOption !== '' ? strtolower($salesChannelIdOption) : $this->resolveSalesChannelId();
        $languageIds = $this->resolveLanguageIds();

        if ($salesChannelId === null || $languageIds === ['de' => null, 'en' => null]) {
            $io->error('Could not resolve sales channel or languages. Use --sales-channel-id.');

            return self::FAILURE;
        }

        $existing = $this->promotionRepository
            ->search(
                (new Criteria())->addFilter(new EqualsAnyFilter('code', self::DEMO_CODES)),
                $context
            )
            ->getEntities();

        $existingCodes = $existing->map(static fn (PromotionEntity $promotion): string => (string) $promotion->getCode());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        /** @var array<string, array{active: bool, validFrom: ?\DateTimeImmutable, validUntil: ?\DateTimeImmutable, en: string, de: string}> $definitions */
        $definitions = [
            'AF-EXPIRED' => [
                'active' => true,
                'validFrom' => $now->modify('-10 days'),
                'validUntil' => $now->modify('-1 day'),
                'en' => 'AF Expired Campaign',
                'de' => 'AF Abgelaufene Aktion',
            ],
            'AF-INACTIVE' => [
                'active' => false,
                'validFrom' => null,
                'validUntil' => null,
                'en' => 'AF Inactive',
                'de' => 'AF Inaktiv',
            ],
            'AF-FUTURE' => [
                'active' => true,
                'validFrom' => $now->modify('+2 days'),
                'validUntil' => null,
                'en' => 'AF Future',
                'de' => 'AF Zukünftig',
            ],
            'AF-VALID' => [
                'active' => true,
                'validFrom' => $now->modify('-10 days'),
                'validUntil' => $now->modify('+10 days'),
                'en' => 'AF Valid',
                'de' => 'AF Gültig',
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($definitions as $code => $definition) {
            \assert(is_string($languageIds['de']) && is_string($languageIds['en']));

            if (\in_array($code, $existingCodes, true)) {
                ++$skipped;
                $io->writeln(sprintf('<info>%s</info> already exists, skipped.', $code));

                continue;
            }

            $this->promotionRepository->upsert([
                [
                    'id' => Uuid::randomHex(),
                    'active' => $definition['active'],
                    'validFrom' => $definition['validFrom'],
                    'validUntil' => $definition['validUntil'],
                    'maxRedemptionsGlobal' => 100,
                    'maxRedemptionsPerCustomer' => 1,
                    'useCodes' => true,
                    'code' => $code,
                    'translations' => [
                        ['languageId' => $languageIds['de'], 'name' => $definition['de']],
                        ['languageId' => $languageIds['en'], 'name' => $definition['en']],
                    ],
                    'salesChannels' => [
                        ['salesChannelId' => $salesChannelId, 'priority' => 1],
                    ],
                    'discounts' => [
                        [
                            'scope' => 'cart',
                            'type' => 'percentage',
                            'value' => 10,
                            'considerAdvancedRules' => false,
                        ],
                    ],
                ],
            ], $context);

            ++$created;
            $io->writeln(sprintf('<info>%s</info> created (sales channel %s).', $code, strtoupper($salesChannelId)));
        }

        $io->success(sprintf('Demo promotions installed: %d created, %d already present.', $created, $skipped));

        return self::SUCCESS;
    }

    private function remove(SymfonyStyle $io): int
    {
        $context = Context::createDefaultContext();

        $existing = $this->promotionRepository
            ->search(
                (new Criteria())->addFilter(new EqualsAnyFilter('code', self::DEMO_CODES)),
                $context
            )
            ->getEntities();

        $ids = \array_values($existing->getIds());
        $codes = $existing->map(static fn (PromotionEntity $promotion): string => (string) $promotion->getCode());

        if ($ids === []) {
            $io->writeln('No demo promotions found, nothing to remove.');

            return self::SUCCESS;
        }

        $this->promotionRepository->delete(
            \array_map(static fn (string $id): array => ['id' => $id], $ids),
            $context
        );

        $io->success(sprintf('Removed %d demo promotion(s): %s.', \count($ids), implode(', ', $codes)));

        return self::SUCCESS;
    }

    private function resolveSalesChannelId(): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM sales_channel WHERE active = 1 ORDER BY created_at LIMIT 1'
        );
        $id ??= $this->connection->fetchOne('SELECT id FROM sales_channel ORDER BY created_at LIMIT 1');

        return is_string($id) && $id !== '' ? (string) Uuid::fromBytesToHex($id) : null;
    }

    /**
     * @return array{de: ?string, en: ?string}
     */
    private function resolveLanguageIds(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT language.id, locale.code
             FROM language
             INNER JOIN locale ON locale.id = language.locale_id
             WHERE locale.code IN (\'de-DE\', \'en-GB\')'
        );

        $result = ['de' => null, 'en' => null];

        foreach ($rows as $row) {
            $id = (string) Uuid::fromBytesToHex($row['id']);

            if ($row['code'] === 'de-DE') {
                $result['de'] = $id;
            } elseif ($row['code'] === 'en-GB') {
                $result['en'] = $id;
            }
        }

        return $result;
    }
}