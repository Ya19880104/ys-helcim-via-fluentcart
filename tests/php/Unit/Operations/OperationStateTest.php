<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Operations\YSHelcimOperationState;

final class OperationStateTest extends TestCase
{
    public function testOnlyCreatedCanStartRemoteProcessing(): void
    {
        self::assertTrue(YSHelcimOperationState::canTransitionRemote('created', 'processing'));

        foreach (['processing', 'succeeded', 'declined', 'failed', 'indeterminate', 'canceled', 'expired'] as $state) {
            self::assertFalse(YSHelcimOperationState::canTransitionRemote($state, 'processing'));
        }
    }

    public function testRemoteTerminalStatesCannotRestart(): void
    {
        foreach (['succeeded', 'declined', 'failed', 'expired'] as $state) {
            foreach (YSHelcimOperationState::remoteStates() as $candidate) {
                self::assertFalse(YSHelcimOperationState::canTransitionRemote($state, $candidate));
            }
        }

        // canceled = a released expired checkout. Its ONLY legal exit is exact late
        // approval proof; everything else stays forbidden.
        foreach (YSHelcimOperationState::remoteStates() as $candidate) {
            self::assertSame(
                'succeeded' === $candidate,
                YSHelcimOperationState::canTransitionRemote('canceled', $candidate)
            );
        }
    }

    public function testIndeterminateCanResolveButCannotRetryProcessing(): void
    {
        self::assertTrue(YSHelcimOperationState::canTransitionRemote('indeterminate', 'succeeded'));
        self::assertTrue(YSHelcimOperationState::canTransitionRemote('indeterminate', 'failed'));
        self::assertFalse(YSHelcimOperationState::canTransitionRemote('indeterminate', 'processing'));
        // Expired-session release: both in-flight states may be released to canceled.
        self::assertTrue(YSHelcimOperationState::canTransitionRemote('indeterminate', 'canceled'));
        self::assertTrue(YSHelcimOperationState::canTransitionRemote('processing', 'canceled'));
        self::assertFalse(YSHelcimOperationState::canTransitionRemote('indeterminate', 'expired'));
        self::assertFalse(YSHelcimOperationState::canTransitionRemote('processing', 'expired'));
    }

    public function testLocalFailureCanBeReconciledWithoutAnotherRemoteTransition(): void
    {
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('pending', 'applying'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('pending', 'failed'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('applying', 'failed'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('failed', 'applying'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('applying', 'recorded'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('recorded', 'applied'));
        self::assertTrue(YSHelcimOperationState::canTransitionLocal('applying', 'applied'));
        self::assertFalse(YSHelcimOperationState::canTransitionLocal('pending', 'applied'));
        self::assertFalse(YSHelcimOperationState::canTransitionLocal('failed', 'applied'));
        self::assertFalse(YSHelcimOperationState::canTransitionLocal('applied', 'pending'));
        self::assertFalse(YSHelcimOperationState::shouldReleaseScope('succeeded', 'recorded', 'purchase'));
    }

    public function testScopeReleaseRequiresDefiniteNoChargeOrFullyAppliedSuccess(): void
    {
        foreach (['created', 'processing', 'indeterminate'] as $remoteState) {
            self::assertFalse(YSHelcimOperationState::shouldReleaseScope($remoteState, 'pending', 'purchase'));
        }

        self::assertFalse(YSHelcimOperationState::shouldReleaseScope('succeeded', 'pending', 'purchase'));
        self::assertFalse(YSHelcimOperationState::shouldReleaseScope('succeeded', 'failed', 'purchase'));
        self::assertFalse(
            YSHelcimOperationState::shouldReleaseScope('succeeded', 'applied', 'purchase'),
            'A paid purchase permanently reserves its transaction family against a stale-read successor.'
        );
        self::assertTrue(YSHelcimOperationState::shouldReleaseScope('succeeded', 'applied', 'refund'));
        self::assertTrue(YSHelcimOperationState::shouldReleaseScope('succeeded', 'applied', 'reverse'));

        foreach (['declined', 'failed', 'expired'] as $remoteState) {
            self::assertTrue(YSHelcimOperationState::shouldReleaseScope($remoteState, 'pending', 'purchase'));
        }
        self::assertFalse(
            YSHelcimOperationState::shouldReleaseScope('canceled', 'pending', 'purchase'),
            'An empty post-expiry lookup is not definite no-charge proof; the transaction stays quarantined.'
        );
    }
}
