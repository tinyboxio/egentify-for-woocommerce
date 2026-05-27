<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Egentify_WooCommerce_Content_Search {
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
        $types = $this->normalize_post_types($options['types'] ?? array());
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
        $candidates = $this->collect_candidates($query, $query_profile, $types, $candidate_limit);
        $weights = $this->get_weights();
        $results = array();

        if (!empty($candidates)) {
            update_object_term_cache(array_keys($candidates), $types);
        }

        foreach ($candidates as $post_id => $candidate) {
            $post = get_post($post_id);

            if (!($post instanceof WP_Post) || !$this->passes_filters($post, $types)) {
                continue;
            }

            $match = $this->score_post($post, $query_profile, $weights, $candidate, $synonym_groups);
            if ($match['score'] <= 0) {
                continue;
            }

            $results[] = array(
                'post' => $post,
                'match' => $match,
            );
        }

        usort(
            $results,
            function ($left, $right) {
                if ($left['match']['score'] === $right['match']['score']) {
                    $left_type_priority = $this->get_post_type_sort_priority($left['post']->post_type);
                    $right_type_priority = $this->get_post_type_sort_priority($right['post']->post_type);

                    if ($left_type_priority !== $right_type_priority) {
                        return $left_type_priority <=> $right_type_priority;
                    }

                    return strcasecmp($left['post']->post_title, $right['post']->post_title);
                }

                return $right['match']['score'] <=> $left['match']['score'];
            }
        );

        $term_match_threshold = $this->determine_term_match_threshold($results, $limit, count($query_profile['requiredTerms']));
        $results = $this->filter_results_by_term_match_threshold($results, $term_match_threshold);
        $formatted_results = array();
        $debug = !empty($options['debug']);

        foreach ($results as $result) {
            $formatted_results[] = $this->format_post($result['post'], $result['match'], $debug);

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
            $payload['types'] = $types;
        }

        return $payload;
    }

    public function get_content_item($post_id, array $options = array()) {
        $post = get_post((int) $post_id);

        if (!($post instanceof WP_Post) || !$this->passes_filters($post, array('post', 'page'))) {
            return null;
        }

        return $this->format_post_detail($post, $options);
    }

    private function normalize_post_types($types) {
        if (is_string($types)) {
            $types = explode(',', $types);
        }

        $types = is_array($types) ? $types : array();
        $types = array_map('sanitize_key', $types);
        $types = array_values(array_intersect($types, array('post', 'page')));

        if (empty($types)) {
            $types = array('post', 'page');
        }

        return array_values(array_unique($types));
    }

    private function collect_candidates($query, array $query_profile, array $types, $limit) {
        $candidates = array();
        $slug_query = sanitize_title($query);

        if (ctype_digit($query_profile['normalizedQuery'])) {
            $this->add_candidate($candidates, (int) $query_profile['normalizedQuery'], 'post_id_exact', 240);
        }

        $title_matches = $this->find_posts_by_title_all_modes($query, $types, $limit);
        foreach ($title_matches['exact'] as $post_id) {
            $this->add_candidate($candidates, $post_id, 'title_exact_seed', 56);
        }
        foreach ($title_matches['prefix'] as $post_id) {
            $this->add_candidate($candidates, $post_id, 'title_prefix_seed', 40);
        }
        foreach ($title_matches['contains'] as $post_id) {
            $this->add_candidate($candidates, $post_id, 'title_contains_seed', 24);
        }

        if ('' !== $slug_query) {
            $slug_matches = $this->find_posts_by_slug_all_modes($slug_query, $types, $limit);
            foreach ($slug_matches['exact'] as $post_id) {
                $this->add_candidate($candidates, $post_id, 'slug_exact_seed', 44);
            }
            foreach ($slug_matches['prefix'] as $post_id) {
                $this->add_candidate($candidates, $post_id, 'slug_prefix_seed', 28);
            }
            foreach ($slug_matches['contains'] as $post_id) {
                $this->add_candidate($candidates, $post_id, 'slug_contains_seed', 16);
            }
        }

        if (count($candidates) >= $limit) {
            return $candidates;
        }

        foreach ($query_profile['seedTerms'] as $seed_term) {
            foreach ($this->find_posts_by_title($seed_term, 'contains', $types, $limit) as $post_id) {
                $this->add_candidate($candidates, $post_id, 'title_term_seed', 12);
            }

            foreach ($this->find_posts_by_taxonomy_term($seed_term, $types, $limit) as $post_id) {
                $this->add_candidate($candidates, $post_id, 'taxonomy_seed', 12);
            }
        }

        if (count($candidates) >= $limit) {
            return $candidates;
        }

        foreach ($this->find_posts_by_wp_search($query, $types, $limit) as $post_id) {
            $this->add_candidate($candidates, $post_id, 'wp_search_seed', 8);
        }

        if (count($candidates) < $limit) {
            $fallback_seed_terms = array_slice($query_profile['seedTerms'], 0, 4);
            $fallback_limit = min(8, max(4, (int) ceil($limit / 6)));

            foreach ($fallback_seed_terms as $seed_term) {
                foreach ($this->find_posts_by_wp_search($seed_term, $types, $fallback_limit) as $post_id) {
                    $this->add_candidate($candidates, $post_id, 'wp_search_term_seed', 4);
                }
            }
        }

        return $candidates;
    }

    private function add_candidate(array &$candidates, $post_id, $reason, $seed_score) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }

        if (!isset($candidates[$post_id])) {
            $candidates[$post_id] = array(
                'seedScore' => 0,
                'matchedOn' => array(),
            );
        }

        $candidates[$post_id]['seedScore'] += (int) $seed_score;

        if (!in_array($reason, $candidates[$post_id]['matchedOn'], true)) {
            $candidates[$post_id]['matchedOn'][] = $reason;
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
            'queryTermPairs' => $this->build_query_term_pairs($required_terms),
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

                if ('' !== $part) {
                    $units[] = $part;
                }
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
                    $required_term['variants'] = array_merge($required_term['variants'], array_keys($group_lookup));
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
                if (!$this->should_use_as_seed_term($variant) || isset($seed_lookup[$variant])) {
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

    private function build_query_term_pairs(array $required_terms) {
        $pairs = array();
        $seen = array();
        $count = count($required_terms);

        for ($index = 0; $index < $count - 1; $index++) {
            $left = isset($required_terms[$index]['term']) ? (string) $required_terms[$index]['term'] : '';
            $right = isset($required_terms[$index + 1]['term']) ? (string) $required_terms[$index + 1]['term'] : '';
            $compound = $this->compact_token($left . $right);

            if ('' === $left || '' === $right || '' === $compound || isset($seen[$compound])) {
                continue;
            }

            $seen[$compound] = true;
            $pairs[] = array(
                'phrase' => $left . ' ' . $right,
                'compound' => $compound,
            );
        }

        return $pairs;
    }

    private function find_posts_by_title_all_modes($query, array $types, $limit) {
        global $wpdb;

        $result = array('exact' => array(), 'prefix' => array(), 'contains' => array());
        $query = trim((string) $query);
        if ('' === $query) {
            return $result;
        }

        $escaped = $wpdb->esc_like($query);
        $contains_value = '%' . $escaped . '%';
        $prefix_value = $escaped . '%';
        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, array($contains_value, $query, $prefix_value, (int) $limit));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $type_placeholders is composed of literal '%s' tokens only; placeholder count matches $params. Cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE post_type IN ({$type_placeholders})
              AND post_status = 'publish'
              AND post_title LIKE %s
            ORDER BY CASE
                WHEN post_title = %s THEN 0
                WHEN post_title LIKE %s THEN 1
                ELSE 2
            END, post_date DESC
            LIMIT %d",
            $params
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $query_lower = mb_strtolower($query);

        foreach ($rows as $row) {
            $post_id = intval($row->ID);
            if ($post_id < 1) {
                continue;
            }

            $title_lower = mb_strtolower($row->post_title);

            $result['contains'][] = $post_id;

            if (0 === mb_strpos($title_lower, $query_lower)) {
                $result['prefix'][] = $post_id;
            }

            if ($title_lower === $query_lower) {
                $result['exact'][] = $post_id;
            }
        }

        return $result;
    }

    private function find_posts_by_title($query, $mode, array $types, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        if ('' === $query) {
            return array();
        }

        if ('prefix' === $mode) {
            $value = $wpdb->esc_like($query) . '%';
            $operator = 'LIKE';
        } elseif ('contains' === $mode) {
            $value = '%' . $wpdb->esc_like($query) . '%';
            $operator = 'LIKE';
        } else {
            $value = $query;
            $operator = '=';
        }

        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, array($value, (int) $limit));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $type_placeholders is composed of literal '%s' tokens only; $operator is from a hardcoded whitelist (LIKE or =). Cached upstream via HTTP cache headers on the REST response.
        if ('=' === $operator) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ({$type_placeholders})
                  AND post_status = 'publish'
                  AND post_title = %s
                ORDER BY post_date DESC
                LIMIT %d",
                $params
            ));
        } else {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ({$type_placeholders})
                  AND post_status = 'publish'
                  AND post_title LIKE %s
                ORDER BY post_date DESC
                LIMIT %d",
                $params
            ));
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_posts_by_slug_all_modes($query, array $types, $limit) {
        global $wpdb;

        $result = array('exact' => array(), 'prefix' => array(), 'contains' => array());
        $query = trim((string) $query);
        if ('' === $query) {
            return $result;
        }

        $escaped = $wpdb->esc_like($query);
        $contains_value = '%' . $escaped . '%';
        $prefix_value = $escaped . '%';
        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, array($contains_value, $query, $prefix_value, (int) $limit));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $type_placeholders is composed of literal '%s' tokens only; placeholder count matches $params. Cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_name
            FROM {$wpdb->posts}
            WHERE post_type IN ({$type_placeholders})
              AND post_status = 'publish'
              AND post_name LIKE %s
            ORDER BY CASE
                WHEN post_name = %s THEN 0
                WHEN post_name LIKE %s THEN 1
                ELSE 2
            END, post_date DESC
            LIMIT %d",
            $params
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $query_lower = mb_strtolower($query);

        foreach ($rows as $row) {
            $post_id = intval($row->ID);
            if ($post_id < 1) {
                continue;
            }

            $slug_lower = mb_strtolower($row->post_name);

            $result['contains'][] = $post_id;

            if (0 === mb_strpos($slug_lower, $query_lower)) {
                $result['prefix'][] = $post_id;
            }

            if ($slug_lower === $query_lower) {
                $result['exact'][] = $post_id;
            }
        }

        return $result;
    }

    private function find_posts_by_slug($query, $mode, array $types, $limit) {
        global $wpdb;

        $query = trim((string) $query);
        if ('' === $query) {
            return array();
        }

        if ('prefix' === $mode) {
            $value = $wpdb->esc_like($query) . '%';
            $operator = 'LIKE';
        } elseif ('contains' === $mode) {
            $value = '%' . $wpdb->esc_like($query) . '%';
            $operator = 'LIKE';
        } else {
            $value = $query;
            $operator = '=';
        }

        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, array($value, (int) $limit));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $type_placeholders is composed of literal '%s' tokens only; $operator is from a hardcoded whitelist (LIKE or =). Cached upstream via HTTP cache headers on the REST response.
        if ('=' === $operator) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ({$type_placeholders})
                  AND post_status = 'publish'
                  AND post_name = %s
                ORDER BY post_date DESC
                LIMIT %d",
                $params
            ));
        } else {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ({$type_placeholders})
                  AND post_status = 'publish'
                  AND post_name LIKE %s
                ORDER BY post_date DESC
                LIMIT %d",
                $params
            ));
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_posts_by_taxonomy_term($query, array $types, $limit) {
        global $wpdb;

        $taxonomies = $this->get_searchable_taxonomies($types);
        if (empty($taxonomies)) {
            return array();
        }

        $type_placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $taxonomy_placeholders = implode(', ', array_fill(0, count($taxonomies), '%s'));
        $value = '%' . $wpdb->esc_like((string) $query) . '%';
        $params = array_merge($taxonomies, $types, array($value, $value, (int) $limit));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Both placeholder strings are composed of literal '%s' tokens only; placeholder count matches $params. Cached upstream via HTTP cache headers on the REST response.
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT posts.ID
            FROM {$wpdb->terms} terms
            INNER JOIN {$wpdb->term_taxonomy} taxonomy ON taxonomy.term_id = terms.term_id
            INNER JOIN {$wpdb->term_relationships} relationships ON relationships.term_taxonomy_id = taxonomy.term_taxonomy_id
            INNER JOIN {$wpdb->posts} posts ON posts.ID = relationships.object_id
            WHERE taxonomy.taxonomy IN ({$taxonomy_placeholders})
              AND posts.post_type IN ({$type_placeholders})
              AND posts.post_status = 'publish'
              AND (terms.name LIKE %s OR terms.slug LIKE %s)
            ORDER BY posts.post_date DESC
            LIMIT %d",
            $params
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $this->pluck_ids($rows);
    }

    private function find_posts_by_wp_search($query, array $types, $limit) {
        $search = new WP_Query(
            array(
                'post_type' => $types,
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

    private function score_post(WP_Post $post, array $query_profile, array $weights, array $candidate, array $synonym_groups) {
        $post_profile = $this->build_post_profile($post, $synonym_groups);
        $matched_terms = $this->get_matched_required_terms($query_profile['requiredTerms'], $post_profile['allLookup']);
        $missing_terms = $this->get_missing_required_terms($query_profile['requiredTerms'], $post_profile['allLookup']);
        $matched_term_count = count($matched_terms);
        $required_term_count = count($query_profile['requiredTerms']);
        $minimum_term_matches = $this->get_minimum_required_term_matches($required_term_count);
        $score = isset($candidate['seedScore']) ? (int) $candidate['seedScore'] : 0;
        $matched_on = isset($candidate['matchedOn']) ? $candidate['matchedOn'] : array();
        $normalized_query = $query_profile['normalizedQuery'];
        $slug_query = sanitize_title($normalized_query);

        if ((string) $post->ID === $normalized_query) {
            $score += $weights['post_id_exact'];
            $matched_on[] = 'post_id_exact';
        }

        if ('' !== $post_profile['slug']) {
            if ($post_profile['slug'] === $slug_query) {
                $score += $weights['slug_exact'];
                $matched_on[] = 'slug_exact';
            } elseif (0 === strpos($post_profile['slug'], $slug_query)) {
                $score += $weights['slug_prefix'];
                $matched_on[] = 'slug_prefix';
            } elseif (false !== strpos($post_profile['slug'], $slug_query)) {
                $score += $weights['slug_contains'];
                $matched_on[] = 'slug_contains';
            }
        }

        if ('' !== $post_profile['title']) {
            if ($post_profile['title'] === $normalized_query) {
                $score += $weights['title_exact'];
                $matched_on[] = 'title_exact';
            } elseif (0 === strpos($post_profile['title'], $normalized_query)) {
                $score += $weights['title_prefix'];
                $matched_on[] = 'title_prefix';
            } elseif (false !== strpos($post_profile['title'], $normalized_query)) {
                $score += $weights['title_contains'];
                $matched_on[] = 'title_contains';
            }
        }

        if ($matched_term_count > 0) {
            $score += min($weights['matched_term_cap'], $matched_term_count * $weights['matched_term']);
            $matched_on[] = 'query_terms';
        }

        $title_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $post_profile['titleLookup']);
        if ($title_hits > 0) {
            $score += min($weights['title_token_cap'], $title_hits * $weights['title_token']);
            $matched_on[] = 'title_terms';
        }

        $taxonomy_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $post_profile['taxonomyLookup']);
        if ($taxonomy_hits > 0) {
            $score += min($weights['taxonomy_token_cap'], $taxonomy_hits * $weights['taxonomy_token']);
            $matched_on[] = 'taxonomy_terms';
        }

        $excerpt_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $post_profile['excerptLookup']);
        if ($excerpt_hits > 0) {
            $score += min($weights['excerpt_token_cap'], $excerpt_hits * $weights['excerpt_token']);
            $matched_on[] = 'excerpt_terms';
        }

        $navigation_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $post_profile['navigationLookup']);
        if ('page' === $post->post_type && $navigation_hits > 0) {
            $score += min($weights['page_navigation_token_cap'], $navigation_hits * $weights['page_navigation_token']);
            $matched_on[] = 'page_navigation_terms';
        }

        $navigation_phrase_hits = $this->count_query_term_pair_hits(
            $query_profile['queryTermPairs'],
            $post_profile['navigationLookup'],
            $post_profile['navigationText']
        );
        if ($navigation_phrase_hits > 0) {
            $score += min($weights['navigation_phrase_cap'], $navigation_phrase_hits * $weights['navigation_phrase']);
            $matched_on[] = 'navigation_phrase';
        }

        $content_hits = $this->count_required_term_hits($query_profile['requiredTerms'], $post_profile['contentLookup']);
        if ($content_hits > 0) {
            $score += min($weights['content_token_cap'], $content_hits * $weights['content_token']);
            $matched_on[] = 'content_terms';
        }

        $content_phrase_hits = $this->count_query_term_pair_hits(
            $query_profile['queryTermPairs'],
            $post_profile['contentLookup'],
            $post_profile['content']
        );
        if ($content_phrase_hits > 0) {
            $score += min($weights['content_phrase_cap'], $content_phrase_hits * $weights['content_phrase']);
            $matched_on[] = 'content_phrase';
        }

        if ('' !== $post_profile['content'] && false !== strpos($post_profile['content'], $normalized_query)) {
            $score += $weights['content_contains'];
            $matched_on[] = 'content_contains';
        }

        if ('page' === $post->post_type) {
            if ('' !== $post_profile['title']) {
                if ($post_profile['title'] === $normalized_query) {
                    $score += $weights['page_title_exact'];
                    $matched_on[] = 'page_title_exact';
                } elseif (0 === strpos($post_profile['title'], $normalized_query)) {
                    $score += $weights['page_title_prefix'];
                    $matched_on[] = 'page_title_prefix';
                }
            }

            if ('' !== $post_profile['slug']) {
                if ($post_profile['slug'] === $slug_query) {
                    $score += $weights['page_slug_exact'];
                    $matched_on[] = 'page_slug_exact';
                } elseif (0 === strpos($post_profile['slug'], $slug_query)) {
                    $score += $weights['page_slug_prefix'];
                    $matched_on[] = 'page_slug_prefix';
                }
            }
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

    private function build_post_profile(WP_Post $post, array $synonym_groups) {
        $title = $this->normalize_text($post->post_title);
        $slug = sanitize_title($post->post_name);
        $excerpt_text = $this->normalize_text($this->get_post_summary($post));
        $content = $this->normalize_text($post->post_excerpt . ' ' . $post->post_content);
        $taxonomy_text = $this->normalize_text($this->get_post_term_text($post));

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
        $excerpt_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($excerpt_text, true),
            $excerpt_text,
            $synonym_groups
        );
        $content_lookup = $this->apply_synonym_groups_to_lookup(
            $this->build_token_lookup_from_text($content, true),
            $content,
            $synonym_groups
        );
        $slug_lookup = $this->build_token_lookup_from_text($slug, false);
        $navigation_text = $this->normalize_text($title . ' ' . str_replace(array('-', '_', '/'), ' ', (string) $post->post_name));

        return array(
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'titleLookup' => $title_lookup,
            'taxonomyLookup' => $taxonomy_lookup,
            'excerptLookup' => $excerpt_lookup,
            'navigationText' => $navigation_text,
            'navigationLookup' => $this->merge_lookups($title_lookup, $slug_lookup),
            'contentLookup' => $content_lookup,
            'allLookup' => $this->merge_lookups($title_lookup, $taxonomy_lookup, $excerpt_lookup, $content_lookup, $slug_lookup),
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

    private function count_query_term_pair_hits(array $query_term_pairs, array $lookup, $normalized_text) {
        $count = 0;
        $normalized_text = (string) $normalized_text;

        foreach ($query_term_pairs as $pair) {
            $compound = isset($pair['compound']) ? (string) $pair['compound'] : '';
            $phrase = isset($pair['phrase']) ? (string) $pair['phrase'] : '';

            if (('' !== $compound && isset($lookup[$compound])) || ('' !== $phrase && $this->text_contains_phrase($normalized_text, $phrase))) {
                $count++;
            }
        }

        return $count;
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

        return 1;
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

    private function get_post_type_sort_priority($post_type) {
        return 'page' === $post_type ? 0 : 1;
    }

    private function term_variants_intersect_lookup(array $variants, array $lookup) {
        foreach ($variants as $variant) {
            if (isset($lookup[$variant])) {
                return true;
            }
        }

        return false;
    }

    private function passes_filters(WP_Post $post, array $types) {
        return 'publish' === $post->post_status && in_array($post->post_type, $types, true);
    }

    private function format_post(WP_Post $post, array $match, $debug) {
        $payload = $this->build_post_payload($post);

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

    private function format_post_detail(WP_Post $post, array $options) {
        $payload = $this->build_post_payload($post);
        $content_html = $this->get_rendered_post_content_html($post);

        $payload['contentText'] = $this->get_readable_content_text($content_html);

        if (!empty($options['include_html'])) {
            $payload['contentHtml'] = $content_html;
        }

        return $payload;
    }

    private function build_post_payload(WP_Post $post) {
        $payload = array(
            'id' => (int) $post->ID,
            'title' => get_the_title($post),
            'slug' => (string) $post->post_name,
            'type' => (string) $post->post_type,
            'permalink' => get_permalink($post),
            'excerpt' => $this->get_post_summary($post),
            'date' => mysql2date('c', $post->post_date_gmt, false),
            'modified' => mysql2date('c', $post->post_modified_gmt, false),
        );

        if ('post' === $post->post_type) {
            $payload['categories'] = $this->get_term_names($post->ID, 'category');
            $payload['tags'] = $this->get_term_names($post->ID, 'post_tag');
        }

        return $payload;
    }

    private function get_post_summary(WP_Post $post) {
        $summary = has_excerpt($post) ? $post->post_excerpt : $post->post_content;

        return wp_trim_words(wp_strip_all_tags((string) $summary), 40, '...');
    }

    private function get_rendered_post_content_html(WP_Post $post) {
        $previous_post = isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post ? $GLOBALS['post'] : null;

        $GLOBALS['post'] = $post;
        setup_postdata($post);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentional: applying WordPress core 'the_content' filter to render post content with shortcodes/blocks, matching standard theme behavior.
        $content_html = (string) apply_filters('the_content', (string) $post->post_content);

        if ($previous_post instanceof WP_Post) {
            $GLOBALS['post'] = $previous_post;
            setup_postdata($previous_post);
        } else {
            wp_reset_postdata();
        }

        return $content_html;
    }

    private function get_readable_content_text($content_html) {
        $content_html = preg_replace('/<(\/p|\/div|br\s*\/?|\/li|\/h[1-6])>/i', "\n", (string) $content_html);
        $content_text = html_entity_decode(wp_strip_all_tags((string) $content_html), ENT_QUOTES, get_bloginfo('charset'));
        $content_text = preg_replace("/[ \t]+/", ' ', (string) $content_text);
        $content_text = preg_replace("/\n{3,}/", "\n\n", (string) $content_text);

        return trim((string) $content_text);
    }

    private function get_post_term_text(WP_Post $post) {
        $taxonomies = $this->get_searchable_taxonomies(array($post->post_type));
        if (empty($taxonomies)) {
            return '';
        }

        $terms = wp_get_post_terms($post->ID, $taxonomies, array('fields' => 'all'));
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

    private function get_term_names($post_id, $taxonomy) {
        $names = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));

        return is_array($names) ? array_values(array_filter(array_map('strval', $names))) : array();
    }

    private function get_searchable_taxonomies(array $types) {
        $taxonomies = array();

        foreach ($types as $type) {
            $type_taxonomies = get_object_taxonomies($type, 'names');

            foreach (is_array($type_taxonomies) ? $type_taxonomies : array() as $taxonomy) {
                $taxonomy_object = get_taxonomy($taxonomy);

                if ($taxonomy_object instanceof WP_Taxonomy && $taxonomy_object->public) {
                    $taxonomies[] = $taxonomy;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $taxonomies))));
    }

    private function get_weights() {
        $weights = array(
            'post_id_exact' => 240,
            'slug_exact' => 170,
            'slug_prefix' => 120,
            'slug_contains' => 60,
            'title_exact' => 180,
            'title_prefix' => 140,
            'title_contains' => 70,
            'matched_term' => 18,
            'matched_term_cap' => 90,
            'title_token' => 28,
            'title_token_cap' => 112,
            'taxonomy_token' => 18,
            'taxonomy_token_cap' => 72,
            'excerpt_token' => 12,
            'excerpt_token_cap' => 36,
            'page_navigation_token' => 18,
            'page_navigation_token_cap' => 36,
            'navigation_phrase' => 18,
            'navigation_phrase_cap' => 36,
            'content_token' => 8,
            'content_token_cap' => 32,
            'content_phrase' => 8,
            'content_phrase_cap' => 24,
            'content_contains' => 18,
            'page_title_exact' => 48,
            'page_title_prefix' => 24,
            'page_slug_exact' => 52,
            'page_slug_prefix' => 28,
        );

        return apply_filters('egentify_woocommerce_content_search_weights', $weights);
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
        preg_match_all('/[a-z0-9]+(?:[-\/_\.][a-z0-9]+)*/i', (string) $value, $matches);
        $tokens = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : array();

        return array_values(array_filter(array_map('strval', $tokens)));
    }

    private function build_phrase_lookup_from_text($text) {
        $lookup = array();

        foreach ($this->extract_raw_tokens($text) as $raw_token) {
            foreach ($this->extract_required_units_from_raw_token($raw_token) as $token) {
                $lookup[$token] = true;

                foreach ($this->build_term_variants($token) as $variant) {
                    $lookup[$variant] = true;
                }
            }
        }

        return $lookup;
    }

    private function build_token_lookup_from_text($text, $expand_inflections) {
        $lookup = array();
        $raw_tokens = $this->extract_raw_tokens($text);
        $units = array();

        foreach ($raw_tokens as $raw_token) {
            foreach ($this->extract_required_units_from_raw_token($raw_token) as $unit) {
                $units[] = $unit;
                $lookup[$unit] = true;

                if ($expand_inflections) {
                    foreach ($this->build_term_variants($unit) as $variant) {
                        $lookup[$variant] = true;
                    }
                }
            }
        }

        $unit_count = count($units);
        for ($index = 0; $index < $unit_count - 1; $index++) {
            $left = $units[$index];
            $right = $units[$index + 1];

            if (!$this->should_build_compound_variant($left, $right)) {
                continue;
            }

            $lookup[$this->compact_token($left . $right)] = true;
        }

        return $lookup;
    }

    private function apply_synonym_groups_to_lookup(array $lookup, $normalized_text, array $synonym_groups) {
        foreach ($synonym_groups as $group) {
            $matched = false;
            $group_lookup = array();

            foreach ($group as $phrase) {
                if ($this->text_contains_phrase($normalized_text, $phrase) || $this->lookups_intersect($lookup, $this->build_phrase_lookup_from_text($phrase))) {
                    $matched = true;
                }

                $group_lookup = $this->merge_lookups($group_lookup, $this->build_phrase_lookup_from_text($phrase));
            }

            if ($matched) {
                $lookup = $this->merge_lookups($lookup, $group_lookup);
            }
        }

        return $lookup;
    }

    private function normalize_synonym_groups(array $synonym_groups) {
        $normalized = array();

        foreach ($synonym_groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $normalized_group = array();

            foreach ($group as $phrase) {
                $phrase = $this->normalize_text($phrase);

                if ('' !== $phrase) {
                    $normalized_group[] = $phrase;
                }
            }

            $normalized_group = $this->unique_preserving_order($normalized_group);

            if (count($normalized_group) >= 2) {
                $normalized[] = $normalized_group;
            }
        }

        return $normalized;
    }

    private function lookups_intersect(array $left, array $right) {
        foreach ($left as $key => $value) {
            if ($value && isset($right[$key])) {
                return true;
            }
        }

        return false;
    }

    private function merge_lookups() {
        $merged = array();

        foreach (func_get_args() as $lookup) {
            foreach (is_array($lookup) ? $lookup : array() as $key => $value) {
                if ($value) {
                    $merged[$key] = true;
                }
            }
        }

        return $merged;
    }

    private function should_build_compound_variant($left, $right) {
        if ('' === $left || '' === $right) {
            return false;
        }

        if (ctype_digit($left) && $this->is_unit_token($right)) {
            return true;
        }

        return !$this->looks_like_identifier($left) && !$this->looks_like_identifier($right);
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

        if (preg_match('/ies$/', $token) && strlen($token) > 4) {
            $variants[] = substr($token, 0, -3) . 'y';
        } elseif (preg_match('/(xes|zes|ches|shes|sses)$/', $token)) {
            $variants[] = substr($token, 0, -2);
        } elseif (preg_match('/s$/', $token) && !preg_match('/ss$/', $token) && strlen($token) > 3) {
            $variants[] = substr($token, 0, -1);
        }

        if (preg_match('/y$/', $token) && !preg_match('/[aeiou]y$/', $token)) {
            $variants[] = substr($token, 0, -1) . 'ies';
        } elseif (preg_match('/(s|x|z|ch|sh)$/', $token)) {
            $variants[] = $token . 'es';
        } elseif (strlen($token) > 2) {
            $variants[] = $token . 's';
        }

        return array_values(array_unique($variants));
    }

    private function is_meaningful_token($token) {
        $token = trim((string) $token);

        if ('' === $token || in_array($token, $this->get_ignored_tokens(), true)) {
            return false;
        }

        if (ctype_digit($token) || in_array($token, $this->get_short_meaningful_tokens(), true)) {
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
        return array('c', 'd', 'g', 'k', 'l', 'm', 's', 't', 'tv', 'uhd', 'hd', 'xl', 'xs', 'xxl', 'mg', 'ml', 'oz', 'lb', 'ft', 'mm', 'cm', 'in', 'pk');
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

        return false !== strpos($text, $phrase);
    }

    private function unique_preserving_order(array $values) {
        $unique = array();
        $seen = array();

        foreach ($values as $value) {
            $value = (string) $value;

            if ('' === $value || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $unique[] = $value;
        }

        return $unique;
    }

    private function format_required_terms_for_debug(array $required_terms) {
        $formatted = array();

        foreach ($required_terms as $required_term) {
            $formatted[] = array(
                'term' => $required_term['term'],
                'variants' => array_values($required_term['variants']),
            );
        }

        return $formatted;
    }

    private function pluck_ids($ids) {
        if (!is_array($ids)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
