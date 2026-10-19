<?php
/**
 * Plugin Name: Kalender
 * Description: Frontend-Kalender mit Datenerfassung im WordPress-Backend.
 * Version: 1.2.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/mastervanvalkenburg/Menstrual-Calendar
 * Text Domain: kalender-plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

final class KP_Kalender_Plugin {
    private const VERSION = '1.2.1';
    private const DB_VERSION = '1.2.1';
    private const DB_VERSION_OPTION = 'kp_calendar_db_version';
    private const ENTRIES_TABLE = 'kp_entries';
    private const CAPABILITY = 'manage_kp_calendar';

    public static function init(): void {
        register_activation_hook(__FILE__, [self::class, 'activate']);

        // Führt das Schema-Update auch nach einem Plugin-Update aus.
        add_action('plugins_loaded', [self::class, 'maybe_upgrade']);

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_shortcode('kalender', [self::class, 'render_calendar']);

        add_action('wp_ajax_kp_get_entries', [self::class, 'ajax_get_entries']);
        add_action('wp_ajax_nopriv_kp_get_entries', [self::class, 'ajax_get_entries']);

        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_kp_save_entry', [self::class, 'save_entry']);
        add_action('admin_post_kp_delete_entry', [self::class, 'delete_entry']);

        // Update-Prüfung für dieses Plugin über GitHub Releases.
        add_filter(
            'update_plugins_github.com',
            [self::class, 'check_github_update'],
            10,
            4
        );
    }

    /**
     * Prüft, ob es auf GitHub eine neuere Release-Version gibt.
     *
     * @param mixed  $update      Vorhandene Update-Informationen.
     * @param array  $plugin_data Plugin-Metadaten.
     * @param string $plugin_file Plugin-Dateipfad relativ zum Plugin-Verzeichnis.
     * @param array  $locales     Installierte Sprachpakete.
     * @return array|false|null
     */
    public static function check_github_update($update, $plugin_data, $plugin_file, $locales) {
        $this_plugin = plugin_basename(__FILE__);

        if ($plugin_file !== $this_plugin || !empty($update)) {
            return $update;
        }

        $repository = 'DEIN-BENUTZERNAME/DEIN-REPOSITORY';
        $api_url = 'https://api.github.com/repos/' . $repository . '/releases/latest';

        $response = wp_remote_get($api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Kalender-WordPress-Plugin',
            ],
        ]);

        if (
            is_wp_error($response)
            || wp_remote_retrieve_response_code($response) !== 200
        ) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (
            !is_array($release)
            || empty($release['tag_name'])
            || empty($release['zipball_url'])
        ) {
            return false;
        }

        $latest_version = ltrim(
            sanitize_text_field($release['tag_name']),
            'v'
        );

        $installed_version = isset($plugin_data['Version'])
            ? $plugin_data['Version']
            : '0.0.0';

        if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][a-zA-Z0-9.-]+)?$/', $latest_version)) {
            return false;
        }

        if (version_compare($latest_version, $installed_version, '<=')) {
            return false;
        }

        return [
            'slug'        => dirname($this_plugin),
            'plugin'      => $this_plugin,
            'new_version' => $latest_version,
            'url'          => isset($release['html_url'])
                ? esc_url_raw($release['html_url'])
                : '',
            'package'      => esc_url_raw($release['zipball_url']),
        ];
    }

    public static function activate(): void {
        self::create_or_update_table();
        self::grant_capabilities();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::create_or_update_table();
            self::grant_capabilities();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    private static function create_or_update_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::ENTRIES_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NOT NULL,
            entry_date DATE NOT NULL,
            description TEXT NULL,
            special TINYINT(1) NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entry_date (entry_date),
            KEY user_id (user_id)
        ) $charset;";

        dbDelta($sql);
    }

    private static function grant_capabilities(): void {
        $subscriber = get_role('subscriber');
        if ($subscriber) {
            $subscriber->add_cap(self::CAPABILITY);
        }

        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap(self::CAPABILITY);
        }
    }

    private static function is_calendar_administrator(): bool {
        return current_user_can('manage_options');
    }

    public static function enqueue_assets(): void {
        if (!is_singular()) {
            return;
        }

        global $post;

        if (!$post || !has_shortcode($post->post_content, 'kalender')) {
            return;
        }

        wp_enqueue_style(
            'kp-calendar',
            plugin_dir_url(__FILE__) . 'assets/kalender.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'kp-calendar',
            plugin_dir_url(__FILE__) . 'assets/kalender.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script('kp-calendar', 'KPKalender', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('kp_calendar_nonce'),
        ]);
    }

    public static function render_calendar(): string {
        ob_start();
        ?>
        <div class="kp-calendar" data-calendar>
            <div class="kp-toolbar">
                <button type="button" data-prev aria-label="Vorheriger Monat">‹</button>
                <h2 data-month-label></h2>
                <button type="button" data-next aria-label="Nächster Monat">›</button>
            </div>

            <div class="kp-weekdays" aria-hidden="true">
                <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span>
                <span>Fr</span><span>Sa</span><span>So</span>
            </div>

            <div class="kp-grid" data-grid></div>

            <section class="kp-entry-list">
                <h3>Einträge</h3>
                <div data-entry-list></div>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function ajax_get_entries(): void {
        check_ajax_referer('kp_calendar_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bitte anmelden.'], 401);
        }

        global $wpdb;

        $month = isset($_POST['month'])
            ? sanitize_text_field(wp_unslash($_POST['month']))
            : '';

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            wp_send_json_error(['message' => 'Ungültiger Monat.'], 400);
        }

        $start = $month . '-01';
        $end = gmdate('Y-m-t', strtotime($start));
        $table = $wpdb->prefix . self::ENTRIES_TABLE;

        if (self::is_calendar_administrator()) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, entry_date, description, special
                 FROM $table
                 WHERE entry_date BETWEEN %s AND %s
                 ORDER BY entry_date ASC, id ASC",
                $start,
                $end
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, entry_date, description, special
                 FROM $table
                 WHERE entry_date BETWEEN %s AND %s
                   AND user_id = %d
                 ORDER BY entry_date ASC, id ASC",
                $start,
                $end,
                get_current_user_id()
            ), ARRAY_A);
        }

        foreach ($rows as &$row) {
            $row['special'] = (bool) $row['special'];
        }
        unset($row);

        wp_send_json_success($rows);
    }

    public static function admin_menu(): void {
        add_menu_page(
            'Kalender-Einträge',
            'Kalender',
            self::CAPABILITY,
            'kp-calendar',
            [self::class, 'render_admin_page'],
            'dashicons-calendar-alt'
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Keine Berechtigung.');
        }

        global $wpdb;

        $table = $wpdb->prefix . self::ENTRIES_TABLE;
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $entry = null;

        if ($edit_id) {
            if (self::is_calendar_administrator()) {
                $entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE id = %d",
                    $edit_id
                ));
            } else {
                $entry = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table
                     WHERE id = %d AND user_id = %d",
                    $edit_id,
                    get_current_user_id()
                ));
            }
        }

        $redirect = admin_url('admin.php?page=kp-calendar');
        ?>
        <div class="wrap">
            <h1>Kalender-Einträge</h1>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Eintrag gespeichert.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Eintrag gelöscht.</p>
                </div>
            <?php endif; ?>

            <?php if ($edit_id && !$entry) : ?>
                <div class="notice notice-error">
                    <p>Der Eintrag wurde nicht gefunden oder du hast keinen Zugriff darauf.</p>
                </div>
            <?php endif; ?>

            <h2><?php echo $entry ? 'Eintrag bearbeiten' : 'Eintrag hinzufügen'; ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="kp_save_entry">
                <input type="hidden" name="id" value="<?php echo esc_attr($entry->id ?? 0); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_url($redirect); ?>">
                <?php wp_nonce_field('kp_save_entry'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="kp-title">Titel</label></th>
                        <td>
                            <input id="kp-title" name="title" type="text" class="regular-text"
                                   maxlength="190" required
                                   value="<?php echo esc_attr($entry->title ?? ''); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="kp-date">Datum</label></th>
                        <td>
                            <input id="kp-date" name="entry_date" type="date" required
                                   value="<?php echo esc_attr($entry->entry_date ?? ''); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="kp-description">Beschreibung</label></th>
                        <td>
                            <textarea id="kp-description" name="description" rows="4"
                                      class="large-text"><?php
                                echo esc_textarea($entry->description ?? '');
                            ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>Besonderes Vorkommnis</th>
                        <td>
                            <label>
                                <input type="checkbox" name="special" value="1"
                                    <?php checked(!empty($entry->special)); ?>>
                                Als besonderes Vorkommnis markieren
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button($entry ? 'Änderungen speichern' : 'Eintrag hinzufügen'); ?>
            </form>

            <hr>
            <h2><?php echo self::is_calendar_administrator() ? 'Alle Einträge' : 'Meine Einträge'; ?></h2>

            <?php
            if (self::is_calendar_administrator()) {
                $rows = $wpdb->get_results(
                    "SELECT * FROM $table ORDER BY entry_date DESC, id DESC"
                );
            } else {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $table
                     WHERE user_id = %d
                     ORDER BY entry_date DESC, id DESC",
                    get_current_user_id()
                ));
            }
            ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Titel</th>
                        <th>Besonderes Vorkommnis</th>
                        <th>Beschreibung</th>
                        <?php if (self::is_calendar_administrator()) : ?>
                            <th>Benutzer-ID</th>
                        <?php endif; ?>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr>
                        <td colspan="<?php echo self::is_calendar_administrator() ? '6' : '5'; ?>">
                            Noch keine Einträge vorhanden.
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->entry_date); ?></td>
                            <td><?php echo esc_html($row->title); ?></td>
                            <td><?php echo (int) $row->special ? 'Ja' : 'Nein'; ?></td>
                            <td><?php echo esc_html($row->description); ?></td>
                            <?php if (self::is_calendar_administrator()) : ?>
                                <td><?php echo esc_html((string) $row->user_id); ?></td>
                            <?php endif; ?>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg([
                                    'page' => 'kp-calendar',
                                    'edit' => $row->id,
                                ], admin_url('admin.php'))); ?>">Bearbeiten</a>
                                |
                                <a href="<?php echo esc_url(wp_nonce_url(
                                    admin_url('admin-post.php?action=kp_delete_entry&id=' . (int) $row->id),
                                    'kp_delete_entry_' . (int) $row->id
                                )); ?>" onclick="return confirm('Eintrag wirklich löschen?');">
                                    Löschen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function save_entry(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Keine Berechtigung.');
        }

        check_admin_referer('kp_save_entry');

        global $wpdb;

        $table = $wpdb->prefix . self::ENTRIES_TABLE;
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $title = isset($_POST['title'])
            ? sanitize_text_field(wp_unslash($_POST['title']))
            : '';
        $date = isset($_POST['entry_date'])
            ? sanitize_text_field(wp_unslash($_POST['entry_date']))
            : '';
        $description = isset($_POST['description'])
            ? sanitize_textarea_field(wp_unslash($_POST['description']))
            : '';
        $special = !empty($_POST['special']) ? 1 : 0;

        if ($title === '' || !self::is_valid_date($date)) {
            wp_die('Bitte einen Titel und ein gültiges Datum angeben.');
        }

        $data = [
            'title'       => $title,
            'entry_date'  => $date,
            'description' => $description,
            'special'     => $special,
        ];

        if ($id) {
            if (self::is_calendar_administrator()) {
                $updated = $wpdb->update(
                    $table,
                    $data,
                    ['id' => $id],
                    ['%s', '%s', '%s', '%d'],
                    ['%d']
                );
            } else {
                $updated = $wpdb->update(
                    $table,
                    $data,
                    [
                        'id'      => $id,
                        'user_id' => get_current_user_id(),
                    ],
                    ['%s', '%s', '%s', '%d'],
                    ['%d', '%d']
                );
            }

            if (
                $updated === false
                || (!self::is_calendar_administrator() && $updated === 0)
            ) {
                wp_die('Der Eintrag konnte nicht geändert werden oder gehört nicht dir.');
            }
        } else {
            $data['user_id'] = get_current_user_id();

            $inserted = $wpdb->insert(
                $table,
                $data,
                ['%s', '%s', '%s', '%d', '%d']
            );

            if (!$inserted) {
                wp_die('Der Eintrag konnte nicht gespeichert werden.');
            }
        }

        // Immer auf die Kalender-Verwaltungsseite umleiten.
        $redirect = admin_url('admin.php?page=kp-calendar');

        wp_safe_redirect(add_query_arg('saved', '1', $redirect));
        exit;
    }

    public static function delete_entry(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Keine Berechtigung.');
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('kp_delete_entry_' . $id);

        if ($id) {
            global $wpdb;

            $table = $wpdb->prefix . self::ENTRIES_TABLE;

            if (self::is_calendar_administrator()) {
                $deleted = $wpdb->delete(
                    $table,
                    ['id' => $id],
                    ['%d']
                );
            } else {
                $deleted = $wpdb->delete(
                    $table,
                    [
                        'id'      => $id,
                        'user_id' => get_current_user_id(),
                    ],
                    ['%d', '%d']
                );
            }

            if ($deleted === false) {
                wp_die('Der Eintrag konnte nicht gelöscht werden.');
            }
        }

        wp_safe_redirect(add_query_arg(
            'deleted',
            '1',
            admin_url('admin.php?page=kp-calendar')
        ));
        exit;
    }

    private static function is_valid_date(string $date): bool {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }
}

KP_Kalender_Plugin::init();
