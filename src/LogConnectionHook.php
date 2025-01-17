<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\LogConfig;

/**
 * Write to (sys)logger on connect/disconnect events.
 */
class LogConnectionHook implements ConnectionHookInterface
{
    private LoggerInterface $logger;
    private LogConfig $logConfig;

    public function __construct(LoggerInterface $logger, LogConfig $logConfig)
    {
        $this->logger = $logger;
        $this->logConfig = $logConfig;
    }

    public function connect(string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp): void
    {
        $this->logger->info(
            self::logConnect($userId, $profileId, $connectionId, $ipFour, $ipSix, $originatingIp)
        );
    }

    public function disconnect(string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): void
    {
        $this->logger->info(
            self::logDisconnect($userId, $profileId, $connectionId, $ipFour, $ipSix)
        );
    }

    private function logConnect(string $userId, string $profileId, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp): string
    {
        if (!$this->logConfig->originatingIp() || null === $originatingIp) {
            $originatingIp = '*';
        }

        return sprintf(
            'CONNECT %s (%s:%s) [%s => %s,%s]',
            $userId,
            $profileId,
            $connectionId,
            $originatingIp,
            $ipFour,
            $ipSix
        );
    }

    private function logDisconnect(string $userId, string $profileId, string $connectionId, string $ipFour, string $ipSix): string
    {
        return sprintf(
            'DISCONNECT %s (%s:%s)',
            $userId,
            $profileId,
            $connectionId
        );
    }
}
