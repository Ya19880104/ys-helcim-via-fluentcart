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
        self::assertTrue(method_exists(YSHelcimRefundAdminPage::class, 'renderModal'));
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
        self::assertStringContainsString('<section id="ys-helcim-refund-sync" class="ys-helcim-refund-sync" hidden>', $output);
        self::assertStringContainsString('id="ys-helcim-refund-sync-button" type="button"', $output);
        self::assertStringContainsString('Sync refunds from Helcim', $output);
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
        // attestation 前可核對的 Helcim 讀回：候選交易類型、金額、invoiceNumber，各有自己的標籤。
        self::assertStringContainsString(
            '<dt>Candidate transaction type</dt><dd id="ys-helcim-refund-resolution-candidate-type"></dd>',
            $output
        );
        self::assertStringContainsString(
            '<dt>Candidate amount</dt><dd id="ys-helcim-refund-resolution-candidate-amount"></dd>',
            $output
        );
        self::assertStringContainsString(
            '<dt>Helcim invoice number</dt><dd id="ys-helcim-refund-resolution-invoice"></dd>',
            $output
        );
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
            'refundCompleted' => 'Helcim refunded the payment and FluentCart recorded the refund. The money usually reaches the card within 5 to 10 business days.',
            'paymentVoided' => 'The payment had not settled yet, so Helcim cancelled (voided) it instead of refunding it, and FluentCart recorded the refund. No processing fee applies, and the pending charge disappears from the card within 1 to 2 days.',
            'refundNotCompleted' => 'The refund was not completed. Review the result before trying again.',
            'openBatchPartialRefund' => 'This payment has not settled yet, so Helcim can only cancel the full amount. No money was moved. Enter the full amount to cancel the payment now, or make this partial refund after the payment settles (Helcim settles once a day).',
            'openBatchUnproven' => 'Helcim did not accept the refund, and the plugin could not confirm that the payment is still unsettled, so nothing was sent. Wait a few minutes and try again.',
            'operationStatusUnreadable' => 'Operation status could not be read.',
            'refundStillReconciling' => 'The refund is still reconciling. Do not submit it again; reconcile this operation.',
            'noOperationToReconcile' => 'There is no valid operation to reconcile.',
            'readingDurableOperation' => 'Reading the durable refund operation…',
            'invalidRefundIntent' => 'Refund intent is invalid.',
            'submittingRefund' => 'Submitting the Helcim refund…',
            'refundStatusUnknownNoRetry' => 'Refund status is unknown. Do not submit it again.',
            'refundStatusUnknown' => 'Refund status is unknown.',
            'refundOptionsLoadFailed' => 'Refund options could not be loaded.',
            'classificationPending' => 'Checking whether this payment was taken through Helcim. Please wait a moment and try again.',
            'syncingProviderRefunds' => 'Checking Helcim for refunds or voids made outside this plugin…',
            'providerRefundsRecorded' => 'Recorded refunds or voids from Helcim: %1$s. FluentCart now matches Helcim.',
            'providerRefundsNothingNew' => 'Helcim has no refunds or voids for this order that are missing from FluentCart.',
            'providerRefundsNoHelcimPayment' => 'This order has no completed Helcim payment to check.',
            'providerRefundsNeedReview' => 'Some refunds in Helcim could not be recorded automatically (%1$s). Compare this order with Helcim before refunding again.',
            'providerRefundsRetryLater' => 'Another refund for this order is still in progress, or Helcim could not be reached. Try again in a few minutes.',
            'providerRefundsSyncFailed' => 'Refunds could not be synced from Helcim.',
            'providerRefundsLegacyPayment' => 'This order was paid before this plugin started keeping a payment journal, so a refund or void made in Helcim cannot be synced automatically. Compare this order with Helcim before refunding.',
            'batchClosedRefundRejected' => 'Helcim rejected this refund, and the payment has already settled, so nothing was sent. Check in Helcim whether this payment was already refunded or voided. If it was, use “Sync refunds from Helcim” to record it in FluentCart.',
            'providerRefundPending' => 'A refund or void made directly in Helcim for this order is not recorded in FluentCart yet. Use “Sync refunds from Helcim” to finish recording it before refunding again.',
            'refundModalBusy' => 'The refund request is still being processed. Wait for the result before closing this window.',
        ];
    }

    /**
     * 建立測試用頁面物件：頁面 slug、權限與瀏覽器設定都可注入。
     *
     * @param array<string, mixed> $browserConfig
     * @param list<array{screen:string,config:array<string,mixed>}> $enqueued
     */
    private static function pageFor(
        string $pageName,
        bool $canRefund = true,
        array $browserConfig = [],
        array &$enqueued = []
    ): YSHelcimRefundAdminPage {
        return new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => $permission === 'orders/view' || ($canRefund && $permission === 'orders/can_refund'),
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
                'version' => '1.1.3',
                'browser_config' => $browserConfig,
                'scope' => $scope,
            ]
        );
    }

    private static function capture(callable $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /** @return list<string> */
    private static function controlIds(string $html): array
    {
        preg_match_all('/\bid="([^"]+)"/', $html, $matches);

        return $matches[1];
    }

    public function testRenderModalOutputsOnlyOnTheFluentCartPageWithBothPermissions(): void
    {
        $output = self::capture([self::pageFor('fluent-cart'), 'renderModal']);

        self::assertStringStartsWith(
            '<div id="ys-helcim-refund-modal" class="ys-helcim-refund-modal" hidden aria-hidden="true">',
            $output
        );
        self::assertStringContainsString('class="ys-helcim-refund-modal__backdrop" data-ys-helcim-refund-modal-close', $output);
        self::assertStringContainsString(
            '<div class="ys-helcim-refund-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ys-helcim-refund-modal-title" tabindex="-1">',
            $output
        );
        self::assertStringContainsString('<h2 id="ys-helcim-refund-modal-title">Refund through Helcim</h2>', $output);
        self::assertStringContainsString(
            '<button type="button" class="ys-helcim-refund-modal__close" data-ys-helcim-refund-modal-close aria-label="Close">',
            $output
        );
        self::assertStringContainsString(
            '<div class="ys-helcim-refund-admin ys-helcim-refund-modal__body" id="ys-helcim-refund-admin">',
            $output
        );
        // cleanupSpaEnhancement() 會移除帶這兩個屬性的節點：彈窗絕不可帶。
        self::assertStringNotContainsString('data-ys-helcim-refund-order', $output);
        self::assertStringNotContainsString('data-ys-helcim-refund-enhancement', $output);
        self::assertStringNotContainsString('<script', strtolower($output));

        self::assertSame('', self::capture([self::pageFor('ys-helcim-refunds'), 'renderModal']));
        self::assertSame('', self::capture([self::pageFor('dashboard'), 'renderModal']));
        self::assertSame('', self::capture([self::pageFor(''), 'renderModal']));
        self::assertSame('', self::capture([self::pageFor('fluent-cart', false), 'renderModal']));

        $throwing = new YSHelcimRefundAdminPage(
            static fn (string $permission): bool => true,
            static function (array $menu): void {
                unset($menu);
            },
            static function (string $screen, array $config): void {
                unset($screen, $config);
            },
            static function (string $scope): array {
                unset($scope);
                throw new \RuntimeException('Configuration unavailable.');
            }
        );
        self::assertSame('', self::capture([$throwing, 'renderModal']));
    }

    public function testRenderModalUsesTheExactCanonicalPanelMarkupAndControlIds(): void
    {
        $canonical = self::capture([self::pageFor('ys-helcim-refunds'), 'render']);
        $modal = self::capture([self::pageFor('fluent-cart'), 'renderModal']);

        $canonicalOpen = '<div class="wrap ys-helcim-refund-admin" id="ys-helcim-refund-admin"><h1>Helcim Refunds</h1>';
        self::assertStringStartsWith($canonicalOpen, $canonical);
        self::assertStringEndsWith('</div>', $canonical);
        $canonicalBody = substr($canonical, strlen($canonicalOpen), -strlen('</div>'));

        $modalOpen = '<div class="ys-helcim-refund-admin ys-helcim-refund-modal__body" id="ys-helcim-refund-admin">';
        $modalBodyStart = strpos($modal, $modalOpen);
        self::assertNotFalse($modalBodyStart);
        self::assertStringEndsWith('</div></div></div>', $modal);
        $modalBody = substr(
            $modal,
            $modalBodyStart + strlen($modalOpen),
            -strlen('</div></div></div>')
        );

        // 同一個來源：面板本體逐位元組相同（沒有預填訂單編號時）。
        self::assertSame($canonicalBody, $modalBody);

        // 彈窗靠這個 class 以 CSS 隱藏訂單編號查詢表單（.ys-helcim-refund-modal__body .ys-helcim-refund-lookup）；
        // class 不見時彈窗會露出查詢表單，店家可在別張訂單的頁面把其他訂單載進彈窗。
        self::assertStringContainsString(
            '<form id="ys-helcim-refund-order-lookup" class="ys-helcim-refund-lookup"',
            $modal
        );

        $panelIds = array_map(
            static fn (string $suffix): string => 'ys-helcim-refund' . $suffix,
            [
                '-admin', '-order-lookup', '-order-id', '-status', '-sync', '-sync-button', '-context',
                '-summary', '-form', '-transaction', '-amount', '-amount-note', '-reason', '-items',
                '-manage-stock', '-manage-stock-note', '-cancel-subscription', '-cancel-subscription-note',
                '-submit', '-reconcile', '-operation', '-resolution', '-resolution-title',
                '-resolution-candidate', '-resolution-inspect', '-resolution-evidence',
                '-resolution-evidence-status', '-resolution-source', '-resolution-candidate-type',
                '-resolution-candidate-amount', '-resolution-invoice', '-resolution-action',
                '-resolution-confirmation', '-resolution-attestation', '-resolution-phrase',
                '-resolution-typed-phrase', '-resolution-commit',
            ]
        );
        $canonicalIds = self::controlIds($canonical);
        $modalIds = self::controlIds($modal);
        self::assertSame(array_values(array_unique($canonicalIds)), $canonicalIds);
        self::assertSame(array_values(array_unique($modalIds)), $modalIds);
        self::assertEqualsCanonicalizing($panelIds, $canonicalIds);
        self::assertSame(
            $canonicalIds,
            array_values(array_diff($modalIds, ['ys-helcim-refund-modal', 'ys-helcim-refund-modal-title']))
        );
    }

    /**
     * FluentCart 後台根元件掛載時執行 jQuery(".notice:not(.fluent-cart), .error:not(.fluent-cart)").remove()。
     * 伺服器輸出的狀態列帶 notice／error class 就會被整個移出 DOM，彈窗裡的狀態訊息全部消失；
     * notice class 只能由 JS 在顯示訊息時加上。
     */
    public function testServerRenderedStatusRowCarriesNoNoticeOrErrorClass(): void
    {
        $outputs = [
            'canonical' => self::capture([self::pageFor('ys-helcim-refunds'), 'render']),
            'modal' => self::capture([self::pageFor('fluent-cart'), 'renderModal']),
        ];

        foreach ($outputs as $screen => $output) {
            self::assertSame(
                1,
                preg_match_all('/<div id="ys-helcim-refund-status"[^>]*>/', $output, $tags),
                $screen
            );
            $tag = $tags[0][0];
            self::assertSame(
                '<div id="ys-helcim-refund-status" class="ys-helcim-refund-status inline" role="status" aria-live="polite" hidden>',
                $tag,
                $screen
            );
            self::assertSame(1, preg_match('/\bclass="([^"]*)"/', $tag, $class), $screen);
            $classes = preg_split('/\s+/', trim($class[1])) ?: [];
            self::assertNotContains('notice', $classes, $screen);
            self::assertNotContains('error', $classes, $screen);
            self::assertContains('ys-helcim-refund-status', $classes, $screen);
        }
    }

    public function testModalCopyAndBrowserFlagSurviveTheWhitelistOnlyOnTheFluentCartPage(): void
    {
        $spa = [];
        self::pageFor('fluent-cart', true, ['modalEnabled' => true, 'restRoot' => '/wp-json/ys-fc-pay/v1/'], $spa)
            ->enqueueAssets();
        self::assertCount(1, $spa);
        self::assertSame('spa', $spa[0]['config']['browser_config']['screen']);
        self::assertTrue($spa[0]['config']['browser_config']['modalEnabled']);
        self::assertSame(
            self::expectedBrowserMessages()['refundModalBusy'],
            $spa[0]['config']['browser_config']['messages']['refundModalBusy']
        );

        $truthy = [];
        self::pageFor('fluent-cart', true, ['modalEnabled' => 'yes'], $truthy)->enqueueAssets();
        self::assertFalse($truthy[0]['config']['browser_config']['modalEnabled']);

        $canonical = [];
        self::pageFor('ys-helcim-refunds', true, ['modalEnabled' => true], $canonical)->enqueueAssets();
        self::assertFalse($canonical[0]['config']['browser_config']['modalEnabled']);

        $absent = [];
        self::pageFor('fluent-cart', true, [], $absent)->enqueueAssets();
        self::assertArrayNotHasKey('modalEnabled', $absent[0]['config']['browser_config']);

        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Admin/YSHelcimRefundAdminPage.php'
        );
        foreach (['Refund through Helcim', 'Close'] as $copy) {
            self::assertStringContainsString("'" . $copy . "' => __( '" . $copy . "', 'ys-helcim-via-fluentcart' )", $source);
        }

        // 每個伺服器提供的訊息鍵都必須在 JS 的 messageKeys 白名單內，反之亦然（不在白名單的鍵 JS 會拒用）。
        $script = (string) file_get_contents(
            dirname(__DIR__, 4) . '/assets/js/ys-helcim-refund-admin.js'
        );
        self::assertSame(1, preg_match('/const messageKeys = new Set\(\[(.*?)\]\);/s', $script, $set));
        preg_match_all("/'([A-Za-z]+)'/", $set[1], $keys);
        self::assertEqualsCanonicalizing(array_keys(self::expectedBrowserMessages()), $keys[1]);
    }
}
