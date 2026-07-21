import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { describe, expect, it } from 'vitest';

const modalScriptPath = fileURLToPath(
  new URL('../../assets/js/ys-helcim-pay-checkout.js', import.meta.url),
);

describe('JavaScript test harness', () => {
  it('loads a fresh DOM and the shipped modal script source', () => {
    const dom = new JSDOM('<!doctype html><html><body></body></html>');
    const source = readFileSync(modalScriptPath, 'utf8');

    expect(dom.window.document.body).not.toBeNull();
    expect(source).toContain("'use strict'");
  });
});
