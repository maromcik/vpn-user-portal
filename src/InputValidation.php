<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SURFnet\VPN\Portal;

use SURFnet\VPN\Common\Http\Exception\HttpException;

class InputValidation
{
    public static function displayName($displayName)
    {
        self::validateString($displayName);

        if (64 < mb_strlen($displayName)) {
            throw new HttpException('invalid displayName (too long)', 400);
        }
        if (0 === preg_match('/^[a-zA-Z0-9-_.@]+$/', $displayName)) {
            throw new HttpException('invalid displayName (invalid characters)', 400);
        }
    }

    public static function commonName($commonName)
    {
        self::validateString($commonName);

        if (64 < mb_strlen($commonName)) {
            throw new HttpException('invalid commonName (too long)', 400);
        }
        if (0 === preg_match('/^[a-fA-F0-9]+$/', $commonName)) {
            throw new HttpException('invalid commonName (invalid characters)', 400);
        }
    }

    public static function profileId($profileId)
    {
        self::validateString($profileId);

        if (1 !== preg_match('/^[a-zA-Z0-9]+$/', $profileId)) {
            throw new HttpException('invalid profileId (invalid characters)', 400);
        }
    }

    public static function setLanguage($setLanguage)
    {
        self::validateString($setLanguage);

        $supportedLanguages = ['en_US', 'nl_NL', 'de_DE', 'fr_FR'];
        if (!in_array($setLanguage, $supportedLanguages)) {
            throw new HttpException('invalid setLanguage (not supported)', 400);
        }
    }

    public static function confirmDisable($confirmDisable)
    {
        self::validateString($confirmDisable);

        if (!in_array($confirmDisable, ['yes', 'no'])) {
            throw new HttpException('invalid confirmDisable (not supported)', 400);
        }
    }

    private static function validateString($input)
    {
        if (!is_string($input)) {
            throw new HttpException('parameter must be string', 400);
        }
        if (0 >= mb_strlen($input)) {
            throw new HttpException('parameter must be non-empty string', 400);
        }
    }

    public static function otpSecret($otpSecret)
    {
        if (0 === preg_match('/^[A-Z0-9]{16}$/', $otpSecret)) {
            throw new HttpException('invalid OTP secret format', 400);
        }
    }

    public static function otpKey($otpKey)
    {
        if (0 === preg_match('/^[0-9]{6}$/', $otpKey)) {
            throw new HttpException('invalid OTP key format', 400);
        }
    }

    public static function clientId($clientId)
    {
        if (0 === preg_match('/^(?:[\x20-\x7E])+$/', $clientId)) {
            throw new HttpException('invalid client_id', 400);
        }
    }
}
