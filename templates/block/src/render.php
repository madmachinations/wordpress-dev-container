<?php
/** @var array $attributes Block attributes supplied by WordPress. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<p <?php echo get_block_wrapper_attributes(); ?>><?php echo wp_kses_post( $attributes['content'] ?? '' ); ?></p>
