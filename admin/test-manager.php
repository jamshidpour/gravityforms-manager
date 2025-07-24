<?php
// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// بررسی مجوز کاربر
if (!current_user_can('manage_options')) {
    wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.', 'dynamic-test-display'));
}

global $wpdb;
$table = AZMOON_MANAGER_TABLE;

// ذخیره اطلاعات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dtd_tests'])) {
    // بررسی nonce برای امنیت
    if (!isset($_POST['dtd_test_manager_nonce']) || !wp_verify_nonce($_POST['dtd_test_manager_nonce'], 'dtd_save_test_settings')) {
        wp_die(__('خطای امنیتی. لطفاً دوباره تلاش کنید.', 'dynamic-test-display'));
    }
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    foreach ($_POST['dtd_tests'] as $form_id => $data) {
        try {
            $form_id = intval($form_id);
            $slug = isset($data['slug']) ? sanitize_title($data['slug']) : '';
            $title = isset($data['title']) ? sanitize_text_field($data['title']) : '';
            $duration = isset($data['duration']) ? intval($data['duration']) : 0;
            $active = !empty($data['active']) ? 1 : 0;
            $time_limit_enabled = !empty($data['time_limit_enabled']) ? 1 : 0;
            
            // اعتبارسنجی
            if ($form_id <= 0) {
                throw new Exception(sprintf(__('شناسه فرم نامعتبر: %d', 'dynamic-test-display'), $form_id));
            }
            
            // اگر slug خالی است، حذف از جدول
            if (empty($slug)) {
                $deleted = $wpdb->delete($table, ['form_id' => $form_id], ['%d']);
                if ($deleted !== false) {
                    $success_count++;
                    dtd_log("Test removed for form ID: {$form_id}");
                } else {
                    throw new Exception($wpdb->last_error ?: __('خطا در حذف رکورد', 'dynamic-test-display'));
                }
                continue;
            }
            
            // بررسی تکراری نبودن slug
            $existing_slug = $wpdb->get_var($wpdb->prepare(
                "SELECT form_id FROM {$table} WHERE form_slug = %s AND form_id != %d",
                $slug, $form_id
            ));
            
            if ($existing_slug) {
                throw new Exception(sprintf(__('Slug "%s" قبلاً استفاده شده است.', 'dynamic-test-display'), $slug));
            }
            
            // اعتبارسنجی مدت زمان
            if ($time_limit_enabled && $duration <= 0) {
                throw new Exception(__('مدت زمان باید بیشتر از صفر باشد.', 'dynamic-test-display'));
            }
            
            // اگر رکورد وجود دارد، آپدیت کن، وگرنه درج کن
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE form_id = %d", $form_id));
            
            $data_arr = [
                'form_id' => $form_id,
                'form_slug' => $slug,
                'title' => $title,
                'duration_minutes' => $time_limit_enabled ? $duration : null,
                'is_active' => $active,
                'time_limit_enabled' => $time_limit_enabled,
            ];
            
            if ($exists) {
                $result = $wpdb->update(
                    $table, 
                    $data_arr, 
                    ['form_id' => $form_id],
                    ['%d', '%s', '%s', '%d', '%d', '%d'],
                    ['%d']
                );
                dtd_log("Test updated for form ID: {$form_id}");
            } else {
                $result = $wpdb->insert(
                    $table, 
                    $data_arr,
                    ['%d', '%s', '%s', '%d', '%d', '%d']
                );
                dtd_log("Test created for form ID: {$form_id}");
            }
            
            if ($result !== false) {
                $success_count++;
            } else {
                throw new Exception($wpdb->last_error ?: __('خطا در ذخیره اطلاعات', 'dynamic-test-display'));
            }
            
        } catch (Exception $e) {
            $error_count++;
            $errors[] = sprintf(__('فرم %d: %s', 'dynamic-test-display'), $form_id, $e->getMessage());
            dtd_log("Error saving test for form ID {$form_id}: " . $e->getMessage(), 'error');
        }
    }
    
    // نمایش پیغام‌های نتیجه
    if ($success_count > 0) {
        echo '<div class="updated"><p>' . sprintf(_n('%d تنظیمات ذخیره شد.', '%d تنظیمات ذخیره شدند.', $success_count, 'dynamic-test-display'), $success_count) . '</p></div>';
    }
    
    if ($error_count > 0) {
        echo '<div class="error"><p>' . sprintf(_n('%d خطا رخ داد:', '%d خطا رخ داد:', $error_count, 'dynamic-test-display'), $error_count) . '</p>';
        echo '<ul>';
        foreach ($errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul></div>';
    }
}

// بررسی وجود Gravity Forms
if (!class_exists('GFAPI')) {
    echo '<div class="error"><p>' . __('افزونه Gravity Forms فعال نیست. لطفاً ابتدا آن را نصب و فعال کنید.', 'dynamic-test-display') . '</p></div>';
    return;
}

// گرفتن فرم‌های گرویتی فرم
try {
    $forms = GFAPI::get_forms();
    if (empty($forms)) {
        echo '<div class="notice notice-warning"><p>' . __('هیچ فرمی در Gravity Forms یافت نشد. ابتدا فرم‌های خود را ایجاد کنید.', 'dynamic-test-display') . '</p></div>';
        return;
    }
} catch (Exception $e) {
    echo '<div class="error"><p>' . __('خطا در دریافت فرم‌ها از Gravity Forms.', 'dynamic-test-display') . '</p></div>';
    dtd_log("Error fetching GF forms: " . $e->getMessage(), 'error');
    return;
}

// گرفتن اطلاعات آزمون‌ها از جدول
try {
    $tests = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
    if ($wpdb->last_error) {
        throw new Exception($wpdb->last_error);
    }
} catch (Exception $e) {
    echo '<div class="error"><p>' . __('خطا در دریافت اطلاعات آزمون‌ها از دیتابیس.', 'dynamic-test-display') . '</p></div>';
    dtd_log("Database error: " . $e->getMessage(), 'error');
    $tests = [];
}

$tests_by_form = [];
foreach ($tests as $t) {
    $tests_by_form[$t['form_id']] = $t;
}
?>

<div class="wrap">
    <h1><?php _e('مدیریت آزمون‌ها', 'dynamic-test-display'); ?></h1>
    
    <div class="dtd-help-text">
        <p><?php _e('در این صفحه می‌توانید فرم‌های Gravity Form خود را به عنوان آزمون تنظیم کنید. برای هر فرم می‌توانید Slug (آدرس یکتا)، عنوان، محدودیت زمانی و وضعیت فعال/غیرفعال را تعیین کنید.', 'dynamic-test-display'); ?></p>
    </div>
    
    <form method="post" action="" id="dtd-test-manager-form">
        <?php wp_nonce_field('dtd_save_test_settings', 'dtd_test_manager_nonce'); ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php _e('نام فرم', 'dynamic-test-display'); ?></th>
                    <th scope="col"><?php _e('Slug', 'dynamic-test-display'); ?> <span class="description">(<?php _e('آدرس یکتا', 'dynamic-test-display'); ?>)</span></th>
                    <th scope="col"><?php _e('عنوان آزمون', 'dynamic-test-display'); ?></th>
                    <th scope="col"><?php _e('محدودیت زمانی', 'dynamic-test-display'); ?></th>
                    <th scope="col"><?php _e('مدت زمان (دقیقه)', 'dynamic-test-display'); ?></th>
                    <th scope="col"><?php _e('فعال؟', 'dynamic-test-display'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): 
                    $form_id = intval($form['id']);
                    $existing = $tests_by_form[$form_id] ?? [];
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($form['title']); ?></strong>
                        <div class="row-actions">
                            <span><?php printf(__('شناسه: %d', 'dynamic-test-display'), $form_id); ?></span>
                        </div>
                    </td>
                    <td>
                        <input 
                            type="text"
                            name="dtd_tests[<?php echo $form_id; ?>][slug]" 
                            value="<?php echo esc_attr($existing['form_slug'] ?? ''); ?>" 
                            placeholder="<?php _e('مثال: test-iq', 'dynamic-test-display'); ?>"
                            pattern="[a-z0-9\-]+"
                            title="<?php _e('فقط حروف کوچک انگلیسی، اعداد و خط تیره مجاز است', 'dynamic-test-display'); ?>"
                        />
                        <div class="description"><?php _e('خالی بگذارید تا حذف شود', 'dynamic-test-display'); ?></div>
                    </td>
                    <td>
                        <input 
                            type="text"
                            name="dtd_tests[<?php echo $form_id; ?>][title]" 
                            value="<?php echo esc_attr($existing['title'] ?? $form['title']); ?>" 
                            placeholder="<?php echo esc_attr($form['title']); ?>"
                        />
                    </td>
                    <td>
                        <input 
                            type="checkbox" 
                            name="dtd_tests[<?php echo $form_id; ?>][time_limit_enabled]" 
                            value="1" 
                            <?php checked($existing['time_limit_enabled'] ?? false); ?> 
                            class="dtd-time-limit-toggle" 
                            data-form-id="<?php echo $form_id; ?>"
                        />
                    </td>
                    <td>
                        <input 
                            type="number" 
                            name="dtd_tests[<?php echo $form_id; ?>][duration]" 
                            value="<?php echo ($existing['time_limit_enabled'] ?? false) ? esc_attr($existing['duration_minutes'] ?? '') : ''; ?>" 
                            min="1" 
                            max="1440"
                            <?php echo !($existing['time_limit_enabled'] ?? false) ? 'disabled' : ''; ?> 
                            class="dtd-duration-field" 
                            data-form-id="<?php echo $form_id; ?>"
                            placeholder="15"
                        />
                    </td>
                    <td>
                        <input 
                            type="checkbox" 
                            name="dtd_tests[<?php echo $form_id; ?>][active]" 
                            value="1" 
                            <?php checked($existing['is_active'] ?? false); ?>
                        />
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="dtd-form-actions">
            <?php submit_button(__('ذخیره تغییرات', 'dynamic-test-display'), 'primary', 'submit', false); ?>
            <button type="button" class="button" id="dtd-preview-urls"><?php _e('مشاهده آدرس‌ها', 'dynamic-test-display'); ?></button>
        </div>
    </form>
    
    <!-- مودال نمایش آدرس‌ها -->
    <div id="dtd-urls-modal" class="dtd-modal" style="display: none;">
        <div class="dtd-modal-content">
            <div class="dtd-modal-header">
                <h3><?php _e('آدرس‌های آزمون‌ها', 'dynamic-test-display'); ?></h3>
                <button type="button" class="dtd-modal-close">&times;</button>
            </div>
            <div class="dtd-modal-body">
                <p><?php _e('آدرس‌های زیر برای دسترسی به آزمون‌ها استفاده می‌شود:', 'dynamic-test-display'); ?></p>
                <div id="dtd-urls-list"></div>
            </div>
        </div>
    </div>
</div>

<style>
.dtd-help-text {
    background: #f0f6fc;
    border-left: 4px solid #0073aa;
    padding: 12px;
    margin: 20px 0;
    border-radius: 4px;
}

.dtd-form-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

.dtd-form-actions .button {
    margin-left: 10px;
}

.dtd-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 100000;
}

.dtd-modal-content {
    background: #fff;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.dtd-modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dtd-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.dtd-modal-body {
    padding: 20px;
}

.dtd-url-item {
    background: #f9f9f9;
    padding: 10px;
    margin: 10px 0;
    border-radius: 4px;
    border-left: 4px solid #0073aa;
}

.dtd-url-item strong {
    display: block;
    margin-bottom: 5px;
}

.dtd-url-item code {
    background: #fff;
    padding: 5px;
    border-radius: 3px;
    font-size: 13px;
    word-break: break-all;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // مدیریت فعال/غیرفعال کردن فیلد مدت زمان
    document.querySelectorAll('.dtd-time-limit-toggle').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var formId = this.getAttribute('data-form-id');
            var durationField = document.querySelector('.dtd-duration-field[data-form-id="' + formId + '"]');
            if (this.checked) {
                durationField.disabled = false;
                durationField.focus();
            } else {
                durationField.value = '';
                durationField.disabled = true;
            }
        });
    });
    
    // مدیریت slug ها - تبدیل به حروف کوچک و جایگزینی فاصله با خط تیره
    document.querySelectorAll('input[name*="[slug]"]').forEach(function(input) {
        input.addEventListener('blur', function() {
            this.value = this.value.toLowerCase()
                .replace(/[^a-z0-9\s\-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/\-+/g, '-')
                .replace(/^\-+|\-+$/g, '');
        });
    });
    
    // نمایش آدرس‌ها
    document.getElementById('dtd-preview-urls').addEventListener('click', function() {
        var modal = document.getElementById('dtd-urls-modal');
        var urlsList = document.getElementById('dtd-urls-list');
        var urls = [];
        
        document.querySelectorAll('input[name*="[slug]"]').forEach(function(input) {
            var slug = input.value.trim();
            var row = input.closest('tr');
            var title = row.querySelector('input[name*="[title]"]').value || 'بدون عنوان';
            
            if (slug) {
                urls.push({
                    title: title,
                    slug: slug,
                    url: '<?php echo home_url('/exam/'); ?>?test=' + slug
                });
            }
        });
        
        if (urls.length === 0) {
            urlsList.innerHTML = '<p><?php _e('هیچ آزمونی با Slug تعریف نشده است.', 'dynamic-test-display'); ?></p>';
        } else {
            var html = '';
            urls.forEach(function(item) {
                html += '<div class="dtd-url-item">';
                html += '<strong>' + item.title + '</strong>';
                html += '<code>' + item.url + '</code>';
                html += '</div>';
            });
            urlsList.innerHTML = html;
        }
        
        modal.style.display = 'flex';
    });
    
    // بستن مودال
    document.querySelector('.dtd-modal-close').addEventListener('click', function() {
        document.getElementById('dtd-urls-modal').style.display = 'none';
    });
    
    // بستن مودال با کلیک بیرون از آن
    document.getElementById('dtd-urls-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
    
    // اعتبارسنجی فرم قبل از ارسال
    document.getElementById('dtd-test-manager-form').addEventListener('submit', function(e) {
        var slugs = [];
        var duplicates = [];
        var hasError = false;
        
        // بررسی تکراری نبودن slug ها
        document.querySelectorAll('input[name*="[slug]"]').forEach(function(input) {
            var slug = input.value.trim();
            if (slug) {
                if (slugs.includes(slug)) {
                    duplicates.push(slug);
                    hasError = true;
                } else {
                    slugs.push(slug);
                }
            }
        });
        
        if (hasError) {
            e.preventDefault();
            alert('<?php _e('Slug های تکراری یافت شد:', 'dynamic-test-display'); ?> ' + duplicates.join(', '));
            return false;
        }
        
        // بررسی مدت زمان برای آزمون‌های با محدودیت زمانی
        var timeErrors = [];
        document.querySelectorAll('.dtd-time-limit-toggle:checked').forEach(function(checkbox) {
            var formId = checkbox.getAttribute('data-form-id');
            var durationField = document.querySelector('.dtd-duration-field[data-form-id="' + formId + '"]');
            var duration = parseInt(durationField.value);
            
            if (!duration || duration <= 0) {
                var row = checkbox.closest('tr');
                var formName = row.querySelector('td:first-child strong').textContent;
                timeErrors.push(formName);
                hasError = true;
            }
        });
        
        if (timeErrors.length > 0) {
            e.preventDefault();
            alert('<?php _e('لطفاً مدت زمان را برای فرم‌های زیر تعیین کنید:', 'dynamic-test-display'); ?>\n' + timeErrors.join('\n'));
            return false;
        }
    });
});
</script>
