<?php
/**
 * Portfolio Page Order Meta Box
 *
 * Adds a drag-and-drop meta box to Pages that display a portfolio category
 * (i.e. pages carrying the `_portfolio_category` meta and a
 * [sessionale_portfolio] shortcode). The chosen order is stored per page in
 * the `_portfolio_order` meta and honoured by sessionale_portfolio_shortcode().
 *
 * @package Portfolio_Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sessionale_Portfolio_Page_Order {

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'), 10, 2);
        add_action('save_post_page', array($this, 'save_order_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Register the meta box, but only on pages tied to a portfolio category.
     *
     * @param string  $post_type Current post type.
     * @param WP_Post $post      Current post object.
     */
    public function add_order_meta_box($post_type, $post) {
        if ($post_type !== 'page') {
            return;
        }

        if (!$post || !$this->get_page_category($post->ID)) {
            return;
        }

        add_meta_box(
            'sessionale_portfolio_order',
            __('Portfolio Order', 'sessionale-portfolio'),
            array($this, 'render_order_meta_box'),
            'page',
            'normal',
            'high'
        );
    }

    /**
     * Render the sortable list of portfolio items for this page's category.
     */
    public function render_order_meta_box($post) {
        $category = $this->get_page_category($post->ID);
        $items    = $this->get_ordered_items($post->ID, $category);

        wp_nonce_field('sessionale_portfolio_order_save', 'sessionale_portfolio_order_nonce');

        if (empty($items)) {
            echo '<p>' . esc_html__('No projects found in this category yet.', 'sessionale-portfolio') . '</p>';
            return;
        }
        ?>
        <p class="description" style="margin-bottom: 12px;">
            <?php esc_html_e('Drag the projects to set the order they appear on this page. New projects added to this category later appear at the end until you reorder them.', 'sessionale-portfolio'); ?>
        </p>
        <ul class="sessionale-portfolio-order-list">
            <?php foreach ($items as $item) : ?>
                <li class="sessionale-portfolio-order-item">
                    <input type="hidden" name="sessionale_portfolio_order[]" value="<?php echo esc_attr($item->ID); ?>">
                    <span class="sessionale-portfolio-order-handle dashicons dashicons-move"></span>
                    <?php
                    $thumb = get_the_post_thumbnail($item->ID, array(50, 50));
                    if ($thumb) {
                        echo '<span class="sessionale-portfolio-order-thumb">' . $thumb . '</span>';
                    }
                    ?>
                    <span class="sessionale-portfolio-order-title"><?php echo esc_html(get_the_title($item->ID)); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Persist the order chosen in the meta box.
     */
    public function save_order_meta($post_id) {
        if (!isset($_POST['sessionale_portfolio_order_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['sessionale_portfolio_order_nonce'], 'sessionale_portfolio_order_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        if (empty($_POST['sessionale_portfolio_order']) || !is_array($_POST['sessionale_portfolio_order'])) {
            delete_post_meta($post_id, '_portfolio_order');
            return;
        }

        $order = array_values(array_filter(array_map('absint', $_POST['sessionale_portfolio_order'])));
        update_post_meta($post_id, '_portfolio_order', $order);
    }

    /**
     * Enqueue the sortable behaviour and styling on the page editor.
     */
    public function enqueue_admin_scripts($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'page') {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', "
            jQuery(function($){
                $('.sessionale-portfolio-order-list').sortable({
                    handle: '.sessionale-portfolio-order-handle',
                    placeholder: 'sessionale-portfolio-order-placeholder',
                    forcePlaceholderSize: true
                });
            });
        ");

        wp_add_inline_style('wp-admin', $this->get_admin_styles());
    }

    /**
     * Inline styles for the order list.
     */
    private function get_admin_styles() {
        return '
            .sessionale-portfolio-order-list { margin: 0; }
            .sessionale-portfolio-order-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 10px;
                margin-bottom: 6px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 4px;
            }
            .sessionale-portfolio-order-handle {
                cursor: move;
                color: #787c82;
            }
            .sessionale-portfolio-order-thumb img {
                display: block;
                width: 50px;
                height: 50px;
                object-fit: cover;
                border-radius: 3px;
            }
            .sessionale-portfolio-order-title { font-weight: 600; }
            .sessionale-portfolio-order-placeholder {
                height: 68px;
                margin-bottom: 6px;
                border: 1px dashed #c3c4c7;
                border-radius: 4px;
                background: #f6f7f7;
            }
        ';
    }

    /**
     * Resolve the portfolio category slug a page is bound to.
     *
     * Prefers the `_portfolio_category` meta (set on theme-created category
     * pages), and falls back to parsing the category attribute out of the
     * [sessionale_portfolio category="..."] shortcode in the page content, so
     * it also works on pages that were built by hand.
     */
    private function get_page_category($post_id) {
        $category = get_post_meta($post_id, '_portfolio_category', true);
        if (!empty($category)) {
            return $category;
        }

        $content = get_post_field('post_content', $post_id);
        if ($content && has_shortcode($content, 'sessionale_portfolio')) {
            if (preg_match('/\[sessionale_portfolio[^\]]*\bcategory\s*=\s*(["\'])(.*?)\1/', $content, $matches)) {
                return $matches[2];
            }
        }

        return '';
    }

    /**
     * Return the category's portfolio items in the page's saved order,
     * with any not-yet-ordered items appended by menu_order.
     *
     * @return WP_Post[]
     */
    private function get_ordered_items($post_id, $category) {
        if (empty($category)) {
            return array();
        }

        $items = get_posts(array(
            'post_type'      => 'portfolio',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'portfolio_category',
                    'field'    => 'slug',
                    'terms'    => $category,
                ),
            ),
        ));

        return self::apply_saved_order($items, get_post_meta($post_id, '_portfolio_order', true));
    }

    /**
     * Reorder a list of portfolio posts by a saved array of IDs. Items present
     * in the saved order come first (in that order); everything else keeps its
     * incoming order and is appended at the end. Shared so the shortcode can
     * reuse the exact same logic.
     *
     * @param WP_Post[] $items
     * @param array|string $saved_order
     * @return WP_Post[]
     */
    public static function apply_saved_order($items, $saved_order) {
        if (empty($saved_order) || !is_array($saved_order)) {
            return $items;
        }

        $by_id = array();
        foreach ($items as $item) {
            $by_id[$item->ID] = $item;
        }

        $ordered = array();
        foreach ($saved_order as $id) {
            $id = (int) $id;
            if (isset($by_id[$id])) {
                $ordered[] = $by_id[$id];
                unset($by_id[$id]);
            }
        }

        // Append any remaining (newly added / unordered) items.
        foreach ($by_id as $item) {
            $ordered[] = $item;
        }

        return $ordered;
    }
}

new Sessionale_Portfolio_Page_Order();
