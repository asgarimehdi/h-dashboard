import { afterEach, describe, expect, it, vi } from 'vitest';

// zabbix-gauges.js touches `window` and `document` at module scope, so the
// globals are stubbed BEFORE the dynamic import. The gauge getters themselves
// need no DOM: a bare factory instance is enough.
async function makeGauge() {
  vi.stubGlobal('window', {});
  vi.stubGlobal('document', { addEventListener() {} });

  const { registerSignalGauge } = await import('../../resources/js/zabbix-gauges.js');
  let factory;
  registerSignalGauge({
    data: (name, cb) => {
      if (name === 'signalGauge') factory = cb;
    },
  });

  return factory();
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.resetModules();
});

describe('signalGauge formatting', () => {
  it('displaySignal renders one decimal or an em dash', async () => {
    const gauge = await makeGauge();

    expect(gauge.displaySignal).toBe('—');
    gauge.signal = 50;
    expect(gauge.displaySignal).toBe('50.0');
    gauge.signal = 50.04;
    expect(gauge.displaySignal).toBe('50.0');
  });

  it('displayFrequency renders one decimal or an em dash', async () => {
    const gauge = await makeGauge();

    expect(gauge.displayFrequency).toBe('—');
    gauge.frequency = 2412.54;
    expect(gauge.displayFrequency).toBe('2412.5');
  });

  it('displayResponseTime always returns a string', async () => {
    const gauge = await makeGauge();

    expect(gauge.displayResponseTime).toBe('—');
    gauge.responseTime = 0.0004;
    expect(gauge.displayResponseTime).toBe('0.4');
    gauge.responseTime = 0.0009;
    expect(gauge.displayResponseTime).toBe('0.9');
    gauge.responseTime = 0.001;
    expect(gauge.displayResponseTime).toBe('1');
    gauge.responseTime = 0.0042;
    expect(gauge.displayResponseTime).toBe('4');
    gauge.responseTime = 0.012;
    expect(gauge.displayResponseTime).toBe('12');
  });

  it('responseTimeColorClass follows the 3ms / 7ms thresholds', async () => {
    const gauge = await makeGauge();

    expect(gauge.responseTimeColorClass).toBe('text-base-content');
    gauge.responseTime = 0.002;
    expect(gauge.responseTimeColorClass).toBe('text-success');
    gauge.responseTime = 0.003;
    expect(gauge.responseTimeColorClass).toBe('text-warning');
    gauge.responseTime = 0.007;
    expect(gauge.responseTimeColorClass).toBe('text-warning');
    gauge.responseTime = 0.02;
    expect(gauge.responseTimeColorClass).toBe('text-error');
  });
});

describe('signalGauge dashOffset', () => {
  it('is the full circumference without a signal', async () => {
    const gauge = await makeGauge();

    expect(gauge.dashOffset).toBeCloseTo(2 * Math.PI * 45);
  });

  it('is zero at full signal and clamps above 100', async () => {
    const gauge = await makeGauge();

    gauge.signal = 100;
    expect(gauge.dashOffset).toBeCloseTo(0);

    gauge.signal = 150;
    expect(gauge.dashOffset).toBeCloseTo(0);
  });

  it('is half the circumference at 50%', async () => {
    const gauge = await makeGauge();

    gauge.signal = 50;
    expect(gauge.dashOffset).toBeCloseTo(Math.PI * 45);
  });
});
