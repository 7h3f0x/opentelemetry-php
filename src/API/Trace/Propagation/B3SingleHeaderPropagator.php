<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Trace\Propagation;

use function array_push;
use function count;
use function explode;
use function implode;
use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

final class B3SingleHeaderPropagator implements TextMapPropagatorInterface
{
    public const B3_SINGLE_HEADER = 'b3';

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** {@inheritdoc} */
    public function fields(): array
    {
        return [self::B3_SINGLE_HEADER];
    }

    /** {@inheritdoc} */
    public function inject(&$carrier, PropagationSetterInterface $setter = null, Context $context = null): void
    {
        $setter = $setter ?? ArrayAccessGetterSetter::getInstance();
        $context = $context ?? Context::getCurrent();
        $spanContext = AbstractSpan::fromContext($context)->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $header = [];
        array_push($header, $spanContext->getTraceId());
        array_push($header, $spanContext->getSpanId());
        array_push($header, $spanContext->isSampled() ? '1' : '0');

        $B3Header = implode('-', $header);
        $setter->set($carrier, self::B3_SINGLE_HEADER, $B3Header);
    }

    /** {@inheritdoc} */
    public function extract($carrier, PropagationGetterInterface $getter = null, Context $context = null): Context
    {
        $getter = $getter ?? ArrayAccessGetterSetter::getInstance();
        $context = $context ?? Context::getCurrent();

        $spanContext = self::extractImpl($carrier, $getter);
        if (!$spanContext->isValid()) {
            return $context;
        }

        return $context->withContextValue(AbstractSpan::wrap($spanContext));
    }

    private static function extractImpl($carrier, PropagationGetterInterface $getter): SpanContextInterface
    {
        $B3Header = $getter->get($carrier, self::B3_SINGLE_HEADER);
        if ($B3Header === null) {
            return SpanContext::getInvalid();
        }

        $pieces = explode('-', $B3Header, 4);
        $numPieces = count($pieces);

        $sampledState = '1';
        $traceId = SpanContext::INVALID_TRACE;
        $spanId = SpanContext::INVALID_SPAN;
        switch ($numPieces) {
            case 1:
                [$sampledState] = $pieces;

                break;
            case 2:
                [$traceId, $spanId] = $pieces;

                break;
            case 3:
                [$traceId, $spanId, $sampledState] = $pieces;

                break;
            case 4:
                [$traceId, $spanId, $sampledState, $parentSpanId] = $pieces;
                if (!SpanContext::isValidSpanId($parentSpanId)) {
                    return SpanContext::getInvalid();
                }

                break;
        }

        if (!SpanContext::isValidSpanId($spanId) || !SpanContext::isValidTraceId($traceId)) {
            return SpanContext::getInvalid();
        }

        $isSampled = ($sampledState === '1' || $sampledState === 'd');

        return SpanContext::createFromRemoteParent(
            $traceId,
            $spanId,
            $isSampled ? SpanContextInterface::TRACE_FLAG_SAMPLED : SpanContextInterface::TRACE_FLAG_DEFAULT
        );
    }
}
