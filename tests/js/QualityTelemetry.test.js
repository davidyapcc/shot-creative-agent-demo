import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import QualityTelemetry from '../../resources/js/components/QualityTelemetry.vue';
import { emptyTelemetry } from '../../resources/js/telemetry';

describe('QualityTelemetry', () =>
{
  it('renders dashes and an empty tag list when there is no quality data', () =>
  {
    const wrapper = mount(QualityTelemetry, {
      props: { telemetry: emptyTelemetry() },
    });

    const text = wrapper.text();
    expect(text).toContain('Pass rate');
    expect(text).toContain('—');
    expect(text).toContain('None');
    expect(text).toContain('Queue depth');
    expect(text).toContain('0');
    expect(text).not.toContain('NaN');
  });

  it('renders pass rate, ranked tags, and operational counts', () =>
  {
    const wrapper = mount(QualityTelemetry, {
      props: {
        telemetry: {
          pass_rate: 66.7,
          top_failure_tags: [
            { tag: 'anatomy', count: 2 },
            { tag: 'style', count: 1 },
          ],
          retries_per_brief: 1.7,
          duration_p50_seconds: 40,
          duration_p95_seconds: 160,
          cost_units_per_brief: 4.7,
          queue_depth: 3,
          failed_jobs: 1,
          rate_limit_hits: 4,
        },
      },
    });

    const text = wrapper.text();
    expect(text).toContain('66.7%');
    expect(text).toContain('anatomy');
    expect(text).toContain('style');
    expect(text.indexOf('anatomy')).toBeLessThan(text.indexOf('style'));
    expect(text).toContain('1.7');
    expect(text).toContain('40s');
    expect(text).toContain('160s');
    expect(text).toContain('4.7');
    expect(text).not.toContain('None');
    expect(text).not.toContain('NaN');
  });

  it('renders dashes and zeroes when a metric is not a finite number', () =>
  {
    const wrapper = mount(QualityTelemetry, {
      props: {
        telemetry: {
          pass_rate: 'nope',
          top_failure_tags: null,
          retries_per_brief: undefined,
          duration_p50_seconds: Number.NaN,
          duration_p95_seconds: '',
          cost_units_per_brief: Number.POSITIVE_INFINITY,
          queue_depth: 'nope',
          failed_jobs: null,
          rate_limit_hits: undefined,
        },
      },
    });

    const text = wrapper.text();
    expect(text).toContain('—');
    expect(text).toContain('None');
    expect(text).toContain('0');
    expect(text).not.toContain('NaN');
    expect(text).not.toContain('Infinity');
    expect(text).not.toContain('nope');
  });
});
