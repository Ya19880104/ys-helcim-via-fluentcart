<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Admin\YSHelcimRefundAdminPage;

final class RefundAdminPageTest extends TestCase
{
    public function testItExposesTheBootstrapWiringSurface(): void
    {
        self::assertTrue(class_exists(YSHelcimRefundAdminPage::class));
        self::assertTrue(method_exists(YSHelcimRefundAdminPage::class, 'registerMenu'));
        self::assertTrue(method_exists(YSHelcimRefundAdminPage::class, 'enqueueAssets'));
        self::assertTrue(method_exists(YSHelcimRefundAdminPage::class, 'render'));
    }

    public function testRegisterMenuRequiresViewAndRefundPermissions(): void
    {
        $checked = [];
        $menus = [];
        $page = new YSHelcimRefundAdminPage(
            static function (string $permission) use (&$checked): bool {
                $checked[] = $permission;
                return $permission === 'orders/view';
            },
            static function (array $menu) use (&$menus): void {
                $menus[] = $menu;
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static fn (string $screen): array => ['screen' => $screen, 'menu_capability' => 'manage_options']
        );

        $page->registerMenu();

        self::assertSame(['orders/view', 'orders/can_refund'], $checked);
        self::assertSame([], $menus);
    }

    public function testRegisterMenuPublishesAHiddenCanonicalPageAndExplicitFluentCartLinkContract(): void
    {
        $menus = [];
        $page = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => in_array($permission, ['orders/view', 'orders/can_refund'], true),
            static function (array $menu) use (&$menus): void {
                $menus[] = $menu;
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static fn (string $screen): array => ['screen' => $screen, 'menu_capability' => 'manage_options']
        );

        $page->registerMenu();

        self::assertCount(1, $menus);
        self::assertSame('admin.php', $menus[0]['parent_slug']);
        self::assertSame('fluent-cart', $menus[0]['menu_parent_slug']);
        self::assertSame('admin.php?page=ys-helcim-refunds', $menus[0]['menu_url']);
        self::assertSame('ys-helcim-refunds', $menus[0]['menu_key']);
        self::assertSame('Helcim Refunds', $menus[0]['page_title']);
        self::assertSame('Helcim Refunds', $menus[0]['menu_title']);
        self::assertSame('manage_options', $menus[0]['capability']);
        self::assertSame('ys-helcim-refunds', $menus[0]['menu_slug']);
        self::assertSame([$page, 'render'], $menus[0]['callback']);
    }

    public function testRegisterMenuFailsClosedWhenInjectedConfigurationIsUnavailable(): void
    {
        $menus = [];
        $page = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => true,
            static function (array $menu) use (&$menus): void {
                $menus[] = $menu;
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static function (string $scope): array {
                unset($scope);
                throw new \RuntimeException('Configuration unavailable.');
            }
        );

        try {
            $page->registerMenu();
        } catch (\Throwable $exception) {
            self::fail('registerMenu must fail closed: ' . $exception->getMessage());
        }

        self::assertSame([], $menus);
    }

    public function testEnqueueAssetsRunsOnlyOnTheCanonicalAndFluentCartPages(): void
    {
        $screens = [];
        foreach (['dashboard' => null, 'ys-helcim-refunds' => 'canonical', 'fluent-cart' => 'spa'] as $pageName => $expectedScreen) {
            $enqueued = [];
            $page = new YSHelcimRefundAdminPage(
                static fn (string $permission): bool => true,
                static function (array $menu): void {
                    unset($menu);
                },
                static function (string $screen, array $config) use (&$enqueued): void {
                    $enqueued[] = compact('screen', 'config');
                },
                static fn (string $scope): array => [
                    'page' => $pageName,
                    'script_url' => '/plugin/assets/js/ys-helcim-refund-admin.js',
                    'style_url' => '/plugin/assets/css/ys-helcim-refund-admin.css',
                    'version' => '1.0.0',
                    'browser_config' => [],
                    'scope' => $scope,
                ]
            );

            $page->enqueueAssets();
            $screens[$pageName] = $enqueued[0]['screen'] ?? null;
        }

        self::assertSame(
            ['dashboard' => null, 'ys-helcim-refunds' => 'canonical', 'fluent-cart' => 'spa'],
            $screens
        );
    }

    public function testEnqueueAssetsPublishesOnlyWhitelistedBrowserConfiguration(): void
    {
        $enqueued = [];
        $page = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => true,
            static function (array $menu): void {
                unset($menu);
            },
            static function (string $screen, array $config) use (&$enqueued): void {
                $enqueued[] = compact('screen', 'config');
            },
            static fn (string $scope): array => [
                'page' => 'ys-helcim-refunds',
                'script_url' => '/plugin/assets/js/ys-helcim-refund-admin.js',
                'style_url' => '/plugin/assets/css/ys-helcim-refund-admin.css',
                'version' => '1.0.0',
                'browser_config' => [
                    'restRoot' => '/wp-json/ys-fc-pay/v1/',
                    'restNonce' => 'nonce-value',
                    'adminPageUrl' => '/wp-admin/admin.php?page=ys-helcim-refunds',
                    'initialOrderId' => 42,
                    'labels' => [
                        'nativeRefund' => 'Refund',
                        'helcimRefund' => 'Helcim Refund',
                        'blocked' => 'Blocked',
                        'api_token' => 'nested-must-not-leak',
                    ],
                    'messages' => [
                        'invalidOrderId' => 'attacker-controlled-copy',
                        'api_token' => 'nested-message-must-not-leak',
                    ],
                    'pollIntervalMs' => 10,
                    'pollAttempts' => 2,
                    'canResolve' => true,
                    'api_token' => 'must-not-leak',
                    'helcim_secret' => 'must-not-leak',
                ],
                'scope' => $scope,
            ]
        );

        $page->enqueueAssets('fluentcart_page_ys-helcim-refunds');

        self::assertCount(1, $enqueued);
        self::assertSame('canonical', $enqueued[0]['screen']);
        self::assertSame('/plugin/assets/js/ys-helcim-refund-admin.js', $enqueued[0]['config']['script_url']);
        self::assertSame('/plugin/assets/css/ys-helcim-refund-admin.css', $enqueued[0]['config']['style_url']);
        self::assertSame('1.0.0', $enqueued[0]['config']['version']);
        self::assertSame('ys-helcim-refund-admin', $enqueued[0]['config']['script_handle']);
        self::assertSame('ys-helcim-refund-admin', $enqueued[0]['config']['style_handle']);
        self::assertSame('ysHelcimRefundAdminConfig', $enqueued[0]['config']['config_object']);
        self::assertSame('canonical', $enqueued[0]['config']['browser_config']['screen']);
        self::assertSame(42, $enqueued[0]['config']['browser_config']['initialOrderId']);
        self::assertTrue($enqueued[0]['config']['browser_config']['canResolve']);
        self::assertSame(
            ['nativeRefund' => 'Refund', 'helcimRefund' => 'Helcim Refund', 'blocked' => 'Blocked'],
            $enqueued[0]['config']['browser_config']['labels']
        );
        self::assertSame(
            self::expectedBrowserMessages(),
            $enqueued[0]['config']['browser_config']['messages']
        );
        self::assertNotSame(
            'attacker-controlled-copy',
            $enqueued[0]['config']['browser_config']['messages']['invalidOrderId']
        );
        self::assertArrayNotHasKey('api_token', $enqueued[0]['config']['browser_config']['messages']);
        self::assertArrayNotHasKey('api_token', $enqueued[0]['config']['browser_config']);
        self::assertArrayNotHasKey('helcim_secret', $enqueued[0]['config']['browser_config']);
    }

    public function testAllAdminCopyUsesStaticallyExtractableTranslationCalls(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Admin/YSHelcimRefundAdminPage.php'
        );

        self::assertDoesNotMatchRegularExpression('/__\(\s*\$/', $source);
        foreach (self::expectedBrowserMessages() as $message) {
            self::assertStringContainsString(
                "__( '" . str_replace("'", "\\'", $message) . "', 'ys-helcim-via-fluentcart' )",
                $source
            );
        }
    }

    public function testRenderOutputsNothingWithoutBothPermissions(): void
    {
        $page = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => $permission === 'orders/view',
            static function (array $menu): void {
                unset($menu);
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static fn (string $scope): array => ['scope' => $scope]
        );

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function testRenderProvidesTheCompleteCanonicalRefundShellWithoutInlineSecrets(): void
    {
        $page = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => true,
            static function (array $menu): void {
                unset($menu);
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static fn (string $scope): array => [
                'scope' => $scope,
                'browser_config' => [
                    'initialOrderId' => 42,
                    'api_token' => 'must-not-render',
                ],
            ]
        );

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('id="ys-helcim-refund-admin"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-order-lookup"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-order-id"', $output);
        self::assertStringContainsString('value="42"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-form"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-transaction"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-amount"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-reason"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-items"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-manage-stock"', $output);
        self::assertMatchesRegularExpression(
            '/id="ys-helcim-refund-manage-stock"[^>]*disabled/',
            $output
        );
        self::assertStringContainsString('This version does not restore stock automatically', $output);
        self::assertStringContainsString('id="ys-helcim-refund-cancel-subscription"', $output);
        self::assertMatchesRegularExpression(
            '/id="ys-helcim-refund-cancel-subscription"[^>]*disabled/',
            $output
        );
        self::assertStringContainsString('Subscription cancellation is not supported', $output);
        self::assertStringContainsString('id="ys-helcim-refund-submit"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-reconcile"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-operation"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution"', $output);
        self::assertMatchesRegularExpression(
            '/id="ys-helcim-refund-resolution"[^>]*hidden/',
            $output
        );
        self::assertStringContainsString('id="ys-helcim-refund-resolution-candidate"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-inspect"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-evidence"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-source"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-action"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-attestation"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-phrase"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-typed-phrase"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-resolution-commit"', $output);
        self::assertStringContainsString('id="ys-helcim-refund-status"', $output);
        self::assertStringContainsString('aria-live="polite"', $output);
        self::assertStringNotContainsString('<script', strtolower($output));
        self::assertStringNotContainsString('api_token', $output);
        self::assertStringNotContainsString('must-not-render', $output);
    }

    /** @return array<string, string> */
    private static function expectedBrowserMessages(): array
    {
        return [
            'restSameOrigin' => 'The REST endpoint must use the same origin as WordPress.',
            'requestFailed' => 'Request failed.',
            'invalidRefundOptions' => 'Invalid refund options.',
            'invalidCandidateTransactionId' => 'Enter a valid candidate Helcim transaction ID.',
            'inspectingPositiveEvidence' => 'Inspecting positive Helcim evidence…',
            'invalidPositiveEvidenceResponse' => 'The positive evidence response is invalid.',
            'positiveEvidenceInspected' => 'Positive evidence inspected. Complete the exact confirmation to continue.',
            'positiveEvidenceInspectionFailed' => 'Positive evidence could not be inspected.',
            'committingPositiveResolution' => 'Committing the positive refund resolution…',
            'invalidPositiveResolutionResponse' => 'The positive resolution response is invalid.',
            'positiveResolutionCommitted' => 'Positive resolution committed. Reading the canonical refund operation…',
            'positiveResolutionUnknown' => 'Positive resolution status is unknown.',
            'refundPageUnavailable' => 'Refund page is unavailable.',
            'noRefundableTransaction' => 'No refundable Helcim transaction was found for this order.',
            'refundBlocked' => 'This Helcim refund is blocked until its accounting state is reconciled.',
            'orderSummary' => 'Order #%1$s · %2$s',
            'refundOptionsLoaded' => 'Refund options loaded.',
            'invalidOrderId' => 'Invalid order ID.',
            'refundOptionsRequired' => 'Refund options must be loaded first.',
            'refundFormUnavailable' => 'Refund form is unavailable.',
            'invalidRefundAmount' => 'Enter a valid refund amount.',
            'operationLabel' => 'Operation',
            'effectiveOperationLabel' => 'Effective operation',
            'providerActionLabel' => 'Provider action',
            'remoteStatusLabel' => 'Remote status',
            'localStatusLabel' => 'Local status',
            'notificationLabel' => 'Notification',
            'effectStatusLabel' => 'Effect status',
            'warningsLabel' => 'Warnings',
            'errorCodeLabel' => 'Error code',
            'providerOutcomeIndeterminate' => 'The provider outcome is indeterminate. Do not submit another refund; inspect positive evidence or reconcile this operation.',
            'manualReconciliationRequired' => 'The provider refund succeeded, but manual stock or local reconciliation is required. Do not submit another refund.',
            'refundCompleted' => 'The Helcim refund and local reconciliation completed.',
            'refundNotCompleted' => 'The refund was not completed. Review the result before trying again.',
            'operationStatusUnreadable' => 'Operation status could not be read.',
            'refundStillReconciling' => 'The refund is still reconciling. Do not submit it again; reconcile this operation.',
            'noOperationToReconcile' => 'There is no valid operation to reconcile.',
            'readingDurableOperation' => 'Reading the durable refund operation…',
            'invalidRefundIntent' => 'Refund intent is invalid.',
            'submittingRefund' => 'Submitting the Helcim refund…',
            'refundStatusUnknownNoRetry' => 'Refund status is unknown. Do not submit it again.',
            'refundStatusUnknown' => 'Refund status is unknown.',
            'refundOptionsLoadFailed' => 'Refund options could not be loaded.',
        ];
    }
}
