<?php
/**
 * Plugin Name: Political Party Membership - Kenya
 * Description: Membership registration system for political parties in Kenya with ID number validation
 * Version: 1.0.6
 * Author: Chebon Kelvin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PoliticalPartyMembership {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function handle_membership_check() {
        // Prevent any output before JSON response
        @ob_clean();
        
        // Check if this is an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error('Invalid request method');
            wp_die();
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'party_membership_nonce')) {
            wp_send_json_error('Security check failed');
            wp_die();
        }
        
        // Sanitize input
        $kenyan_id = sanitize_text_field($_POST['kenyan_id']);
        
        // Validate required field
        if (empty($kenyan_id)) {
            wp_send_json_error('Please enter your ID number.');
            wp_die();
        }
        
        // Validate Kenyan ID format
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            wp_send_json_error('Please enter a valid Kenyan ID number (7-8 digits only).');
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        // Check if member exists
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($member) {
            // Format the registration date nicely
            $formatted_date = date('F j, Y', strtotime($member->registration_date));
            
            wp_send_json_success(array(
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'phone' => $member->phone,
                'registration_date' => $formatted_date,
                'status' => ucfirst($member->status)
            ));
        } else {
            wp_send_json_error('No membership found for this ID number. You may need to register first.');
        }
        
        wp_die();
    }
    
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'admin_menu'));
        
        // Handle form submissions
        add_action('wp_ajax_register_member', array($this, 'handle_registration'));
        add_action('wp_ajax_nopriv_register_member', array($this, 'handle_registration'));
        
        // Handle membership check
        add_action('wp_ajax_check_membership', array($this, 'handle_membership_check'));
        add_action('wp_ajax_nopriv_check_membership', array($this, 'handle_membership_check'));
        
        // Add shortcode
        add_shortcode('party_membership_form', array($this, 'membership_form_shortcode'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    public function activate() {
        $this->create_tables();
        $this->create_pages();
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'party_members';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
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
    
    private function create_pages() {
        // Create membership registration page
        $page_content = '[party_membership_form]';
        
        $page = array(
            'post_title' => 'Membership Registration',
            'post_content' => $page_content,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_slug' => 'membership-registration'
        );
        
        if (!get_page_by_path('membership-registration')) {
            wp_insert_post($page);
        }
    }
    
    public function admin_menu() {
        add_menu_page(
            'Party Members',
            'Party Members',
            'manage_options',
            'party-members',
            array($this, 'admin_page'),
            'dashicons-groups',
            30
        );
        
        add_submenu_page(
            'party-members',
            'All Members',
            'All Members',
            'manage_options',
            'party-members',
            array($this, 'admin_page')
        );
        
        add_submenu_page(
            'party-members',
            'Add New Member',
            'Add New Member',
            'manage_options',
            'party-members-add',
            array($this, 'add_member_page')
        );
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('party-membership-js', plugin_dir_url(__FILE__) . 'party-membership.js', array('jquery'), '1.0.0', true);
        wp_localize_script('party-membership-js', 'party_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('party_membership_nonce')
        ));
    }
    
    public function admin_enqueue_scripts() {
        // Admin styles if needed
    }
    
    public function membership_form_shortcode($atts) {
        ob_start();
        ?>
        <div id="party-membership-form">
            <form id="membership-registration-form">
                <?php wp_nonce_field('party_membership_nonce', 'membership_nonce'); ?>
                
                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name as it appears on your ID">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" required placeholder="e.g., 0712345678 or +254712345678">
                </div>
                
                <div class="form-group">
                    <label for="kenyan_id">Kenyan ID Number *</label>
                    <input type="text" id="kenyan_id" name="kenyan_id" required placeholder="Enter your National ID number" maxlength="20">
                    <small>Enter your National ID number (numbers only)</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="terms_agreement" name="terms_agreement" required>
                        I agree to the terms and conditions of party membership
                    </label>
                </div>
                
                <button type="submit" id="submit-membership">Register as Member</button>
                
                <div id="membership-message" style="display:none;"></div>
            </form>
        </div>
        
        <!-- Membership Checker Section -->
        <div id="membership-checker" style="margin-top: 40px;">
            <h3>Check Your Membership</h3>
            <p>Already a member? Enter your Kenyan ID number to check your membership details.</p>
            
            <form id="membership-check-form">
                <div class="form-group">
                    <label for="check_kenyan_id">Kenyan ID Number</label>
                    <input type="text" id="check_kenyan_id" name="check_kenyan_id" placeholder="Enter your National ID number" maxlength="8">
                </div>
                
                <button type="submit" id="check-membership">Check Membership</button>
                
                <div id="membership-check-message" style="display:none;"></div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Format Kenyan ID input - only numbers
            $('#kenyan_id').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            
            // Format phone number
            $('#phone').on('input', function() {
                var value = this.value.replace(/[^0-9+]/g, '');
                this.value = value;
            });
            
            $('#membership-registration-form').on('submit', function(e) {
                e.preventDefault();
                
                var kenyanId = $('#kenyan_id').val().trim();
                var phone = $('#phone').val().trim();
                var fullName = $('#full_name').val().trim();
                var email = $('#email').val().trim();
                
                // Clear previous messages
                $('#membership-message').hide();
                
                // Basic validation
                if (!fullName || !email || !phone || !kenyanId) {
                    $('#membership-message').html('<div class="error-message">Please fill in all required fields</div>').show();
                    return;
                }
                
                if (kenyanId.length < 7 || kenyanId.length > 8) {
                    $('#membership-message').html('<div class="error-message">Please enter a valid Kenyan ID number (7-8 digits)</div>').show();
                    return;
                }
                
                if (phone.length < 10) {
                    $('#membership-message').html('<div class="error-message">Please enter a valid phone number</div>').show();
                    return;
                }
                
                if (!$('#terms_agreement').is(':checked')) {
                    $('#membership-message').html('<div class="error-message">Please agree to the terms and conditions</div>').show();
                    return;
                }
                
                var formData = {
                    action: 'register_member',
                    nonce: party_ajax.nonce,
                    full_name: fullName,
                    email: email,
                    phone: phone,
                    kenyan_id: kenyanId,
                    terms_agreement: true
                };
                
                console.log('Sending data:', formData);
                
                $('#submit-membership').prop('disabled', true).text('Registering...');
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    timeout: 30000,
                    success: function(response) {
                        console.log('Response:', response);
                        if (response && response.success) {
                            $('#membership-message').html('<div class="success-message">Registration successful! Your member number is: <strong>' + response.data.member_number + '</strong><br>Welcome to our political party!</div>').show();
                            $('#membership-registration-form')[0].reset();
                        } else {
                            var errorMsg = response && response.data ? response.data : 'Registration failed. Please try again.';
                            $('#membership-message').html('<div class="error-message">Error: ' + errorMsg + '</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', xhr.responseText);
                        var errorMsg = 'An error occurred. Please try again.';
                        if (xhr.responseText) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.data) {
                                    errorMsg = response.data;
                                }
                            } catch(e) {
                                console.log('Could not parse error response');
                            }
                        }
                        $('#membership-message').html('<div class="error-message">' + errorMsg + '</div>').show();
                    },
                    complete: function() {
                        $('#submit-membership').prop('disabled', false).text('Register as Member');
                    }
                });
            });
            
            // Membership Check Form
            $('#check_kenyan_id').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            
            $('#membership-check-form').on('submit', function(e) {
                e.preventDefault();
                
                var checkId = $('#check_kenyan_id').val().trim();
                
                // Clear previous messages
                $('#membership-check-message').hide();
                
                // Basic validation
                if (!checkId) {
                    $('#membership-check-message').html('<div class="error-message">Please enter your ID number</div>').show();
                    return;
                }
                
                if (checkId.length < 7 || checkId.length > 8) {
                    $('#membership-check-message').html('<div class="error-message">Please enter a valid Kenyan ID number (7-8 digits)</div>').show();
                    return;
                }
                
                var formData = {
                    action: 'check_membership',
                    nonce: party_ajax.nonce,
                    kenyan_id: checkId
                };
                
                $('#check-membership').prop('disabled', true).text('Checking...');
                
                $.ajax({
                    url: party_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    timeout: 30000,
                    success: function(response) {
                        console.log('Check response:', response);
                        if (response && response.success) {
                            var member = response.data;
                            var memberInfo = '<div class="success-message">' +
                                '<h4>Membership Found!</h4>' +
                                '<p><strong>Member Number:</strong> ' + member.member_number + '</p>' +
                                '<p><strong>Full Name:</strong> ' + member.full_name + '</p>' +
                                '<p><strong>Email:</strong> ' + member.email + '</p>' +
                                '<p><strong>Phone:</strong> ' + member.phone + '</p>' +
                                '<p><strong>Registration Date:</strong> ' + member.registration_date + '</p>' +
                                '<p><strong>Status:</strong> ' + member.status + '</p>' +
                                '</div>';
                            $('#membership-check-message').html(memberInfo).show();
                        } else {
                            var errorMsg = response && response.data ? response.data : 'No membership found for this ID number.';
                            $('#membership-check-message').html('<div class="error-message">' + errorMsg + '</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('Check Error:', xhr.responseText);
                        $('#membership-check-message').html('<div class="error-message">An error occurred while checking membership.</div>').show();
                    },
                    complete: function() {
                        $('#check-membership').prop('disabled', false).text('Check Membership');
                    }
                });
            });
        });
        </script>
        
        <style>
        #party-membership-form,
        #membership-checker {
            max-width: 500px;
            margin: 20px auto;
            padding: 30px;
            background: #ffffff;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        #party-membership-form h3,
        #membership-checker h3 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 24px;
        }
        
        #membership-checker {
            background: #f8f9fa;
            border-color: #dee2e6;
        }
        
        #membership-checker p {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            border-color: #0073aa;
            outline: none;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 14px;
        }
        
        .form-group input[type="checkbox"] {
            margin-right: 8px;
        }
        
        button[type="submit"] {
            background: #0073aa;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s;
        }
        
        #check-membership {
            background: #28a745;
        }
        
        button[type="submit"]:hover {
            background: #005a87;
        }
        
        #check-membership:hover {
            background: #218838;
        }
        
        button[type="submit"]:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            border: 1px solid #f5c6cb;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    public function handle_registration() {
        // Prevent any output before JSON response
        @ob_clean();
        
        // Enable error reporting for debugging
        if (WP_DEBUG) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        
        // Check if this is an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error('Invalid request method');
            wp_die();
        }
        
        // Verify nonce - check both possible nonce field names
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['membership_nonce']) ? $_POST['membership_nonce'] : '');
        
        if (!wp_verify_nonce($nonce, 'party_membership_nonce')) {
            wp_send_json_error('Security check failed');
            wp_die();
        }
        
        // Sanitize input data
        $full_name = sanitize_text_field($_POST['full_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $kenyan_id = sanitize_text_field($_POST['kenyan_id']);
        
        // Validate required fields
        if (empty($full_name) || empty($email) || empty($phone) || empty($kenyan_id)) {
            wp_send_json_error('Please fill in all required fields.');
            wp_die();
        }
        
        // Validate Kenyan ID (7-8 digits)
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            wp_send_json_error('Please enter a valid Kenyan ID number (7-8 digits only).');
            wp_die();
        }
        
        // Validate email
        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
            wp_die();
        }
        
        // Validate phone number
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen($phone) < 10) {
            wp_send_json_error('Please enter a valid phone number.');
            wp_die();
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        // Check if email already exists
        $existing_email = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE email = %s",
            $email
        ));
        
        if ($existing_email) {
            wp_send_json_error('This email address is already registered.');
            wp_die();
        }
        
        // Check if Kenyan ID already exists
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($existing_id) {
            wp_send_json_error('This ID number is already registered. Each ID number can only be used once.');
            wp_die();
        }
        
        // Generate member number
        $member_number = $this->generate_member_number();
        
        // Insert member data
        $result = $wpdb->insert(
            $table_name,
            array(
                'member_number' => $member_number,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'kenyan_id' => $kenyan_id,
                'registration_date' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('Database insert failed: ' . $wpdb->last_error);
            wp_send_json_error('Database error occurred. Please try again.');
            wp_die();
        }
        
        if ($result) {
            // Registration successful - no email sending
            wp_send_json_success(array(
                'member_number' => $member_number,
                'message' => 'Registration successful!'
            ));
            wp_die();
        } else {
            wp_send_json_error('Registration failed. Please try again.');
            wp_die();
        }
    }
    
    private function generate_member_number() {
        $counter = get_option('party_member_counter', 0);
        $counter++;
        update_option('party_member_counter', $counter);
        
        return 'NVP-' . $counter;
    }
    
    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        // Handle delete actions
        if (isset($_POST['action']) && $_POST['action'] === 'delete_member' && isset($_POST['member_id'])) {
            $this->handle_delete_member();
        }
        
        // Handle bulk delete - check for bulk_delete action
        if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['member_ids']) && is_array($_POST['member_ids'])) {
            $this->handle_bulk_delete();
        }
        
        // Handle search
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where_clause = '';
        if ($search) {
            $where_clause = $wpdb->prepare(
                " WHERE full_name LIKE %s OR email LIKE %s OR member_number LIKE %s OR kenyan_id LIKE %s",
                '%' . $search . '%',
                '%' . $search . '%',
                '%' . $search . '%',
                '%' . $search . '%'
            );
        }
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        $total_members = $wpdb->get_var("SELECT COUNT(*) FROM $table_name" . $where_clause);
        $total_pages = ceil($total_members / $per_page);
        
        $members = $wpdb->get_results(
            "SELECT * FROM $table_name" . $where_clause . " ORDER BY registration_date DESC LIMIT $per_page OFFSET $offset"
        );
        
        ?>
        <div class="wrap">
            <h1>Party Members</h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" style="display: inline-block;">
                        <input type="hidden" name="page" value="party-members">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search members...">
                        <input type="submit" class="button" value="Search">
                    </form>
                </div>
                <div class="alignright">
                    <strong>Total Members: <?php echo $total_members; ?></strong>
                </div>
            </div>
            
            <form method="post" id="members-table-form">
                <?php wp_nonce_field('party_members_action', 'party_members_nonce'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="action" id="bulk-action-selector-top">
                            <option value="-1">Bulk Actions</option>
                            <option value="bulk_delete">Delete Selected</option>
                        </select>
                        <input type="submit" class="button" value="Apply" onclick="return handleBulkAction();">
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </td>
                            <th>Member Number</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Kenyan ID</th>
                            <th>Registration Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($members): ?>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="member_ids[]" value="<?php echo esc_attr($member->id); ?>">
                                    </th>
                                    <td><strong><?php echo esc_html($member->member_number); ?></strong></td>
                                    <td><?php echo esc_html($member->full_name); ?></td>
                                    <td><?php echo esc_html($member->email); ?></td>
                                    <td><?php echo esc_html($member->phone); ?></td>
                                    <td><?php echo esc_html($member->kenyan_id); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($member->registration_date)); ?></td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($member->status); ?>">
                                            <?php echo ucfirst($member->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;" onsubmit="return confirmDelete('<?php echo esc_js($member->full_name); ?>');">
                                            <?php wp_nonce_field('party_members_action', 'party_members_nonce'); ?>
                                            <input type="hidden" name="action" value="delete_member">
                                            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                            <input type="submit" class="button button-small" value="Delete" style="color: #dc3232;">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">No members found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        // Select all checkbox functionality
        document.getElementById('cb-select-all-1').addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="member_ids[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = this.checked;
            }
        });
        
        // Confirm individual delete
        function confirmDelete(memberName) {
            return confirm('Are you sure you want to delete the member "' + memberName + '"? This action cannot be undone.');
        }
        
        // Handle bulk actions
        function handleBulkAction() {
            var action = document.getElementById('bulk-action-selector-top').value;
            
            if (action === '-1' || action === '') {
                alert('Please select an action.');
                return false;
            }
            
            if (action === 'bulk_delete') {
                var checkedBoxes = document.querySelectorAll('input[name="member_ids[]"]:checked');
                if (checkedBoxes.length === 0) {
                    alert('Please select at least one member to delete.');
                    return false;
                }
                return confirm('Are you sure you want to delete ' + checkedBoxes.length + ' selected member(s)? This action cannot be undone.');
            }
            
            return true;
        }
        </script>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-inactive { color: #dc3232; }
        .check-column { width: 2.2em; }
        .column-cb { width: 2.2em; }
        </style>
        <?php
    }
    
    // Handle individual member deletion
    private function handle_delete_member() {
        if (!wp_verify_nonce($_POST['party_members_nonce'], 'party_members_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action');
        }
        
        $member_id = intval($_POST['member_id']);
        
        if ($member_id <= 0) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid member ID.</p></div>';
            });
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        // Get member info before deletion for confirmation
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name FROM $table_name WHERE id = %d",
            $member_id
        ));
        
        if (!$member) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Member not found.</p></div>';
            });
            return;
        }
        
        $result = $wpdb->delete(
            $table_name,
            array('id' => $member_id),
            array('%d')
        );
        
        if ($result !== false) {
            add_action('admin_notices', function() use ($member) {
                echo '<div class="notice notice-success"><p>Member "' . esc_html($member->full_name) . '" has been successfully deleted.</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to delete member. Please try again.</p></div>';
            });
        }
    }
    
    // Handle bulk deletion
    private function handle_bulk_delete() {
        if (!wp_verify_nonce($_POST['party_members_nonce'], 'party_members_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action');
        }
        
        if (!isset($_POST['member_ids']) || !is_array($_POST['member_ids'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>No members selected for deletion.</p></div>';
            });
            return;
        }
        
        $member_ids = array_map('intval', $_POST['member_ids']);
        $member_ids = array_filter($member_ids, function($id) {
            return $id > 0;
        });
        
        if (empty($member_ids)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>No valid member IDs provided.</p></div>';
            });
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));
        
        $deleted_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE id IN ($placeholders)",
            $member_ids
        ));
        
        if ($deleted_count !== false) {
            add_action('admin_notices', function() use ($deleted_count) {
                echo '<div class="notice notice-success"><p>' . $deleted_count . ' member(s) have been successfully deleted.</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to delete members. Please try again.</p></div>';
            });
        }
    }
    
    public function add_member_page() {
        if (isset($_POST['add_member'])) {
            $this->handle_admin_registration();
        }
        
        ?>
        <div class="wrap">
            <h1>Add New Member</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('add_member_admin', 'add_member_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="full_name">Full Name *</label></th>
                        <td><input type="text" id="full_name" name="full_name" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="email">Email Address *</label></th>
                        <td><input type="email" id="email" name="email" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="phone">Phone Number *</label></th>
                        <td><input type="tel" id="phone" name="phone" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="kenyan_id">Kenyan ID Number *</label></th>
                        <td>
                            <input type="text" id="kenyan_id" name="kenyan_id" required class="regular-text" maxlength="8">
                            <p class="description">Enter National ID number (7-8 digits only)</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="add_member" class="button-primary" value="Add Member">
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#kenyan_id').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        });
        </script>
        <?php
    }
    
    private function handle_admin_registration() {
        if (!wp_verify_nonce($_POST['add_member_nonce'], 'add_member_admin')) {
            wp_die('Security check failed');
        }
        
        $full_name = sanitize_text_field($_POST['full_name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $kenyan_id = sanitize_text_field($_POST['kenyan_id']);
        
        if (empty($full_name) || empty($email) || empty($phone) || empty($kenyan_id)) {
            echo '<div class="notice notice-error"><p>Please fill in all required fields.</p></div>';
            return;
        }
        
        if (!preg_match('/^[0-9]{7,8}$/', $kenyan_id)) {
            echo '<div class="notice notice-error"><p>Please enter a valid Kenyan ID number (7-8 digits only).</p></div>';
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'party_members';
        
        $existing_email = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE email = %s",
            $email
        ));
        
        if ($existing_email) {
            echo '<div class="notice notice-error"><p>This email address is already registered.</p></div>';
            return;
        }
        
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE kenyan_id = %s",
            $kenyan_id
        ));
        
        if ($existing_id) {
            echo '<div class="notice notice-error"><p>This ID number is already registered.</p></div>';
            return;
        }
        
        $member_number = $this->generate_member_number();
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'member_number' => $member_number,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'kenyan_id' => $kenyan_id,
                'registration_date' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            echo '<div class="notice notice-success"><p>Member successfully added with number: <strong>' . $member_number . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to add member. Please try again.</p></div>';
        }
    }
}

// Initialize the plugin
new PoliticalPartyMembership();