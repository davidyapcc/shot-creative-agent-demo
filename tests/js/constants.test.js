import { describe, it, expect } from 'vitest';
import { BriefState, ShotState } from '../../resources/js/constants';

describe('pipeline states', () =>
{
  it('uses the PHP enum values', () =>
  {
    expect(ShotState.DONE).toBe('done');
    expect(ShotState.COMPLETED).toBeUndefined();
    expect(BriefState.DEGRADED).toBe('degraded');
    expect(BriefState.DONE).toBe('done');
  });
});
