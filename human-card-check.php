<?php
/**
 * Plugin Name: Human Card Check
 * Plugin URI: https://github.com/juliansebastien-rgb/human-card-check
 * Description: Human-friendly card challenge for WordPress registration, WooCommerce, comments, login, lost password and Ultimate Member.
 * Version: 0.3.9
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: human-card-check
 * Update URI: https://github.com/juliansebastien-rgb/human-card-check
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Human_Card_Check {
    private const VERSION = '0.3.9';
    private const TRANSIENT_PREFIX = 'human_card_check_';
    private const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MIN_SOLVE_SECONDS = 3;
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/human-card-check';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/human-card-check';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/human-card-check';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private const LANGUAGE_OPTION = 'human_card_check_language';
    private const PRO_TOKEN_OPTION = 'human_card_check_pro_token';
    private const PRO_STATUS_OPTION = 'human_card_check_pro_status';
    private const PRO_PAYMENT_LINK_OPTION = 'human_card_check_pro_payment_link';
    private const COMMENT_AJAX_OPTION = 'human_card_check_comment_ajax_protection';
    private const DEFAULT_PRO_PAYMENT_LINK = 'https://buy.stripe.com/cNidR29Lz7OV8cN2Hj8k800';

    /** @var array<string,array<int,string>> */
    private array $card_labels = [
        'fr' => [
            1 => 'as',
            2 => 'roi',
            3 => 'dame',
            4 => 'valet',
            5 => '10',
            6 => '9',
        ],
        'en' => [
            1 => 'ace',
            2 => 'king',
            3 => 'queen',
            4 => 'jack',
            5 => '10',
            6 => '9',
        ],
        'it' => [
            1 => 'asso',
            2 => 're',
            3 => 'regina',
            4 => 'fante',
            5 => '10',
            6 => '9',
        ],
        'es' => [
            1 => 'as',
            2 => 'rey',
            3 => 'reina',
            4 => 'jota',
            5 => '10',
            6 => '9',
        ],
    ];

    /** @var array<int,int> */
    private array $card_strength = [
        1 => 6,
        2 => 5,
        3 => 4,
        4 => 3,
        5 => 2,
        6 => 1,
    ];

    public function boot(): void {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

        add_shortcode('human_card_check_demo', [$this, 'render_demo_shortcode']);
        add_shortcode('caj_human_check_demo', [$this, 'render_demo_shortcode']);

        add_action('register_form', [$this, 'render_wp_registration_challenge']);
        add_filter('registration_errors', [$this, 'validate_wp_registration'], 20, 3);
        add_action('woocommerce_register_form', [$this, 'render_woo_registration_challenge']);
        add_filter('woocommerce_registration_errors', [$this, 'validate_woo_registration'], 20, 3);

        add_action('um_after_register_fields', [$this, 'render_um_registration_challenge']);
        add_action('um_submit_form_errors_hook_registration', [$this, 'validate_um_registration'], 20);
        add_action('comment_form_after_fields', [$this, 'render_comment_challenge']);
        add_action('comment_form_logged_in_after', [$this, 'render_comment_challenge']);
        add_filter('preprocess_comment', [$this, 'validate_comment_submission'], 20);
        add_action('login_form', [$this, 'render_login_challenge']);
        add_filter('authenticate', [$this, 'validate_wp_login'], 25, 3);
        add_action('lostpassword_form', [$this, 'render_lostpassword_challenge']);
        add_action('lostpassword_post', [$this, 'validate_lostpassword_request'], 20, 2);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function register_assets(): void {
        wp_register_style(
            'caj-human-check',
            plugin_dir_url(__FILE__) . 'assets/css/caj-human-check.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'caj-human-check',
            plugin_dir_url(__FILE__) . 'assets/js/caj-human-check.js',
            [],
            self::VERSION,
            true
        );
    }

    public function register_settings(): void {
        register_setting(
            'human_card_check_settings',
            self::LANGUAGE_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_language_setting'],
                'default' => 'auto',
            ]
        );

        register_setting(
            'human_card_check_settings',
            self::PRO_TOKEN_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_pro_token_setting'],
                'default' => '',
            ]
        );

        register_setting(
            'human_card_check_settings',
            self::PRO_PAYMENT_LINK_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default' => self::DEFAULT_PRO_PAYMENT_LINK,
            ]
        );

        register_setting(
            'human_card_check_settings',
            self::COMMENT_AJAX_OPTION,
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox_setting'],
                'default' => true,
            ]
        );
    }

    public function register_settings_page(): void {
        add_options_page(
            'Human Card Check',
            'Human Card Check',
            'manage_options',
            'human-card-check',
            [$this, 'render_settings_page']
        );
    }

    public function sanitize_language_setting($value): string {
        $allowed = ['auto', 'fr', 'en', 'it', 'es'];
        $value = is_string($value) ? strtolower($value) : 'auto';

        return in_array($value, $allowed, true) ? $value : 'auto';
    }

    public function sanitize_pro_token_setting($value): string {
        $token = is_string($value) ? trim($value) : '';
        $token = preg_replace('/[^A-Za-z0-9_\-]/', '', $token);
        $is_active = $token !== '' && strlen($token) >= 16;

        update_option(
            self::PRO_STATUS_OPTION,
            [
                'active' => $is_active,
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'source' => $is_active ? 'manual-token' : 'none',
            ],
            false
        );

        return is_string($token) ? $token : '';
    }

    public function sanitize_checkbox_setting($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return !empty($value);
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $current = $this->get_language_setting();
        $pro_token = $this->get_pro_token();
        $pro_status = $this->get_pro_status();
        $payment_link = $this->get_pro_payment_link();
        $comment_ajax_protection = $this->is_comment_ajax_protection_enabled();
        ?>
        <div class="wrap">
            <h1>Human Card Check</h1>
            <?php $this->render_admin_branding(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('human_card_check_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="human_card_check_language">Challenge language</label>
                        </th>
                        <td>
                            <select id="human_card_check_language" name="<?php echo esc_attr(self::LANGUAGE_OPTION); ?>">
                                <option value="auto" <?php selected($current, 'auto'); ?>>Automatic (site language)</option>
                                <option value="fr" <?php selected($current, 'fr'); ?>>Francais</option>
                                <option value="en" <?php selected($current, 'en'); ?>>English</option>
                                <option value="it" <?php selected($current, 'it'); ?>>Italiano</option>
                                <option value="es" <?php selected($current, 'es'); ?>>Espanol</option>
                            </select>
                            <p class="description">Choose the language used for the card challenge on the front end.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="human_card_check_pro_payment_link">Pro payment link</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="human_card_check_pro_payment_link"
                                value="<?php echo esc_attr($payment_link); ?>"
                                class="regular-text"
                                readonly
                                disabled
                            />
                            <p class="description">This payment link is managed by the plugin and shown in the plugin list and settings pages.</p>
                            <p><a href="<?php echo esc_url($payment_link); ?>" target="_blank" rel="noopener noreferrer">Open payment page</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            AJAX comment protection
                        </th>
                        <td>
                            <label for="human_card_check_comment_ajax_protection">
                                <input
                                    type="checkbox"
                                    id="human_card_check_comment_ajax_protection"
                                    name="<?php echo esc_attr(self::COMMENT_AJAX_OPTION); ?>"
                                    value="1"
                                    <?php checked($comment_ajax_protection); ?>
                                />
                                Validate the Human Card Check challenge for AJAX comment submissions too.
                            </label>
                            <p class="description">Enabled by default. Turn this off only if your theme or comment plugin uses a custom AJAX flow that conflicts with comment posting.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="human_card_check_pro_token">License token</label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="human_card_check_pro_token"
                                name="<?php echo esc_attr(self::PRO_TOKEN_OPTION); ?>"
                                value="<?php echo esc_attr($pro_token); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                            <p class="description">Enter the license token received after payment to unlock Human Card Check Pro features.</p>
                            <p>
                                <strong>Status:</strong>
                                <?php echo $pro_status['active'] ? esc_html__('Pro active', 'human-card-check') : esc_html__('Free mode', 'human-card-check'); ?>
                            </p>
                            <?php if (!empty($pro_status['checked_at'])) : ?>
                                <p class="description">Last token check: <?php echo esc_html($pro_status['checked_at']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save changes'); ?>
            </form>
            <hr />
            <h2>Free vs Pro</h2>
            <p><strong>Free:</strong> card challenge, language setting, native WordPress registration, WooCommerce registration, comments, login, lost password and Ultimate Member integration.</p>
            <p><strong>Pro:</strong> trust score, email/domain analysis, risk logs, configurable thresholds and automatic decisions.</p>
            <?php if ($payment_link !== '') : ?>
                <p><a class="button button-primary" href="<?php echo esc_url($payment_link); ?>" target="_blank" rel="noopener noreferrer">Buy Human Card Check Pro</a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_admin_branding(): void {
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/hcc-logo.PNG';
        ?>
        <style>
            .hcc-admin-brand {
                display: flex;
                align-items: center;
                gap: 18px;
                margin: 18px 0 24px;
                padding: 20px 22px;
                border: 1px solid #d8dee8;
                border-radius: 20px;
                background: linear-gradient(135deg, #fffdf8 0%, #f1f6ff 100%);
                box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            }
            .hcc-admin-brand__logo {
                width: 78px;
                height: 78px;
                border-radius: 22px;
                object-fit: cover;
                background: #fff;
                box-shadow: 0 10px 22px rgba(15, 23, 42, 0.12);
            }
            .hcc-admin-brand__eyebrow {
                margin: 0 0 6px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .08em;
                text-transform: uppercase;
                color: #64748b;
            }
            .hcc-admin-brand__title {
                margin: 0 0 6px;
                font-size: 24px;
                line-height: 1.2;
                color: #0f172a;
            }
            .hcc-admin-brand__text {
                margin: 0;
                max-width: 760px;
                color: #475569;
            }
        </style>
        <div class="hcc-admin-brand">
            <img class="hcc-admin-brand__logo" src="<?php echo esc_url($logo_url); ?>" alt="Human Card Check logo" />
            <div>
                <p class="hcc-admin-brand__eyebrow">Le Labo d'Azertaf</p>
                <h2 class="hcc-admin-brand__title">Human Card Check</h2>
                <p class="hcc-admin-brand__text">A playful anti-bot layer for WordPress, now covering registration, WooCommerce, comments, login, lost password and Ultimate Member.</p>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function plugin_action_links(array $links): array {
        $settings_url = admin_url('options-general.php?page=human-card-check');
        $actions = [
            '<a href="' . esc_url($settings_url) . '">Settings</a>',
        ];

        $payment_link = $this->get_pro_payment_link();
        if ($payment_link !== '' && !$this->is_pro_active()) {
            $actions[] = '<a href="' . esc_url($payment_link) . '" target="_blank" rel="noopener noreferrer"><strong>Upgrade to Pro</strong></a>';
        }

        return array_merge($actions, $links);
    }

    public function render_demo_shortcode(): string {
        $message = '';

        if (
            isset($_POST['human_card_check_demo_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['human_card_check_demo_nonce'])), 'human_card_check_demo_submit')
        ) {
            $result = $this->validate_request_payload();
            $message = sprintf(
                '<div class="human-card-check__demo-result %1$s">%2$s</div>',
                $result['valid'] ? 'is-valid' : 'is-invalid',
                esc_html($result['message'])
            );
        }

        ob_start();
        ?>
        <form method="post" class="human-card-check__demo-form">
            <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php $this->render_challenge_markup('demo'); ?>
            <?php wp_nonce_field('human_card_check_demo_submit', 'human_card_check_demo_nonce'); ?>
            <p><button type="submit"><?php echo esc_html($this->t('test_button')); ?></button></p>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function render_wp_registration_challenge(): void {
        $this->render_challenge_markup('wp-register');
    }

    public function validate_wp_registration(WP_Error $errors, string $sanitized_user_login = '', string $user_email = ''): WP_Error {
        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            $errors->add('human_card_check', $result['message']);
        } else {
            $decision = $this->run_pro_registration_analysis(
                [
                    'context' => 'wp-register',
                    'user_login' => $sanitized_user_login,
                    'user_email' => $user_email,
                ]
            );

            if (!$decision['allow']) {
                $errors->add('human_card_check_pro', $decision['message']);
            }
        }

        return $errors;
    }

    public function render_um_registration_challenge(): void {
        $this->render_challenge_markup('um-register');
    }

    public function validate_um_registration(): void {
        if (!function_exists('UM')) {
            return;
        }

        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            UM()->form()->add_error('human_card_check', $result['message']);
        } else {
            $email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
            $username = isset($_POST['user_login']) ? sanitize_user(wp_unslash($_POST['user_login'])) : '';
            $decision = $this->run_pro_registration_analysis(
                [
                    'context' => 'um-register',
                    'user_login' => $username,
                    'user_email' => $email,
                ]
            );

            if (!$decision['allow']) {
                UM()->form()->add_error('human_card_check_pro', $decision['message']);
            }
        }
    }

    public function render_woo_registration_challenge(): void {
        $this->render_challenge_markup('woo-register');
    }

    public function validate_woo_registration(WP_Error $errors, string $username = '', string $email = ''): WP_Error {
        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            $errors->add('human_card_check', $result['message']);
        } else {
            $decision = $this->run_pro_registration_analysis(
                [
                    'context' => 'woo-register',
                    'user_login' => $username,
                    'user_email' => $email,
                ]
            );

            if (!$decision['allow']) {
                $errors->add('human_card_check_pro', $decision['message']);
            }
        }

        return $errors;
    }

    public function render_comment_challenge(): void {
        if (is_admin()) {
            return;
        }

        $this->render_challenge_markup('comment');
    }

    /**
     * @param array<string,mixed> $commentdata
     * @return array<string,mixed>
     */
    public function validate_comment_submission(array $commentdata): array {
        if (is_admin()) {
            return $commentdata;
        }

        if (wp_doing_ajax() && !$this->is_comment_ajax_protection_enabled()) {
            return $commentdata;
        }

        if (empty($commentdata['comment_post_ID']) || !empty($commentdata['comment_type'])) {
            return $commentdata;
        }

        $result = $this->validate_request_payload();
        if (!$result['valid']) {
            wp_die(esc_html($result['message']), esc_html__('Comment blocked', 'human-card-check'), ['response' => 403]);
        }

        $decision = $this->run_pro_registration_analysis(
            [
                'context' => 'comment',
                'user_login' => !empty($commentdata['comment_author']) ? (string) $commentdata['comment_author'] : '',
                'user_email' => !empty($commentdata['comment_author_email']) ? (string) $commentdata['comment_author_email'] : '',
            ]
        );

        if (!$decision['allow']) {
            wp_die(esc_html($decision['message']), esc_html__('Comment blocked', 'human-card-check'), ['response' => 403]);
        }

        return $commentdata;
    }

    public function render_login_challenge(): void {
        if (!$this->is_wp_login_action('login')) {
            return;
        }

        $this->render_challenge_markup('login');
    }

    public function validate_wp_login($user, string $username, string $password) {
        if (!$this->is_wp_login_action('login') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $user;
        }

        if ($user instanceof WP_Error && $user->has_errors()) {
            return $user;
        }

        $result = $this->validate_request_payload();
        if (!$result['valid']) {
            return new WP_Error('human_card_check', $result['message']);
        }

        $decision = $this->run_pro_registration_analysis(
            [
                'context' => 'login',
                'user_login' => $username,
                'user_email' => '',
            ]
        );

        if (!$decision['allow']) {
            return new WP_Error('human_card_check_pro', $decision['message']);
        }

        return $user;
    }

    public function render_lostpassword_challenge(): void {
        $this->render_challenge_markup('lostpassword');
    }

    public function validate_lostpassword_request(WP_Error $errors, $user_data): void {
        $result = $this->validate_request_payload();

        if (!$result['valid']) {
            $errors->add('human_card_check', $result['message']);
            return;
        }

        $user_login = '';
        $user_email = '';

        if ($user_data instanceof WP_User) {
            $user_login = $user_data->user_login;
            $user_email = $user_data->user_email;
        } elseif (is_string($user_data)) {
            $submitted = trim($user_data);
            if (is_email($submitted)) {
                $user_email = $submitted;
            } else {
                $user_login = $submitted;
            }
        }

        $decision = $this->run_pro_registration_analysis(
            [
                'context' => 'lostpassword',
                'user_login' => $user_login,
                'user_email' => $user_email,
            ]
        );

        if (!$decision['allow']) {
            $errors->add('human_card_check_pro', $decision['message']);
        }
    }

    private function render_challenge_markup(string $context): void {
        wp_enqueue_style('caj-human-check');
        wp_enqueue_script('caj-human-check');

        $challenge = $this->create_challenge($context);
        ?>
        <div class="human-card-check" data-context="<?php echo esc_attr($context); ?>">
            <p class="human-card-check__title"><?php echo esc_html($this->t('title')); ?></p>
            <p class="human-card-check__question"><?php echo esc_html($challenge['question']); ?></p>

            <div class="human-card-check__cards" aria-hidden="true">
                <?php foreach ($challenge['cards'] as $index => $card_id) : ?>
                    <figure class="human-card-check__card">
                        <img
                            src="<?php echo esc_url($this->get_card_url((int) $card_id)); ?>"
                            alt=""
                            loading="lazy"
                        />
                        <figcaption><?php echo esc_html($this->position_label($index)); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>

            <div class="human-card-check__answers" role="radiogroup" aria-label="<?php echo esc_attr($challenge['question']); ?>">
                <?php foreach ($challenge['choices'] as $choice_key => $choice_label) : ?>
                    <label class="human-card-check__answer">
                        <input type="radio" name="human_card_check_answer" value="<?php echo esc_attr($choice_key); ?>" required>
                        <span><?php echo esc_html($choice_label); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="human_card_check_id" value="<?php echo esc_attr($challenge['id']); ?>">
        </div>
        <?php
    }

    private function create_challenge(string $context): array {
        $cards = array_map('intval', (array) array_rand($this->card_strength, 3));
        shuffle($cards);

        $builders = [
            'build_position_question',
            'build_center_card_question',
            'build_presence_question',
            'build_figure_count_question',
            'build_highest_card_question',
        ];

        $builder_name = $builders[array_rand($builders)];
        $payload = $this->{$builder_name}($cards);

        $challenge = [
            'id' => wp_generate_uuid4(),
            'cards' => $cards,
            'question' => $payload['question'],
            'choices' => $payload['choices'],
            'answer' => $payload['answer'],
            'created_at' => time(),
            'context' => $context,
        ];

        set_transient(self::TRANSIENT_PREFIX . $challenge['id'], $challenge, self::CHALLENGE_TTL);

        return $challenge;
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_position_question(array $cards): array {
        $target_position = wp_rand(0, 2);
        $target_card = $cards[$target_position];

        return [
            'question' => $this->t('where_is_card', ['card' => $this->get_card_display_label($target_card)]),
            'choices' => $this->shuffle_choices([
                'left' => $this->t('left'),
                'center' => $this->t('center'),
                'right' => $this->t('right'),
            ]),
            'answer' => ['left', 'center', 'right'][$target_position],
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_center_card_question(array $cards): array {
        $choices = [];

        foreach ($cards as $card_id) {
            $choices['card_' . $card_id] = $this->get_card_display_label($card_id);
        }

        return [
            'question' => $this->t('which_center'),
            'choices' => $this->shuffle_choices($choices),
            'answer' => 'card_' . $cards[1],
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_presence_question(array $cards): array {
        $target_card = wp_rand(0, 1) === 1
            ? $cards[array_rand($cards)]
            : array_rand($this->card_labels);

        $target_card = (int) $target_card;
        $is_present = in_array($target_card, $cards, true);

        return [
            'question' => $this->t('do_you_see_card', ['card' => $this->get_card_display_label($target_card)]),
            'choices' => $this->shuffle_choices([
                'yes' => $this->t('yes'),
                'no' => $this->t('no'),
            ]),
            'answer' => $is_present ? 'yes' : 'no',
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_figure_count_question(array $cards): array {
        $figure_ids = [2, 3, 4];
        $count = 0;

        foreach ($cards as $card_id) {
            if (in_array($card_id, $figure_ids, true)) {
                $count++;
            }
        }

        return [
            'question' => $this->t('how_many_figures'),
            'choices' => $this->shuffle_choices([
                '0' => '0',
                '1' => '1',
                '2' => '2',
                '3' => '3',
            ]),
            'answer' => (string) $count,
        ];
    }

    /**
     * @param array<int,int> $cards
     * @return array{question:string,choices:array<string,string>,answer:string}
     */
    private function build_highest_card_question(array $cards): array {
        $highest_card = $cards[0];

        foreach ($cards as $card_id) {
            if ($this->card_strength[$card_id] > $this->card_strength[$highest_card]) {
                $highest_card = $card_id;
            }
        }

        $choices = [];

        foreach ($cards as $card_id) {
            $choices['card_' . $card_id] = $this->get_card_display_label($card_id);
        }

        return [
            'question' => $this->t('which_highest'),
            'choices' => $this->shuffle_choices($choices),
            'answer' => 'card_' . $highest_card,
        ];
    }

    /**
     * @return array{valid:bool,message:string}
     */
    private function validate_request_payload(): array {
        $challenge_id = isset($_POST['human_card_check_id']) ? sanitize_text_field(wp_unslash($_POST['human_card_check_id'])) : '';
        $submitted_answer = isset($_POST['human_card_check_answer']) ? sanitize_text_field(wp_unslash($_POST['human_card_check_answer'])) : '';

        if ($challenge_id === '' || $submitted_answer === '') {
            return [
                'valid' => false,
                'message' => $this->t('error_answer_required'),
            ];
        }

        $challenge = get_transient(self::TRANSIENT_PREFIX . $challenge_id);

        if (!is_array($challenge) || empty($challenge['answer']) || empty($challenge['created_at'])) {
            return [
                'valid' => false,
                'message' => $this->t('error_expired'),
            ];
        }

        delete_transient(self::TRANSIENT_PREFIX . $challenge_id);

        $age = time() - (int) $challenge['created_at'];
        $is_correct = hash_equals((string) $challenge['answer'], $submitted_answer);

        if ($age < self::MIN_SOLVE_SECONDS) {
            return [
                'valid' => false,
                'message' => $this->t('error_too_fast'),
            ];
        }

        if (!$is_correct) {
            return [
                'valid' => false,
                'message' => $this->t('error_incorrect'),
            ];
        }

        return [
            'valid' => true,
            'message' => $this->t('success'),
        ];
    }

    private function get_card_url(int $card_id): string {
        return plugin_dir_url(__FILE__) . 'assets/cards/CAJ-' . $card_id . '.PNG';
    }

    /**
     * @param array<string,string> $choices
     * @return array<string,string>
     */
    private function shuffle_choices(array $choices): array {
        $keys = array_keys($choices);
        shuffle($keys);

        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[$key] = $choices[$key];
        }

        return $shuffled;
    }

    private function position_label(int $index): string {
        return [
            $this->t('left'),
            $this->t('center'),
            $this->t('right'),
        ][$index] ?? '';
    }

    public function inject_github_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_data();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $update = (object) [
            'slug' => 'human-card-check',
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.8',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        $transient->response[$plugin_file] = $update;

        return $transient;
    }

    public function filter_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'human-card-check') {
            return $result;
        }

        $release = $this->get_github_release_data();

        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'Human Card Check',
            'slug' => 'human-card-check',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/juliansebastien-rgb">Le Labo d&#039;Azertaf</a>',
            'author_profile' => 'https://github.com/juliansebastien-rgb',
            'homepage' => self::GITHUB_REPOSITORY_URL,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '6.8',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Human-friendly card challenge for WordPress registration, WooCommerce, comments, login, lost password and Ultimate Member.',
                'installation' => 'Upload the plugin, activate it, then test it on your registration, WooCommerce, comments, login, lost password or Ultimate Member forms. You can also use the [human_card_check_demo] shortcode on a test page. The challenge language can be changed in Settings > Human Card Check.',
                'changelog' => sprintf("= %s =\n* GitHub release package.\n", $release['version']),
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function clear_update_cache($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];

        if (in_array(plugin_basename(__FILE__), $plugins, true)) {
            delete_transient(self::TRANSIENT_PREFIX . 'github_release');
        }
    }

    public function normalize_github_update_source(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return $source;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (!in_array(plugin_basename(__FILE__), $plugins, true)) {
            return $source;
        }

        $normalized = trailingslashit($remote_source) . 'human-card-check';

        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    private function get_github_release_data(): ?array {
        $cache_key = self::TRANSIENT_PREFIX . 'github_release';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $release = $this->request_github_release('/releases/latest');

        if (!$release) {
            $tag = $this->request_github_release('/tags');
            if (!$tag || empty($tag[0]['name'])) {
                return null;
            }

            $first_tag = $tag[0];
            $release = [
                'tag_name' => $first_tag['name'],
                'zipball_url' => self::GITHUB_API_BASE . '/zipball/' . rawurlencode($first_tag['name']),
                'html_url' => self::GITHUB_REPOSITORY_URL . '/releases/tag/' . rawurlencode($first_tag['name']),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'body' => '',
            ];
        }

        if (empty($release['tag_name'])) {
            return null;
        }

        $package = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                $download = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                if ($name !== '' && substr($name, -4) === '.zip' && $download !== '') {
                    $package = $download;
                    break;
                }
            }
        }

        if ($package === '' && !empty($release['zipball_url'])) {
            $package = (string) $release['zipball_url'];
        }

        if ($package === '') {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => $package,
            'url' => !empty($release['html_url']) ? (string) $release['html_url'] : self::GITHUB_REPOSITORY_URL,
            'published_at' => !empty($release['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $release['published_at'])) : gmdate('Y-m-d H:i:s'),
            'body' => !empty($release['body']) ? (string) $release['body'] : '',
        ];

        set_transient($cache_key, $data, self::UPDATE_CACHE_TTL);

        return $data;
    }

    private function request_github_release(string $path) {
        $response = wp_remote_get(
            self::GITHUB_API_BASE . $path,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Human Card Check/' . self::VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    private function get_language_setting(): string {
        $value = get_option(self::LANGUAGE_OPTION, 'auto');

        return is_string($value) ? $value : 'auto';
    }

    private function get_current_language(): string {
        $setting = $this->get_language_setting();
        if ($setting !== 'auto') {
            return $setting;
        }

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $prefix = strtolower(substr((string) $locale, 0, 2));

        return in_array($prefix, ['fr', 'en', 'it', 'es'], true) ? $prefix : 'en';
    }

    private function get_pro_token(): string {
        $value = get_option(self::PRO_TOKEN_OPTION, '');
        return is_string($value) ? $value : '';
    }

    private function get_pro_payment_link(): string {
        $value = get_option(self::PRO_PAYMENT_LINK_OPTION, self::DEFAULT_PRO_PAYMENT_LINK);
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : self::DEFAULT_PRO_PAYMENT_LINK;
    }

    private function is_comment_ajax_protection_enabled(): bool {
        $value = get_option(self::COMMENT_AJAX_OPTION, true);
        return !empty($value);
    }

    /**
     * @return array{active:bool,checked_at:string,source:string}
     */
    private function get_pro_status(): array {
        $value = get_option(self::PRO_STATUS_OPTION, []);

        return [
            'active' => !empty($value['active']),
            'checked_at' => isset($value['checked_at']) && is_string($value['checked_at']) ? $value['checked_at'] : '',
            'source' => isset($value['source']) && is_string($value['source']) ? $value['source'] : 'none',
        ];
    }

    private function is_pro_active(): bool {
        $status = $this->get_pro_status();
        return $status['active'];
    }

    private function is_wp_login_action(string $expected_action): bool {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
        return $action === $expected_action;
    }

    /**
     * @param array<string,string> $context
     * @return array{allow:bool,message:string,action:string}
     */
    private function run_pro_registration_analysis(array $context): array {
        if (!$this->is_pro_active()) {
            return [
                'allow' => true,
                'message' => '',
                'action' => 'allow',
            ];
        }

        $payload = [
            'user_email' => isset($context['user_email']) ? sanitize_email($context['user_email']) : '',
            'user_login' => isset($context['user_login']) ? sanitize_user($context['user_login']) : '',
            'context' => isset($context['context']) ? sanitize_text_field($context['context']) : '',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'trust_score' => 0,
            'flags' => [],
        ];

        if ($payload['user_email'] !== '' && str_contains($payload['user_email'], '@')) {
            $domain = substr(strrchr($payload['user_email'], '@'), 1);
            $payload['email_domain'] = strtolower((string) $domain);
        }

        /**
         * Future Human Card Check Pro addon entry point.
         *
         * The free plugin passes sanitized registration context so a paid addon
         * can calculate a trust score, persist logs and decide whether to apply
         * extra verification or moderation.
         */
        do_action('human_card_check_pro_analyze_registration', $payload);

        $decision = apply_filters(
            'human_card_check_pro_registration_decision',
            [
                'allow' => true,
                'message' => '',
                'action' => 'allow',
            ],
            $payload
        );

        if (!is_array($decision)) {
            return [
                'allow' => true,
                'message' => '',
                'action' => 'allow',
            ];
        }

        return [
            'allow' => !empty($decision['allow']),
            'message' => isset($decision['message']) ? (string) $decision['message'] : '',
            'action' => isset($decision['action']) ? (string) $decision['action'] : 'allow',
        ];
    }

    private function get_card_display_label(int $card_id): string {
        $lang = $this->get_current_language();
        $labels = $this->card_labels[$lang] ?? $this->card_labels['en'];

        return $this->t('card_of_hearts', ['card' => $labels[$card_id] ?? (string) $card_id]);
    }

    private function t(string $key, array $replacements = []): string {
        $lang = $this->get_current_language();
        $translations = $this->translations();
        $value = $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;

        foreach ($replacements as $token => $replacement) {
            $value = str_replace('{' . $token . '}', (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function translations(): array {
        return [
            'fr' => [
                'title' => 'Verifions que vous etes bien un humain :-)',
                'test_button' => 'Tester la verification',
                'left' => 'A gauche',
                'center' => 'Au centre',
                'right' => 'A droite',
                'yes' => 'Oui',
                'no' => 'Non',
                'card_of_hearts' => '{card} de coeur',
                'where_is_card' => 'Ou se trouve le {card} ?',
                'which_center' => 'Quelle carte est au centre ?',
                'do_you_see_card' => 'Voyez-vous le {card} ?',
                'how_many_figures' => 'Combien de figures voyez-vous ?',
                'which_highest' => 'Quelle est la carte la plus forte ?',
                'error_answer_required' => 'Merci de repondre a la verification par cartes.',
                'error_expired' => 'La verification a expire. Merci d essayer de nouveau.',
                'error_too_fast' => 'La verification a ete resolue trop vite. Merci de recommencer calmement.',
                'error_incorrect' => 'La reponse n est pas correcte. Merci de recommencer.',
                'success' => 'Verification reussie.',
            ],
            'en' => [
                'title' => 'Let us make sure you are human :-)',
                'test_button' => 'Test the verification',
                'left' => 'Left',
                'center' => 'Center',
                'right' => 'Right',
                'yes' => 'Yes',
                'no' => 'No',
                'card_of_hearts' => '{card} of hearts',
                'where_is_card' => 'Where is the {card}?',
                'which_center' => 'Which card is in the center?',
                'do_you_see_card' => 'Do you see the {card}?',
                'how_many_figures' => 'How many face cards do you see?',
                'which_highest' => 'Which card is the highest?',
                'error_answer_required' => 'Please answer the card verification challenge.',
                'error_expired' => 'The verification expired. Please try again.',
                'error_too_fast' => 'The verification was solved too quickly. Please try again.',
                'error_incorrect' => 'The answer is not correct. Please try again.',
                'success' => 'Verification successful.',
            ],
            'it' => [
                'title' => 'Verifichiamo che tu sia davvero umano :-)',
                'test_button' => 'Testa la verifica',
                'left' => 'A sinistra',
                'center' => 'Al centro',
                'right' => 'A destra',
                'yes' => 'Si',
                'no' => 'No',
                'card_of_hearts' => '{card} di cuori',
                'where_is_card' => 'Dove si trova il {card}?',
                'which_center' => 'Quale carta e al centro?',
                'do_you_see_card' => 'Vedi il {card}?',
                'how_many_figures' => 'Quante figure vedi?',
                'which_highest' => 'Qual e la carta piu alta?',
                'error_answer_required' => 'Rispondi alla verifica con le carte.',
                'error_expired' => 'La verifica e scaduta. Riprova.',
                'error_too_fast' => 'La verifica e stata risolta troppo velocemente. Riprova.',
                'error_incorrect' => 'La risposta non e corretta. Riprova.',
                'success' => 'Verifica completata con successo.',
            ],
            'es' => [
                'title' => 'Verifiquemos que realmente eres humano :-)',
                'test_button' => 'Probar la verificacion',
                'left' => 'A la izquierda',
                'center' => 'En el centro',
                'right' => 'A la derecha',
                'yes' => 'Si',
                'no' => 'No',
                'card_of_hearts' => '{card} de corazones',
                'where_is_card' => 'Donde esta el {card}?',
                'which_center' => 'Que carta esta en el centro?',
                'do_you_see_card' => 'Ves el {card}?',
                'how_many_figures' => 'Cuantas figuras ves?',
                'which_highest' => 'Cual es la carta mas alta?',
                'error_answer_required' => 'Responde al desafio de cartas.',
                'error_expired' => 'La verificacion ha caducado. Intentalo de nuevo.',
                'error_too_fast' => 'La verificacion se resolvio demasiado rapido. Intentalo de nuevo.',
                'error_incorrect' => 'La respuesta no es correcta. Intentalo de nuevo.',
                'success' => 'Verificacion completada con exito.',
            ],
        ];
    }
}

(new Human_Card_Check())->boot();
