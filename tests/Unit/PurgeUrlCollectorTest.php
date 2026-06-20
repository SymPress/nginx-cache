<?php

declare(strict_types=1);

namespace {
    if (!function_exists('sympress_nginx_cache_test_url')) {
        function sympress_nginx_cache_test_url(string $path = '/'): string
        {
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }

            return 'https://example.test/' . ltrim($path, '/');
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = '/'): string
        {
            return sympress_nginx_cache_test_url($path);
        }
    }

    if (!function_exists('site_url')) {
        function site_url(string $path = '/'): string
        {
            return sympress_nginx_cache_test_url($path);
        }
    }

    if (!function_exists('rest_url')) {
        function rest_url(string $path = ''): string
        {
            return sympress_nginx_cache_test_url('/wp-json/' . ltrim($path, '/'));
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['sympress_nginx_cache_test_options'][$name] ?? $default;
        }
    }

    if (!function_exists('get_permalink')) {
        function get_permalink(mixed $post = 0): string|false
        {
            $postId = is_object($post) && property_exists($post, 'ID') ? (int) $post->ID : (int) $post;

            return $GLOBALS['sympress_nginx_cache_test_permalinks'][$postId] ?? false;
        }
    }

    if (!function_exists('get_post')) {
        function get_post(int $postId): ?object
        {
            return $GLOBALS['sympress_nginx_cache_test_posts'][$postId] ?? null;
        }
    }

    if (!function_exists('get_post_type')) {
        function get_post_type(int $postId): string|false
        {
            $post = get_post($postId);

            return is_object($post) && property_exists($post, 'post_type') ? (string) $post->post_type : false;
        }
    }

    if (!function_exists('get_post_type_object')) {
        function get_post_type_object(string $postType): object
        {
            return (object) [
                'rest_base' => match ($postType) {
                    'page' => 'pages',
                    'product' => 'products',
                    default => 'posts',
                },
            ];
        }
    }

    if (!function_exists('get_post_type_archive_link')) {
        function get_post_type_archive_link(string $postType): string|false
        {
            return $postType === 'product'
                ? sympress_nginx_cache_test_url('/shop/')
                : sympress_nginx_cache_test_url('/' . trim($postType, '/') . '/');
        }
    }

    if (!function_exists('get_author_posts_url')) {
        function get_author_posts_url(int $authorId): string
        {
            return sympress_nginx_cache_test_url(sprintf('/author/%d/', $authorId));
        }
    }

    if (!function_exists('get_object_taxonomies')) {
        function get_object_taxonomies(string $postType): array
        {
            return $postType === 'product' ? ['product_cat'] : ['category'];
        }
    }

    if (!function_exists('get_the_terms')) {
        function get_the_terms(int $postId, string $taxonomy): array
        {
            return [(object) [
                'term_id'  => $taxonomy === 'product_cat' ? 11 : 7,
                'taxonomy' => $taxonomy,
                'slug'     => $taxonomy === 'product_cat' ? 'hats' : 'news',
            ]];
        }
    }

    if (!function_exists('get_term_link')) {
        function get_term_link(mixed $term): string|false
        {
            if (is_object($term) && property_exists($term, 'slug')) {
                return sympress_nginx_cache_test_url('/' . trim((string) $term->slug, '/') . '/');
            }

            return is_numeric($term) ? sympress_nginx_cache_test_url('/term/' . (int) $term . '/') : false;
        }
    }

    if (!function_exists('get_term_feed_link')) {
        function get_term_feed_link(int $termId): string
        {
            return sympress_nginx_cache_test_url('/term/' . $termId . '/feed/');
        }
    }

    if (!function_exists('get_term')) {
        function get_term(int $termId): object
        {
            return (object) ['term_id' => $termId, 'taxonomy' => 'category', 'slug' => 'news'];
        }
    }

    if (!function_exists('get_the_time')) {
        function get_the_time(string $format, int $postId): string
        {
            return match ($format) {
                'Y' => '2026',
                'm' => '06',
                'd' => '20',
                default => '',
            };
        }
    }

    if (!function_exists('get_year_link')) {
        function get_year_link(int $year): string
        {
            return sympress_nginx_cache_test_url('/' . $year . '/');
        }
    }

    if (!function_exists('get_month_link')) {
        function get_month_link(int $year, int $month): string
        {
            return sympress_nginx_cache_test_url(sprintf('/%04d/%02d/', $year, $month));
        }
    }

    if (!function_exists('get_day_link')) {
        function get_day_link(int $year, int $month, int $day): string
        {
            return sympress_nginx_cache_test_url(sprintf('/%04d/%02d/%02d/', $year, $month, $day));
        }
    }

    if (!function_exists('get_post_comments_feed_link')) {
        function get_post_comments_feed_link(int $postId): string
        {
            return sympress_nginx_cache_test_url('/comments/' . $postId . '/feed/');
        }
    }
}

namespace SymPress\NginxCache\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use SymPress\NginxCache\Purge\PurgeUrlCollector;
    use SymPress\NginxCache\Security\UrlPolicy;
    use SymPress\NginxCache\Settings\WordPressCacheSettings;
    use SymPress\NginxCache\Surrogate\CacheTagResolver;
    use SymPress\NginxCache\Surrogate\TagIndexRepository;
    use SymPress\NginxCache\Time\CacheClock;
    use Symfony\Component\Clock\MockClock;

    final class PurgeUrlCollectorTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['sympress_nginx_cache_test_options'] = [
                'page_for_posts' => 9,
                WordPressCacheSettings::OPTION_ARCHIVE_PAGE_LIMIT => 2,
                WordPressCacheSettings::OPTION_PURGE_AMP => 1,
                WordPressCacheSettings::OPTION_PURGE_FEEDS => 1,
            ];
            $GLOBALS['sympress_nginx_cache_test_posts'] = [
                9 => (object) ['ID' => 9, 'post_type' => 'page', 'post_author' => 1],
                42 => (object) ['ID' => 42, 'post_type' => 'post', 'post_author' => 5],
                100 => (object) ['ID' => 100, 'post_type' => 'product', 'post_author' => 8],
            ];
            $GLOBALS['sympress_nginx_cache_test_permalinks'] = [
                9 => 'https://example.test/blog/',
                42 => 'https://example.test/hello-world/',
                100 => 'https://example.test/product/hat/',
            ];
        }

        public function testItCollectsPostsPageDateFeedPagedAndAmpUrls(): void
        {
            $urls = $this->collector()->collect('transition_post_status', [
                'publish',
                'draft',
                (object) ['ID' => 42, 'post_type' => 'post', 'post_author' => 5],
            ]);

            self::assertContains('https://example.test/hello-world/', $urls);
            self::assertContains('https://example.test/hello-world/amp/', $urls);
            self::assertContains('https://example.test/blog/', $urls);
            self::assertContains('https://example.test/blog/page/2/', $urls);
            self::assertContains('https://example.test/blog/feed/', $urls);
            self::assertContains('https://example.test/2026/', $urls);
            self::assertContains('https://example.test/2026/06/', $urls);
            self::assertContains('https://example.test/2026/06/20/', $urls);
            self::assertContains('https://example.test/author/5/page/2/', $urls);
            self::assertContains('https://example.test/wp-json/wp/v2/posts/42', $urls);
        }

        public function testItCollectsWooCommerceOrderProductUrls(): void
        {
            $order = new class {
                public function get_items(): array
                {
                    return [
                        new class {
                            public function get_product(): object
                            {
                                return new class {
                                    public function get_id(): int
                                    {
                                        return 100;
                                    }

                                    public function get_parent_id(): int
                                    {
                                        return 0;
                                    }
                                };
                            }
                        },
                    ];
                }
            };

            $urls = $this->collector()->collect('woocommerce_reduce_order_stock', [$order]);

            self::assertContains('https://example.test/product/hat/', $urls);
            self::assertContains('https://example.test/shop/', $urls);
            self::assertContains('https://example.test/wp-json/wp/v2/products/100', $urls);
        }

        private function collector(): PurgeUrlCollector
        {
            $resolver = new CacheTagResolver();
            $urlPolicy = new UrlPolicy();

            return new PurgeUrlCollector(
                $resolver,
                new TagIndexRepository($resolver, $urlPolicy, new CacheClock(new MockClock('2026-06-20 12:00:00'))),
                $urlPolicy,
                new WordPressCacheSettings('/tmp/cache', $urlPolicy),
            );
        }
    }
}
