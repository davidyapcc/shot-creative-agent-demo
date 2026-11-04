import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ShotCard from '../../resources/js/components/ShotCard.vue';

describe('ShotCard', () => {
  it('renders shot sequence, prompt and attempt count', () => {
    const wrapper = mount(ShotCard, {
      props: {
        shot: {
          id: 1,
          sequence: 1,
          prompt: 'Neon sign reflecting on rain puddle',
          state: 'pending',
          attempts: 2,
        },
      },
    });

    expect(wrapper.text()).toContain('Shot 1');
    expect(wrapper.text()).toContain('Attempt 2');
    expect(wrapper.text()).toContain('Neon sign reflecting on rain puddle');
  });

  it('renders keyframe image and video clip when paths exist', () => {
    const wrapper = mount(ShotCard, {
      props: {
        shot: {
          id: 1,
          sequence: 1,
          prompt: 'Neon alley',
          state: 'done',
          keyframe_path: 'keyframes/shot_1.png',
          video_path: 'videos/shot_1.mp4',
        },
      },
    });

    const img = wrapper.find('img');
    expect(img.exists()).toBe(true);
    expect(img.attributes('src')).toBe('/media/keyframes/shot_1.png');

    const video = wrapper.find('video');
    expect(video.exists()).toBe(true);
    expect(video.attributes('src')).toBe('/media/videos/shot_1.mp4');
  });

  it('renders video progress when generating video', () => {
    const wrapper = mount(ShotCard, {
      props: {
        shot: {
          id: 1,
          sequence: 1,
          prompt: 'Neon alley',
          state: 'generating_video',
        },
        videoProgress: 40,
      },
    });

    expect(wrapper.text()).toContain('Rendering video 40%');
  });

  it('renders judge failure tags and reason when judgement is fail', () => {
    const wrapper = mount(ShotCard, {
      props: {
        shot: {
          id: 1,
          sequence: 1,
          prompt: 'Neon alley',
          state: 'failed',
          latest_judgement: {
            stage: 'video',
            verdict: 'fail',
            hard_fail_tags: ['anatomy', 'temporal_flicker'],
            reason: 'Distorted hands and severe flicker',
          },
        },
      },
    });

    expect(wrapper.text()).toContain('Judge Verdict: FAIL');
    expect(wrapper.text()).toContain('fail: anatomy');
    expect(wrapper.text()).toContain('fail: temporal_flicker');
    expect(wrapper.text()).toContain('Distorted hands and severe flicker');
  });
});
