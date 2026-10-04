<?php
// UPDATED FILE - Refactored IPv4 anonymization using string manipulation

namespace WPAICG\AIPKit\Addons; // Updated Namespace

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * AIPKit_IP_Anonymization
 *
 * Provides functionality to anonymize IP addresses when enabled.
 * Supports both IPv4 and IPv6.
 * UPDATED: Added conditional logging based on WP_DEBUG.
 * UPDATED: Improved IPv6 anonymization fallback.
 * UPDATED: Refactored IPv4 anonymization using string manipulation for reliability.
 */
class AIPKit_IP_Anonymization {

    /**
     * Anonymizes an IP address when enabled.
     *
     * @param string|null $ip The IP address to potentially anonymize.
     * @param bool $enabled Whether anonymization should be applied.
     * @return string|null The original or anonymized IP address, or null if input was null/empty.
     */
    public static function maybe_anonymize(?string $ip, bool $enabled = false): ?string {
        if (empty($ip)) {
            return $ip; // Return null/empty if input is null/empty
        }

        if (!$enabled) {
            return $ip; // Not enabled, return original IP
        }

        // Validate and anonymize
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::_anonymize_ipv4($ip);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::_anonymize_ipv6($ip);
        } else {
            return $ip; // Return original if invalid format
        }
    }

    /**
     * Anonymizes an IPv4 address by setting the last octet to 0 using string manipulation.
     *
     * @param string $ip Valid IPv4 address.
     * @return string Anonymized IPv4 address.
     */
    private static function _anonymize_ipv4(string $ip): string {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            // We know it's valid IPv4 because filter_var passed
            $parts[3] = '0'; // Set the last part to '0'
            return implode('.', $parts);
        }

        // Return a safe default anonymized value instead of the potentially malformed original
        return '0.0.0.0';
    }

    /**
     * Anonymizes an IPv6 address by setting the last 16 bits (last block) to zeros.
     * Handles both compressed and uncompressed formats generally.
     *
     * @param string $ip Valid IPv6 address.
     * @return string Anonymized IPv6 address.
     */
    private static function _anonymize_ipv6(string $ip): string {
        $packed_ip = @inet_pton($ip); // Use error suppression for invalid input (already filtered)
        if ($packed_ip === false) {
            return '::'; // Return generic anonymous on pton failure
        }

        // Expand compressed IPv6 address for easier manipulation
        $expanded_ip = inet_ntop($packed_ip);
        if ($expanded_ip === false) {

            return '::'; // Return generic anonymous on expansion failure
        }

        // Split into blocks
        $blocks = explode(':', $expanded_ip);

        // Set the last block to '0000'
        if (count($blocks) === 8) { // Ensure we have 8 blocks after expansion
            $blocks[7] = '0000';
        } else {

            return '::'; // Return generic anonymous if block count is wrong
        }


        // Re-join and attempt to compress
        $anonymized_expanded = implode(':', $blocks);
        $anonymized_packed = @inet_pton($anonymized_expanded);
        if ($anonymized_packed === false) {

            return '::'; // Return generic anonymous on pack failure
        }

        $anonymized_compressed = inet_ntop($anonymized_packed);
        if ($anonymized_compressed === false) {

            // *** UPDATED FALLBACK ***
            return '::'; // Return generic anonymous '::' if compression fails
        }

        return $anonymized_compressed;
    }
}
