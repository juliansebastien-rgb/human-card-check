<?php
/**
 * Plugin Name: Human Card Check
 * Plugin URI: https://github.com/juliansebastien-rgb/human-card-check
 * Description: Human-friendly card challenge for WordPress registration forms and Ultimate Member.
 * Version: 0.2.0
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
    private const VERSION = '0.2.0';
    private const TRANSIENT_PREFIX = 'human_card_check_';
    private const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MIN_SOLVE_SECONDS = 3;
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/human-card-check';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/human-card-check';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/human-card-check';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;

    /** @var array<int,string> */
    private array $card_labels = [
        1 => 'as',
        2 => 'roi',
        3 => 'dame',
        4 => 'valet',
        5 => '10',
        6 => '9',
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

        add_shortcode('human_card_check_demo', [$this, 'render_demo_shortcode']);
        add_shortcode('caj_human_check_demo', [$this, 'render_demo_shortcode']);

        add_action('register_form', [$this, 'render_wp_registration_challenge']);
        add_filter('registration_errors', [$this, 'validate_wp_registration'], 20, 3);

        add_action('um_after_register_fields', [$this, 'render_um_registration_challenge']);
        add_action('um_submit_form_errors_hook_registration', [$this, 'validate_um_registration'], 20);

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
            <p><button type="submit"><?php echo esc_html__('Test the verification', 'human-card-check'); ?></button></p>
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
        }
    }

    private function render_challenge_markup(string $context): void {
        wp_enqueue_style('caj-human-check');
        wp_enqueue_script('caj-human-check');

        $challenge = $this->create_challenge($context);
        ?>
        <div class="human-card-check" data-context="<?php echo esc_attr($context); ?>">
            <p class="human-card-check__title"><?php echo esc_html__('Verifions que vous etes bien un humain :-)', 'human-card-check'); ?></p>
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
        $cards = array_map('intval', (array) array_rand($this->card_labels, 3));
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
            'question' => sprintf(
                /* translators: %s: card label */
                __('Where is the %s of hearts?', 'human-card-check'),
                $this->card_labels[$target_card]
            ),
            'choices' => $this->shuffle_choices([
                'left' => __('Left', 'human-card-check'),
                'center' => __('Center', 'human-card-check'),
                'right' => __('Right', 'human-card-check'),
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
            $choices['card_' . $card_id] = sprintf(
                /* translators: %s: card label */
                __('%s of hearts', 'human-card-check'),
                $this->card_labels[$card_id]
            );
        }

        return [
            'question' => __('Which card is in the center?', 'human-card-check'),
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
            'question' => sprintf(
                /* translators: %s: card label */
                __('Do you see the %s of hearts?', 'human-card-check'),
                $this->card_labels[$target_card]
            ),
            'choices' => $this->shuffle_choices([
                'yes' => __('Yes', 'human-card-check'),
                'no' => __('No', 'human-card-check'),
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
            'question' => __('How many face cards do you see?', 'human-card-check'),
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
            $choices['card_' . $card_id] = sprintf(
                /* translators: %s: card label */
                __('%s of hearts', 'human-card-check'),
                $this->card_labels[$card_id]
            );
        }

        return [
            'question' => __('Which card is the highest?', 'human-card-check'),
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
                'message' => __('Please answer the card verification challenge.', 'human-card-check'),
            ];
        }

        $challenge = get_transient(self::TRANSIENT_PREFIX . $challenge_id);

        if (!is_array($challenge) || empty($challenge['answer']) || empty($challenge['created_at'])) {
            return [
                'valid' => false,
                'message' => __('The verification expired. Please try again.', 'human-card-check'),
            ];
        }

        delete_transient(self::TRANSIENT_PREFIX . $challenge_id);

        $age = time() - (int) $challenge['created_at'];
        $is_correct = hash_equals((string) $challenge['answer'], $submitted_answer);

        if ($age < self::MIN_SOLVE_SECONDS) {
            return [
                'valid' => false,
                'message' => __('The verification was solved too quickly. Please try again.', 'human-card-check'),
            ];
        }

        if (!$is_correct) {
            return [
                'valid' => false,
                'message' => __('The answer is not correct. Please try again.', 'human-card-check'),
            ];
        }

        return [
            'valid' => true,
            'message' => __('Verification successful.', 'human-card-check'),
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
            __('Left', 'human-card-check'),
            __('Center', 'human-card-check'),
            __('Right', 'human-card-check'),
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
                'description' => 'Human-friendly card challenge for WordPress registration forms and Ultimate Member.',
                'installation' => 'Upload the plugin, activate it, then test it on your registration forms. You can also use the [human_card_check_demo] shortcode on a test page.',
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

        if (empty($release['tag_name']) || empty($release['zipball_url'])) {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => (string) $release['zipball_url'],
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
}

(new Human_Card_Check())->boot();
