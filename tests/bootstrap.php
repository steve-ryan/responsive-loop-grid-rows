<?php
/**
 * Unit-test bootstrap: no WordPress, no database.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'RLG_DEBUG', false );

require_once __DIR__ . '/stubs.php';

$root = dirname( __DIR__ );

require_once $root . '/includes/class-debug.php';
require_once $root . '/includes/class-responsive-query.php';
require_once $root . '/includes/class-pagination.php';

require_once __DIR__ . '/Elementor_Fixture.php';
