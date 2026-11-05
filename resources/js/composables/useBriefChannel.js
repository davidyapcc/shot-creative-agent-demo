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
    if (!echo)
    {
      setupEcho();
    }

    if (!echo)
    {
      return;
    }

    stopBriefChannel(activeChannel);
    activeChannel = echo.channel(`briefs.${briefId}`);
    listenToBriefChannel(activeChannel, handlers);
  }

  function subscribeTelemetry(handlers)
  {
    if (!echo)
    {
      setupEcho();
    }

    if (!echo)
    {
      return;
    }

    stopBriefChannel(telemetryChannel);
    telemetryChannel = echo.channel('telemetry');
    listenToBriefChannel(telemetryChannel, handlers);
  }

  return { subscribe, subscribeTelemetry };
}
