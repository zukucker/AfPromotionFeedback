<?php

declare(strict_types=1);

use Af\PromotionFeedback\Cart\PromotionReasonCollector;
use Af\PromotionFeedback\Command\PromotionDemoCommand;
use Af\PromotionFeedback\Service\PromotionCodeDiagnosticService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(PromotionCodeDiagnosticService::class)
        ->autowire()
        ->args([service('promotion.repository')]);

    $services->set(PromotionReasonCollector::class)
        ->autowire()
        ->autoconfigure();

    $services->set(PromotionDemoCommand::class)
        ->autowire()
        ->autoconfigure()
        ->args([service('promotion.repository')]);
};