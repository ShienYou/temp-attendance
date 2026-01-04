<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: people
 * - UI: 人員 CSV 匯入/匯出 + 手動新增（最穩：非 AJAX，整頁送出） + 篩選/列表/inline edit
 * - Handlers: create_person, toggle_person_active, delete_person
 * - AJAX: bc_update_person_field
 * - Admin queries helper kept (even if not used currently) to avoid losing old functions
 */

function bc_admin_render_tab_people($current_project_id) {
    ?>
    <!-- 人員管理 -->
    <h2>人員管理（目前專案）</h2>

    <hr>

    <h3>人員 CSV 匯入 / 匯出</h3>
    <p style="color:#666;margin-top:-6px;">
        用於大量建立或備份人員名單。<br>
        匯入會「新增或更新」人員，不會自動刪除既有人員。
    </p>

    <p>
        <button id="bc-export-people" class="button">匯出人員 CSV</button>
    </p>

    <form id="bc-import-people-form" enctype="multipart/form-data">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="file" name="csv_file" accept=".csv" required>
        <button class="button button-primary">匯入人員 CSV</button>
    </form>

    <div id="bc-csv-result" style="margin-top:12px;"></div>

    <script>
    (function(){
        const exportBtn = document.getElementById('bc-export-people');
        const importForm = document.getElementById('bc-import-people-form');
        const resultBox = document.getElementById('bc-csv-result');

        if (exportBtn) {
            exportBtn.addEventListener('click', function(){
                const url = ajaxurl +
                    '?action=bc_export_people_csv' +
                    '&nonce=<?php echo wp_create_nonce('bc_admin_action'); ?>';
                window.location.href = url;
            });
        }

        if (importForm) {
            importForm.addEventListener('submit', function(e){
                e.preventDefault();

                const formData = new FormData(importForm);
                formData.append('action', 'bc_import_people_csv');
                formData.append('nonce', '<?php echo wp_create_nonce('bc_admin_action'); ?>');

                resultBox.innerHTML = '匯入中，請稍候…';

                fetch(ajaxurl, { method:'POST', body:formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            const d = res.data;
                            resultBox.innerHTML =
                                `✅ 匯入完成：新增 ${d.inserted}，略過 ${d.duplicates}`;
                        } else {
                            resultBox.innerHTML = '❌ 匯入失敗';
                        }
                    })
                    .catch(() => resultBox.innerHTML = '❌ AJAX 錯誤');
            });
        }
    })();
    </script>

    <p style="color:#666;margin-top:-6px;">
        這裡是「人員名單的正式入口」。前台選人、未來小隊整理與結算，都會依這裡為準。
    </p>

    <?php
    // 先取分堂資料（手動新增＋列表 inline edit 都會用到）
    $branches_active = bc_get_branches($current_project_id, true);
    ?>

    <hr>

    <h3>手動新增人員（最穩版本）</h3>
    <p style="color:#666;margin-top:-6px;">
        這裡是「少量補名單」用的，送出後會整頁刷新（不走 AJAX，最穩最不會死）。<br>
        規則與 CSV 一樣：同名 + 同小組 → 不會新增（只提示，不會卡住）。
    </p>

    <form method="post" action="<?php echo esc_url( admin_url('admin.php?page=bible-checkin&tab=people') ); ?>" style="background:#fff;border:1px solid #ddd;padding:12px;border-radius:6px;max-width:980px;">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="hidden" name="bc_action" value="create_person">

        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:120px;"><label for="bc-create-name">姓名（必填）</label></th>
                <td>
                    <input id="bc-create-name" type="text" name="name_create" class="regular-text" style="width:220px;" required>
                </td>

                <th style="width:120px;">分堂</th>
                <td>
                    <!-- ⚠️ 重點：不要用 name="branch_id"，避免撞到 filter 的 selector -->
                    <select name="branch_id_create" style="min-width:220px;">
                        <option value="0">（未指定）</option>
                        <?php foreach ($branches_active as $br): ?>
                            <option value="<?php echo esc_attr($br['id']); ?>">
                                <?php echo esc_html($br['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th>牧區</th>
                <td><input type="text" name="region_create" class="regular-text" style="width:220px;"></td>

                <th>小區</th>
                <td><input type="text" name="sub_region_create" class="regular-text" style="width:220px;"></td>
            </tr>

            <tr>
                <th>小組</th>
                <td><input type="text" name="group_name_create" class="regular-text" style="width:220px;"></td>

                <th>小隊</th>
                <td>
                    <input type="text" name="team_no_create" class="regular-text" style="width:80px;">
                    <label style="margin-left:10px;">
                        <input type="checkbox" name="is_active_create" value="1" checked> 啟用
                    </label>
                </td>
            </tr>
        </table>

        <p style="margin:10px 0 0 0;">
            <button class="button button-primary">新增人員</button>
        </p>
    </form>

    <?php
    // filter tree（給篩選下拉）
    $filter_tree = bc_get_people_hierarchy_for_filters($current_project_id);

    /* ========= GET params ========= */
    $page     = max(1, intval($_GET['paged'] ?? 1));
    $per_page = intval($_GET['per_page'] ?? 50);
    if ($per_page <= 0) $per_page = 50;
    if ($per_page > 200) $per_page = 200;

    $orderby = sanitize_key($_GET['orderby'] ?? 'id');
    $order   = strtoupper($_GET['order'] ?? 'ASC');
    $order   = in_array($order, ['ASC','DESC'], true) ? $order : 'ASC';

    $filters = [];

    if (!empty($_GET['branch_id'])) {
        $filters['branch_id'] = intval($_GET['branch_id']);
    }
    if (isset($_GET['is_active']) && ($_GET['is_active'] === '0' || $_GET['is_active'] === '1')) {
        $filters['is_active'] = intval($_GET['is_active']);
    }
    if (!empty($_GET['region'])) {
        $filters['region'] = sanitize_text_field($_GET['region']);
    }
    if (!empty($_GET['sub_region'])) {
        $filters['sub_region'] = sanitize_text_field($_GET['sub_region']);
    }
    if (!empty($_GET['group_name'])) {
        $filters['group_name'] = sanitize_text_field($_GET['group_name']);
    }
    if (!empty($_GET['q'])) {
        $filters['q'] = sanitize_text_field($_GET['q']);
    }

    $args = [
        'page'     => $page,
        'per_page' => $per_page,
        'orderby'  => $orderby,
        'order'    => $order,
        'filters'  => $filters,
    ];

    $total_all      = bc_get_people_count($current_project_id, []);
    $total_filtered = bc_get_people_count($current_project_id, $args);
    $total_pages    = max(1, ceil($total_filtered / $per_page));
    $people         = bc_get_people_list_with_branch($current_project_id, $args);
    ?>

    <h3 style="margin-top:16px;">人員清單（可直接編輯）</h3>

    <p style="margin:6px 0;color:#444;">
        總人數：<?php echo (int)$total_all; ?>　
        篩選後：<?php echo (int)$total_filtered; ?>　
        本頁顯示：<?php echo is_array($people) ? count($people) : 0; ?>
    </p>

    <form method="get" style="margin:12px 0;">
        <input type="hidden" name="page" value="bible-checkin">
        <input type="hidden" name="tab" value="people">

        分堂：
        <select name="branch_id">
            <option value="">全部</option>
            <?php foreach ($branches_active as $br): ?>
                <option value="<?php echo esc_attr($br['id']); ?>" <?php selected($_GET['branch_id'] ?? '', $br['id']); ?>>
                    <?php echo esc_html($br['branch_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        牧區：
        <select name="region" id="filter-region">
            <option value="">全部</option>
            <?php foreach ($filter_tree as $branch): ?>
                <?php foreach ($branch['regions'] as $region_name => $r): ?>
                    <option value="<?php echo esc_attr($region_name); ?>"
                        data-branch="<?php echo esc_attr($branch['branch_id']); ?>"
                        <?php selected($_GET['region'] ?? '', $region_name); ?>>
                        <?php echo esc_html($region_name); ?>
                    </option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>

        小區：
        <select name="sub_region" id="filter-sub-region">
            <option value="">全部</option>
            <?php foreach ($filter_tree as $branch): ?>
                <?php foreach ($branch['regions'] as $region_name => $r): ?>
                    <?php foreach ($r['sub_regions'] as $sub_name => $sr): ?>
                        <option value="<?php echo esc_attr($sub_name); ?>"
                            data-branch="<?php echo esc_attr($branch['branch_id']); ?>"
                            data-region="<?php echo esc_attr($region_name); ?>"
                            <?php selected($_GET['sub_region'] ?? '', $sub_name); ?>>
                            <?php echo esc_html($sub_name); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>

        小組：
        <select name="group_name" id="filter-group">
            <option value="">全部</option>
            <?php foreach ($filter_tree as $branch): ?>
                <?php foreach ($branch['regions'] as $region_name => $r): ?>
                    <?php foreach ($r['sub_regions'] as $sub_name => $sr): ?>
                        <?php foreach ($sr['groups'] as $group_name): ?>
                            <option value="<?php echo esc_attr($group_name); ?>"
                                data-branch="<?php echo esc_attr($branch['branch_id']); ?>"
                                data-region="<?php echo esc_attr($region_name); ?>"
                                data-sub-region="<?php echo esc_attr($sub_name); ?>"
                                <?php selected($_GET['group_name'] ?? '', $group_name); ?>>
                                <?php echo esc_html($group_name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>

        狀態：
        <select name="is_active">
            <option value="">全部</option>
            <option value="1" <?php selected($_GET['is_active'] ?? '', '1'); ?>>啟用</option>
            <option value="0" <?php selected($_GET['is_active'] ?? '', '0'); ?>>停用</option>
        </select>

        姓名：
        <input type="text" name="q" value="<?php echo esc_attr($_GET['q'] ?? ''); ?>" style="width:90px;">

        排序：
        <select name="orderby">
            <option value="id">建立順序</option>
            <option value="name" <?php selected($_GET['orderby'] ?? '', 'name'); ?>>姓名</option>
            <option value="group_name" <?php selected($_GET['orderby'] ?? '', 'group_name'); ?>>小組</option>
            <option value="team_no" <?php selected($_GET['orderby'] ?? '', 'team_no'); ?>>小隊</option>
        </select>

        <select name="order">
            <option value="ASC" <?php selected($_GET['order'] ?? '', 'ASC'); ?>>↑</option>
            <option value="DESC" <?php selected($_GET['order'] ?? '', 'DESC'); ?>>↓</option>
        </select>

        每頁：
        <select name="per_page">
            <?php foreach ([20,50,100,200] as $n): ?>
                <option value="<?php echo $n; ?>" <?php selected($per_page, $n); ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>

        <button class="button">套用</button>
    </form>

    <?php if ($people): ?>
    <table class="widefat striped">
    <thead>
    <tr>
        <th style="width:120px;">姓名</th>
        <th style="width:140px;">分堂</th>
        <th>牧區</th>
        <th>小區</th>
        <th>小組</th>
        <th style="width:80px;">小隊</th>
        <th style="width:60px;">啟用</th>
        <th style="width:140px;">操作</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($people as $p): ?>
    <tr>
        <td><input type="text" class="bc-inline-edit regular-text" style="width:100%;" value="<?php echo esc_attr($p['name']); ?>" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="name"></td>
        <td>
            <select class="bc-inline-edit" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="branch_id">
                <option value="0">（未指定）</option>
                <?php foreach ($branches_active as $br): ?>
                    <option value="<?php echo esc_attr($br['id']); ?>" <?php selected($br['id'], $p['branch_id']); ?>>
                        <?php echo esc_html($br['branch_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" class="bc-inline-edit regular-text" style="width:100%;" value="<?php echo esc_attr($p['region']); ?>" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="region"></td>
        <td><input type="text" class="bc-inline-edit regular-text" style="width:100%;" value="<?php echo esc_attr($p['sub_region']); ?>" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="sub_region"></td>
        <td><input type="text" class="bc-inline-edit regular-text" style="width:100%;" value="<?php echo esc_attr($p['group_name']); ?>" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="group_name"></td>
        <td><input type="text" class="bc-inline-edit regular-text" style="width:60px;" value="<?php echo esc_attr($p['team_no']); ?>" data-person-id="<?php echo esc_attr($p['id']); ?>" data-field="team_no"></td>
        <td><?php echo (int)$p['is_active'] ? '是' : '否'; ?></td>
        <td>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
                <input type="hidden" name="bc_action" value="toggle_person_active">
                <input type="hidden" name="person_id" value="<?php echo esc_attr($p['id']); ?>">
                <input type="hidden" name="new_active" value="<?php echo (int)$p['is_active'] ? 0 : 1; ?>">
                <button class="button"><?php echo (int)$p['is_active'] ? '停用' : '啟用'; ?></button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('確定要永久刪除這位人員嗎？');">
                <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
                <input type="hidden" name="bc_action" value="delete_person">
                <input type="hidden" name="person_id" value="<?php echo esc_attr($p['id']); ?>">
                <button class="button button-link-delete" style="color:#a00;">刪除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div style="margin-top:12px;">
    <?php
    $base_url = admin_url('admin.php?page=bible-checkin&tab=people');
    $query = $_GET;
    unset($query['paged']);
    for ($i=1;$i<=$total_pages;$i++):
        $query['paged']=$i;
        $url=add_query_arg($query,$base_url);
    ?>
    <?php if ($i==$page): ?>
    <strong style="margin-right:6px;"><?php echo $i; ?></strong>
    <?php else: ?>
    <a href="<?php echo esc_url($url); ?>" style="margin-right:6px;"><?php echo $i; ?></a>
    <?php endif; ?>
    <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <p>尚未建立任何人員。</p>
    <?php endif; ?>

    <script>
    (function(){
        document.querySelectorAll('.bc-inline-edit').forEach(el=>{
            let oldValue=el.value;
            const save=()=>{
                const newValue=el.value;
                if(newValue===oldValue)return;
                const form=new FormData();
                form.append('action','bc_update_person_field');
                form.append('nonce','<?php echo wp_create_nonce('bc_admin_action'); ?>');
                form.append('person_id',el.dataset.personId);
                form.append('field',el.dataset.field);
                form.append('value',newValue);
                fetch(ajaxurl,{method:'POST',body:form})
                .then(r=>r.json())
                .then(res=>{
                    if(!res.success){alert('更新失敗');el.value=oldValue;}
                    else{oldValue=newValue;el.style.background='#e6ffed';setTimeout(()=>el.style.background='',500);}
                });
            };
            el.addEventListener('change',save);
            el.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();el.blur();save();}});
            el.addEventListener('blur',save);
        });
    })();
    </script>

    <script>
    (function(){
        const branchSel = document.querySelector('select[name="branch_id"]');
        const regionSel = document.getElementById('filter-region');
        const subSel    = document.getElementById('filter-sub-region');
        const groupSel  = document.getElementById('filter-group');

        function filterOptions() {
            const bid = branchSel?.value || '';
            const region = regionSel?.value || '';
            const sub = subSel?.value || '';

            regionSel.querySelectorAll('option[data-branch]').forEach(o => {
                o.hidden = bid && o.dataset.branch !== bid;
            });

            subSel.querySelectorAll('option[data-region]').forEach(o => {
                o.hidden = (bid && o.dataset.branch !== bid) ||
                           (region && o.dataset.region !== region);
            });

            groupSel.querySelectorAll('option[data-sub-region]').forEach(o => {
                o.hidden = (bid && o.dataset.branch !== bid) ||
                           (region && o.dataset.region !== region) ||
                           (sub && o.dataset.subRegion !== sub);
            });
        }

        [branchSel, regionSel, subSel].forEach(el => {
            if (el) el.addEventListener('change', filterOptions);
        });

        filterOptions(); // 初始跑一次
    })();
    </script>

    <hr>
    <?php
}

/* ===== People handlers ===== */

function bc_handle_create_person() {
    global $wpdb;

    $project_id = bc_get_current_project_id();

    // ✅ 同一支 handler 同時支援舊欄位與新欄位（但 UI 會用 *_create，避免撞到 filter）
    $name       = sanitize_text_field($_POST['name_create'] ?? ($_POST['name'] ?? ''));
    $branch_id  = intval($_POST['branch_id_create'] ?? ($_POST['branch_id'] ?? 0));
    $region     = sanitize_text_field($_POST['region_create'] ?? ($_POST['region'] ?? ''));
    $sub_region = sanitize_text_field($_POST['sub_region_create'] ?? ($_POST['sub_region'] ?? ''));
    $group_name = sanitize_text_field($_POST['group_name_create'] ?? ($_POST['group_name'] ?? ''));
    $team_no    = sanitize_text_field($_POST['team_no_create'] ?? ($_POST['team_no'] ?? ''));

    // checkbox：新 UI 用 is_active_create；舊版相容 is_active
    $is_active  = (isset($_POST['is_active_create']) || isset($_POST['is_active'])) ? 1 : 0;

    if ($name === '') {
        bc_admin_error('姓名必填');
        return;
    }

    // ✅ 同名 + 同小組：與 CSV 規則一致 → 不會新增（只提示、不會卡住）
    $people_table = bc_table_people();
    $existing_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM {$people_table}
             WHERE project_id = %d
               AND name = %s
               AND group_name = %s
             LIMIT 1",
            $project_id,
            $name,
            $group_name
        )
    );

    if ($existing_id > 0) {
        bc_admin_notice('已存在同名＋同小組的人員，本次未新增。');
        return;
    }

    $ok = $wpdb->insert(
        $people_table,
        [
            'project_id'  => $project_id,
            'branch_id'   => $branch_id > 0 ? $branch_id : 0,
            'region'      => $region,
            'sub_region'  => $sub_region,
            'group_name'  => $group_name,
            'team_id'     => 0,
            'team_no'     => $team_no,
            'name'        => $name,
            'is_active'   => $is_active,
            'created_at'  => current_time('mysql'),
        ],
        [
            '%d', // project_id
            '%d', // branch_id
            '%s', // region
            '%s', // sub_region
            '%s', // group_name
            '%d', // team_id
            '%s', // team_no
            '%s', // name
            '%d', // is_active
            '%s', // created_at
        ]
    );

    if ($ok !== false) bc_admin_notice('人員已新增');
    else bc_admin_error('人員新增失敗');
}

function bc_handle_toggle_person_active() {
    global $wpdb;

    $project_id = bc_get_current_project_id();
    $person_id  = intval($_POST['person_id']);
    $new_active = intval($_POST['new_active']) ? 1 : 0;

    $ok = $wpdb->update(
        bc_table_people(),
        ['is_active' => $new_active],
        ['id' => $person_id, 'project_id' => $project_id],
        ['%d'],
        ['%d','%d']
    );

    if ($ok !== false) bc_admin_notice('人員狀態已更新');
    else bc_admin_error('人員狀態更新失敗');
}

function bc_handle_delete_person() {
    global $wpdb;

    $project_id = bc_get_current_project_id();
    $person_id  = intval($_POST['person_id'] ?? 0);

    if ($project_id <= 0 || $person_id <= 0) {
        bc_admin_error('參數不正確');
        return;
    }

    // ⚠️ Round 5.1：先不檢查是否已有打卡紀錄（之後 Round 6+ 再補）
    $ok = $wpdb->delete(
        bc_table_people(),
        [
            'id'         => $person_id,
            'project_id' => $project_id,
        ],
        ['%d','%d']
    );

    if ($ok !== false) {
        bc_admin_notice('人員已刪除');
    } else {
        bc_admin_error('人員刪除失敗');
    }
}

/**
 * AJAX: inline update person field (admin only)
 */
add_action('wp_ajax_bc_update_person_field', 'bc_ajax_update_person_field');

function bc_ajax_update_person_field() {
    if ( ! current_user_can('bible_checkin_manage') ) {
        wp_send_json_error(['message' => 'permission denied']);
    }

    check_ajax_referer('bc_admin_action', 'nonce');

    global $wpdb;

    $project_id = bc_get_current_project_id();
    $person_id  = intval($_POST['person_id'] ?? 0);
    $field      = sanitize_key($_POST['field'] ?? '');
    $value      = $_POST['value'] ?? '';

    if ($project_id <= 0 || $person_id <= 0) {
        wp_send_json_error(['message' => 'invalid id']);
    }

    /**
     * 白名單欄位（防止腦補）
     * ⚠️ 只允許這些欄位 inline edit
     */
    $allowed_fields = [
        'name'        => '%s', // ✅ 允許修改姓名
        'branch_id'   => '%d',
        'region'      => '%s',
        'sub_region'  => '%s',
        'group_name'  => '%s',
        'team_no'     => '%s',
    ];

    if ( ! isset($allowed_fields[$field]) ) {
        wp_send_json_error(['message' => 'field not allowed']);
    }

    // sanitize value
    if ($allowed_fields[$field] === '%d') {
        $value = intval($value);
    } else {
        $value = sanitize_text_field($value);
    }

    $ok = $wpdb->update(
        bc_table_people(),
        [ $field => $value ],
        [
            'id'         => $person_id,
            'project_id' => $project_id,
        ],
        [ $allowed_fields[$field] ],
        [ '%d', '%d' ]
    );

    if ($ok === false) {
        wp_send_json_error(['message' => 'db update failed']);
    }

    wp_send_json_success([
        'person_id' => $person_id,
        'field'     => $field,
        'value'     => $value,
    ]);
}

/* =========================
 * Admin queries
 * ========================= */

function bc_admin_get_people_with_branch($project_id) {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) return [];

    $p = bc_table_people();
    $b = bc_table_branches();

    $sql = $wpdb->prepare(
        "SELECT p.*, b.branch_name
         FROM {$p} p
         LEFT JOIN {$b} b
           ON b.id = p.branch_id AND b.project_id = p.project_id
         WHERE p.project_id = %d
         ORDER BY p.is_active DESC, p.group_name ASC, p.team_no ASC, p.name ASC, p.id ASC",
        $project_id
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
}
