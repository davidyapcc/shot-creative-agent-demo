import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StageStream from '../../resources/js/components/StageStream.vue';

describe('StageStream', () => {
  it('renders standard stage sequence chips', () => {
    const wrapper = mount(StageStream, {
      props: {
        briefState: 'planning',
        videoProgress: 0,
        judgeFailureTag: null,
      },
    });

    const text = wrapper.text();
    expect(text).toContain('planning');
    expect(text).toContain('keyframes');
    expect(text).toContain('judging');
    expect(text).toContain('video');
    expect(text).toContain('audio');
    expect(text).toContain('muxing');
    expect(text).toContain('done');
  });

  it('renders judge failure tag in stream when judge fails', () => {
    const wrapper = mount(StageStream, {
      props: {
        briefState: 'failed',
        judgeFailureTag: 'anatomy',
      },
    });

    expect(wrapper.text()).toContain('judging (fail: anatomy)');
  });

  it('renders video progress percentage when rendering', () => {
    const wrapper = mount(StageStream, {
      props: {
        briefState: 'rendering',
        videoProgress: 40,
      },
    });

    expect(wrapper.text()).toContain('video 40%');
  });

  it('renders broadcast stream events list', () => {
    const wrapper = mount(StageStream, {
      props: {
        briefState: 'keyframes',
        events: [
          { name: 'BriefStateChanged', detail: 'Transitioned from pending to planning', time: '12:00:00' },
          { name: 'ShotUpdated', detail: 'Shot #1 state is generating_image', time: '12:00:01' },
        ],
      },
    });

    expect(wrapper.text()).toContain('BriefStateChanged');
    expect(wrapper.text()).toContain('ShotUpdated');
    expect(wrapper.text()).toContain('2 events received');
  });
});
