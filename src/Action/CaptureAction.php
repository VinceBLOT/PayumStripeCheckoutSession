<?php

declare(strict_types=1);

namespace Prometee\PayumStripeCheckoutSession\Action;

use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Sync;
use Payum\Core\Security\TokenInterface;
use Prometee\PayumStripeCheckoutSession\Request\Api\RedirectToCheckout;
use Prometee\PayumStripeCheckoutSession\Request\Api\Resource\CreateSession;

class CaptureAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Capture $request
     */
    public function execute($request)
    {
        /* @var $request Capture */
        RequestNotSupportedException::assertSupports($this, $request);
        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (false === $model->offsetExists('id')) {
            $token = $request->getToken();
            $model['success_url'] = $token->getAfterUrl();
            $model['cancel_url'] = $token->getTargetUrl();
            $this->embedTokenHash($model, $token);

            // 1. Create a new `Session`
            $createCheckoutSession = new CreateSession($model);
            $this->gateway->execute($createCheckoutSession);
            $session = $createCheckoutSession->getApiResource();

            // 2. Prepare storing of an `PaymentIntent` object
            //    (legacy Stripe payments were storing `Charge` object)
            $model->replace($session->toArray());
            $this->gateway->execute(new Sync($model));

            // 3. Display the page to redirect to Stripe Checkout portal
            $redirectToCheckout = new RedirectToCheckout($session->toArray());
            $this->gateway->execute($redirectToCheckout);
            // Nothing else will be execute after this line because of the rendering of the template
        }

        // 0. Retrieve `PaymentIntent` object and update it
        $this->gateway->execute(new Sync($model));
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof ArrayAccess
            ;
    }

    /**
     * Save the token hash for future webhook consuming retrieval
     *
     * comment : A `Session` can be completed or its `PaymentIntent` can be canceled.
     *           So the token hash have to be stored both on `Session` metadata and on
     *           `PaymentIntent` metadata
     *
     * @param ArrayObject $model
     * @param TokenInterface $token
     */
    public function embedTokenHash(ArrayObject $model, TokenInterface $token): void
    {
        $metadata = $model->offsetGet('metadata');
        if (null === []) {
            $metadata = [];
        }

        $metadata['token_hash'] = $token->getHash();
        $model['metadata'] = $metadata;

        $paymentIntentData = $model->offsetGet('payment_intent_data');
        if (null === $paymentIntentData) {
            $paymentIntentData = [];
        }
        if (false === isset($paymentIntentData['metadata'])) {
            $paymentIntentData['metadata'] = [];
        }
        $paymentIntentData['metadata']['token_hash'] = $token->getHash();
        $model['payment_intent_data'] = $paymentIntentData;
    }
}
