<?php

declare(strict_types=1);

namespace MonitorHQ\Exception;

/** The request never produced a response (DNS, connect, TLS or timeout failure). */
class ConnectionException extends MonitorHQException
{
}
