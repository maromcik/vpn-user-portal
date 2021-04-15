<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal;

use DateTimeImmutable;
use fkooman\OAuth\Server\Authorization;
use fkooman\OAuth\Server\Scope;
use fkooman\OAuth\Server\StorageInterface;
use PDO;

class Storage implements StorageInterface
{
    const CURRENT_SCHEMA_VERSION = '2021040902';

    private PDO $db;

    private Migration $migration;

    public function __construct(PDO $db, string $schemaDir)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ('sqlite' === $db->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
        $this->db = $db;
        $this->migration = new Migration($db, $schemaDir, self::CURRENT_SCHEMA_VERSION);
    }

    public function wgAddPeer(string $userId, string $profileId, string $displayName, string $publicKey, string $ipFour, string $ipSix, DateTimeImmutable $createdAt, ?string $clientId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO wg_peers (
                user_id,
                profile_id,
                display_name,
                public_key,
                ip_four,
                ip_six,
                created_at,
                client_id
             )
             VALUES(
                :user_id,
                :profile_id,
                :display_name,
                :public_key,
                :ip_four,
                :ip_six,
                :created_at,
                :client_id
             )'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $createdAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR | PDO::PARAM_NULL);
        $stmt->execute();
    }

    public function wgRemovePeer(string $userId, string $publicKey): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM
                wg_peers
             WHERE
                user_id = :user_id
             AND
                public_key = :public_key'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':public_key', $publicKey, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array<string>
     */
    public function wgGetAllocatedIpFourAddresses(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                ip_four
             FROM wg_peers'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<array{display_name:string,public_key:string,ip_four:string,ip_six:string,created_at:\DateTimeImmutable,client_id:string|null}>
     */
    public function wgGetPeers(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                profile_id,
                display_name,
                public_key,
                ip_four,
                ip_six,
                created_at,
                client_id
             FROM wg_peers
             WHERE
                user_id = :user_id'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $wgPeers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resultRow) {
            $wgPeers[] = [
                'profile_id' => (string) $resultRow['profile_id'],
                'display_name' => (string) $resultRow['display_name'],
                'public_key' => (string) $resultRow['public_key'],
                'ip_four' => (string) $resultRow['ip_four'],
                'ip_six' => (string) $resultRow['ip_six'],
                'created_at' => new DateTimeImmutable($resultRow['created_at']),
                'client_id' => null === $resultRow['client_id'] ? null : (string) $resultRow['client_id'],
            ];
        }

        return $wgPeers;
    }

    /**
     * @return array<array{user_id:string,display_name:string,public_key:string,ip_four:string,ip_six:string,created_at:\DateTimeImmutable,client_id:string|null}>
     */
    public function wgGetAllPeers(string $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                user_id,
                display_name,
                public_key,
                ip_four,
                ip_six,
                created_at,
                client_id
             FROM
                wg_peers
             WHERE
                profile_id = :profile_id'
        );
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->execute();
        $wgPeers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resultRow) {
            $wgPeers[] = [
                'user_id' => (string) $resultRow['user_id'],
                'display_name' => (string) $resultRow['display_name'],
                'public_key' => (string) $resultRow['public_key'],
                'ip_four' => (string) $resultRow['ip_four'],
                'ip_six' => (string) $resultRow['ip_six'],
                'created_at' => new DateTimeImmutable($resultRow['created_at']),
                'client_id' => null === $resultRow['client_id'] ? null : (string) $resultRow['client_id'],
            ];
        }

        return $wgPeers;
    }

    public function localUserExists(string $authUser): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(user_id)
             FROM local_users
             WHERE
                user_id = :user_id'
        );

        $stmt->bindValue(':user_id', $authUser, PDO::PARAM_STR);
        $stmt->execute();

        return 1 === (int) $stmt->fetchColumn();
    }

    public function localUserAdd(string $userId, string $passwordHash, DateTimeImmutable $createdAt): void
    {
        if ($this->localUserExists($userId)) {
            $this->localUserUpdatePassword($userId, $passwordHash);

            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO
                local_users (user_id, password_hash, created_at)
            VALUES
                (:user_id, :password_hash, :created_at)'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $createdAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function localUserUpdatePassword(string $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE
                local_users
             SET
                password_hash = :password_hash
             WHERE
                user_id = :user_id'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function localUserPasswordHash(string $authUser): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT
                password_hash
             FROM local_users
             WHERE
                user_id = :user_id'
        );

        $stmt->bindValue(':user_id', $authUser, PDO::PARAM_STR);
        $stmt->execute();
        $resultColumn = $stmt->fetchColumn(0);

        return \is_string($resultColumn) ? $resultColumn : null;
    }

    public function getAuthorization(string $authKey): ?Authorization
    {
        $stmt = $this->db->prepare(
            'SELECT
                auth_key,
                user_id,
                client_id,
                scope,
                expires_at
             FROM authorizations
             WHERE
                auth_key = :auth_key'
        );

        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->execute();

        /** @var array{auth_key:string,user_id:string,client_id:string,scope:string,expires_at:string}|false */
        $queryResult = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $queryResult) {
            return null;
        }

        return new Authorization(
            $queryResult['auth_key'],
            $queryResult['user_id'],
            $queryResult['client_id'],
            new Scope($queryResult['scope']),
            new DateTimeImmutable($queryResult['expires_at'])
        );
    }

    public function storeAuthorization(string $userId, string $clientId, Scope $scope, string $authKey, DateTimeImmutable $expiresAt): void
    {
        // the "authorizations" table has the UNIQUE constraint on the
        // "auth_key" column, thus preventing multiple entries with the same
        // "auth_key" to make absolutely sure "auth_keys" cannot be replayed
        $stmt = $this->db->prepare(
            'INSERT INTO authorizations (
                auth_key,
                user_id,
                client_id,
                scope,
                expires_at
             )
             VALUES(
                :auth_key,
                :user_id,
                :client_id,
                :scope,
                :expires_at
             )'
        );

        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':scope', (string) $scope, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $expiresAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array<\fkooman\OAuth\Server\Authorization>
     */
    public function getAuthorizations(string $userId): array
    {
        $authorizationList = [];
        $stmt = $this->db->prepare(
            'SELECT
                auth_key,
                user_id,
                client_id,
                scope,
                expires_at
             FROM authorizations
             WHERE
                user_id = :user_id'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        $queryResultSet = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var array{auth_key:string,user_id:string,client_id:string,scope:string,expires_at:string} $queryResult */
        foreach ($queryResultSet as $queryResult) {
            $authorizationList[] = new Authorization(
                $queryResult['auth_key'],
                $queryResult['user_id'],
                $queryResult['client_id'],
                new Scope($queryResult['scope']),
                new DateTimeImmutable($queryResult['expires_at'])
            );
        }

        return $authorizationList;
    }

    public function deleteAuthorization(string $authKey): void
    {
        // this will also cascade into the refresh_token_log table in case
        // there were any stored refresh_token_id entries for this "auth_key"
        $stmt = $this->db->prepare(
            'DELETE FROM
                authorizations
             WHERE
                auth_key = :auth_key'
        );

        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function isRefreshTokenReplay(string $refreshTokenId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(refresh_token_id)
             FROM refresh_token_log
             WHERE
                refresh_token_id = :refresh_token_id'
        );

        $stmt->bindValue(':refresh_token_id', $refreshTokenId, PDO::PARAM_STR);
        $stmt->execute();

        return 1 === (int) $stmt->fetchColumn(0);
    }

    public function logRefreshToken(string $authKey, string $refreshTokenId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO refresh_token_log (
                auth_key,
                refresh_token_id
             )
             VALUES(
                :auth_key,
                :refresh_token_id
             )'
        );

        $stmt->bindValue(':auth_key', $authKey, PDO::PARAM_STR);
        $stmt->bindValue(':refresh_token_id', $refreshTokenId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function deleteExpiredAuthorizations(string $userId, DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM
                authorizations
             WHERE
                user_id = :user_id
             AND
                expires_at < :expires_at'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);

        $stmt->execute();
    }

    public function init(): void
    {
        $this->migration->init();
    }

    public function update(): void
    {
        $this->migration->run();
    }

    /**
     * @return array
     */
    public function getUsers()
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            user_id,
            permission_list,
            is_disabled
        FROM
            users
    SQL
        );
        $stmt->execute();

        $userList = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userList[] = [
                'user_id' => $row['user_id'],
                'permission_list' => Json::decode($row['permission_list']),
                'is_disabled' => (bool) $row['is_disabled'],
            ];
        }

        return $userList;
    }

    /**
     * @return array<string>
     */
    public function getPermissionList(string $userId)
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            permission_list
        FROM
            users
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return Json::decode($stmt->fetchColumn());
    }

    /**
     * @return array
     */
    public function getAppUsage()
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            client_id,
            COUNT(DISTINCT user_id) AS client_count
        FROM
            certificates
        GROUP BY
            client_id
        ORDER BY
            client_count DESC
    SQL
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * XXX remove is_disabled from here? we have a separate call for that.
     *
     * @return false|array
     */
    public function getUserCertificateInfo(string $commonName)
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            u.user_id AS user_id,
            u.is_disabled AS user_is_disabled,
            c.display_name AS display_name,
            c.valid_from,
            c.valid_to,
            c.client_id
        FROM
            users u, certificates c
        WHERE
            u.user_id = c.user_id AND
            c.common_name = :common_name
    SQL
        );

        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function userDelete(string $userId): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        DELETE FROM
            users
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @param array<string> $permissionList
     */
    public function userUpdate(string $userId, array $permissionList): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        UPDATE
            users
        SET
            permission_list = :permission_list
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':permission_list', Json::encode($permissionList), PDO::PARAM_STR);

        $stmt->execute();
    }

    public function addCertificate(string $userId, string $commonName, string $displayName, DateTimeImmutable $validFrom, DateTimeImmutable $validTo, ?string $clientId): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        INSERT INTO certificates
            (common_name, user_id, display_name, valid_from, valid_to, client_id)
        VALUES
            (:common_name, :user_id, :display_name, :valid_from, :valid_to, :client_id)
    SQL
        );
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
        $stmt->bindValue(':valid_from', $validFrom->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':valid_to', $validTo->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR | PDO::PARAM_NULL);
        $stmt->execute();
    }

    public function getCertificates(string $userId): array
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            common_name,
            display_name,
            valid_from,
            valid_to,
            client_id
        FROM
            certificates
        WHERE
            user_id = :user_id
        ORDER BY
            valid_from DESC
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteCertificate(string $commonName): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        DELETE FROM
            certificates
        WHERE
            common_name = :common_name
    SQL
        );
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function deleteCertificatesOfClientId(string $userId, string $clientId): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        DELETE FROM
            certificates
        WHERE
            user_id = :user_id
        AND
            client_id = :client_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function disableUser(string $userId): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        UPDATE
            users
        SET
            is_disabled = 1
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * XXX merge with disableUser.
     */
    public function userEnable(string $userId): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        UPDATE
            users
        SET
            is_disabled = 0
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    public function userExists(string $userId): bool
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            COUNT(user_id)
        FROM
            users
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return 1 === (int) $stmt->fetchColumn(0);
    }

    public function userIsDisabled(string $userId): bool
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            is_disabled
        FROM
            users
        WHERE
            user_id = :user_id
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        // because the user always exists, this will always return something,
        // this is why we don't need to distinguish between a successful fetch
        // or not, a bit ugly!
        return (bool) $stmt->fetchColumn(0);
    }

    public function clientConnect(string $profileId, string $commonName, string $ipFour, string $ipSix, DateTimeImmutable $connectedAt): void
    {
        // update "lost" client entries when a new client connects that gets
        // the IP address of an existing entry that was not "closed" yet. This
        // may occur when the OpenVPN process dies without writing the
        // disconnect event to the log. We fix this when a new client
        // wants to connect and gets this exact same IP address...
        $stmt = $this->db->prepare(
<<< 'SQL'
            UPDATE
                connection_log
            SET
                disconnected_at = :date_time,
                client_lost = 1
            WHERE
                profile_id = :profile_id
            AND
                ip_four = :ip_four
            AND
                ip_six = :ip_six
            AND
                disconnected_at IS NULL
    SQL
        );

        $stmt->bindValue(':date_time', $connectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->execute();

        // this query is so complex, because we want to store the user_id in the
        // log as well, not just the common_name... the user may delete the
        // certificate, or the user account may be deleted...
        $stmt = $this->db->prepare(
<<< 'SQL'
        INSERT INTO connection_log
            (
                user_id,
                profile_id,
                common_name,
                ip_four,
                ip_six,
                connected_at
            )
        VALUES
            (
                (
                    SELECT
                        u.user_id
                    FROM
                        users u, certificates c
                    WHERE
                        u.user_id = c.user_id
                    AND
                        c.common_name = :common_name
                ),
                :profile_id,
                :common_name,
                :ip_four,
                :ip_six,
                :connected_at
            )
    SQL
        );

        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->bindValue(':connected_at', $connectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function clientDisconnect(string $profileId, string $commonName, string $ipFour, string $ipSix, DateTimeImmutable $connectedAt, DateTimeImmutable $disconnectedAt, int $bytesTransferred): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        UPDATE
            connection_log
        SET
            disconnected_at = :disconnected_at,
            bytes_transferred = :bytes_transferred
        WHERE
            profile_id = :profile_id
        AND
            common_name = :common_name
        AND
            ip_four = :ip_four
        AND
            ip_six = :ip_six
        AND
            connected_at = :connected_at
    SQL
        );

        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_STR);
        $stmt->bindValue(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->bindValue(':ip_four', $ipFour, PDO::PARAM_STR);
        $stmt->bindValue(':ip_six', $ipSix, PDO::PARAM_STR);
        $stmt->bindValue(':connected_at', $connectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':disconnected_at', $disconnectedAt->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->bindValue(':bytes_transferred', $bytesTransferred, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function getConnectionLogForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            l.user_id,
            l.common_name,
            l.profile_id,
            l.ip_four,
            l.ip_six,
            l.connected_at,
            l.disconnected_at,
            l.bytes_transferred,
            l.client_lost,
            c.client_id AS client_id
        FROM
            connection_log l,
            certificates c
        WHERE
            l.user_id = :user_id
        AND
            l.common_name = c.common_name
        ORDER BY
            l.connected_at
        DESC
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return false|array
     */
    public function getLogEntry(DateTimeImmutable $dateTime, string $ipAddress)
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            user_id,
            profile_id,
            common_name,
            ip_four,
            ip_six,
            connected_at,
            disconnected_at,
            client_lost
        FROM
            connection_log
        WHERE
            (ip_four = :ip_address OR ip_six = :ip_address)
        AND
            connected_at < :date_time
        AND
            (disconnected_at > :date_time OR disconnected_at IS NULL)
    SQL
        );
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();

        // XXX can this also contain multiple results? I don't think so, but
        // make sure!
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function cleanConnectionLog(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        DELETE FROM
            connection_log
        WHERE
            connected_at < :date_time
        AND
            disconnected_at IS NOT NULL
    SQL
        );

        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * Remove log messages older than specified time.
     */
    public function cleanUserLog(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        DELETE FROM
            user_log
        WHERE
            date_time < :date_time
    SQL
        );

        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * Get all log messages for a particular user.
     */
    public function getUserLog(string $userId): array
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        SELECT
            log_id, log_level, log_message, date_time
        FROM
            user_log
        WHERE
            user_id = :user_id
        ORDER BY
            date_time DESC
    SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addUserLog(string $userId, int $logLevel, string $logMessage, DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        INSERT INTO user_log
            (user_id, log_level, log_message, date_time)
        VALUES
            (:user_id, :log_level, :log_message, :date_time)
    SQL
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':log_level', $logLevel, PDO::PARAM_INT);
        $stmt->bindValue(':log_message', $logMessage, PDO::PARAM_STR);
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function cleanExpiredCertificates(DateTimeImmutable $dateTime): void
    {
        $stmt = $this->db->prepare('DELETE FROM certificates WHERE valid_to < :date_time');
        $stmt->bindValue(':date_time', $dateTime->format(DateTimeImmutable::ATOM), PDO::PARAM_STR);

        $stmt->execute();
    }

    /**
     * @deprecated
     */
    public function getPdo(): PDO
    {
        return $this->db;
    }

    /**
     * @param array<string> $permissionList
     */
    public function userAdd(string $userId, array $permissionList): void
    {
        $stmt = $this->db->prepare(
<<< 'SQL'
        INSERT INTO
            users (
                user_id,
                permission_list,
                is_disabled
            )
        VALUES (
            :user_id,
            :permission_list,
            :is_disabled
        )
    SQL
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':permission_list', Json::encode($permissionList), PDO::PARAM_STR);
        $stmt->bindValue(':is_disabled', false, PDO::PARAM_BOOL);
        $stmt->execute();
    }
}
