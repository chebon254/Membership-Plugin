<?php
/**
 * Plugin Name: Political Party Membership - Kenya
 * Plugin URI: https://example.com/
 * Description: Membership registration system for political parties in Kenya with ID number validation
 * Version: 1.1.2
 * Author: Chebon Kelvin
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: political-party-membership
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 * Network: false
 * 
 * @package PoliticalPartyMembership
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PPM_VERSION', '1.1.0');
define('PPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PPM_TEXT_DOMAIN', 'political-party-membership');

/**
 * Main Political Party Membership Plugin Class
 * 
 * @since 1.0.0
 */
final class PoliticalPartyMembership {
    
    /**
     * Plugin instance
     * 
     * @var PoliticalPartyMembership|null
     */
    private static ?PoliticalPartyMembership $instance = null;
    
    /**
     * Database table name
     * 
     * @var string
     */
    private string $table_name;
    
    /**
     * Get plugin instance (Singleton pattern)
     * 
     * @return PoliticalPartyMembership
     */
    public static function get_instance(): PoliticalPartyMembership {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'party_members';
        
        add_action('init', [$this, 'init']);
        
        // Load text domain for translations
        add_action('plugins_loaded', [$this, 'load_text_domain']);
        
        // Check if tables exist and create them if not
        add_action('admin_init', [$this, 'check_database']);
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize a singleton.');
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_text_domain(): void {
        load_plugin_textdomain(
            PPM_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Handle membership check AJAX request
     */
    public function handle_membership_check(): void {
        // Clean any previous output
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Check if this is an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error(__('Invalid request method', PPM_TEXT_DOMAIN));
        }
        
        // Verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'party_membership_nonce')) {
            wp_send_json_error(__('Security check failed', PPM_TEXT_DOMAIN));
        }
        
        // Sanitize and validate input
        $kenyan_id = sanitize_text_field($_POST['kenyan_id'] ?? '');
        
        if (empty($kenyan_id)) {
            wp_send_json_error(__('Please enter your ID number.', PPM_TEXT_DOMAIN));
        }
        
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            wp_send_json_error(__('Please enter a valid Kenyan ID number (7-8 digits only).', PPM_TEXT_DOMAIN));
        }
        
        global $wpdb;
        
        // Check if member exists
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($member) {
            // Format the registration date
            $formatted_date = wp_date('F j, Y', strtotime($member->registration_date));
            
            wp_send_json_success([
                'member_number' => esc_html($member->member_number),
                'full_name' => esc_html($member->full_name),
                'email' => esc_html($member->email),
                'phone' => esc_html($member->phone),
                'registration_date' => $formatted_date,
                'status' => esc_html(ucfirst($member->status))
            ]);
        } else {
            wp_send_json_error(__('No membership found for this ID number. You may need to register first.', PPM_TEXT_DOMAIN));
        }
    }
    
    /**
     * Check and create database tables if they don't exist
     */
    public function check_database(): void {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            $this->create_tables();
            $this->create_pages();
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init(): void {
        // Add admin menu
        add_action('admin_menu', [$this, 'admin_menu']);
        
        // Handle AJAX requests
        add_action('wp_ajax_register_member', [$this, 'handle_registration']);
        add_action('wp_ajax_nopriv_register_member', [$this, 'handle_registration']);
        add_action('wp_ajax_check_membership', [$this, 'handle_membership_check']);
        add_action('wp_ajax_nopriv_check_membership', [$this, 'handle_membership_check']);
        
        // Add shortcodes
        add_shortcode('party_membership_form', [$this, 'membership_form_shortcode']);
        add_shortcode('party_members_list', [$this, 'members_list_shortcode']);
        add_shortcode('party_membership_stats', [$this, 'membership_stats_shortcode']);
        add_shortcode('party_membership_checker', [$this, 'membership_checker_shortcode']);
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
    }
    
    /**
     * Plugin activation
     */
    public function activate(): void {
        $this->create_tables();
        $this->create_pages();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            member_number varchar(20) NOT NULL,
            full_name varchar(200) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) NOT NULL,
            kenyan_id varchar(20) NOT NULL,
            registration_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY member_number (member_number),
            UNIQUE KEY email (email),
            UNIQUE KEY kenyan_id (kenyan_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Initialize member counter if not exists
        if (!get_option('party_member_counter')) {
            add_option('party_member_counter', 0);
        }
    }
    
    /**
     * Create plugin pages
     */
    private function create_pages(): void {
        $page_content = '[party_membership_form]';
        
        $page_data = [
            'post_title' => __('Membership Registration', PPM_TEXT_DOMAIN),
            'post_content' => $page_content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'membership-registration'
        ];
        
        if (!get_page_by_path('membership-registration')) {
            wp_insert_post($page_data);
        }
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu(): void {
        add_menu_page(
            __('Party Members', PPM_TEXT_DOMAIN),
            __('Party Members', PPM_TEXT_DOMAIN),
            'manage_options',
            'party-members',
            [$this, 'admin_page'],
            'dashicons-groups',
            30
        );
        
        add_submenu_page(
            'party-members',
            __('All Members', PPM_TEXT_DOMAIN),
            __('All Members', PPM_TEXT_DOMAIN),
            'manage_options',
            'party-members',
            [$this, 'admin_page']
        );
        
        add_submenu_page(
            'party-members',
            __('Add New Member', PPM_TEXT_DOMAIN),
            __('Add New Member', PPM_TEXT_DOMAIN),
            'manage_options',
            'party-members-add',
            [$this, 'add_member_page']
        );
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts(): void {
        // Only enqueue on pages that have our shortcodes or forms
        global $post;
        if (is_admin() || 
            (is_object($post) && (
                has_shortcode($post->post_content, 'party_membership_form') ||
                has_shortcode($post->post_content, 'party_members_list') ||
                has_shortcode($post->post_content, 'party_membership_stats') ||
                has_shortcode($post->post_content, 'party_membership_checker')
            ))) {
            
            wp_enqueue_script('jquery');
            
            // Create inline JavaScript for AJAX
            $ajax_script = "
                var party_ajax = {
                    ajax_url: '" . admin_url('admin-ajax.php') . "',
                    nonce: '" . wp_create_nonce('party_membership_nonce') . "'
                };
            ";
            
            wp_add_inline_script('jquery', $ajax_script);
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts(): void {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'party-members') !== false) {
            // Add inline admin styles
            $admin_css = "
                .status-active { color: #46b450; font-weight: bold; }
                .status-inactive { color: #dc3232; }
                .check-column { width: 2.2em; }
                .column-cb { width: 2.2em; }
                .button-link-delete { color: #dc3232; }
                .button-link-delete:hover { color: #a00; }
            ";
            wp_add_inline_style('wp-admin', $admin_css);
        }
    }
    
    /**
     * Members list shortcode
     */
    public function members_list_shortcode($atts): string {
        $atts = shortcode_atts([
            'limit' => 10,
            'show_email' => 'no',
            'show_phone' => 'no',
            'show_id' => 'no',
            'orderby' => 'registration_date',
            'order' => 'DESC'
        ], $atts);
        
        global $wpdb;
        
        $limit = intval($atts['limit']);
        $orderby = sanitize_sql_orderby($atts['orderby'] . ' ' . $atts['order']);
        
        if (!$orderby) {
            $orderby = 'registration_date DESC';
        }
        
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE status = 'active' ORDER BY {$orderby} LIMIT %d",
            $limit
        ));
        
        if (empty($members)) {
            return '<p>' . __('No members found.', PPM_TEXT_DOMAIN) . '</p>';
        }
        
        ob_start();
        ?>
        <div class="ppm-members-list">
            <table class="ppm-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Member Number', PPM_TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('Full Name', PPM_TEXT_DOMAIN); ?></th>
                        <?php if ($atts['show_email'] === 'yes'): ?>
                        <th><?php esc_html_e('Email', PPM_TEXT_DOMAIN); ?></th>
                        <?php endif; ?>
                        <?php if ($atts['show_phone'] === 'yes'): ?>
                        <th><?php esc_html_e('Phone', PPM_TEXT_DOMAIN); ?></th>
                        <?php endif; ?>
                        <?php if ($atts['show_id'] === 'yes'): ?>
                        <th><?php esc_html_e('ID Number', PPM_TEXT_DOMAIN); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Registration Date', PPM_TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?php echo esc_html($member->member_number); ?></td>
                        <td><?php echo esc_html($member->full_name); ?></td>
                        <?php if ($atts['show_email'] === 'yes'): ?>
                        <td><?php echo esc_html($member->email); ?></td>
                        <?php endif; ?>
                        <?php if ($atts['show_phone'] === 'yes'): ?>
                        <td><?php echo esc_html($member->phone); ?></td>
                        <?php endif; ?>
                        <?php if ($atts['show_id'] === 'yes'): ?>
                        <td><?php echo esc_html($member->kenyan_id); ?></td>
                        <?php endif; ?>
                        <td><?php echo esc_html(wp_date('M j, Y', strtotime($member->registration_date))); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .ppm-members-list {
            margin: 20px 0;
            overflow-x: auto;
        }
        .ppm-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .ppm-table th,
        .ppm-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .ppm-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        .ppm-table tr:hover {
            background: #f5f5f5;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Membership stats shortcode
     */
    public function membership_stats_shortcode($atts): string {
        $atts = shortcode_atts([
            'show_total' => 'yes',
            'show_today' => 'yes',
            'show_this_month' => 'yes',
            'show_this_year' => 'yes'
        ], $atts);
        
        global $wpdb;
        
        $stats = [];
        
        if ($atts['show_total'] === 'yes') {
            $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'active'");
        }
        
        if ($atts['show_today'] === 'yes') {
            $today = wp_date('Y-m-d');
            $stats['today'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'active' AND DATE(registration_date) = %s",
                $today
            ));
        }
        
        if ($atts['show_this_month'] === 'yes') {
            $month = wp_date('Y-m');
            $stats['month'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'active' AND DATE_FORMAT(registration_date, '%%Y-%%m') = %s",
                $month
            ));
        }
        
        if ($atts['show_this_year'] === 'yes') {
            $year = wp_date('Y');
            $stats['year'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'active' AND YEAR(registration_date) = %s",
                $year
            ));
        }
        
        ob_start();
        ?>
        <div class="ppm-stats">
            <div class="ppm-stats-grid">
                <?php if (isset($stats['total'])): ?>
                <div class="ppm-stat-card">
                    <h3><?php echo esc_html($stats['total']); ?></h3>
                    <p><?php esc_html_e('Total Members', PPM_TEXT_DOMAIN); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($stats['today'])): ?>
                <div class="ppm-stat-card">
                    <h3><?php echo esc_html($stats['today']); ?></h3>
                    <p><?php esc_html_e('New Today', PPM_TEXT_DOMAIN); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($stats['month'])): ?>
                <div class="ppm-stat-card">
                    <h3><?php echo esc_html($stats['month']); ?></h3>
                    <p><?php esc_html_e('This Month', PPM_TEXT_DOMAIN); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($stats['year'])): ?>
                <div class="ppm-stat-card">
                    <h3><?php echo esc_html($stats['year']); ?></h3>
                    <p><?php esc_html_e('This Year', PPM_TEXT_DOMAIN); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .ppm-stats {
            margin: 20px 0;
        }
        .ppm-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .ppm-stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .ppm-stat-card h3 {
            font-size: 2.5em;
            margin: 0 0 10px 0;
            font-weight: bold;
        }
        .ppm-stat-card p {
            margin: 0;
            font-size: 1.1em;
            opacity: 0.9;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Membership checker only shortcode
     */
    public function membership_checker_shortcode($atts): string {
        ob_start();
        ?>
        <div id="membership-checker" class="ppm-form-container ppm-checker">
            <h3><?php esc_html_e('Check Your Membership', PPM_TEXT_DOMAIN); ?></h3>
            <p><?php esc_html_e('Enter your Kenyan ID number to check your membership details.', PPM_TEXT_DOMAIN); ?></p>
            
            <form id="membership-check-form" class="ppm-form">
                <div class="ppm-form-group">
                    <label for="check_kenyan_id"><?php esc_html_e('Kenyan ID Number', PPM_TEXT_DOMAIN); ?></label>
                    <input type="text" id="check_kenyan_id" name="check_kenyan_id" 
                           placeholder="<?php esc_attr_e('Enter your National ID number', PPM_TEXT_DOMAIN); ?>" 
                           maxlength="8" pattern="[0-9]{7,8}">
                </div>
                
                <button type="submit" id="check-membership" class="ppm-button ppm-button-secondary">
                    <?php esc_html_e('Check Membership', PPM_TEXT_DOMAIN); ?>
                </button>
                
                <div id="membership-check-message" class="ppm-message" style="display:none;"></div>
            </form>
        </div>
        
        <style>
        .ppm-form-container {
            max-width: 500px;
            margin: 20px auto;
            padding: 30px;
            background: #ffffff;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .ppm-checker {
            background: #f8f9fa;
            border-color: #dee2e6;
        }
        
        .ppm-form-container h3 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 24px;
        }
        
        .ppm-checker p {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }
        
        .ppm-form-group {
            margin-bottom: 20px;
        }
        
        .ppm-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .ppm-form-group input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        
        .ppm-form-group input:focus {
            border-color: #0073aa;
            outline: none;
        }
        
        .ppm-button {
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s;
        }
        
        .ppm-button-secondary {
            background: #28a745;
            color: white;
        }
        
        .ppm-button:hover {
            opacity: 0.9;
        }
        
        .ppm-button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .ppm-message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
        }
        
        .ppm-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .ppm-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkForm = document.getElementById('membership-check-form');
            const checkIdInput = document.getElementById('check_kenyan_id');
            
            // Format ID input - only numbers
            if (checkIdInput) {
                checkIdInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
            
            // Check form submission
            if (checkForm) {
                checkForm.addEventListener('submit', handleCheckSubmit);
            }
            
            function handleCheckSubmit(e) {
                e.preventDefault();
                
                const checkId = document.getElementById('check_kenyan_id').value.trim();
                const messageDiv = document.getElementById('membership-check-message');
                const submitButton = document.getElementById('check-membership');
                
                if (!checkId) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter your ID number', PPM_TEXT_DOMAIN); ?>');
                    return;
                }
                
                if (checkId.length < 7 || checkId.length > 8) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter a valid Kenyan ID number (7-8 digits)', PPM_TEXT_DOMAIN); ?>');
                    return;
                }
                
                const ajaxData = new FormData();
                ajaxData.append('action', 'check_membership');
                ajaxData.append('nonce', party_ajax.nonce);
                ajaxData.append('kenyan_id', checkId);
                
                submitButton.disabled = true;
                submitButton.textContent = '<?php esc_html_e('Checking...', PPM_TEXT_DOMAIN); ?>';
                
                fetch(party_ajax.ajax_url, {
                    method: 'POST',
                    body: ajaxData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const member = data.data;
                        const memberInfo = `
                            <h4><?php esc_html_e('Membership Found!', PPM_TEXT_DOMAIN); ?></h4>
                            <p><strong><?php esc_html_e('Member Number:', PPM_TEXT_DOMAIN); ?></strong> ${member.member_number}</p>
                            <p><strong><?php esc_html_e('Full Name:', PPM_TEXT_DOMAIN); ?></strong> ${member.full_name}</p>
                            <p><strong><?php esc_html_e('Email:', PPM_TEXT_DOMAIN); ?></strong> ${member.email}</p>
                            <p><strong><?php esc_html_e('Phone:', PPM_TEXT_DOMAIN); ?></strong> ${member.phone}</p>
                            <p><strong><?php esc_html_e('Registration Date:', PPM_TEXT_DOMAIN); ?></strong> ${member.registration_date}</p>
                            <p><strong><?php esc_html_e('Status:', PPM_TEXT_DOMAIN); ?></strong> ${member.status}</p>
                        `;
                        showMessage(messageDiv, 'success', memberInfo);
                    } else {
                        showMessage(messageDiv, 'error', data.data || '<?php esc_html_e('No membership found for this ID number.', PPM_TEXT_DOMAIN); ?>');
                    }
                })
                .catch(error => {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('An error occurred while checking membership.', PPM_TEXT_DOMAIN); ?>');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.textContent = '<?php esc_html_e('Check Membership', PPM_TEXT_DOMAIN); ?>';
                });
            }
            
            function showMessage(element, type, message) {
                element.className = `pmp-message ppm-${type}`;
                element.innerHTML = message;
                element.style.display = 'block';
                
                // Auto-hide success messages after 5 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        element.style.display = 'none';
                    }, 5000);
                }
            }
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    public function membership_form_shortcode($atts): string {
        $atts = shortcode_atts([
            'show_checker' => 'yes'
        ], $atts);
        
        ob_start();
        
        // Include template file if it exists, otherwise use inline template
        $template_file = PPM_PLUGIN_DIR . 'templates/membership-form.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            $this->render_membership_form($atts);
        }
        
        return ob_get_clean();
    }
    
    /**
     * Render membership form template
     */
    private function render_membership_form(array $atts): void {
        ?>
        <div id="party-membership-form" class="ppm-form-container">
            <form id="membership-registration-form" class="ppm-form">
                <?php wp_nonce_field('party_membership_nonce', 'membership_nonce'); ?>
                
                <div class="ppm-form-group">
                    <label for="full_name"><?php esc_html_e('Full Name', PPM_TEXT_DOMAIN); ?> *</label>
                    <input type="text" id="full_name" name="full_name" required 
                           placeholder="<?php esc_attr_e('Enter your full name as it appears on your ID', PPM_TEXT_DOMAIN); ?>">
                </div>
                
                <div class="ppm-form-group">
                    <label for="email"><?php esc_html_e('Email Address', PPM_TEXT_DOMAIN); ?> *</label>
                    <input type="email" id="email" name="email" required 
                           placeholder="<?php esc_attr_e('Enter your email address', PPM_TEXT_DOMAIN); ?>">
                </div>
                
                <div class="ppm-form-group">
                    <label for="phone"><?php esc_html_e('Phone Number', PPM_TEXT_DOMAIN); ?> *</label>
                    <input type="tel" id="phone" name="phone" required 
                           placeholder="<?php esc_attr_e('e.g., 0712345678 or +254712345678', PPM_TEXT_DOMAIN); ?>">
                </div>
                
                <div class="ppm-form-group">
                    <label for="kenyan_id"><?php esc_html_e('Kenyan ID Number', PPM_TEXT_DOMAIN); ?> *</label>
                    <input type="text" id="kenyan_id" name="kenyan_id" required 
                           placeholder="<?php esc_attr_e('Enter your National ID number', PPM_TEXT_DOMAIN); ?>" 
                           maxlength="8" pattern="[0-9]{7,8}">
                    <small><?php esc_html_e('Enter your National ID number (numbers only)', PPM_TEXT_DOMAIN); ?></small>
                </div>
                
                <div class="ppm-form-group">
                    <label>
                        <input type="checkbox" id="terms_agreement" name="terms_agreement" required>
                        <?php esc_html_e('I agree to the terms and conditions of party membership', PPM_TEXT_DOMAIN); ?>
                    </label>
                </div>
                
                <button type="submit" id="submit-membership" class="ppm-button ppm-button-primary">
                    <?php esc_html_e('Register as Member', PPM_TEXT_DOMAIN); ?>
                </button>
                
                <div id="membership-message" class="ppm-message" style="display:none;"></div>
            </form>
        </div>
        
        <?php if ($atts['show_checker'] === 'yes'): ?>
        <div id="membership-checker" class="ppm-form-container ppm-checker">
            <h3><?php esc_html_e('Check Your Membership', PPM_TEXT_DOMAIN); ?></h3>
            <p><?php esc_html_e('Already a member? Enter your Kenyan ID number to check your membership details.', PPM_TEXT_DOMAIN); ?></p>
            
            <form id="membership-check-form" class="ppm-form">
                <div class="ppm-form-group">
                    <label for="check_kenyan_id"><?php esc_html_e('Kenyan ID Number', PPM_TEXT_DOMAIN); ?></label>
                    <input type="text" id="check_kenyan_id" name="check_kenyan_id" 
                           placeholder="<?php esc_attr_e('Enter your National ID number', PPM_TEXT_DOMAIN); ?>" 
                           maxlength="8" pattern="[0-9]{7,8}">
                </div>
                
                <button type="submit" id="check-membership" class="ppm-button ppm-button-secondary">
                    <?php esc_html_e('Check Membership', PPM_TEXT_DOMAIN); ?>
                </button>
                
                <div id="membership-check-message" class="ppm-message" style="display:none;"></div>
            </form>
        </div>
        <?php endif; ?>
        
        <style>
        .ppm-form-container {
            max-width: 500px;
            margin: 20px auto;
            padding: 30px;
            background: #ffffff;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .ppm-checker {
            background: #f8f9fa;
            border-color: #dee2e6;
            margin-top: 40px;
        }
        
        .ppm-form-container h3 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 24px;
        }
        
        .ppm-checker p {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }
        
        .ppm-form-group {
            margin-bottom: 20px;
        }
        
        .ppm-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .ppm-form-group input[type="text"],
        .ppm-form-group input[type="email"],
        .ppm-form-group input[type="tel"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s;
            font-family: inherit;
        }
        
        .ppm-form-group input:focus {
            border-color: #0073aa;
            outline: none;
            box-shadow: 0 0 0 1px #0073aa;
        }
        
        .ppm-form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 14px;
        }
        
        .ppm-form-group input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }
        
        .ppm-button {
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-family: inherit;
        }
        
        .ppm-button-primary {
            background: linear-gradient(135deg, #0073aa 0%, #005177 100%);
            color: white;
        }
        
        .ppm-button-secondary {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
        }
        
        .ppm-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .ppm-button:disabled {
            background: #ccc !important;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .ppm-message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .ppm-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .ppm-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .ppm-loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0073aa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive design */
        @media (max-width: 600px) {
            .ppm-form-container {
                margin: 10px;
                padding: 20px;
                max-width: none;
            }
            
            .ppm-form-container h3 {
                font-size: 20px;
            }
            
            .ppm-button {
                font-size: 16px;
                padding: 12px 24px;
            }
        }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation and AJAX handling
            const registrationForm = document.getElementById('membership-registration-form');
            const checkForm = document.getElementById('membership-check-form');
            
            // Format ID inputs - only numbers
            document.querySelectorAll('#kenyan_id, #check_kenyan_id').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            });
            
            // Format phone number
            const phoneInput = document.getElementById('phone');
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9+]/g, '');
                });
            }
            
            // Registration form submission
            if (registrationForm) {
                registrationForm.addEventListener('submit', handleRegistrationSubmit);
            }
            
            // Check form submission
            if (checkForm) {
                checkForm.addEventListener('submit', handleCheckSubmit);
            }
            
            function handleRegistrationSubmit(e) {
                e.preventDefault();
                
                const formData = new FormData(registrationForm);
                const messageDiv = document.getElementById('membership-message');
                const submitButton = document.getElementById('submit-membership');
                
                // Basic validation
                if (!validateRegistrationForm(formData, messageDiv)) {
                    return;
                }
                
                // Prepare AJAX data
                const ajaxData = new FormData();
                ajaxData.append('action', 'register_member');
                ajaxData.append('nonce', party_ajax.nonce);
                
                formData.forEach((value, key) => {
                    ajaxData.append(key, value);
                });
                
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="ppm-loading"></span><?php esc_html_e('Registering...', PPM_TEXT_DOMAIN); ?>';
                
                fetch(party_ajax.ajax_url, {
                    method: 'POST',
                    body: ajaxData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(messageDiv, 'success', 
                            '<?php esc_html_e('Registration successful! Your member number is:', PPM_TEXT_DOMAIN); ?> <strong>' + 
                            data.data.member_number + '</strong><br><?php esc_html_e('Welcome to our political party!', PPM_TEXT_DOMAIN); ?>');
                        registrationForm.reset();
                    } else {
                        showMessage(messageDiv, 'error', data.data || '<?php esc_html_e('Registration failed. Please try again.', PPM_TEXT_DOMAIN); ?>');
                    }
                })
                .catch(error => {
                    console.error('Registration error:', error);
                    showMessage(messageDiv, 'error', '<?php esc_html_e('An error occurred. Please try again.', PPM_TEXT_DOMAIN); ?>');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<?php esc_html_e('Register as Member', PPM_TEXT_DOMAIN); ?>';
                });
            }
            
            function handleCheckSubmit(e) {
                e.preventDefault();
                
                const checkId = document.getElementById('check_kenyan_id').value.trim();
                const messageDiv = document.getElementById('membership-check-message');
                const submitButton = document.getElementById('check-membership');
                
                if (!checkId) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter your ID number', PPM_TEXT_DOMAIN); ?>');
                    return;
                }
                
                if (checkId.length < 7 || checkId.length > 8) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter a valid Kenyan ID number (7-8 digits)', PPM_TEXT_DOMAIN); ?>');
                    return;
                }
                
                const ajaxData = new FormData();
                ajaxData.append('action', 'check_membership');
                ajaxData.append('nonce', party_ajax.nonce);
                ajaxData.append('kenyan_id', checkId);
                
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="ppm-loading"></span><?php esc_html_e('Checking...', PPM_TEXT_DOMAIN); ?>';
                
                fetch(party_ajax.ajax_url, {
                    method: 'POST',
                    body: ajaxData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const member = data.data;
                        const memberInfo = `
                            <h4><?php esc_html_e('Membership Found!', PPM_TEXT_DOMAIN); ?></h4>
                            <p><strong><?php esc_html_e('Member Number:', PPM_TEXT_DOMAIN); ?></strong> ${member.member_number}</p>
                            <p><strong><?php esc_html_e('Full Name:', PPM_TEXT_DOMAIN); ?></strong> ${member.full_name}</p>
                            <p><strong><?php esc_html_e('Email:', PPM_TEXT_DOMAIN); ?></strong> ${member.email}</p>
                            <p><strong><?php esc_html_e('Phone:', PPM_TEXT_DOMAIN); ?></strong> ${member.phone}</p>
                            <p><strong><?php esc_html_e('Registration Date:', PPM_TEXT_DOMAIN); ?></strong> ${member.registration_date}</p>
                            <p><strong><?php esc_html_e('Status:', PPM_TEXT_DOMAIN); ?></strong> ${member.status}</p>
                        `;
                        showMessage(messageDiv, 'success', memberInfo);
                    } else {
                        showMessage(messageDiv, 'error', data.data || '<?php esc_html_e('No membership found for this ID number.', PPM_TEXT_DOMAIN); ?>');
                    }
                })
                .catch(error => {
                    console.error('Check error:', error);
                    showMessage(messageDiv, 'error', '<?php esc_html_e('An error occurred while checking membership.', PPM_TEXT_DOMAIN); ?>');
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<?php esc_html_e('Check Membership', PPM_TEXT_DOMAIN); ?>';
                });
            }
            
            function validateRegistrationForm(formData, messageDiv) {
                const fullName = formData.get('full_name')?.trim();
                const email = formData.get('email')?.trim();
                const phone = formData.get('phone')?.trim();
                const kenyanId = formData.get('kenyan_id')?.trim();
                const termsAgreed = formData.get('terms_agreement');
                
                if (!fullName || !email || !phone || !kenyanId) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please fill in all required fields', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                
                if (kenyanId.length < 7 || kenyanId.length > 8) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter a valid Kenyan ID number (7-8 digits)', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                
                if (phone.length < 10) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter a valid phone number', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                
                // Basic email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please enter a valid email address', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                
                if (!termsAgreed) {
                    showMessage(messageDiv, 'error', '<?php esc_html_e('Please agree to the terms and conditions', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                
                return true;
            }
            
            function showMessage(element, type, message) {
                element.className = `ppm-message ppm-${type}`;
                element.innerHTML = message;
                element.style.display = 'block';
                
                // Scroll to message
                element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                
                // Auto-hide success messages after 8 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        element.style.display = 'none';
                    }, 8000);
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Handle member registration
     */
    public function handle_registration(): void {
        // Clean any previous output
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Check if this is an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error(__('Invalid request method', PPM_TEXT_DOMAIN));
        }
        
        // Verify nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? $_POST['membership_nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'party_membership_nonce')) {
            wp_send_json_error(__('Security check failed', PPM_TEXT_DOMAIN));
        }
        
        // Sanitize input data
        $full_name = sanitize_text_field($_POST['full_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $kenyan_id = sanitize_text_field($_POST['kenyan_id'] ?? '');
        
        // Validate required fields
        if (empty($full_name) || empty($email) || empty($phone) || empty($kenyan_id)) {
            wp_send_json_error(__('Please fill in all required fields.', PPM_TEXT_DOMAIN));
        }
        
        // Validate Kenyan ID
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            wp_send_json_error(__('Please enter a valid Kenyan ID number (7-8 digits only).', PPM_TEXT_DOMAIN));
        }
        
        // Validate email
        if (!is_email($email)) {
            wp_send_json_error(__('Please enter a valid email address.', PPM_TEXT_DOMAIN));
        }
        
        // Validate phone number
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen($phone) < 10) {
            wp_send_json_error(__('Please enter a valid phone number.', PPM_TEXT_DOMAIN));
        }
        
        global $wpdb;
        
        // Check for existing email
        $existing_email = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE email = %s",
            $email
        ));
        
        if ($existing_email) {
            wp_send_json_error(__('This email address is already registered.', PPM_TEXT_DOMAIN));
        }
        
        // Check for existing Kenyan ID
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($existing_id) {
            wp_send_json_error(__('This ID number is already registered. Each ID number can only be used once.', PPM_TEXT_DOMAIN));
        }
        
        // Generate member number
        $member_number = $this->generate_member_number();
        
        // Insert member data
        $result = $wpdb->insert(
            $this->table_name,
            [
                'member_number' => $member_number,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'kenyan_id' => $kenyan_id,
                'registration_date' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            error_log('PPM Database insert failed: ' . $wpdb->last_error);
            wp_send_json_error(__('Database error occurred. Please try again.', PPM_TEXT_DOMAIN));
        }
        
        if ($result) {
            wp_send_json_success([
                'member_number' => $member_number,
                'message' => __('Registration successful!', PPM_TEXT_DOMAIN)
            ]);
        } else {
            wp_send_json_error(__('Registration failed. Please try again.', PPM_TEXT_DOMAIN));
        }
    }
    
    /**
     * Generate unique member number
     */
    private function generate_member_number(): string {
        $counter = get_option('party_member_counter', 0);
        $counter++;
        update_option('party_member_counter', $counter);
        
        return 'NVP-' . str_pad($counter, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Admin page for managing members
     */
    public function admin_page(): void {
        global $wpdb;
        
        // Handle delete actions
        if (isset($_POST['action'], $_POST['party_members_nonce']) && 
            wp_verify_nonce($_POST['party_members_nonce'], 'party_members_action')) {
            
            if ($_POST['action'] === 'delete_member' && isset($_POST['member_id'])) {
                $this->handle_delete_member();
            } elseif ($_POST['action'] === 'bulk_delete' && isset($_POST['member_ids']) && is_array($_POST['member_ids'])) {
                $this->handle_bulk_delete();
            }
        }
        
        // Handle search
        $search = sanitize_text_field($_GET['s'] ?? '');
        $where_clause = '';
        $search_params = [];
        
        if ($search) {
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_clause = " WHERE full_name LIKE %s OR email LIKE %s OR member_number LIKE %s OR kenyan_id LIKE %s";
            $search_params = [$search_term, $search_term, $search_term, $search_term];
        }
        
        // Pagination
        $per_page = 20;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        if ($search) {
            $total_members = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}" . $where_clause,
                ...$search_params
            ));
        } else {
            $total_members = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        }
        
        $total_pages = ceil($total_members / $per_page);
        
        // Get members
        if ($search) {
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}" . $where_clause . " ORDER BY registration_date DESC LIMIT %d OFFSET %d",
                ...array_merge($search_params, [$per_page, $offset])
            ));
        } else {
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY registration_date DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ));
        }
        
        $this->render_admin_page($members, $total_members, $total_pages, $current_page, $search);
    }
    
    /**
     * Render admin page HTML
     */
    private function render_admin_page(array $members, int $total_members, int $total_pages, int $current_page, string $search): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Party Members', PPM_TEXT_DOMAIN); ?></h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" style="display: inline-block;">
                        <input type="hidden" name="page" value="party-members">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Search members...', PPM_TEXT_DOMAIN); ?>">
                        <input type="submit" class="button" value="<?php esc_attr_e('Search', PPM_TEXT_DOMAIN); ?>">
                        <?php if ($search): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=party-members')); ?>" class="button">
                                <?php esc_html_e('Clear', PPM_TEXT_DOMAIN); ?>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="alignright">
                    <strong><?php printf(esc_html__('Total Members: %d', PPM_TEXT_DOMAIN), $total_members); ?></strong>
                </div>
            </div>
            
            <form method="post" id="members-table-form">
                <?php wp_nonce_field('party_members_action', 'party_members_nonce'); ?>
                
                <?php if (!empty($members)): ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk Actions', PPM_TEXT_DOMAIN); ?></option>
                            <option value="bulk_delete"><?php esc_html_e('Delete Selected', PPM_TEXT_DOMAIN); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php esc_attr_e('Apply', PPM_TEXT_DOMAIN); ?>" 
                               onclick="return handleBulkAction();">
                    </div>
                </div>
                <?php endif; ?>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <?php if (!empty($members)): ?>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <?php endif; ?>
                            <th><?php esc_html_e('Member Number', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Full Name', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Email', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Phone', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Kenyan ID', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Registration Date', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Status', PPM_TEXT_DOMAIN); ?></th>
                            <th><?php esc_html_e('Actions', PPM_TEXT_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($members)): ?>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="member_ids[]" value="<?php echo esc_attr($member->id); ?>">
                                    </th>
                                    <td><strong><?php echo esc_html($member->member_number); ?></strong></td>
                                    <td><?php echo esc_html($member->full_name); ?></td>
                                    <td><a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a></td>
                                    <td><a href="tel:<?php echo esc_attr($member->phone); ?>"><?php echo esc_html($member->phone); ?></a></td>
                                    <td><?php echo esc_html($member->kenyan_id); ?></td>
                                    <td><?php echo esc_html(wp_date('M j, Y', strtotime($member->registration_date))); ?></td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($member->status); ?>">
                                            <?php echo esc_html(ucfirst($member->status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;" 
                                              onsubmit="return confirmDelete('<?php echo esc_js($member->full_name); ?>');">
                                            <?php wp_nonce_field('party_members_action', 'party_members_nonce'); ?>
                                            <input type="hidden" name="action" value="delete_member">
                                            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                            <input type="submit" class="button button-small button-link-delete" 
                                                   value="<?php esc_attr_e('Delete', PPM_TEXT_DOMAIN); ?>">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">
                                    <?php if ($search): ?>
                                        <?php printf(esc_html__('No members found matching "%s".', PPM_TEXT_DOMAIN), esc_html($search)); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('No members found.', PPM_TEXT_DOMAIN); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ]);
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Select all checkbox functionality
            const selectAllCheckbox = document.getElementById('cb-select-all-1');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('input[name="member_ids[]"]');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
        });
        
        // Confirm individual delete
        function confirmDelete(memberName) {
            return confirm('<?php esc_html_e('Are you sure you want to delete the member', PPM_TEXT_DOMAIN); ?> "' + memberName + '"? <?php esc_html_e('This action cannot be undone.', PPM_TEXT_DOMAIN); ?>');
        }
        
        // Handle bulk actions
        function handleBulkAction() {
            const action = document.getElementById('bulk-action-selector-top').value;
            
            if (action === '-1' || action === '') {
                alert('<?php esc_html_e('Please select an action.', PPM_TEXT_DOMAIN); ?>');
                return false;
            }
            
            if (action === 'bulk_delete') {
                const checkedBoxes = document.querySelectorAll('input[name="member_ids[]"]:checked');
                if (checkedBoxes.length === 0) {
                    alert('<?php esc_html_e('Please select at least one member to delete.', PPM_TEXT_DOMAIN); ?>');
                    return false;
                }
                return confirm('<?php esc_html_e('Are you sure you want to delete', PPM_TEXT_DOMAIN); ?> ' + checkedBoxes.length + ' <?php esc_html_e('selected member(s)? This action cannot be undone.', PPM_TEXT_DOMAIN); ?>');
            }
            
            return true;
        }
        </script>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-inactive { color: #dc3232; }
        .check-column { width: 2.2em; }
        .column-cb { width: 2.2em; }
        .button-link-delete { color: #dc3232; }
        .button-link-delete:hover { color: #a00; }
        </style>
        <?php
    }
    
    /**
     * Handle individual member deletion
     */
    private function handle_delete_member(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action', PPM_TEXT_DOMAIN));
        }
        
        $member_id = intval($_POST['member_id'] ?? 0);
        
        if ($member_id <= 0) {
            $this->add_admin_notice('error', __('Invalid member ID.', PPM_TEXT_DOMAIN));
            return;
        }
        
        global $wpdb;
        
        // Get member info before deletion
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name FROM {$this->table_name} WHERE id = %d",
            $member_id
        ));
        
        if (!$member) {
            $this->add_admin_notice('error', __('Member not found.', PPM_TEXT_DOMAIN));
            return;
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            ['id' => $member_id],
            ['%d']
        );
        
        if ($result !== false) {
            $this->add_admin_notice('success', 
                sprintf(__('Member "%s" has been successfully deleted.', PPM_TEXT_DOMAIN), 
                esc_html($member->full_name)));
        } else {
            $this->add_admin_notice('error', __('Failed to delete member. Please try again.', PPM_TEXT_DOMAIN));
        }
    }
    
    /**
     * Handle bulk deletion
     */
    private function handle_bulk_delete(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action', PPM_TEXT_DOMAIN));
        }
        
        $member_ids = array_map('intval', $_POST['member_ids'] ?? []);
        $member_ids = array_filter($member_ids, fn($id) => $id > 0);
        
        if (empty($member_ids)) {
            $this->add_admin_notice('error', __('No valid member IDs provided.', PPM_TEXT_DOMAIN));
            return;
        }
        
        global $wpdb;
        
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
        
        $deleted_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE id IN ($placeholders)",
            ...$member_ids
        ));
        
        if ($deleted_count !== false) {
            $this->add_admin_notice('success', 
                sprintf(_n('%d member has been successfully deleted.', '%d members have been successfully deleted.', 
                $deleted_count, PPM_TEXT_DOMAIN), $deleted_count));
        } else {
            $this->add_admin_notice('error', __('Failed to delete members. Please try again.', PPM_TEXT_DOMAIN));
        }
    }
    
    /**
     * Add admin notice
     */
    private function add_admin_notice(string $type, string $message): void {
        add_action('admin_notices', function() use ($type, $message) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', 
                esc_attr($type), esc_html($message));
        });
    }
    
    /**
     * Add member page
     */
    public function add_member_page(): void {
        if (isset($_POST['add_member'], $_POST['add_member_nonce']) && 
            wp_verify_nonce($_POST['add_member_nonce'], 'add_member_admin')) {
            $this->handle_admin_registration();
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Add New Member', PPM_TEXT_DOMAIN); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('add_member_admin', 'add_member_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="full_name"><?php esc_html_e('Full Name', PPM_TEXT_DOMAIN); ?> *</label></th>
                        <td><input type="text" id="full_name" name="full_name" required class="regular-text" value="<?php echo esc_attr($_POST['full_name'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="email"><?php esc_html_e('Email Address', PPM_TEXT_DOMAIN); ?> *</label></th>
                        <td><input type="email" id="email" name="email" required class="regular-text" value="<?php echo esc_attr($_POST['email'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="phone"><?php esc_html_e('Phone Number', PPM_TEXT_DOMAIN); ?> *</label></th>
                        <td><input type="tel" id="phone" name="phone" required class="regular-text" value="<?php echo esc_attr($_POST['phone'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="kenyan_id"><?php esc_html_e('Kenyan ID Number', PPM_TEXT_DOMAIN); ?> *</label></th>
                        <td>
                            <input type="text" id="kenyan_id" name="kenyan_id" required class="regular-text" 
                                   maxlength="8" pattern="[0-9]{7,8}" value="<?php echo esc_attr($_POST['kenyan_id'] ?? ''); ?>">
                            <p class="description"><?php esc_html_e('Enter National ID number (7-8 digits only)', PPM_TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="add_member" class="button-primary" 
                           value="<?php esc_attr_e('Add Member', PPM_TEXT_DOMAIN); ?>">
                </p>
            </form>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const kenyanIdInput = document.getElementById('kenyan_id');
            if (kenyanIdInput) {
                kenyanIdInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Handle admin registration
     */
    private function handle_admin_registration(): void {
        $full_name = sanitize_text_field($_POST['full_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $kenyan_id = sanitize_text_field($_POST['kenyan_id'] ?? '');
        
        if (empty($full_name) || empty($email) || empty($phone) || empty($kenyan_id)) {
            $this->add_admin_notice('error', __('Please fill in all required fields.', PPM_TEXT_DOMAIN));
            return;
        }
        
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            $this->add_admin_notice('error', __('Please enter a valid Kenyan ID number (7-8 digits only).', PPM_TEXT_DOMAIN));
            return;
        }
        
        if (!is_email($email)) {
            $this->add_admin_notice('error', __('Please enter a valid email address.', PPM_TEXT_DOMAIN));
            return;
        }
        
        global $wpdb;
        
        // Check for existing email
        $existing_email = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE email = %s",
            $email
        ));
        
        if ($existing_email) {
            $this->add_admin_notice('error', __('This email address is already registered.', PPM_TEXT_DOMAIN));
            return;
        }
        
        // Check for existing ID
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($existing_id) {
            $this->add_admin_notice('error', __('This ID number is already registered.', PPM_TEXT_DOMAIN));
            return;
        }
        
        $member_number = $this->generate_member_number();
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'member_number' => $member_number,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'kenyan_id' => $kenyan_id,
                'registration_date' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            $this->add_admin_notice('success', 
                sprintf(__('Member successfully added with number: %s', PPM_TEXT_DOMAIN), 
                '<strong>' . esc_html($member_number) . '</strong>'));
                
            // Clear form data on success
            $_POST = [];
        } else {
            $this->add_admin_notice('error', __('Failed to add member. Please try again.', PPM_TEXT_DOMAIN));
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    PoliticalPartyMembership::get_instance();
});

// Manual activation function for database creation
register_activation_hook(__FILE__, function() {
    $plugin = PoliticalPartyMembership::get_instance();
    $plugin->activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    $plugin = PoliticalPartyMembership::get_instance();
    $plugin->deactivate();
});

// Uninstall hook
register_uninstall_hook(__FILE__, ['PoliticalPartyMembershipUninstaller', 'uninstall']);

/**
 * Plugin uninstall cleanup
 */
class PoliticalPartyMembershipUninstaller {
    public static function uninstall(): void {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit;
        }
        
        global $wpdb;
        
        // Remove database table
        $table_name = $wpdb->prefix . 'party_members';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        
        // Remove options
        delete_option('party_member_counter');
        
        // Remove pages created by plugin
        $page = get_page_by_path('membership-registration');
        if ($page) {
            wp_delete_post($page->ID, true);
        }
        
        // Clear any cached data
        wp_cache_flush();
    }
}