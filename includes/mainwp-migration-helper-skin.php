<?php
/**
 *
 * @package MainWP/Migration
 */

namespace MainWP\Migration;

class MainWP_Migration_Helper_Upgrader_Skin extends \WP_Upgrader_Skin {
    public function feedback($string,...$args ) {
        // Suppress output.
    }
}
