import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LiveProgressApp from '../../resources/js/components/LiveProgressApp.vue';

// Mock Echo
const mockListeners = {};
const mockChannel = {
  listen: vi.fn((event, callback) =>
  {
    mockListeners[event] = callback;
    return mockChannel;
  }),
  stopListening: vi.fn(),
};

const mockEcho = {
  channel: vi.fn(() => mockChannel),
  disconnect: vi.fn(),
  connector: {
    pusher: {
      connection: {
        bind: vi.fn(),
      },
    },
  },
};

vi.mock('../../resources/js/echo', () => ({
  initEcho: vi.fn(() => mockEcho),
}));

describe('LiveProgressApp', () =>
{
  beforeEach(() =>
  {
    vi.clearAllMocks();
    for (const key in mockListeners)
    {
      delete mockListeners[key];
    }
  });

  it('renders initial state with prompt preset and submit button', () =>
  {
    const wrapper = mount(LiveProgressApp);
    expect(wrapper.text()).toContain('Submit Creative Brief');
    expect(wrapper.find('textarea').element.value).toContain('15s product teaser');
    expect(wrapper.find('button[type="submit"]').text()).toContain('Generate Shots & Watch Live');
  });

  it('submits brief via POST /briefs, subscribes to Echo, and syncs data', async () =>
  {
    const mockBrief = {
      id: 99,
      prompt: '15s product teaser, rainy Tokyo, neon',
      state: 'pending',
      shots: [],
    };

    global.fetch = vi.fn((url, options) =>
    {
      if (url === '/briefs' && options?.method === 'POST')
      {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, data: { brief: mockBrief } }),
        });
      }
      if (url === '/briefs/99')
      {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, data: { brief: mockBrief } }),
        });
      }
      return Promise.reject(new Error('Unknown endpoint'));
    });

    const wrapper = mount(LiveProgressApp);

    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(global.fetch).toHaveBeenCalledWith('/briefs', expect.objectContaining({ method: 'POST' }));
    expect(mockEcho.channel).toHaveBeenCalledWith('briefs.99');
    expect(wrapper.text()).toContain('Brief #99');
  });

  it('handles incoming broadcast events and updates stage stream and shots', async () =>
  {
    const mockBrief = { id: 77, prompt: 'Sample prompt', state: 'planning', shots: [] };

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, data: { brief: mockBrief } }),
    });

    const wrapper = mount(LiveProgressApp);
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    // 1. BriefStateChanged
    expect(mockListeners['.BriefStateChanged']).toBeDefined();
    mockListeners['.BriefStateChanged']({ from: 'pending', to: 'keyframes' });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('keyframes');

    // 2. ShotUpdated
    expect(mockListeners['.ShotUpdated']).toBeDefined();
    mockListeners['.ShotUpdated']({
      shot: {
        id: 101,
        sequence: 1,
        prompt: 'Shot 1 prompt',
        state: 'generating_image',
        attempts: 1,
      },
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('Shot 1');
    expect(wrapper.text()).toContain('generating image');

    // 3. VideoProgressUpdated
    expect(mockListeners['.VideoProgressUpdated']).toBeDefined();
    mockListeners['.VideoProgressUpdated']({
      briefId: 77,
      shotId: 101,
      progress: 60,
    });
    await wrapper.vm.$nextTick();

    // 4. JudgeVerdictRecorded
    expect(mockListeners['.JudgeVerdictRecorded']).toBeDefined();
    mockListeners['.JudgeVerdictRecorded']({
      briefId: 77,
      shotId: 101,
      stage: 'image',
      verdict: 'fail',
      tag: 'anatomy',
      hardFailTags: ['anatomy'],
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('judging (fail: anatomy)');
  });

  it('displays error message when brief submission fails', async () =>
  {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      json: () => Promise.resolve({ success: false, error: { message: 'Validation failed' } }),
    });

    const wrapper = mount(LiveProgressApp);
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(wrapper.text()).toContain('Validation failed');
  });
});
