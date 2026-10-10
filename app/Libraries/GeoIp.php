<?php

namespace App\Libraries;

/**
 * GeoIp — country catalogue and helpers for geography-based firewall objects.
 *
 * Geography address objects (a country name → the set of networks that
 * country originates from) are generated from the country's registry data, in
 * the same way a GeoIP alias set feeds firewall rules.
 *
 * MicronOS models a country as a firewall address object of type `geoip` whose
 * value is a comma-separated list of ISO 3166-1 alpha-2 codes, e.g. "US, CA".
 * The nftables compiler turns each code into a named, interval set
 * (`@geo_us`) that micrond populates from the appliance GeoIP database.
 *
 * This class holds the country catalogue, validates/normalises codes, and can
 * optionally inline networks from a plain-text feed so a set is usable even
 * before the daemon has populated it:
 *
 *     writable/geoip/us.netset   # one CIDR per line, or comma-separated
 */
class GeoIp
{
    /** ISO 3166-1 alpha-2 codes → English country name. */
    public const COUNTRIES = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan',
        'AG' => 'Antigua and Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albania',
        'AM' => 'Armenia', 'AO' => 'Angola', 'AQ' => 'Antarctica',
        'AR' => 'Argentina', 'AS' => 'American Samoa', 'AT' => 'Austria',
        'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Åland Islands',
        'AZ' => 'Azerbaijan', 'BA' => 'Bosnia and Herzegovina', 'BB' => 'Barbados',
        'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BF' => 'Burkina Faso',
        'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi',
        'BJ' => 'Benin', 'BL' => 'Saint Barthélemy', 'BM' => 'Bermuda',
        'BN' => 'Brunei Darussalam', 'BO' => 'Bolivia', 'BQ' => 'Bonaire',
        'BR' => 'Brazil', 'BS' => 'Bahamas', 'BT' => 'Bhutan',
        'BV' => 'Bouvet Island', 'BW' => 'Botswana', 'BY' => 'Belarus',
        'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocos Islands',
        'CD' => 'DR Congo', 'CF' => 'Central African Republic', 'CG' => 'Congo',
        'CH' => 'Switzerland', 'CI' => "Côte d'Ivoire", 'CK' => 'Cook Islands',
        'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China',
        'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba',
        'CV' => 'Cabo Verde', 'CW' => 'Curaçao', 'CX' => 'Christmas Island',
        'CY' => 'Cyprus', 'CZ' => 'Czechia', 'DE' => 'Germany',
        'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'DZ' => 'Algeria', 'EC' => 'Ecuador',
        'EE' => 'Estonia', 'EG' => 'Egypt', 'EH' => 'Western Sahara',
        'ER' => 'Eritrea', 'ES' => 'Spain', 'ET' => 'Ethiopia',
        'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falkland Islands',
        'FM' => 'Micronesia', 'FO' => 'Faroe Islands', 'FR' => 'France',
        'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada',
        'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey',
        'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Greenland',
        'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe',
        'GQ' => 'Equatorial Guinea', 'GR' => 'Greece',
        'GS' => 'South Georgia', 'GT' => 'Guatemala', 'GU' => 'Guam',
        'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HK' => 'Hong Kong',
        'HM' => 'Heard & McDonald Islands', 'HN' => 'Honduras', 'HR' => 'Croatia',
        'HT' => 'Haiti', 'HU' => 'Hungary', 'ID' => 'Indonesia',
        'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man',
        'IN' => 'India', 'IO' => 'British Indian Ocean Territory', 'IQ' => 'Iraq',
        'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy',
        'JE' => 'Jersey', 'JM' => 'Jamaica', 'JO' => 'Jordan',
        'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan',
        'KH' => 'Cambodia', 'KI' => 'Kiribati', 'KM' => 'Comoros',
        'KN' => 'Saint Kitts and Nevis', 'KP' => 'North Korea',
        'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands',
        'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon',
        'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka',
        'LR' => 'Liberia', 'LS' => 'Lesotho', 'LT' => 'Lithuania',
        'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya',
        'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova',
        'ME' => 'Montenegro', 'MF' => 'Saint Martin', 'MG' => 'Madagascar',
        'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali',
        'MM' => 'Myanmar', 'MN' => 'Mongolia', 'MO' => 'Macao',
        'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique', 'MR' => 'Mauritania',
        'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius',
        'MV' => 'Maldives', 'MW' => 'Malawi', 'MX' => 'Mexico',
        'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia',
        'NC' => 'New Caledonia', 'NE' => 'Niger', 'NF' => 'Norfolk Island',
        'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'Netherlands',
        'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru',
        'NU' => 'Niue', 'NZ' => 'New Zealand', 'OM' => 'Oman',
        'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia',
        'PG' => 'Papua New Guinea', 'PH' => 'Philippines', 'PK' => 'Pakistan',
        'PL' => 'Poland', 'PM' => 'Saint Pierre and Miquelon', 'PN' => 'Pitcairn',
        'PR' => 'Puerto Rico', 'PS' => 'Palestine', 'PT' => 'Portugal',
        'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar',
        'RE' => 'Réunion', 'RO' => 'Romania', 'RS' => 'Serbia',
        'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan',
        'SE' => 'Sweden', 'SG' => 'Singapore', 'SH' => 'Saint Helena',
        'SI' => 'Slovenia', 'SJ' => 'Svalbard and Jan Mayen', 'SK' => 'Slovakia',
        'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal',
        'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan',
        'ST' => 'Sao Tome and Principe', 'SV' => 'El Salvador', 'SX' => 'Sint Maarten',
        'SY' => 'Syria', 'SZ' => 'Eswatini', 'TC' => 'Turks and Caicos Islands',
        'TD' => 'Chad', 'TF' => 'French Southern Territories', 'TG' => 'Togo',
        'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TK' => 'Tokelau',
        'TL' => 'Timor-Leste', 'TM' => 'Turkmenistan', 'TN' => 'Tunisia',
        'TO' => 'Tonga', 'TR' => 'Türkiye', 'TT' => 'Trinidad and Tobago',
        'TV' => 'Tuvalu', 'TW' => 'Taiwan', 'TZ' => 'Tanzania',
        'UA' => 'Ukraine', 'UG' => 'Uganda', 'UM' => 'US Minor Outlying Islands',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
        'VA' => 'Holy See', 'VC' => 'Saint Vincent and the Grenadines',
        'VE' => 'Venezuela', 'VG' => 'British Virgin Islands',
        'VI' => 'US Virgin Islands', 'VN' => 'Vietnam', 'VU' => 'Vanuatu',
        'WF' => 'Wallis and Futuna', 'WS' => 'Samoa', 'YE' => 'Yemen',
        'YT' => 'Mayotte', 'ZA' => 'South Africa', 'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];

    /** Country codes → names, sorted by name for select lists. */
    public static function countries(): array
    {
        $list = self::COUNTRIES;
        asort($list);

        return $list;
    }

    /** True when $code is a known ISO 3166-1 alpha-2 country code. */
    public static function isKnown(string $code): bool
    {
        return isset(self::COUNTRIES[strtoupper(trim($code))]);
    }

    /**
     * Split a geography value into validated, uppercase ISO codes.
     *
     * @return list<string>
     */
    public static function codes(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $value) as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && self::isKnown($code) && ! in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /** Codes that are NOT valid ISO 3166-1 alpha-2 entries (for error messages). */
    public static function invalidCodes(string $value): array
    {
        $bad = [];
        foreach (preg_split('/[\s,;]+/', $value) as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && ! self::isKnown($code)) {
                $bad[] = $code;
            }
        }

        return array_values(array_unique($bad));
    }

    /** Canonical stored form of a geography value, e.g. "US, CA". */
    public static function normalise(string $value): string
    {
        return implode(', ', self::codes($value));
    }

    /** Human label, e.g. "United States, Canada". Falls back to the raw value. */
    public static function label(string $value): string
    {
        $names = [];
        foreach (self::codes($value) as $code) {
            $names[] = self::COUNTRIES[$code];
        }

        return $names === [] ? trim($value) : implode(', ', $names);
    }

    /** nftables set identifier for a country, e.g. "geo_us". */
    public static function setName(string $code): string
    {
        return 'geo_' . strtolower(trim($code));
    }

    /** Directory that holds optional per-country network feeds. */
    public static function dataDir(): string
    {
        return WRITEPATH . 'geoip';
    }

    /**
     * Networks for a country from the local feed, when present. These are
     * inlined into the nftables set so matching works even before micrond has
     * populated it from the GeoIP database. Returns [] when no feed exists.
     *
     * @return list<string>
     */
    public static function networks(string $code): array
    {
        $code = strtolower(trim($code));
        if (! self::isKnown($code)) {
            return [];
        }

        $out = [];
        foreach ([".netset", ".list", ".txt"] as $ext) {
            $file = self::dataDir() . '/' . $code . $ext;
            if (! is_file($file)) {
                continue;
            }
            $raw = (string) @file_get_contents($file);
            foreach (preg_split('/[\s,]+/', $raw) as $entry) {
                $entry = trim($entry);
                if ($entry !== '' && strpos($entry, '#') !== 0 && Cidr::isValid($entry)) {
                    $out[] = $entry;
                }
            }
            break;
        }

        return array_values(array_unique($out));
    }
}
