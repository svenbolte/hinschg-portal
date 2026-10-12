<?php
/**
Plugin Name: HinSchG Portal
Description: Hinweisgeberschutz-Portal mit Mandantenverwaltung (8-stellige ID via URL-Parameter mandant=XXXXXXXX) und anonymer Hinweisabgabe.
Tags: hinweisgeberschutz, whistleblower, compliance, mandanten, portal, datenschutz
Author: ChatGPT and PBMod
Author URI: https://github.com/svenbolte/
Plugin URI: https://github.com/svenbolte/hinschg-portal/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Version: 1.9.18
Stable tag: 1.9.17
Requires at least: 6.0
Tested up to: 6.8.3
Requires PHP: 8.2
Text Domain: hinschg-portal
**/

if (!defined('ABSPATH')) { exit; }

define('HINSCHG_PORTAL_DB_VERSION', '1.9.9');
define('HINSCHG_PORTAL_OPTION_KEY', 'hinschg_portal_options');
define('HINSCHG_PORTAL_NONCE_ACTION', 'hinschg_portal_action');

function hinschg_portal_activate() {
    hinschg_register_contact_role();
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'hinschg_mandanten';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    // Ensure 'logo_id' exists for older installs
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id CHAR(8) NOT NULL UNIQUE,
        name VARCHAR(190) NOT NULL,
        ort VARCHAR(190) NULL,
        email VARCHAR(190) NOT NULL,
        website VARCHAR(190) NULL,
        logo_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY tenant_id_idx (tenant_id)
    ) {$charset_collate};";
    dbDelta($sql);
    // Separate BLOB storage: no publicly reachable file URL or media-library entry.
    $blob_table = $wpdb->prefix . 'hinschg_private_files';
    $blob_sql = "CREATE TABLE {$blob_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        case_id BIGINT UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        mime VARCHAR(120) NOT NULL,
        content LONGBLOB NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY case_id (case_id)
    ) {$charset_collate};";
    dbDelta($blob_sql);

    
    // Fügt Spalte 'website' hinzu, falls sie bei einem Update fehlt
    if( $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'website'") != 'website' ) {
        $wpdb->query("ALTER TABLE `{$table}` ADD `website` VARCHAR(190) NULL AFTER `email`");
    }

    // Default options
    if (!get_option(HINSCHG_PORTAL_OPTION_KEY)) {
        add_option(HINSCHG_PORTAL_OPTION_KEY, [
            'notify_cc' => '', // optional CC
            'store_ip'  => false, // IP nicht speichern (wir speichern sowieso keine IP)
            'allow_attachments' => true,
            'max_attachment_mb' => 10,
        ]);
    }

    // Öffentliche Portalseite nur anlegen, wenn der Slug noch nicht existiert.
    $portal_page = get_page_by_path('hinweis', OBJECT, 'page');
    if (!$portal_page) {
        // Auch Papierkorb-Einträge berücksichtigen, um keine Duplikate anzulegen.
        $existing_pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'any',
            'name' => 'hinweis',
            'numberposts' => 1,
        ]);
        if (empty($existing_pages)) {
            wp_insert_post([
                'post_title' => 'Hinweisgeberschutz Portal',
                'post_name' => 'hinweis',
                'post_content' => '[hinweisportal]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }
    }

    update_option('hinschg_portal_db_version', HINSCHG_PORTAL_DB_VERSION, false);
}

function hinschg_portal_register_cpt_hinweis() {
    register_post_type('hinweis', [
        'label' => __('Hinweise', 'hinschg-portal'),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'hinschg-portal', // als Submenü unter HinSchG Portal
        'show_in_admin_bar' => false,       // kein "Neu" oben in der Admin-Bar
        'capability_type' => ['hinweis', 'hinweise'],
        'map_meta_cap' => true,
        'capabilities' => [
            'create_posts' => 'do_not_allow', // verhindert "Beitrag anlegen" im Backend
        ],
        'supports' => ['title', 'editor'],
        'labels' => [
            'name' => __('Hinweise', 'hinschg-portal'),
            'singular_name' => __('Hinweis', 'hinschg-portal'),
            'add_new' => __('(Deaktiviert)', 'hinschg-portal'),
            'add_new_item' => __('(Deaktiviert)', 'hinschg-portal'),
        ],
    ]);
}

/* ===================== Admin: Mandanten ===================== */

function hinschg_portal_register_admin_menu() {
    add_menu_page(
        __('HinSchG Portal', 'hinschg-portal'),
        __('HinSchG Portal', 'hinschg-portal'),
        'manage_options',
        'hinschg-portal',
        'hinschg_portal_render_cockpit_page',
        'dashicons-shield'
    );
    add_submenu_page('hinschg-portal', __('Cockpit', 'hinschg-portal'), __('Cockpit', 'hinschg-portal'), 'manage_options', 'hinschg-portal', 'hinschg_portal_render_cockpit_page');
    add_submenu_page('hinschg-portal', __('Mandanten', 'hinschg-portal'), __('Mandanten', 'hinschg-portal'), 'manage_options', 'hinschg-portal-mandanten', 'hinschg_portal_render_mandanten_page');
    add_submenu_page('hinschg-portal', __('Einstellungen', 'hinschg-portal'), __('Einstellungen', 'hinschg-portal'), 'manage_options', 'hinschg-portal-settings', 'hinschg_portal_render_settings_page');
    hinschg_portal_register_legal_page();
}

function hinschg_portal_render_cockpit_page() {
    if (!current_user_can('manage_options') && !hinschg_is_contact_only()) {
        return;
    }

    global $wpdb;

    // Enforce the same case-level tenant permissions for every dashboard metric.
    $scope_sql = '';
    $scope_ids = [];
    if (!current_user_can('manage_options')) {
        $tenants = hinschg_allowed_tenants();
        if ($tenants) {
            $scope_ids = get_posts([
                'post_type' => 'hinweis', 'post_status' => 'private',
                'fields' => 'ids', 'posts_per_page' => -1,
                'meta_query' => [[ 'key' => '_hinschg_tenant_id', 'value' => $tenants, 'compare' => 'IN' ]],
            ]);
            $scope_ids = array_values(array_filter(array_map('absint', $scope_ids), 'hinschg_can_access_case'));
        }
        $scope_sql = $scope_ids ? ' AND p.ID IN (' . implode(',', $scope_ids) . ')' : ' AND 1=0';
    }
    $statuses = hinschg_portal_statuses();
    $status_counts = array_fill_keys(array_keys($statuses), 0);
    $status_rows = $wpdb->get_results(
        "SELECT COALESCE(NULLIF(pm.meta_value, ''), 'eingegangen') AS report_status, COUNT(DISTINCT p.ID) AS report_count
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_hinschg_status'
         WHERE p.post_type = 'hinweis' AND p.post_status = 'private' {$scope_sql}
         GROUP BY report_status"
    );

    foreach ($status_rows as $row) {
        if (isset($status_counts[$row->report_status])) {
            $status_counts[$row->report_status] = (int) $row->report_count;
        }
    }

    $total = array_sum($status_counts);
    $open = ($status_counts['eingegangen'] ?? 0) + ($status_counts['in_pruefung'] ?? 0) + ($status_counts['rueckfrage'] ?? 0);
    $completed = ($status_counts['abgeschlossen'] ?? 0) + ($status_counts['abgelehnt'] ?? 0);
    $last_30_days = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'hinweis' AND p.post_status = 'private' {$scope_sql} AND p.post_date >= %s",
            gmdate('Y-m-d H:i:s', current_time('timestamp', true) - (30 * DAY_IN_SECONDS))
        )
    );
    $with_attachment = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_hinschg_attachment_id' AND pm.meta_value <> '' AND pm.meta_value <> '0'
         WHERE p.post_type = 'hinweis' AND p.post_status = 'private' {$scope_sql}"
    );
    $oldest_open = $wpdb->get_var(
        "SELECT MIN(p.post_date)
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_hinschg_status'
         WHERE p.post_type = 'hinweis' AND p.post_status = 'private' {$scope_sql}
         AND COALESCE(NULLIF(pm.meta_value, ''), 'eingegangen') IN ('eingegangen', 'in_pruefung', 'rueckfrage')"
    );
    $oldest_open_days = $oldest_open ? max(0, (int) floor((current_time('timestamp') - strtotime($oldest_open)) / DAY_IN_SECONDS)) : 0;
    $completion_rate = $total > 0 ? round(($completed / $total) * 100) : 0;

    $recent_reports = get_posts([
        'post_type' => 'hinweis',
        'post_status' => 'private',
        'posts_per_page' => 8,
        'orderby' => 'date',
        'order' => 'DESC',
        'post__in' => !current_user_can('manage_options') ? ($scope_ids ?: [0]) : [],
    ]);

    $tenant_rows = $wpdb->get_results(
        "SELECT tenant.meta_value AS tenant_id, COUNT(DISTINCT p.ID) AS total_count,
         SUM(CASE WHEN COALESCE(NULLIF(status.meta_value, ''), 'eingegangen') IN ('eingegangen', 'in_pruefung', 'rueckfrage') THEN 1 ELSE 0 END) AS open_count
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} tenant ON tenant.post_id = p.ID AND tenant.meta_key = '_hinschg_tenant_id'
         LEFT JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = '_hinschg_status'
         WHERE p.post_type = 'hinweis' AND p.post_status = 'private' {$scope_sql}
         GROUP BY tenant.meta_value
         ORDER BY total_count DESC
         LIMIT 8"
    );

    $max_status_count = max(1, ...array_values($status_counts));
    ?>
    <div class="wrap hinschg-cockpit">
        <h1><?php esc_html_e('HinSchG Cockpit', 'hinschg-portal'); ?></h1>
        <p class="hinschg-cockpit-subtitle"><?php esc_html_e('Überblick über Meldungen, Bearbeitungsstände und Mandanten.', 'hinschg-portal'); ?></p>
        <style>
		.hinschg-cockpit{max-width:100%}
		.hinschg-cockpit-subtitle{color:#646970;font-size:14px;margin-bottom:1rem}
		.hinschg-kpi-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin:0 0 10px}
		.hinschg-kpi-card,.hinschg-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 2px 8px #0000000d}
		.hinschg-kpi-card{padding:18px}
		.hinschg-kpi-label{color:#646970;display:block;font-size:13px;margin-bottom:8px}
		.hinschg-kpi-value{color:#1d2327;display:block;font-size:30px;font-weight:700;line-height:1}
		.hinschg-kpi-note{color:#646970;display:block;font-size:12px;margin-top:9px}
		.hinschg-dashboard-grid{display:grid;gap:18px;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);margin-bottom:18px}
		.hinschg-panel{overflow:hidden}
		.hinschg-panel h2{border-bottom:1px solid #e2e4e7;font-size:16px;margin:0;padding:16px 18px}
		.hinschg-panel-body{padding:18px}
		.hinschg-status-row{align-items:center;display:grid;gap:12px;grid-template-columns:minmax(130px,1fr) 3fr 42px;margin-bottom:14px}
		.hinschg-status-row:last-child{margin-bottom:0}
		.hinschg-status-bar{background:#f0f0f1;border-radius:999px;height:12px;overflow:hidden}
		.hinschg-status-bar span{background:#2271b1;border-radius:999px;display:block;height:100%;min-width:2px}
		.hinschg-status-count{font-weight:600;text-align:right}
		.hinschg-cockpit table{border-collapse:collapse;width:100%}
		.hinschg-cockpit th,.hinschg-cockpit td{border-bottom:1px solid #f0f0f1;padding:11px 12px;text-align:left;vertical-align:top}
		.hinschg-cockpit th{color:#50575e;font-size:12px;text-transform:uppercase}
		.hinschg-status-badge{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;display:inline-block;font-size:12px;padding:3px 9px;white-space:nowrap}
		.hinschg-empty{color:#646970;margin:0}
		@media (max-width: 1100px) {
		.hinschg-dashboard-grid{grid-template-columns:1fr}
		}
		@media (max-width: 782px) {
		.hinschg-status-row{grid-template-columns:1fr 2fr 36px}
		.hinschg-panel{overflow-x:auto}
		}
        </style>
        <div class="hinschg-kpi-grid">
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Hinweise gesamt</span>
                <span class="hinschg-kpi-value"><?php echo esc_html(number_format_i18n($total)); ?></span>
                <span class="hinschg-kpi-note">Alle gespeicherten Vorgänge</span>
            </div>
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Offene Vorgänge</span>
                <span class="hinschg-kpi-value"><?php echo esc_html(number_format_i18n($open)); ?></span>
                <span class="hinschg-kpi-note">Eingegangen, in Prüfung oder Rückfrage</span>
            </div>
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Abschlussquote</span>
                <span class="hinschg-kpi-value"><?php echo esc_html($completion_rate); ?> %</span>
                <span class="hinschg-kpi-note"><?php echo esc_html(number_format_i18n($completed)); ?> erledigte Vorgänge</span>
            </div>
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Neue Hinweise</span>
                <span class="hinschg-kpi-value"><?php echo esc_html(number_format_i18n($last_30_days)); ?></span>
                <span class="hinschg-kpi-note">In den letzten 30 Tagen</span>
            </div>
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Mit Anhang</span>
                <span class="hinschg-kpi-value"><?php echo esc_html(number_format_i18n($with_attachment)); ?></span>
                <span class="hinschg-kpi-note">Vorgänge mit hochgeladener Datei</span>
            </div>
            <div class="hinschg-kpi-card">
                <span class="hinschg-kpi-label">Ältester offener Vorgang</span>
                <span class="hinschg-kpi-value"><?php echo esc_html(number_format_i18n($oldest_open_days)); ?></span>
                <span class="hinschg-kpi-note">Tage seit Eingang</span>
            </div>
        </div>

        <div class="hinschg-dashboard-grid">
            <section class="hinschg-panel">
                <h2>Statusverteilung</h2>
                <div class="hinschg-panel-body">
                    <?php foreach ($statuses as $status_key => $status_label) :
                        $count = $status_counts[$status_key];
                        $width = ($count / $max_status_count) * 100;
                    ?>
                        <div class="hinschg-status-row">
                            <span><?php echo esc_html($status_label); ?></span>
                            <div class="hinschg-status-bar"><span style="width:<?php echo esc_attr(number_format($width, 2, '.', '')); ?>%"></span></div>
                            <span class="hinschg-status-count"><?php echo esc_html(number_format_i18n($count)); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="hinschg-panel">
                <h2>Mandanten mit den meisten Hinweisen</h2>
                <?php if ($tenant_rows) : ?>
                    <table>
                        <thead><tr><th>Mandant</th><th>Gesamt</th><th>Offen</th></tr></thead>
                        <tbody>
                        <?php foreach ($tenant_rows as $tenant_row) :
                            $mandant = hinschg_portal_get_mandant_by_tenant_id($tenant_row->tenant_id);
                            $tenant_name = $mandant ? $mandant->name : $tenant_row->tenant_id;
                        ?>
                            <tr>
                                <td><?php echo esc_html($tenant_name); ?><br><small><?php echo esc_html($tenant_row->tenant_id); ?></small></td>
                                <td><?php echo esc_html(number_format_i18n((int) $tenant_row->total_count)); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $tenant_row->open_count)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="hinschg-panel-body"><p class="hinschg-empty">Noch keine Hinweise vorhanden.</p></div>
                <?php endif; ?>
            </section>
        </div>

        <section class="hinschg-panel">
            <h2>Zuletzt eingegangene Hinweise</h2>
            <?php if ($recent_reports) : ?>
                <table>
                    <thead><tr><th>Datum</th><th>Betreff</th><th>Mandant</th><th>Status</th><th>Aktion</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_reports as $report) :
                        $tenant_id = get_post_meta($report->ID, '_hinschg_tenant_id', true);
                        $mandant = $tenant_id ? hinschg_portal_get_mandant_by_tenant_id($tenant_id) : false;
                    ?>
                        <tr>
                            <td><?php echo esc_html(get_the_date('d.m.Y H:i', $report)); ?></td>
                            <td><strong><?php echo esc_html(get_the_title($report)); ?></strong></td>
                            <td><?php echo esc_html($mandant ? $mandant->name : ($tenant_id ?: '–')); ?></td>
                            <td><span class="hinschg-status-badge"><?php echo esc_html(hinschg_portal_get_status_label($report->ID)); ?></span></td>
                            <td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($report->ID)); ?>">Öffnen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <div class="hinschg-panel-body"><p class="hinschg-empty">Noch keine Hinweise vorhanden.</p></div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

function hinschg_portal_get_mandanten($search = '') {
    global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
    /* HP_FIX: ensure logo_id column */
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    if ($search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE tenant_id LIKE %s OR name LIKE %s ORDER BY created_at DESC", $like, $like));
    }
    return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
}

function hinschg_portal_get_mandant_by_tenant_id($tenant_id) {
    global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
    /* HP_FIX: ensure logo_id column */
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE tenant_id = %s", $tenant_id));
}

// VOLLSTÄNDIGE UND KORRIGIERTE render_mandanten_page() FUNKTION

function hinschg_portal_render_mandanten_page() {
    /* HP_FIX: opts in mandanten page */
    $opts = get_option(HINSCHG_PORTAL_OPTION_KEY, []);
    if (!current_user_can('manage_options')) { return; }
    $nonce = wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION);
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $mandanten = hinschg_portal_get_mandanten($search);
    $edit_data_id = isset($_GET['edit_data']) ? sanitize_text_field(wp_unslash($_GET['edit_data'])) : '';
    $edit_data = preg_match('/^[0-9]{8}$/', $edit_data_id) ? hinschg_portal_get_mandant_by_tenant_id($edit_data_id) : null;
    $show_mandant_form = $edit_data || isset($_GET['new']);
    
    // --- START: Logo Edit View (falls 'edit' Parameter gesetzt) ---
    if (isset($_GET['edit']) && preg_match('/^\d{8}$/', $_GET['edit'])) {
        $edit_id = $_GET['edit'];
        $m = hinschg_portal_get_mandant_by_tenant_id($edit_id);
        if ( function_exists('wp_enqueue_media') ) { wp_enqueue_media(); }
        
        if ($m) {
            // Determine current logo URL and ID
            $current_logo_id = (int) $m->logo_id;
            $current_logo_url = $current_logo_id ? wp_get_attachment_url($current_logo_id) : '';
            $success_msg = isset($_GET['updated']) && $_GET['updated'] === 'logo_ok' ? '<div class="updated notice"><p>Logo erfolgreich gespeichert.</p></div>' : '';
            
            ?>
            <div class="wrap">
                <h1><?php _e('Mandanten – Logo bearbeiten', 'hinschg-portal'); ?></h1>
                <?php echo $success_msg; ?>
                <h2><?php echo esc_html($m->name); ?> (<?php echo esc_html($m->tenant_id); ?>)</h2>

                <a href="<?php echo esc_url( remove_query_arg(['edit', 'updated'], admin_url('admin.php?page=hinschg-portal-mandanten')) ); ?>" class="button">&laquo; Zurück zur Mandanten-Übersicht</a>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 20px;">
                    <input type="hidden" name="action" value="hinschg_save_logo" />
                    <input type="hidden" name="tenant_id" value="<?php echo esc_attr($m->tenant_id); ?>" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION)); ?>"/>
                    
                    <h3><?php _e('Aktuelles Logo', 'hinschg-portal'); ?></h3>
                    <?php if ($current_logo_url): ?>
                        <img src="<?php echo esc_url($current_logo_url); ?>" style="max-width: 200px; max-height: 100px; border: 1px solid #ccc; padding: 5px; background: #fff; display: block; margin-bottom: 10px;" alt="<?php echo esc_attr($m->name); ?> Logo" />
                    <?php else: ?>
                        <p><?php _e('Kein Logo hinterlegt.', 'hinschg-portal'); ?></p>
                    <?php endif; ?>

                    <h3><?php _e('Logo auswählen oder hochladen', 'hinschg-portal'); ?></h3>
                    <p>
                        <input type="hidden" name="logo_id" id="hinschg_logo_id" value="<?php echo esc_attr($current_logo_id); ?>" />
                        <button type="button" class="button button-secondary hinschg_upload_logo_button" data-tenant-id="<?php echo esc_attr($m->tenant_id); ?>">
                            <?php _e('Logo auswählen/hochladen', 'hinschg-portal'); ?>
                        </button>
                        <?php if ($current_logo_id): ?>
                            <button type="button" class="button button-secondary hinschg_remove_logo_button"><?php _e('Logo entfernen', 'hinschg-portal'); ?></button>
                        <?php endif; ?>
                        <p class="description"><?php _e('Optimale Größe: 200x100 Pixel (wird automatisch skaliert).', 'hinschg-portal'); ?></p>
                        <button type="submit" class="button button-primary" style="display:none;">Logo speichern</button>
                    </p>
                </form>

                <script>
                jQuery(document).ready(function($){
                    var media_uploader;
                    
                    // Set cookie for filename prefixing (um die Mandanten-ID in der prefix_tenant_filename Funktion zu nutzen)
                    $('.hinschg_upload_logo_button').on('click', function(e) {
                        e.preventDefault();
                        var tenantId = $(this).data('tenant-id');
                        document.cookie = "hinschg_tenant=" + tenantId + "; path=/; max-age=" + (3600); // 1 Stunde Gültigkeit
                        
                        if (media_uploader) { media_uploader.open(); return; }

                        media_uploader = wp.media.frames.file_frame = wp.media({
                            title: '<?php _e("Logo für Mandant auswählen", "hinschg-portal"); ?>',
                            button: { text: '<?php _e("Logo verwenden", "hinschg-portal"); ?>' },
                            multiple: false
                        });

                        media_uploader.on('select', function() {
                            var attachment = media_uploader.state().get('selection').first().toJSON();
                            $('#hinschg_logo_id').val(attachment.id);
                            // Sende das Formular ab, um die ID zu speichern
                            $('#hinschg_logo_id').closest('form').submit(); 
                        });

                        media_uploader.open();
                    });

                    // Logo entfernen
                    $('.hinschg_remove_logo_button').on('click', function(e) {
                        e.preventDefault();
                        if(confirm('<?php _e("Soll das Logo wirklich entfernt werden?", "hinschg-portal"); ?>')) {
                            $('#hinschg_logo_id').val('');
                            // Sende das Formular ab, um die leere ID zu speichern
                            $('#hinschg_logo_id').closest('form').submit();
                        }
                    });
                    
                    // Cookie löschen, wenn die Seite verlassen wird
                    $(window).on('beforeunload', function() {
                        document.cookie = "hinschg_tenant=; path=/; max-age=0";
                    });
                });
                </script>

                <h2 style="margin-top: 2em;"><?php _e('Mandanten löschen', 'hinschg-portal'); ?></h2>
                <p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php _e('Diesen Mandanten löschen? Alle Hinweise bleiben erhalten.', 'hinschg-portal'); ?>');" style="display:inline;">
                        <input type="hidden" name="action" value="hinschg_delete_mandant" />
                        <input type="hidden" name="tenant_id" value="<?php echo esc_attr($m->tenant_id); ?>" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION)); ?>"/>
                        <button class="button button-link-delete"><?php _e('Mandanten löschen', 'hinschg-portal'); ?></button>
                    </form>
                </p>
            </div>
            <?php
        } else {
            echo '<div class="wrap"><h1>' . __('Mandant nicht gefunden.', 'hinschg-portal') . '</h1></div>';
        }
        return; // Beendet die Funktion nach Rendering der Detailansicht
    }
    // --- END: Logo Edit View ---
    
    // --- START: Hauptansicht Mandanten-Liste (Wiederhergestellt) ---
    // Basis-URL für das Portal (aus der settings_page entnommen)
    $portal_base_url = home_url('/hinweis/') . '?mandant='; 
    ?>
    <div class="wrap">
        <h1><?php _e('Mandanten verwalten', 'hinschg-portal'); ?></h1>

        
        <?php // Formular zum Anlegen/Bearbeiten eines Mandanten
        if (isset($_GET['updated']) && $_GET['updated'] === '1') { echo '<div class="updated notice"><p>Mandant gespeichert.</p></div>'; }
        if (isset($_GET['deleted'])) { echo '<div class="updated notice"><p>Mandant gelöscht.</p></div>'; }
        if (isset($_GET['error'])) { 
            $msg = ($_GET['error'] === 'id') ? 'Die ID muss 8-stellig und numerisch sein.' : 'Die E-Mail-Adresse ist ungültig.';
            echo '<div class="error notice"><p>Fehler: ' . esc_html($msg) . '</p></div>'; 
        }
        ?>
        
        <?php if (!$show_mandant_form): ?>
            <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page'=>'hinschg-portal-mandanten','new'=>'1'], admin_url('admin.php'))); ?>">+ Neuen Mandanten anlegen</a></p>
        <?php else: ?>
        <h2><?php echo $edit_data ? 'Mandanten bearbeiten' : 'Neuen Mandanten anlegen'; ?></h2>
        <?php if (!$edit_data): ?>
        <div class="notice notice-info" style="margin: 12px 0 18px; padding: 12px 16px; border-left: 4px solid #3858d6;">
            <strong><?php esc_html_e('Nach dem Anlegen eines Mandanten: Ansprechpartner zuweisen', 'hinschg-portal'); ?></strong>
            <div>
                <p><?php esc_html_e('Unter Benutzer → Neu hinzufügen einen Benutzer mit der Rolle „HinSchG-Ansprechpartner“ anlegen. Anschließend im Benutzerprofil unter „HinSchG – Mandanten“ einen oder mehrere Mandanten auswählen und speichern. Bereits vorhandene Benutzer können dort ebenfalls zugeordnet werden.', 'hinschg-portal'); ?></p>
                <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('user-new.php?role=hinschg_ansprechpartner')); ?>"><?php esc_html_e('Benutzer anlegen', 'hinschg-portal'); ?></a> <a href="<?php echo esc_url(admin_url('users.php')); ?>"><?php esc_html_e('Bestehende Benutzer verwalten', 'hinschg-portal'); ?></a></p>
                <p><em><?php esc_html_e('WordPress-Administratoren haben Zugriff auf alle Mandanten, ohne gesonderte Zuordnung.', 'hinschg-portal'); ?></em></p>
            </div>
        </div>
        <?php endif; ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=hinschg-portal-mandanten')); ?>" class="button">Abbrechen / zurück zur Übersicht</a></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="hinschg_save_mandant" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION)); ?>"/>
            <style>
                .hinschg-mandant-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 24px;max-width:900px;margin:18px 0 22px}
                .hinschg-mandant-field{display:flex;flex-direction:column;gap:7px;min-width:0}
                .hinschg-mandant-field label{font-weight:600}
                .hinschg-mandant-field input{width:100%;max-width:none;min-height:38px;box-sizing:border-box}
                @media(max-width:782px){.hinschg-mandant-fields{grid-template-columns:1fr;gap:14px}}
            </style>
            <div class="hinschg-mandant-fields">
                <div class="hinschg-mandant-field">
                    <label for="tenant_id"><?php _e('ID (8-stellig, nur Zahlen)', 'hinschg-portal'); ?></label>
                    <input type="text" id="tenant_id" name="tenant_id" required pattern="[0-9]{8}" maxlength="8" placeholder="12345678" value="<?php echo esc_attr($edit_data ? $edit_data->tenant_id : ''); ?>" <?php echo $edit_data ? 'readonly' : ''; ?>/>
                </div>
                <div class="hinschg-mandant-field">
                    <label for="name"><?php _e('Name', 'hinschg-portal'); ?></label>
                    <input type="text" id="name" name="name" required placeholder="Firma Mustermann GmbH" value="<?php echo esc_attr($edit_data ? $edit_data->name : ''); ?>"/>
                </div>
                <div class="hinschg-mandant-field">
                    <label for="ort"><?php _e('Ort', 'hinschg-portal'); ?></label>
                    <input type="text" id="ort" name="ort" placeholder="Musterstadt" value="<?php echo esc_attr($edit_data ? $edit_data->ort : ''); ?>"/>
                </div>
                <div class="hinschg-mandant-field">
                    <label for="email"><?php _e('E-Mail für Benachrichtigung', 'hinschg-portal'); ?></label>
                    <input type="email" id="email" name="email" required placeholder="kontakt@mandant.de" value="<?php echo esc_attr($edit_data ? $edit_data->email : ''); ?>"/>
                </div>
                <div class="hinschg-mandant-field">
                    <label for="website"><?php _e('Webseite (optional)', 'hinschg-portal'); ?></label>
                    <input type="url" id="website" name="website" placeholder="https://www.mandant.de" value="<?php echo esc_attr($edit_data ? $edit_data->website : ''); ?>"/>
                </div>
            </div>
            <p><button class="button button-primary"><?php echo $edit_data ? 'Änderungen speichern' : 'Mandanten anlegen'; ?></button></p>
        </form>
        <?php endif; ?>
        
        <?php if (!empty($mandanten)) { ?>
        <h2><?php _e('Vorhandene Mandanten', 'hinschg-portal'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 10%"><?php _e('ID', 'hinschg-portal'); ?></th>
                    <th scope="col" style="width: 30%"><?php _e('Link kopieren', 'hinschg-portal'); ?></th>
                    <th scope="col"><?php _e('Name', 'hinschg-portal'); ?></th>
                    <th scope="col"><?php _e('Ort', 'hinschg-portal'); ?></th>
                    <th scope="col"><?php _e('E-Mail', 'hinschg-portal'); ?></th>
                    <th scope="col"><?php _e('Webseite', 'hinschg-portal'); ?></th>
                    <th scope="col" style="width: 15%"><?php _e('Aktionen', 'hinschg-portal'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mandanten as $m) : 
                    $copy_url = add_query_arg('mandant', $m->tenant_id, home_url('/hinweis/'));
                ?>
                <tr>
                    <td data-colname="ID"><?php echo esc_html($m->tenant_id); ?></td>
                    <td data-colname="Link kopieren">
                        <input id="copy-hinurl-<?php echo esc_attr($m->tenant_id); ?>" name="copy-hinurl" type="text" class="copy-to-clipboard" value="<?php echo esc_url($copy_url); ?>" readonly style="width: 100%; cursor: pointer;" />
							<p class="newlabel" style="background-color:#fe8;display:none">Pfad in Zwischenablage kopiert</p>
                    </td>
                    <td data-colname="Name">
                        <?php echo esc_html($m->name); ?>
                        <a href="<?php echo esc_url( add_query_arg(['page'=>'hinschg-portal-mandanten','edit'=>$m->tenant_id], admin_url('admin.php')) ); ?>" style="font-size: 0.9em; display: block;"><?php _e('(Logo bearbeiten)', 'hinschg-portal'); ?></a>
                    </td>
                    <td data-colname="Ort"><?php echo esc_html($m->ort); ?></td>
                    <td data-colname="E-Mail"><?php echo esc_html($m->email); ?></td>
                    <td data-colname="Webseite"><?php echo !empty($m->website) ? '<a href="' . esc_url($m->website) . '" target="_blank">' . esc_html(parse_url($m->website, PHP_URL_HOST)) . '</a>' : '–'; ?></td>
                    <td data-colname="Aktionen">
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page'=>'hinschg-portal-mandanten', 'edit_data'=>$m->tenant_id], admin_url('admin.php'))); ?>">Bearbeiten</a> 
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php _e('Diesen Mandanten löschen? Alle Hinweise bleiben erhalten.', 'hinschg-portal'); ?>');" style="display:inline;">
                            <input type="hidden" name="action" value="hinschg_delete_mandant" />
                            <input type="hidden" name="tenant_id" value="<?php echo esc_attr($m->tenant_id); ?>" />
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION)); ?>"/>
                            <button class="button button-small button-link-delete"><?php _e('Löschen', 'hinschg-portal'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php } else { ?>
            <p><strong><?php _e('Keine Einträge gefunden.', 'hinschg-portal'); ?></strong></p>
        <?php } ?>
    </div>
    <?php
}

function hinschg_portal_handle_save_mandant() {
    if (!current_user_can('manage_options')) { wp_die('Nope'); }
    check_admin_referer(HINSCHG_PORTAL_NONCE_ACTION);
    if ( ! function_exists('media_handle_upload') ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
    /* HP_FIX: ensure logo_id column */
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $ort = isset($_POST['ort']) ? sanitize_text_field($_POST['ort']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    // NEU: Webseite
    $website = isset($_POST['website']) ? esc_url_raw($_POST['website']) : '';
    
    // Fügt HTTP/HTTPS hinzu, falls Protokoll fehlt und Wert vorhanden
    if (!empty($website) && !preg_match('#^https?://#i', $website)) {
         $website = 'https://' . $website;
    }

    
    // Preserve existing fields if this is a partial update (e.g., only logo upload)
    $existing = null;
    if ($tenant_id) { $existing = hinschg_portal_get_mandant_by_tenant_id($tenant_id); }
    if ($existing) {
        if ($name === '')   { $name   = $existing->name; }
        if ($ort === '')    { $ort    = $existing->ort; }
        if ($email === '')  { $email  = $existing->email; }
        if ($website === ''){ $website= $existing->website; }
    }
if (!preg_match('/^\d{8}$/', $tenant_id)) {
        wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'error' => 'id'], admin_url('admin.php')));
        exit;
    }
    if (!is_email($email)) {
        wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'error' => 'email'], admin_url('admin.php')));
        exit;
    }

    $data = [
        'name'      => $name,
        'ort'       => $ort,
        'email'     => $email,
        'website'   => $website, // NEU: Webseite
    ];

    $existing = hinschg_portal_get_mandant_by_tenant_id($tenant_id);
    if ($existing) {
        // Update
        $wpdb->update($table, $data, ['tenant_id' => $tenant_id]);
    } else {
        // Insert
        $data['tenant_id'] = $tenant_id;
        $wpdb->insert($table, $data);
    }
    if ($wpdb->last_error) { wp_die(esc_html('Mandant konnte nicht gespeichert werden: ' . $wpdb->last_error)); }
    wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'updated' => '1', 'created' => ($existing ? '0' : '1'), 'logo' => (!empty($logo_id) || !empty($logo_saved) ? 'ok' : (isset($upload_err) ? $upload_err : 'na')), 'f' => isset($logo_field)? $logo_field : ''], admin_url('admin.php')));
    exit;
}

function hinschg_portal_handle_delete_mandant() {
    if (!current_user_can('manage_options')) { wp_die('Nope'); }
    check_admin_referer(HINSCHG_PORTAL_NONCE_ACTION);
    global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
    /* HP_FIX: ensure logo_id column */
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
    if (preg_match('/^\d{8}$/', $tenant_id)) {
        $wpdb->delete($table, ['tenant_id' => $tenant_id]);
    }
    wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'deleted' => '1'], admin_url('admin.php')));
    exit;
}

/**
 * Speichert die Logo-ID für einen Mandanten.
 */

function hinschg_portal_handle_save_logo() {
    if (!current_user_can('manage_options')) { wp_die('Nope'); }
    check_admin_referer(HINSCHG_PORTAL_NONCE_ACTION);
    
    global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
    /* HP_FIX: ensure logo_id column */
    if ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'logo_id'") != 'logo_id') {
        $wpdb->query("ALTER TABLE {$table} ADD logo_id BIGINT UNSIGNED NULL AFTER website");
    }
    
    $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
    // logo_id kann 0 sein, wenn "Logo entfernen" geklickt wird
    $logo_id = isset($_POST['logo_id']) ? intval($_POST['logo_id']) : 0;

    if (!preg_match('/^\d{8}$/', $tenant_id)) {
        wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'error' => 'id'], admin_url('admin.php')));
        exit;
    }

    // Führt ein Update nur der logo_id durch
    $wpdb->update($table, ['logo_id' => $logo_id], ['tenant_id' => $tenant_id]);

    // Leitet zur Bearbeitungsseite zurück mit einer Erfolgsmeldung
    wp_redirect(add_query_arg(['page' => 'hinschg-portal-mandanten', 'edit' => $tenant_id, 'updated' => 'logo_ok'], admin_url('admin.php')));
    exit;
}

function hinschg_portal_maybe_migrate() {
    $installed_version = get_option('hinschg_portal_db_version', '0');

    if (version_compare($installed_version, HINSCHG_PORTAL_DB_VERSION, '>=')) {
        return;
    }

    hinschg_portal_activate();
}

function hinschg_portal_prefix_tenant_filename($filename) {
    // Read tenant id from cookie set by our media modal
    $tid = isset($_COOKIE['hinschg_tenant']) ? preg_replace('/[^0-9]/', '', $_COOKIE['hinschg_tenant']) : '';
    if ($tid && preg_match('/^\d{8}$/', $tid)) {
        $info = pathinfo($filename);
        $name = $info['filename'] ?? $filename;
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        // Avoid double prefix
        if (strpos($name, $tid . '-') !== 0) {
            $filename = $tid . '-' . $name . $ext;
        }
    }
    return $filename;
}

/* ===================== Einstellungen ===================== */

function hinschg_portal_render_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    $opts = get_option(HINSCHG_PORTAL_OPTION_KEY, []);
    if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], HINSCHG_PORTAL_NONCE_ACTION)) {
        $opts['notify_cc'] = isset($_POST['notify_cc']) ? sanitize_text_field($_POST['notify_cc']) : '';
        update_option(HINSCHG_PORTAL_OPTION_KEY, $opts);
        echo '<div class="updated notice"><p>Einstellungen gespeichert.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php _e('Einstellungen', 'hinschg-portal'); ?></h1>
        <form method="post">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(HINSCHG_PORTAL_NONCE_ACTION)); ?>"/>
            <table class="form-table">
                <tr><th>Benachrichtigungs-CC</th><td><input name="notify_cc" value="<?php echo esc_attr($opts['notify_cc'] ?? ''); ?>" placeholder="cc@example.org"/></td></tr>
            </table>
            <p><button class="button button-primary">Speichern</button></p>
        </form>
        <hr/>
        <p>Bei der Aktivierung des Plugins wird automatisch die Seite <strong>„Hinweisgeberschutz Portal“</strong> mit dem Slug <code>hinweis</code> und dem Shortcode <code>[hinweisportal]</code> erstellt, sofern sie noch nicht vorhanden ist. Mandanten werden über den URL-Parameter <code>?mandant=12345678</code> aufgerufen.</p>
        <div><strong>URL zu dieser Seite: <input id="copy-hinurl" name="copy-hinurl" type="text" class="copy-to-clipboard" style="min-width:50%" value="<?php echo esc_url(add_query_arg('mandant', '12345678', home_url('/hinweis/'))); ?>">
			<p class="newlabel" style="background-color:#fe8;display:none">Pfad in Zwischenablage kopiert</p></div>
			<p>Datenschutz: Es werden vom Formular keine IP-Adressen oder Cookies gespeichert. Serverseitige Logs können unabhängig davon IPs erfassen – bitte Webserver entsprechend konfigurieren.</p>
    </div>
    <?php
}

/* ===================== Frontend: Shortcode ===================== */

function hinschg_portal_enqueue_styles() {
    wp_register_style('hinschg-portal', false);
    wp_enqueue_style('hinschg-portal');
    $theme_color = sanitize_hex_color(get_theme_mod('link-color', '#006060'));
    $theme_color = $theme_color ?: '#006060';
    $custom_css = sprintf(
        '.hinschg-form{display:flex;flex-direction:column;gap:1em;padding:1.5em;background:#fff;box-shadow:0 4px 14px #0002}
		.hinschg-form label{margin-bottom:.3em;font-weight:600}
		.hinschg-form input[type="text"],.hinschg-form input[type="email"],.hinschg-form input[type="file"],.hinschg-form textarea{box-sizing:border-box;width:100%%;border:1px solid %1$s;font-size:1rem}
		.hinschg-form input:focus,.hinschg-form textarea:focus{border-color:%1$s;outline:none;box-shadow:0 0 0 3px #5553}
		.hinschg-form details{margin-top:1em;padding:.8em 1em;border:1px solid %1$s;background:#f8fafc}
		.hinschg-form details[open]{background:%1$s19}
		.hinschg-portal{max-width:100%%}
		.hinschg-portal .intro-text{margin:0 0 1.5em;color:#333;font-size:1rem;line-height:1.6;text-align:justify}
		.hinschg-portal input[type="text"],.hinschg-portal input[type="email"],.hinschg-portal textarea{box-sizing:border-box;width:100%%;border:1px solid %1$s}
		.hinschg-portal textarea{min-height:140px}
		.hinschg-portal button[type="submit"]{width:100%%}
		.hinschg-portal details{margin-top:1em}
		.notice-success{padding:.5em;border-left:4px solid #22c55e;color:#14532d;background:#e6ffed}
		.notice-error{padding:.5em;border-left:4px solid #ef4444;color:#7f1d1d;background:#fee2e2}
		.hinschg-featured{display:block;width:100%%;max-height:260px;margin:0 0 .75rem;object-fit:contain;background:#fff}
		.hinschg-portal .mandant-header{display:flex;align-items:center;gap:1rem;margin:1em 0 1rem;padding:0}
		.hinschg-portal img.mandant-logo{flex-shrink:0;width:auto;max-width:200px;height:50px;max-height:100px;padding:4px;border:1px solid #e5e7eb;border-radius:6px;object-fit:contain;background:#fff}
		.hinschg-portal .mandant-text-wrapper{flex-grow:1;padding:6px 12px;border:1px solid %1$s19;border-radius:12px;background:linear-gradient(90deg,%1$s40,%1$s16);box-shadow:0 2px 6px #ccc8;text-align:left}
		.hinschg-portal .mandant-text-wrapper .mandant-name{margin:0;padding:0;color:%1$s;font-size:1.4em;font-weight:700;line-height:1.2}
		.hinschg-portal .mandant-secondary-info{margin-top:5px;color:#666;font-size:.9em}
		.hinschg-report-view{margin-top:1em;padding:1em;border:1px solid %1$s;background:#fff}
		.hinschg-report-view h6{margin-top:0}
		.hinschg-report-content{margin-top:1em;padding-top:1em;border-top:1px solid #ddd}
		.hinschg-public-reply{margin-top:1.25em;padding:1em;border-left:4px solid %1$s;background:%1$s12}
		.hinschg-public-reply h6{margin-top:0}
		.hinschg-mailbox{margin-top:1.25em;padding-top:1em;border-top:1px solid #ddd}
		.hinschg-message{margin:.75em 0;padding:.85em 1em;border-radius:8px;background:#f4f5f6}
		.hinschg-message--staff{border-left:4px solid %1$s;background:%1$s10}
		.hinschg-message__meta{display:block;margin-bottom:.35em;font-size:.82em;opacity:.75}
		.hinschg-classification{display:flex;gap:.75em;flex-wrap:wrap;margin:.75em 0}
		.hinschg-chip{display:inline-block;padding:.25em .6em;border:1px solid #dcdcde;border-radius:999px;background:#fff;font-size:.9em}',
        $theme_color
    );
    wp_add_inline_style('hinschg-portal', $custom_css);
}

function hinschg_portal_shortcode_hinweisportal($atts, $content = '') {
    hinschg_portal_enqueue_styles();
    $atts = shortcode_atts(['image' => 1], $atts, 'hinweisportal');
    $show_image = intval($atts['image']) === 1;
    $tenant_id = isset($_GET['mandant']) ? preg_replace('/[^0-9]/', '', $_GET['mandant']) : '';
    $mandant = (preg_match('/^\d{8}$/', $tenant_id)) ? hinschg_portal_get_mandant_by_tenant_id($tenant_id) : null;
    $opts = get_option(HINSCHG_PORTAL_OPTION_KEY, []);
		$hinschginfo = 'Dieses Hinweisgeber-Portal ermöglicht eine sichere, vertrauliche und – wenn gewünscht – anonyme Meldung von Missständen, Compliance-Verstößen oder ethischen Bedenken für das oben angegebene Unternehmen. Ihre Mitteilung wird ausschließlich an die verantwortliche Stelle des oben genannten Mandanten weitergeleitet. Sie können freiwillig eine anonyme Kontaktadresse hinterlassen, falls Rückfragen notwendig sind. Bitte schildern Sie den Sachverhalt so konkret wie möglich und fügen Sie bei Bedarf relevante Dokumente hinzu.';
    ob_start();


echo hinschg_portal_public_information();

    echo '<div class="hinschg-portal">';
    if ($show_image) {
        $feat_url = plugins_url('kategorie-whistleblower1.jpg', __FILE__);
        echo '<img class="hinschg-featured" src="' . esc_url($feat_url) . '" alt="Hinweisgeberschutz" loading="lazy">';
    }

    if (!$mandant) {
			echo '<div class="intro-text">'.$hinschginfo.'</div>';
        echo '<div class="notice-error"><p>Bitte rufen Sie den Link mit einem gültigen Mandanten-Parameter auf (z. B. <code>?mandant=12345678</code>).</p></div>';
        echo '</div>';
        return ob_get_clean();
    }
    
    // KORRIGIERTER BLOCK FÜR LOGO- UND NAMENSANZEIGE (Wiederherstellung des Block-Stils)
    
    // 1. Logo-Daten abrufen
    $logo_html = '';
    $logo_id = (int) $mandant->logo_id;
    if ($logo_id) {
        $logo_url = wp_get_attachment_url($logo_id);
        if ($logo_url) {
            $logo_html = '<img class="mandant-logo" src="' . esc_url($logo_url) . '" alt="' . esc_attr($mandant->name) . ' Logo" loading="lazy" />';
        }
    }
    
    // 2. Header mit Logo und Text-Wrapper
    echo '<div class="mandant-header">';
    echo $logo_html; // Logo anzeigen, falls vorhanden
    
    // NEUES WRAPPER-DIV für den Text, um den Block-Stil anzuwenden
    echo '<div class="mandant-text-wrapper">'; 
    
    // Haupt-Name
    echo '<div class="mandant-name">' . esc_html($mandant->name) . '</div>';
    
    // Ort und Webseite
    echo '<div class="mandant-secondary-info">';
    echo esc_html($mandant->ort);
    if (!empty($mandant->website)) {
        echo ' &nbsp; | &nbsp; <a href="' . esc_url($mandant->website) . '" target="_blank" rel="noopener nofollow">' . parse_url($mandant->website, PHP_URL_HOST) . '</a>';
    }
    echo '</div>'; // End mandant-secondary-info
    
    echo '</div>'; // End mandant-text-wrapper
    echo '</div>'; // End mandant-header

    echo '<div class="intro-text">'.$hinschginfo.'</div>';
    
    // ENDE KORRIGIERTER BLOCK
    
		?>
		<details class="hinweisgesetz-info" style="margin-bottom:1.5em;">
			<summary>Was ist das Hinweisgeberschutzgesetz?</summary>
			<div>
				<p>Das <strong>Hinweisgeberschutzgesetz (HinSchG)</strong> schützt Personen, die Missstände oder Rechtsverstöße in Unternehmen oder Behörden melden – sogenannte Hinweisgeber oder Whistleblower.</p>
				<p>Es verpflichtet Organisationen ab einer bestimmten Größe, eine <strong>interne Meldestelle</strong> einzurichten, über die Mitarbeitenden oder externe Personen sicher und vertraulich Hinweise abgeben können.</p>
				<p><em>Kurz gesagt:</em> Dieses Gesetz sorgt dafür, dass Hinweise auf Fehlverhalten vertraulich behandelt werden und Hinweisgeber vor Benachteiligung oder Repressalien geschützt sind.</p>
			</div>
		</details>
		<?php

    // Handle submission
    $submission_success = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hinschg_submit'])) {
        $result = hinschg_portal_handle_front_submission($mandant, $opts);
        if ($result['ok']) {
            $submission_success = true;
            echo '<div class="notice-success"><p>Vielen Dank. Ihr Hinweis wurde sicher übermittelt. Vorgangs-ID: <code>' . esc_html($result['token']) . '</code></p><p>Bitte bewahren Sie diese Vorgangs-ID sicher auf. Damit können Sie den Vorgang später einsehen und anonym mit der Meldestelle kommunizieren.</p></div>';
        } else {
            echo '<div class="notice-error"><p>' . esc_html($result['msg']) . '</p></div>';
        }
    }

    hinschg_portal_render_lookup($mandant);
    if (!$submission_success) { hinschg_portal_render_front_form($mandant, $opts); }
    echo '</div>';
    return ob_get_clean();
}

function hinschg_portal_render_lookup($mandant) {
    $lookup_result = null;
    $lookup_token = '';
    $message_notice = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hinschg_message_submit'])) {
        $lookup_token = isset($_POST['lookup_token']) ? sanitize_text_field(wp_unslash($_POST['lookup_token'])) : '';
        $message_notice = hinschg_portal_handle_reporter_message($mandant, $lookup_token);
        $message_ok = strpos($message_notice, 'OK:') === 0;
        if ($message_ok) { $message_notice = substr($message_notice, 3); }
        $lookup_result = hinschg_portal_find_report($mandant, $lookup_token, false);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hinschg_lookup'])) {
        $lookup_token = isset($_POST['lookup_token'])
            ? sanitize_text_field(wp_unslash($_POST['lookup_token']))
            : '';

        $lookup_result = hinschg_portal_find_report($mandant, $lookup_token);
    }
    ?>
    <details class="hinschg-lookup"<?php echo $lookup_result !== null ? ' open' : ''; ?>>
        <summary>Bereits eingereichten Hinweis einsehen</summary>
        <div>
            <form method="post" class="hinschg-form" autocomplete="off">
                <?php wp_nonce_field(HINSCHG_PORTAL_NONCE_ACTION . '_lookup', '_hinschg_lookup_nonce'); ?>
                <input type="hidden" name="tenant_id" value="<?php echo esc_attr($mandant->tenant_id); ?>"/>
                <p>
                    <label>
                        Vorgangs-ID<br/>
                        <input
                            type="text"
                            name="lookup_token"
                            value="<?php echo esc_attr($lookup_token); ?>"
                            minlength="12"
                            maxlength="64"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="Ihre Vorgangs-ID"
                        />
                    </label>
                </p>
                <p><button type="submit" name="hinschg_lookup">Hinweis anzeigen</button></p>
            </form>

            <?php if (is_array($lookup_result) && !empty($lookup_result['ok'])) : ?>
                <article class="hinschg-report-view">
                    <h6><?php echo esc_html($lookup_result['title']); ?></h6>
                    <p><strong>Vorgangs-ID:</strong> <code><?php echo esc_html($lookup_result['token']); ?></code></p>
                    <p><strong>Eingegangen am:</strong> <?php echo esc_html($lookup_result['date']); ?></p>
                    <p><strong>Status:</strong> <?php echo esc_html($lookup_result['status']); ?></p>
                    <?php if (!empty($lookup_result['status_updated'])) : ?>
                        <p><strong>Status aktualisiert am:</strong> <?php echo esc_html($lookup_result['status_updated']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($lookup_result['category'])) : ?>
                        <div class="hinschg-classification"><span class="hinschg-chip"><strong>Kategorie:</strong> <?php echo esc_html($lookup_result['category']); ?></span></div>
                    <?php endif; ?>
                    <div class="hinschg-report-content">
                        <?php echo wp_kses_post(wpautop($lookup_result['content'])); ?>
                    </div>
                    <?php if (!empty($lookup_result['public_reply'])) : ?>
                        <div class="hinschg-public-reply">
                            <h6>Frühere Rückmeldung der Meldestelle</h6>
                            <?php echo wp_kses_post(wpautop($lookup_result['public_reply'])); ?>
                        </div>
                    <?php endif; ?>
                    <div class="hinschg-mailbox">
                        <h6>Anonymes Postfach</h6>
                        <?php if (!empty($message_notice)) : ?>
                            <div class="<?php echo !empty($message_ok) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html($message_notice); ?></p></div>
                        <?php endif; ?>
                        <?php if (!empty($lookup_result['messages'])) : ?>
                            <?php foreach ($lookup_result['messages'] as $mail_message) : ?>
                                <div class="hinschg-message <?php echo $mail_message['sender'] === 'staff' ? 'hinschg-message--staff' : 'hinschg-message--reporter'; ?>">
                                    <span class="hinschg-message__meta"><?php echo esc_html($mail_message['sender'] === 'staff' ? 'Meldestelle' : 'Hinweisgeber'); ?> · <?php echo esc_html($mail_message['date_display']); ?></span>
                                    <?php echo wp_kses_post(wpautop($mail_message['message'])); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p>Noch keine Nachrichten im Postfach.</p>
                        <?php endif; ?>
                        <form method="post" class="hinschg-form" autocomplete="off">
                            <?php wp_nonce_field(HINSCHG_PORTAL_NONCE_ACTION . '_message', '_hinschg_message_nonce'); ?>
                            <input type="hidden" name="tenant_id" value="<?php echo esc_attr($mandant->tenant_id); ?>"/>
                            <input type="hidden" name="lookup_token" value="<?php echo esc_attr($lookup_result['token']); ?>"/>
                            <p><label>Nachricht an die Meldestelle<br/><textarea name="mailbox_message" rows="5" maxlength="5000" required placeholder="Antwort oder Ergänzung eingeben …"></textarea></label></p>
                            <p><button type="submit" name="hinschg_message_submit">Nachricht anonym senden</button></p>
                        </form>
                    </div>
                </article>
            <?php elseif (is_array($lookup_result)) : ?>
                <div class="notice-error"><p><?php echo esc_html($lookup_result['msg']); ?></p></div>
            <?php endif; ?>
        </div>
    </details>
    <?php
}

function hinschg_portal_find_report($mandant, $token, $verify_nonce = true) {
    if ($verify_nonce && (
        !isset($_POST['_hinschg_lookup_nonce'])
        || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['_hinschg_lookup_nonce'])),
            HINSCHG_PORTAL_NONCE_ACTION . '_lookup'
        )
    )) {
        return [
            'ok' => false,
            'msg' => 'Sicherheits-Token ungültig. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
        ];
    }

    $posted_tenant_id = isset($_POST['tenant_id'])
        ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['tenant_id']))
        : '';

    if ($posted_tenant_id !== $mandant->tenant_id) {
        return [
            'ok' => false,
            'msg' => 'Mandant ungültig.',
        ];
    }

    if (!preg_match('/^[A-Za-z0-9]{12,64}$/', $token)) {
        return [
            'ok' => false,
            'msg' => 'Bitte geben Sie eine gültige Vorgangs-ID ein.',
        ];
    }

    $reports = get_posts([
        'post_type' => 'hinweis',
        'post_status' => 'private',
        'posts_per_page' => 1,
        'no_found_rows' => true,
        'fields' => 'ids',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_hinschg_tenant_id',
                'value' => $mandant->tenant_id,
                'compare' => '=',
            ],
            [
                'key' => '_hinschg_token',
                'value' => $token,
                'compare' => '=',
            ],
        ],
    ]);

    if (empty($reports)) {
        return [
            'ok' => false,
            'msg' => 'Zu dieser Vorgangs-ID wurde für diesen Mandanten kein Hinweis gefunden.',
        ];
    }

    $post = get_post((int) $reports[0]);
    if (!$post || $post->post_type !== 'hinweis') {
        return [
            'ok' => false,
            'msg' => 'Der Hinweis konnte nicht geladen werden.',
        ];
    }

    return [
        'ok' => true,
        'token' => $token,
        'title' => get_the_title($post) ?: 'Hinweis',
        'date' => get_the_date('d.m.Y H:i', $post),
        'status' => hinschg_portal_get_status_label($post->ID),
        'status_updated' => ($updated_at = get_post_meta($post->ID, '_hinschg_status_updated_at', true))
            ? mysql2date('d.m.Y H:i', $updated_at)
            : '',
        'public_reply' => get_post_meta($post->ID, '_hinschg_public_reply', true),
        'category' => hinschg_portal_get_category_label(get_post_meta($post->ID, '_hinschg_category', true)),
        'messages' => hinschg_portal_get_messages($post->ID),
        'content' => $post->post_content,
        'post_id' => $post->ID,
    ];
}

function hinschg_portal_render_front_form($mandant, $opts) {
    $max_mb = 10;
    // Restore non-sensitive input after upload errors; do not retain attachments or contact email.
    $retry = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hinschg_submit']);
    $old_subject = $retry ? sanitize_text_field(wp_unslash($_POST['subject'] ?? '')) : '';
    $old_category = $retry ? sanitize_key(wp_unslash($_POST['category'] ?? '')) : '';
    $old_message = $retry ? wp_kses_post(wp_unslash($_POST['message'] ?? '')) : '';
    ?>
     <h6>Hinweis abgeben – <?php echo esc_html($mandant->name); ?> (<?php echo esc_html($mandant->tenant_id); ?>)</h6>
    <form method="post" enctype="multipart/form-data" class="hinschg-form" autocomplete="off">
        <?php wp_nonce_field(HINSCHG_PORTAL_NONCE_ACTION, '_wpnonce'); ?>
        <input type="hidden" name="tenant_id" value="<?php echo esc_attr($mandant->tenant_id); ?>"/>
        <fieldset>
            <p><label>Betreff (optional)<br/>
                <input type="text" name="subject" maxlength="180" value="<?php echo esc_attr($old_subject); ?>" placeholder="Kurzer Betreff"/></label></p>
            <p><label>Kategorie<br/>
                <select name="category" required>
                    <option value="">Bitte auswählen</option>
                    <?php foreach (hinschg_portal_categories() as $category_key => $category_label) : ?>
                        <option value="<?php echo esc_attr($category_key); ?>" <?php selected($old_category, $category_key); ?>><?php echo esc_html($category_label); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
            <p><label>Beschreibung des Sachverhalts<br/>
                <textarea name="message" rows="8" required placeholder="Bitte beschreiben Sie den Hinweis möglichst konkret."><?php echo esc_textarea($old_message); ?></textarea></label></p>
            <p><label>Datei anhängen (optional, max. <?php echo esc_html($max_mb); ?> MB)<br/>
                <input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.txt" /></label></p>
            <details><summary>Kontakt aufnehmen? (optional)</summary>
                <div>Wenn Sie eine Rückmeldung wünschen, können Sie eine anonyme E-Mail-Adresse angeben. Dies ist freiwillig. Wenn Sie Ihre Identität nicht preisgeben möchten, können Sie eine E-Mail-Adresse bei einem anonymen Anbieter wie ProtonMail nutzen und diese bei der Meldestelle angeben</p>
                <p><label>E-Mail (optional)<br/><input type="email" name="contact_email" placeholder="anonym@example.org"/></label></div>
            </details>
            <p><label><input type="checkbox" name="confirm" required/> Ich bestätige, dass meine Angaben nach bestem Wissen und Gewissen korrekt sind.</label></p>
            
            <p class="antispam"><label><strong>Sicherheitsfrage:</strong> <?php
                $questions = hinschg_portal_captcha_questions();
                $qid = array_rand($questions);
                echo esc_html($questions[$qid][0]);
            ?><br>
                <input type="text" name="quiz_answer" required placeholder="Antwort eingeben" style="max-width:22ch;">
                <input type="hidden" name="quiz_id" value="<?php echo esc_attr($qid); ?>">
            </label></p>
            <p><button type="submit" name="hinschg_submit">Hinweis absenden</button></p>

        </fieldset>
        <p style="font-size:12px;opacity:.8;">Es werden keine IP-Adressen, Cookies oder Browserdaten gespeichert. Bitte vermeiden Sie personenbezogene Daten, sofern nicht zwingend erforderlich.</p>
    </form>
    <?php
}

function hinschg_portal_handle_front_submission($mandant, $opts) {
    $dl_token = '';
    $dl_url = '';
    $expires = 0;

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], HINSCHG_PORTAL_NONCE_ACTION)) {
        return ['ok' => false, 'msg' => 'Sicherheits-Token ungültig.'];
    }
    $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
    if ($tenant_id !== $mandant->tenant_id) {
        return ['ok' => false, 'msg' => 'Mandant ungültig.'];
    }
    if (empty($_POST['confirm'])) {
        return ['ok' => false, 'msg' => 'Bitte bestätigen Sie die Richtigkeit Ihrer Angaben.'];
    }

    
    // Captcha-Validierung (mehrere Fragen, sessionlos)
    $questions = hinschg_portal_captcha_questions();
    $qid = isset($_POST['quiz_id']) ? intval($_POST['quiz_id']) : -1;
    $qa  = isset($_POST['quiz_answer']) ? hinschg_portal_captcha_normalize(wp_unslash($_POST['quiz_answer'])) : '';
    if (!isset($questions[$qid])) {
        return ['ok'=>false,'msg'=>'Sicherheitsfrage fehlt oder ist ungültig. Bitte erneut versuchen.'];
    }
    $valids_norm = array_map('hinschg_portal_captcha_normalize', $questions[$qid][1]);
    if (!in_array($qa, $valids_norm, true)) {
        return ['ok'=>false,'msg'=>'Sicherheitsfrage wurde nicht korrekt beantwortet. Bitte erneut versuchen.'];
    }
    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $category = sanitize_key($_POST['category'] ?? '');
    if (!isset(hinschg_portal_categories()[$category])) {
        return ['ok' => false, 'msg' => 'Bitte wählen Sie eine gültige Kategorie.'];
    }
    $message = wp_kses_post($_POST['message'] ?? '');
    if (!$message || strlen(trim(wp_strip_all_tags($message))) < 10) {
        return ['ok' => false, 'msg' => 'Bitte hinterlegen Sie eine aussagekräftige Beschreibung (min. 10 Zeichen).'];
    }
    $contact_email = sanitize_email($_POST['contact_email'] ?? '');
    if ($contact_email && !is_email($contact_email)) {
        return ['ok' => false, 'msg' => 'Kontakt-E-Mail ist ungültig.'];
    }

    // Validate the upload before creating the case; do not move it into public uploads.
    $private_upload = null;
    if (!empty($_FILES['attachment']['name'])) {
        $file = $_FILES['attachment'];
        $max_bytes = 10 * 1024 * 1024;
        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK ||
            !is_uploaded_file($file['tmp_name']) || $file['size'] < 1 || $file['size'] > $max_bytes) {
            return ['ok' => false, 'msg' => 'Die Datei konnte nicht hochgeladen werden oder ist zu gross (maximal 10 MB).'];
        }
        $name = sanitize_file_name(wp_basename($file['name']));
        $allowed = ['pdf'=>'application/pdf', 'png'=>'image/png', 'jpg'=>'image/jpeg',
                    'jpeg'=>'image/jpeg', 'txt'=>'text/plain'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        // MIME detection varies by OS and file content. Check signatures for binary formats.
        $head = file_get_contents($file['tmp_name'], false, null, 0, 16);
        $valid = false;
        if ($ext === 'pdf') {
            $valid = is_string($head) && strncmp($head, '%PDF-', 5) === 0;
        } elseif ($ext === 'png') {
            $valid = is_string($head) && strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0;
        } elseif ($ext === 'jpg' || $ext === 'jpeg') {
            $valid = is_string($head) && substr($head, 0, 3) === "\xff\xd8\xff";
        } elseif ($ext === 'txt') {
            $sample = file_get_contents($file['tmp_name'], false, null, 0, 65536);
            $valid = is_string($sample) && strpos($sample, "\0") === false &&
                (function_exists('mb_check_encoding') ? mb_check_encoding($sample, 'UTF-8') : preg_match('//u', $sample));
        }
        if (!$valid) {
            return ['ok' => false, 'msg' => 'Die Datei konnte nicht erkannt werden. Erlaubt sind PDF, PNG, JPG und TXT (maximal 10 MB). Bitte prüfen Sie die Datei und versuchen Sie es erneut.'];
        }
        $private_upload = ['tmp' => $file['tmp_name'], 'name' => $name, 'mime' => $allowed[$ext]];
    }

    // Create token for anonymous follow-up
    $token = wp_generate_password(24, false, false);

    // Create private post
    $post_id = wp_insert_post([
        'post_type' => 'hinweis',
        'post_title' => $subject ? $subject : ('Hinweis ' . current_time('Y-m-d H:i')),
        'post_content' => $message,
        'post_status' => 'private',
    ], true);
    if (is_wp_error($post_id)) {
        return ['ok' => false, 'msg' => 'Speicherfehler: ' . $post_id->get_error_message()];
    }

    add_post_meta($post_id, '_hinschg_tenant_id', $mandant->tenant_id, true);
    add_post_meta($post_id, '_hinschg_token', $token, true);
    add_post_meta($post_id, '_hinschg_status', 'eingegangen', true);
    add_post_meta($post_id, '_hinschg_received_at', current_time('mysql'), true);
    add_post_meta($post_id, '_hinschg_category', $category, true);
    add_post_meta($post_id, '_hinschg_risk', 'ungeprueft', true);
    add_post_meta($post_id, '_hinschg_audit_log', [[
        'event' => 'created',
        'label' => 'Hinweis eingegangen',
        'date' => current_time('mysql'),
        'user_id' => 0,
    ]], true);
    if ($contact_email) { add_post_meta($post_id, '_hinschg_contact_email', $contact_email, true); }
    if ($private_upload) {
        global $wpdb;
        $bytes = file_get_contents($private_upload['tmp']);
        $stored = $bytes !== false && $wpdb->insert($wpdb->prefix . 'hinschg_private_files', [
            'case_id' => $post_id, 'filename' => $private_upload['name'],
            'mime' => $private_upload['mime'], 'content' => $bytes,
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%s','%s']);
        if (!$stored) {
            wp_delete_post($post_id, true);
            return ['ok' => false, 'msg' => 'Die Datei konnte nicht sicher gespeichert werden. Bitte erneut versuchen.'];
        }
    }

    hinschg_portal_add_audit_event($post_id, 'submission_details',
        'Bei Einreichung: Kontakt-E-Mail ' . ($contact_email ? 'angegeben' : 'nicht angegeben') .
        '; Dateianhang ' . ($private_upload ? 'eingereicht' : 'nicht eingereicht'), 0);

    // Keine Meldungsinhalte, Tokens, Kontaktangaben oder Dateilinks per E-Mail.
    wp_mail($mandant->email, '[' . get_bloginfo('name') . '] Neue Meldung',
        "Im geschützten Hinweisgeberportal liegt eine neue Meldung vor. Bitte melden Sie sich über den vereinbarten sicheren Verwaltungszugang an.",
        ['Content-Type: text/plain; charset=UTF-8']);
    return ['ok' => true, 'msg' => 'gespeichert', 'token' => $token];
}

/* ===================== Fallmanagement: Kategorien, Risiko, Postfach, Audit ===================== */

function hinschg_portal_categories() {
    return [
        'korruption' => __('Korruption / Bestechung', 'hinschg-portal'),
        'betrug' => __('Betrug / Vermögensdelikte', 'hinschg-portal'),
        'datenschutz_it' => __('Datenschutz / Informationssicherheit', 'hinschg-portal'),
        'diskriminierung' => __('Diskriminierung / Belästigung', 'hinschg-portal'),
        'arbeitsschutz' => __('Arbeits- / Gesundheitsschutz', 'hinschg-portal'),
        'interessenkonflikt' => __('Interessenkonflikt', 'hinschg-portal'),
        'wettbewerb' => __('Kartell- / Wettbewerbsrecht', 'hinschg-portal'),
        'umwelt' => __('Umwelt / Nachhaltigkeit', 'hinschg-portal'),
        'sonstiges' => __('Sonstiges', 'hinschg-portal'),
    ];
}

function hinschg_portal_get_category_label($key) {
    $categories = hinschg_portal_categories();
    return isset($categories[$key]) ? $categories[$key] : __('Nicht klassifiziert', 'hinschg-portal');
}

function hinschg_portal_risks() {
    return [
        'ungeprueft' => __('Noch nicht bewertet', 'hinschg-portal'),
        'niedrig' => __('Niedrig', 'hinschg-portal'),
        'mittel' => __('Mittel', 'hinschg-portal'),
        'hoch' => __('Hoch', 'hinschg-portal'),
        'kritisch' => __('Kritisch', 'hinschg-portal'),
    ];
}

function hinschg_portal_get_messages($post_id) {
    $messages = get_post_meta($post_id, '_hinschg_messages', true);
    if (!is_array($messages)) { return []; }
    $clean = [];
    foreach ($messages as $message) {
        if (!is_array($message) || empty($message['message'])) { continue; }
        $date = !empty($message['date']) ? $message['date'] : current_time('mysql');
        $clean[] = [
            'sender' => (($message['sender'] ?? '') === 'staff') ? 'staff' : 'reporter',
            'message' => wp_kses_post($message['message']),
            'date' => $date,
            'date_display' => mysql2date('d.m.Y H:i', $date),
            'user_id' => absint($message['user_id'] ?? 0),
        ];
    }
    return $clean;
}

function hinschg_portal_append_message($post_id, $sender, $message, $user_id = 0) {
    $message = trim(sanitize_textarea_field($message));
    if ($message === '') { return false; }
    $messages = get_post_meta($post_id, '_hinschg_messages', true);
    if (!is_array($messages)) { $messages = []; }
    $messages[] = [
        'sender' => $sender === 'staff' ? 'staff' : 'reporter',
        'message' => $message,
        'date' => current_time('mysql'),
        'user_id' => absint($user_id),
    ];
    update_post_meta($post_id, '_hinschg_messages', $messages);
    hinschg_portal_add_audit_event($post_id, $sender === 'staff' ? 'staff_message' : 'reporter_message', $sender === 'staff' ? 'Nachricht der Meldestelle' : 'Nachricht des Hinweisgebers', $user_id);
    return true;
}

function hinschg_portal_add_audit_event($post_id, $event, $label, $user_id = 0) {
    $log = get_post_meta($post_id, '_hinschg_audit_log', true);
    if (!is_array($log)) { $log = []; }
    $log[] = [
        'event' => sanitize_key($event),
        'label' => sanitize_text_field($label),
        'date' => current_time('mysql'),
        'user_id' => absint($user_id),
    ];
    update_post_meta($post_id, '_hinschg_audit_log', $log);
}

function hinschg_portal_handle_reporter_message($mandant, $token) {
    if (!isset($_POST['_hinschg_message_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_hinschg_message_nonce'])), HINSCHG_PORTAL_NONCE_ACTION . '_message')) {
        return 'Sicherheits-Token ungültig. Bitte laden Sie die Seite neu.';
    }
    $posted_tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', wp_unslash($_POST['tenant_id'])) : '';
    if ($posted_tenant_id !== $mandant->tenant_id || !preg_match('/^[A-Za-z0-9]{12,64}$/', $token)) {
        return 'Vorgang konnte nicht verifiziert werden.';
    }
    $result = hinschg_portal_find_report($mandant, $token, false);
    if (empty($result['ok']) || empty($result['post_id'])) { return 'Vorgang konnte nicht gefunden werden.'; }
    $message = isset($_POST['mailbox_message']) ? wp_unslash($_POST['mailbox_message']) : '';
    if (strlen(trim($message)) < 2 || strlen($message) > 5000) { return 'Bitte geben Sie eine Nachricht mit 2 bis 5.000 Zeichen ein.'; }
    if (!hinschg_portal_append_message($result['post_id'], 'reporter', $message, 0)) { return 'Nachricht konnte nicht gespeichert werden.'; }
    wp_mail($mandant->email, '[' . get_bloginfo('name') . '] Neue Postfachnachricht',
        'Im geschützten Hinweisgeberportal liegt eine neue Nachricht vor.',
        ['Content-Type: text/plain; charset=UTF-8']);
    return 'OK:Nachricht wurde anonym an die Meldestelle übermittelt.';
}

function hinschg_portal_handle_print_case() {
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) { wp_die(__('Keine Berechtigung.', 'hinschg-portal')); }
    check_admin_referer('hinschg_print_case_' . $post_id);
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'hinweis') { wp_die(__('Vorgang nicht gefunden.', 'hinschg-portal')); }
    $tid = get_post_meta($post_id, '_hinschg_tenant_id', true);
    $tenant = $tid ? hinschg_portal_get_mandant_by_tenant_id($tid) : null;
    $messages = hinschg_portal_get_messages($post_id);
    $audit = get_post_meta($post_id, '_hinschg_audit_log', true);
    if (!is_array($audit)) { $audit = []; }
    $risk_key = get_post_meta($post_id, '_hinschg_risk', true) ?: 'ungeprueft';
    $risks = hinschg_portal_risks();
    $category = hinschg_portal_get_category_label(get_post_meta($post_id, '_hinschg_category', true));
    $submitted_email = get_post_meta($post_id, '_hinschg_contact_email', true);
    global $wpdb;
    $submitted_files = $wpdb->get_results($wpdb->prepare(
        "SELECT filename, mime, OCTET_LENGTH(content) AS file_size FROM {$wpdb->prefix}hinschg_private_files WHERE case_id = %d ORDER BY id ASC",
        $post_id
    ));
    $legacy_attachment = absint(get_post_meta($post_id, '_hinschg_attachment_id', true));
    nocache_headers();
    header('Content-Type: text/html; charset=' . get_option('blog_charset'));
    ?>
<!doctype html><html><head><meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>"><title>Compliance-Nachweis – <?php echo esc_html(get_the_title($post)); ?></title>
<style>body{font:14px/1.5 system-ui,-apple-system,sans-serif;color:#111;max-width:980px;margin:30px auto;padding:0 24px}h1{font-size:26px;margin-bottom:4px}h2{font-size:18px;border-bottom:1px solid #bbb;padding-bottom:5px;margin-top:28px}.meta{display:grid;grid-template-columns:190px 1fr;gap:5px 14px}.box{border:1px solid #ccc;border-radius:6px;padding:12px;margin:10px 0}.muted{color:#666}.toolbar{position:sticky;top:0;background:#fff;padding:10px 0;border-bottom:1px solid #ddd}.toolbar button{padding:8px 14px}.event{display:grid;grid-template-columns:150px 1fr;gap:12px;border-bottom:1px solid #eee;padding:6px 0}@media print{body{margin:0;max-width:none}.toolbar{display:none}a{color:#111;text-decoration:none}}</style></head><body>
<div class="toolbar"><button onclick="window.print()">Drucken / als PDF speichern</button></div>
<h1>Compliance-Nachweis / Fallakte</h1><p class="muted">Erstellt am <?php echo esc_html(current_time('d.m.Y H:i')); ?> · HinSchG Portal <?php echo esc_html('1.9.9'); ?></p>
<h2>Vorgang</h2><div class="meta">
<div>Vorgangs-ID</div><div><strong><?php echo esc_html(get_post_meta($post_id, '_hinschg_token', true)); ?></strong></div>
<div>Mandant</div><div><?php echo esc_html($tenant ? $tenant->name . ' (' . $tid . ')' : $tid); ?></div>
<div>Eingang</div><div><?php echo esc_html(get_the_date('d.m.Y H:i', $post)); ?></div>
<div>Status</div><div><?php echo esc_html(hinschg_portal_get_status_label($post_id)); ?></div>
<div>Kategorie</div><div><?php echo esc_html($category); ?></div>
<div>Risikoklasse</div><div><?php echo esc_html($risks[$risk_key] ?? $risks['ungeprueft']); ?></div>
</div>
<h2>Angaben bei Einreichung</h2><div class="meta">
<div>Optionale Kontakt-E-Mail</div><div><?php echo $submitted_email ? esc_html($submitted_email) : 'Nicht angegeben'; ?></div>
<div>Dateianhänge</div><div><?php echo $submitted_files ? esc_html(count($submitted_files) . ' Datei(en) eingereicht') : ($legacy_attachment ? 'Älterer Medienanhang vorhanden' : 'Keine Datenbankanhänge vorhanden'); ?></div>
</div>
<?php if ($submitted_files) : ?><div class="box"><strong>Eingereichte Dateien:</strong><ul><?php foreach ($submitted_files as $submitted_file) : ?><li><?php echo esc_html($submitted_file->filename); ?> (<?php echo esc_html($submitted_file->mime); ?>, <?php echo esc_html(size_format((int)$submitted_file->file_size, 1)); ?>)</li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($legacy_attachment) : ?><p class="muted">Älterer Medienanhang (WordPress-Mediathek) wurde im Vorgang hinterlegt.</p><?php endif; ?>
<h2>Sachverhalt</h2><div class="box"><strong><?php echo esc_html(get_the_title($post)); ?></strong><?php echo wp_kses_post(wpautop($post->post_content)); ?></div>
<?php $legacy = get_post_meta($post_id, '_hinschg_public_reply', true); if ($legacy) : ?><h2>Frühere Rückmeldung</h2><div class="box"><?php echo wp_kses_post(wpautop($legacy)); ?></div><?php endif; ?>
<h2>Anonymes Postfach</h2><?php if ($messages) : foreach ($messages as $m) : ?><div class="box"><strong><?php echo esc_html($m['sender'] === 'staff' ? 'Meldestelle' : 'Hinweisgeber'); ?></strong> · <?php echo esc_html($m['date_display']); ?><?php echo wp_kses_post(wpautop($m['message'])); ?></div><?php endforeach; else : ?><p class="muted">Keine Postfach-Nachrichten.</p><?php endif; ?>
<h2>Audit-Verlauf</h2><?php if ($audit) : foreach ($audit as $e) : $u=absint($e['user_id']??0); $user=$u?get_userdata($u):false; ?><div class="event"><div><?php echo esc_html(!empty($e['date'])?mysql2date('d.m.Y H:i',$e['date']):'–'); ?></div><div><strong><?php echo esc_html($e['label']??$e['event']??'Ereignis'); ?></strong><?php if($user): ?> <span class="muted">· <?php echo esc_html($user->display_name); ?></span><?php endif; ?></div></div><?php endforeach; else : ?><p class="muted">Für diesen Altvorgang liegen noch keine Audit-Ereignisse vor.</p><?php endif; ?>
<p class="muted" style="margin-top:35px">Hinweis: Dieser Ausdruck dokumentiert den im Portal gespeicherten Bearbeitungsstand. Er ersetzt keine rechtliche Einzelfallprüfung.</p>
</body></html><?php exit;
}

/* ===================== Datenschutz (Export/Eraser Stubs) ===================== */

function hinschg_portal_register_privacy_exporter($exporters) {
    $exporters['hinschg-portal'] = [
        'exporter_friendly_name' => __('HinSchG Portal', 'hinschg-portal'),
        'callback' => function($email, $page) {
            // Wir speichern keine IPs oder personenbezogenen Daten außer ggf. freiwilliger E-Mail.
            $data = [];
            $query = new WP_Query([
                'post_type' => 'hinweis',
                'post_status' => 'private',
                'posts_per_page' => 100,
                'meta_query' => [
                    [
                        'key' => '_hinschg_contact_email',
                        'value' => $email,
                        'compare' => '=',
                    ]
                ]
            ]);
            foreach ($query->posts as $p) {
                $data[] = [
                    'group_id' => 'hinschg-portal',
                    'group_label' => __('HinSchG Portal', 'hinschg-portal'),
                    'item_id' => 'hinweis-' . $p->ID,
                    'data' => [
                        [ 'name' => 'Post-ID', 'value' => $p->ID ],
                        [ 'name' => 'Betreff', 'value' => get_the_title($p) ],
                        [ 'name' => 'Text', 'value' => wp_strip_all_tags($p->post_content) ],
                        [ 'name' => 'Vorgangs-ID', 'value' => get_post_meta($p->ID, '_hinschg_token', true) ],
                    ]
                ];
            }
            return [ 'data' => $data, 'done' => true ];
        }
    ];
    return $exporters;
}

function hinschg_portal_register_privacy_eraser($erasers) {
    $erasers['hinschg-portal'] = [
        'eraser_friendly_name' => __('HinSchG Portal', 'hinschg-portal'),
        'callback' => function($email, $page) {
            $query = new WP_Query([
                'post_type' => 'hinweis',
                'post_status' => 'private',
                'posts_per_page' => 100,
                'meta_query' => [
                    [ 'key' => '_hinschg_contact_email', 'value' => $email, 'compare' => '=' ]
                ]
            ]);
            $items_removed = false;
            foreach ($query->posts as $p) {
                delete_post_meta($p->ID, '_hinschg_contact_email');
                $items_removed = true;
            }
            return [ 'items_removed' => $items_removed, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
    ];
    return $erasers;
}

function hinschg_portal_statuses() {
    return [
        'eingegangen' => __('Eingegangen', 'hinschg-portal'),
        'in_pruefung' => __('In Prüfung', 'hinschg-portal'),
        'rueckfrage' => __('Rückfrage / Rückmeldung', 'hinschg-portal'),
        'abgeschlossen' => __('Abgeschlossen', 'hinschg-portal'),
        'abgelehnt' => __('Nicht weiterverfolgt', 'hinschg-portal'),
    ];
}

function hinschg_portal_get_status($post_id) {
    $status = get_post_meta($post_id, '_hinschg_status', true);
    $statuses = hinschg_portal_statuses();

    return isset($statuses[$status]) ? $status : 'eingegangen';
}

function hinschg_portal_get_status_label($post_id) {
    $statuses = hinschg_portal_statuses();
    $status = hinschg_portal_get_status($post_id);

    return $statuses[$status];
}

function hinschg_portal_save_report_meta($post_id, $post) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (!isset($_POST['_hinschg_report_meta_nonce'])) {
        return;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['_hinschg_report_meta_nonce']));
    if (!wp_verify_nonce($nonce, 'hinschg_portal_save_report_meta')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!$post || $post->post_type !== 'hinweis') {
        return;
    }

    $statuses = hinschg_portal_statuses();
    $status = isset($_POST['hinschg_status'])
        ? sanitize_key(wp_unslash($_POST['hinschg_status']))
        : 'eingegangen';

    if (!isset($statuses[$status])) {
        $status = 'eingegangen';
    }

    $public_reply = isset($_POST['hinschg_public_reply'])
        ? sanitize_textarea_field(wp_unslash($_POST['hinschg_public_reply']))
        : '';
    $categories = hinschg_portal_categories();
    $category = isset($_POST['hinschg_category']) ? sanitize_key(wp_unslash($_POST['hinschg_category'])) : '';
    if (!isset($categories[$category])) { $category = 'sonstiges'; }
    $risks = hinschg_portal_risks();
    $risk = isset($_POST['hinschg_risk']) ? sanitize_key(wp_unslash($_POST['hinschg_risk'])) : 'ungeprueft';
    if (!isset($risks[$risk])) { $risk = 'ungeprueft'; }
    $old_status = hinschg_portal_get_status($post_id);
    $old_category = get_post_meta($post_id, '_hinschg_category', true);
    $old_risk = get_post_meta($post_id, '_hinschg_risk', true);

    update_post_meta($post_id, '_hinschg_status', $status);
    update_post_meta($post_id, '_hinschg_public_reply', $public_reply);
    update_post_meta($post_id, '_hinschg_category', $category);
    update_post_meta($post_id, '_hinschg_risk', $risk);
    update_post_meta($post_id, '_hinschg_status_updated_at', current_time('mysql'));
    update_post_meta($post_id, '_hinschg_status_updated_by', get_current_user_id());
    if ($old_status !== $status && in_array($status, ['abgeschlossen', 'abgelehnt'], true)) { update_post_meta($post_id, '_hinschg_closed_at', current_time('mysql')); } elseif ($old_status !== $status) { delete_post_meta($post_id, '_hinschg_closed_at'); }
    if ($old_status !== $status) { hinschg_portal_add_audit_event($post_id, 'status_changed', 'Status: ' . hinschg_portal_get_status_label($post_id), get_current_user_id()); }
    if ($old_category !== $category) { hinschg_portal_add_audit_event($post_id, 'category_changed', 'Kategorie: ' . hinschg_portal_get_category_label($category), get_current_user_id()); }
    if ($old_risk !== $risk) { hinschg_portal_add_audit_event($post_id, 'risk_changed', 'Risikoklasse: ' . ($risks[$risk] ?? $risk), get_current_user_id()); }
    $staff_message = isset($_POST['hinschg_staff_message']) ? wp_unslash($_POST['hinschg_staff_message']) : '';
    if (trim($staff_message) !== '') { hinschg_portal_append_message($post_id, 'staff', $staff_message, get_current_user_id()); }
}

/* ===== Admin-UI: Spalten, Metabox & Filter ===== */

function hinschg_portal_admin_columns($columns) {
    $columns_new = [];
    foreach ($columns as $key => $label) {
        $columns_new[$key] = $label;
        if ($key === 'title') {
            $columns_new['tenant'] = __('Mandant', 'hinschg-portal');
            $columns_new['status'] = __('Status', 'hinschg-portal');
            $columns_new['category'] = __('Kategorie', 'hinschg-portal');
            $columns_new['risk'] = __('Risiko', 'hinschg-portal');
            $columns_new['token'] = __('Vorgangs-ID', 'hinschg-portal');
            $columns_new['contact'] = __('Kontakt (freiw.)', 'hinschg-portal');
        }
    }
    return $columns_new;
}

function hinschg_portal_admin_column_content($column, $post_id) {
    if ($column === 'tenant') {
        $tid = get_post_meta($post_id, '_hinschg_tenant_id', true);
        if ($tid) {
            $m = hinschg_portal_get_mandant_by_tenant_id($tid);
            echo $m ? esc_html($m->name . ' (' . $m->tenant_id . ')') : esc_html($tid);
        } else {
            echo '–';
        }
    } elseif ($column === 'status') {
        echo esc_html(hinschg_portal_get_status_label($post_id));
    } elseif ($column === 'category') {
        echo esc_html(hinschg_portal_get_category_label(get_post_meta($post_id, '_hinschg_category', true)));
    } elseif ($column === 'risk') {
        $risks = hinschg_portal_risks(); $risk = get_post_meta($post_id, '_hinschg_risk', true) ?: 'ungeprueft'; echo esc_html($risks[$risk] ?? $risk);
    } elseif ($column === 'token') {
        echo esc_html(get_post_meta($post_id, '_hinschg_token', true) ?: '–');
    } elseif ($column === 'contact') {
        echo esc_html(get_post_meta($post_id, '_hinschg_contact_email', true) ?: '–');
    }
}

function hinschg_portal_register_meta_boxes() {
    add_meta_box(
        'hinschg_meta',
        __('Hinweis-Details', 'hinschg-portal'),
        'hinschg_portal_render_report_meta_box',
        'hinweis',
        'side',
        'high'
    );

    add_meta_box(
        'hinschg_status',
        __('Bearbeitungsstatus und Rückmeldung', 'hinschg-portal'),
        'hinschg_portal_render_status_meta_box',
        'hinweis',
        'normal',
        'high'
    );
}

function hinschg_portal_render_report_meta_box($post) {
    $tid = get_post_meta($post->ID, '_hinschg_tenant_id', true);
    $token = get_post_meta($post->ID, '_hinschg_token', true);
    $contact = get_post_meta($post->ID, '_hinschg_contact_email', true);
    $att_id = (int) get_post_meta($post->ID, '_hinschg_attachment_id', true);

    echo '<p><strong>Mandant:</strong> ' . esc_html($tid ?: '–');
    if ($tid) {
        $mandant = hinschg_portal_get_mandant_by_tenant_id($tid);
        if ($mandant) {
            echo ' — ' . esc_html($mandant->name);
        }
    }
    echo '</p>';
    echo '<p><strong>Vorgangs-ID:</strong> <code>' . esc_html($token ?: '–') . '</code></p>';
    echo '<p><strong>Kontakt (freiwillig):</strong> ' . esc_html($contact ?: '–') . '</p>';
    $print_url = wp_nonce_url(add_query_arg(['action' => 'hinschg_print_case', 'post' => $post->ID], admin_url('admin-post.php')), 'hinschg_print_case_' . $post->ID);
    echo '<p><a class="button button-primary" href="' . esc_url($print_url) . '" target="_blank" rel="noopener">Compliance-Nachweis drucken / PDF</a></p>';

    global $wpdb;
    $private_file = $wpdb->get_row($wpdb->prepare(
        "SELECT id, filename FROM {$wpdb->prefix}hinschg_private_files WHERE case_id = %d LIMIT 1", $post->ID));
    if ($private_file && current_user_can('edit_post', $post->ID)) {
        $url = wp_nonce_url(add_query_arg(['action'=>'hinschg_private_file', 'post'=>$post->ID,
            'file'=>$private_file->id], admin_url('admin-post.php')), 'hinschg_private_file_'.$post->ID.'_'.$private_file->id);
        echo '<p><strong>Anhang:</strong> <a href="'.esc_url($url).'">'.esc_html($private_file->filename).' herunterladen</a></p>';
    }
    if ($att_id) {
        $url = wp_get_attachment_url($att_id);
        if ($url) {
            echo '<p><strong>Anhang:</strong> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">Download</a></p>';
        }
    }
}

function hinschg_portal_render_status_meta_box($post) {
    $statuses = hinschg_portal_statuses();
    $current_status = hinschg_portal_get_status($post->ID);
    $public_reply = get_post_meta($post->ID, '_hinschg_public_reply', true);
    $updated_at = get_post_meta($post->ID, '_hinschg_status_updated_at', true);
    $updated_by = (int) get_post_meta($post->ID, '_hinschg_status_updated_by', true);
    $current_category = get_post_meta($post->ID, '_hinschg_category', true) ?: 'sonstiges';
    $current_risk = get_post_meta($post->ID, '_hinschg_risk', true) ?: 'ungeprueft';
    $messages = hinschg_portal_get_messages($post->ID);

    wp_nonce_field('hinschg_portal_save_report_meta', '_hinschg_report_meta_nonce');

    echo '<p><label for="hinschg_status"><strong>Status</strong></label></p>';
    echo '<select id="hinschg_status" name="hinschg_status" style="min-width:260px">';
    foreach ($statuses as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($current_status, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px">';
    echo '<div><p><label for="hinschg_category"><strong>Kategorie</strong></label></p><select id="hinschg_category" name="hinschg_category">';
    foreach (hinschg_portal_categories() as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($current_category, $value, false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></div>';
    echo '<div><p><label for="hinschg_risk"><strong>Risikoklassifikation</strong></label></p><select id="hinschg_risk" name="hinschg_risk">';
    foreach (hinschg_portal_risks() as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($current_risk, $value, false) . '>' . esc_html($label) . '</option>'; }
    echo '</select></div></div>';

    echo '<p><label for="hinschg_public_reply"><strong>Bestehende öffentliche Rückmeldung (Legacy)</strong></label></p>';
    echo '<textarea id="hinschg_public_reply" name="hinschg_public_reply" rows="7" class="widefat" placeholder="Optional: Rückmeldung, Rückfrage oder Abschlussinformation für den Hinweisgeber">' . esc_textarea($public_reply) . '</textarea>';
    echo '<p class="description">Dieses Feld bleibt für bestehende Fälle erhalten. Für neue Kommunikation bitte das anonyme Postfach darunter verwenden.</p>';

    echo '<hr><h3>Anonymes Zwei-Wege-Postfach</h3>';
    if ($messages) {
        foreach ($messages as $m) {
            echo '<div style="padding:10px 12px;margin:8px 0;background:' . ($m['sender'] === 'staff' ? '#f0f6fc' : '#f6f7f7') . ';border-left:4px solid ' . ($m['sender'] === 'staff' ? '#2271b1' : '#8c8f94') . '">';
            echo '<strong>' . esc_html($m['sender'] === 'staff' ? 'Meldestelle' : 'Hinweisgeber') . '</strong> · ' . esc_html($m['date_display']);
            echo wp_kses_post(wpautop($m['message'])); echo '</div>';
        }
    } else { echo '<p class="description">Noch keine Nachrichten.</p>'; }
    echo '<p><label for="hinschg_staff_message"><strong>Neue Nachricht an den Hinweisgeber</strong></label></p>';
    echo '<textarea id="hinschg_staff_message" name="hinschg_staff_message" rows="5" class="widefat" maxlength="5000" placeholder="Nachricht oder Rückfrage eingeben. Wird beim Aktualisieren gesendet."></textarea>';
    echo '<p class="description">Die Nachricht erscheint nach dem Speichern in der anonymen Vorgangsabfrage.</p>';

    if ($updated_at) {
        $user = $updated_by ? get_userdata($updated_by) : false;
        $user_name = $user ? $user->display_name : __('unbekannt', 'hinschg-portal');
        echo '<p class="description">Zuletzt geändert: ' . esc_html(mysql2date('d.m.Y H:i', $updated_at)) . ' durch ' . esc_html($user_name) . '</p>';
    }
}

function hinschg_portal_add_tenant_filter() {
    global $typenow;
    if ($typenow !== 'hinweis') { return; }
    $current = isset($_GET['tenant_id']) ? preg_replace('/[^0-9]/','', $_GET['tenant_id']) : '';
    echo '<input type="search" name="tenant_id" value="' . esc_attr($current) . '" placeholder="Mandant-ID (8-stellig)" />';
}

function hinschg_portal_apply_tenant_filter($query) {
    if (!is_admin() || !$query->is_main_query()) { return; }
    if ($query->get('post_type') !== 'hinweis') { return; }
    if (!empty($_GET['tenant_id']) && preg_match('/^\d{8}$/', $_GET['tenant_id'])) {
        $query->set('meta_query', [ [ 'key' => '_hinschg_tenant_id', 'value' => sanitize_text_field($_GET['tenant_id']) ] ]);
    }
}

function hinschg_portal_private_file_download() {
    $post_id = absint($_GET['post'] ?? 0);
    $file_id = absint($_GET['file'] ?? 0);
    if (!$post_id || !$file_id || get_post_type($post_id) !== 'hinweis' ||
        !current_user_can('edit_post', $post_id)) { status_header(403); exit('Kein Zugriff'); }
    check_admin_referer('hinschg_private_file_'.$post_id.'_'.$file_id);
    global $wpdb;
    $file = $wpdb->get_row($wpdb->prepare(
        "SELECT filename,mime,content FROM {$wpdb->prefix}hinschg_private_files WHERE id = %d AND case_id = %d",
        $file_id, $post_id));
    if (!$file) { status_header(404); exit('Datei nicht gefunden'); }
    nocache_headers();
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"], '', $file->filename).'"');
    header('Content-Length: '.strlen($file->content));
    echo $file->content;
    exit;
}
add_action('admin_post_hinschg_private_file', 'hinschg_portal_private_file_download');

function hinschg_portal_handle_download() {
    if (!is_user_logged_in()) { status_header(403); exit('Forbidden'); }
    $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    $tid     = isset($_GET['tid']) ? preg_replace('/[^0-9]/','', $_GET['tid']) : '';
    $token   = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

    if (!$post_id || !$tid || !$token) { status_header(403); exit('Forbidden'); }

    $p = get_post($post_id);
    if (!$p || $p->post_type !== 'hinweis' || !current_user_can('edit_post', $post_id)) { status_header(403); exit('Forbidden'); }

    $saved_tid   = get_post_meta($p->ID, '_hinschg_tenant_id', true);
    $saved_token = get_post_meta($p->ID, '_hinschg_dl_token', true);
    $expires     = intval(get_post_meta($p->ID, '_hinschg_dl_expires', true));
    $att_id      = intval(get_post_meta($p->ID, '_hinschg_attachment_id', true));

    if (!$att_id || !$saved_token || !$expires || time() > $expires) { status_header(410); exit('Link abgelaufen'); }
    if (!hash_equals($saved_tid, $tid) || !hash_equals($saved_token, $token)) { status_header(403); exit('Invalid token'); }

    $file = get_attached_file($att_id);
    if (!$file || !file_exists($file)) { status_header(404); exit('File missing'); }

    $mime = get_post_mime_type($att_id) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}


// ===================== Admin: Rechtliches / Haftung =====================

function hinschg_portal_register_legal_page() {
    add_submenu_page(
        'hinschg-portal',                // Parent-Slug
        'Rechtliches & Haftung',        // Seitentitel
        'Rechtliches',                  // Menütext
        'manage_options',               // Berechtigung
        'hinschg_portal_legal',         // Slug
        'hinschg_portal_render_legal_page'    // Callback
    );
}


	// ----------- Rechtshinweise für Denksport, Anwendungen, auch für Compliance Tools -----------------

function hinschg_portal_public_information() {
    return '<details><summary>Informationen zum Meldeverfahren und Datenschutz</summary><p>Sie können Hinweise ohne Angabe Ihres Namens übermitteln und ueber Ihr anonymes Postfach Rückfragen beantworten. Bewahren Sie Ihre Zugangskennung sicher auf. Eine Eingangsbestätigung soll innerhalb von sieben Tagen erfolgen; eine Rückmeldung grundsätzlich innerhalb von drei Monaten nach Eingangsbestätigung.</p><p>Sie können sich auch an eine zuständige externe Meldestelle wenden, etwa das <a href="https://www.bundesjustizamt.de/DE/MeldestelledesBundes/MeldestelledesBundes_node.html" rel="noopener noreferrer" target="_blank">Bundesamt für Justiz</a>. Informationen zur verantwortlichen Stelle und den Datenschutzrechten müssen vom Betreiber ergänzt werden.</p></details>';
}

function hinschg_portal_hs_show_disclaimer() {
		return '<details class="noprint"><summary>📜 Rechtliche Hinweise & Nutzungsbedingungen</summary>
		<div>
		<p><b>Test-, Demo- und Eigeninstallationen:</b><br>
		Die bereitgestellten Anwendungen, Tools, Spiele, Vorlagen und Module dienen ausschließlich Informations-, Demonstrations-, Lern-, Test- und Präsentationszwecken. Bitte geben Sie keine vertraulichen, personenbezogenen oder produktiven Unternehmensdaten ein. Eingegebene Inhalte können zu Demonstrations-, Entwicklungs-, Test- oder Fehleranalysezwecken verarbeitet und gespeichert werden. Es besteht kein Anspruch auf Vertraulichkeit, dauerhafte Speicherung oder Verfügbarkeit der eingegebenen Daten.</p>
		<p>Für den produktiven Einsatz wird eine Installation innerhalb der eigenen geschützten IT-, Cloud- oder Intranet-Umgebung empfohlen. Sofern Quellcode, Installationspakete oder Softwarekomponenten bereitgestellt werden, erfolgen Installation, Konfiguration, Anpassung, Updates und Betrieb ausschließlich in eigener Verantwortung des Nutzers. Der produktive Einsatz erfolgt auf eigenes Risiko.</p>
		<p><b>Technische Softwarelösung:</b><br>
		Die Software wird ausschließlich als technische Softwarelösung bereitgestellt. Der Anbieter betreibt keine Cloud-Plattform, keine SaaS-Lösung und keine Managed Services. Sämtliche Module – einschließlich des Hinweisgeber-Portals – werden ausschließlich innerhalb der IT-Infrastruktur des Nutzers betrieben.</p>
		<p>Der Nutzer ist insbesondere verantwortlich für Installation, Konfiguration, Betrieb, Datensicherung, Benutzer- und Berechtigungsverwaltung, Verfügbarkeit, IT-Sicherheit, Datenschutzkonfigurationen, Updates sowie die Einhaltung gesetzlicher und regulatorischer Anforderungen.</p>
		<p>Die Software unterstützt organisatorische, dokumentationsbezogene und administrative Prozesse. Sie ersetzt insbesondere keine Rechts-, Datenschutz-, Compliance-, Steuer-, Unternehmens- oder sonstige Fachberatung, keine Auditierung, Zertifizierung oder sonstige fachliche Prüfung. Dies gilt ausdrücklich auch für bereitgestellte Vorlagen, Muster, Checklisten, Dokumente, Beispielprüfungen, Quizfragen sowie automatisch erzeugte Inhalte.</p>
		<p><b>KI-Hinweis:</b><br>
		Inhalte, Vorlagen und Anwendungen können ganz oder teilweise unter Einsatz Künstlicher Intelligenz erstellt worden sein. Trotz sorgfältiger Prüfung können Fehler, Ungenauigkeiten, unvollständige Ergebnisse oder fehlerhafte Schlussfolgerungen auftreten.</p>
		<ul>
		<li>Automatisch erzeugte Dokumente, Listen, Vorlagen, Berichte, Prüfungen, Analysen, Auswertungen, Zuordnungen, Kontaktdaten oder Klassifizierungen können unvollständig, fehlerhaft oder veraltet sein.</li>
		<li>Alle erzeugten Ergebnisse sind vor einer produktiven Verwendung eigenverantwortlich auf Richtigkeit, Vollständigkeit, Aktualität, rechtliche Zulässigkeit und Eignung für den jeweiligen Einsatzzweck zu prüfen.</li>
		<li>Der Anbieter übernimmt keine Verantwortung für Entscheidungen, Datenverarbeitungen oder Maßnahmen, die auf Grundlage bereitgestellter oder erzeugter Inhalte getroffen werden.</li>
		<li>Es wird keine Gewähr für Richtigkeit, Vollständigkeit, Aktualität, Verfügbarkeit, Funktionsfähigkeit, Kompatibilität, rechtliche Verwendbarkeit oder Eignung für einen bestimmten Zweck übernommen.</li>
		</ul>
		<p><b>Hinweisgeber-Portal:</b><br>
		Das Hinweisgeber-Portal stellt ausschließlich eine technische Plattform zur Entgegennahme und Verwaltung von Meldungen bereit. Der Anbieter übernimmt keine Funktion als interne Meldestelle, Ombudsperson, Compliance-Stelle, Ermittlungsstelle oder Rechtsberater.</p>
		<p><b>Open Source & Lizenzen:</b><br>
		Einzelne Plugins oder Softwarebestandteile können ganz oder teilweise auf Open-Source-Projekten basieren oder Open-Source-Komponenten enthalten. Für diese Bestandteile gelten die jeweiligen Original-Lizenzbedingungen. Ergänzende Hinweise ergeben sich aus den jeweiligen README-, LICENSE- oder sonstigen Dokumentationsdateien der Plugins.</p>
		<p><b>Markenhinweis:</b><br>
		Alle genannten oder erkennbaren Marken, Produktnamen, Logos, Spielprinzipien oder Konzepte bleiben Eigentum ihrer jeweiligen Rechteinhaber. Dieses Angebot steht – soweit nicht ausdrücklich angegeben – in keiner Verbindung zu den jeweiligen Markeninhabern oder Rechteinhabern.</p>
		<p><b>Haftung:</b><br>
		Die Bereitstellung erfolgt ausdrücklich &bdquo;AS IS&ldquo; (&bdquo;wie besehen&ldquo;).  Der Anbieter übernimmt keine Gewähr für Fehlerfreiheit, unterbrechungsfreien Betrieb oder die Einhaltung gesetzlicher und regulatorischer Anforderungen. Die Haftung ist auf Vorsatz und grobe Fahrlässigkeit beschränkt. Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten (Kardinalpflichten) ist die Haftung auf den vertragstypischen, vorhersehbaren Schaden begrenzt. Die Haftung wegen Verletzung von Leben, Körper oder Gesundheit sowie nach zwingenden gesetzlichen Vorschriften bleibt unberührt.</p>
		<p><a target="_blank" href="'.esc_url( home_url( '/impressum/' ) ).'">Ausführliche rechtliche Hinweise, Lizenz- und Nutzungsbedingungen</a></p>
		</div></details>';
	}

function hinschg_portal_render_legal_page() {
	?>
    <div class="wrap">
        <h1>Rechtliches &amp; Haftung</h1>
        <h2>Hintergrund des Hinweisgeberschutzgesetzes (HinSchG)</h2>
        <p>
            Das Hinweisgeberschutzgesetz (HinSchG) dient der Umsetzung der EU-Richtlinie (EU) 2019/1937 über den Schutz von Personen,
            die Verstöße gegen das Unionsrecht melden. Ziel des Gesetzes ist es, Hinweisgeber – also Personen, die Missstände,
            Gesetzesverstöße oder unethisches Verhalten in Unternehmen oder Behörden melden – vor Benachteiligungen zu schützen.
        </p>
        <ul style="list-style-type: disc; margin-left: 2em;">
            <li>Pflicht zur Einrichtung eines vertraulichen Meldewegs</li>
            <li>Schutz der Identität von Hinweisgebern und betroffenen Personen</li>
            <li>Bearbeitung und Rückmeldung innerhalb gesetzlicher Fristen</li>
            <li>Möglichkeit zur anonymen Hinweisabgabe</li>
            <li>Sanktionen bei Behinderung oder Repressalien gegen Hinweisgeber</li>
        </ul>
		<?php 	echo hinschg_portal_hs_show_disclaimer(); ?>
    </div>
    <?php
}


// === Captcha (mehrere Fragen, sessionlos, ohne externe Dienste) ===

function hinschg_portal_captcha_questions() {
    return [
        ['Welche Farbe hat der Himmel an einem wolkenlosen Tag?', ['blau','blue']],
        ['Wieviel ist zwei plus drei?', ['5','fuenf','fünf','funf']],
        ['Welcher Wochentag folgt auf Montag?', ['dienstag','tuesday']],
        ['Schreibe das Wort "Mensch" rückwärts.', ['hcsnem']],
        ['Welche Farbe hat eine reife Banane?', ['gelb','yellow']],
    ];
}

function hinschg_portal_captcha_normalize($s) {
    $s = strtolower(trim((string)$s));
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/\s+/', ' ', $s);
}


/* Ansprechpartner erhalten nur Fallrechte; keine WordPress-Administration. */
function hinschg_register_contact_role() {
    // Bestehende Rollen bei Updates synchronisieren; keine allgemeinen Beitragsrechte.
    $role = get_role('hinschg_ansprechpartner');
    if (!$role) $role = add_role('hinschg_ansprechpartner', 'HinSchG-Ansprechpartner', ['read' => true]);
    if (!$role) return;
    foreach (['edit_posts','edit_others_posts','read_private_posts','edit_published_posts','edit_private_posts','delete_posts','delete_others_posts','publish_posts'] as $cap) {
        $role->remove_cap($cap);
    }
    foreach (['read','edit_hinweise','edit_others_hinweise','edit_private_hinweise','edit_published_hinweise','read_private_hinweise'] as $cap) {
        $role->add_cap($cap);
    }
}
add_action('init', 'hinschg_register_contact_role');
function hinschg_admin_case_caps() {
    $role = get_role('administrator');
    if (!$role) return;
    foreach (['edit_hinweise','edit_others_hinweise','edit_private_hinweise','edit_published_hinweise','read_private_hinweise','delete_hinweise','delete_others_hinweise','delete_private_hinweise','delete_published_hinweise','publish_hinweise'] as $cap) $role->add_cap($cap);
}
add_action('init', 'hinschg_admin_case_caps');
/* Administratoren duerfen HinSchG-Vorgaenge auch nach Rollen-/Pluginupdates verwalten.
   Nur die spezifischen Fall-Capabilities werden ergaenzt. */
function hinschg_admin_primitive_caps($allcaps, $caps, $args, $user) {
    if (!in_array('administrator', (array) $user->roles, true) || empty($allcaps['manage_options'])) return $allcaps;
    foreach (['edit_hinweise','edit_others_hinweise','edit_private_hinweise',
        'edit_published_hinweise','read_private_hinweise','delete_hinweise',
        'delete_others_hinweise','delete_private_hinweise',
        'delete_published_hinweise','publish_hinweise'] as $cap) {
        $allcaps[$cap] = true;
    }
    return $allcaps;
}
add_filter('user_has_cap', 'hinschg_admin_primitive_caps', 20, 4);
/* Mandantenbezogene Fallberechtigungen. Zuweisung nur durch WordPress-Administratoren. */
function hinschg_allowed_tenants($user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    $ids = get_user_meta($user_id, '_hinschg_allowed_tenants', true);
    return is_array($ids) ? array_values(array_filter($ids, function($id) { return is_string($id) && preg_match('/^\d{8}$/', $id); })) : [];
}
function hinschg_can_access_case($post_id, $user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id || get_post_type($post_id) !== 'hinweis') return false;
    // WordPress-Administratoren behalten den Zugriff auf alle Mandanten.
    if (user_can($user_id, 'manage_options')) return true;
    $tid = (string) get_post_meta($post_id, '_hinschg_tenant_id', true);
    return $tid !== '' && in_array($tid, hinschg_allowed_tenants($user_id), true);
}
function hinschg_case_caps($caps, $cap, $user_id, $args) {
    if (!in_array($cap, ['read_post','edit_post','delete_post'], true) || empty($args[0])) return $caps;
    $id = (int) $args[0];
    if (get_post_type($id) === 'hinweis') {
        if (user_can($user_id, 'manage_options')) return ['exist'];
        if (!hinschg_can_access_case($id, $user_id)) return ['do_not_allow'];
        if (in_array('hinschg_ansprechpartner', (array) get_userdata($user_id)->roles, true) && $cap === 'delete_post') return ['do_not_allow'];
    }
    return $caps;
}
add_filter('map_meta_cap', 'hinschg_case_caps', 20, 4);
function hinschg_scope_case_query($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'hinweis') return;
    if (current_user_can('manage_options')) return;
    $ids = hinschg_allowed_tenants();
    $meta = (array) $query->get('meta_query');
    $meta[] = ['key'=>'_hinschg_tenant_id','value'=>$ids ?: ['__none__'],'compare'=>'IN'];
    $query->set('meta_query', $meta);
}
add_action('pre_get_posts','hinschg_scope_case_query', 99);
/* Mandanten direkt im WordPress-Benutzerprofil zuweisen. */
function hinschg_user_tenants_fields($user) {
    if (!current_user_can('manage_options')) return;
    $selected = hinschg_allowed_tenants($user->ID);
    echo '<h2>HinSchG – Mandantenzuordnung</h2>';
    echo '<p>Nur für die Rolle HinSchG-Ansprechpartner erforderlich. WordPress-Administratoren haben immer Zugriff auf alle Mandanten.</p>';
    wp_nonce_field('hinschg_user_tenants_save', 'hinschg_user_tenants_nonce');
    echo '<table class="form-table"><tr><th>Mandanten auswählen</th><td>';
    $tenants = hinschg_portal_get_mandanten();
    if (!$tenants) echo '<p>Es sind noch keine Mandanten angelegt.</p>';
    foreach ($tenants as $tenant) {
        $id = (string) $tenant->tenant_id;
        echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="hinschg_user_tenants[]" value="'.esc_attr($id).'" '.checked(in_array($id,$selected,true),true,false).'> '.esc_html($tenant->name).' ('.esc_html($id).')</label>';
    }
    echo '<p class="description">Mehrere Mandanten sind möglich. Ohne Auswahl sieht der Ansprechpartner keine Fälle.</p></td></tr></table>';
}
add_action('show_user_profile','hinschg_user_tenants_fields');
add_action('edit_user_profile','hinschg_user_tenants_fields');
function hinschg_save_user_tenants($user_id) {
    if (!current_user_can('manage_options') || !current_user_can('edit_user',$user_id)) return;
    if (empty($_POST['hinschg_user_tenants_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hinschg_user_tenants_nonce'])),'hinschg_user_tenants_save')) return;
    $raw = isset($_POST['hinschg_user_tenants']) && is_array($_POST['hinschg_user_tenants']) ? wp_unslash($_POST['hinschg_user_tenants']) : [];
    $valid = array_map(static function($m){ return (string)$m->tenant_id; }, hinschg_portal_get_mandanten());
    $ids = array_values(array_unique(array_intersect(array_filter(array_map('sanitize_text_field',$raw),static function($id){return preg_match('/^\d{8}$/',$id);}),$valid)));
    update_user_meta($user_id,'_hinschg_allowed_tenants',$ids);
}
add_action('personal_options_update','hinschg_save_user_tenants');
add_action('edit_user_profile_update','hinschg_save_user_tenants');
/* Beim Anlegen eines Benutzers: Mandantenauswahl direkt im Formular. */
function hinschg_new_user_tenants_fields() {
    if (!current_user_can('manage_options')) return;
    wp_nonce_field('hinschg_new_user_tenants','hinschg_new_user_tenants_nonce');
    echo '<h2>HinSchG – Mandanten (für Ansprechpartner)</h2><table class="form-table"><tr><th>Mandanten</th><td>';
    foreach (hinschg_portal_get_mandanten() as $tenant) {
        echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="hinschg_new_user_tenants[]" value="'.esc_attr($tenant->tenant_id).'"> '.esc_html($tenant->name).' ('.esc_html($tenant->tenant_id).')</label>';
    }
    echo '<p class="description">Optional: mehrere auswählen. WordPress-Administratoren brauchen keine Zuordnung.</p></td></tr></table>';
}
add_action('user_new_form','hinschg_new_user_tenants_fields');
add_action('admin_footer-user-new.php', 'hinschg_preselect_contact_role');
function hinschg_preselect_contact_role() {
    if (!current_user_can('create_users') || !current_user_can('manage_options')) return;
    if (!isset($_GET['role']) || sanitize_key(wp_unslash($_GET['role'])) !== 'hinschg_ansprechpartner') return;
    if (!get_role('hinschg_ansprechpartner')) return;
    // Nur bei explizitem Aufruf des Ansprechpartner-Links vorauswaehlen.
    ?>
    <script>
    (function () {
        var role = document.getElementById('role');
        if (role && role.querySelector('option[value="hinschg_ansprechpartner"]')) {
            role.value = 'hinschg_ansprechpartner';
            role.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }());
    </script>
    <?php
}

function hinschg_save_new_user_tenants($user_id) {
    if (!current_user_can('manage_options') || empty($_POST['hinschg_new_user_tenants_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hinschg_new_user_tenants_nonce'])),'hinschg_new_user_tenants')) return;
    $raw = isset($_POST['hinschg_new_user_tenants']) && is_array($_POST['hinschg_new_user_tenants']) ? wp_unslash($_POST['hinschg_new_user_tenants']) : [];
    $valid = array_map(static function($m){return (string)$m->tenant_id;},hinschg_portal_get_mandanten());
    $ids = array_values(array_unique(array_intersect(array_filter(array_map('sanitize_text_field',$raw),static function($id){return preg_match('/^\d{8}$/',$id);}),$valid)));
    update_user_meta($user_id,'_hinschg_allowed_tenants',$ids);
}
add_action('user_register','hinschg_save_new_user_tenants',20);
add_action('admin_menu', function() {
    if (current_user_can('manage_options') || !current_user_can('edit_hinweise')) return;
    $u = wp_get_current_user();
    if (in_array('hinschg_ansprechpartner', (array) $u->roles, true)) {
        // Dashboard und Vorgangsliste fuer zugewiesene Mandanten.
        add_menu_page('HinSchG Hinweise', 'HinSchG Hinweise', 'edit_hinweise', 'hinschg-cases', 'hinschg_portal_render_cockpit_page', 'dashicons-shield', 26);
        add_submenu_page('hinschg-cases', 'Cockpit', 'Cockpit', 'edit_hinweise', 'hinschg-cases', 'hinschg_portal_render_cockpit_page');
        add_submenu_page('hinschg-cases', 'Hinweise', 'Hinweise', 'edit_hinweise', 'edit.php?post_type=hinweis');
    }
}, 40);

/* Das Ansprechpartner-Dashboard verwendet ausschliesslich erlaubte Mandanten.
   Keine mandantenuebergreifenden SQL-Statistiken. */
function hinschg_contact_dashboard_page() {
    if (!hinschg_is_contact_only() || !current_user_can('edit_hinweise')) {
        wp_die(esc_html__('Keine Berechtigung.', 'hinschg-portal'), '', ['response' => 403]);
    }
    $tenants = hinschg_allowed_tenants();
    echo '<div class="wrap"><h1>HinSchG Dashboard</h1>';
    if (!$tenants) {
        echo '<p>Es sind noch keine Mandanten zugewiesen. Bitte einen Administrator kontaktieren.</p></div>';
        return;
    }
    $query = new WP_Query([
        'post_type' => 'hinweis', 'post_status' => 'private',
        'posts_per_page' => -1, 'fields' => 'ids',
        'meta_query' => [[ 'key' => '_hinschg_tenant_id', 'value' => $tenants, 'compare' => 'IN' ]],
    ]);
    $counts = ['gesamt' => 0, 'offen' => 0, 'abgeschlossen' => 0];
    foreach ($query->posts as $id) {
        if (!hinschg_can_access_case($id)) continue;
        $counts['gesamt']++;
        $status = (string) get_post_meta($id, '_hinschg_status', true);
        if (in_array($status, ['abgeschlossen', 'abgelehnt'], true)) $counts['abgeschlossen']++;
        else $counts['offen']++;
    }
    echo '<p>Übersicht über die Ihnen zugewiesenen Mandanten.</p>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    foreach (['gesamt' => 'Hinweise gesamt', 'offen' => 'Offene Vorgänge', 'abgeschlossen' => 'Abgeschlossene Vorgänge'] as $key => $label) {
        echo '<div class="postbox" style="padding:18px;min-width:160px"><strong>' . esc_html($label) . '</strong><p style="font-size:28px;margin:8px 0">' . esc_html(number_format_i18n($counts[$key])) . '</p></div>';
    }
    echo '</div><p><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=hinweis')) . '">Meine Hinweise anzeigen</a></p></div>';
}
/* Schlankes Backend fuer Ansprechpartner, ohne Admins einzuschraenken. */
function hinschg_is_contact_only() {
    if (!is_user_logged_in() || current_user_can('manage_options')) return false;
    return in_array('hinschg_ansprechpartner', (array) wp_get_current_user()->roles, true);
}
function hinschg_contact_backend_menu() {
    if (!hinschg_is_contact_only()) return;
    global $menu;
    // Nur die Vorgangsliste anzeigen; Profil bleibt ueber den Benutzer-Link erreichbar.
    foreach ((array) $menu as $item) {
        $slug = isset($item[2]) ? (string) $item[2] : '';
        if ($slug !== 'hinschg-cases') remove_menu_page($slug);
    }
}
add_action('admin_menu', 'hinschg_contact_backend_menu', 999);
function hinschg_contact_backend_guard() {
    if (!hinschg_is_contact_only() || wp_doing_ajax()) return; // AJAX requires endpoint-specific permission checks.
    global $pagenow;
    $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $allowed = false;
    if ($pagenow === 'edit.php' && $type === 'hinweis' && $page === '') $allowed = true;
    if ($pagenow === 'post.php') {
        $id = absint($_GET['post'] ?? $_POST['post_ID'] ?? 0);
        $allowed = $id && hinschg_can_access_case($id);
    }
    if ($pagenow === 'admin.php' && $page === 'hinschg-cases') $allowed = true;
    if ($pagenow === 'profile.php') $allowed = true;
    if ($allowed) return;
    if ($pagenow === 'index.php' || $pagenow === 'admin.php' && $page === '') {
        wp_safe_redirect(admin_url('admin.php?page=hinschg-cases')); exit;
    }
    wp_die(esc_html__('Dieser Bereich ist fuer HinSchG-Ansprechpartner nicht freigegeben.', 'hinschg-portal'), '', ['response'=>403]);
}
add_action('admin_init', 'hinschg_contact_backend_guard', 100);
/* WooCommerce may override the login destination and block non-shop roles from wp-admin.
   Only the dedicated HinSchG role is exempted; other customers are unchanged. */
function hinschg_contact_user($user) {
    return $user instanceof WP_User && !user_can($user, 'manage_options')
        && in_array('hinschg_ansprechpartner', (array) $user->roles, true);
}
add_filter('login_redirect', function($redirect, $requested, $user) {
    return hinschg_contact_user($user) ? admin_url('admin.php?page=hinschg-cases') : $redirect;
}, PHP_INT_MAX, 3);
add_filter('woocommerce_login_redirect', function($redirect, $user = null) {
    if (!$user instanceof WP_User) $user = wp_get_current_user();
    return hinschg_contact_user($user) ? admin_url('admin.php?page=hinschg-cases') : $redirect;
}, PHP_INT_MAX, 2);
add_filter('woocommerce_prevent_admin_access', function($prevent) {
    return hinschg_is_contact_only() ? false : $prevent;
}, PHP_INT_MAX);
/* WordPress can be configured to redirect these users via other login flows.
   The backend guard below continues to restrict which admin screens they may use. */
add_filter('show_admin_bar', function($show) { return hinschg_is_contact_only() ? false : $show; });

/* Plugin-Hooks kontextabhängig registrieren. */
register_activation_hook(__FILE__, 'hinschg_portal_activate');

if (is_admin()) {
    add_action('init', 'hinschg_portal_register_cpt_hinweis');
    add_action('admin_menu', 'hinschg_portal_register_admin_menu');
    add_action('admin_init', 'hinschg_portal_maybe_migrate');

    global $pagenow;

    if ($pagenow === 'admin-post.php') {
        $hinschg_portal_action = isset($_REQUEST['action'])
            ? sanitize_key(wp_unslash($_REQUEST['action']))
            : '';

        $hinschg_portal_handlers = [
            'hinschg_save_mandant' => 'hinschg_portal_handle_save_mandant',
            'hinschg_delete_mandant' => 'hinschg_portal_handle_delete_mandant',
            'hinschg_save_logo' => 'hinschg_portal_handle_save_logo',
            'hinschg_dl' => 'hinschg_portal_handle_download',
            'hinschg_print_case' => 'hinschg_portal_handle_print_case',
        ];

        if (isset($hinschg_portal_handlers[$hinschg_portal_action])) {
            add_action(
                'admin_post_' . $hinschg_portal_action,
                $hinschg_portal_handlers[$hinschg_portal_action]
            );

            if ($hinschg_portal_action === 'hinschg_dl') {
                add_action(
                    'admin_post_nopriv_' . $hinschg_portal_action,
                    $hinschg_portal_handlers[$hinschg_portal_action]
                );
            }
        }
    }

    if (isset($_COOKIE['hinschg_tenant'])) {
        add_filter(
            'sanitize_file_name',
            'hinschg_portal_prefix_tenant_filename',
            10,
            1
        );
    }

    $hinschg_portal_post_type = isset($_GET['post_type'])
        ? sanitize_key(wp_unslash($_GET['post_type']))
        : '';
    $hinschg_portal_post_id = isset($_GET['post'])
        ? absint($_GET['post'])
        : 0;

    if (
        $hinschg_portal_post_id
        && get_post_type($hinschg_portal_post_id) === 'hinweis'
    ) {
        $hinschg_portal_post_type = 'hinweis';
    }

    add_action(
        'save_post_hinweis',
        'hinschg_portal_save_report_meta',
        10,
        2
    );

    if ($hinschg_portal_post_type === 'hinweis') {
        add_filter(
            'manage_edit-hinweis_columns',
            'hinschg_portal_admin_columns'
        );
        add_action(
            'manage_hinweis_posts_custom_column',
            'hinschg_portal_admin_column_content',
            10,
            2
        );
        add_action(
            'add_meta_boxes_hinweis',
            'hinschg_portal_register_meta_boxes'
        );
        add_action(
            'restrict_manage_posts',
            'hinschg_portal_add_tenant_filter'
        );
        add_action(
            'pre_get_posts',
            'hinschg_portal_apply_tenant_filter'
        );
    }

    if ($pagenow === 'export-personal-data.php' || wp_doing_ajax()) {
        add_filter(
            'wp_privacy_personal_data_exporters',
            'hinschg_portal_register_privacy_exporter'
        );
        add_filter(
            'wp_privacy_personal_data_erasers',
            'hinschg_portal_register_privacy_eraser'
        );
    }
} else {
    add_shortcode(
        'hinweisportal',
        'hinschg_portal_shortcode_hinweisportal'
    );
}

/* ===== Fristenkontrolle und Löschvormerkung (keine automatische Vernichtung) ===== */
function hinschg_portal_deadline_timestamp($post_id, $kind) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'hinweis') return 0;
    $received = get_post_timestamp($post, 'date');
    if (!$received) return 0;
    if ($kind === 'receipt') return $received + 7 * DAY_IN_SECONDS;
    if ($kind === 'feedback') {
        $confirmed = get_post_meta($post_id, '_hinschg_acknowledged_at', true);
        $base = $confirmed ? strtotime($confirmed) : ($received + 7 * DAY_IN_SECONDS);
        return $base ? strtotime('+3 months', $base) : 0;
    }
    if ($kind === 'retention') {
        $closed = get_post_meta($post_id, '_hinschg_closed_at', true);
        return $closed ? strtotime('+3 years', strtotime($closed)) : 0;
    }
    return 0;
}
function hinschg_portal_deadlines_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
    if (isset($_POST['hinschg_ack_id'])) {
        check_admin_referer('hinschg_ack');
        $id = absint($_POST['hinschg_ack_id']);
        if (get_post_type($id) === 'hinweis' && current_user_can('edit_post', $id)) {
            update_post_meta($id, '_hinschg_acknowledged_at', current_time('mysql'));
            hinschg_portal_add_audit_event($id, 'acknowledgement_recorded', 'Eingangsbestätigung manuell dokumentiert', get_current_user_id());
        }
    }
    hinschg_portal_retention_handle_action();
    // Refresh the dashboard counts without sending an email.
    hinschg_portal_daily_review(false);
    $items = get_posts(['post_type'=>'hinweis','post_status'=>'private','numberposts'=>-1,'orderby'=>'date','order'=>'DESC']);
    echo '<div class="wrap"><h1>HinSchG – Fristen und Aufbewahrung</h1><p>Fristen sind Orientierungshilfen. Eine Eingangsbestätigung muss tatsächlich versendet werden, bevor sie hier dokumentiert wird. Löschkandidaten werden nicht automatisch gelöscht; rechtliche Ausnahmen und Backups sind vor Löschung zu prüfen.</p>';
    // Show urgent, actionable cases first, without disclosing case details in notifications.
    $items = array_values(array_filter($items, function($item) { return current_user_can('edit_post', $item->ID); }));
    $priority = function($item) {
        $ack = get_post_meta($item->ID, '_hinschg_acknowledged_at', true);
        $receipt = hinschg_portal_deadline_timestamp($item->ID, 'receipt');
        $feedback = hinschg_portal_deadline_timestamp($item->ID, 'feedback');
        $retention = hinschg_portal_deadline_timestamp($item->ID, 'retention');
        $now = time();
        if ((!$ack && $receipt && $receipt < $now) || ($feedback && $feedback < $now)) return 0;
        if ($retention && $retention < $now) return 1;
        if ((!$ack && $receipt && $receipt < $now + 2 * DAY_IN_SECONDS) || ($feedback && $feedback < $now + 7 * DAY_IN_SECONDS)) return 2;
        return 3;
    };
    usort($items, function($a, $b) use ($priority) { return $priority($a) <=> $priority($b); });
    $urgent = count(array_filter($items, function($item) use ($priority) { return $priority($item) === 0; }));
    $review_counts = get_option('hinschg_portal_review_counts', []);
    $retention_due = max(0, (int) ($review_counts['retention'] ?? 0));
    echo '<p><strong>'.esc_html($retention_due).' fällige Aufbewahrungsprüfungen</strong> (separat von den Fristen).</p>';
    echo '<p><strong>'.esc_html($urgent).' Vorgänge mit überfälliger Eingangsbestätigung oder Rückmeldefrist</strong> – dringende Vorgänge stehen oben. Die tatsächliche Rückmeldung ist unabhängig davon zu dokumentieren.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Vorgang</th><th>7-Tage-Frist</th><th>Rückmeldung</th><th>Löschprüfung</th><th>Aktion</th></tr></thead><tbody>';
    foreach ($items as $item) {
        $ack = get_post_meta($item->ID, '_hinschg_acknowledged_at', true);
        echo '<tr><td><a href="'.esc_url(get_edit_post_link($item->ID)).'">#'.esc_html($item->ID).'</a></td>';
        foreach (['receipt','feedback','retention'] as $kind) {
            $ts = hinschg_portal_deadline_timestamp($item->ID, $kind);
            $label = $ts ? wp_date('d.m.Y', $ts) : '–';
            if ($kind === 'receipt' && $ack) $label .= ' (bestätigt)';
            elseif ($ts && $ts < time()) $label .= ' (prüfen: überfällig)';
            echo '<td>'.esc_html($label).'</td>';
        }
        echo '<td>';
        hinschg_portal_retention_controls($item->ID);
        if (!$ack) {
            echo '<form method="post">'; wp_nonce_field('hinschg_ack');
            echo '<input type="hidden" name="hinschg_ack_id" value="'.esc_attr($item->ID).'"><button class="button">Eingangsbestätigung dokumentieren</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table><p>Es werden alle berechtigten Vorgänge angezeigt. Keine automatische Löschung aktiviert.</p>';
    // Minimal, non-identifying deletion receipt: no report content, case ID or tenant ID.
    $deletions = get_option('hinschg_portal_retention_deletion_log', []);
    if (!is_array($deletions)) $deletions = [];
    echo '<h2>Letzte abgeschlossene Löschungen</h2>';
    echo '<p>Dieses Protokoll enthält keine Meldungsinhalte oder Mandanten-IDs. Backups sind separat zu behandeln.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Zeitpunkt</th><th>Vorgemerkt von</th><th>Freigegeben von</th><th>Prüfsumme (gekürzt)</th></tr></thead><tbody>';
    foreach (array_reverse(array_slice($deletions, -20)) as $entry) {
        if (!is_array($entry)) continue;
        $when = !empty($entry['deleted_at']) ? wp_date('d.m.Y H:i', (int)$entry['deleted_at']) : '–';
        $digest = isset($entry['case_digest']) ? substr((string)$entry['case_digest'], 0, 12) : '–';
        echo '<tr><td>'.esc_html($when).'</td><td>'.esc_html((string)($entry['requested_by'] ?? '–')).'</td><td>'.esc_html((string)($entry['approved_by'] ?? '–')).'</td><td><code>'.esc_html($digest).'</code></td></tr>';
    }
    if (!$deletions) echo '<tr><td colspan="4">Noch keine abgeschlossenen Löschungen.</td></tr>';
    echo '</tbody></table></div>';
}
add_action('admin_menu', function() {
    add_submenu_page('hinschg-portal', 'Fristen und Aufbewahrung', 'Fristen und Aufbewahrung', 'manage_options', 'hinschg-fristen', 'hinschg_portal_deadlines_admin_page');
});


/* ===== 1.7.0: privacy-preserving deadline monitoring and retention review ===== */
function hinschg_portal_schedule_daily_review() {
    if (!wp_next_scheduled('hinschg_portal_daily_review')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'hinschg_portal_daily_review');
    }
}
add_action('init', 'hinschg_portal_schedule_daily_review');
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('hinschg_portal_daily_review');
});
function hinschg_portal_daily_review($send_mail = true) {
    $counts = ['receipt' => 0, 'feedback' => 0, 'retention' => 0];
    $now = time();
    $page = 1;
    do {
        $ids = get_posts(['post_type' => 'hinweis', 'post_status' => 'private', 'fields' => 'ids', 'posts_per_page' => 100, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false]);
        foreach ($ids as $id) {
            foreach (array_keys($counts) as $kind) {
                if ($kind === 'receipt' && get_post_meta($id, '_hinschg_acknowledged_at', true)) continue;
                $due = hinschg_portal_deadline_timestamp($id, $kind);
                if ($due && $due <= $now) $counts[$kind]++;
            }
        }
        $page++;
    } while (count($ids) === 100);
    update_option('hinschg_portal_review_counts', $counts, false);
    update_option('hinschg_portal_review_checked_at', $now, false);
    if (!$send_mail || array_sum($counts) === 0) return;
    $last_sent = (int) get_option('hinschg_portal_review_last_mail', 0);
    if ($last_sent && $last_sent > $now - DAY_IN_SECONDS) return;
    $recipient = get_option('admin_email');
    if (!$recipient || !is_email($recipient)) return;
    $subject = 'HinSchG-Portal: Fristenpruefung erforderlich';
    $body = 'Im HinSchG-Portal sind Fristen oder Aufbewahrungsprüfungen offen. Bitte melden Sie sich im geschuetzten WordPress-Administrationsbereich an und pruefen Sie die Uebersicht Fristen und Aufbewahrung. Diese Nachricht enthaelt keine Falldaten.';
    if (wp_mail($recipient, $subject, $body)) update_option('hinschg_portal_review_last_mail', $now, false);
}
add_action('hinschg_portal_daily_review', 'hinschg_portal_daily_review');
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    // Only show this notice inside the HinSchG portal, never on unrelated WP screens.
    $screen = get_current_screen();
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $is_portal = ($page === 'hinschg-portal' || strpos($page, 'hinschg-') === 0)
        || ($screen && in_array($screen->post_type, ['hinweis'], true));
    if (!$is_portal) return;
    $counts = get_option('hinschg_portal_review_counts', []);
    if (!is_array($counts)) return;
    $receipt = max(0, (int) ($counts['receipt'] ?? 0));
    $feedback = max(0, (int) ($counts['feedback'] ?? 0));
    $retention = max(0, (int) ($counts['retention'] ?? 0));
    if ($receipt + $feedback + $retention === 0) return;
    $url = admin_url('admin.php?page=hinschg-fristen');
    $details = sprintf('%d überfällige Eingangsbestätigungen, %d fällige Rückmeldefristen, %d fällige Aufbewahrungsprüfungen.', $receipt, $feedback, $retention);
    echo '<div class="notice notice-warning"><p><strong>HinSchG-Portal:</strong> '.esc_html($details).' <a href="'.esc_url($url).'">Fristenübersicht öffnen</a>. Stand: letzte Prüfung durch WP-Cron oder Aufruf der Fristenübersicht.</p></div>';
});


// Delete database-stored files with the case (also for ordinary permanent deletion).
add_action('before_delete_post', function($post_id) {
    if (get_post_type($post_id) !== 'hinweis') return;
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'hinschg_private_files', ['case_id' => (int)$post_id], ['%d']);
});

/* ===== 1.8.0: Controlled two-person retention deletion ===== */
function hinschg_portal_retention_handle_action() {
    if (empty($_POST['hinschg_retention_action'])) return;
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
    check_admin_referer('hinschg_retention_action');
    $id = absint($_POST['hinschg_retention_id'] ?? 0);
    $action = sanitize_key(wp_unslash($_POST['hinschg_retention_action']));
    if (get_post_type($id) !== 'hinweis' || !current_user_can('delete_post', $id)) wp_die('Keine Fallberechtigung');
    $user = get_current_user_id();
    $hold = (bool) get_post_meta($id, '_hinschg_retention_hold', true);
    $due = hinschg_portal_deadline_timestamp($id, 'retention');
    $request = get_post_meta($id, '_hinschg_retention_request', true);
    if ($action === 'hold') {
        update_post_meta($id, '_hinschg_retention_hold', '1');
        delete_post_meta($id, '_hinschg_retention_request');
        hinschg_portal_add_audit_event($id, 'retention_hold', 'Aufbewahrungssperre gesetzt', $user);
    } elseif ($action === 'release') {
        delete_post_meta($id, '_hinschg_retention_hold');
        hinschg_portal_add_audit_event($id, 'retention_release', 'Aufbewahrungssperre aufgehoben', $user);
    } elseif ($action === 'request' && !$hold && $due && $due <= time()) {
        update_post_meta($id, '_hinschg_retention_request', ['user' => $user, 'time' => time()]);
        hinschg_portal_add_audit_event($id, 'retention_requested', 'Loeschung zur Zweitfreigabe vorgemerkt', $user);
    } elseif ($action === 'cancel') {
        delete_post_meta($id, '_hinschg_retention_request');
        hinschg_portal_add_audit_event($id, 'retention_cancelled', 'Loeschvormerkung aufgehoben', $user);
    } elseif ($action === 'approve' && !$hold && $due && $due <= time()
        && is_array($request) && !empty($request['user']) && (int)$request['user'] !== $user) {
        // Do not delete cases with any linked file: this includes legacy attachments
        // whose post_parent was never set to the case.
        $attachments = get_children(['post_parent' => $id, 'post_type' => 'attachment', 'numberposts' => 1]);
        $linked_attachment_id = absint(get_post_meta($id, '_hinschg_attachment_id', true));
        if ($attachments || $linked_attachment_id) {
            wp_die('Loeschung blockiert: Dieser Vorgang enthaelt Dateianhaenge. Bitte zuerst den geschuetzten Datei-Loeschprozess klaeren.');
        }
        $deleted = wp_delete_post($id, true);
        if (!$deleted || get_post($id)) {
            wp_die('Loeschung fehlgeschlagen. Es wurde kein erfolgreicher Loeschvorgang protokolliert.');
        }
        // Only log confirmed deletions. Do not store contents or tenant identifiers.
        $log = get_option('hinschg_portal_retention_deletion_log', []);
        if (!is_array($log)) $log = [];
        $log[] = ['case_digest' => hash_hmac('sha256', (string)$id, wp_salt('auth')),
            'requested_by' => (int)$request['user'], 'approved_by' => $user, 'deleted_at' => time()];
        update_option('hinschg_portal_retention_deletion_log', array_slice($log, -1000), false);
        echo '<div class="notice notice-success"><p>Fallakte nach Zweitfreigabe gelöscht. Bitte Backups und externe Kopien nach Löschkonzept behandeln.</p></div>';
    }
}
function hinschg_portal_retention_controls($id) {
    if (!current_user_can('manage_options') || !current_user_can('delete_post', $id)) return;
    $due = hinschg_portal_deadline_timestamp($id, 'retention');
    $hold = (bool) get_post_meta($id, '_hinschg_retention_hold', true);
    $request = get_post_meta($id, '_hinschg_retention_request', true);
    $actions = $hold ? ['release' => 'Sperre aufheben'] : ['hold' => 'Aufbewahrungssperre setzen'];
    if (!$hold && $due && $due <= time()) {
        if (!is_array($request)) $actions['request'] = 'Loeschung vormerken';
        else {
            $actions['cancel'] = 'Vormerkung aufheben';
            if ((int)($request['user'] ?? 0) !== get_current_user_id()) $actions['approve'] = 'Endgueltig loeschen (Zweitfreigabe)';
        }
    }
    foreach ($actions as $action => $label) {
        echo '<form method="post" style="margin:4px 0">';
        wp_nonce_field('hinschg_retention_action');
        echo '<input type="hidden" name="hinschg_retention_id" value="'.esc_attr($id).'">';
        echo '<input type="hidden" name="hinschg_retention_action" value="'.esc_attr($action).'">';
        echo '<button type="submit" class="button"'.($action === 'approve' ? ' onclick="return confirm(\'Fallakte unwiderruflich löschen? Backups separat prüfen.\')"' : '').'>'.esc_html($label).'</button></form>';
    }
}
