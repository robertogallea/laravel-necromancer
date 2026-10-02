<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Bindings\Actions;

use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerInvoiceNumbers;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;

final class NecromancerIssueInvoice
{
    public function handle(NecromancerPaymentGateway $gateway, NecromancerInvoiceNumbers $numbers, ?string $note = null): void {}
}
