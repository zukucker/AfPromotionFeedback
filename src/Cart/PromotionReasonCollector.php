<?php declare(strict_types=1);

namespace Af\PromotionFeedback\Cart;

use Af\PromotionFeedback\Cart\Error\PromotionInvalidError;
use Af\PromotionFeedback\Service\PromotionCodeDiagnosticService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Promotion\Cart\Error\PromotionNotFoundError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Runs after the core PromotionCollector (priority 0 < 4900) and replaces the generic
 * "promotion not found" error with a reason-specific error whenever the code exists
 * but is currently not applicable (expired campaign, inactive, not started, wrong
 * sales channel, already redeemed individual code).
 */
class PromotionReasonCollector implements CartDataCollectorInterface
{
    public function __construct(private readonly PromotionCodeDiagnosticService $diagnosticService)
    {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($original->getErrors() as $key => $error) {
            if (!$error instanceof PromotionNotFoundError) {
                continue;
            }

            $code = (string) ($error->getParameters()['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $reason = $this->diagnosticService->resolveReason($code, $context);
            if ($reason === null) {
                continue;
            }

            $original->getErrors()->remove($key);
            $original->addErrors(new PromotionInvalidError($code, $reason));
        }
    }
}