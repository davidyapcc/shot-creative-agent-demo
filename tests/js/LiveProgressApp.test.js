import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LiveProgressApp from '../../resources/js/components/LiveProgressApp.vue';
import { initEcho } from '../../resources/js/echo';

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

const EMPTY_TELEMETRY = {
  pass_rate: null,
  top_failure_tags: [],
  retries_per_brief: null,
  duration_p50_seconds: null,
  duration_p95_seconds: null,
  cost_units_per_brief: null,
  queue_depth: 0,
  failed_jobs: 0,
  rate_limit_hits: 0,
};

function telemetryResponse(data = EMPTY_TELEMETRY)
{
  return Promise.resolve({
    ok: true,
    json: () => Promise.resolve({ success: true, data }),
  });
}

function mockFetch(impl)
{
  global.fetch = vi.fn((url, options) =>
  {
    if (url === '/telemetry')
    {
      return telemetryResponse();
    }

    return impl(url, options);
  });
}

vi.mock('../../resources/js/echo', () => ({
  initEcho: vi.fn(() => mockEcho),
}));

async function submitPrompt(wrapper, prompt = '15s product teaser, rainy Tokyo, neon')
{
  await wrapper.find('textarea').setValue(prompt);
  await wrapper.find('form').trigger('submit.prevent');
}

describe('LiveProgressApp', () =>
{
  beforeEach(() =>
  {
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/');
    for (const key in mockListeners)
    {
      delete mockListeners[key];
    }

    mockFetch(() => Promise.reject(new Error('Unknown endpoint')));
  });

  it('renders an empty brief form and a submit button', () =>
  {
    const wrapper = mount(LiveProgressApp);
    expect(wrapper.text()).toContain('Submit Creative Brief');
    expect(wrapper.find('textarea').element.value).toBe('');
    expect(wrapper.find('textarea').attributes('placeholder')).toContain('15s product teaser');
    expect(wrapper.find('button[type="submit"]').text()).toContain('Generate Shots & Watch Live');
    expect(wrapper.text()).not.toContain('Preset Examples');
    expect(wrapper.text()).not.toContain('Final Assembly');
  });

  it('submits brief via POST /briefs, subscribes to Echo, and syncs data', async () =>
  {
    const mockBrief = {
      id: 99,
      prompt: '15s product teaser, rainy Tokyo, neon',
      state: 'pending',
      shots: [],
    };

    mockFetch((url, options) =>
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

    await submitPrompt(wrapper);
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
    await submitPrompt(wrapper);
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
      hardFailTags: ['anatomy'],
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('video progress 60%');
    expect(wrapper.text()).toContain('video 60%');
    expect(wrapper.text()).toContain('judging (fail: anatomy)');
    expect(wrapper.text()).not.toContain('regenerating');
  });

  it('shows regenerating while a failed shot is generated again', async () =>
  {
    const mockBrief = { id: 77, prompt: 'Sample prompt', state: 'keyframes', shots: [] };

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, data: { brief: mockBrief } }),
    });

    const wrapper = mount(LiveProgressApp);
    await submitPrompt(wrapper);
    await flushPromises();

    mockListeners['.ShotUpdated']({
      shot: {
        id: 101,
        sequence: 1,
        prompt: 'Shot 1 prompt',
        state: 'generating_image',
        attempts: 2,
      },
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('regenerating');
    expect(wrapper.text()).toContain('Attempt 2');
  });

  it('displays error message when brief submission fails', async () =>
  {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      json: () => Promise.resolve({ success: false, error: { message: 'Validation failed' } }),
    });

    const wrapper = mount(LiveProgressApp);
    await submitPrompt(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain('Validation failed');
  });

  it('restores the event log and per-shot progress when the page reloads mid-run', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');

    const brief = {
      id: 55,
      prompt: 'reload me',
      state: 'rendering',
      shots: [
        {
          id: 9,
          sequence: 1,
          prompt: 'Finished shot',
          state: 'done',
          video_path: 'videos/a.mp4',
          attempts: 1,
          judgements: [],
        },
        {
          id: 10,
          sequence: 2,
          prompt: 'Rendering shot',
          state: 'generating_video',
          attempts: 1,
          metadata: { video_progress: 50 },
          judgements: [
            {
              id: 4,
              stage: 'image',
              verdict: 'fail',
              hard_fail_tags: ['anatomy'],
            },
          ],
        },
      ],
    };

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, data: { brief } }),
    });

    const wrapper = mount(LiveProgressApp);
    await flushPromises();

    expect(mockEcho.channel).toHaveBeenCalledWith('briefs.55');
    expect(wrapper.text()).toContain('Brief state is rendering');
    expect(wrapper.text()).toContain('Shot #1 state is done');
    expect(wrapper.text()).toContain('Shot #1 video progress 100%');
    expect(wrapper.text()).toContain('Shot #2 video progress 50%');
    expect(wrapper.text()).not.toContain('Shot #9 video progress');
    expect(wrapper.text()).toContain('Rendering video 50%');
    expect(wrapper.text()).toContain('video 75%');
    expect(wrapper.text()).toContain('judging (fail: anatomy)');
    expect(wrapper.text()).toContain('fail: anatomy');
  });

  it('drops shots the server no longer returns', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');

    const shot = {
      id: 1,
      sequence: 1,
      prompt: 'Old shot',
      state: 'generating_image',
      attempts: 1,
    };

    const briefFetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          data: { brief: { id: 55, state: 'keyframes', shots: [shot] } },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          data: { brief: { id: 55, state: 'audio', shots: [] } },
        }),
      });
    mockFetch((url, options) => briefFetch(url, options));

    const wrapper = mount(LiveProgressApp);
    await flushPromises();

    expect(wrapper.text()).toContain('Shot 1');

    mockListeners['.BriefStateChanged']({ from: 'keyframes', to: 'audio' });
    await flushPromises();

    expect(wrapper.text()).toContain('No shots planned yet');
  });

  it('keeps a shot that arrives while the reload request is still open', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');

    let resolveFetch;
    mockFetch(() => new Promise((resolve) =>
    {
      resolveFetch = resolve;
    }));

    const wrapper = mount(LiveProgressApp);
    await wrapper.vm.$nextTick();

    expect(mockListeners['.ShotUpdated']).toBeDefined();
    mockListeners['.ShotUpdated']({
      shot: {
        id: 7,
        sequence: 1,
        prompt: 'Live shot',
        state: 'generating_image',
        attempts: 1,
      },
    });
    await wrapper.vm.$nextTick();

    resolveFetch({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: { brief: { id: 55, state: 'keyframes', shots: [] } },
      }),
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Shot 1');
    expect(wrapper.text()).toContain('Brief state is keyframes');
  });

  it('keeps a newer shot update that arrives while a reload is in flight', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');

    const staleShot = {
      id: 1,
      sequence: 1,
      prompt: 'Old shot',
      state: 'generating_image',
      attempts: 1,
    };

    let resolveSecondFetch;
    const briefFetch = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({
          success: true,
          data: { brief: { id: 55, state: 'rendering', shots: [staleShot] } },
        }),
      })
      .mockImplementationOnce(() => new Promise((resolve) =>
      {
        resolveSecondFetch = resolve;
      }));
    mockFetch((url, options) => briefFetch(url, options));

    const wrapper = mount(LiveProgressApp);
    await flushPromises();

    mockListeners['.BriefStateChanged']({ from: 'rendering', to: 'audio' });
    await wrapper.vm.$nextTick();

    mockListeners['.ShotUpdated']({
      shot: {
        id: 1,
        sequence: 1,
        prompt: 'Old shot',
        state: 'judging_video',
        attempts: 1,
      },
    });
    await wrapper.vm.$nextTick();

    resolveSecondFetch({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: { brief: { id: 55, state: 'audio', shots: [staleShot] } },
      }),
    });
    await flushPromises();

    expect(wrapper.text()).toContain('judging video');
    expect(wrapper.text()).not.toContain('generating image');
  });

  it('shows an error when Echo cannot start', async () =>
  {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    initEcho.mockImplementationOnce(() =>
    {
      throw new Error('Reverb app key is not configured.');
    });

    const wrapper = mount(LiveProgressApp);
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('Reverb app key is not configured.');
    expect(errorSpy).toHaveBeenCalled();
    errorSpy.mockRestore();
  });

  it('still loads a brief when Echo cannot start', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    initEcho.mockImplementation(() =>
    {
      throw new Error('Reverb app key is not configured.');
    });

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: {
          brief: {
            id: 55,
            state: 'planning',
            shots: [],
          },
        },
      }),
    });

    try
    {
      const wrapper = mount(LiveProgressApp);
      await flushPromises();

      expect(wrapper.text()).toContain('Reverb app key is not configured.');
      expect(wrapper.text()).toContain('Brief state is planning');
      expect(mockEcho.channel).not.toHaveBeenCalled();
    }
    finally
    {
      initEcho.mockImplementation(() => mockEcho);
      errorSpy.mockRestore();
    }
  });

  it('reports a failed reload', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=9');
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      statusText: 'Not Found',
      json: () => Promise.resolve({}),
    });

    const wrapper = mount(LiveProgressApp);
    await flushPromises();

    expect(wrapper.text()).toContain('Failed to load brief #9: Not Found');
    expect(errorSpy).toHaveBeenCalled();
    errorSpy.mockRestore();
  });

  it('ignores a stale brief snapshot after a newer state event', async () =>
  {
    window.history.replaceState({}, '', '/?brief_id=55');

    let resolveFirst;
    let resolveSecond;
    let calls = 0;
    mockFetch(() => new Promise((resolve) =>
    {
      calls += 1;
      if (calls === 1)
      {
        resolveFirst = resolve;
      }
      else
      {
        resolveSecond = resolve;
      }
    }));

    const wrapper = mount(LiveProgressApp);
    await wrapper.vm.$nextTick();

    mockListeners['.BriefStateChanged']({ from: 'rendering', to: 'audio' });
    await wrapper.vm.$nextTick();

    resolveFirst({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: { brief: { id: 55, state: 'planning', shots: [] } },
      }),
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Transitioned from rendering to audio');
    expect(wrapper.text()).not.toContain('Brief state is planning');

    resolveSecond({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: { brief: { id: 55, state: 'audio', shots: [] } },
      }),
    });
    await flushPromises();

    expect(wrapper.text()).not.toContain('Brief state is planning');
  });

  it('shows quality telemetry above the shot cards and updates it live', async () =>
  {
    const wrapper = mount(LiveProgressApp);
    await flushPromises();

    const quality = wrapper.get('[aria-label="Quality telemetry"]');
    const columnChildren = [...quality.element.parentElement.children];
    expect(columnChildren[0]).toBe(quality.element);
    expect(columnChildren[1].textContent).toContain('Shot Cards');
    expect(wrapper.get('[aria-label="Quality telemetry"]').text()).toContain('None');
    expect(wrapper.get('[aria-label="Quality telemetry"]').text()).toContain('—');
    expect(mockEcho.channel).toHaveBeenCalledWith('telemetry');
    expect(global.fetch).toHaveBeenCalledWith('/telemetry', expect.any(Object));

    mockListeners['.TelemetryUpdated']({
      success: true,
      data: {
        pass_rate: 94,
        top_failure_tags: [
          { tag: 'anatomy', count: 2 },
          { tag: 'flicker', count: 2 },
        ],
        retries_per_brief: 1.7,
        duration_p50_seconds: 40,
        duration_p95_seconds: 160,
        cost_units_per_brief: 4.7,
        queue_depth: 3,
        failed_jobs: 1,
        rate_limit_hits: 4,
      },
    });
    await wrapper.vm.$nextTick();

    const updated = wrapper.get('[aria-label="Quality telemetry"]').text();
    expect(updated).toContain('94%');
    expect(updated).toContain('anatomy');
    expect(updated).toContain('flicker');
    expect(updated).toContain('1.7');
    expect(updated).toContain('40s');
    expect(updated).toContain('160s');
    expect(updated).toContain('4.7');
    expect(updated).not.toContain('None');
  });

  it('keeps a telemetry event that arrives while the first load is open', async () =>
  {
    let resolveTelemetry;
    global.fetch = vi.fn((url) =>
    {
      if (url === '/telemetry')
      {
        return new Promise((resolve) =>
        {
          resolveTelemetry = resolve;
        });
      }

      return Promise.reject(new Error('Unknown endpoint'));
    });

    const wrapper = mount(LiveProgressApp);
    await wrapper.vm.$nextTick();

    mockListeners['.TelemetryUpdated']({
      success: true,
      data: {
        pass_rate: 94,
        top_failure_tags: [{ tag: 'anatomy', count: 2 }],
        retries_per_brief: 1.7,
        duration_p50_seconds: 40,
        duration_p95_seconds: 160,
        cost_units_per_brief: 4.7,
        queue_depth: 3,
        failed_jobs: 1,
        rate_limit_hits: 4,
      },
    });
    await wrapper.vm.$nextTick();

    resolveTelemetry({
      ok: true,
      json: () => Promise.resolve({ success: true, data: EMPTY_TELEMETRY }),
    });
    await flushPromises();

    expect(wrapper.get('[aria-label="Quality telemetry"]').text()).toContain('94%');
  });
});
