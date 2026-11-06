<?php

declare(strict_types=1);

/*
 * Brief streams stay on public channels ("briefs.{id}"), and the quality
 * header listens on the public "telemetry" channel.
 *
 * This demo has no user model and no login. Laravel runs the callbacks
 * in this file only for private- and presence- channels, through
 * /broadcasting/auth. A callback registered here would never run for
 * the public Channel instances the events broadcast on, so none is
 * registered.
 *
 * /media is public for the same reason. That route still limits paths
 * to known prefixes and extensions, and it is rate limited.
 */
