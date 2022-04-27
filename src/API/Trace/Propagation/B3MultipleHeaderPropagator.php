<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Trace\Propagation;

use OpenTelemetry\API\Trace\AbstractSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

final class B3MultipleHeaderPropagator implements TextMapPropagatorInterface
{
    public const B3_TRACE_ID_HEADER = 'x-b3-traceid';
    public const B3_SPAN_ID_HEADER = 'x-b3-spanid';
    public const B3_SAMPLED_HEADER = 'x-b3-sampled';
    public const B3_PARENT_SPAN_ID_HEADER = 'x-b3-parentspanid';
    public const B3_DEBUG_FLAG_HEADER = 'x-b3-flags';

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
        return [self::B3_TRACE_ID_HEADER, self::B3_SPAN_ID_HEADER, self::B3_SAMPLED_HEADER];
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

        $setter->set($carrier, self::B3_TRACE_ID_HEADER, $spanContext->getTraceId());
        $setter->set($carrier, self::B3_SPAN_ID_HEADER, $spanContext->getSpanId());
        $setter->set($carrier, self::B3_SAMPLED_HEADER, $spanContext->isSampled() ? '1' : '0');
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
        $traceId = $getter->get($carrier, self::B3_TRACE_ID_HEADER) ?? SpanContext::INVALID_TRACE;
        $spanId = $getter->get($carrier, self::B3_SPAN_ID_HEADER) ?? SpanContext::INVALID_SPAN;
        $sampledState = $getter->get($carrier, self::B3_SAMPLED_HEADER) ?? '1';
        $debugFlag = $getter->get($carrier, self::B3_DEBUG_FLAG_HEADER);
        $parentSpanId = $getter->get($carrier, self::B3_PARENT_SPAN_ID_HEADER);

        if ($parentSpanId !== null && !SpanContext::isValidSpanId($parentSpanId)) {
            return SpanContext::getInvalid();
        }

        if (!SpanContext::isValidSpanId($spanId) || !SpanContext::isValidTraceId($traceId)) {
            return SpanContext::getInvalid();
        }

        $isSampled = ($sampledState === '1' || $debugFlag === '1');

        return SpanContext::createFromRemoteParent(
            $traceId,
            $spanId,
            $isSampled ? SpanContextInterface::TRACE_FLAG_SAMPLED : SpanContextInterface::TRACE_FLAG_DEFAULT
        );
    }
}
