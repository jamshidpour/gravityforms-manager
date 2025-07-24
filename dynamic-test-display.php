<?php
/*
Plugin Name: Dynamic Test Display
Description: نمایش داینامیک آزمون‌های روانشناسی بر اساس پارامتر URL با فرم گرویتی فرم
Version: 1.1
Author: وب شیک
*/

if (!defined('ABSPATH')) exit;


define('AZMOON_MANAGER_TABLE', $GLOBALS['wpdb']->prefix . 'azmoon_forms');

// ساخت جدول هنگام فعال‌سازی افزونه
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS " . AZMOON_MANAGER_TABLE . " (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_id BIGINT UNSIGNED NOT NULL,
        form_slug VARCHAR(191) NOT NULL UNIQUE,
        title VARCHAR(255) DEFAULT NULL,
        time_limit_enabled TINYINT(1) NOT NULL DEFAULT 0,
        duration_minutes INT UNSIGNED DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

// افزودن آیتم به منوی مدیریت
add_action('admin_menu', function() {
    add_menu_page(
        'مدیریت آزمون‌ها',
        'مدیریت آزمون‌ها',
        'manage_options',
        'test-manager',
        function() {
            include plugin_dir_path(__FILE__) . 'admin/test-manager.php';
        },
        'dashicons-welcome-write-blog',
        25
    );
});

// افزودن زیرمنوی تنظیمات
add_action('admin_menu', function() {
    add_submenu_page(
        'test-manager',
        'تنظیمات آزمون‌ها',
        'تنظیمات',
        'manage_options',
        'dtd-settings',
        'dtd_settings_page_callback'
    );
});

function dtd_settings_page_callback() {
    if (isset($_POST['dtd_save_settings'])) {
        $only_logged_in = isset($_POST['dtd_only_logged_in']) ? 1 : 0;
        $only_purchased = isset($_POST['dtd_only_purchased']) ? 1 : 0;
        update_option('dtd_only_logged_in', $only_logged_in);
        update_option('dtd_only_purchased', $only_purchased);
        echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
    }
    $only_logged_in = get_option('dtd_only_logged_in', 1);
    $only_purchased = get_option('dtd_only_purchased', 1);
    ?>
    <div class="wrap">
        <h1>تنظیمات آزمون‌ها</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">نمایش آزمون تنها پس از ورود به حساب کاربری</th>
                    <td>
                        <input type="checkbox" name="dtd_only_logged_in" value="1" <?php checked($only_logged_in, 1); ?> />
                        <label>در صورت فعال بودن، فقط کاربران وارد شده می‌توانند آزمون را مشاهده کنند.</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">نمایش آزمون تنها در صورت خرید محصول</th>
                    <td>
                        <input type="checkbox" name="dtd_only_purchased" value="1" <?php checked($only_purchased, 1); ?> />
                        <label>در صورت فعال بودن، فقط کاربرانی که محصول آزمون را خریده‌اند می‌توانند آزمون را مشاهده کنند.</label>
                    </td>
                </tr>
            </table>
            <input type="submit" name="dtd_save_settings" class="button button-primary" value="ذخیره تنظیمات" />
        </form>
    </div>
    <?php
}

// محدودیت نمایش آزمون فقط برای کاربران لاگین و خریدار
add_filter('the_content', function($content) {
    global $post;
    if (has_shortcode($content, 'dynamic_test_form')) {
        $only_logged_in = get_option('dtd_only_logged_in', 1);
        $only_purchased = get_option('dtd_only_purchased', 1);
        if ($only_logged_in && !is_user_logged_in()) {
            return '<div class="dtd-login-required">جهت مشاهده آزمون وارد حساب کاربری خود شوید.</div>';
        }
        if ($only_purchased && is_user_logged_in()) {
            $slug = $_GET['test'] ?? '';
            if ($slug) {
                $user_id = get_current_user_id();
                $has_bought = false;
                if (function_exists('wc_customer_bought_product')) {
                    $product_id = dtd_get_product_id_by_slug($slug);
                    if ($product_id) {
                        $has_bought = wc_customer_bought_product('', $user_id, $product_id);
                    }
                }
                if (!$has_bought) {
                    return '<div class="dtd-login-required">برای مشاهده این آزمون باید محصول مربوطه را خریداری کنید.</div>';
                }
            }
        }
    }
    return $content;
});

// گرفتن اطلاعات فرم‌ها از جدول دیتابیس
function dtd_get_tests($only_active = false) {
    global $wpdb;
    $table = AZMOON_MANAGER_TABLE;
    $where = $only_active ? 'WHERE is_active = 1' : '';
    $results = $wpdb->get_results("SELECT * FROM $table $where", ARRAY_A);
    $tests = [];
    foreach ($results as $row) {
        $tests[$row['form_slug']] = [
            'form_id' => $row['form_id'],
            'slug' => $row['form_slug'],
            'title' => $row['title'],
            'duration' => $row['duration_minutes'],
            'time_limit_enabled' => $row['time_limit_enabled'],
            'active' => $row['is_active'],
        ];
    }
    return $tests;
}

// شورتکد نمایش آزمون
add_shortcode('dynamic_test_form', function() {
    $slug = $_GET['test'] ?? null;
    $tests = dtd_get_tests(true);

    if (!$slug || !isset($tests[$slug]) || !$tests[$slug]['active']) {
        return '<p>آزمون مورد نظر یافت نشد یا غیرفعال است.</p>';
    }

    $test = $tests[$slug];

    ob_start(); ?>
    <div class="dynamic-test-box">
        <h1><?php echo esc_html($test['title']); ?></h1>
        <?php if (!empty($test['duration'])): ?>
            <p class="test-duration">⏱ مدت زمان: <?php echo esc_html($test['duration']); ?> دقیقه</p>
        <?php endif; ?>
        <div class="test-form"><?php echo do_shortcode('[gravityform id="' . intval($test['form_id']) . '" title="false" description="false" ajax="true"]'); ?></div>
    </div>
    <?php
    return ob_get_clean();
});

// بارگذاری CSS
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('dynamic-test-style', plugin_dir_url(__FILE__) . 'assets/style.css');
});

// افزودن دکمه شروع آزمون به صفحه تشکر ووکامرس
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    $tests = dtd_get_tests(true);
    $slugs = array_keys($tests);
    $button_shown = false;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $product_slug = $product->get_slug();
        if (in_array($product_slug, $slugs)) {
            $exam_url = home_url('/exam/?test=' . $product_slug);
            echo '<a href="' . esc_url($exam_url) . '" class="button dtd-exam-btn" style="display:inline-block;margin:20px 0;padding:12px 28px;background:#0073aa;color:#fff;border-radius:6px;font-size:18px;text-decoration:none;">شروع آزمون</a>';
            $button_shown = true;
        }
    }
}, 20);

// افزودن دکمه شروع آزمون به ردیف هر محصول در جدول سفارش ووکامرس (صفحه تشکر و سفارش‌های من)
add_action('woocommerce_order_item_meta_end', function($item_id, $item, $order) {
    $product = $item->get_product();
    if (!$product) return;
    $tests = dtd_get_tests(true);
    $slugs = array_keys($tests);
    $product_slug = $product->get_slug();
    if (in_array($product_slug, $slugs)) {
        $exam_url = home_url('/exam/?test=' . $product_slug);
        echo '<br><a href="' . esc_url($exam_url) . '" class="button dtd-exam-btn" style="display:inline-block;margin:10px 0 0 0;padding:8px 20px;background:#0073aa;color:#fff;border-radius:6px;font-size:15px;text-decoration:none;">شروع آزمون</a>';
    }
}, 10, 3);

// تابع کمکی برای پیدا کردن product_id بر اساس slug
function dtd_get_product_id_by_slug($slug) {
    $product = get_page_by_path($slug, OBJECT, 'product');
    return $product ? $product->ID : 0;
}
