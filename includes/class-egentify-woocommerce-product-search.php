<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Product_Search {
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 20;
    private const CANDIDATE_MULTIPLIER = 6;
    private const MAX_SEED_TERMS = 12;

    /** @var Egentify_WooCommerce_Settings */
    private $settings;

    public function __construct(Egentify_WooCommerce_Settings $settings) {
        $this->settings = $settings;
    }

    public function search($query, array $options = array()) {
        $query = sanitize_text_field((string) $query);
        $synonym_groups = $this->normalize_synonym_groups($this->settings->get_search_synonym_groups());
        $query_profile = $this->build_query_profile($query, $synonym_groups);

        if ('' === $query_profile['normalizedQuery'] || empty($query_profile['requiredTerms'])) {
            return array(
                'query' => $query,
                'count' => 0,
                'results' => array(),
            );
        }

        $limit = isset($options['limit']) ? absint($options['limit']) : self::DEFAULT_LIMIT;
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $limit = min($limit, self::MAX_LIMIT);
        $candidate_limit = max(24, $limit * self::CANDIDATE_MULTIPLIER);
        $candidates = $this->collect_candidates($query, $query_profile, $candidate_limit);
        $weights = $this->get_weights();
        $results = array();

        if (!empty($candidates)) {
            update_object_term_cache(array_keys($candidates), 'product');
        }

        foreach ($candidates as $product_id => $candidate) {
            $product = wc_get_product($product_id);

            if (!($product instanceof WC_Product)) {
                continue;
            }

            if (!$this->passes_filters($product, $options)) {
                continue;
            }

            $match = $this->score_product($product, $query_profile, $weights, $candidate, $synonym_groups);
            if ($match['score'] <= 0) {
                continue;
            }

            $results[] = array(
                'product' => $product,
                'match' => $match,
            );
        }

        usort(
            $results,
            function ($left, $right) {
                if ($left['match']['score'] === $right['match']['score']) {
                    return strcasecmp($left['product']->get_name(), $right['product']->get_name());
                }

                return $right['match']['score'] <=> $left['match']['score'];
            }
        );

        $term_match_threshold = $this->determine_term_match_threshold($results, $limit, count($query_profile['requiredTerms']));
        $results = $this->filter_results_by_term_match_threshold($results, $term_match_threshold);
        $formatted_results = array();
        $debug = !empty($options['debug']);

        foreach ($results as $result) {
            $formatted_results[] = $this->format_product($result['product'], $result['match'], $debug);

            if (count($formatted_results) >= $limit) {
                break;
            }
        }

        $payload = array(
            'query' => $query,
            'count' => count($formatted_results),
            'results' => $formatted_results,
        );

        if ($debug) {
            $payload['normalizedQuery'] = $query_profile['normalizedQuery'];
            $payload['seedTerms'] = $query_profile['seedTerms'];
            $payload['requiredTerms'] = $this->format_required_terms_for_debug($query_profile['requiredTerms']);
            $payload['termMatchThreshold'] = $term_match_threshold;
        }

        return $payload;
    }

    private function collect_candidates($query, array $query_profile, $limit) {
        $candidates = array();

        if (ctype_digit($query_profile['normalizedQuery'])) {
            $this->add_candidate($candidates, (int) $query_profile['normalizedQuery'], 'product_id_exact', 300);
        }

        $sku_matches = $this->find_products_by_sku_all_modes($query, $limit);
        foreach ($sku_matches['exact'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'sku_exact_seed', 180);
        }
        foreach ($sku_matches['prefix'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'sku_prefix_seed', 120);
        }
        foreach ($sku_matches['contains'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'sku_contains_seed', 60);
        }

        $title_matches = $this->find_products_by_title_all_modes($query, $limit);
        foreach ($title_matches['exact'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'title_exact_seed', 50);
        }
        foreach ($title_matches['prefix'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'title_prefix_seed', 36);
        }
        foreach ($title_matches['contains'] as $product_id) {
            $this->add_candidate($candidates, $product_id, 'title_contains_seed', 24);
        }

        if (count($candidates) >= $limit) {
            return $candidates;
        }

        foreach ($query_profile['seedTerms'] as $seed_term) {
            foreach ($this->find_products_by_title($seed_term, 'contains', $limit) as $product_id) {
                $this->add_candidate($candidates, $product_id, 'title_token_seed', 12);
            }

            foreach ($this->find_products_by_taxonomy_term($seed_term, $limit) as $product_id) {
                $this->add_candidate($candidates, $product_id, 'taxonomy_seed', 12);
            }
        }

        if (count($candidates) >= $limit) {
            return $candidates;
        }

        foreach ($this->find_products_by_wp_search($query, $limit) as $product_id) {
            $this->add_candidate($candidates, $product_id, 'wp_search_seed', 8);
        }

        return $candidates;
    }

    private function add_candidate(array &$candidates, $product_id, $reason, $seed_score) {
        $product_id = $this->normalize_product_id((int) $product_id);

        if ($product_id < 1) {
            return;
        }

        if (!isset($candidates[$product_id])) {
            $candidates[$product_id] = array(
                'seedScore' => 0,
                'matchedOn' => array(),
            );
        }

        $candidates[$product_id]['seedScore'] += (int) $seed_score;

        if (!in_array($reason, $candidates[$product_id]['matchedOn'], true)) {
            $candidates[$product_id]['matchedOn'][] = $reason;
        }
    }

    private function build_query_profile($query, array $synonym_groups) {
        $normalized_query = $this->normalize_text($query);
        $raw_tokens = $this->extract_raw_tokens($normalized_query);
        $required_terms = $this->build_required_terms($raw_tokens, $normalized_query, $synonym_groups);

        return array(
            'normalizedQuery' => $normalized_query,
            'requiredTerms' => $required_terms,
            'seedTerms' => $this->build_seed_terms($required_terms),
        );
    }

    private function build_required_terms(array $raw_tokens, $normalized_query, array $synonym_groups) {
        $required_terms = array();

        foreach ($raw_tokens as $raw_token) {
            foreach ($this->extract_required_units_from_raw_token($raw_token) as $term) {
                if (!$this->is_meaningful_token($term)) {
                    continue;
                }

                $required_terms[] = array(
                    'term' => $term,
                    'variants' => $this->build_term_variants($term),
                );
            }
        }

        if (empty($required_terms)) {
            return array();
        }

        $this->add_adjacent_compound_variants($required_terms);
        $this->add_synonym_variants($required_terms, $normalized_query, $synonym_groups);

        foreach ($required_terms as &$required_term) {
            $required_term['variants'] = $this->unique_preserving_order($required_term['variants']);
        }
        unset($required_term);

        return $required_terms;
    }

    private function extract_required_units_from_raw_token($raw_token) {
        $raw_token = trim((string) $raw_token);
        if ('' === $raw_token) {
            return array();
        }

        $compact = $this->compact_token($raw_token);
        if ('' === $compact) {
            return array();
        }

        if ($this->looks_like_identifier($compact)) {
            return array($compact);
        }

        if (preg_match('/[-\/_\.]/', $raw_token)) {
            $parts = preg_split('/[-\/_\.]+/', $raw_token);
            $parts = is_array($parts) ? $parts : array();
            $units = array();

            foreach ($parts as $part) {
                $part = $this->compact_token($part);

                if ('' === $part) {
                    continue;
                }

                $units[] = $part;
            }

            if (!empty($units)) {
                return $units;
            }
        }

        return array($compact);
    }

    private function add_adjacent_compound_variants(array &$required_terms) {
        $count = count($required_terms);

        for ($index = 0; $index < $count - 1; $index++) {
            $left = $required_terms[$index]['term'];
            $right = $required_terms[$index + 1]['term'];

            if (!$this->should_build_compound_variant($left, $right)) {
                continue;
            }

            $compound = $this->compact_token($left . $right);
            if ('' === $compound) {
                continue;
            }

            $required_terms[$index]['variants'][] = $compound;
            $required_terms[$index + 1]['variants'][] = $compound;
        }
    }

    private function add_synonym_variants(array &$required_terms, $normalized_query, array $synonym_groups) {
        foreach ($synonym_groups as $group) {
            $matched = false;
            $group_lookup = array();

            foreach ($group as $phrase) {
                if ($this->text_contains_phrase($normalized_query, $phrase)) {
                    $matched = true;
                }

                $group_lookup = $this->merge_lookups(
                    $group_lookup,
                    $this->build_phrase_lookup_from_text($phrase)
                );
            }

            if (!$matched || empty($group_lookup)) {
                continue;
            }

            foreach ($required_terms as &$required_term) {
                if ($this->term_variants_intersect_lookup($required_term['variants'], $group_lookup)) {
                    $required_term['variants'] = array_merge(
                        $required_term['variants'],
                        array_keys($group_lookup)
                    );
                }
            }
            unset($required_term);
        }
    }

    private function build_seed_terms(array $required_terms) {
        $seed_terms = array();
        $seed_lookup = array();

        foreach ($required_terms as $required_term) {
            foreach ($required_term['variants'] as $variant) {
                if (!$this->should_use_as_seed_term($variant)) {
                    continue;
                }

                if (isset($seed_lookup[$variant])) {
                    continue;
                }

                $seed_lookup[$variant] = true;
                $seed_terms[] = $variant;

                if (count($seed_terms) >= self::MAX_SEED_TERMS) {
                    break 2;
                }
            }
        }

        return $seed_terms;
    }

    private function find_products_by_sku_all_modes($query, $limit) {
        global $wpdb;

        $result = array('exact' => array(), 'prefix' => array(), 'contains' => array());
        $query = trim((string) $query);
        if ('' === $query) {
            return $result;
        }

        $escaped = $wpdb->esc_like($query);
        $contains_value = '%' . $escaped . '%';
        $prefix_value = $escaped . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query against product postmeta; results are short-lived per-request and cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT CASE
                WHEN posts.post_type = 'product_variation' AND posts.post_parent > 0 THEN posts.post_parent
                ELSE posts.ID
            END AS product_id,
            pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
              AND posts.post_type IN ('product', 'product_variation')
              AND posts.post_status = 'publish'
              AND pm.meta_value LIKE %s
            ORDER BY CASE
                WHEN pm.meta_value = %s THEN 0
                WHEN pm.meta_value LIKE %s THEN 1
                ELSE 2
            END
            LIMIT %d",
            $contains_value,
            $query,
            $prefix_value,
            (int) $limit
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $query_lower = mb_strtolower($query);
        $seen = array('exact' => array(), 'prefix' => array(), 'contains' => array());

        foreach ($rows as $row) {
            $product_id = absint($row->product_id);
            if ($product_id < 1) {
                continue;
            }

            $meta_lower = mb_strtolower($row->meta_value);

            if (!isset($seen['contains'][$product_id])) {
                $seen['contains'][$product_id] = true;
                $result['contains'][] = $product_id;
            }

            if (0 === mb_strpos($meta_lower, $query_lower) && !isset($seen['prefix'][$product_id])) {
                $seen['prefix'][$product_id] = true;
                $result['prefix'][] = $product_id;
            }

            if ($meta_lower === $query_lower && !isset($seen['exact'][$product_id])) {
                $seen['exact'][$product_id] = true;
                $result['exact'][] = $product_id;
            }
        }

        return $result;
    }

    private function find_products_by_title_all_modes($query, $limit) {
        global $wpdb;

        $result = array('exact' => array(), 'prefix' => array(), 'contains' => array());
        $query = trim((string) $query);
        if ('' === $query) {
            return $result;
        }

        $escaped = $wpdb->esc_like($query);
        $contains_value = '%' . $escaped . '%';
        $prefix_value = $escaped . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query against product titles; results are short-lived per-request and cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT posts.ID, posts.post_title
            FROM {$wpdb->posts} posts
            WHERE posts.post_type = 'product'
              AND posts.post_status = 'publish'
              AND posts.post_title LIKE %s
            ORDER BY CASE
                WHEN posts.post_title = %s THEN 0
                WHEN posts.post_title LIKE %s THEN 1
                ELSE 2
            END
            LIMIT %d",
            $contains_value,
            $query,
            $prefix_value,
            (int) $limit
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $query_lower = mb_strtolower($query);

        foreach ($rows as $row) {
            $product_id = absint($row->ID);
            if ($product_id < 1) {
                continue;
            }

            $title_lower = mb_strtolower($row->post_title);

            $result['contains'][] = $product_id;

            if (0 === mb_strpos($title_lower, $query_lower)) {
                $result['prefix'][] = $product_id;
            }

            if ($title_lower === $query_lower) {
                $result['exact'][] = $product_id;
            }
        }

        return $result;
    }

    private function find_products_by_sku($query, $mode, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        if ('' === $query) {
            return array();
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; cached upstream via HTTP cache headers on the REST response.
        if ('prefix' === $mode) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT CASE
                    WHEN posts.post_type = 'product_variation' AND posts.post_parent > 0 THEN posts.post_parent
                    ELSE posts.ID
                END AS product_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE pm.meta_key = '_sku'
                  AND posts.post_type IN ('product', 'product_variation')
                  AND posts.post_status = 'publish'
                  AND pm.meta_value LIKE %s
                LIMIT %d",
                $wpdb->esc_like($query) . '%',
                (int) $limit
            ));
        } elseif ('contains' === $mode) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT CASE
                    WHEN posts.post_type = 'product_variation' AND posts.post_parent > 0 THEN posts.post_parent
                    ELSE posts.ID
                END AS product_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE pm.meta_key = '_sku'
                  AND posts.post_type IN ('product', 'product_variation')
                  AND posts.post_status = 'publish'
                  AND pm.meta_value LIKE %s
                LIMIT %d",
                '%' . $wpdb->esc_like($query) . '%',
                (int) $limit
            ));
        } else {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT CASE
                    WHEN posts.post_type = 'product_variation' AND posts.post_parent > 0 THEN posts.post_parent
                    ELSE posts.ID
                END AS product_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE pm.meta_key = '_sku'
                  AND posts.post_type IN ('product', 'product_variation')
                  AND posts.post_status = 'publish'
                  AND pm.meta_value = %s
                LIMIT %d",
                $query,
                (int) $limit
            ));
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_products_by_title($query, $mode, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        if ('' === $query) {
            return array();
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom search query; cached upstream via HTTP cache headers on the REST response.
        if ('prefix' === $mode) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT posts.ID
                FROM {$wpdb->posts} posts
                WHERE posts.post_type = 'product'
                  AND posts.post_status = 'publish'
                  AND posts.post_title LIKE %s
                LIMIT %d",
                $wpdb->esc_like($query) . '%',
                (int) $limit
            ));
        } elseif ('contains' === $mode) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT posts.ID
                FROM {$wpdb->posts} posts
                WHERE posts.post_type = 'product'
                  AND posts.post_status = 'publish'
                  AND posts.post_title LIKE %s
                LIMIT %d",
                '%' . $wpdb->esc_like($query) . '%',
                (int) $limit
            ));
        } else {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT posts.ID
                FROM {$wpdb->posts} posts
                WHERE posts.post_type = 'product'
                  AND posts.post_status = 'publish'
                  AND posts.post_title = %s
                LIMIT %d",
                $query,
                (int) $limit
            ));
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_products_by_taxonomy_term($query, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        $taxonomies = $this->get_searchable_taxonomies();

        if ('' === $query || empty($taxonomies)) {
            return array();
        }

        $taxonomy_placeholders = implode(', ', array_fill(0, count($taxonomies), '%s'));
        $like_value = '%' . $wpdb->esc_like($query) . '%';
        $params = array_merge(
            $taxonomies,
            array(
                $like_value,
                $like_value,
                (int) $limit,
            )
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $taxonomy_placeholders is composed of literal '%s' tokens only; placeholder count matches $params (taxonomies + 3). Cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT posts.ID
            FROM {$wpdb->terms} terms
            INNER JOIN {$wpdb->term_taxonomy} taxonomy
                ON taxonomy.term_id = terms.term_id
            INNER JOIN {$wpdb->term_relationships} relationships
                ON relationships.term_taxonomy_id = taxonomy.term_taxonomy_id
            INNER JOIN {$wpdb->posts} posts
                ON posts.ID = relationships.object_id
            WHERE taxonomy.taxonomy IN ({$taxonomy_placeholders})
              AND posts.post_type = 'product'
              AND posts.post_status = 'publish'
              AND (
                    terms.name LIKE %s
                 OR terms.slug LIKE %s
              )
            LIMIT %d",
            $params
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_products_by_wp_search($query, $limit) {
        $search = new WP_Query(
            array(
                'post_type' => 'product',
                'post_status' => 'publish',
                's' => $query,
                'posts_per_page' => (int) $limit,
                'fields' => 'ids',
                'no_found_rows' => true,
                'orderby' => 'relevance',
                'ignore_sticky_posts' => true,
            )
        );

        return $this->pluck_ids($search->posts);
    }

    private function score_product(WC_Product $product, array $query_profile, array $weights, array $candidate, array $synonym_groups) {
        $product_profile = $this->build_product_profile($product, $synonym_groups);
        $matched_terms = $this->get_matched_required_terms($query_profile['requiredTerms'], $product_profile['allLookup']);
        $missing_terms = $this->get_missing_required_terms($query_profile['requiredTerms'], $product_profile['allLookup']);
        $matched_term_count = count($matched_terms);
        $required_term_count = count($query_profile['requiredTerms']);
        $minimum_term_matches = $this->get_minimum_required_term_matches($required_term_count);

        $score = isset($candidate['seedScore']) ? (int) $candidate['seedScore'] : 0;
        $matched_on = isset($candidate['matchedOn']) ? $candidate['matchedOn'] : array();
        $normalized_query = $query_profile['normalizedQuery'];

        if ((string) $product->get_id() === $normalized_query) {
            $score += $weights['product_id_exact'];
            $matched_on[] = 'product_id_exact';
        }

        if ('' !== $product_profile['sku']) {
            if ($product_profile['sku'] === $normalized_query) {
                $score += $weights['sku_exact'];
                $matched_on[] = 'sku_exact';
            } elseif (0 === strpos($product_profile['sku'], $normalized_query)) {
                $score += $weights['sku_prefix'];
                $matched_on[] = 'sku_prefix';
            } elseif (false !== strpos($product_profile['sku'], $normalized_query)) {
                $score += $weights['sku_contains'];
                $matched_on[] = 'sku_contains';
            }
        }

        if ('' !== $product_profile['title']) {
            if ($product_profile['title'] === $normalized_query) {
                $score += $weights['title_exact'];
                $matched_on[] = 'title_exact';
            } elseif (0 === strpos($product_profile['title'], $normalized_query)) {
                $score += $weights['title_prefix'];
                $matched_on[] = 'title_prefix';
            } elseif (false !== strpos($product_profile['title'], $normalized_query)) {
                $score += $weights['title_contains'];
                $matched_on[] = 'title_contains';
            }
        }

        if ($matched_term_count > 0) {
            $score += min($weights['matched_term_cap'], $matched_term_count * $weights['matched_term']);
            $matched_on[] = 'query_terms';
        }

        $title_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $product_profile['titleLookup']);
        if ($title_hits > 0) {
            $score += min($weights['title_token_cap'], $title_hits * $weights['title_token']);
            $matched_on[] = 'title_terms';
        }

        $metadata_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $product_profile['metadataLookup']);
        if ($metadata_hits > 0) {
            $score += min($weights['taxonomy_token_cap'], $metadata_hits * $weights['taxonomy_token']);
            $matched_on[] = 'metadata_terms';
        }

        $short_description_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $product_profile['shortDescriptionLookup']);
        if ($short_description_hits > 0) {
            $score += min($weights['short_description_token_cap'], $short_description_hits * $weights['short_description_token']);
            $matched_on[] = 'short_description_terms';
        }

        if ('' !== $product_profile['description'] && false !== strpos($product_profile['description'], $normalized_query)) {
            $score += $weights['description_contains'];
            $matched_on[] = 'description_contains';
        }

        if ($product->is_in_stock()) {
            $score += $weights['in_stock'];
            $matched_on[] = 'in_stock';
        }

        if ($product->is_on_sale()) {
            $score += $weights['on_sale'];
            $matched_on[] = 'on_sale';
        }

        if ($product->is_featured()) {
            $score += $weights['featured'];
            $matched_on[] = 'featured';
        }

        $total_sales = (int) $product->get_total_sales();
        if ($total_sales > 0) {
            $score += min($weights['sales_cap'], (int) floor($total_sales / $weights['sales_divisor']));
        }

        return array(
            'score' => $score,
            'matchedOn' => array_values(array_unique($matched_on)),
            'matchedTerms' => $matched_terms,
            'matchedTermCount' => $matched_term_count,
            'requiredTermCount' => $required_term_count,
            'minimumTermMatches' => $minimum_term_matches,
            'missingTerms' => $missing_terms,
        );
    }

    private function build_product_profile(WC_Product $product, array $synonym_groups) {
        $title = $this->normalize_text($product->get_name());
        $sku = $this->normalize_text($product->get_sku());
        $short_description = $this->normalize_text($product->get_short_description());
        $description = $this->normalize_text($product->get_short_description() . ' ' . $product->get_description());
        $taxonomy_text = $this->normalize_text($this->get_product_term_text($product->get_id()));
        $attribute_text = $this->normalize_text($this->get_product_attribute_text($product));

        $title_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($title, true),
            $title,
            $synonym_groups
        );
        $taxonomy_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($taxonomy_text, true),
            $taxonomy_text,
            $synonym_groups
        );
        $attribute_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($attribute_text, true),
            $attribute_text,
            $synonym_groups
        );
        $short_description_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($short_description, true),
            $short_description,
            $synonym_groups
        );
        $sku_lookup = $this->build_token_lookup_from_text($sku, false);

        return array(
            'title' => $title,
            'sku' => $sku,
            'description' => $description,
            'titleLookup' => $title_lookup,
            'metadataLookup' => $this->merge_lookups($taxonomy_lookup, $attribute_lookup),
            'shortDescriptionLookup' => $short_description_lookup,
            'allLookup' => $this->merge_lookups(
                $title_lookup,
                $taxonomy_lookup,
                $attribute_lookup,
                $short_description_lookup,
                $sku_lookup
            ),
        );
    }

    private function count_required_term_hits(array $required_terms, array $lookup) {
        $count = 0;

        foreach ($required_terms as $required_term) {
            if ($this->term_variants_intersect_lookup($required_term['variants'], $lookup)) {
                $count++;
            }
        }

        return $count;
    }

    private function get_matched_required_terms(array $required_terms, array $lookup) {
        $matched = array();

        foreach ($required_terms as $required_term) {
            if ($this->term_variants_intersect_lookup($required_term['variants'], $lookup)) {
                $matched[] = $required_term['term'];
            }
        }

        return $matched;
    }

    private function get_missing_required_terms(array $required_terms, array $lookup) {
        $missing = array();

        foreach ($required_terms as $required_term) {
            if (!$this->term_variants_intersect_lookup($required_term['variants'], $lookup)) {
                $missing[] = $required_term['term'];
            }
        }

        return $missing;
    }

    private function get_minimum_required_term_matches($required_term_count) {
        $required_term_count = max(0, (int) $required_term_count);

        if ($required_term_count <= 1) {
            return $required_term_count;
        }

        return $required_term_count - 1;
    }

    private function determine_term_match_threshold(array $results, $limit, $required_term_count) {
        $required_term_count = max(0, (int) $required_term_count);
        if ($required_term_count < 1) {
            return 0;
        }

        $limit = max(1, (int) $limit);

        for ($threshold = $this->get_minimum_required_term_matches($required_term_count); $threshold >= 0; $threshold--) {
            $matching_results = 0;

            foreach ($results as $result) {
                $matched_term_count = isset($result['match']['matchedTermCount']) ? (int) $result['match']['matchedTermCount'] : 0;

                if ($matched_term_count >= $threshold) {
                    $matching_results++;
                }

                if ($matching_results >= $limit) {
                    return $threshold;
                }
            }
        }

        return 0;
    }

    private function filter_results_by_term_match_threshold(array $results, $threshold) {
        $threshold = max(0, (int) $threshold);

        if ($threshold < 1) {
            return $results;
        }

        return array_values(
            array_filter(
                $results,
                function ($result) use ($threshold) {
                    $matched_term_count = isset($result['match']['matchedTermCount']) ? (int) $result['match']['matchedTermCount'] : 0;

                    return $matched_term_count >= $threshold;
                }
            )
        );
    }

    private function term_variants_intersect_lookup(array $variants, array $lookup) {
        foreach ($variants as $variant) {
            if (isset($lookup[$variant])) {
                return true;
            }
        }

        return false;
    }

    private function passes_filters(WC_Product $product, array $options) {
        if ('publish' !== get_post_status($product->get_id())) {
            return false;
        }

        if (!$product->is_visible()) {
            return false;
        }

        if (!empty($options['in_stock']) && !$product->is_in_stock()) {
            return false;
        }

        if (!empty($options['on_sale']) && !$product->is_on_sale()) {
            return false;
        }

        if (!empty($options['categories']) && !has_term($options['categories'], 'product_cat', $product->get_id())) {
            return false;
        }

        $price = $product->get_price();
        if ('' !== $price) {
            $numeric_price = (float) $price;

            if (isset($options['min_price']) && null !== $options['min_price'] && $numeric_price < (float) $options['min_price']) {
                return false;
            }

            if (isset($options['max_price']) && null !== $options['max_price'] && $numeric_price > (float) $options['max_price']) {
                return false;
            }
        } elseif ((isset($options['min_price']) && null !== $options['min_price']) || (isset($options['max_price']) && null !== $options['max_price'])) {
            return false;
        }

        return true;
    }

    private function format_product(WC_Product $product, array $match, $debug) {
        $image_id = (int) $product->get_image_id();
        $image_url = $image_id > 0 ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : '';
        $image_alt = $image_id > 0 ? get_post_meta($image_id, '_wp_attachment_image_alt', true) : '';
        $payload = array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => $product->get_permalink(),
            'type' => $product->get_type(),
            'sku' => (string) $product->get_sku(),
            'price' => $this->format_price($product->get_price()),
            'regularPrice' => $this->format_price($product->get_regular_price()),
            'salePrice' => $this->format_price($product->get_sale_price()),
            'currency' => get_woocommerce_currency(),
            'stockStatus' => $product->get_stock_status(),
            'inStock' => $product->is_in_stock(),
            'onSale' => $product->is_on_sale(),
            'shortDescription' => wp_trim_words(wp_strip_all_tags($product->get_short_description()), 40, '...'),
            'categories' => $this->get_term_names($product->get_id(), 'product_cat'),
            'tags' => $this->get_term_names($product->get_id(), 'product_tag'),
            'image' => array(
                'id' => $image_id,
                'src' => $image_url ? $image_url : '',
                'alt' => is_string($image_alt) ? $image_alt : '',
            ),
        );

        $attributes = $this->format_attributes($product);
        if (!empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        if ($debug) {
            $payload['match'] = array(
                'score' => $match['score'],
                'matchedOn' => $match['matchedOn'],
                'matchedTerms' => $match['matchedTerms'],
                'matchedTermCount' => $match['matchedTermCount'],
                'requiredTermCount' => $match['requiredTermCount'],
                'minimumTermMatches' => $match['minimumTermMatches'],
                'missingTerms' => $match['missingTerms'],
            );
        }

        return $payload;
    }

    private function format_attributes(WC_Product $product) {
        $attributes = array();

        foreach ($product->get_attributes() as $attribute) {
            if (!($attribute instanceof WC_Product_Attribute) || !$attribute->get_visible()) {
                continue;
            }

            if ($attribute->is_taxonomy()) {
                $options = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
            } else {
                $options = $attribute->get_options();
            }

            $options = array_values(
                array_filter(
                    array_map('strval', is_array($options) ? $options : array())
                )
            );

            if (empty($options)) {
                continue;
            }

            $attributes[] = array(
                'name' => wc_attribute_label($attribute->get_name()),
                'options' => $options,
            );
        }

        return $attributes;
    }

    private function get_product_term_text($product_id) {
        $taxonomies = $this->get_searchable_taxonomies();
        if (empty($taxonomies)) {
            return '';
        }

        $terms = wp_get_post_terms($product_id, $taxonomies, array('fields' => 'all'));
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        $parts = array();

        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }

            $parts[] = $term->name;
            $parts[] = $term->slug;
        }

        return implode(' ', array_filter(array_map('strval', $parts)));
    }

    private function get_product_attribute_text(WC_Product $product) {
        $parts = array();

        foreach ($product->get_attributes() as $attribute) {
            if (!($attribute instanceof WC_Product_Attribute) || !$attribute->get_visible()) {
                continue;
            }

            $parts[] = wc_attribute_label($attribute->get_name());

            if ($attribute->is_taxonomy()) {
                $options = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
            } else {
                $options = $attribute->get_options();
            }

            foreach (is_array($options) ? $options : array() as $option) {
                $parts[] = (string) $option;
            }
        }

        return implode(' ', array_filter(array_map('strval', $parts)));
    }

    private function format_price($price) {
        if ('' === (string) $price) {
            return '';
        }

        return wc_format_decimal($price, wc_get_price_decimals());
    }

    private function get_term_names($product_id, $taxonomy) {
        $names = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));

        return is_array($names) ? array_values(array_filter(array_map('strval', $names))) : array();
    }

    private function get_searchable_taxonomies() {
        $taxonomies = array('product_cat', 'product_tag');

        if (function_exists('wc_get_attribute_taxonomy_names')) {
            $taxonomies = array_merge($taxonomies, wc_get_attribute_taxonomy_names());
        }

        $taxonomies = apply_filters('egentify_woocommerce_search_taxonomies', $taxonomies);

        return array_values(array_unique(array_filter(array_map('strval', $taxonomies))));
    }

    private function get_weights() {
        $weights = array(
            'product_id_exact' => 300,
            'sku_exact' => 220,
            'sku_prefix' => 150,
            'sku_contains' => 80,
            'title_exact' => 180,
            'title_prefix' => 140,
            'title_contains' => 70,
            'matched_term' => 18,
            'matched_term_cap' => 90,
            'title_token' => 28,
            'title_token_cap' => 112,
            'taxonomy_token' => 20,
            'taxonomy_token_cap' => 80,
            'short_description_token' => 10,
            'short_description_token_cap' => 30,
            'description_contains' => 20,
            'in_stock' => 12,
            'on_sale' => 6,
            'featured' => 4,
            'sales_divisor' => 25,
            'sales_cap' => 15,
        );

        return apply_filters('egentify_woocommerce_product_search_weights', $weights);
    }

    private function normalize_product_id($product_id) {
        if ($product_id < 1) {
            return 0;
        }

        $post = get_post($product_id);
        if (!($post instanceof WP_Post)) {
            return 0;
        }

        if ('product_variation' === $post->post_type && $post->post_parent > 0) {
            return (int) $post->post_parent;
        }

        return (int) $post->ID;
    }

    private function normalize_text($value) {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower($value);
        $value = str_replace(array("'", '`', '’'), '', $value);
        $value = preg_replace('/[^a-z0-9\/\-\._ ]+/i', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    private function extract_raw_tokens($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return array();
        }

        preg_match_all('/[a-z0-9]+(?:[\/\-\._][a-z0-9]+)*/i', $value, $matches);
        $tokens = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : array();

        return array_values(array_filter(array_map('strval', $tokens)));
    }

    private function build_phrase_lookup_from_text($text) {
        $normalized = $this->normalize_text($text);
        $lookup = $this->build_token_lookup_from_text($normalized, true);
        $raw_tokens = $this->extract_raw_tokens($normalized);

        if (count($raw_tokens) > 1) {
            $compact_parts = array();

            foreach ($raw_tokens as $raw_token) {
                $compact = $this->compact_token($raw_token);

                if ('' === $compact) {
                    continue;
                }

                $compact_parts[] = $compact;
            }

            if (!empty($compact_parts)) {
                $lookup[implode('', $compact_parts)] = true;
            }
        }

        return $lookup;
    }

    private function build_token_lookup_from_text($text, $expand_inflections) {
        $normalized = $this->normalize_text($text);
        $raw_tokens = $this->extract_raw_tokens($normalized);
        $units = array();
        $lookup = array();

        foreach ($raw_tokens as $raw_token) {
            $compact = $this->compact_token($raw_token);

            if ('' === $compact) {
                continue;
            }

            if (preg_match('/[-\/_\.]/', $raw_token)) {
                $parts = preg_split('/[-\/_\.]+/', $raw_token);
                $parts = is_array($parts) ? $parts : array();

                foreach ($parts as $part) {
                    $part = $this->compact_token($part);

                    if ('' === $part || !$this->is_meaningful_token($part)) {
                        continue;
                    }

                    $units[] = $part;
                    $lookup[$part] = true;
                }

                if ($this->is_meaningful_token($compact)) {
                    $lookup[$compact] = true;
                }

                continue;
            }

            if ($this->looks_like_identifier($compact)) {
                $units[] = $compact;
                $lookup[$compact] = true;
                continue;
            }

            if ($this->is_meaningful_token($compact)) {
                $units[] = $compact;
                $lookup[$compact] = true;
            }
        }

        for ($index = 0; $index < count($units) - 1; $index++) {
            if (!$this->should_build_compound_variant($units[$index], $units[$index + 1])) {
                continue;
            }

            $lookup[$units[$index] . $units[$index + 1]] = true;
        }

        if ($expand_inflections) {
            foreach (array_keys($lookup) as $token) {
                if ($this->looks_like_identifier($token) || $this->is_unit_token($token)) {
                    continue;
                }

                foreach ($this->expand_inflection_variants($token) as $variant) {
                    if ($this->is_meaningful_token($variant)) {
                        $lookup[$variant] = true;
                    }
                }
            }
        }

        return $lookup;
    }

    private function apply_synonym_groups_to_lookup(array $lookup, $normalized_text, array $synonym_groups) {
        foreach ($synonym_groups as $group) {
            $matched = false;
            $group_lookup = array();

            foreach ($group as $phrase) {
                $phrase_lookup = $this->build_phrase_lookup_from_text($phrase);

                if ($this->text_contains_phrase($normalized_text, $phrase) || $this->lookups_intersect($lookup, $phrase_lookup)) {
                    $matched = true;
                }

                $group_lookup = $this->merge_lookups($group_lookup, $phrase_lookup);
            }

            if ($matched) {
                $lookup = $this->merge_lookups($lookup, $group_lookup);
            }
        }

        return $lookup;
    }

    private function normalize_synonym_groups(array $synonym_groups) {
        $normalized_groups = array();

        foreach ($synonym_groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $normalized_group = array();

            foreach ($group as $phrase) {
                $phrase = $this->normalize_text($phrase);

                if ('' === $phrase) {
                    continue;
                }

                $normalized_group[] = $phrase;
            }

            $normalized_group = $this->unique_preserving_order($normalized_group);

            if (count($normalized_group) > 1) {
                $normalized_groups[] = $normalized_group;
            }
        }

        return $normalized_groups;
    }

    private function lookups_intersect(array $left, array $right) {
        foreach ($left as $token => $value) {
            if (isset($right[$token])) {
                return true;
            }
        }

        return false;
    }

    private function merge_lookups() {
        $merged = array();

        foreach (func_get_args() as $lookup) {
            foreach ((array) $lookup as $token => $value) {
                $merged[(string) $token] = true;
            }
        }

        return $merged;
    }

    private function should_build_compound_variant($left, $right) {
        if ('' === $left || '' === $right) {
            return false;
        }

        if (ctype_digit($left) || ctype_digit($right)) {
            return true;
        }

        if ($this->is_unit_token($left) || $this->is_unit_token($right)) {
            return true;
        }

        if (strlen($left) <= 2 || strlen($right) <= 2) {
            return true;
        }

        return false;
    }

    private function build_term_variants($term) {
        $variants = array($term);

        if (!$this->looks_like_identifier($term) && !$this->is_unit_token($term)) {
            $variants = array_merge($variants, $this->expand_inflection_variants($term));
        }

        return $this->unique_preserving_order($variants);
    }

    private function expand_inflection_variants($token) {
        $variants = array($token);
        $is_plural_form = false;

        if (preg_match('/ies$/', $token) && strlen($token) > 4) {
            $variants[] = substr($token, 0, -3) . 'y';
            $is_plural_form = true;
        } elseif (preg_match('/(xes|zes|ches|shes|sses)$/', $token)) {
            $variants[] = substr($token, 0, -2);
            $is_plural_form = true;
        } elseif (preg_match('/s$/', $token) && !preg_match('/ss$/', $token) && strlen($token) > 3) {
            $variants[] = substr($token, 0, -1);
            $is_plural_form = true;
        }

        if (!$is_plural_form) {
            if (preg_match('/y$/', $token) && !preg_match('/[aeiou]y$/', $token)) {
                $variants[] = substr($token, 0, -1) . 'ies';
            } elseif (preg_match('/(s|x|z|ch|sh)$/', $token)) {
                $variants[] = $token . 'es';
            } elseif (strlen($token) > 2) {
                $variants[] = $token . 's';
            }
        }

        return $this->unique_preserving_order($variants);
    }

    private function is_meaningful_token($token) {
        $token = trim((string) $token);

        if ('' === $token) {
            return false;
        }

        if (in_array($token, $this->get_ignored_tokens(), true)) {
            return false;
        }

        if (ctype_digit($token)) {
            return true;
        }

        if (in_array($token, $this->get_short_meaningful_tokens(), true)) {
            return true;
        }

        return strlen($token) >= 2;
    }

    private function should_use_as_seed_term($token) {
        if (!$this->is_meaningful_token($token)) {
            return false;
        }

        if (ctype_digit($token) || $this->looks_like_identifier($token)) {
            return true;
        }

        return strlen($token) >= 2;
    }

    private function get_ignored_tokens() {
        return array(
            'a',
            'an',
            'and',
            'are',
            'as',
            'at',
            'be',
            'but',
            'by',
            'can',
            'could',
            'do',
            'does',
            'for',
            'from',
            'how',
            'i',
            'if',
            'in',
            'is',
            'it',
            'me',
            'my',
            'of',
            'on',
            'or',
            'our',
            'should',
            'so',
            'that',
            'the',
            'their',
            'there',
            'they',
            'this',
            'to',
            'was',
            'we',
            'what',
            'when',
            'where',
            'which',
            'who',
            'why',
            'will',
            'with',
            'would',
            'you',
            'your',
        );
    }

    private function get_short_meaningful_tokens() {
        return array(
            'c',
            'd',
            'g',
            'k',
            'l',
            'm',
            's',
            't',
            'tv',
            'uhd',
            'hd',
            'xl',
            'xs',
            'xxl',
            'mg',
            'ml',
            'oz',
            'lb',
            'ft',
            'mm',
            'cm',
            'in',
            'pk',
        );
    }

    private function is_unit_token($token) {
        return in_array($token, array('mg', 'g', 'kg', 'ml', 'l', 'oz', 'lb', 'w', 'v', 'mm', 'cm', 'm', 'ft', 'in', 'pk', 'pack'), true);
    }

    private function looks_like_identifier($token) {
        return (bool) preg_match('/[a-z]+\d|\d+[a-z]|^[a-z0-9]{2,}\d{2,}[a-z0-9]*$/i', $token);
    }

    private function compact_token($token) {
        return preg_replace('/[^a-z0-9]+/i', '', (string) $token);
    }

    private function text_contains_phrase($text, $phrase) {
        $text = trim((string) $text);
        $phrase = trim((string) $phrase);

        if ('' === $text || '' === $phrase) {
            return false;
        }

        return false !== strpos(' ' . $text . ' ', ' ' . $phrase . ' ');
    }

    private function unique_preserving_order(array $values) {
        $unique = array();

        foreach ($values as $value) {
            $value = (string) $value;

            if ('' === $value || isset($unique[$value])) {
                continue;
            }

            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    private function format_required_terms_for_debug(array $required_terms) {
        $formatted = array();

        foreach ($required_terms as $required_term) {
            $formatted[] = array(
                'term' => $required_term['term'],
                'variants' => $required_term['variants'],
            );
        }

        return $formatted;
    }

    private function pluck_ids($ids) {
        $ids = is_array($ids) ? $ids : array();
        $ids = array_map('absint', $ids);
        $ids = array_filter($ids);

        return array_values(array_unique($ids));
    }

}
