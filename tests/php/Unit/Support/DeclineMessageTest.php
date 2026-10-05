<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YangSheep\Helcim\FluentCart\Support\YSHelcimDeclineMessage;

final class DeclineMessageTest extends TestCase
{
    /**
     * Helcim's documented test-decline texts (devdocs "Testing Payment Declines and
     * CVV Responses") plus the decline observed in production.
     *
     * @return array<string, array{string, string}>
     */
    public static function helcimDeclines(): array
    {
        return [
            'cvv 200' => ['Transaction Declined: DECLINE CVF2 - Do not honor due to CVF2 mismatch\\failure', 'cvv'],
            'cvv2 wording' => ['Transaction Declined: DECLINE CVV2 - Do not honor due to CVV2 mismatch/failure', 'cvv'],
            'pick up 201' => ['Transaction Declined: PICK UP CARD - Pick up card', 'issuer'],
            'amount error 202' => ['Transaction Declined: AMOUNT ERROR - Tran Amount Error', 'store'],
            'service limit 203' => ['Transaction Declined: AMT OVER SVC LMT - Amount is more than established service limit', 'store'],
            'appl type 204' => ['Transaction Declined: APPL TYPE ERROR - Call support for help with this error', 'store'],
            'cannot convert 205' => ['Transaction Declined: CANNOT CONVERT - Check is ok, but cannot convert. Do Not Honor', 'issuer'],
            'declined t4 206' => ['Transaction Declined: DECLINED T4 - Do Not Honor. Failed negative check, unpaid items', 'issuer'],
            'system error 207' => ['Transaction Declined: DECLINED-HELP 9999 - System Error', 'retry'],
            'duplicate check 208' => ['Transaction Declined: DUP CHECK NBR - Duplicate Check Number', 'store'],
            'do not honor 209' => ['Transaction Declined: DECLINED - Do Not Honor', 'issuer'],
            'expired 210' => ['Transaction Declined: EXPIRED CARD - Expired Card', 'expired'],
            'invalid card 212' => ['Transaction Declined: INVALID CARD - Invalid Card', 'invalid_card'],
            'invalid cavv 213' => ['Transaction Declined: INVALID CAVV - Invalid Cardholder Authentication Verification Value', 'generic'],
            'invalid terminal 214' => ['Transaction Declined: INVALID TERM ID - Invalid Terminal ID', 'store'],
            'network error 222' => ['Transaction Declined: NETWORK ERROR - General System Error', 'retry'],
            'please retry 223' => ['Transaction Declined: PLEASE RETRY - Please Retry/Reenter Transaction', 'retry'],
            'exceeds balance 225' => ['Transaction Declined: REQ. EXCEEDS BAL. - Req. exceeds balance', 'funds'],
            'service not allowed 227' => ['Transaction Declined: SERV NOT ALLOWED - Invalid request', 'issuer'],
            'refer to issuer 229' => ['Transaction Declined: CALL AUTH. CENTER - Refer to Issuer', 'issuer'],
            'production fraud screen' => ['Transaction Declined: SUSPECTED FRAUD', 'issuer'],
            'insufficient funds wording' => ['Transaction Declined: INSUFFICIENT FUNDS', 'funds'],
            'avs wording' => ['Transaction Declined: AVS MISMATCH', 'address'],
            'bare decline' => ['Transaction Declined: DECLINED', 'generic'],
            'hosted window prefix' => ['HelcimPay.js transaction failed - Transaction Declined: EXPIRED CARD - Expired Card', 'expired'],
            'expiry date wording' => ['Invalid Expiry Date', 'expired'],
            'session expiry is not a card expiry' => ['HelcimPay.js transaction failed - Checkout session expired', 'generic'],
        ];
    }

    #[DataProvider('helcimDeclines')]
    public function testHelcimDeclineTextMapsToOneActionableCategory(string $text, string $expected): void
    {
        self::assertSame($expected, YSHelcimDeclineMessage::category($text));
    }

    public function testMissingOrUnusableTextIsAGenericDecline(): void
    {
        self::assertSame('generic', YSHelcimDeclineMessage::category(null));
        self::assertSame('generic', YSHelcimDeclineMessage::category(''));
        self::assertSame('generic', YSHelcimDeclineMessage::category("  \n\t "));
        self::assertNull(YSHelcimDeclineMessage::reason(['Transaction Declined: DECLINED']));
    }

    public function testEveryShopperMessageStatesNoPaymentWasTakenAndNeverMentionsFraud(): void
    {
        $messages = [];
        foreach (YSHelcimDeclineMessage::categories() as $category) {
            $message = YSHelcimDeclineMessage::shopperMessage($category);
            self::assertStringContainsString('no payment was taken', strtolower($message), $category);
            self::assertStringNotContainsStringIgnoringCase('fraud', $message, $category);
            $messages[$category] = $message;
        }

        self::assertSame('generic', YSHelcimDeclineMessage::categories()[count($messages) - 1]);
        self::assertCount(count($messages), array_unique($messages));
        self::assertSame(
            YSHelcimDeclineMessage::shopperMessage('generic'),
            YSHelcimDeclineMessage::shopperMessage('not-a-category')
        );
    }

    public function testReasonIsOneBoundedLineForTheOrderLog(): void
    {
        self::assertSame(
            'Transaction Declined: SUSPECTED FRAUD extra',
            YSHelcimDeclineMessage::reason("Transaction Declined: SUSPECTED FRAUD\r\nextra")
        );
        self::assertSame(300, strlen((string) YSHelcimDeclineMessage::reason(str_repeat('A', 600))));
    }

    public function testReasonStripsMarkupAndMasksCardNumbersBeforeItReachesTheOrderLog(): void
    {
        // FluentCart 後台以 innerHTML 呈現訂單活動紀錄：Helcim 原文不得帶任何標籤或卡號進去。
        $reason = YSHelcimDeclineMessage::reason(
            'Transaction Declined: <a href="https://evil.test/">call us</a><img src=x onerror=alert(1)> card 4111 1111 1111 1111 api-token=abc123'
        );

        self::assertIsString($reason);
        self::assertStringNotContainsString('<', $reason);
        self::assertStringNotContainsString('href', $reason);
        self::assertStringNotContainsString('onerror', $reason);
        self::assertStringNotContainsString('4111', $reason);
        self::assertStringNotContainsString('abc123', $reason);
        self::assertStringContainsString('Transaction Declined: call us', $reason);
        self::assertStringContainsString('[redacted-number]', $reason);
        self::assertNull(YSHelcimDeclineMessage::reason('<img src=x onerror=alert(1)>'));
        self::assertSame('issuer', YSHelcimDeclineMessage::category('Transaction Declined: <b>SUSPECTED FRAUD</b>'));
    }

    public function testCheckoutScriptsMirrorTheServerPatternsInTheSameOrder(): void
    {
        $server = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Support/YSHelcimDeclineMessage.php');
        preg_match_all("/'([a-z_]+)'\\s*=>\\s*'(\\/[^']+\\/i)',/", $server, $serverMatches, PREG_SET_ORDER);
        $expected = array_map(static fn (array $match): array => [$match[1], $match[2]], $serverMatches);
        self::assertCount(8, $expected);

        foreach (['ys-helcim-js-checkout.js', 'ys-helcim-pay-checkout.js'] as $script) {
            $source = (string) file_get_contents(dirname(__DIR__, 4) . '/assets/js/' . $script);
            preg_match_all("/\\['([a-z_]+)', (\\/.+\\/i)\\]/", $source, $scriptMatches, PREG_SET_ORDER);
            $actual = array_map(static fn (array $match): array => [$match[1], $match[2]], $scriptMatches);
            self::assertSame($expected, $actual, $script);
        }
    }
}
