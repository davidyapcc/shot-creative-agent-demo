import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Echo from 'laravel-echo';
import { initEcho } from '../../resources/js/echo';

vi.mock('laravel-echo', () => ({
  default: vi.fn().mockImplementation(function (options)
  {
    this.options = options;
  }),
}));

vi.mock('pusher-js', () => ({
  default: vi.fn(),
}));

describe('initEcho', () =>
{
  beforeEach(() =>
  {
    window.__CONFIG__ = {};
    Echo.mockClear();
  });

  afterEach(() =>
  {
    delete window.__CONFIG__;
  });

  it('throws at startup when the Reverb app key is missing', () =>
  {
    expect(() => initEcho()).toThrow(/Reverb app key is not configured/);
    expect(Echo).not.toHaveBeenCalled();
  });

  it('constructs Echo when the key is configured', () =>
  {
    window.__CONFIG__ = {
      reverbAppKey: 'test-key',
      reverbHost: 'localhost',
      reverbPort: 8080,
      reverbScheme: 'http',
    };

    initEcho();

    expect(Echo).toHaveBeenCalledWith(expect.objectContaining({
      key: 'test-key',
      wsHost: 'localhost',
    }));
  });
});
