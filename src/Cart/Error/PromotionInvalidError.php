<?php declare(strict_types=1);

namespace Af\PromotionFeedback\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

class PromotionInvalidError extends Error
{
    public function __construct(
        protected string $promotionCode,
        private readonly string $reason
    ) {
        $this->message = \sprintf('Promo code %s is not valid (%s).', $this->promotionCode, $this->reason);

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return 'promotion-invalid-' . $this->reason;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function getMessageKey(): string
    {
        return 'promotion-invalid-' . $this->reason;
    }

    public function blockOrder(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return [
            'code' => $this->promotionCode,
        ];
    }

    public function isPersistent(): bool
    {
        return true;
    }
}