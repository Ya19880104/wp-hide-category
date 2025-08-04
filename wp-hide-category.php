<?php
/*
Plugin Name: WooCommerce隱藏分類商品
Description: 隱藏 WooCommerce 商店與分類頁中的指定商品分類，並從分類小工具中移除。第二組分類會連同商品頁一併隱藏為 404，可指定額外頁面一併排除。
Version: 1.4.1
Author: YANGSHEEP DESIGN
Author URI: https://yangsheep.com.tw
Text Domain: YANGSHEEP CLOUD
*/

if (!defined('ABSPATH')) exit;

class YangSheep_Hide_Category {

    const OPTION_KEY_HIDE_NORMAL = 'yangsheep_hidden_category_ids';
    const OPTION_KEY_HIDE_FULL   = 'yangsheep_hidden_category_ids_full';
    const OPTION_KEY_HIDE_PAGES  = 'yangsheep_hidden_category_pages';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_setting']);
        add_action('pre_get_posts', [$this, 'hide_categories_in_shop']);
        add_filter('get_terms', [$this, 'filter_hidden_categories_from_list'], 10, 3);
        add_action('template_redirect', [$this, 'maybe_force_404_on_hidden_product']);
    }

    public function add_admin_menu() {
        add_menu_page(
            '隱藏分類商品',
            '隱藏分類商品',
            'manage_woocommerce',
            'yangsheep-hide-category',
            [$this, 'render_admin_page'],
            'dashicons-hidden',
            56
        );
    }

    public function register_setting() {
        register_setting('yangsheep_hide_category_group', self::OPTION_KEY_HIDE_NORMAL);
        register_setting('yangsheep_hide_category_group', self::OPTION_KEY_HIDE_FULL);
        register_setting('yangsheep_hide_category_group', self::OPTION_KEY_HIDE_PAGES);
    }

    public function render_admin_page() {
        $normal_hidden = (array) get_option(self::OPTION_KEY_HIDE_NORMAL, []);
        $full_hidden = (array) get_option(self::OPTION_KEY_HIDE_FULL, []);
        $extra_pages = get_option(self::OPTION_KEY_HIDE_PAGES, '');
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>隱藏分類商品</h1>
            <form method="post" action="options.php">
                <?php settings_fields('yangsheep_hide_category_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">選擇要隱藏的分類（僅商店與分類頁）</th>
                        <td>
                            <select name="<?php echo self::OPTION_KEY_HIDE_NORMAL; ?>[]" multiple style="height: 200px; width: 300px;">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array($cat->term_id, $normal_hidden)); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">所選分類的商品將不會出現在商店頁與分類頁，但商品頁仍可直接開啟。</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">選擇要完全隱藏的分類（含商品頁變 404）</th>
                        <td>
                            <select name="<?php echo self::OPTION_KEY_HIDE_FULL; ?>[]" multiple style="height: 200px; width: 300px;">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array($cat->term_id, $full_hidden)); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">所選分類的商品不僅從商店中消失，直接瀏覽商品頁也會顯示 404。</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">指定也要隱藏的頁面（相對網址）</th>
                        <td>
                            <textarea name="<?php echo self::OPTION_KEY_HIDE_PAGES; ?>" rows="5" cols="60"><?php echo esc_textarea($extra_pages); ?></textarea>
                            <p class="description">每行一個網址，格式如：<code>/page-a</code> 或 <code>/thank-you</code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function hide_categories_in_shop($query) {
        if (is_admin() || !$query->is_main_query()) return;

        $tax_query = (array) $query->get('tax_query');

        $hidden_normal = (array) get_option(self::OPTION_KEY_HIDE_NORMAL, []);
        $hidden_full   = (array) get_option(self::OPTION_KEY_HIDE_FULL, []);

        if (is_shop() || is_product_category() || is_search()) {
            if (!empty($hidden_normal)) {
                $tax_query[] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => array_map('intval', $hidden_normal),
                    'operator' => 'NOT IN',
                ];
            }

            if (!empty($hidden_full)) {
                $tax_query[] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => array_map('intval', $hidden_full),
                    'operator' => 'NOT IN',
                ];
            }
        }

        $uri = $_SERVER['REQUEST_URI'];
        $extra_hidden_pages = array_filter(array_map('trim', explode("\n", get_option(self::OPTION_KEY_HIDE_PAGES, ''))));
        if (!empty($hidden_full) && in_array($uri, $extra_hidden_pages)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $hidden_full),
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($tax_query)) {
            $query->set('tax_query', $tax_query);
        }
    }

    public function filter_hidden_categories_from_list($terms, $taxonomies, $args) {
        if (is_admin() || !in_array('product_cat', (array)$taxonomies, true)) {
            return $terms;
        }

        if (!(is_shop() || is_product_category() || is_search() || is_archive())) {
            return $terms;
        }

        $hidden_ids = array_merge(
            (array) get_option(self::OPTION_KEY_HIDE_NORMAL, []),
            (array) get_option(self::OPTION_KEY_HIDE_FULL, [])
        );

        if (empty($hidden_ids)) {
            return $terms;
        }

        return array_filter($terms, function($term) use ($hidden_ids) {
            return !in_array($term->term_id, $hidden_ids);
        });
    }

    public function maybe_force_404_on_hidden_product() {
        if (!is_singular('product')) return;

        $hidden_ids = (array) get_option(self::OPTION_KEY_HIDE_FULL, []);
        if (empty($hidden_ids)) return;

        global $post;
        $product_terms = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'ids']);
        if (array_intersect($hidden_ids, $product_terms)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            exit;
        }
    }
}

new YangSheep_Hide_Category();
