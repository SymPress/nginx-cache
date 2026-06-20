<?php

declare(strict_types=1);

namespace SymPress\NginxCache\Config;

use SymPress\NginxCache\Settings\WordPressCacheSettings;
use SymPress\NginxCache\Value\CacheProfile;

final readonly class BypassRuleProvider
{
    public function __construct(
        private ?WordPressCacheSettings $settings = null,
    ) {
    }

    /** @return array{cookies: list<string>, uris: list<string>, user_agents: list<string>, query_allowlist: list<string>} */
    public function rules(CacheProfile $profile): array
    {
        $cookies = [
            'comment_author',
            'wordpress_[a-f0-9]+',
            'wordpress_logged_in_',
            'wordpress_sec_',
            'wp-postpass_',
            'preview_',
        ];
        $uris = [
            '^/wp-admin/',
            '^/wp/wp-admin/',
            '^/wp-login\.php',
            '^/wp/wp-login\.php',
            '^/wp-cron\.php',
            '^/wp/wp-cron\.php',
            '^/xmlrpc\.php',
            '^/wp/xmlrpc\.php',
            '^/wp-json/',
            '^/index\.php/wp-json/',
            'preview=true',
        ];
        $userAgents = [];
        $queryAllowlist = ['^$'];

        if (in_array($profile, [CacheProfile::Commerce, CacheProfile::Safe], true)) {
            $cookies = [
                ...$cookies,
                'woocommerce_cart_hash',
                'woocommerce_items_in_cart',
                'wp_woocommerce_session_',
                'edd_items_in_cart',
                'edd_cart_token',
                'wp_llms_session_',
                'llms_cart',
                'pmpro_visit',
                'mepr_',
                'rcp_',
                'wishlist_member',
                'swpm_',
                'woocommerce_recently_viewed',
                'currency',
                'wcml_client_currency',
                'pll_language',
                'wp-wpml_current_language',
            ];
            $uris = [
                ...$uris,
                '^/cart/?',
                '^/checkout/?',
                '^/order-pay/?',
                '^/order-received/?',
                '^/my-account/?',
                '^/account/?',
                '^/wc-api/',
                '^/wp-json/wc/',
                '^/wp-json/wc-store/',
                '^/members/',
                '^/membership/',
                '^/member/',
                '^/courses/',
                '^/lesson/',
                '^/dashboard/',
            ];
        }

        if ($profile === CacheProfile::Headless) {
            $uris = array_values(array_diff($uris, ['^/wp-json/', '^/index\.php/wp-json/']));
            $queryAllowlist[] = '^_fields=';
        }

        if ($profile === CacheProfile::HighTraffic) {
            $queryAllowlist[] = '^ver=[a-zA-Z0-9._-]+$';
        }

        if (function_exists('apply_filters')) {
            $cookies = (array) apply_filters('sympress_nginx_cache_bypass_cookies', $cookies, $profile->value);
            $uris = (array) apply_filters('sympress_nginx_cache_bypass_uris', $uris, $profile->value);
            $userAgents = (array) apply_filters('sympress_nginx_cache_bypass_user_agents', $userAgents, $profile->value);
            $queryAllowlist = (array) apply_filters('sympress_nginx_cache_query_allowlist', $queryAllowlist, $profile->value);
        }

        if ($this->settings instanceof WordPressCacheSettings) {
            $cookies = [...$cookies, ...$this->settings->customBypassCookies()];
            $uris = [...$uris, ...$this->settings->customBypassUris()];
            $userAgents = [...$userAgents, ...$this->settings->customBypassUserAgents()];
            $queryAllowlist = [...$queryAllowlist, ...$this->settings->customQueryAllowlist()];
        }

        return [
            'cookies'         => $this->strings($cookies),
            'uris'            => $this->strings($uris),
            'user_agents'     => $this->strings($userAgents),
            'query_allowlist' => $this->strings($queryAllowlist),
        ];
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values),
                    static fn (string $value): bool => $value !== '',
                ),
            ),
        );
    }
}
