<?php
if (!current_user_can('manage_options')) return;

global $wpdb;
$table = AZMOON_MANAGER_TABLE;

// ذخیره اطلاعات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dtd_tests'])) {
    foreach ($_POST['dtd_tests'] as $form_id => $data) {
        $slug = sanitize_title($data['slug']);
        $title = sanitize_text_field($data['title']);
        $duration = intval($data['duration']);
        $active = !empty($data['active']) ? 1 : 0;
        $time_limit_enabled = !empty($data['time_limit_enabled']) ? 1 : 0;
        $form_id = intval($form_id);

        // اگر slug خالی است، حذف از جدول
        if (empty($slug)) {
            $wpdb->delete($table, ['form_id' => $form_id]);
            continue;
        }

        // اگر رکورد وجود دارد، آپدیت کن، وگرنه درج کن
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE form_id = %d", $form_id));
        $data_arr = [
            'form_id' => $form_id,
            'form_slug' => $slug,
            'title' => $title,
            'duration_minutes' => $duration,
            'is_active' => $active,
            'time_limit_enabled' => $time_limit_enabled,
        ];
        if ($exists) {
            $wpdb->update($table, $data_arr, ['form_id' => $form_id]);
        } else {
            $wpdb->insert($table, $data_arr);
        }
    }
    echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
}

// گرفتن فرم‌های گرویتی فرم
if (!class_exists('GFAPI')) {
    echo '<p>گرویتی فرم فعال نیست.</p>';
    return;
}
$forms = GFAPI::get_forms();
// گرفتن اطلاعات آزمون‌ها از جدول
$tests = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
$tests_by_form = [];
foreach ($tests as $t) {
    $tests_by_form[$t['form_id']] = $t;
}
?>
<div class="wrap">
    <h1>مدیریت آزمون‌ها</h1>
    <form method="post">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>نام فرم</th>
                    <th>Slug</th>
                    <th>عنوان آزمون</th>
                    <th>محدودیت زمانی</th>
                    <th>مدت زمان (دقیقه)</th>
                    <th>فعال؟</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): 
                    $form_id = $form['id'];
                    $existing = $tests_by_form[$form_id] ?? [];
                ?>
                <tr>
                    <td><?php echo esc_html($form['title']); ?></td>
                    <td><input name="dtd_tests[<?php echo $form_id; ?>][slug]" value="<?php echo esc_attr($existing['form_slug'] ?? ''); ?>" /></td>
                    <td><input name="dtd_tests[<?php echo $form_id; ?>][title]" value="<?php echo esc_attr($existing['title'] ?? $form['title']); ?>" /></td>
                    <td><input type="checkbox" name="dtd_tests[<?php echo $form_id; ?>][time_limit_enabled]" value="1" <?php checked($existing['time_limit_enabled'] ?? false); ?> class="dtd-time-limit-toggle" data-form-id="<?php echo $form_id; ?>" /></td>
                    <td><input type="number" name="dtd_tests[<?php echo $form_id; ?>][duration]" value="<?php echo ($existing['time_limit_enabled'] ?? false) ? esc_attr($existing['duration_minutes'] ?? '') : ''; ?>" min="1" <?php echo !($existing['time_limit_enabled'] ?? false) ? 'disabled' : ''; ?> class="dtd-duration-field" data-form-id="<?php echo $form_id; ?>" /></td>
                    <td><input type="checkbox" name="dtd_tests[<?php echo $form_id; ?>][active]" value="1" <?php checked($existing['is_active'] ?? false); ?> /></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button('ذخیره تغییرات'); ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.dtd-time-limit-toggle').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var formId = this.getAttribute('data-form-id');
            var durationField = document.querySelector('.dtd-duration-field[data-form-id="' + formId + '"]');
            if (this.checked) {
                durationField.disabled = false;
            } else {
                durationField.value = '';
                durationField.disabled = true;
            }
        });
    });
});
</script>
