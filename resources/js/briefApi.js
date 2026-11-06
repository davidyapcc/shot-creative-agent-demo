export async function postBrief(prompt)
{
  const response = await fetch('/briefs', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ prompt }),
  });

  const json = await response.json();
  if (!response.ok || !json.success)
  {
    throw new Error(json.error?.message || 'Failed to submit brief');
  }

  return json.data.brief;
}

export async function fetchBrief(id)
{
  const response = await fetch(`/briefs/${id}`, {
    headers: { 'Accept': 'application/json' },
  });

  if (!response.ok)
  {
    throw new Error(`Failed to load brief #${id}: ${response.statusText}`);
  }

  const json = await response.json();
  if (!json.success || !json.data?.brief)
  {
    throw new Error(`Failed to load brief #${id}`);
  }

  return json.data.brief;
}

export async function fetchTelemetry()
{
  const response = await fetch('/telemetry', {
    headers: { 'Accept': 'application/json' },
  });

  if (!response.ok)
  {
    throw new Error('Failed to load quality telemetry');
  }

  const json = await response.json();
  if (!json.success || !json.data)
  {
    throw new Error('Failed to load quality telemetry');
  }

  return json.data;
}
