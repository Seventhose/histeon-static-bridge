<?php
/**
 * QA only, disposable stacks: two scripts Complianz has to hold back until consent, one statistics and one
 * marketing. complianz-e2e.sh copies this file in and removes it at the end.
 */
add_action('wp_footer', static function () {
    echo '<script src="' . esc_url(content_url('sbqa-stat.js')) . '"></script>' . "\n";
    echo '<script src="' . esc_url(content_url('sbqa-mkt.js')) . '"></script>' . "\n";
}, 5);
