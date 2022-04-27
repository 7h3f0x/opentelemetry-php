<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\API\Unit\Trace\Propagation;

use OpenTelemetry\API\Trace\Propagation\B3SingleHeaderPropagator;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\Span;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenTelemetry\API\Trace\Propagation\B3SingleHeaderPropagator
 */
class B3SingleHeaderPropagatorTest extends TestCase
{
    private const TRACE_ID_BASE16 = 'ff000000000000000000000000000041';
    private const SPAN_ID_BASE16 = 'ff00000000000041';
    private const B3_HEADER_SAMPLED = self::TRACE_ID_BASE16 . '-' . self::SPAN_ID_BASE16 . '-1';
    private const B3_HEADER_DEBUG = self::TRACE_ID_BASE16 . '-' . self::SPAN_ID_BASE16 . '-d';
    private const B3_HEADER_NOT_SAMPLED = self::TRACE_ID_BASE16 . '-' . self::SPAN_ID_BASE16 . '-0';

    private B3SingleHeaderPropagator $b3SingleHeaderPropagator;

    protected function setUp(): void
    {
        $this->b3SingleHeaderPropagator = B3SingleHeaderPropagator::getInstance();
    }

    public function test_fields(): void
    {
        $this->assertSame(
            ['b3'],
            $this->b3SingleHeaderPropagator->fields()
        );
    }

    public function test_inject_empty(): void
    {
        $carrier = [];
        $this->b3SingleHeaderPropagator->inject($carrier);
        $this->assertEmpty($carrier);
    }

    public function test_inject_invalid_context(): void
    {
        $carrier = [];
        $this
            ->b3SingleHeaderPropagator
            ->inject(
                $carrier,
                null,
                $this->withSpanContext(
                    SpanContext::create(
                        SpanContext::INVALID_TRACE,
                        SpanContext::INVALID_SPAN,
                        SpanContext::SAMPLED_FLAG
                    ),
                    Context::getCurrent()
                )
            );
        $this->assertEmpty($carrier);
    }

    public function test_inject_sampled_context(): void
    {
        $carrier = [];
        $this
            ->b3SingleHeaderPropagator
            ->inject(
                $carrier,
                null,
                $this->withSpanContext(
                    SpanContext::create(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
                    Context::getCurrent()
                )
            );

        $this->assertSame(
            [B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_SAMPLED],
            $carrier
        );
    }

    public function test_inject_non_sampled_context(): void
    {
        $carrier = [];
        $this
            ->b3SingleHeaderPropagator
            ->inject(
                $carrier,
                null,
                $this->withSpanContext(
                    SpanContext::create(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16),
                    Context::getCurrent()
                )
            );

        $this->assertSame(
            [B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_NOT_SAMPLED],
            $carrier
        );
    }

    public function test_extract_nothing(): void
    {
        $this->assertSame(
            Context::getCurrent(),
            $this->b3SingleHeaderPropagator->extract([])
        );
    }

    public function test_extract_sampled_context(): void
    {
        $carrier = [
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_SAMPLED,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3SingleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_sampled_context_debug(): void
    {
        $carrier = [
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_DEBUG,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3SingleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_non_sampled_context(): void
    {
        $carrier = [
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_NOT_SAMPLED,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16),
            $this->getSpanContext($this->b3SingleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_with_parentspan(): void
    {
        $carrier = [
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_SAMPLED . '-' . self::SPAN_ID_BASE16,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3SingleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_and_inject(): void
    {
        $b3SingleHeader = self::TRACE_ID_BASE16 . '-' . self::SPAN_ID_BASE16 . '-1';
        $extractCarrier = [
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => $b3SingleHeader,
        ];
        $context = $this->b3SingleHeaderPropagator->extract($extractCarrier);
        $injectCarrier = [];
        $this->b3SingleHeaderPropagator->inject($injectCarrier, null, $context);
        $this->assertSame($injectCarrier, $extractCarrier);
    }

    public function test_extract_empty_header(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => '',
        ]);
    }

    public function test_extract_invalid_parentspan(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::B3_HEADER_SAMPLED . '-abcdefghijklmnopabcdefghijklmnop',
        ]);
    }

    public function test_invalid_trace_id(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => 'abcdefghijklmnopabcdefghijklmnop-' . self::SPAN_ID_BASE16 . '-1',
        ]);
    }

    public function test_invalid_trace_id_size(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::TRACE_ID_BASE16 . '00-' . self::SPAN_ID_BASE16 . '-1',
        ]);
    }

    public function test_invalid_span_id(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::TRACE_ID_BASE16 . 'abcdefghijklmnop-1',
        ]);
    }

    public function test_invalid_span_id_size(): void
    {
        $this->assertInvalid([
            B3SingleHeaderPropagator::B3_SINGLE_HEADER => self::TRACE_ID_BASE16 . 'abcdefghijklmnop-00-1',
        ]);
    }

    private function assertInvalid(array $carrier): void
    {
        $this->assertSame(
            Context::getCurrent(),
            $this->b3SingleHeaderPropagator->extract($carrier),
        );
    }

    private function getSpanContext(Context $context): SpanContextInterface
    {
        return Span::fromContext($context)->getContext();
    }

    private function withSpanContext(SpanContextInterface $spanContext, Context $context): Context
    {
        return $context->withContextValue(Span::wrap($spanContext));
    }
}
