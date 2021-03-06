<?php
/**
 * @author  ThimPress
 * @package LearnPress/Admin/Views
 * @version 3.0.0
 */

defined( 'ABSPATH' ) or die();

?>
<div class="card">
    <h2><?php _e( 'LearnPress hard cache', 'learnpress' ); ?></h2>
    <p><?php _e( 'Hard cache is build-in tool of LearnPress for caching of static content such as course, lesson, quiz.', 'learnpress' ); ?></p>
    <p><?php _e( 'When cache is enabled, the content will be cached when course is accessed in the first time.', 'learnpress' ); ?></p>
    <p><?php _e( 'And it will not change for all accessing later until the cache is cleared.', 'learnpress' ); ?></p>
    <p><?php _e( 'If the content is not changed after updating course, click button below to flush the cache for applying changes.', 'learnpress' ); ?></p>

    <label>
        <input type="checkbox" name="enable_hard_cache"
               value="1" <?php checked( LP()->settings()->get( 'enable_hard_cache' ), 'yes' ); ?>>
		<?php _e( 'Enable/Disable hard cache', 'learnpress' ); ?>
    </label>
    <p class="tools-button">
        <a class="button" id="learn-press-clear-cache"
           data-text="<?php esc_attr_e( 'Clear cache', 'learnpress' ); ?>"
           data-cleaning-text="<?php esc_attr_e( 'Cleaning...', 'learnpress' ); ?>"
           href="<?php echo wp_nonce_url( admin_url( 'index.php?page=lp-clear-cache' ), 'clear-cache' ); ?>">
			<?php esc_html_e( 'Clear cache', 'learnpress' ); ?>
        </a>
    </p>
</div>