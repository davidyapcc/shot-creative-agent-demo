import { onBeforeUnmount, onMounted } from 'vue';
import { initEcho } from '../echo';
import { listenToBriefChannel, stopBriefChannel } from '../broadcastEvents';

export function useBriefChannel(reportError)
{
  let echo = null;
  let activeChannel = null;
  let telemetryChannel = null;

  onMounted(() =>
  {
    setupEcho();
  });

  onBeforeUnmount(() =>
  {
    stopBriefChannel(activeChannel);
    stopBriefChannel(telemetryChannel);
    if (echo)
    {
      echo.disconnect();
    }
  });

  function setupEcho()
  {
    try
    {
      echo = initEcho();
    }
    catch (error)
    {
      console.error('Failed to initialize Echo:', error);
      reportError(error);
    }
  }

  function subscribe(briefId, handlers)
  {
    activeChannel = listen(`briefs.${briefId}`, handlers, activeChannel);
  }

  function subscribeTelemetry(handlers)
  {
    telemetryChannel = listen('telemetry', handlers, telemetryChannel);
  }

  function listen(name, handlers, current)
  {
    if (!echo)
    {
      setupEcho();
    }

    if (!echo)
    {
      return current;
    }

    stopBriefChannel(current);
    const channel = echo.channel(name);
    listenToBriefChannel(channel, handlers);

    return channel;
  }

  return { subscribe, subscribeTelemetry };
}
