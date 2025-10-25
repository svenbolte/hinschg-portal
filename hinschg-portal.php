<?php
/**
 * Plugin Name: HinSchG Portal (Multi-Mandant)
 * Description: Hinweisgeberschutz-Portal mit Mandantenverwaltung (8-stellige ID via URL-Parameter mandant=XXXXXXXX) und anonymer Hinweisabgabe.
 * Version: 1.2.1
 * Author: Patrick & GPT-5 Thinking
 * Text Domain: hinschg-portal
 */

if (!defined('ABSPATH')) { exit; }

class HinSchG_Portal {
    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'hinschg_portal_options';
    const NONCE_ACTION = 'hinschg_portal_action';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        
        add_action('admin_menu', [$this, 'register_legal_page']);
add_action('admin_post_hinschg_save_mandant', [$this, 'handle_save_mandant']);
        add_action('admin_post_hinschg_delete_mandant', [$this, 'handle_delete_mandant']);
        add_shortcode('hinweisportal', [$this, 'shortcode_hinweisportal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('admin_post_nopriv_hinschg_dl', [$this, 'handle_download']);
        add_action('admin_post_hinschg_dl', [$this, 'handle_download']);
        add_action('init', [$this, 'register_cpt_hinweis']);

        // Admin-UI Erweiterungen für Hinweise
        add_filter('manage_edit-hinweis_columns', [$this, 'admin_columns']);
        add_action('manage_hinweis_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('restrict_manage_posts', [$this, 'add_tenant_filter']);
        add_action('pre_get_posts', [$this, 'apply_tenant_filter']);

        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_privacy_exporter']);
        add_filter('wp_privacy_personal_data_erasers',  [$this, 'register_privacy_eraser']);
    }

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'hinschg_mandanten';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id CHAR(8) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            ort VARCHAR(190) NULL,
            email VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id_idx (tenant_id)
        ) {$charset_collate};";
        dbDelta($sql);

        // Default options
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, [
                'notify_cc' => '', // optional CC
                'store_ip'  => false, // IP nicht speichern (wir speichern sowieso keine IP)
                'allow_attachments' => true,
                'max_attachment_mb' => 10,
            ]);
        }
    }

    public function register_cpt_hinweis() {
        register_post_type('hinweis', [
            'label' => __('Hinweise', 'hinschg-portal'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'hinschg-portal', // als Submenü unter HinSchG Portal
            'show_in_admin_bar' => false,       // kein "Neu" oben in der Admin-Bar
            'capability_type' => 'post',
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
    public function register_admin_menu() {
        add_menu_page(
            __('HinSchG Portal', 'hinschg-portal'),
            __('HinSchG Portal', 'hinschg-portal'),
            'manage_options',
            'hinschg-portal',
            [$this, 'render_mandanten_page'],
            'dashicons-shield'
        );
        add_submenu_page('hinschg-portal', __('Mandanten', 'hinschg-portal'), __('Mandanten', 'hinschg-portal'), 'manage_options', 'hinschg-portal', [$this, 'render_mandanten_page']);
        add_submenu_page('hinschg-portal', __('Einstellungen', 'hinschg-portal'), __('Einstellungen', 'hinschg-portal'), 'manage_options', 'hinschg-portal-settings', [$this, 'render_settings_page']);
        // Hinweis: Der CPT "Hinweise" erscheint automatisch als Submenü durch 'show_in_menu' => 'hinschg-portal'
    }

    private function get_mandanten($search = '') {
        global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE tenant_id LIKE %s OR name LIKE %s ORDER BY created_at DESC", $like, $like));
        }
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");
    }

    private function get_mandant_by_tenant_id($tenant_id) {
        global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE tenant_id = %s", $tenant_id));
    }

    public function render_mandanten_page() {
        if (!current_user_can('manage_options')) { return; }
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $mandanten = $this->get_mandanten($search);
        ?>
        <div class="wrap">
            <h1><?php _e('Mandanten', 'hinschg-portal'); ?></h1>
            <form method="get" style="margin:1em 0;">
                <input type="hidden" name="page" value="hinschg-portal"/>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Suche nach ID oder Name" />
                <button class="button">Suchen</button>
            </form>
            <h2><?php _e('Neuen Mandanten anlegen', 'hinschg-portal'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hinschg_save_mandant" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"/>
                <table class="form-table">
                    <tr><th>8-stellige ID</th><td><input name="tenant_id" maxlength="8" pattern="[0-9]{8}" inputmode="numeric" required placeholder="12345678"/></td></tr>
                    <tr><th>Name</th><td><input name="name" required/></td></tr>
                    <tr><th>Ort</th><td><input name="ort"/></td></tr>
                    <tr><th>Kontakt-E-Mail</th><td><input type="email" name="email" required/></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>

            <h2><?php _e('Bestehende Mandanten', 'hinschg-portal'); ?></h2>
            <table class="widefat fixed striped">
                <thead><tr>
                    <th><?php _e('ID', 'hinschg-portal'); ?></th>
                    <th><?php _e('Name', 'hinschg-portal'); ?></th>
                    <th><?php _e('Ort', 'hinschg-portal'); ?></th>
                    <th><?php _e('E-Mail', 'hinschg-portal'); ?></th>
                    <th><?php _e('Aktionen', 'hinschg-portal'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($mandanten) : foreach ($mandanten as $m) : ?>
                    <tr>
                        <td><code><?php echo esc_html($m->tenant_id); ?></code></td>
                        <td><?php echo esc_html($m->name); ?></td>
                        <td><?php echo esc_html($m->ort); ?></td>
                        <td><?php echo esc_html($m->email); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Diesen Mandanten löschen?');" style="display:inline;">
                                <input type="hidden" name="action" value="hinschg_delete_mandant" />
                                <input type="hidden" name="tenant_id" value="<?php echo esc_attr($m->tenant_id); ?>" />
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"/>
                                <button class="button button-link-delete">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="5">Keine Einträge.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_save_mandant() {
        if (!current_user_can('manage_options')) { wp_die('Nope'); }
        check_admin_referer(self::NONCE_ACTION);
        global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
        $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $ort = isset($_POST['ort']) ? sanitize_text_field($_POST['ort']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (!preg_match('/^\d{8}$/', $tenant_id)) {
            wp_redirect(add_query_arg(['page' => 'hinschg-portal', 'error' => 'id'], admin_url('admin.php')));
            exit;
        }
        if (!is_email($email)) {
            wp_redirect(add_query_arg(['page' => 'hinschg-portal', 'error' => 'email'], admin_url('admin.php')));
            exit;
        }

        $existing = $this->get_mandant_by_tenant_id($tenant_id);
        if ($existing) {
            // Update
            $wpdb->update($table, [
                'name' => $name,
                'ort'  => $ort,
                'email'=> $email,
            ], ['tenant_id' => $tenant_id]);
        } else {
            // Insert
            $wpdb->insert($table, [
                'tenant_id' => $tenant_id,
                'name'      => $name,
                'ort'       => $ort,
                'email'     => $email,
            ]);
        }
        wp_redirect(add_query_arg(['page' => 'hinschg-portal', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_mandant() {
        if (!current_user_can('manage_options')) { wp_die('Nope'); }
        check_admin_referer(self::NONCE_ACTION);
        global $wpdb; $table = $wpdb->prefix . 'hinschg_mandanten';
        $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
        if (preg_match('/^\d{8}$/', $tenant_id)) {
            $wpdb->delete($table, ['tenant_id' => $tenant_id]);
        }
        wp_redirect(add_query_arg(['page' => 'hinschg-portal', 'deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    /* ===================== Einstellungen ===================== */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $opts = get_option(self::OPTION_KEY, []);
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], self::NONCE_ACTION)) {
            $opts['notify_cc'] = isset($_POST['notify_cc']) ? sanitize_text_field($_POST['notify_cc']) : '';
            $opts['allow_attachments'] = isset($_POST['allow_attachments']);
            $opts['max_attachment_mb'] = max(1, intval($_POST['max_attachment_mb'] ?? 10));
            update_option(self::OPTION_KEY, $opts);
            echo '<div class="updated notice"><p>Einstellungen gespeichert.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Einstellungen', 'hinschg-portal'); ?></h1>
            <form method="post">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>"/>
                <table class="form-table">
                    <tr><th>Benachrichtigungs-CC</th><td><input name="notify_cc" value="<?php echo esc_attr($opts['notify_cc'] ?? ''); ?>" placeholder="cc@example.org"/></td></tr>
                    <tr><th>Dateiupload erlauben</th><td><label><input type="checkbox" name="allow_attachments" <?php checked($opts['allow_attachments'] ?? false); ?>/> Ja</label></td></tr>
                    <tr><th>Max. Dateigröße (MB)</th><td><input type="number" name="max_attachment_mb" min="1" max="50" value="<?php echo esc_attr($opts['max_attachment_mb'] ?? 10); ?>"/></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
            <hr/>
            <p><strong>Shortcode:</strong> <code>[hinweisportal]</code> – URL-Parameter: <code>?mandant=12345678</code></p>
            <p>Datenschutz: Es werden vom Formular keine IP-Adressen oder Cookies gespeichert. Serverseitige Logs können unabhängig davon IPs erfassen – bitte Webserver entsprechend konfigurieren.</p>
        </div>
        <?php
    }

    /* ===================== Frontend: Shortcode ===================== */
    
    /* ===================== Frontend: Styles (inline) ===================== */
    public function enqueue_styles() {
        wp_register_style('hinschg-portal', false);
        wp_enqueue_style('hinschg-portal');
		$tcolor = get_theme_mod('link-color', '#006060');
        $custom_css = '
			.hinschg-form{display:flex;flex-direction:column;gap:1em;padding:1.5em;background:#fff;box-shadow:0 4px 14px rgba(0,0,0,.08)}
			.hinschg-form label{font-weight:600;'.$tcolor.';margin-bottom:.3em}.hinschg-form input[type=text],
			.hinschg-form input[type=email],.hinschg-form textarea,.hinschg-form input[type=file]{width:100%;padding:.7em;border:1px solid '.$tcolor.';border-radius:8px;font-size:1rem;box-sizing:border-box}
			.hinschg-form input:focus,.hinschg-form textarea:focus{outline:none;border-color:'.$tcolor.';box-shadow:0 0 0 3px rgba(0,80,179,.15)}
			.hinschg-form details{margin-top:1em;background:#f8fafc;border:1px solid '.$tcolor.';padding:.8em 1em}
			.hinschg-form details[open]{background:'.$tcolor.'19}
            .hinschg-portal { background:#fffc;border:1px solid '.$tcolor.';max-width: 100%; margin: 1em auto; padding: 1em }
            .hinschg-portal .mandant-name { font-size: 1.3em; text-align: center; font-weight: 600; color: '.$tcolor.'; margin-bottom: .5em; }
            .hinschg-portal .intro-text { font-size: 1rem; line-height: 1.6; color: #333; text-align: center; margin: 0 0 1.5em; }
            .hinschg-portal input[type="text"], .hinschg-portal input[type="email"], .hinschg-portal textarea { width: 100%; padding: .7em; border: 1px solid '.$tcolor.'; box-sizing: border-box; }
            .hinschg-portal textarea { min-height: 140px; }
            .hinschg-portal button[type="submit"] { width: 100%;}
            .hinschg-portal details { margin-top: 1em; }
        ';
        wp_add_inline_style('hinschg-portal', $custom_css);
    }

public function shortcode_hinweisportal($atts, $content = '') {
        $tenant_id = isset($_GET['mandant']) ? preg_replace('/[^0-9]/', '', $_GET['mandant']) : '';
        $mandant = (preg_match('/^\d{8}$/', $tenant_id)) ? $this->get_mandant_by_tenant_id($tenant_id) : null;
        $opts = get_option(self::OPTION_KEY, []);

        ob_start();
        echo '<div class="hinschg-portal">';
        if (!$mandant) {
            echo '<div class="notice notice-warning"><p>Bitte rufen Sie den Link mit einem gültigen Mandanten-Parameter auf (z. B. <code>?mandant=12345678</code>).</p></div>';
            echo '</div>';
            return ob_get_clean();
        }
        // Mandantenname und Introtext
        echo '<div class="mandant-name">' . esc_html($mandant->name) . '</div>';
        echo '<div class="intro-text">Dieses Hinweisgeber-Portal ermöglicht eine sichere, vertrauliche und – wenn gewünscht – anonyme Meldung von Missständen, Compliance-Verstößen oder ethischen Bedenken. Ihre Mitteilung wird ausschließlich an die verantwortliche Stelle des oben genannten Mandanten weitergeleitet. Sie können freiwillig eine anonyme Kontaktadresse hinterlassen, falls Rückfragen notwendig sind. Bitte schildern Sie den Sachverhalt so konkret wie möglich und fügen Sie bei Bedarf relevante Dokumente hinzu.</div>';
		?>
		<details class="hinweisgesetz-info" style="margin-bottom:1.5em;">
			<summary style="cursor:pointer;font-weight:600">Was ist das Hinweisgeberschutzgesetz?</summary>
			<div style="margin-top:.8em;color:#333;">
				<p>Das <strong>Hinweisgeberschutzgesetz (HinSchG)</strong> schützt Personen, die Missstände oder Rechtsverstöße in Unternehmen oder Behörden melden – sogenannte Hinweisgeber oder Whistleblower.</p>
				<p>Es verpflichtet Organisationen ab einer bestimmten Größe, eine <strong>interne Meldestelle</strong> einzurichten, über die Mitarbeitende oder externe Personen sicher und vertraulich Hinweise abgeben können.</p>
				<p><em>Kurz gesagt:</em> Dieses Gesetz sorgt dafür, dass Hinweise auf Fehlverhalten vertraulich behandelt werden und Hinweisgeber vor Benachteiligung oder Repressalien geschützt sind.</p>
			</div>
		</details>
		<?php

        // Handle submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hinschg_submit'])) {
            $result = $this->handle_front_submission($mandant, $opts);
            if ($result['ok']) {
                echo '<div class="notice notice-success"><p>Vielen Dank. Ihr Hinweis wurde sicher übermittelt. Vorgangs-ID: <code>' . esc_html($result['token']) . '</code></p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($result['msg']) . '</p></div>';
            }
        }

        $this->render_front_form($mandant, $opts);
        echo '</div>';
        return ob_get_clean();
    }

    private function render_front_form($mandant, $opts) {
        $max_mb = intval($opts['max_attachment_mb'] ?? 10);
        ?>
         <h6>Hinweis abgeben – <?php echo esc_html($mandant->name); ?> (<?php echo esc_html($mandant->tenant_id); ?>)</h6>
        <form method="post" enctype="multipart/form-data" class="hinschg-form" autocomplete="off">
            <?php wp_nonce_field(self::NONCE_ACTION, '_wpnonce'); ?>
            <input type="hidden" name="tenant_id" value="<?php echo esc_attr($mandant->tenant_id); ?>"/>
            <fieldset>
                <p><label>Betreff (optional)<br/>
                    <input type="text" name="subject" maxlength="180" placeholder="Kurzer Betreff"/></label></p>
                <p><label>Beschreibung des Sachverhalts<br/>
                    <textarea name="message" rows="8" required placeholder="Bitte beschreiben Sie den Hinweis möglichst konkret."></textarea></label></p>
                <p><label>Datei anhängen (optional, max. <?php echo esc_html($max_mb); ?> MB)<br/>
                    <input type="file" name="attachment" /></label></p>
                <details><summary>Kontakt aufnehmen? (optional)</summary>
                    <p>Wenn Sie eine Rückmeldung wünschen, können Sie eine anonyme E-Mail-Adresse angeben. Dies ist freiwillig. Wenn Sie Ihre Identität nicht preisgeben möchten, können Sie eine E-Mail-Adresse bei einem anonymen Anbieter wie ProtonMail nutzen und diese bei der Meldestelle angeben</p>
                    <p><label>E-Mail (optional)<br/><input type="email" name="contact_email" placeholder="anonym@example.org"/></label></p>
                </details>
                <p><label><input type="checkbox" name="confirm" required/> Ich bestätige, dass meine Angaben nach bestem Wissen und Gewissen korrekt sind.</label></p>
                <p><button type="submit" name="hinschg_submit">Hinweis absenden</button></p>
            </fieldset>
            <p style="font-size:12px;opacity:.8;">Es werden keine IP-Adressen, Cookies oder Browserdaten gespeichert. Bitte vermeiden Sie personenbezogene Daten, sofern nicht zwingend erforderlich.</p>
        </form>
        <?php
    }

    private function handle_front_submission($mandant, $opts) {
        $dl_token = '';
        $dl_url = '';
        $expires = 0;

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], self::NONCE_ACTION)) {
            return ['ok' => false, 'msg' => 'Sicherheits-Token ungültig.'];
        }
        $tenant_id = isset($_POST['tenant_id']) ? preg_replace('/[^0-9]/', '', $_POST['tenant_id']) : '';
        if ($tenant_id !== $mandant->tenant_id) {
            return ['ok' => false, 'msg' => 'Mandant ungültig.'];
        }
        if (empty($_POST['confirm'])) {
            return ['ok' => false, 'msg' => 'Bitte bestätigen Sie die Richtigkeit Ihrer Angaben.'];
        }

        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = wp_kses_post($_POST['message'] ?? '');
        if (!$message || strlen(trim(wp_strip_all_tags($message))) < 10) {
            return ['ok' => false, 'msg' => 'Bitte hinterlegen Sie eine aussagekräftige Beschreibung (min. 10 Zeichen).'];
        }
        $contact_email = sanitize_email($_POST['contact_email'] ?? '');
        if ($contact_email && !is_email($contact_email)) {
            return ['ok' => false, 'msg' => 'Kontakt-E-Mail ist ungültig.'];
        }

        // Optional: Attachment
        $attachment_id = 0;
        if (!empty($_FILES['attachment']['name']) && ($opts['allow_attachments'] ?? true)) {
            $max_bytes = (int)($opts['max_attachment_mb'] ?? 10) * 1024 * 1024;
            if ($_FILES['attachment']['size'] > $max_bytes) {
                return ['ok' => false, 'msg' => 'Datei ist zu groß.'];
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $upload = wp_handle_upload($_FILES['attachment'], ['test_form' => false]);
            if (isset($upload['error'])) {
                return ['ok' => false, 'msg' => 'Upload-Fehler: ' . $upload['error']];
            }
            $attachment_id = wp_insert_attachment([
                'post_mime_type' => $upload['type'],
                'post_title' => basename($upload['file']),
                'post_content' => '',
                'post_status' => 'private',
            ], $upload['file']);
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attach_data);
        }

        // Create token for anonymous follow-up
        $token = wp_generate_password(12, false, false);

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
        if ($contact_email) { add_post_meta($post_id, '_hinschg_contact_email', $contact_email, true); }
        if ($attachment_id) { add_post_meta($post_id, '_hinschg_attachment_id', (int)$attachment_id, true); }

        // Notify tenant
        $admin_link = add_query_arg(['post' => $post_id, 'action' => 'edit'], admin_url('post.php'));
        $mail_subject = 'Neuer Hinweis (' . $mandant->name . ')';
        $mail_body  = "Es wurde ein neuer Hinweis eingereicht.\n\n";
        $mail_body .= "Mandant: {$mandant->name} ({$mandant->tenant_id})\n";
        $mail_body .= "Betreff: " . ($subject ?: '-') . "\n\n";
        $mail_body .= "Vorgangs-ID: {$token}\n";
        $mail_body .= "Ansicht im Backend: {$admin_link}\n\n";
        $mail_body .= "Nachricht:\n" . wp_strip_all_tags($message) . "\n";
        if ($contact_email) { $mail_body .= "\nKontakt (freiwillig): {$contact_email}\n"; }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if (!empty($opts['notify_cc'])) { $headers[] = 'Cc: ' . $opts['notify_cc']; }
        
        
        // --- Added: build temporary (7 Tage) download link for attachment and append to mail ---
        if (isset($attachment_id) && !empty($attachment_id)) {
            // Read existing metas if present
            $dl_token = get_post_meta($post_id, '_hinschg_dl_token', true);
            $expires  = intval(get_post_meta($post_id, '_hinschg_dl_expires', true));
            if (empty($dl_token) || empty($expires)) {
                $dl_token = wp_generate_password(20, false, false);
                $expires  = time() + 7 * 24 * 3600; // 7 Tage validity
                add_post_meta($post_id, '_hinschg_dl_token',   $dl_token, true);
                add_post_meta($post_id, '_hinschg_dl_expires', $expires,  true);
            }
            $dl_url = add_query_arg([
                'action' => 'hinschg_dl',
                'post'   => $post_id,
                'tid'    => $mandant->tenant_id,
                'token'  => $dl_token,
            ], admin_url('admin-post.php'));

            if (!empty($dl_url)) {
                $mail_body .= "\nAnhang (7 Tage gültig):\n{$dl_url}\n";
            }
        }
// Download-Link für Anhang (7 Tage gültig) anfügen, falls Anhang existiert
        if (!empty($attachment_id)) {
            $dl_url = add_query_arg([
                'action' => 'hinschg_dl',
                'post'   => $post_id,
                'tid'    => $mandant->tenant_id,
                'token'  => $dl_token,
            ], admin_url('admin-post.php'));
            
        }
wp_mail($mandant->email, $mail_subject, $mail_body, $headers);

        return ['ok' => true, 'msg' => 'gespeichert', 'token' => $token];
    }

    /* ===================== Datenschutz (Export/Eraser Stubs) ===================== */
    public function register_privacy_exporter($exporters) {
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

    public function register_privacy_eraser($erasers) {
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

    /* ===== Admin-UI: Spalten, Metabox & Filter ===== */
    public function admin_columns($columns) {
        $columns_new = [];
        foreach ($columns as $key => $label) {
            $columns_new[$key] = $label;
            if ($key === 'title') {
                $columns_new['tenant'] = __('Mandant', 'hinschg-portal');
                $columns_new['token']  = __('Vorgangs-ID', 'hinschg-portal');
                $columns_new['contact']= __('Kontakt (freiw.)', 'hinschg-portal');
            }
        }
        return $columns_new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'tenant') {
            $tid = get_post_meta($post_id, '_hinschg_tenant_id', true);
            if ($tid) {
                $m = $this->get_mandant_by_tenant_id($tid);
                echo $m ? esc_html($m->name . ' (' . $m->tenant_id . ')') : esc_html($tid);
            } else {
                echo '–';
            }
        } elseif ($column === 'token') {
            echo esc_html(get_post_meta($post_id, '_hinschg_token', true) ?: '–');
        } elseif ($column === 'contact') {
            echo esc_html(get_post_meta($post_id, '_hinschg_contact_email', true) ?: '–');
        }
    }

    public function register_meta_boxes() {
        add_meta_box('hinschg_meta', __('Hinweis-Details', 'hinschg-portal'), function($post){
            $tid = get_post_meta($post->ID, '_hinschg_tenant_id', true);
            $token = get_post_meta($post->ID, '_hinschg_token', true);
            $contact = get_post_meta($post->ID, '_hinschg_contact_email', true);
            $att_id = (int) get_post_meta($post->ID, '_hinschg_attachment_id', true);
            echo '<p><strong>Mandant:</strong> ' . esc_html($tid ?: '–');
            if ($tid) { $m = $this->get_mandant_by_tenant_id($tid); if ($m) { echo ' — ' . esc_html($m->name); } }
            echo '</p>';
            echo '<p><strong>Vorgangs-ID:</strong> ' . esc_html($token ?: '–') . '</p>';
            echo '<p><strong>Kontakt (freiwillig):</strong> ' . esc_html($contact ?: '–') . '</p>';
            if ($att_id) {
                $url = wp_get_attachment_url($att_id);
                echo '<p><strong>Anhang:</strong> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">Download</a></p>';
            }
        }, 'hinweis', 'side', 'high');
    }

    public function add_tenant_filter() {
        global $typenow;
        if ($typenow !== 'hinweis') { return; }
        $current = isset($_GET['tenant_id']) ? preg_replace('/[^0-9]/','', $_GET['tenant_id']) : '';
        echo '<input type="search" name="tenant_id" value="' . esc_attr($current) . '" placeholder="Mandant-ID (8-stellig)" />';
    }

    public function apply_tenant_filter($query) {
        if (!is_admin() || !$query->is_main_query()) { return; }
        if ($query->get('post_type') !== 'hinweis') { return; }
        if (!empty($_GET['tenant_id']) && preg_match('/^\d{8}$/', $_GET['tenant_id'])) {
            $query->set('meta_query', [ [ 'key' => '_hinschg_tenant_id', 'value' => sanitize_text_field($_GET['tenant_id']) ] ]);
        }
    }


    public function handle_download() {
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        $tid     = isset($_GET['tid']) ? preg_replace('/[^0-9]/','', $_GET['tid']) : '';
        $token   = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        if (!$post_id || !$tid || !$token) { status_header(403); exit('Forbidden'); }

        $p = get_post($post_id);
        if (!$p || $p->post_type !== 'hinweis') { status_header(404); exit('Not found'); }

        $saved_tid   = get_post_meta($post_id, '_hinschg_tenant_id', true);
        $saved_token = get_post_meta($post_id, '_hinschg_dl_token', true);
        $expires     = intval(get_post_meta($post_id, '_hinschg_dl_expires', true));
        $att_id      = intval(get_post_meta($post_id, '_hinschg_attachment_id', true));

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
    public function register_legal_page() {
        add_submenu_page(
            'hinschg-portal',                // Parent-Slug
            'Rechtliches & Haftung',        // Seitentitel
            'Rechtliches',                  // Menütext
            'manage_options',               // Berechtigung
            'hinschg_portal_legal',         // Slug
            [$this, 'render_legal_page']    // Callback
        );
    }

    public function render_legal_page() {
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

            <h2>Haftungsausschluss</h2>
            <p><strong>Dieses Plugin stellt ein technisches Hilfsmittel zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes bereit.</strong></p>
            <p>
                Es ersetzt keine rechtliche Beratung und bietet keine Gewähr für die Vollständigkeit oder rechtliche Konformität der Implementierung
                im jeweiligen Einzelfall. Die Einrichtung, rechtliche Bewertung und der datenschutzkonforme Betrieb der Meldeplattform liegen
                in der alleinigen Verantwortung des Betreibers.
            </p>
            <p><strong>Der Einsatz erfolgt auf eigenes Risiko.</strong> Der Autor übernimmt keine Haftung für unmittelbare oder mittelbare Schäden,
            die durch die Nutzung oder Nichtnutzung dieses Plugins entstehen, soweit diese nicht auf vorsätzlichem oder grob fahrlässigem Handeln beruhen.</p>
            <p>Durch die Nutzung des Plugins erkennen Sie diesen Haftungsausschluss ausdrücklich an.</p>
        </div>
        <?php
    }

}
HinSchG_Portal::instance();
