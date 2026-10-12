<?php declare(strict_types=1);

namespace Af\PromotionFeedback\Service;

use Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeEntity;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Looks up a promo code without the strict "permitted" filters of the promotion gateway
 * and answers why the code is currently not applicable.
 */
class PromotionCodeDiagnosticService
{
    private const ASSOCIATIONS = ['salesChannels', 'individualCodes'];

    public function __construct(private readonly EntityRepository $promotionRepository)
    {
    }

    /**
     * Returns a reason key matching a `checkout.promotion-invalid-<reason>` snippet,
     * or null when the code is either unknown or should have been applicable.
     */
    public function resolveReason(string $code, SalesChannelContext $context): ?string
    {
        $promotion = $this->searchGlobalCode($code, $context->getContext());
        $individualCode = null;

        if ($promotion === null) {
            $individualCode = $this->searchIndividualCode($code, $context->getContext());
            $promotion = $individualCode?->getPromotion();
        }

        if ($promotion === null) {
            return null;
        }

        if ($individualCode !== null && $individualCode->getPayload() !== null) {
            return 'already-redeemed';
        }

        if (!$promotion->isActive()) {
            return 'inactive';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $validFrom = $promotion->getValidFrom();
        if ($validFrom !== null && \DateTimeImmutable::createFromInterface($validFrom)->setTimezone(new \DateTimeZone('UTC')) > $now) {
            return 'not-started';
        }

        $validUntil = $promotion->getValidUntil();
        if ($validUntil !== null && \DateTimeImmutable::createFromInterface($validUntil)->setTimezone(new \DateTimeZone('UTC')) <= $now) {
            return 'expired';
        }

        if (!$this->isAssignedToSalesChannel($promotion, $context->getSalesChannelId())) {
            return 'sales-channel';
        }

        return null;
    }

    private function searchGlobalCode(string $code, Context $context): ?PromotionEntity
    {
        $criteria = new Criteria();
        $criteria
            ->setLimit(1)
            ->addFilter(new EqualsFilter('code', $code))
            ->addAssociations(self::ASSOCIATIONS);

        return $this->promotionRepository->search($criteria, $context)->first();
    }

    private function searchIndividualCode(string $code, Context $context): ?PromotionIndividualCodeEntity
    {
        $criteria = new Criteria();
        $criteria
            ->setLimit(1)
            ->addFilter(new EqualsAnyFilter('individualCodes.code', [$code]))
            ->addAssociations(self::ASSOCIATIONS);

        $promotion = $this->promotionRepository->search($criteria, $context)->first();

        if ($promotion === null || $promotion->getIndividualCodes() === null) {
            return null;
        }

        foreach ($promotion->getIndividualCodes() as $individualCode) {
            if ($individualCode->getCode() === $code) {
                return $individualCode;
            }
        }

        return null;
    }

    private function isAssignedToSalesChannel(PromotionEntity $promotion, string $salesChannelId): bool
    {
        $salesChannels = $promotion->getSalesChannels();
        if ($salesChannels === null || $salesChannels->count() === 0) {
            return true;
        }

        foreach ($salesChannels as $salesChannel) {
            if ($salesChannel->getSalesChannelId() === $salesChannelId) {
                return true;
            }
        }

        return false;
    }
}