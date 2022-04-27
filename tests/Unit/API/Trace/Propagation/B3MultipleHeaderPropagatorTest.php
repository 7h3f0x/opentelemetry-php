<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\API\Unit\Trace\Propagation;

use OpenTelemetry\API\Trace\Propagation\B3MultipleHeaderPropagator;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\Span;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenTelemetry\API\Trace\Propagation\B3MultipleHeaderPropagator
 */
class B3MultipleHeaderPropagatorTest extends TestCase
{
    private const TRACE_ID_BASE16 = 'ff000000000000000000000000000041';
    private const SPAN_ID_BASE16 = 'ff00000000000041';
    private const B3_TRACE_ID_HEADER_VALID = self::TRACE_ID_BASE16;
    private const B3_SPAN_ID_HEADER_VALID = self::SPAN_ID_BASE16;
    private const B3_SAMPLED_HEADER_SAMPLED = '1';
    private const B3_DEBUG_HEADER = '1';
    private const B3_SAMPLED_HEADER_NOT_SAMPLED = '0';

    private B3MultipleHeaderPropagator $b3MultipleHeaderPropagator;

    protected function setUp(): void
    {
        $this->b3MultipleHeaderPropagator = B3MultipleHeaderPropagator::getInstance();
    }

    public function test_fields(): void
    {
        $this->assertSame(
            ['x-b3-traceid', 'x-b3-spanid', 'x-b3-sampled'],
            $this->b3MultipleHeaderPropagator->fields()
        );
    }

    public function test_inject_empty(): void
    {
        $carrier = [];
        $this->b3MultipleHeaderPropagator->inject($carrier);
        $this->assertEmpty($carrier);
    }

    public function test_inject_invalid_context(): void
    {
        $carrier = [];
        $this
            ->b3MultipleHeaderPropagator
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
            ->b3MultipleHeaderPropagator
            ->inject(
                $carrier,
                null,
                $this->withSpanContext(
                    SpanContext::create(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
                    Context::getCurrent()
                )
            );

        $this->assertSame(
            [
                B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
                B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
                B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
            ],
            $carrier
        );
    }

    public function test_inject_non_sampled_context(): void
    {
        $carrier = [];
        $this
            ->b3MultipleHeaderPropagator
            ->inject(
                $carrier,
                null,
                $this->withSpanContext(
                    SpanContext::create(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16),
                    Context::getCurrent()
                )
            );

        $this->assertSame(
            [
                B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
                B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
                B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_NOT_SAMPLED,
            ],
            $carrier
        );
    }

    public function test_extract_nothing(): void
    {
        $this->assertSame(
            Context::getCurrent(),
            $this->b3MultipleHeaderPropagator->extract([])
        );
    }

    public function test_extract_sampled_context(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_non_sampled_context(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_NOT_SAMPLED,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_sampled_context_debug(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_DEBUG_FLAG_HEADER => self::B3_DEBUG_HEADER,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_and_inject(): void
    {
        $extractCarrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ];
        $context = $this->b3MultipleHeaderPropagator->extract($extractCarrier);
        $injectCarrier = [];
        $this->b3MultipleHeaderPropagator->inject($injectCarrier, null, $context);
        $this->assertSame($injectCarrier, $extractCarrier);
    }

    public function test_extract_empty_traceid(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => '',
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_extract_no_traceid(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_extract_empty_spanid(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => '',
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_extract_no_spanid(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_extract_empty_sampled(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => '',
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_no_sampled(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContext::SAMPLED_FLAG),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_extract_valid_parentspan(): void
    {
        $carrier = [
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
            B3MultipleHeaderPropagator::B3_PARENT_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
        ];

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::TRACE_ID_BASE16, self::SPAN_ID_BASE16, SpanContextInterface::TRACE_FLAG_SAMPLED),
            $this->getSpanContext($this->b3MultipleHeaderPropagator->extract($carrier))
        );
    }

    public function test_invalid_trace_id(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => 'abcdefghijklmnopabcdefghijklmnop',
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_invalid_trace_id_size(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID . '00',
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_invalid_span_id(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => 'abcdefghijklmnop',
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_invalid_span_id_size(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => 'abcdefghijklmnop-00',
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
        ]);
    }

    public function test_invalid_parentspan(): void
    {
        $this->assertInvalid([
            B3MultipleHeaderPropagator::B3_TRACE_ID_HEADER => self::B3_TRACE_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SPAN_ID_HEADER => self::B3_SPAN_ID_HEADER_VALID,
            B3MultipleHeaderPropagator::B3_SAMPLED_HEADER => self::B3_SAMPLED_HEADER_SAMPLED,
            B3MultipleHeaderPropagator::B3_PARENT_SPAN_ID_HEADER => 'abcdefghijklmnop',
        ]);
    }

    private function assertInvalid(array $carrier): void
    {
        $this->assertSame(
            Context::getCurrent(),
            $this->b3MultipleHeaderPropagator->extract($carrier),
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
