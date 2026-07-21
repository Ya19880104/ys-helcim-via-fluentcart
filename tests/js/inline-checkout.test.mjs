import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const inlineScriptPath = fileURLToPath(
  new URL('../../assets/js/ys-helcim-js-checkout.js', import.meta.url),
);

describe('Helcim.js developer-account contract', () => {
  it('never creates or mutates the deprecated SDK test field', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).not.toContain("createHidden('test'");
    expect(source).not.toContain('paymentData.test_mode');
    expect(source).not.toContain("getElementById('test')");
  });

  it('forwards the server-signed transaction confirmation token', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain("body.append('confirm_token', paymentData.confirm_token || '')");
  });

  it('normalizes the displayed card number before invoking the SDK', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain('numberInput.value = validation.cardDigits;');
  });

  it('handles a rejected SDK promise immediately instead of waiting for the poll timeout', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain('Promise.resolve(window.helcimProcess()).catch(function (err)');
  });

  it('checks the SDK before creating an order and preserves an active tokenize container', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');
    const payStart = source.indexOf('function onPayClick(detail)');
    const sdkCheck = source.indexOf("typeof window.helcimProcess !== 'function'", payStart);
    const orderCall = source.indexOf('Promise.resolve(detail.orderHandler())', payStart);
    const loadStart = source.indexOf('function onLoadPayments(e)');
    const processingGuard = source.indexOf('if (state.processing)', loadStart);
    const containerRewrite = source.indexOf("container.innerHTML = '<p", loadStart);

    expect(sdkCheck).toBeGreaterThan(payStart);
    expect(sdkCheck).toBeLessThan(orderCall);
    expect(processingGuard).toBeGreaterThan(loadStart);
    expect(processingGuard).toBeLessThan(containerRewrite);
  });

  it('serializes only the minimum response-hash proof fields to the merchant server', () => {
    const source = readFileSync(inlineScriptPath, 'utf8');

    expect(source).toContain("var proofFieldNames = ['response', 'cardNumber', 'cardToken', 'hash', 'xmlHash'];");
    expect(source).toContain("body.append('response_fields', JSON.stringify(proofFields));");
  });
});
