<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AppBundle\Payment;

use Combodo\StripeV3\Action\NotifyUnsafeAction;
use Mockery\Matcher\Not;
use Payum\Core\Extension\Context;
use Payum\Core\Extension\ExtensionInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Generic;
use Payum\Core\Request\GetStatusInterface;
use Payum\Core\Request\Notify;
use SM\Factory\FactoryInterface;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\StateMachine\StateMachineInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Webmozart\Assert\Assert;

class StripeV3UpdatePaymentStateOnNotifyExtension implements ExtensionInterface
{
    /** @var FactoryInterface */
    private $factory;
    /** @var Payum $payum */
    private $payum;

    public function __construct(FactoryInterface $factory, Payum $payum)
    {
        $this->factory = $factory;
        $this->payum = $payum;
    }

    /**
     * {@inheritdoc}
     */
    public function onPreExecute(Context $context): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onExecute(Context $context): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onPostExecute(Context $context): void
    {
        $action = $context->getAction();
        if (!$action instanceof NotifyUnsafeAction) {
            return;
        }
        if ($context->getException() !== null) {
            return;
        }

        $token      = $action->getToken();
        $status     = $action->getStatus();

        if (empty($token) ) {
            throw new BadRequestHttpException('The token provided was not found! (see previous exceptions)');
        }
        if (empty($status)) {
            throw new \LogicException('The request status could not be retrieved! (see previous exceptions)');
        }


        if (! $status->isCaptured()) {
            return;
        }

        $payment = $status->getFirstModel();


        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED) {
            $this->updatePaymentState($payment, PaymentInterface::STATE_COMPLETED);
        }


        $this->payum->getHttpRequestVerifier()->invalidate($token);
    }

    private function updatePaymentState(PaymentInterface $payment, string $nextState): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);

        Assert::isInstanceOf($stateMachine, StateMachineInterface::class);

        if (null !== $transition = $stateMachine->getTransitionToState($nextState)) {
            $stateMachine->apply($transition);
        }
    }
}
