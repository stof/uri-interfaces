<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri;

use League\Uri\Exceptions\IdnaConversionFailed;
use League\Uri\Exceptions\IdnSupportMissing;
use League\Uri\Exceptions\SyntaxError;
use function defined;
use function function_exists;
use function idn_to_ascii;
use function idn_to_utf8;
use function rawurldecode;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * @see https://unicode-org.github.io/icu-docs/apidoc/released/icu4c/uidna_8h.html
 */
final class Idna
{
    private const REGEXP_IDNA_PATTERN = '/[^\x20-\x7f]/';
    private const MAX_DOMAIN_LENGTH = 255;

    /**
     * IDNA options.
     */
    public const IDNA_DEFAULT                    = 0;
    public const IDNA_ALLOW_UNASSIGNED           = 1;
    public const IDNA_USE_STD3_RULES             = 2;
    public const IDNA_CHECK_BIDI                 = 4;
    public const IDNA_CHECK_CONTEXTJ             = 8;
    public const IDNA_NONTRANSITIONAL_TO_ASCII   = 0x10;
    public const IDNA_NONTRANSITIONAL_TO_UNICODE = 0x20;
    public const IDNA_CHECK_CONTEXTO             = 0x40;

    /**
     * IDNA errors.
     */
    public const ERROR_EMPTY_LABEL            = 1;
    public const ERROR_LABEL_TOO_LONG         = 2;
    public const ERROR_DOMAIN_NAME_TOO_LONG   = 4;
    public const ERROR_LEADING_HYPHEN         = 8;
    public const ERROR_TRAILING_HYPHEN        = 0x10;
    public const ERROR_HYPHEN_3_4             = 0x20;
    public const ERROR_LEADING_COMBINING_MARK = 0x40;
    public const ERROR_DISALLOWED             = 0x80;
    public const ERROR_PUNYCODE               = 0x100;
    public const ERROR_LABEL_HAS_DOT          = 0x200;
    public const ERROR_INVALID_ACE_LABEL      = 0x400;
    public const ERROR_BIDI                   = 0x800;
    public const ERROR_CONTEXTJ               = 0x1000;
    public const ERROR_CONTEXTO_PUNCTUATION   = 0x2000;
    public const ERROR_CONTEXTO_DIGITS        = 0x4000;

    /**
     * IDNA default options.
     */
    public const IDNA2008_ASCII = self::IDNA_NONTRANSITIONAL_TO_ASCII
        | self::IDNA_CHECK_BIDI
        | self::IDNA_USE_STD3_RULES
        | self::IDNA_CHECK_CONTEXTJ;
    public const IDNA2008_UNICODE = self::IDNA_NONTRANSITIONAL_TO_UNICODE
        | self::IDNA_CHECK_BIDI
        | self::IDNA_USE_STD3_RULES
        | self::IDNA_CHECK_CONTEXTJ;

    /**
     * @codeCoverageIgnore
     */
    private static function supportsIdna(): void
    {
        static $idnSupport;
        if (null === $idnSupport) {
            $idnSupport = function_exists('\idn_to_ascii') && defined('\INTL_IDNA_VARIANT_UTS46');
        }

        if (!$idnSupport) {
            throw new IdnSupportMissing('IDN host can not be processed. Verify that ext/intl is installed for IDN support and that ICU is at least version 4.6.');
        }
    }

    /**
     * Converts the input to its IDNA ASCII form.
     *
     * This method returns the string converted to IDN ASCII form
     *
     * @throws SyntaxError if the string can not be converted to ASCII using IDN UTS46 algorithm
     */
    public static function toAscii(string $domain, int $options): IdnaInfo
    {
        $domain = rawurldecode($domain);

        if (1 !== preg_match(self::REGEXP_IDNA_PATTERN, $domain)) {
            $errors = 0;
            if (strlen($domain) >= self::MAX_DOMAIN_LENGTH) {
                $errors = self::ERROR_DOMAIN_NAME_TOO_LONG;
            }

            $info = IdnaInfo::fromIntl(['result' => strtolower($domain), 'isTransitionalDifferent' => false, 'errors' => $errors]);
            if (0 !== $info->errors()) {
                throw IdnaConversionFailed::dueToIDNAError($domain, $info);
            }

            return $info;
        }

        self::supportsIdna();

        /* @param-out array{errors: int, isTransitionalDifferent: bool, result: string} $idnaInfo */
        idn_to_ascii($domain, $options, INTL_IDNA_VARIANT_UTS46, $idnaInfo);
        if ([] === $idnaInfo) {
            throw  IdnaConversionFailed::dueToInvalidHost($domain);
        }

        /* @var array{errors: int, isTransitionalDifferent: bool, result: string} $idnaInfo */
        return self::createIdnaInfo($domain, $idnaInfo);
    }

    /**
     * Converts the input to its IDNA UNICODE form.
     *
     * This method returns the string converted to IDN UNICODE form
     *
     * @throws SyntaxError if the string can not be converted to UNICODE using IDN UTS46 algorithm
     */
    public static function toUnicode(string $domain, int $options): IdnaInfo
    {
        if (false === stripos($domain, 'xn--')) {
            return IdnaInfo::fromIntl(['result' => $domain, 'isTransitionalDifferent' => false, 'errors' => 0]);
        }

        self::supportsIdna();

        /* @param-out array{errors: int, isTransitionalDifferent: bool, result: string} $idnaInfo */
        idn_to_utf8($domain, $options, INTL_IDNA_VARIANT_UTS46, $idnaInfo);
        if ([] === $idnaInfo) {
            throw new SyntaxError(sprintf('Host `%s` is not a valid IDN host', $domain));
        }

        /* @var array{errors: int, isTransitionalDifferent: bool, result: string} $idnaInfo */
        return self::createIdnaInfo($domain, $idnaInfo);
    }

    /**
     * @param array{result:string, isTransitionalDifferent:bool, errors:int} $idnaInfo
     */
    private static function createIdnaInfo(string $domain, array $idnaInfo): IdnaInfo
    {
        $info = IdnaInfo::fromIntl($idnaInfo);
        if (0 !== $info->errors()) {
            throw IdnaConversionFailed::dueToIDNAError($domain, $info);
        }

        return $info;
    }
}
